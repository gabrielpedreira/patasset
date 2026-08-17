<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_logado'])){
    // http_response_code(403); exit(json_encode(['erro'=>'nao autenticado']));
}

require_once "conexao.php";

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
    '3d-diagnose'        => '3D DIAGNOSE',
];

$slug = trim($_POST['slug'] ?? 'geral');
if(!array_key_exists($slug, $mapa)){
    echo json_encode(['erro'=>'slug invalido: '.$slug]); exit();
}

$unidadeValor = $mapa[$slug];

function whereUni($conn, $val){
    if($val === null) return '1=1';
    $esc = $conn->real_escape_string(strtoupper(trim($val)));
    return "UPPER(TRIM(unidade)) = '$esc'";
}
$wh = whereUni($conn, $unidadeValor);

function qv($conn, $sql){
    $r = $conn->query($sql);
    if(!$r) return 0;
    $row = $r->fetch_assoc();
    return $row ? array_values($row)[0] : 0;
}

/**
 * Converte valor_item no formato BR (1.000,00) para DECIMAL somável.
 * REPLACE remove pontos de milhar, substitui vírgula decimal por ponto.
 * NULLIF descarta células vazias para não contaminar a soma.
 */
function exprSomaValorItem(): string {
    return "SUM(
        CAST(
            REPLACE(
                REPLACE(
                    NULLIF(TRIM(valor_item), ''),
                '.',  ''   ),   /* remove ponto de milhar  */
            ',', '.'            /* vírgula decimal → ponto */
        ) AS DECIMAL(20,2)
    ))";
}

// TOTAL
$total = (int)qv($conn, "SELECT COUNT(*) as t FROM cadastro WHERE $wh");

// VALOR TOTAL APROXIMADO — soma valor_item (formato BR) de todos os itens
$soma_expr   = exprSomaValorItem();
$valor_total = (float)qv($conn, "SELECT $soma_expr as t FROM cadastro WHERE $wh");

// PATRIMÔNIO PRÓPRIO — soma valor_item apenas de itens com propriedade = PATRIMONIO
$valor_proprio = (float)qv($conn, "SELECT $soma_expr as t FROM cadastro WHERE $wh AND UPPER(TRIM(propriedade))='PATRIMONIO'");

// VALOR DE CONCILIADOS — soma valor_item onde conciliado = SIM
// Usa centro_custo_unidade como base do filtro de unidade (em vez de unidade)
function whereCCU($conn, $val){
    if($val === null) return '1=1';
    $esc = $conn->real_escape_string(strtoupper(trim($val)));
    return "UPPER(TRIM(centro_custo_unidade)) = '$esc'";
}
$whCCU = whereCCU($conn, $unidadeValor);
$valor_conciliados = (float)qv($conn, "SELECT $soma_expr as t FROM cadastro WHERE $whCCU AND LOWER(TRIM(conciliado))='sim'");

// SETORES
$resSet=$conn->query("SELECT setor, COUNT(*) as t FROM cadastro WHERE $wh GROUP BY setor ORDER BY t DESC");
$setores=[]; $setoresQtd=[];
if($resSet) while($r=$resSet->fetch_assoc()){ $setores[]=$r['setor']?:'NÃO DEFINIDO'; $setoresQtd[]=(int)$r['t']; }

// UNIDADES (só geral)
$unidades=[]; $unidadesQtd=[];
if($unidadeValor === null){
    $resUni=$conn->query("SELECT TRIM(unidade) as unidade, COUNT(*) as t FROM cadastro GROUP BY TRIM(unidade) ORDER BY t DESC");
    if($resUni) while($r=$resUni->fetch_assoc()){ $unidades[]=$r['unidade']?:'NÃO DEF.'; $unidadesQtd[]=(int)$r['t']; }
}

// EM OUTRA UNIDADE
if($unidadeValor !== null){
    $esc = $conn->real_escape_string(strtoupper(trim($unidadeValor)));
    $em_outra = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE UPPER(TRIM(unidade))='$esc' AND UPPER(TRIM(movimentado))='SIM' AND unidade_destino IS NOT NULL AND unidade_destino<>'' AND UPPER(TRIM(unidade_destino))<>'$esc'");
} else {
    $em_outra = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE UPPER(TRIM(movimentado))='SIM' AND unidade_destino IS NOT NULL AND unidade_destino<>'' AND UPPER(TRIM(unidade_destino))<>UPPER(TRIM(unidade))");
}

// STATUS
$resS=$conn->query("SELECT status, COUNT(*) as t FROM cadastro WHERE $wh GROUP BY status ORDER BY t DESC");
$sLabels=[]; $sQtd=[];
if($resS) while($r=$resS->fetch_assoc()){ $sLabels[]=$r['status']?:'NÃO DEF.'; $sQtd[]=(int)$r['t']; }

$valorStatus=[];
foreach(['ATIVO','INATIVO','BAIXADO'] as $st){
    $valorStatus[$st]=(float)qv($conn,"SELECT SUM(valor_item) as t FROM cadastro WHERE $wh AND UPPER(TRIM(status))='$st'");
}

// CONTAGENS INDIVIDUAIS DE STATUS (para KPI row 2)
$qtd_ativos   = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE $wh AND UPPER(TRIM(status))='ATIVO'");
$qtd_inativos = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE $wh AND UPPER(TRIM(status))='INATIVO'");
$qtd_baixados = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE $wh AND UPPER(TRIM(status))='BAIXADO'");

// CONTAGENS POR PROPRIEDADE (para KPI row 2)
$qtd_comodato  = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE $wh AND UPPER(TRIM(propriedade))='COMODATO'");
$qtd_alugado   = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE $wh AND UPPER(TRIM(propriedade))='ALUGADO'");
$qtd_emprestado= (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE $wh AND UPPER(TRIM(propriedade))='EMPRESTADO'");

// PROPRIEDADE
$resP=$conn->query("SELECT propriedade, COUNT(*) as t FROM cadastro WHERE $wh GROUP BY propriedade ORDER BY t DESC");
$pLabels=[]; $pQtd=[];
if($resP) while($r=$resP->fetch_assoc()){ $pLabels[]=$r['propriedade']?:'NÃO DEF.'; $pQtd[]=(int)$r['t']; }
$qtd_proprio   = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE $wh AND UPPER(TRIM(propriedade))='PATRIMONIO'");
$qtd_terceiros = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE $wh AND UPPER(TRIM(propriedade)) IN('ALUGADO','COMODATO','EMPRESTADO')");

// CONCILIAÇÃO — usa centro_custo_unidade como base do filtro (igual ao valor_conciliados)
$total_ccu       = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE $whCCU");
$conciliados     = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE $whCCU AND UPPER(TRIM(conciliado))='SIM'");
$nao_conciliados = $total_ccu - $conciliados;

// MOVIMENTAÇÃO
$movimentados   = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE $wh AND UPPER(TRIM(movimentado))='SIM'");
$mov_definitivo = (int)qv($conn,"SELECT COUNT(*) as t FROM cadastro WHERE $wh AND UPPER(TRIM(movimentado_definitivo))='SIM'");

// CRESCIMENTO ANUAL
$resAnos=$conn->query("SELECT YEAR(periodo) AS ano, COUNT(*) AS t FROM cadastro WHERE $wh AND periodo IS NOT NULL GROUP BY YEAR(periodo) ORDER BY ano ASC");
$anos=[]; $anosQtd=[];
if($resAnos) while($r=$resAnos->fetch_assoc()){ $anos[]=(int)$r['ano']; $anosQtd[]=(int)$r['t']; }

// USUARIOS
$resU=$conn->query("SELECT usuario_cadastro, COUNT(*) as t FROM cadastro WHERE $wh GROUP BY usuario_cadastro ORDER BY t DESC");
$uLabels=[]; $uQtd=[];
if($resU) while($r=$resU->fetch_assoc()){ $uLabels[]=$r['usuario_cadastro']?:'NÃO DEF.'; $uQtd[]=(int)$r['t']; }

// TIPOS
$resD=$conn->query("SELECT descricao, COUNT(*) as t FROM cadastro WHERE $wh AND descricao IS NOT NULL AND descricao<>'' GROUP BY descricao ORDER BY t DESC");
$dLabels=[]; $dQtd=[];
if($resD) while($r=$resD->fetch_assoc()){ $dLabels[]=$r['descricao']; $dQtd[]=(int)$r['t']; }

// CLASSIFICAÇÃO
$resSub=$conn->query("SELECT subgrupo, COUNT(*) as t FROM cadastro WHERE $wh AND subgrupo IS NOT NULL AND subgrupo<>'' GROUP BY subgrupo ORDER BY t DESC");
$subLabels=[]; $subQtd=[];
if($resSub) while($r=$resSub->fetch_assoc()){ $subLabels[]=$r['subgrupo']; $subQtd[]=(int)$r['t']; }

// CENTRO DE CUSTO POR UNIDADE
$soma_expr2 = exprSomaValorItem();
$resCCU = $conn->query("
    SELECT TRIM(centro_custo_unidade) AS ccu, COUNT(*) AS t, $soma_expr2 AS v
    FROM cadastro
    WHERE $wh
    GROUP BY TRIM(centro_custo_unidade)
    ORDER BY t DESC
");
$ccuLabels=[]; $ccuQtd=[]; $ccuValor=[];
if($resCCU) while($r=$resCCU->fetch_assoc()){
    $ccuLabels[] = $r['ccu'] ?: 'NÃO DEF.';
    $ccuQtd[]    = (int)$r['t'];
    $ccuValor[]  = round((float)$r['v'], 2);
}

$conn->close();

echo json_encode([
    'total'              => $total,
    'valor_total'        => round($valor_total, 2),
    'valor_proprio'      => round($valor_proprio, 2),
    'valor_conciliados'  => round($valor_conciliados, 2),   // ← NOVO
    'setores'            => $setores,
    'setores_qtd'        => $setoresQtd,
    'unidades'           => $unidades,
    'unidades_qtd'       => $unidadesQtd,
    'em_outra'           => $em_outra,
    'status_labels'      => $sLabels,
    'status_qtd'         => $sQtd,
    'valor_status'       => $valorStatus,
    'qtd_ativos'         => $qtd_ativos,
    'qtd_inativos'       => $qtd_inativos,
    'qtd_baixados'       => $qtd_baixados,
    'qtd_comodato'       => $qtd_comodato,
    'qtd_alugado'        => $qtd_alugado,
    'qtd_emprestado'     => $qtd_emprestado,
    'prop_labels'        => $pLabels,
    'prop_qtd'           => $pQtd,
    'qtd_proprio'        => $qtd_proprio,
    'qtd_terceiros'      => $qtd_terceiros,
    'conciliados'        => $conciliados,
    'nao_conciliados'    => $nao_conciliados,
    'movimentados'       => $movimentados,
    'mov_definitivo'     => $mov_definitivo,
    'anos'               => $anos,
    'anos_qtd'           => $anosQtd,
    'usu_labels'         => $uLabels,
    'usu_qtd'            => $uQtd,
    'desc_labels'        => $dLabels,
    'desc_qtd'           => $dQtd,
    'sub_labels'         => $subLabels,
    'sub_qtd'            => $subQtd,
    'ccu_labels'         => $ccuLabels,
    'ccu_qtd'            => $ccuQtd,
    'ccu_valor'          => $ccuValor,
]);