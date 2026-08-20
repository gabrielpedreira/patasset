<?php
/**
 * dev_seguranca.php
 * Registro de erros, ameaças e eventos de segurança.
 *
 * Substitui o dev_logger.php, que tinha as funções mas nunca foi incluído em
 * página nenhuma — as tabelas dev_log_erros e dev_invasoes existiam vazias
 * desde sempre, e o painel mostrava telas em branco achando que era normal.
 *
 * Tudo aqui é defensivo: nenhuma falha de log pode derrubar a página do
 * usuário. Um sistema de monitoramento que quebra a aplicação é pior do que
 * não ter monitoramento.
 */

if (defined('DEV_SEGURANCA_CARREGADO')) return;
define('DEV_SEGURANCA_CARREGADO', true);

/** Conexão própria: o log precisa funcionar mesmo quando a página falhou antes
 *  de conectar, ou quando a conexão dela já foi fechada. */
function dev_conn(): ?mysqli {
    static $c = null;
    static $tentou = false;
    if ($c instanceof mysqli) return $c;
    if ($tentou) return null;
    $tentou = true;

    require_once __DIR__ . '/config_seguro.php';

    mysqli_report(MYSQLI_REPORT_OFF);
    $c = @new mysqli(PAT_DB_HOST, PAT_DB_USER, PAT_DB_PASS, PAT_DB_NAME);
    if ($c->connect_errno) { $c = null; return null; }
    @$c->set_charset('utf8mb4');
    return $c;
}

/** IP público de quem fez a requisição */
function dev_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/** Usuário logado, se houver */
function dev_usuario_atual(): string {
    if (session_status() === PHP_SESSION_NONE) return '';
    return (string)($_SESSION['usuario_logado'] ?? '');
}

/**
 * Traduz o User-Agent em navegador / sistema / tipo de dispositivo.
 * Não identifica a máquina: navegador nenhum entrega nome de host ou MAC —
 * isso seria uma falha de privacidade grave do próprio navegador. O que dá
 * para saber é o conjunto IP + navegador + sistema, que já é suficiente para
 * distinguir tentativas repetidas.
 */
function dev_dispositivo(?string $ua = null): array {
    $ua = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');

    $nav = 'Desconhecido';
    foreach ([
        'Edg/'      => 'Edge',
        'OPR/'      => 'Opera',
        'Chrome/'   => 'Chrome',
        'Firefox/'  => 'Firefox',
        'Safari/'   => 'Safari',
        'MSIE'      => 'Internet Explorer',
        'Trident/'  => 'Internet Explorer',
        'curl/'     => 'curl (script)',
        'Wget'      => 'Wget (script)',
        'python'    => 'Python (script)',
        'Postman'   => 'Postman',
    ] as $marca => $nome) {
        if (stripos($ua, $marca) !== false) { $nav = $nome; break; }
    }

    $sis = 'Desconhecido';
    foreach ([
        'Windows NT 10' => 'Windows 10/11',
        'Windows NT'    => 'Windows',
        'Android'       => 'Android',
        'iPhone'        => 'iPhone',
        'iPad'          => 'iPad',
        'Mac OS X'      => 'macOS',
        'Linux'         => 'Linux',
    ] as $marca => $nome) {
        if (stripos($ua, $marca) !== false) { $sis = $nome; break; }
    }

    $tipo = 'Computador';
    if (preg_match('/Mobile|Android|iPhone/i', $ua))      $tipo = 'Celular';
    elseif (preg_match('/iPad|Tablet/i', $ua))            $tipo = 'Tablet';
    if (preg_match('/curl|wget|python|bot|spider/i', $ua)) $tipo = 'Automatizado';
    if (trim($ua) === '')                                  $tipo = 'Sem identificação';

    return ['navegador' => $nav, 'sistema' => $sis, 'dispositivo' => $tipo];
}

/* ═══════════════════════════════════════════════════════════════════════════
   ERROS
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Registra um erro (PHP ou JavaScript).
 * Erros idênticos são agrupados por impressão digital e contam ocorrências,
 * em vez de gerar milhares de linhas iguais.
 */
function dev_registrar_erro(array $e): void {
    $c = dev_conn();
    if (!$c) return;

    $origem  = in_array($e['origem'] ?? 'PHP', ['PHP','JS'], true) ? $e['origem'] : 'PHP';
    $nivel   = in_array($e['nivel'] ?? 'ERROR', ['INFO','WARNING','ERROR','CRITICAL'], true)
             ? $e['nivel'] : 'ERROR';
    $arquivo = substr((string)($e['arquivo'] ?? ''), 0, 100);
    $url     = substr((string)($e['url'] ?? ($_SERVER['REQUEST_URI'] ?? '')), 0, 255);
    $linha   = (int)($e['linha'] ?? 0);
    $msg     = substr((string)($e['mensagem'] ?? ''), 0, 2000);
    $stack   = substr((string)($e['stack'] ?? ''), 0, 4000);
    $usuario = substr((string)($e['usuario'] ?? dev_usuario_atual()), 0, 100);
    $ip      = substr((string)($e['ip'] ?? dev_ip()), 0, 50);
    $ua      = substr((string)($e['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);

    if ($msg === '') return;

    // Agrupa o mesmo erro no mesmo lugar. A mensagem entra normalizada para
    // que variações de id/valor ("item 45", "item 46") não virem linhas novas.
    $msg_norm  = preg_replace('/\d+/', '#', $msg);
    $impressao = md5($origem . '|' . $arquivo . '|' . $linha . '|' . $msg_norm);

    $st = $c->prepare("
        INSERT INTO dev_log_erros
            (origem, nivel, arquivo, url, linha, mensagem, stack, usuario, ip,
             user_agent, impressao, ocorrencias, criado_em, atualizado_em)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())
        ON DUPLICATE KEY UPDATE
            ocorrencias   = ocorrencias + 1,
            atualizado_em = NOW(),
            usuario       = VALUES(usuario),
            ip            = VALUES(ip),
            url           = VALUES(url)");
    if (!$st) return;
    $st->bind_param('ssssissssss', $origem, $nivel, $arquivo, $url, $linha,
                    $msg, $stack, $usuario, $ip, $ua, $impressao);
    @$st->execute();
    $st->close();

    // Erro repetindo muito costuma ser exploração ou defeito grave em produção
    if ($nivel === 'CRITICAL') {
        dev_registrar_ameaca([
            'tipo'       => 'ERRO_CRITICO',
            'severidade' => 'MEDIA',
            'detalhe'    => "Erro crítico em $arquivo linha $linha: $msg",
        ]);
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   AMEAÇAS
   ═══════════════════════════════════════════════════════════════════════════ */

const DEV_TIPOS_AMEACA = [
    'FORCA_BRUTA'      => 'Tentativas de senha em excesso',
    'ACESSO_NEGADO'    => 'Acesso a página sem permissão',
    'SEM_LOGIN'        => 'Página restrita acessada sem sessão',
    'SESSAO_INVALIDA'  => 'Sessão expirada ou revogada em uso',
    'ERRO_CRITICO'     => 'Erro crítico na aplicação',
    'UPLOAD_SUSPEITO'  => 'Envio de arquivo recusado por tipo',
    'CSRF_SUSPEITO'    => 'Ação sem token de confirmação',
    'OUTRO'            => 'Outro',
];

/**
 * Registra uma ameaça. Eventos do mesmo tipo, mesmo IP e mesmo alvo são
 * agrupados com contador — 200 tentativas viram uma linha com "200x",
 * que é mais legível do que 200 linhas e não incha a tabela.
 */
function dev_registrar_ameaca(array $a): void {
    $c = dev_conn();
    if (!$c) return;

    $tipo = array_key_exists($a['tipo'] ?? '', DEV_TIPOS_AMEACA) ? $a['tipo'] : 'OUTRO';
    $sev  = in_array($a['severidade'] ?? 'MEDIA', ['BAIXA','MEDIA','ALTA','CRITICA'], true)
          ? $a['severidade'] : 'MEDIA';

    $alvo    = substr((string)($a['usuario_alvo'] ?? ''), 0, 100);
    $ip      = substr((string)($a['ip_rede']  ?? dev_ip()), 0, 50);
    $ip_loc  = substr((string)($a['ip_local'] ?? ''), 0, 50);
    $ua      = substr((string)($a['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
    $pagina  = substr((string)($a['pagina'] ?? basename($_SERVER['PHP_SELF'] ?? '')), 0, 100);
    $detalhe = substr((string)($a['detalhe'] ?? ''), 0, 1000);

    $d = dev_dispositivo($ua);

    // A página entra na chave: sem ela, ocorrências de origens diferentes
    // colapsavam numa linha só e o painel mostrava apenas a última — o que
    // torna impossível descobrir de onde vinha o problema.
    $chave = md5($tipo . '|' . $ip . '|' . $alvo . '|' . $pagina . '|' . date('Y-m-d'));

    $st = $c->prepare("
        INSERT INTO dev_ameacas
            (chave, tipo, severidade, usuario_alvo, ip_rede, ip_local, user_agent,
             navegador, sistema, dispositivo, pagina, detalhe, ocorrencias,
             primeira_em, ultima_em)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())
        ON DUPLICATE KEY UPDATE
            ocorrencias = ocorrencias + 1,
            ultima_em   = NOW(),
            severidade  = VALUES(severidade),
            detalhe     = VALUES(detalhe)");
    if (!$st) return;
    $st->bind_param('ssssssssssss', $chave, $tipo, $sev, $alvo, $ip, $ip_loc, $ua,
                    $d['navegador'], $d['sistema'], $d['dispositivo'], $pagina, $detalhe);
    @$st->execute();
    $st->close();
}

/* ═══════════════════════════════════════════════════════════════════════════
   PRESENÇA DE VISITANTES NÃO LOGADOS
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Registra o acesso de quem NÃO está logado.
 *
 * O sistema já sabia quem estava logado (tabela usuarios_online), mas era cego
 * para o resto: a abertura de chamado é pública, acessível por QR Code, e
 * ninguém tinha como saber se alguém a estava usando. Só se via o resultado —
 * um chamado criado — nunca a tentativa.
 *
 * Uma linha por IP+página, atualizada a cada visita. Não cria registro novo a
 * cada carregamento: seria uma tabela crescendo sem limite por nada.
 */
function dev_registrar_presenca(string $pagina = ''): void {
    $c = dev_conn();
    if (!$c) return;

    $pagina = substr($pagina ?: basename($_SERVER['PHP_SELF'] ?? ''), 0, 100);
    $ip     = dev_ip();
    if ($ip === '') return;

    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $d  = dev_dispositivo($ua);
    $chave = md5($ip . '|' . $pagina);

    $st = $c->prepare("
        INSERT INTO dev_presenca
            (chave, ip, pagina, navegador, sistema, dispositivo, visitas, primeira_em, ultima_em)
        VALUES (?,?,?,?,?,?,1,NOW(),NOW())
        ON DUPLICATE KEY UPDATE
            visitas   = visitas + 1,
            ultima_em = NOW(),
            pagina    = VALUES(pagina)");
    if (!$st) return;
    $st->bind_param('ssssss', $chave, $ip, $pagina,
                    $d['navegador'], $d['sistema'], $d['dispositivo']);
    @$st->execute();
    $st->close();
}

/**
 * Chamada padrão para páginas restritas: registra quem tentou entrar sem
 * ter direito. Use antes do header('Location: acesso_bloqueado.html').
 */
function dev_acesso_negado(string $motivo = 'PERMISSAO', string $detalhe = ''): void {
    $tipo = $motivo === 'SEM_LOGIN' ? 'SEM_LOGIN' : 'ACESSO_NEGADO';
    dev_registrar_ameaca([
        'tipo'         => $tipo,
        'severidade'   => 'MEDIA',
        'usuario_alvo' => dev_usuario_atual(),
        'detalhe'      => $detalhe ?: 'Tentativa de abrir ' . basename($_SERVER['PHP_SELF'] ?? ''),
    ]);
}
