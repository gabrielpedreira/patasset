<?php
/* ═══════════════════════════════════════════════════
   operacao_massa.php
   Aplica alteração em massa (excluir/limpar OU adicionar)
   em UMA coluna da tabela `cadastro`.
   Restrito a permissão A ou classe DEV.
   A coluna vem SEMPRE de uma whitelist (nunca interpolar entrada livre).
═══════════════════════════════════════════════════ */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);

function resp($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (!isset($_SESSION['usuario_logado'])) {
    resp(['sucesso' => false, 'mensagem' => 'Sessão inválida']);
}

require_once 'conexao.php';

/* ── valida usuário / permissão ── */
$usuario = $_SESSION['usuario_logado'];
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $usuario);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$permicao = $row['permicao']       ?? '';
$classe   = $row['classe_usuario'] ?? '';
$status   = $row['status']         ?? '';

if ($status !== 'ATIVO')                             resp(['sucesso' => false, 'mensagem' => 'Usuário bloqueado']);
if (!in_array($classe, ['DEV', 'PATRIMONIO'], true)) resp(['sucesso' => false, 'mensagem' => 'Sem permissão']);
if (!($permicao === 'A' || $classe === 'DEV'))       resp(['sucesso' => false, 'mensagem' => 'Operação restrita à permissão A ou DEV']);

/* ── entrada ── */
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) resp(['sucesso' => false, 'mensagem' => 'Payload inválido']);

$tipo   = $in['tipo']   ?? '';
$coluna = $in['coluna'] ?? '';
$valor  = (string)($in['valor'] ?? '');
$modo   = $in['modo']   ?? '';
$ids    = $in['ids']    ?? [];

/* ── whitelist de colunas permitidas (mesma da planilha, menos id + logs de usuário) ── */
$colunasPermitidas = [
    'descricao','descricao_detalhada','marca','modelo','serie','propriedade',
    'tag_antiga','tag_trocada','empresa','tag_alugado','observacao','unidade','setor',
    'pavimento','area','grupo','classe','subgrupo','periodo','status',
    'movimentado_definitivo','movimentado','data_movimentacao','folha','unidade_destino',
    'setor_destino','area_destino','obs_movimentacao','data_inspecao','encontrado','estado',
    'obs3','n_conformidade','status2','o_servico','data_baixa','centro_custo_unidade',
    'centro_custo_setor','unidade_atribuida','setor_atribuido','conciliado','nota_fiscal',
    'fornecedor_nome','fornecedor_cnpj','data_aquisicao','valor_nota','valor_item',
    'data_inicio_depreciacao','depreciacao_acumulada','saldo_remanecente','contrato_arrendamento',
];
$bloqueadas = ['id','usuario_cadastro','usuario_movimentacao','usuario_inspecao','usuario_conciliacao'];

if (!in_array($coluna, $colunasPermitidas, true) || in_array($coluna, $bloqueadas, true)) {
    resp(['sucesso' => false, 'mensagem' => 'Coluna não permitida para esta operação']);
}
if (!in_array($tipo, ['excluir', 'adicionar'], true)) resp(['sucesso' => false, 'mensagem' => 'Tipo inválido']);
if (!in_array($modo, ['ids', 'todos'], true))         resp(['sucesso' => false, 'mensagem' => 'Modo inválido']);

/* coluna é validada contra whitelist → seguro interpolar entre crases */
$col      = '`' . $coluna . '`';
$afetados = 0;

if ($modo === 'ids') {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (count($ids) === 0) resp(['sucesso' => true, 'afetados' => 0]);
    $place = implode(',', array_fill(0, count($ids), '?'));

    if ($tipo === 'adicionar') {
        $sql   = "UPDATE cadastro SET $col = ? WHERE id IN ($place)";
        $stmt  = $conn->prepare($sql);
        if (!$stmt) resp(['sucesso' => false, 'mensagem' => 'Erro SQL: ' . $conn->error]);
        $types = 's' . str_repeat('i', count($ids));
        $params = array_merge([$valor], $ids);
        $stmt->bind_param($types, ...$params);
    } else { // excluir → limpa onde a coluna for igual ao valor
        $sql   = "UPDATE cadastro SET $col = '' WHERE id IN ($place) AND $col = ?";
        $stmt  = $conn->prepare($sql);
        if (!$stmt) resp(['sucesso' => false, 'mensagem' => 'Erro SQL: ' . $conn->error]);
        $types = str_repeat('i', count($ids)) . 's';
        $params = array_merge($ids, [$valor]);
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) resp(['sucesso' => false, 'mensagem' => 'Erro ao executar: ' . $stmt->error]);
    $afetados = $stmt->affected_rows;
    $stmt->close();

} else { // modo 'todos' — tabela inteira (sem filtro)
    if ($tipo === 'adicionar') {
        $sql  = "UPDATE cadastro SET $col = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) resp(['sucesso' => false, 'mensagem' => 'Erro SQL: ' . $conn->error]);
        $stmt->bind_param('s', $valor);
    } else {
        $sql  = "UPDATE cadastro SET $col = '' WHERE $col = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) resp(['sucesso' => false, 'mensagem' => 'Erro SQL: ' . $conn->error]);
        $stmt->bind_param('s', $valor);
    }
    if (!$stmt->execute()) resp(['sucesso' => false, 'mensagem' => 'Erro ao executar: ' . $stmt->error]);
    $afetados = $stmt->affected_rows;
    $stmt->close();
}

$conn->close();
resp(['sucesso' => true, 'afetados' => $afetados]);