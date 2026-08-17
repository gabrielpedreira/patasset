<?php
ob_start();
session_start();
include 'conexao.php';
ob_clean();

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_logado'])) {
    http_response_code(403);
    echo json_encode(['erro' => 'Não autorizado']);
    exit();
}

$acao = $_GET['acao'] ?? '';

// ── Lista de unidades distintas ───────────────────────────────────────────────
if ($acao === 'unidades') {
    $res = $conn->query("SELECT DISTINCT unidade FROM cadastro WHERE unidade IS NOT NULL AND unidade <> '' ORDER BY unidade ASC");
    if ($res === false) { echo json_encode(['erro' => $conn->error]); exit(); }
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row['unidade'];
    echo json_encode($rows);
    exit();
}

// ── Contagem por setor (busca por descrição + unidade) ────────────────────────
if ($acao === 'buscar') {
    $unidade   = trim($_GET['unidade']   ?? '');
    $descricao = trim($_GET['descricao'] ?? '');

    if ($unidade === '' || $descricao === '') {
        echo json_encode(['erro' => 'Parâmetros insuficientes']);
        exit();
    }

    $stmt = $conn->prepare(
        "SELECT setor, COUNT(*) AS total
         FROM cadastro
         WHERE unidade = ?
           AND (descricao LIKE ? OR descricao_detalhada LIKE ?)
         GROUP BY setor
         ORDER BY total DESC, setor ASC"
    );
    $like = '%' . $descricao . '%';
    $stmt->bind_param('sss', $unidade, $like, $like);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    echo json_encode($rows);
    exit();
}

// ── Busca por tag (tag_antiga ou tag_trocada) ─────────────────────────────────
if ($acao === 'buscar_tag') {
    $tag = trim($_GET['tag'] ?? '');

    if ($tag === '') {
        echo json_encode(['erro' => 'Tag não informada']);
        exit();
    }

    $stmt = $conn->prepare(
        "SELECT descricao, unidade, setor, pavimento, area
         FROM cadastro
         WHERE tag_antiga = ? OR tag_trocada = ?
         ORDER BY descricao ASC, unidade ASC"
    );
    $stmt->bind_param('ss', $tag, $tag);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    echo json_encode($rows);
    exit();
}

echo json_encode(['erro' => 'Ação inválida']);