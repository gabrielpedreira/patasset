<?php
session_start();
include 'conexao.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_logado'])) {
    echo json_encode(['ok' => false, 'msg' => 'Sessão expirada.']);
    exit();
}

$usuario = $_SESSION['usuario_logado'];
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
$stmt->bind_param("s", $usuario);
$stmt->execute();
$res = $stmt->get_result();
$nivel = 'C'; $classe_usuario = ''; $status = 'ATIVO';
if ($r = $res->fetch_assoc()) {
    $nivel          = strtoupper(trim($r['permicao']));
    $classe_usuario = strtoupper(trim($r['classe_usuario']));
    $status         = $r['status'] ?? 'ATIVO';
}
$stmt->close();

if ($status !== 'ATIVO' || !in_array($classe_usuario, ['DEV', 'ENGENHARIA CLINICA']) || $nivel !== 'A') {
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método inválido.']);
    exit();
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
    exit();
}

// Exclusão lógica — mantém o registro mas marca como inativo
$upd = $conn->prepare("UPDATE fornecedores SET ativo = 0 WHERE id = ?");
$upd->bind_param("i", $id);

if ($upd->execute() && $upd->affected_rows > 0) {
    $upd->close();
    $conn->close();
    echo json_encode(['ok' => true]);
} else {
    $err = $upd->error;
    $upd->close();
    $conn->close();
    echo json_encode(['ok' => false, 'msg' => 'Registro não encontrado ou erro: ' . $err]);
}
?>