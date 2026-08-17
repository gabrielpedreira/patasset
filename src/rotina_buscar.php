<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_logado'])) {
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}

require_once 'conexao.php';

$unidade   = strtoupper(trim($_POST['unidade']   ?? ''));
$setor     = strtoupper(trim($_POST['setor']     ?? ''));
$pavimento = strtoupper(trim($_POST['pavimento'] ?? ''));
$area      = strtoupper(trim($_POST['area']      ?? ''));
$tag_serie = trim($_POST['tag_serie'] ?? '');

if ($unidade === '' && $tag_serie === '') {
    echo json_encode(['erro' => 'Parâmetro unidade é obrigatório']);
    exit;
}

/*
 * Lógica de localização efetiva:
 *
 * BUSCA POR ORIGEM: item aparece se bate unidade/setor/pavimento/area (origem)
 *   - EXCETO se movimentado=SIM E (unidade_destino OU setor_destino OU area_destino differ) → item saiu
 *
 * BUSCA POR DESTINO: item aparece se bate unidade_destino/setor_destino/area_destino
 *   - Não existe pavimento_destino, então pavimento é ignorado nessa parte
 *
 * Os dois conjuntos se unem (OR). IDs duplicados são improvavéis pois
 * um item não pode estar em origem E destino diferentes ao mesmo tempo.
 */

// Item ainda está na origem: não foi movimentado, OU foi movimentado mas voltou ao mesmo local
$C_STILL_ORIG = "(
    UPPER(TRIM(COALESCE(movimentado,''))) != 'SIM'
    OR (
        UPPER(TRIM(unidade)) = UPPER(TRIM(unidade_destino)) AND
        UPPER(TRIM(setor))   = UPPER(TRIM(setor_destino))   AND
        UPPER(TRIM(area))    = UPPER(TRIM(area_destino))
    )
)";

// Item está no destino: foi movimentado para outro local
$C_AT_DEST = "(
    UPPER(TRIM(COALESCE(movimentado,''))) = 'SIM' AND NOT (
        UPPER(TRIM(unidade)) = UPPER(TRIM(unidade_destino)) AND
        UPPER(TRIM(setor))   = UPPER(TRIM(setor_destino))   AND
        UPPER(TRIM(area))    = UPPER(TRIM(area_destino))
    )
)";

$selectCols = "id, descricao, descricao_detalhada, marca, modelo, serie, tag_antiga, tag_trocada,
    encontrado, estado, obs3, n_conformidade, status2, o_servico, movimentado, movimentado_definitivo,
    unidade, setor, pavimento, area, unidade_destino, setor_destino, area_destino";

// Localização efetiva para exibição na tabela
$selectEff = "
    CASE WHEN $C_STILL_ORIG THEN unidade   ELSE unidade_destino END AS unidade_eff,
    CASE WHEN $C_STILL_ORIG THEN setor     ELSE setor_destino   END AS setor_eff,
    CASE WHEN $C_STILL_ORIG THEN pavimento ELSE ''              END AS pavimento_eff,
    CASE WHEN $C_STILL_ORIG THEN area      ELSE area_destino    END AS area_eff";

if ($tag_serie !== '') {
    $ts = $conn->real_escape_string($tag_serie);
    $sql = "SELECT $selectCols, $selectEff
            FROM cadastro
            WHERE tag_antiga LIKE '%$ts%'
               OR tag_trocada LIKE '%$ts%'
               OR serie LIKE '%$ts%'";
} else {
    $u = $conn->real_escape_string($unidade);
    $s = $conn->real_escape_string($setor);
    $p = $conn->real_escape_string($pavimento);
    $a = $conn->real_escape_string($area);

    // Condições para busca por ORIGEM (item ainda está lá)
    $origConds = "UPPER(TRIM(unidade)) = '$u'";
    if ($s !== '') $origConds .= " AND UPPER(TRIM(setor)) = '$s'";
    if ($p !== '') $origConds .= " AND UPPER(TRIM(pavimento)) = '$p'";
    if ($a !== '') $origConds .= " AND UPPER(TRIM(area)) = '$a'";

    // Condições para busca por DESTINO (item foi movido para cá)
    // Não existe pavimento_destino → ignora filtro de pavimento nessa parte
    $destConds = "UPPER(TRIM(unidade_destino)) = '$u'";
    if ($s !== '') $destConds .= " AND UPPER(TRIM(setor_destino)) = '$s'";
    if ($a !== '') $destConds .= " AND UPPER(TRIM(area_destino)) = '$a'";

    $sql = "SELECT $selectCols, $selectEff
            FROM cadastro
            WHERE ($C_STILL_ORIG AND $origConds)
               OR ($C_AT_DEST    AND $destConds)";
}

$result = $conn->query($sql);
if (!$result) {
    echo json_encode(['erro' => $conn->error]);
    exit;
}

$rows = [];
while ($r = $result->fetch_assoc()) {
    $rows[] = $r;
}

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
