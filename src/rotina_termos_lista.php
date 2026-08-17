<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_logado'])) {
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}

require_once 'conexao.php';

$unidade = trim($_POST['unidade'] ?? '');
$setor   = trim($_POST['setor']   ?? '');
$area    = trim($_POST['area']    ?? '');

if ($unidade === '') {
    echo json_encode(['erro' => 'Parâmetro unidade é obrigatório']);
    exit;
}

$conditions = ['unidade = ?'];
$params     = [$unidade];
$types      = 's';

if ($setor !== '') {
    $conditions[] = 'setor = ?';
    $params[]     = $setor;
    $types       .= 's';
}
if ($area !== '') {
    $conditions[] = 'area = ?';
    $params[]     = $area;
    $types       .= 's';
}

$where = implode(' AND ', $conditions);

$stmt = $conn->prepare(
    "SELECT id, unidade, setor, area, data_geracao, coordenador, usuario, arquivo, criado_em
     FROM termos_responsabilidade
     WHERE $where
     ORDER BY data_geracao DESC, id DESC
     LIMIT 20"
);

if (!$stmt) {
    echo json_encode(['erro' => $conn->error]);
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($r = $result->fetch_assoc()) {
    $rows[] = $r;
}
$stmt->close();

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
