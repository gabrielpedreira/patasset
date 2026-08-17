<?php
/**
 * check_session.php
 * Inclua após session_start() e include 'conexao.php' em cada página protegida.
 */

if (!isset($_SESSION['usuario_logado'])) {
    header('Location: index.html');
    exit;
}

try {
    $_cs_sid  = session_id();
    $_cs_stmt = $conn->prepare("SELECT revogada FROM usuarios_online WHERE session_id = ? LIMIT 1");
    if ($_cs_stmt) {
        $_cs_stmt->bind_param('s', $_cs_sid);
        $_cs_stmt->execute();
        $_cs_result = $_cs_stmt->get_result();
        $_cs_row    = $_cs_result ? $_cs_result->fetch_assoc() : null;
        $_cs_stmt->close();

        if ($_cs_row && (int)$_cs_row['revogada'] === 1) {
            // Remove do banco para o heartbeat não recriar
            $conn->query("DELETE FROM usuarios_online WHERE session_id = '" . $conn->real_escape_string($_cs_sid) . "'");
            session_unset();
            session_destroy();
            header('Location: index.html?error=Sua+sessao+foi+encerrada');
            exit;
        }

        // ultimo_acesso atualizado pelo heartbeat
    }
    unset($_cs_sid, $_cs_stmt, $_cs_result, $_cs_row);
} catch (Throwable $e) {
    // Captura qualquer erro (Exception ou Error) sem quebrar a página
}