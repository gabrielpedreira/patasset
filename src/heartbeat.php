<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SESSION['usuario_logado'])) {
    echo json_encode(['ok' => false, 'revogada' => false]);
    exit;
}

require_once 'conexao.php';

$sid     = session_id();
$usuario = $_SESSION['usuario_logado'];

// IP da rede
$ip_rede = trim(explode(',',
    $_SERVER['HTTP_X_FORWARDED_FOR'] ??
    $_SERVER['HTTP_X_REAL_IP']       ??
    $_SERVER['REMOTE_ADDR']          ?? ''
)[0]);

// Verifica se sessão está revogada
$stmt = $conn->prepare("SELECT revogada FROM usuarios_online WHERE session_id = ? LIMIT 1");
$stmt->bind_param('s', $sid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row && (int)$row['revogada'] === 1) {
    session_unset();
    session_destroy();
    echo json_encode(['ok' => false, 'revogada' => true]);
    exit;
}

// Atualiza último acesso e ip
$stmt = $conn->prepare("UPDATE usuarios_online SET ultimo_acesso = NOW(), ip = ? WHERE session_id = ?");
$stmt->bind_param('ss', $ip_rede, $sid);
$stmt->execute();
$stmt->close();

// Verifica se usuário foi bloqueado
$stmt = $conn->prepare("SELECT status FROM usuarios WHERE usuario = ? LIMIT 1");
$stmt->bind_param('s', $usuario);
$stmt->execute();
$urow   = $stmt->get_result()->fetch_assoc();
$status = $urow['status'] ?? 'ATIVO';
$stmt->close();

if ($status !== 'ATIVO') {
    $stmt = $conn->prepare("UPDATE usuarios_online SET revogada = 1 WHERE session_id = ?");
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $stmt->close();
    session_unset();
    session_destroy();
    echo json_encode(['ok' => false, 'revogada' => true]);
    exit;
}

echo json_encode(['ok' => true, 'revogada' => false]);
