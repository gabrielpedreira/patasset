<?php
session_start();
include 'conexao.php';

header('Content-Type: application/json');

// ─── AUTENTICAÇÃO ─────────────────────────────────────────────────────────────
$usuario_logado = $_SESSION['usuario_logado'] ?? '';
if (!$usuario_logado) {
    echo json_encode(['sucesso' => false, 'erro' => 'Sessão expirada.']);
    exit();
}

// ─── RECEBE JSON ──────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !is_array($input) || count($input) === 0) {
    echo json_encode(['sucesso' => false, 'erro' => 'Nenhum item recebido.']);
    exit();
}

// ─── GERA PRÓXIMO PROTOCOLO ───────────────────────────────────────────────────
// Pega o maior protocolo existente e incrementa, independente da data
$stmtMax = $conn->query("
    SELECT MAX(CAST(protocolo AS UNSIGNED)) AS ultimo FROM (
        SELECT protocolo FROM pre_descarte
        UNION ALL
        SELECT protocolo FROM baixa_definitiva
    ) AS todas
");
$resMax    = $stmtMax->fetch_assoc();
$ultimo    = $resMax['ultimo'] ?? 0;
$protocolo = str_pad((int)$ultimo + 1, 6, '0', STR_PAD_LEFT);

// ─── DATA DO DIA ──────────────────────────────────────────────────────────────
$dt_descarte = date('Y-m-d');

// ─── INSERT DE CADA ITEM ──────────────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO pre_descarte
        (tag, descricao, marca, modelo, serie, unidade_origem, setor_origem,
         observacao, foto, dt_descarte, protocolo, nao_conformidade)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

foreach ($input as $item) {
    $tag             = strtoupper(trim($item['tag']             ?? ''));
    $descricao       = strtoupper(trim($item['descricao']       ?? ''));
    $marca           = strtoupper(trim($item['marca']           ?? ''));
    $modelo          = strtoupper(trim($item['modelo']          ?? ''));
    $serie           = strtoupper(trim($item['serie']           ?? ''));
    $unidade_origem  = strtoupper(trim($item['unidade_origem']  ?? ''));
    $setor_origem    = strtoupper(trim($item['setor_origem']    ?? ''));
    $observacao      = trim($item['observacao']                 ?? '');
    $nao_conformidade= ($item['nao_conformidade'] === 'SIM') ? 'SIM' : null;

    if (empty($descricao)) continue; // pula itens sem descrição

    // ─── FOTO (base64 → binário) ──────────────────────────────────────────────
    $foto = null;
    if (!empty($item['foto'])) {
        // Remove o prefixo "data:image/png;base64," ou similar
        $fotoBase64 = preg_replace('/^data:image\/\w+;base64,/', '', $item['foto']);
        $fotoBin    = base64_decode($fotoBase64);

        // Valida assinatura do arquivo (PNG ou JPG)
        $isPng = substr($fotoBin, 0, 4) === "\x89PNG";
        $isJpg = substr($fotoBin, 0, 2) === "\xFF\xD8";
        if ($isPng || $isJpg) {
            $foto = $fotoBin;
        }
    }

    $stmt->bind_param(
        "ssssssssssss",
        $tag, $descricao, $marca, $modelo, $serie,
        $unidade_origem, $setor_origem,
        $observacao, $foto, $dt_descarte, $protocolo, $nao_conformidade
    );

    if (!$stmt->execute()) {
        echo json_encode(['sucesso' => false, 'erro' => 'Erro ao salvar item: ' . $stmt->error]);
        exit();
    }
}

$stmt->close();
$conn->close();

echo json_encode(['sucesso' => true, 'protocolo' => $protocolo]);
?>