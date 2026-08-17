<?php
/**
 * contabil_dados.php
 * Backend AJAX para contabil.php — lê e salva dados da tabela `relacao`
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Captura erros fatais e retorna JSON em vez de HTML
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['erro' => 'Erro interno: ' . $e['message'], 'linhas' => [], 'total' => 0]);
    }
});

session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('Content-Type: application/json');
    echo json_encode(['erro' => 'Sessão expirada.', 'linhas' => [], 'total' => 0]);
    exit();
}

require_once __DIR__ . '/conexao.php';

header('Content-Type: application/json; charset=utf-8');

/* ── autenticação ── */
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $_SESSION['usuario_logado']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['ok'=>false,'erro'=>'Usuário não encontrado.','linhas'=>[],'total'=>0]);
    exit();
}

$isDev      = ($row['classe_usuario'] === 'DEV');
$statusOk   = ($row['status'] === 'ATIVO') || $isDev; // DEV bypassa status
$permicaoOk = in_array($row['permicao'],       ['A','B'],              true);
$classeOk   = in_array($row['classe_usuario'], ['DEV','PATRIMONIO'],   true);

if (!$statusOk || !$permicaoOk || !$classeOk) {
    echo json_encode(['ok'=>false,'erro'=>'Acesso negado.','linhas'=>[],'total'=>0]);
    exit();
}

/* ════════════════════════════════
   POST — salvar linhas editadas
════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_linhas') {
    $linhas = json_decode($_POST['linhas'] ?? '[]', true);
    if (!is_array($linhas) || empty($linhas)) {
        echo json_encode(['ok' => false, 'msg' => 'Nenhuma linha recebida.']);
        exit();
    }

    $erros  = 0;
    $salvos = 0;

    foreach ($linhas as $linha) {
        $id       = (int)($linha['id']       ?? 0);
        $descricao = strtoupper(trim($linha['descricao'] ?? ''));
        $grupo     = strtoupper(trim($linha['grupo']     ?? ''));
        $classe    = strtoupper(trim($linha['classe']    ?? ''));
        $subgrupo  = strtoupper(trim($linha['subgrupo']  ?? ''));

        if ($id <= 0) { $erros++; continue; }

        $stmt = $conn->prepare(
            "UPDATE relacao SET descricao=?, grupo=?, classe=?, subgrupo=? WHERE id=?"
        );
        if (!$stmt) { $erros++; continue; }
        $stmt->bind_param("ssssi", $descricao, $grupo, $classe, $subgrupo, $id);
        if ($stmt->execute()) $salvos++;
        else $erros++;
        $stmt->close();
    }

    echo json_encode([
        'ok'     => $erros === 0,
        'msg'    => $erros === 0
                    ? "Alterações salvas! ({$salvos} registro(s) atualizado(s))"
                    : "Salvo com {$erros} erro(s). {$salvos} registro(s) atualizado(s).",
        'salvos' => $salvos,
        'erros'  => $erros,
    ]);
    exit();
}

/* ════════════════════════════════
   GET — listar registros
════════════════════════════════ */
$pagina    = max(1, intval($_GET['pagina']    ?? 1));
$porPagina = max(1, intval($_GET['porPagina'] ?? 100));
$termo     = trim($_GET['termo'] ?? '');
$offset    = ($pagina - 1) * $porPagina;

$conditions = [];
$params     = [];
$types      = '';

if ($termo !== '') {
    $conditions[] = "(descricao LIKE ? OR grupo LIKE ? OR classe LIKE ? OR subgrupo LIKE ?)";
    for ($i = 0; $i < 4; $i++) {
        $params[] = '%' . $termo . '%';
        $types   .= 's';
    }
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

/* COUNT */
$sqlCount = "SELECT COUNT(*) FROM relacao $where";
$stmtC    = $conn->prepare($sqlCount);
if ($params) $stmtC->bind_param($types, ...$params);
$stmtC->execute();
$stmtC->bind_result($total);
$stmtC->fetch();
$stmtC->close();

/* DADOS — ordem alfabética por descrição */
$sqlDados = "SELECT id, descricao, grupo, classe, subgrupo
             FROM relacao
             $where
             ORDER BY descricao ASC
             LIMIT ? OFFSET ?";

$paramsD = array_merge($params, [$porPagina, $offset]);
$typesD  = $types . 'ii';
$stmtD   = $conn->prepare($sqlDados);
if ($paramsD) $stmtD->bind_param($typesD, ...$paramsD);
$stmtD->execute();
$res    = $stmtD->get_result();
$linhas = [];
while ($row = $res->fetch_assoc()) $linhas[] = $row;
$stmtD->close();

$conn->close();

echo json_encode([
    'total'  => (int)$total,
    'pagina' => $pagina,
    'linhas' => $linhas,
]);