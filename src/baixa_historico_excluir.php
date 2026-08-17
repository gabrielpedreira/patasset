<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

include 'conexao.php';

$usuario_logado = $_SESSION['usuario_logado'] ?? '';
if (!$usuario_logado) {
    echo json_encode(['erro' => 'Sessão expirada.']); exit;
}

// Apenas DEV
$stmt = $conn->prepare("SELECT classe_usuario FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $usuario_logado);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (($row['classe_usuario'] ?? '') !== 'DEV') {
    echo json_encode(['erro' => 'Acesso restrito.']); exit;
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['erro' => 'ID inválido.']); exit;
}

// Busca o registro de baixa e o item correspondente em cadastro
$stmtSel = $conn->prepare("
    SELECT bd.tag, c.id AS id_cadastro, c.responsavel
    FROM baixa_definitiva bd
    LEFT JOIN cadastro c ON (c.tag_antiga = bd.tag OR c.tag_trocada = bd.tag)
    WHERE bd.id = ?
    LIMIT 1
");
$stmtSel->bind_param("i", $id);
$stmtSel->execute();
$reg = $stmtSel->get_result()->fetch_assoc();
$stmtSel->close();

if (!$reg) {
    echo json_encode(['erro' => 'Registro não encontrado.']); exit;
}

$tag         = $reg['tag']         ?? '';
$id_cadastro = intval($reg['id_cadastro'] ?? 0);
// O que define se o item é do LifeTech passou a ser a responsabilidade
// pela manutenção, não mais o subgrupo.
$responsavel = strtoupper(trim($reg['responsavel'] ?? ''));
$is_lifetech = ($responsavel === 'ENGENHARIA CLINICA');

$conn->begin_transaction();
try {
    // 1. Se for equipamento LifeTech, desfaz retirada de peças pelo cadastro.id
    if ($is_lifetech && $id_cadastro > 0) {
        $s1a = $conn->prepare("DELETE FROM retiradadepecas_status WHERE id_baixa = ?");
        $s1a->bind_param("i", $id_cadastro);
        $s1a->execute();
        $s1a->close();

        $s1b = $conn->prepare("DELETE FROM retiradadepecas_equipamento_tipo WHERE id_baixa = ?");
        $s1b->bind_param("i", $id_cadastro);
        $s1b->execute();
        $s1b->close();
    }

    // 2. Remove o registro principal de baixa
    $s2 = $conn->prepare("DELETE FROM baixa_definitiva WHERE id = ?");
    $s2->bind_param("i", $id);
    $s2->execute();
    $s2->close();

    // 3. Reverte o status em cadastro
    if (!empty($tag)) {
        $s3 = $conn->prepare("
            UPDATE cadastro
            SET status = NULL, data_baixa = NULL
            WHERE (tag_antiga = ? OR tag_trocada = ?)
              AND status = 'BAIXADO'
        ");
        $s3->bind_param("ss", $tag, $tag);
        $s3->execute();
        $s3->close();
    }

    $conn->commit();

    $msg = $is_lifetech
        ? 'Baixa excluída e retirada de peças desfeita com sucesso.'
        : 'Baixa excluída com sucesso.';
    echo json_encode(['sucesso' => true, 'msg' => $msg]);

} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['erro' => $e->getMessage()]);
}
