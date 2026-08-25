<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('Content-Type: application/json');
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}
include 'conexao.php';

// Mesma porta da página nao_localizados.php: A ou B + classe do patrimônio.
seg_exigir_permissao($conn, ['A', 'B'], ['DEV', 'PATRIMONIO']);

$unidade = $_GET['unidade'] ?? '';
$setor   = $_GET['setor']   ?? '';

/* TOTAL GERAL */
$sqlGeral = "SELECT COUNT(*) as total FROM cadastro
             WHERE LOWER(REPLACE(encontrado,'ã','a')) = 'nao'";
$resGeral   = $conn->query($sqlGeral);
$totalGeral = $resGeral->fetch_assoc()['total'] ?? 0;

/* TOTAL POR UNIDADE */
$totalUnidade = 0;
if ($unidade) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM cadastro
                            WHERE unidade = ?
                            AND LOWER(REPLACE(encontrado,'ã','a')) = 'nao'");
    $stmt->bind_param("s", $unidade);
    $stmt->execute();
    $totalUnidade = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

/* TOTAL POR SETOR — Alteração 2 */
$totalSetor = 0;
if ($unidade && $setor) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM cadastro
                            WHERE unidade = ? AND setor = ?
                            AND LOWER(REPLACE(encontrado,'ã','a')) = 'nao'");
    $stmt->bind_param("ss", $unidade, $setor);
    $stmt->execute();
    $totalSetor = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

echo json_encode([
    "total_geral"    => $totalGeral,
    "total_unidade"  => $totalUnidade,
    "total_setor"    => $totalSetor
]);