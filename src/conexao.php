<?php
// Credenciais em /home/usuario/config_sistema/segredos.php, fora de
// public_html — ver config_seguro.php.
require_once __DIR__ . '/config_seguro.php';

$host     = PAT_DB_HOST;
$user     = PAT_DB_USER;
$password = PAT_DB_PASS;
$dbname   = PAT_DB_NAME;

$conn = new mysqli($host, $user, $password, $dbname);


if ($conn->connect_error) {
    die("Falha na conexão: " . $conn->connect_error);
}

// ─── Sessão e CSRF ───────────────────────────────────────────────────────────
// Entra aqui porque o conexao.php é incluído por praticamente toda página, e
// sempre DEPOIS do session_start dela — que é exatamente o momento em que dá
// para checar sessão e token. Ver seguranca_sessao.php.
require_once __DIR__ . '/seguranca_sessao.php';
seg_guardar();

// ─── Modo Manutenção ─────────────────────────────────────────────────────────
// Redireciona usuários não-DEV para manutencao.php quando o flag está ativo.
// DEV continua com acesso normal. Páginas excluídas: manutencao.php, index.*
$_mnt_flag   = __DIR__ . '/manutencao.flag';
$_mnt_script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
$_mnt_excluir = ['manutencao.php', 'index.php', 'index.html', 'login.php'];

if (file_exists($_mnt_flag) && !in_array($_mnt_script, $_mnt_excluir)) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $__classe = strtoupper(trim($_SESSION['classe_usuario'] ?? ''));
    if ($__classe !== 'DEV') {
        header('Location: manutencao.php');
        exit;
    }
}
// ─────────────────────────────────────────────────────────────────────────────

?>
