<?php
session_start();
include 'conexao.php';

header('Content-Type: application/json');

// ─── AUTENTICAÇÃO ─────────────────────────────────────────────────────────────
$usuario_logado = $_SESSION['usuario_logado'] ?? '';
if (!$usuario_logado) {
    echo json_encode(['encontrado' => false]);
    exit();
}

// ─── RECEBE PARÂMETRO ─────────────────────────────────────────────────────────
$serie = strtoupper(trim($_GET['serie'] ?? ''));

if (empty($serie)) {
    echo json_encode(['encontrado' => false]);
    exit();
}

// ─── BUSCA NA TABELA CADASTRO ─────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT descricao, marca, modelo, serie, unidade, setor
    FROM cadastro
    WHERE serie = ?
    LIMIT 1
");
$stmt->bind_param("s", $serie);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

if ($row = $res->fetch_assoc()) {
    echo json_encode([
        'encontrado' => true,
        'descricao'  => $row['descricao'],
        'marca'      => $row['marca'],
        'modelo'     => $row['modelo'],
        'serie'      => $row['serie'],
        'unidade'    => $row['unidade'],
        'setor'      => $row['setor'],
    ]);
} else {
    echo json_encode(['encontrado' => false]);
}

$conn->close();
?>