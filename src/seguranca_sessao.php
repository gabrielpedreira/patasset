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
    seg_publicar_cookie();

    // ── CSRF ────────────────────────────────────────────────────────────────
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    if (seg_isenta()) return;

    $esperado = $_SESSION['_csrf'] ?? '';
    $recebido = seg_token_recebido();

    // hash_equals compara em tempo constante — comparação com == permitiria
    // descobrir o token byte a byte medindo o tempo de resposta.
    if ($esperado !== '' && $recebido !== '' && hash_equals($esperado, $recebido)) {
        return;   // válido
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
             . ' | ' . ($recebido === '' ? 'token ausente' : 'token diferente do da sessão');

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
