<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'conexao.php';

// Devolve unidade, setor e pavimento do inventário. Dado interno: exige login.
// conexao.php sozinho não autentica — ver seg_exigir_login() em
// seguranca_sessao.php.
seg_exigir_login();

$nivel     = $_GET['nivel']     ?? '';
$unidade   = strtoupper(trim($_GET['unidade']   ?? ''));
$setor     = strtoupper(trim($_GET['setor']     ?? ''));
$pavimento = strtoupper(trim($_GET['pavimento'] ?? ''));

// Item ainda está na origem (não movimentado, ou voltou ao mesmo local)
$C_ORIG = "(
    UPPER(TRIM(COALESCE(movimentado,''))) != 'SIM'
    OR (
        UPPER(TRIM(unidade)) = UPPER(TRIM(unidade_destino)) AND
        UPPER(TRIM(setor))   = UPPER(TRIM(setor_destino))   AND
        UPPER(TRIM(area))    = UPPER(TRIM(area_destino))
    )
)";

// Item está no destino (movimentado para outro local)
$C_DEST = "(
    UPPER(TRIM(COALESCE(movimentado,''))) = 'SIM' AND NOT (
        UPPER(TRIM(unidade)) = UPPER(TRIM(unidade_destino)) AND
        UPPER(TRIM(setor))   = UPPER(TRIM(setor_destino))   AND
        UPPER(TRIM(area))    = UPPER(TRIM(area_destino))
    )
)";

$results = [];

switch ($nivel) {

    case 'unidade':
        $q1 = $conn->query("SELECT DISTINCT UPPER(TRIM(unidade)) AS val FROM cadastro WHERE $C_ORIG AND unidade IS NOT NULL AND TRIM(unidade) != ''");
        $q2 = $conn->query("SELECT DISTINCT UPPER(TRIM(unidade_destino)) AS val FROM cadastro WHERE $C_DEST AND unidade_destino IS NOT NULL AND TRIM(unidade_destino) != ''");
        $vals = [];
        if ($q1) while ($r = $q1->fetch_assoc()) $vals[] = $r['val'];
        if ($q2) while ($r = $q2->fetch_assoc()) $vals[] = $r['val'];
        $vals = array_unique($vals);
        sort($vals);
        foreach ($vals as $v) $results[] = ['unidade' => $v];
        break;

    case 'setor':
        $u = $conn->real_escape_string($unidade);
        $q1 = $conn->query("SELECT DISTINCT UPPER(TRIM(setor)) AS val FROM cadastro WHERE $C_ORIG AND UPPER(TRIM(unidade)) = '$u' AND setor IS NOT NULL AND TRIM(setor) != ''");
        $q2 = $conn->query("SELECT DISTINCT UPPER(TRIM(setor_destino)) AS val FROM cadastro WHERE $C_DEST AND UPPER(TRIM(unidade_destino)) = '$u' AND setor_destino IS NOT NULL AND TRIM(setor_destino) != ''");
        $vals = [];
        if ($q1) while ($r = $q1->fetch_assoc()) $vals[] = $r['val'];
        if ($q2) while ($r = $q2->fetch_assoc()) $vals[] = $r['val'];
        $vals = array_unique($vals);
        sort($vals);
        foreach ($vals as $v) $results[] = ['setor' => $v];
        break;

    case 'pavimento':
        // Não existe pavimento_destino → busca apenas pelos itens de origem no setor
        // (inclui itens que ainda estão lá + itens movidos mas que voltaram ao mesmo local)
        $u = $conn->real_escape_string($unidade);
        $s = $conn->real_escape_string($setor);
        $q1 = $conn->query("SELECT DISTINCT UPPER(TRIM(pavimento)) AS val FROM cadastro WHERE UPPER(TRIM(unidade)) = '$u' AND UPPER(TRIM(setor)) = '$s' AND pavimento IS NOT NULL AND TRIM(pavimento) != ''");
        $vals = [];
        if ($q1) while ($r = $q1->fetch_assoc()) $vals[] = $r['val'];
        $vals = array_unique($vals);
        sort($vals);
        foreach ($vals as $v) $results[] = ['pavimento' => $v];
        break;

    case 'area':
        // coluna `area` (origem) e `area_destino` (destino efetivo)
        $u = $conn->real_escape_string($unidade);
        $s = $conn->real_escape_string($setor);
        $p = $conn->real_escape_string($pavimento);
        $pavCond = ($p !== '') ? "AND UPPER(TRIM(pavimento)) = '$p'" : '';
        // itens cuja localização efetiva é este setor (origem sem mover, ou destino se movido)
        $q1 = $conn->query("SELECT DISTINCT UPPER(TRIM(area)) AS val FROM cadastro WHERE $C_ORIG AND UPPER(TRIM(unidade)) = '$u' AND UPPER(TRIM(setor)) = '$s' $pavCond AND area IS NOT NULL AND TRIM(area) != ''");
        $q2 = $conn->query("SELECT DISTINCT UPPER(TRIM(area_destino)) AS val FROM cadastro WHERE $C_DEST AND UPPER(TRIM(unidade_destino)) = '$u' AND UPPER(TRIM(setor_destino)) = '$s' AND area_destino IS NOT NULL AND TRIM(area_destino) != ''");
        $vals = [];
        if ($q1) while ($r = $q1->fetch_assoc()) $vals[] = $r['val'];
        if ($q2) while ($r = $q2->fetch_assoc()) $vals[] = $r['val'];
        $vals = array_unique($vals);
        sort($vals);
        foreach ($vals as $v) $results[] = ['area' => $v];
        break;

    default:
        echo json_encode([]);
        exit;
}

echo json_encode($results, JSON_UNESCAPED_UNICODE);
