<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([]);
    exit;
}
require_once "conexao.php";

header("Content-Type: application/json; charset=UTF-8");

$ultimoId = isset($_GET['ultimo_id']) ? intval($_GET['ultimo_id']) : 0;

$sql = "SELECT 
            id, status, realizado, data_movimentacao, folha, destino,
            grupo, classe, subgrupo,
            unidade, setor, pavimento, area,
            tag_antiga, tag_trocada, tag_nova,
            propriedade, unidade_localizacao,
            descricao, marca, modelo, serie,
            observacao, usuario_cadastro,
            data_inspecao, usuario_inspecao, encontrado
        FROM cadastro
        WHERE id > ?
        ORDER BY id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $ultimoId);
$stmt->execute();

$result = $stmt->get_result();
$novos = [];

while($row = $result->fetch_assoc()){
    $novos[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode($novos, JSON_UNESCAPED_UNICODE);
exit;
