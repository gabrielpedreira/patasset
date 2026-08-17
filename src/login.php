<?php
ob_start();
session_start();
ini_set('display_errors', 0);
error_reporting(0);
require_once 'conexao.php';
require_once 'login_seguranca.php';
require_once 'dev_seguranca.php';

$usuario = trim($_POST['usuario'] ?? '');
$senha   = $_POST['senha']        ?? '';
$isAjax  = !empty($_POST['ajax']);

function jsonResponse(array $payload): void {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

// Só aceita POST. Bloqueia varredura por GET com senha na URL.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.html');
    exit;
}

// Campo-armadilha: invisível no formulário, então só um robô preenche.
if (trim($_POST['website'] ?? '') !== '') {
    sleep(2);
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Usuário ou senha incorretos.']);
    header('Location: index.html?error=Usu%C3%A1rio+ou+senha+incorretos');
    exit;
}

if ($usuario === '' || $senha === '') {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Preencha usuário e senha.']);
    header('Location: index.html?error=Preencha+usuario+e+senha');
    exit;
}

// ── IP da rede ────────────────────────────────────────────────────────────────
$ip_rede = trim(explode(',',
    $_SERVER['HTTP_X_FORWARDED_FOR'] ??
    $_SERVER['HTTP_X_REAL_IP']       ??
    $_SERVER['REMOTE_ADDR']          ?? ''
)[0]);

// ── User-Agent ────────────────────────────────────────────────────────────────
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

// ── PROTEÇÃO CONTRA FORÇA BRUTA ──────────────────────────────────────────────
// Contagem separada por usuário (5 falhas → 15 min) e por IP (20 falhas).
// Ver login_seguranca.php. Defensivo: se a tabela não existir, o login segue.
$bloqueio_msg = null;
$bloqueio_seg = 0;
try {
    $bloqueio_msg = ls_verificar($conn, $usuario, $ip_rede);
    if ($bloqueio_msg !== null) {
        // Segundos exatos, para a tela mostrar contagem regressiva confiável
        // em vez de tentar adivinhar pelo texto da mensagem.
        $eu = ls_estado($conn, 'USUARIO', $usuario);
        $ei = ls_estado($conn, 'IP', $ip_rede);
        $bloqueio_seg = max($eu['segundos'], $ei['segundos']);
    }
} catch (Throwable $e) {
    $bloqueio_msg = null;
}

if ($bloqueio_msg !== null) {
    try {
        $stmt_rl = $conn->prepare("
            INSERT INTO historico_acessos (usuario, ip_rede, user_agent, resultado)
            VALUES (?, ?, ?, 'BLOQUEADO')
        ");
        if ($stmt_rl) {
            $stmt_rl->bind_param('sss', $usuario, $ip_rede, $user_agent);
            $stmt_rl->execute();
            $stmt_rl->close();
        }
    } catch (Throwable $e) {}

    // Registra como ameaça no painel do DEV. Tentativas contra um bloqueio já
    // ativo são mais graves que as primeiras: quem insiste depois de barrado
    // não é alguém que esqueceu a senha.
    try {
        dev_registrar_ameaca([
            'tipo'         => 'FORCA_BRUTA',
            'severidade'   => 'ALTA',
            'usuario_alvo' => $usuario,
            'ip_rede'      => $ip_rede,
            'ip_local'     => (string)($_POST['ip_local'] ?? ''),
            'user_agent'   => $user_agent,
            'pagina'       => 'login.php',
            'detalhe'      => 'Tentativa de login durante bloqueio ativo. ' . $bloqueio_msg,
        ]);
    } catch (Throwable $e) {}

    if ($isAjax) jsonResponse([
        'success'   => false,
        'message'   => $bloqueio_msg,
        'bloqueado' => true,
        'segundos'  => $bloqueio_seg,
    ]);
    header('Location: index.html?error=' . urlencode($bloqueio_msg));
    exit;
}

// Quantas falhas recentes este usuário tem — define o atraso da resposta
$falhas_recentes = 0;
try {
    $falhas_recentes = ls_estado($conn, 'USUARIO', $usuario)['tentativas'];
} catch (Throwable $e) {}
// ─────────────────────────────────────────────────────────────────────────────

// ── Detecta se tabela e colunas existem ──────────────────────────────────────
function tabelaExiste($conn, $tabela) {
    $r = $conn->query("SHOW TABLES LIKE '$tabela'");
    return $r && $r->num_rows > 0;
}
function colunaExiste($conn, $tabela, $coluna) {
    $r = $conn->query("SHOW COLUMNS FROM `$tabela` LIKE '$coluna'");
    return $r && $r->num_rows > 0;
}

$hist_existe        = tabelaExiste($conn, 'historico_acessos');
$hist_tem_resultado = $hist_existe && colunaExiste($conn, 'historico_acessos', 'resultado');
$hist_tem_ua        = $hist_existe && colunaExiste($conn, 'historico_acessos', 'user_agent');
$hist_tem_ip_rede   = $hist_existe && colunaExiste($conn, 'historico_acessos', 'ip_rede');
$hist_tem_classe    = $hist_existe && colunaExiste($conn, 'historico_acessos', 'classe_usuario');
$hist_tem_permicao  = $hist_existe && colunaExiste($conn, 'historico_acessos', 'permicao');

// ── Registra no histórico de acessos ─────────────────────────────────────────
function registrarHistorico($conn, $usuario, $permicao, $classe, $ip_rede, $user_agent, $resultado,
    $hist_existe, $hist_tem_resultado, $hist_tem_ua, $hist_tem_ip_rede,
    $hist_tem_classe, $hist_tem_permicao) {

    if (!$hist_existe) return;

    $cols   = ['usuario'];
    $vals   = ['?'];
    $types  = 's';
    $params = [$usuario];

    if ($hist_tem_permicao)  { $cols[]='permicao';       $vals[]='?'; $types.='s'; $params[]=($permicao ?: 'N/A'); }
    if ($hist_tem_classe)    { $cols[]='classe_usuario'; $vals[]='?'; $types.='s'; $params[]=($classe   ?: 'N/A'); }
    if ($hist_tem_ip_rede)   { $cols[]='ip_rede';        $vals[]='?'; $types.='s'; $params[]=$ip_rede; }
    if ($hist_tem_ua)        { $cols[]='user_agent';     $vals[]='?'; $types.='s'; $params[]=$user_agent; }
    if ($hist_tem_resultado) { $cols[]='resultado';      $vals[]='?'; $types.='s'; $params[]=$resultado; }

    $sql  = "INSERT INTO historico_acessos (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
}

// ── Busca usuário ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT senha, permicao, classe_usuario, status FROM usuarios WHERE usuario = ? LIMIT 1");
$stmt->bind_param('s', $usuario);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows === 1) {
    $row           = $result->fetch_assoc();
    $senhaBanco    = $row['senha'];
    $permicao      = $row['permicao'];
    $classeUsuario = strtoupper(trim($row['classe_usuario'] ?? ''));
    $status        = $row['status'] ?? 'ATIVO';

    // Aceita senha em texto puro (legado) ou com hash
    $valid = ($senha === $senhaBanco) || password_verify($senha, $senhaBanco);

    if ($valid) {

        // ── Usuário bloqueado pelo DEV ────────────────────────────────────────
        if ($status !== 'ATIVO') {
            registrarHistorico($conn, $usuario, $permicao, $classeUsuario,
                $ip_rede, $user_agent, 'BLOQUEADO',
                $hist_existe, $hist_tem_resultado, $hist_tem_ua,
                $hist_tem_ip_rede, $hist_tem_classe, $hist_tem_permicao);
            if ($isAjax) jsonResponse(['success' => false, 'message' => 'Acesso bloqueado. Contate o administrador.']);
            header('Location: index.html?error=Acesso+bloqueado');
            exit;
        }

        // ── Login válido — cria sessão ────────────────────────────────────────
        try { ls_registrar_sucesso($conn, $usuario); } catch (Throwable $e) {}

        // Novo ID de sessão: impede que um ID plantado antes do login (fixação
        // de sessão) continue valendo depois que o usuário se autentica.
        session_regenerate_id(true);

        $_SESSION['usuario_logado']  = $usuario;
        $_SESSION['permicao']        = $permicao;
        $_SESSION['classe_usuario']  = $classeUsuario;
        $_SESSION['mostrar_loading'] = true;

        $sid = session_id();
        $now = date('Y-m-d H:i:s');

        // Registra em usuarios_online
        $conn->query("DELETE FROM usuarios_online WHERE usuario = '" . $conn->real_escape_string($usuario) . "'");
        $stmt2 = $conn->prepare("
            INSERT IGNORE INTO usuarios_online
                (session_id, usuario, classe_usuario, ip, ultimo_acesso, login_em, revogada)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ");
        $stmt2->bind_param('ssssss', $sid, $usuario, $classeUsuario, $ip_rede, $now, $now);
        $stmt2->execute();
        $stmt2->close();

        // Registra em historico_acessos
        registrarHistorico($conn, $usuario, $permicao, $classeUsuario,
            $ip_rede, $user_agent, 'OK',
            $hist_existe, $hist_tem_resultado, $hist_tem_ua,
            $hist_tem_ip_rede, $hist_tem_classe, $hist_tem_permicao);

        if ($isAjax) jsonResponse(['success' => true, 'classe' => $classeUsuario]);

        switch ($classeUsuario) {
            case 'PATRIMONIO':         header('Location: inicial.php');                   break;
            case 'ENGENHARIA CLINICA': header('Location: engenharia_clinica_inicial.php'); break;
            case 'DEV':                header('Location: dev_painel.php');                break;
            default:                   header('Location: index.html?error=Classe+de+usuario+nao+reconhecida');
        }
        exit;
    }
}

// ── Login inválido ────────────────────────────────────────────────────────────
registrarHistorico($conn, $usuario, 'N/A', 'N/A',
    $ip_rede, $user_agent, 'FALHA',
    $hist_existe, $hist_tem_resultado, $hist_tem_ua,
    $hist_tem_ip_rede, $hist_tem_classe, $hist_tem_permicao);

try { ls_registrar_falha($conn, $usuario, $ip_rede); } catch (Throwable $e) {}

// Atraso crescente: quem errou uma vez quase não percebe, mas um script que
// tenta senhas em sequência fica limitado a poucas tentativas por minuto.
ls_atraso($falhas_recentes);

// Avisa quantas tentativas restam — sem dizer se o usuário existe.
$restantes  = LS_MAX_USUARIO - ($falhas_recentes + 1);
$msg_erro   = 'Usuário ou senha incorretos.';
$agora_bloq = false;
if ($restantes > 0 && $restantes <= 2) {
    $msg_erro .= ' Restam ' . $restantes . ' tentativa' . ($restantes === 1 ? '' : 's')
               . ' antes do bloqueio de ' . LS_BLOQUEIO_MIN . ' minutos.';
} elseif ($restantes <= 0) {
    // Esta foi a tentativa que estourou o limite: o bloqueio já está gravado.
    $msg_erro   = 'Muitas tentativas. Aguarde ' . LS_BLOQUEIO_MIN . ' minutos e tente novamente.';
    $agora_bloq = true;

    try {
        // Usuário inexistente indica varredura de nomes, não esquecimento de senha
        $usuario_existe = false;
        $stEx = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ? LIMIT 1");
        if ($stEx) {
            $stEx->bind_param('s', $usuario);
            $stEx->execute();
            $rEx = $stEx->get_result();
            $usuario_existe = (bool)($rEx && $rEx->fetch_assoc());
            $stEx->close();
        }

        dev_registrar_ameaca([
            'tipo'         => 'FORCA_BRUTA',
            'severidade'   => $usuario_existe ? 'ALTA' : 'CRITICA',
            'usuario_alvo' => $usuario,
            'ip_rede'      => $ip_rede,
            'ip_local'     => (string)($_POST['ip_local'] ?? ''),
            'user_agent'   => $user_agent,
            'pagina'       => 'login.php',
            'detalhe'      => LS_MAX_USUARIO . ' senhas erradas seguidas para "' . $usuario . '". '
                            . ($usuario_existe
                                ? 'Usuário existe — pode ser esquecimento de senha ou ataque dirigido.'
                                : 'Usuário NÃO existe no sistema — indica varredura de nomes de conta.'),
        ]);
    } catch (Throwable $e) {}
}

if ($isAjax) jsonResponse([
    'success'   => false,
    'message'   => $msg_erro,
    'bloqueado' => $agora_bloq,
    'segundos'  => $agora_bloq ? LS_BLOQUEIO_MIN * 60 : 0,
]);
header('Location: index.html?error=' . urlencode($msg_erro));
exit;