<?php
session_start();
include 'conexao.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_logado'])) { echo json_encode([]); exit(); }

$usuario = $_SESSION['usuario_logado'];
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
$stmt->bind_param("s", $usuario);
$stmt->execute();
$res = $stmt->get_result();
$nivel = 'C'; $classe_usuario = ''; $status = 'ATIVO';
if ($r = $res->fetch_assoc()) {
    $nivel          = strtoupper(trim($r['permicao']));
    $classe_usuario = strtoupper(trim($r['classe_usuario']));
    $status         = $r['status'] ?? 'ATIVO';
}
$stmt->close();

if ($status !== 'ATIVO' || !in_array($classe_usuario, ['DEV','ENGENHARIA CLINICA']) || !in_array($nivel, ['A','B','C'])) {
    echo json_encode([]); exit();
}

// Colunas permitidas
$colunasPermitidas = [
    'id','descricao','descricao_detalhada','marca','modelo','serie',
    'propriedade','tag_antiga','tag_trocada','empresa','tag_alugado',
    'observacao','unidade','setor','pavimento','area','usuario_cadastro',
    'periodo','status','data_movimentacao','unidade_destino','setor_destino',
    'area_destino','usuario_movimentacao'
];
$colSet = array_flip($colunasPermitidas);

// ── Ordenação por coluna (A→Z / Z→A) ─────────────────────────────────────────
// A coluna vem do cliente, então passa pela whitelist antes de entrar no
// ORDER BY — não dá para usar prepared statement em nome de coluna.
$sortColRaw = trim($_GET['sort_col'] ?? '');
$sortDir    = strtolower(trim($_GET['sort_dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$orderBy    = ($sortColRaw !== '' && isset($colSet[$sortColRaw]))
    ? "`$sortColRaw` $sortDir, unidade ASC, descricao ASC"
    : "unidade ASC, descricao ASC";

$VAZIO = '__vazio__';
$POR_PAGINA = max(1, intval($_GET['porPagina'] ?? 100));
$pagina     = max(1, intval($_GET['pagina']    ?? 1));
$offset     = ($pagina - 1) * $POR_PAGINA;
$termo      = trim($_GET['termo'] ?? '');
$modo       = $_GET['modo'] ?? 'dados';

// Filtros por coluna
$filtrosWhere = []; $params = []; $tipos = '';

// Filtro base: itens sob responsabilidade da Engenharia Clínica.
// Antes era subgrupo='EQUIPAMENTO HOSPITALAR'; agora quem manda é a
// coluna `responsavel`, que diz de quem é a manutenção do item.
$filtrosWhere[] = "responsavel = ?";
$params[] = 'ENGENHARIA CLINICA'; $tipos .= 's';

// Filtros do usuário
$filtrosColunas = $_GET['filtros'] ?? [];
foreach ($filtrosColunas as $col => $vals) {
    if (!isset($colSet[$col]) || !is_array($vals)) continue;
    $vals = array_values($vals);
    $temVazio  = in_array($VAZIO, $vals);
    $normais   = array_filter($vals, fn($v) => $v !== $VAZIO);
    $partes = [];
    if ($temVazio)            { $partes[] = "(`$col` IS NULL OR `$col` = '')"; }
    if (count($normais) > 0) {
        $ph = implode(',', array_fill(0, count($normais), '?'));
        $partes[] = "`$col` IN ($ph)";
        foreach ($normais as $v) { $params[] = $v; $tipos .= 's'; }
    }
    if ($partes) $filtrosWhere[] = '(' . implode(' OR ', $partes) . ')';
}

// Filtros LIKE
$filtrosLike = $_GET['like'] ?? [];
foreach ($filtrosLike as $col => $t) {
    if (!isset($colSet[$col]) || $t === '') continue;
    $filtrosWhere[] = "`$col` LIKE ?";
    $params[] = '%' . $t . '%'; $tipos .= 's';
}

// Termo global
if ($termo !== '') {
    $termoCols = ['descricao','descricao_detalhada','marca','modelo','serie','unidade','setor','tag_antiga','tag_alugado'];
    $termoPartes = array_map(fn($c) => "`$c` LIKE ?", $termoCols);
    $filtrosWhere[] = '(' . implode(' OR ', $termoPartes) . ')';
    foreach ($termoCols as $_) { $params[] = "%$termo%"; $tipos .= 's'; }
}

$where = $filtrosWhere ? 'WHERE ' . implode(' AND ', $filtrosWhere) : '';

// ── MODO: opções de filtro por coluna ────────────────────────────────────────
if ($modo === 'opcoes') {
    $col = $_GET['coluna'] ?? '';
    if (!isset($colSet[$col])) { echo json_encode([]); exit(); }
    $sql = "SELECT DISTINCT `$col` FROM cadastro $where ORDER BY `$col` ASC";
    $st = $conn->prepare($sql);
    if ($tipos && $st) { $st->bind_param($tipos, ...$params); }
    if ($st) { $st->execute(); $r2 = $st->get_result(); }
    $opcoes = [];
    while ($row = $r2->fetch_row()) $opcoes[] = $row[0] ?? '';
    echo json_encode($opcoes);
    exit();
}

// ── MODO: contar duplicados ───────────────────────────────────────────────────
if ($modo === 'contar_duplicados') {
    $sql = "SELECT tag_antiga, COUNT(*) as cnt FROM cadastro WHERE responsavel='ENGENHARIA CLINICA' AND tag_antiga IS NOT NULL AND tag_antiga <> '' GROUP BY tag_antiga HAVING cnt > 1";
    $r2 = $conn->query($sql);
    $total_dup = 0; $excesso = 0;
    while ($row = $r2->fetch_assoc()) { $total_dup += $row['cnt']; $excesso += ($row['cnt'] - 1); }
    echo json_encode(['total_dup' => $total_dup, 'excesso' => $excesso]);
    exit();
}

// ── MODO: duplicados ─────────────────────────────────────────────────────────
$dupWhere = '';
if (($_GET['duplicados'] ?? '') === '1') {
    $dupWhere = " AND tag_antiga IN (SELECT tag_antiga FROM cadastro WHERE responsavel='ENGENHARIA CLINICA' AND tag_antiga IS NOT NULL AND tag_antiga <> '' GROUP BY tag_antiga HAVING COUNT(*)>1)";
}

// ── MODO: exportar (sem paginação) ───────────────────────────────────────────
if ($modo === 'exportar') {
    $cols = implode(',', array_map(fn($c) => "`$c`", array_diff($colunasPermitidas, ['id'])));
    $sql  = "SELECT $cols FROM cadastro $where$dupWhere ORDER BY $orderBy";
    $st   = $conn->prepare($sql);
    if ($tipos && $st) $st->bind_param($tipos, ...$params);
    $st->execute();
    $r2 = $st->get_result();
    $rows = [];
    while ($row = $r2->fetch_assoc()) $rows[] = $row;
    echo json_encode($rows);
    exit();
}

// ── MODO: dados (padrão) ──────────────────────────────────────────────────────
$cols = implode(',', array_map(fn($c) => "`$c`", $colunasPermitidas));

// Total
$stCount = $conn->prepare("SELECT COUNT(*) FROM cadastro $where$dupWhere");
if ($tipos && $stCount) $stCount->bind_param($tipos, ...$params);
$stCount->execute(); $stCount->bind_result($total); $stCount->fetch(); $stCount->close();

// Dados
$paramsPage = $params; $tiposPage = $tipos;
$paramsPage[] = $POR_PAGINA; $tiposPage .= 'i';
$paramsPage[] = $offset;     $tiposPage .= 'i';

$sql = "SELECT $cols FROM cadastro $where$dupWhere ORDER BY $orderBy LIMIT ? OFFSET ?";
$st  = $conn->prepare($sql);
if ($tiposPage && $st) $st->bind_param($tiposPage, ...$paramsPage);
$st->execute(); $r2 = $st->get_result();
$linhas = [];
while ($row = $r2->fetch_assoc()) $linhas[] = $row;

echo json_encode(['linhas' => $linhas, 'total' => (int)$total]);
$conn->close();
?>