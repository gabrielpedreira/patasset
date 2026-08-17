<?php
session_start();
include 'conexao.php';
header('Content-Type: application/json');

$usuario_logado = $_SESSION['usuario_logado'] ?? '';
if (!$usuario_logado) {
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit();
}

$stmt = $conn->prepare("SELECT permicao FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $usuario_logado);
$stmt->execute();
$res = $stmt->get_result();
$permicao = ($row = $res->fetch_assoc()) ? $row['permicao'] : '';
$stmt->close();

if (!in_array($permicao, ['A', 'B'])) {
    echo json_encode(['sucesso' => false, 'erro' => 'Sem permissão']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$ids  = $data['ids'] ?? [];

if (empty($ids) || !is_array($ids)) {
    echo json_encode(['sucesso' => false, 'erro' => 'Nenhum ID informado']);
    exit();
}

// Sanitiza: apenas inteiros positivos
$ids = array_filter(array_map('intval', $ids), fn($v) => $v > 0);

if (empty($ids)) {
    echo json_encode(['sucesso' => false, 'erro' => 'IDs inválidos']);
    exit();
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types        = str_repeat('i', count($ids));

$stmt = $conn->prepare("DELETE FROM pre_descarte WHERE id IN ($placeholders)");
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$removidos = $stmt->affected_rows;
$stmt->close();

echo json_encode(['sucesso' => true, 'removidos' => $removidos]);