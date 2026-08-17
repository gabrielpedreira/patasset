<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('Content-Type: application/json');
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}
include "conexao.php";

$data = json_decode(file_get_contents("php://input"),true);

$id = $data['id'] ?? 0;

$stmt = $conn->prepare("DELETE FROM cronograma WHERE id=?");
$stmt->bind_param("i",$id);

$ok = $stmt->execute();

echo json_encode(["ok"=>$ok]);
