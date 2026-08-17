<?php
session_start();
include 'conexao.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_logado'])) {
    echo json_encode([]);
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
    echo json_encode([]);
    exit();
}

$result = $conn->query(
    "SELECT id, razao_social, nome_fantasia, cnpj, area_atuacao,
            cidade, estado, telefone_principal, email_comercial, data_cadastro
     FROM fornecedores
     WHERE ativo = 1
     ORDER BY razao_social ASC"
);

$lista = [];
while ($row = $result->fetch_assoc()) {
    $lista[] = $row;
}

$conn->close();
echo json_encode($lista);
?>