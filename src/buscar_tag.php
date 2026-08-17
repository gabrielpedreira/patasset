<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('Content-Type: application/json');
    echo json_encode(['encontrado' => false]);
    exit;
}
include 'conexao.php';
header('Content-Type: application/json');

$tag = strtoupper(trim($_GET['tag'] ?? ''));

if (empty($tag)) {
    echo json_encode(['encontrado' => false]);
    exit();
}

$sql = "
SELECT id, descricao, marca, modelo, serie,
       unidade, setor,
       unidade_destino, setor_destino, area_destino
FROM cadastro
WHERE tag_antiga  = ?
   OR tag_trocada = ?
   OR UPPER(serie) = ?
LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $tag, $tag, $tag);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

if ($row = $res->fetch_assoc()) {
    echo json_encode([
        'encontrado'      => true,
        'id'              => $row['id'],
        'descricao'       => $row['descricao'],
        'marca'           => $row['marca'],
        'modelo'          => $row['modelo'],
        'serie'           => $row['serie'],
        'unidade'         => $row['unidade'],
        'setor'           => $row['setor'],
        'unidade_destino' => $row['unidade_destino'],
        'setor_destino'   => $row['setor_destino'],
        'area_destino'    => $row['area_destino'],
    ]);
} else {
    echo json_encode(['encontrado' => false]);
}