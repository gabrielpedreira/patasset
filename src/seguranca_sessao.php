<?php
/**
 * seguranca_sessao.php
 * Endurecimento de sessão e proteção contra CSRF.
 *
 * ─── Por que CSRF importa aqui ──────────────────────────────────────────────
 * O sistema confia apenas no cookie de sessão para autorizar uma ação. O
 * navegador envia esse cookie automaticamente em qualquer requisição ao
 * domínio — inclusive numa disparada por outra aba. Então uma página maliciosa
 * aberta por alguém logado pode fazer o navegador dele criar um usuário com
 * permissão A no painel DEV, ou excluir registros, sem que nada apareça na
 * tela. O token resolve porque o site atacante não consegue lê-lo.
 *
 * ─── Modo observação ────────────────────────────────────────────────────────
 * A validação começa DESLIGADA (SEG_CSRF_BLOQUEAR = false). Ela registra as
 * requisições sem token, mas deixa passar. São 86 arquivos com POST; ligar a
 * exigência de uma vez, às cegas, quebraria telas que ninguém testou hoje e o
 * sistema pararia no meio do expediente.
 *
 * Depois de alguns dias observando a lista em DEV → Ameaças, quando ela estiver
 * vazia, troque para true. Aí a proteção passa a valer de verdade.
 */

if (defined('SEG_SESSAO_CARREGADO')) return;
define('SEG_SESSAO_CARREGADO', true);

// ─── Parâmetros ──────────────────────────────────────────────────────────────
/**
 * true  = recusa POST sem token (proteção valendo)
 * false = apenas registra em DEV → Ameaças e deixa passar
 *
 * Ligado em 17/08/2026, depois de um período em observação com a lista zerada.
 * Se alguma tela começar a acusar "Ação não confirmada", volte para false,
 * suba o arquivo, e a tela funciona de novo na hora — o registro continua
 * apontando exatamente qual endpoint precisa de ajuste.
 */
const SEG_CSRF_BLOQUEAR   = true;
const SEG_INATIVIDADE_MIN = 30;     // minutos sem mexer no teclado/mouse
const SEG_SESSAO_MAX_HORAS = 12;    // duração máxima de uma sessão

/**
 * Configura o cookie de sessão. TEM de rodar antes de qualquer session_start —
 * por isso é chamada pelo dev_captura.php, que o PHP carrega antes de toda
 * página (ver .user.ini).
 */
function seg_configurar_cookie(): void {
    if (PHP_SAPI === 'cli') return;
    if (session_status() !== PHP_SESSION_NONE) return;   // já começou, tarde demais

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    // HttpOnly: JavaScript não lê o cookie. Se um dia aparecer um XSS, ele não
    // consegue roubar a sessão junto.
    // SameSite=Lax: o navegador não envia o cookie em POST vindo de outro site,
    // o que já barra boa parte do CSRF por conta própria.
    // Secure só com HTTPS — em HTTP o cookie seria descartado e ninguém entraria.
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    if ($https) @ini_set('session.cookie_secure', '1');
}

/**
 * Token da sessão atual, criado na primeira chamada.
 *
 * O valor fica em cache na própria função porque algumas telas chamam
 * session_write_close() no início — o dev_painel.php faz isso para as
 * requisições AJAX não ficarem em fila esperando o lock da sessão. Com a sessão
 * fechada, $_SESSION não é mais legível, e o token injetado no fim da página
 * sairia vazio. Era exatamente por isso que o painel aparecia como a única tela
 * sem token: a proteção falhava justamente no alvo de maior valor.
 */
function seg_token(): string {
    static $cache = '';

    if (session_status() === PHP_SESSION_ACTIVE) {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        $cache = $_SESSION['_csrf'];
        return $cache;
    }

    return $cache;   // sessão já encerrada nesta requisição
}

/**
 * Classe do usuário, com cache pelo mesmo motivo do token: telas que chamam
 * session_write_close() no início deixam $_SESSION ilegível no momento em que
 * o rodapé da página é montado. Sem o cache, o atalho do painel não seria
 * emitido justamente nas telas onde o DEV mais trabalha.
 */
function seg_classe_cache(): string {
    static $cache = '';
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['classe_usuario'])) {
        $cache = strtoupper(trim((string)$_SESSION['classe_usuario']));
    }
    return $cache;
}

/**
 * Publica o token num cookie legível por JavaScript.
 *
 * POR QUE ISSO É NECESSÁRIO:
 * o script que anexa o token é acrescentado ao FIM do documento (auto_append).
 * Scripts executam na ordem do documento, então o código da própria página roda
 * antes — e telas que disparam requisições já no carregamento saíam sem token.
 * Foi o que aconteceu no painel DEV com listar_usuarios, erros_listar e outras.
 * O cookie existe desde o primeiro byte da resposta, então qualquer script o
 * encontra, a qualquer momento.
 *
 * POR QUE ISSO CONTINUA SEGURO:
 * é o padrão conhecido como "double submit cookie". O navegador envia o cookie
 * sozinho em qualquer requisição, mas o servidor compara o cookie com o valor
 * recebido no cabeçalho ou no corpo — e um site atacante NÃO consegue ler o
 * cookie (política de mesma origem) nem definir cabeçalhos numa requisição
 * disparada de fora. Sem poder copiar o valor, ele não passa da comparação.
 *
 * Este cookie NÃO é HttpOnly de propósito: o JavaScript precisa lê-lo. Ele não
 * dá acesso a nada por si só — o cookie de sessão, que dá, continua HttpOnly.
 */
function seg_publicar_cookie(): void {
    if (headers_sent()) return;

    $token = seg_token();
    if ($token === '') return;
    if (($_COOKIE['pat_csrf'] ?? '') === $token) return;   // já está lá

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    @setcookie('pat_csrf', $token, [
        'expires'  => 0,          // dura só a sessão do navegador
        'path'     => '/',
        'secure'   => $https,
        'httponly' => false,      // intencional: o JavaScript precisa ler
        'samesite' => 'Lax',
    ]);
    $_COOKIE['pat_csrf'] = $token;
}

/** Campo escondido para colocar dentro de <form> */
function seg_campo(): string {
    $t = seg_token();
    return $t === '' ? '' : '<input type="hidden" name="_csrf" value="' . $t . '">';
}

/** Onde o token chegou na requisição */
function seg_token_recebido(): string {
    if (!empty($_POST['_csrf']))                    return (string)$_POST['_csrf'];
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN']))      return (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    return '';
}

/**
 * Requisições que não passam pela validação.
 * O login não pode exigir token: quem está entrando ainda não tem sessão.
 * O receptor de erros de JavaScript também não, porque o erro pode acontecer
 * justamente na tela de login.
 */
function seg_isenta(): bool {
    $arquivo = basename($_SERVER['PHP_SELF'] ?? '');
    return in_array($arquivo, [
        'login.php',
        'heartbeat.php',
        'dev_log_js.php',
        'logout.php',
    ], true);
}

/**
 * Verificação principal, chamada pelo conexao.php.
 * Cuida de três coisas: sessão velha demais, inatividade e token.
 */
function seg_guardar(): void {
    if (PHP_SAPI === 'cli') return;
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if (empty($_SESSION['usuario_logado'])) return;

    $agora = time();

    // ── Idade máxima da sessão ───────────────────────────────────────────────
    if (empty($_SESSION['_inicio'])) $_SESSION['_inicio'] = $agora;
    if ($agora - (int)$_SESSION['_inicio'] > SEG_SESSAO_MAX_HORAS * 3600) {
        seg_encerrar('Sua sessão expirou. Entre novamente.');
    }

    $_SESSION['_ultimo'] = $agora;

    // Cria e guarda o token enquanto a sessão ainda está aberta. Páginas que
    // chamam session_write_close() depois disso continuam conseguindo emitir o
    // token no fim do HTML, lendo do cache da função.
    seg_token();
    seg_classe_cache();     // guarda a classe enquanto a sessão está aberta
    seg_publicar_cookie();

    // ── CSRF ────────────────────────────────────────────────────────────────
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    if (seg_isenta()) return;

    $esperado = $_SESSION['_csrf'] ?? '';
    $recebido = seg_token_recebido();

    // hash_equals compara em tempo constante — comparação com == permitiria
    // descobrir o token byte a byte medindo o tempo de resposta.
    if ($esperado !== '' && $recebido !== '' && hash_equals($esperado, $recebido)) {
        return;   // token válido — caminho normal
    }

    /* ── Segunda via: metadados de fetch do navegador ────────────────────────
     * Dez telas do sistema disparam requisições já no carregamento, antes de o
     * script que anexa o token existir — ele é acrescentado ao FIM do
     * documento. Exigir só o token derrubava essas telas.
     *
     * Sec-Fetch-Site é definido pelo próprio navegador e está na lista de
     * cabeçalhos proibidos: JavaScript não consegue alterá-lo. Numa requisição
     * disparada por outro site, o navegador envia "cross-site"; na mesma
     * origem, "same-origin". Isso dá a mesma garantia que o token para o caso
     * que interessa, sem depender da ordem de carregamento dos scripts.
     *
     * Navegador antigo que não envie o cabeçalho cai na exigência do token.
     */
    $origem_fetch = strtolower(trim($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));

    if ($origem_fetch === 'same-origin') {
        // Requisição da própria aplicação. Registra sem bloquear, para
        // continuarmos vendo quais telas ainda não mandam o token.
        if ($recebido === '') {
            try {
                require_once __DIR__ . '/dev_seguranca.php';
                dev_registrar_ameaca([
                    'tipo'         => 'CSRF_SUSPEITO',
                    'severidade'   => 'BAIXA',
                    'usuario_alvo' => (string)$_SESSION['usuario_logado'],
                    'pagina'       => basename($_SERVER['PHP_SELF'] ?? '') . ':sem-token',
                    'detalhe'      => 'POST sem token, aceito por Sec-Fetch-Site: same-origin. '
                                    . 'Ação: ' . substr((string)($_POST['action'] ?? $_POST['acao'] ?? '—'), 0, 60),
                ]);
            } catch (Throwable $e) {}
        }
        return;
    }

    if ($origem_fetch !== '' && $origem_fetch !== 'same-origin') {
        // O navegador afirma que a requisição veio de fora. Isso é CSRF.
        $detalhe_ext = 'Requisição de outra origem (Sec-Fetch-Site: ' . $origem_fetch . ')';
    }

    // ── Falhou ──────────────────────────────────────────────────────────────
    $pagina = basename($_SERVER['PHP_SELF'] ?? '');

    // A ação é o que identifica o chamador de verdade. Vários endpoints do
    // sistema atendem dezenas de ações no mesmo arquivo — saber apenas
    // "dev_painel.php" não diz qual função do JavaScript está sem o token.
    $acao = '';
    foreach (['action', 'acao', 'op', 'modo'] as $chave) {
        if (!empty($_POST[$chave]) && is_string($_POST[$chave])) {
            $acao = substr($_POST[$chave], 0, 60);
            break;
        }
    }

    $detalhe = 'POST sem token em ' . $pagina
             . ($acao !== '' ? ' → ação "' . $acao . '"' : ' (sem parâmetro de ação)')
             . ' | origem: ' . ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '(sem referência)')
             . ' | ' . ($recebido === '' ? 'token ausente' : 'token diferente do da sessão')
             . (isset($detalhe_ext) ? ' | ' . $detalhe_ext : '');

    try {
        require_once __DIR__ . '/dev_seguranca.php';
        dev_registrar_ameaca([
            'tipo'         => 'CSRF_SUSPEITO',
            'severidade'   => SEG_CSRF_BLOQUEAR ? 'ALTA' : 'BAIXA',
            'usuario_alvo' => (string)$_SESSION['usuario_logado'],
            // A página+ação entram no "alvo" da chave de agrupamento para que
            // cada endpoint gere a SUA linha. Agrupando tudo junto, 62
            // violações de origens diferentes viravam uma linha só, mostrando
            // apenas a última — foi o que nos impediu de achar a causa.
            'pagina'       => $pagina . ($acao !== '' ? ':' . $acao : ''),
            'detalhe'      => $detalhe . (SEG_CSRF_BLOQUEAR ? '' : ' [modo observação — passou]'),
        ]);
    } catch (Throwable $e) {}

    if (!SEG_CSRF_BLOQUEAR) return;   // observação: deixa passar

    http_response_code(419);
    $json = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'json') !== false
         || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
         || !empty($_POST['ajax']);

    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'ok' => false,
            'mensagem' => 'Sessão inválida para esta ação. Recarregue a página e tente novamente.']);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="UTF-8"><body style="font-family:Arial;padding:40px;'
           . 'background:#0f172a;color:#e2e8f0;line-height:1.7">'
           . '<h2 style="color:#fbbf24">Ação não confirmada</h2>'
           . '<p>Esta ação não pôde ser validada. Isso acontece quando a página ficou aberta '
           . 'por muito tempo, ou quando o pedido não veio do sistema.</p>'
           . '<p><a href="javascript:history.back()" style="color:#60a5fa">Voltar</a></p></body>';
    }
    exit;
}

/**
 * Barra requisição sem login.
 *
 * POR QUE ISTO EXISTE SEPARADO DE seg_guardar()
 * seg_guardar() começa com `if (empty($_SESSION['usuario_logado'])) return;`
 * e isso é deliberado: ela endurece a sessão de quem entrou (idade máxima,
 * inatividade, CSRF), e páginas públicas de propósito — abertura de chamado
 * por QR Code, login, index — precisam continuar funcionando.
 *
 * O efeito colateral é que ela NÃO autentica ninguém. Um endpoint que apenas
 * inclui conexao.php e confia em seg_guardar() está aberto a requisição
 * anônima. Quem exige login precisa dizer isso explicitamente, chamando esta
 * função. Só o autor do endpoint sabe se ele é público ou não; o include não
 * tem como adivinhar.
 *
 * Responde em JSON quando o chamador é AJAX, para o JavaScript da tela não
 * tentar interpretar HTML como resposta.
 */
function seg_exigir_login(): void {
    if (PHP_SAPI === 'cli') return;
    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (!empty($_SESSION['usuario_logado'])) return;

    // Registra: requisição anônima a endpoint fechado não acontece por acaso.
    try {
        require_once __DIR__ . '/dev_seguranca.php';
        dev_registrar_ameaca([
            'tipo'         => 'ACESSO_SEM_LOGIN',
            'severidade'   => 'MEDIA',
            'usuario_alvo' => '(anônimo)',
            'pagina'       => basename($_SERVER['PHP_SELF'] ?? ''),
            'detalhe'      => 'Requisição sem sessão a endpoint que exige login'
                            . ' | método: ' . ($_SERVER['REQUEST_METHOD'] ?? '?')
                            . ' | origem: ' . ($_SERVER['HTTP_REFERER'] ?? '(sem referência)'),
        ]);
    } catch (Throwable $e) {}

    http_response_code(401);

    $json = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'json') !== false
         || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
         || !empty($_POST['ajax']);

    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso'  => false,
            'ok'       => false,
            'expirado' => true,
            'mensagem' => 'Sessão expirada. Entre novamente.'
        ]);
    } else {
        header('Location: index.html?error=' . urlencode('Faça login para continuar.'));
    }
    exit;
}

/**
 * Exige permissão E classe, conferidas no servidor.
 *
 * POR QUE ISTO EXISTE
 * O controle de acesso do sistema estava só nas páginas: `planilha.php`
 * desabilitava o botão Salvar para o nível C, `dash.php` redirecionava quem
 * não fosse A, e assim por diante. Mas o botão desabilitado é só no navegador.
 * Os endpoints por trás — `salvar_planilha.php`, `movimentar_action.php`,
 * `indicadores_dados.php` — conferiam apenas se havia login, não o nível nem a
 * classe. Um usuário C, vendo a planilha em modo leitura, conseguia editar
 * chamando o endpoint direto; um usuário de ENGENHARIA CLINICA conseguia ler
 * dados de patrimônio que a tela nunca ofereceria a ele.
 *
 * Autorização é decisão do servidor. A tela apenas reflete o que o servidor já
 * decidiu — esconder o botão é cortesia com o usuário, não proteção.
 *
 * Esta função repete, num só lugar, a regra que as páginas já aplicam: carrega
 * permissão, classe e status do banco (nunca da sessão, que não é reconsultada
 * quando um admin altera o usuário) e barra quem não atende.
 *
 * @param mysqli $conn   Conexão já aberta.
 * @param array  $niveis Permissões aceitas, ex.: ['A','B'].
 * @param array  $classes Classes aceitas. Padrão: os donos do patrimônio.
 * @param bool   $json   true responde JSON (endpoint AJAX); false redireciona
 *                       (página ou download).
 * @return array{permicao:string,classe_usuario:string} Dados do usuário atual.
 */
function seg_exigir_permissao(
    mysqli $conn,
    array $niveis,
    array $classes = ['DEV', 'PATRIMONIO'],
    bool $json = true
): array {
    seg_exigir_login();   // sem sessão nem chega aqui

    $usuario = (string)($_SESSION['usuario_logado'] ?? '');

    $permicao = '';
    $classe   = '';
    $status   = 'ATIVO';

    $stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario = ?");
    if ($stmt) {
        $stmt->bind_param('s', $usuario);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $permicao = (string)($row['permicao'] ?? '');
            $classe   = (string)($row['classe_usuario'] ?? '');
            $status   = (string)($row['status'] ?? 'ATIVO');
        }
        $stmt->close();
    }

    // Conta desativada enquanto a sessão seguia aberta: encerra na hora.
    if ($status !== 'ATIVO') {
        seg_encerrar('Sua conta foi desativada. Entre novamente.');
    }

    if (in_array($permicao, $niveis, true) && in_array($classe, $classes, true)) {
        return ['permicao' => $permicao, 'classe_usuario' => $classe];
    }

    // Registra: acesso a ação acima do nível não acontece por acaso — é o
    // usuário chamando o endpoint direto, contornando o que a tela esconde.
    try {
        require_once __DIR__ . '/dev_seguranca.php';
        dev_registrar_ameaca([
            'tipo'         => 'ACESSO_SEM_PERMISSAO',
            'severidade'   => 'MEDIA',
            'usuario_alvo' => $usuario !== '' ? $usuario : '(desconhecido)',
            'pagina'       => basename($_SERVER['PHP_SELF'] ?? ''),
            'detalhe'      => 'Permissão/classe insuficiente. '
                            . 'Tem: ' . ($permicao !== '' ? $permicao : '—') . '/' . ($classe !== '' ? $classe : '—')
                            . ' | Exige: ' . implode('|', $niveis) . ' + ' . implode('|', $classes),
        ]);
    } catch (Throwable $e) {}

    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso'  => false,
            'ok'       => false,
            'mensagem' => 'Você não tem permissão para esta ação.'
        ]);
    } else {
        header('Location: acesso_bloqueado.html');
    }
    exit;
}

/** Encerra a sessão e devolve o usuário ao login */
function seg_encerrar(string $motivo): void {
    $json = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    @session_unset();
    @session_destroy();

    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'ok' => false, 'expirado' => true, 'mensagem' => $motivo]);
    } else {
        header('Location: index.html?error=' . urlencode($motivo));
    }
    exit;
}
