<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['usuario_logado'])) { echo json_encode(['erro'=>'nao autenticado']); exit(); }
require_once "conexao.php";

// Dados por trás do dashboard de indicadores: mesma regra da página (nível A +
// classe do patrimônio). Antes, qualquer logado obtinha os números por aqui.
seg_exigir_permissao($conn, ['A'], ['DEV', 'PATRIMONIO']);

require_once "indicadores_localizacao.php";

/* ═══════════════════════════════════════════════════════════════════════════
   INDICADORES DE LOCALIZAÇÃO × NOTA FISCAL
   Atendido antes do restante porque é uma tabela cruzada, não um GROUP BY:
   cada barra é uma combinação de duas condições, não um valor de coluna.
   ═══════════════════════════════════════════════════════════════════════════ */
if (($_POST['acao'] ?? '') === 'localizacao') {
    $uni_f = strtoupper(trim($_POST['unidade'] ?? ''));
    $where = $uni_f !== ''
        ? "UPPER(TRIM(unidade))='" . $conn->real_escape_string($uni_f) . "'"
        : '';

    $k = il_calcular($conn, $where);
    $rot = il_rotulos();

    // Nos gráficos entram só as quatro combinações; os dois totais ficariam
    // somados junto com as partes e distorceriam a leitura.
    $chaves = ['loc_com_nf', 'loc_sem_nf', 'nloc_com_nf', 'nloc_sem_nf'];

    echo json_encode([
        'kpis'     => $k,
        'labels'   => array_map(fn($c) => $rot[$c], $chaves),
        'datasets' => [
            ['label' => 'Quantidade', 'data' => array_map(fn($c) => $k[$c]['qtd'],   $chaves)],
            ['label' => 'Valor R$',   'data' => array_map(fn($c) => round($k[$c]['valor'], 2), $chaves)],
        ],
    ]);
    $conn->close();
    exit();
}

$fonte    = trim($_POST['fonte']    ?? 'conciliados');
$metricas = $_POST['metricas']  ?? ['quantidade'];   // array
$dimensoes= $_POST['dimensoes'] ?? ['unidade'];       // array
$unidade  = trim($_POST['unidade']  ?? '');
$limite   = max(5, min(50, (int)($_POST['limite'] ?? 20)));

if (!is_array($metricas))  $metricas  = [$metricas];
if (!is_array($dimensoes)) $dimensoes = [$dimensoes];

function exprValor(): string {
    return "COALESCE(SUM(CAST(REPLACE(REPLACE(NULLIF(TRIM(valor_item),''),'.',''),',','.') AS DECIMAL(20,2))),0)";
}

function colMap(string $d): string {
    return match($d) {
        'ccu'        => 'TRIM(centro_custo_unidade)',
        'destino'    => 'TRIM(unidade_destino)',
        'conciliado' => 'UPPER(TRIM(conciliado))',
        'subgrupo'   => 'TRIM(subgrupo)',
        'descricao'  => 'TRIM(descricao)',
        'mes'        => "DATE_FORMAT(data_movimentacao,'%Y-%m')",
        'status'     => 'UPPER(TRIM(status))',
        // Localização: reduz a coluna `encontrado` a dois baldes com rótulo
        // pronto. Vazio/NULL cai em "Não Localizado" — item sem marcação de
        // auditoria conta como não localizado, que é o comportamento seguro.
        'localizado' => "CASE WHEN UPPER(TRIM(encontrado))='SIM' THEN 'Localizado' ELSE 'Não Localizado' END",
        default      => 'TRIM(unidade)',
    };
}

function dimLabel(string $d): string {
    return match($d) {
        'ccu'        => 'Centro de Custo',
        'destino'    => 'Unidade Destino',
        'conciliado' => 'Conciliação',
        'subgrupo'   => 'Subgrupo',
        'descricao'  => 'Tipo',
        'mes'        => 'Mês',
        'status'     => 'Status',
        'localizado' => 'Localização',
        default      => 'Unidade',
    };
}

function metricaLabel(string $m): string {
    return match($m) {
        'valor'       => 'Valor R$',
        'porcentagem' => 'Porcentagem',
        default       => 'Quantidade',
    };
}

function buildWhere(string $fonte, $conn, string $unidade): string {
    $parts = [];
    if ($fonte === 'movimentacoes') {
        $parts[] = "(UPPER(TRIM(movimentado))='SIM' OR UPPER(TRIM(movimentado_definitivo))='SIM')";
    }
    if ($unidade !== '') {
        $u = $conn->real_escape_string(strtoupper(trim($unidade)));
        $parts[] = "UPPER(TRIM(unidade))='$u'";
    }
    return $parts ? implode(' AND ', $parts) : '1=1';
}

function runQuery($conn, string $col, string $metrica, string $where, int $limite): array {
    $expr  = $metrica === 'valor' ? exprValor() : 'COUNT(*)';
    $order = str_contains($col, 'DATE_FORMAT') ? 'dim ASC' : 'val DESC';
    $sql   = "SELECT $col AS dim, $expr AS val FROM cadastro WHERE $where GROUP BY $col ORDER BY $order LIMIT $limite";
    $res   = $conn->query($sql);
    $map   = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $key = ($r['dim'] !== null && $r['dim'] !== '') ? $r['dim'] : 'NÃO DEF.';
            $map[$key] = round((float)$r['val'], 2);
        }
    }
    return $map;
}

/* ── Executa todas as combinações dim × metrica ── */
function buildDatasets($conn, string $fonte, array $dimensoes, array $metricas, string $where, int $limite): array {
    // Para cada (dim, metrica), busca um map label→valor
    $rawMaps = []; // [dsLabel => map[label=>val]]
    $allLabels = [];

    foreach ($dimensoes as $dim) {
        $col = colMap($dim);
        foreach ($metricas as $met) {
            $map = runQuery($conn, $col, $met, $where, $limite);
            $dsLabel = count($dimensoes) > 1 || count($metricas) > 1
                ? dimLabel($dim) . ' — ' . metricaLabel($met)
                : dimLabel($dim);
            $rawMaps[$dsLabel] = $map;
            $allLabels = array_merge($allLabels, array_keys($map));
        }
    }

    // Porcentagem: recalcular sobre total global desse dataset
    foreach ($metricas as $met) {
        if ($met !== 'porcentagem') continue;
        foreach ($rawMaps as $dsLabel => &$map) {
            if (!str_contains($dsLabel, 'Porcentagem') && count($metricas) > 1) continue;
            $total = array_sum($map);
            if ($total > 0) {
                foreach ($map as $k => &$v) $v = round($v / $total * 100, 1);
            }
        }
        unset($map);
    }

    $allLabels = array_values(array_unique($allLabels));

    $datasets = [];
    foreach ($rawMaps as $dsLabel => $map) {
        $data = array_map(fn($l) => $map[$l] ?? 0, $allLabels);
        $datasets[] = ['label' => $dsLabel, 'data' => $data];
    }

    return ['labels' => $allLabels, 'datasets' => $datasets];
}

/* ── Ambos: cadastro geral + movimentações ── */
if ($fonte === 'ambos') {
    $w1  = buildWhere('conciliados', $conn, $unidade);
    $w2  = buildWhere('movimentacoes', $conn, $unidade);
    $r1  = buildDatasets($conn, 'conciliados',   $dimensoes, $metricas, $w1, $limite);
    $r2  = buildDatasets($conn, 'movimentacoes', $dimensoes, $metricas, $w2, $limite);

    // Une labels de ambas as fontes
    $allL = array_values(array_unique(array_merge($r1['labels'], $r2['labels'])));
    $merged = [];
    foreach ($r1['datasets'] as $ds) {
        $map  = array_combine($r1['labels'], $ds['data']);
        $merged[] = ['label' => $ds['label'] . ' (Cadastro)', 'data' => array_map(fn($l) => $map[$l] ?? 0, $allL)];
    }
    foreach ($r2['datasets'] as $ds) {
        $map  = array_combine($r2['labels'], $ds['data']);
        $merged[] = ['label' => $ds['label'] . ' (Movimentações)', 'data' => array_map(fn($l) => $map[$l] ?? 0, $allL)];
    }
    echo json_encode(['labels' => $allL, 'datasets' => $merged]);
} else {
    $w   = buildWhere($fonte, $conn, $unidade);
    $res = buildDatasets($conn, $fonte, $dimensoes, $metricas, $w, $limite);
    echo json_encode($res);
}

$conn->close();
