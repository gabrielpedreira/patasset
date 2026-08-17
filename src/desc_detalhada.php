<?php
session_start();
if(!isset($_SESSION['usuario_logado'])){ http_response_code(403); exit(); }
require_once "conexao.php";
header('Content-Type: application/json');

$descricao = trim($_POST['descricao'] ?? '');
$slug      = trim($_POST['slug'] ?? 'geral');

if($descricao === ''){
    echo json_encode([]);
    exit();
}

/*
 * Mapa idêntico ao filtro_dashboard.php (valores em UPPER CASE)
 */
$mapa = [
    'geral'              => null,
    'casa-de-portugal'   => 'CASA DE PORTUGAL',
    'premium'            => 'PREMIUM',
    'evangelico'         => 'EVANGELICO',
    'sao-bernardo'       => 'SÃO BERNARDO',
    'oftalmo-casa'       => 'OFTALMO CASA',
    'menssana'           => 'MENSSANA',
    'santa-cruz'         => 'SANTA CRUZ',
    'ilha-do-governador' => 'ILHA DO GOVERNADOR',
    'rio-laranjeiras'    => 'RIO LARANJEIRAS',
    'rio-botafogo'       => 'RIO BOTAFOGO',
    'prontocor'          => 'PRONTOCOR',
    'egas-moniz'         => 'EGAS MONIZ',
];

if(!array_key_exists($slug, $mapa)){
    echo json_encode([]);
    exit();
}

$unidadeValor = $mapa[$slug]; // null = geral

$escDesc = $conn->real_escape_string($descricao);

if($unidadeValor === null){
    // Geral — todas as unidades
    $sql = "
        SELECT descricao_detalhada, COUNT(*) AS qtd
        FROM cadastro
        WHERE descricao = '$escDesc'
          AND descricao_detalhada IS NOT NULL
          AND descricao_detalhada <> ''
        GROUP BY descricao_detalhada
        ORDER BY qtd DESC
    ";
} else {
    $escUni = $conn->real_escape_string(strtoupper(trim($unidadeValor)));
    $sql = "
        SELECT descricao_detalhada, COUNT(*) AS qtd
        FROM cadastro
        WHERE descricao = '$escDesc'
          AND UPPER(TRIM(unidade)) = '$escUni'
          AND descricao_detalhada IS NOT NULL
          AND descricao_detalhada <> ''
        GROUP BY descricao_detalhada
        ORDER BY qtd DESC
    ";
}

$res = $conn->query($sql);

$rows = [];
if($res){
    while($row = $res->fetch_assoc()){
        $rows[] = [
            'descricao_detalhada' => $row['descricao_detalhada'],
            'qtd'                 => (int)$row['qtd'],
        ];
    }
}

$conn->close();
echo json_encode($rows);