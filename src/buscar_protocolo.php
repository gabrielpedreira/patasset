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
// Normaliza o protocolo: remove zeros à esquerda e reaplica formatação com 6 dígitos
// Assim "1", "01" e "000001" são tratados como o mesmo protocolo
$protocolo = str_pad(ltrim(trim($_GET['protocolo'] ?? ''), '0') ?: '0', 6, '0', STR_PAD_LEFT);

if (empty($protocolo)) {
    echo json_encode(['encontrado' => false]);
    exit();
}

// ─── BUSCA NA TABELA PRE_DESCARTE ─────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        id,
        tag,
        descricao,
        marca,
        modelo,
        serie,
        unidade_origem,
        setor_origem,
        observacao,
        foto,
        dt_descarte,
        protocolo,
        nao_conformidade
    FROM pre_descarte
    WHERE protocolo = ?
    ORDER BY id ASC
");
$stmt->bind_param("s", $protocolo);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

if ($res->num_rows === 0) {
    echo json_encode(['encontrado' => false]);
    exit();
}

$itens = [];
while ($row = $res->fetch_assoc()) {
    $itens[] = [
        'id'               => $row['id'],
        'tag'              => $row['tag'],
        'descricao'        => $row['descricao'],
        'marca'            => $row['marca'],
        'modelo'           => $row['modelo'],
        'serie'            => $row['serie'],
        'unidade_origem'   => $row['unidade_origem'],
        'setor_origem'     => $row['setor_origem'],
        'obs'              => $row['observacao'],
        'dt_descarte'      => $row['dt_descarte'],
        'protocolo'        => $row['protocolo'],
        'nao_conformidade' => $row['nao_conformidade'],
        // Foto convertida para base64 para exibição no front
        'foto_base64'      => $row['foto'] ? base64_encode($row['foto']) : null,
    ];
}

echo json_encode(['encontrado' => true, 'itens' => $itens]);

$conn->close();
?>