<?php
session_start();
require_once 'conexao.php';

// ── Remove registro de online do banco ───────────────────────────────────────
$sid = session_id();
if ($sid) {
    $stmt = $conn->prepare("DELETE FROM usuarios_online WHERE session_id = ?");
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $stmt->close();
}

// ── Encerra sessão ───────────────────────────────────────────────────────────
session_unset();
session_destroy();

// Saída automática por inatividade avisa o motivo — sem isso o usuário volta,
// vê a tela de login e acha que o sistema caiu.
if (($_GET['motivo'] ?? '') === 'inatividade') {
    header('Location: index.html?error=' . urlencode(
        'Sessão encerrada por inatividade. Entre novamente.'));
    exit();
}

header("Location: index.html");
exit();