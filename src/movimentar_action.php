<?php
ob_start();

session_start();
require 'conexao.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = $_SESSION['usuario_logado'] ?? '';
if (!$usuario) {
    ob_clean();
    echo json_encode(['sucesso' => false, 'erro' => 'Usuário não logado']);
    exit();
}

// Movimentar item exige A ou B + classe dona do patrimônio (mesma regra de
// movimentar.php). A tela desabilita o botão para C; o endpoint não conferia
// nada além do login. Ver seg_exigir_permissao().
ob_clean();
seg_exigir_permissao($conn, ['A', 'B'], ['DEV', 'PATRIMONIO']);

/* ===============================
   DADOS RECEBIDOS
================================ */

$ids_raw           = $_POST['itens'] ?? [];
$unidade_destino   = trim($_POST['unidade_destino']   ?? '');
$setor_destino     = trim($_POST['setor_destino']     ?? '');
$pavimento_destino = trim($_POST['pavimento_destino'] ?? '');
$area_destino      = trim($_POST['area_destino']      ?? '');
$obs               = trim($_POST['obs_movimentacao']  ?? '');
$definitiva        = ($_POST['definitiva'] ?? '0') === '1';

/* ===============================
   VALIDAÇÃO
================================ */

if (empty($ids_raw)) {
    ob_clean();
    echo json_encode(['sucesso' => false, 'erro' => 'Nenhum item informado.']);
    exit();
}

if ($unidade_destino === '' || $setor_destino === '') {
    ob_clean();
    echo json_encode(['sucesso' => false, 'erro' => 'Preencha Unidade e Setor de destino.']);
    exit();
}

$ids = array_map('intval', $ids_raw);
$ids = array_filter($ids, fn($v) => $v > 0);
$ids = array_values($ids);

if (empty($ids)) {
    ob_clean();
    echo json_encode(['sucesso' => false, 'erro' => 'IDs inválidos.']);
    exit();
}

/* ===============================
   DATA / TIPO
================================ */

$data   = date('Y-m-d H:i:s');
$tipo   = $definitiva ? 'MOVIMENTAÇÃO DEFINITIVA' : 'MOVIMENTAÇÃO TEMPORÁRIA';
$movDef = $definitiva ? 'SIM' : null;

/* ===============================
   LOOP POR ITEM
================================ */

$ids_processados = [];

foreach ($ids as $id_item) {

    // Busca dados atuais do item
    $stmtBusca = $conn->prepare("SELECT * FROM cadastro WHERE id = ? LIMIT 1");
    $stmtBusca->bind_param("i", $id_item);
    $stmtBusca->execute();
    $dados = $stmtBusca->get_result()->fetch_assoc();
    $stmtBusca->close();

    if (!$dados) continue;

    $tag = $dados['tag_trocada'] ?: $dados['tag_antiga'];

    // Inserir histórico
    $sqlHist = "
    INSERT INTO historico (
        data, descricao, marca, modelo, serie, tag,
        unidade, setor,
        unidade_dest, setor_dest, pav_dest, local_dest,
        obs_mov, tipo_mov, usuario_mov
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ";

    $stmtHist = $conn->prepare($sqlHist);
    if (!$stmtHist) continue;

    // Origem = localização atual do item (unidade_destino/setor_destino)
    // com fallback para unidade/setor caso o item nunca tenha sido movimentado
    $origem_unidade = !empty($dados['unidade_destino']) ? $dados['unidade_destino'] : $dados['unidade'];
    $origem_setor   = !empty($dados['setor_destino'])   ? $dados['setor_destino']   : $dados['setor'];

    $stmtHist->bind_param(
        "sssssssssssssss",
        $data,
        $dados['descricao'],
        $dados['marca'],
        $dados['modelo'],
        $dados['serie'],
        $tag,
        $origem_unidade,
        $origem_setor,
        $unidade_destino,
        $setor_destino,
        $pavimento_destino,
        $area_destino,
        $obs,
        $tipo,
        $usuario
    );
    $stmtHist->execute();
    $stmtHist->close();

    // Update cadastro — origem (unidade/setor/pavimento/area) NÃO é alterada
    // Apenas os campos de destino e controle são atualizados
    $sqlUpdate = "
    UPDATE cadastro SET
        unidade_destino        = ?,
        setor_destino          = ?,
        area_destino           = ?,
        data_movimentacao      = ?,
        obs_movimentacao       = ?,
        movimentado            = 'SIM',
        movimentado_definitivo = ?,
        usuario_movimentacao   = ?
    WHERE id = ?
    LIMIT 1
    ";

    $stmtUp = $conn->prepare($sqlUpdate);
    if (!$stmtUp) continue;

    $stmtUp->bind_param(
        "sssssssi",
        $unidade_destino,
        $setor_destino,
        $area_destino,
        $data,
        $obs,
        $movDef,
        $usuario,
        $id_item
    );
    $stmtUp->execute();
    $stmtUp->close();

    $ids_processados[] = $id_item;
}

$conn->close();

ob_clean();
echo json_encode(['sucesso' => true, 'ids' => $ids_processados]);
exit();