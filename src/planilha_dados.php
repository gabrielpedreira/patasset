<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    echo json_encode(['erro' => 'Não logado']);
    exit();
}

require_once "conexao.php";

// Dados da planilha: visível a A, B e C (mesma regra de planilha.php), mas só
// para a classe dona do patrimônio. Sem isto, outra classe lia o inventário
// por este endpoint. Ver seg_exigir_permissao().
seg_exigir_permissao($conn, ['A', 'B', 'C'], ['DEV', 'PATRIMONIO']);

header('Content-Type: application/json; charset=utf-8');

$pagina    = max(1, intval($_GET['pagina']    ?? 1));
$porPagina = max(1, intval($_GET['porPagina'] ?? 100));
$termo     = trim($_GET['termo'] ?? '');
$offset    = ($pagina - 1) * $porPagina;

// Ordenação por coluna (A→Z / Z→A)
$sortColRaw = trim($_GET['sort_col'] ?? '');
$sortDirRaw = strtolower(trim($_GET['sort_dir'] ?? 'asc'));
$sortDir    = $sortDirRaw === 'desc' ? 'DESC' : 'ASC';

$colunasPermitidas = [ // whitelist — só estas podem ser usadas em ORDER BY
    'id','descricao','descricao_detalhada','marca','modelo','serie','propriedade',
    'tag_antiga','tag_trocada','empresa','tag_alugado','observacao',
    'unidade','setor','pavimento','area','usuario_cadastro',
    'grupo','classe','subgrupo','responsavel','periodo','status',
    'movimentado_definitivo','movimentado','data_movimentacao','folha',
    'unidade_destino','setor_destino','area_destino','obs_movimentacao','usuario_movimentacao',
    'data_inspecao','usuario_inspecao','encontrado','estado','obs3','n_conformidade',
    'status2','o_servico','data_baixa','centro_custo_unidade','centro_custo_setor',
    'unidade_atribuida','setor_atribuido','conciliado','usuario_conciliacao','nota_fiscal',
    'fornecedor_nome','fornecedor_cnpj','data_aquisicao','valor_nota','valor_item',
    'data_inicio_depreciacao','depreciacao_acumulada','saldo_remanecente','contrato_arrendamento'
];

// Monta ORDER BY com segurança (whitelist)
$orderBy = (in_array($sortColRaw, $colunasPermitidas) && $sortColRaw !== '')
    ? "`$sortColRaw` $sortDir, id DESC"
    : "id DESC";

/* ═══════════════════════════════════════════════════════════
   VALORES GENÉRICOS — não devem ser considerados duplicados
   Comparados após LOWER(TRIM()) do valor do banco
═══════════════════════════════════════════════════════════ */
function getValoresGenericos(): array {
    return [
        // sem número / sem série
        's/n','sn','s.n','s.n.','s/n.','sem numero','sem número',
        'sem serie','sem série','sem numero de serie','sem número de série',
        'sem numero serie','sem número série','sem numeracao','sem numeração',
        // sem placa / sem tag
        'sem placa','sem tag','sem patrimonio','sem patrimônio',
        'sem identificacao','sem identificação','sem identificador',
        // não aplicável
        'n/a','na','n.a','n.a.','nd','n.d','n.d.','ni','n.i','n.i.',
        'nao informado','não informado','nao informada','não informada',
        'nao possui','não possui','nao tem','não tem',
        'desconhecido','desconhecida','indefinido','indefinida',
        // nenhum / sem
        'nenhum','nenhuma','sem','sem nada',
        // zeros
        '0','00','000','0000','00000','000000',
        // traços / hífens sequenciais
        '-','--','---','----','-----','------',
        // x repetido
        'x','xx','xxx','xxxx','xxxxx','xxxxxx',
        // nao / sem abreviado
        'nao','não',
    ];
}

/**
 * Monta cláusula SQL para excluir valores genéricos de uma coluna.
 * Usa LOWER(TRIM(col)) NOT IN (lista escapada).
 * Retorna string SQL pronta para ser inserida em AND.
 */
function clausulaExcluirGenericos(string $col, $conn): string {
    $genericos = getValoresGenericos();
    $escapados = array_map(fn($v) => "'" . $conn->real_escape_string($v) . "'", $genericos);
    $lista     = implode(',', $escapados);
    return "LOWER(TRIM(`$col`)) NOT IN ($lista)";
}

/* ── filtros exatos (checkbox) ── */
$filtrosColunas = [];
$rawFiltros = $_GET['filtros'] ?? [];
if (is_array($rawFiltros)) {
    foreach ($rawFiltros as $col => $val) {
        if (!in_array($col, $colunasPermitidas, true)) continue;
        $valores = is_array($val)
            ? array_values(array_map(fn($v) => $v === '__vazio__' ? '' : $v, $val))
            : ($val !== '' ? [($val === '__vazio__' ? '' : $val)] : []);
        if (count($valores) > 0) $filtrosColunas[$col] = $valores;
    }
}

/* ── colunas de data (usam DATE() na comparação) ── */
$colunasData = [
    'data_movimentacao','data_inspecao','data_baixa',
    'data_aquisicao','data_inicio_depreciacao'
];

/* ── excluir vazios por coluna ── */
$excluirVazio = [];
$rawExcluirVazio = $_GET['excluir_vazio'] ?? [];
if (is_array($rawExcluirVazio)) {
    foreach ($rawExcluirVazio as $col => $val) {
        if (in_array($col, $colunasPermitidas, true) && $val === '1') {
            $excluirVazio[] = $col;
        }
    }
}

/* ── filtrar somente vazios por coluna ── */
$filtrarVazio = [];
$rawFiltrarVazio = $_GET['filtrar_vazio'] ?? [];
if (is_array($rawFiltrarVazio)) {
    foreach ($rawFiltrarVazio as $col => $val) {
        if (in_array($col, $colunasPermitidas, true) && $val === '1') {
            $filtrarVazio[] = $col;
        }
    }
}

/* ── filtros LIKE por coluna ── */
$filtrosLike = [];
$rawLike = $_GET['like'] ?? [];
if (is_array($rawLike)) {
    foreach ($rawLike as $col => $val) {
        if (!in_array($col, $colunasPermitidas, true)) continue;
        $val = trim($val);
        if ($val !== '') $filtrosLike[$col] = $val;
    }
}

/* ═══════════════════════════════════════════════════════════
   montarWhere
═══════════════════════════════════════════════════════════ */
function montarWhere(
    string $termo,
    array  $filtrosColunas,
    array  $filtrosLike,
    string $excluirCol,
    $conn,
    array  $excluirVazio = [],
    array  $filtrarVazio = [],
    array  $colunasData  = []
): array {
    $conditions = []; $params = []; $types = '';

    // Excluir células vazias/nulas por coluna
    foreach ($excluirVazio as $col) {
        if ($col === $excluirCol) continue;
        // Colunas DATE não suportam comparação com '' em modo estrito — usar só IS NOT NULL
        if (in_array($col, $colunasData, true)) {
            $conditions[] = "`$col` IS NOT NULL";
        } else {
            $conditions[] = "(`$col` IS NOT NULL AND `$col` <> '')";
        }
    }

    // Filtrar SOMENTE células vazias/nulas
    foreach ($filtrarVazio as $col) {
        if ($col === $excluirCol) continue;
        if (in_array($col, $colunasData, true)) {
            $conditions[] = "`$col` IS NULL";
        } else {
            $conditions[] = "(`$col` IS NULL OR `$col` = '')";
        }
    }

    foreach ($filtrosColunas as $col => $valores) {
        if ($col === $excluirCol || empty($valores)) continue;
        $eData   = in_array($col, $colunasData, true);
        $expr    = $eData ? "DATE(`$col`)" : "`$col`";
        $vazios  = in_array('', $valores, true);
        $normais = array_values(array_filter($valores, fn($v) => $v !== ''));
        $partes  = [];
        if ($vazios) $partes[] = "(`$col` IS NULL OR `$col` = '')";
        if (count($normais) === 1) {
            $partes[] = "$expr = ?"; $params[] = $normais[0]; $types .= 's';
        } elseif (count($normais) > 1) {
            $ph = implode(',', array_fill(0, count($normais), '?'));
            $partes[] = "$expr IN ($ph)";
            foreach ($normais as $v) { $params[] = $v; $types .= 's'; }
        }
        if (count($partes) === 1)   $conditions[] = $partes[0];
        elseif (count($partes) > 1) $conditions[] = '(' . implode(' OR ', $partes) . ')';
    }

    foreach ($filtrosLike as $col => $val) {
        if ($col === $excluirCol || $val === '') continue;
        $conditions[] = "`$col` LIKE ?";
        $params[] = '%' . $val . '%'; $types .= 's';
    }

    if ($termo !== '') {
        $cols = [
            'id','descricao','descricao_detalhada','marca','modelo','serie','propriedade',
            'tag_antiga','tag_trocada','empresa','tag_alugado','observacao',
            'unidade','setor','pavimento','area','usuario_cadastro',
            'grupo','classe','subgrupo','responsavel','periodo','status',
            'movimentado_definitivo','movimentado','data_movimentacao','folha',
            'unidade_destino','setor_destino','area_destino','obs_movimentacao','usuario_movimentacao',
            'data_inspecao','usuario_inspecao','encontrado','estado','obs3','n_conformidade',
            'status2','o_servico','data_baixa','centro_custo_unidade','centro_custo_setor',
            'unidade_atribuida','setor_atribuido','conciliado','usuario_conciliacao','nota_fiscal',
            'fornecedor_nome','fornecedor_cnpj','data_aquisicao','valor_nota','valor_item',
            'data_inicio_depreciacao','depreciacao_acumulada','saldo_remanecente','contrato_arrendamento'
        ];
        $likes = [];
        foreach ($cols as $c) {
            $likes[] = "`$c` LIKE ?";
            $params[] = '%' . $termo . '%'; $types .= 's';
        }
        $conditions[] = '(' . implode(' OR ', $likes) . ')';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params, $types];
}

/* ═══════════════════════════════════════════════════════════
   buscarValoresDuplicados
   - Comparação ESTRITAMENTE IGUAL: LOWER(TRIM(col)) GROUP BY HAVING COUNT > 1
   - Exclui NULL, vazio e valores genéricos (s/n, sem placa, etc.)
   - Retorna array ['col' => ['val1','val2',...]]
═══════════════════════════════════════════════════════════ */
function buscarValoresDuplicados($conn): array {
    $colsDup = ['serie', 'tag_antiga', 'tag_trocada'];
    $valsDup = ['serie' => [], 'tag_antiga' => [], 'tag_trocada' => []];

    foreach ($colsDup as $col) {
        $excGenericos = clausulaExcluirGenericos($col, $conn);

        /*
         * Condições:
         * 1. Não NULL e não vazio                     → garante que célula vazia não entra
         * 2. NOT IN (lista de valores genéricos)      → exclui s/n, sem placa, etc.
         * 3. GROUP BY LOWER(TRIM(col)) HAVING COUNT>1 → só valores que aparecem 2+ vezes
         *    A comparação é exata: "3122" ≠ "3123"
         */
        $sql = "
            SELECT LOWER(TRIM(`$col`)) AS val
            FROM cadastro
            WHERE `$col` IS NOT NULL
              AND TRIM(`$col`) <> ''
              AND $excGenericos
            GROUP BY LOWER(TRIM(`$col`))
            HAVING COUNT(*) > 1
        ";

        $res = $conn->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $valsDup[$col][] = $conn->real_escape_string($r['val']);
            }
            $res->free();
        }
    }

    return $valsDup;
}

/* ── contar duplicados ── */
if (($_GET['modo'] ?? '') === 'contar_duplicados') {
    $colunasDup = ['serie', 'tag_antiga', 'tag_trocada'];
    $idsExcesso = []; $idsEnvolvidos = []; $excesso = 0;

    foreach ($colunasDup as $col) {
        $excGenericos = clausulaExcluirGenericos($col, $conn);

        $sql = "
            SELECT c.id, LOWER(TRIM(c.`$col`)) AS val
            FROM cadastro c
            INNER JOIN (
                SELECT LOWER(TRIM(`$col`)) AS val
                FROM cadastro
                WHERE `$col` IS NOT NULL
                  AND TRIM(`$col`) <> ''
                  AND $excGenericos
                GROUP BY LOWER(TRIM(`$col`))
                HAVING COUNT(*) > 1
            ) AS dup ON LOWER(TRIM(c.`$col`)) = dup.val
            WHERE c.`$col` IS NOT NULL AND TRIM(c.`$col`) <> ''
            ORDER BY val ASC, c.id ASC
        ";
        $res = $conn->query($sql);
        if (!$res) continue;

        $grupos = [];
        while ($r = $res->fetch_assoc()) {
            $grupos[$r['val']][]     = (int)$r['id'];
            $idsEnvolvidos[$r['id']] = true;
        }
        $res->free();

        foreach ($grupos as $ids) {
            foreach ($ids as $idx => $id) {
                if ($idx === 0) continue;
                if (!isset($idsExcesso[$id])) { $idsExcesso[$id] = true; $excesso++; }
            }
        }
    }

    echo json_encode(['excesso' => $excesso, 'total_dup' => count($idsEnvolvidos)]);
    $conn->close(); exit();
}

/* ── modo opcoes ── */
if (($_GET['modo'] ?? '') === 'opcoes') {
    $col = $_GET['coluna'] ?? '';
    if (!in_array($col, $colunasPermitidas, true)) { echo json_encode([]); exit(); }

    [$where, $params, $types] = montarWhere($termo, $filtrosColunas, $filtrosLike, $col, $conn, $excluirVazio, $filtrarVazio, $colunasData);
    $where = aplicarFiltroCor($where, trim($_GET['filtro_cor'] ?? ''));

    if (($_GET['duplicados'] ?? '') === '1') {
        $valsDup   = buscarValoresDuplicados($conn);
        $partesDup = [];
        foreach ($valsDup as $c => $vals) {
            if (empty($vals)) continue;
            $lista = "'" . implode("','", $vals) . "'";
            $partesDup[] = "LOWER(TRIM(`$c`)) IN ($lista)";
        }
        if (!empty($partesDup)) {
            $dupCond = '(' . implode(' OR ', $partesDup) . ')';
            $where   = $where ? $where . " AND $dupCond" : "WHERE $dupCond";
        }
    }

    // Para colunas de data: retornar apenas a parte DATE (sem hora)
    $eData  = in_array($col, $colunasData, true);
    $expr   = $eData ? "DATE(`$col`)" : "`$col`";
    $sql    = "SELECT DISTINCT $expr AS val FROM cadastro $where ORDER BY val";
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $opcoes = []; $temVazio = false;
    while ($r = $res->fetch_row()) {
        if ($r[0] === null || $r[0] === '') $temVazio = true;
        else $opcoes[] = $r[0];
    }
    if ($temVazio) $opcoes[] = '';
    $stmt->close();
    echo json_encode($opcoes);
    exit();
}

/* ── filtro por cor (vermelho/amarelo/laranja) ── */
function aplicarFiltroCor(string $where, string $filtroCor): string {
    $cond = match($filtroCor) {
        'vermelho' => "status = 'BAIXADO'",
        'amarelo'  => "(movimentado = 'SIM' OR movimentado_definitivo = 'SIM')",
        'laranja'  => "UPPER(TRIM(encontrado)) = 'NAO'",
        default    => ''
    };
    if (!$cond) return $where;
    return $where ? $where . " AND $cond" : "WHERE $cond";
}

/* ═══════════════════════════════════════════════════════════
   BUSCA PRINCIPAL
═══════════════════════════════════════════════════════════ */
[$where, $params, $types] = montarWhere($termo, $filtrosColunas, $filtrosLike, '', $conn, $excluirVazio, $filtrarVazio, $colunasData);

$filtroCor = trim($_GET['filtro_cor'] ?? '');
$where = aplicarFiltroCor($where, $filtroCor);


$filtroDuplicados = ($_GET['duplicados'] ?? '') === '1';

if ($filtroDuplicados) {
    $valsDup = buscarValoresDuplicados($conn);

    $partesDup         = [];
    $lista_serie       = "''";
    $lista_tag_antiga  = "''";
    $lista_tag_trocada = "''";

    foreach ($valsDup as $col => $vals) {
        if (empty($vals)) continue;
        $lista = "'" . implode("','", $vals) . "'";
        $partesDup[] = "LOWER(TRIM(`$col`)) IN ($lista)";
        if ($col === 'serie')       $lista_serie       = $lista;
        if ($col === 'tag_antiga')  $lista_tag_antiga  = $lista;
        if ($col === 'tag_trocada') $lista_tag_trocada = $lista;
    }

    if (empty($partesDup)) {
        echo json_encode(['total' => 0, 'pagina' => $pagina, 'linhas' => []]);
        $conn->close(); exit();
    }

    $dupCond  = '(' . implode(' OR ', $partesDup) . ')';
    $whereAll = $where ? $where . " AND $dupCond" : "WHERE $dupCond";

    $orderDup = "ORDER BY
        CASE
            WHEN LOWER(TRIM(serie))       IN ($lista_serie)       THEN 1
            WHEN LOWER(TRIM(tag_antiga))  IN ($lista_tag_antiga)  THEN 2
            WHEN LOWER(TRIM(tag_trocada)) IN ($lista_tag_trocada) THEN 3
            ELSE 4
        END,
        COALESCE(
            NULLIF(LOWER(TRIM(serie)),''),
            NULLIF(LOWER(TRIM(tag_antiga)),''),
            NULLIF(LOWER(TRIM(tag_trocada)),'')
        ),
        id ASC";

    $sqlCount = "SELECT COUNT(*) FROM cadastro $whereAll";
    $stmtC    = $conn->prepare($sqlCount);
    if (!$stmtC) { echo json_encode(['erro' => $conn->error]); exit(); }
    if ($params) $stmtC->bind_param($types, ...$params);
    $stmtC->execute();
    $stmtC->bind_result($total);
    $stmtC->fetch();
    $stmtC->close();

    $sqlDados = "
        SELECT
            id, descricao, descricao_detalhada, marca, modelo, serie, propriedade, tag_antiga, tag_trocada,
            empresa, tag_alugado, observacao, unidade, setor, pavimento, area, usuario_cadastro,
            grupo, classe, subgrupo, responsavel, periodo, status, movimentado_definitivo, movimentado,
            data_movimentacao, folha, unidade_destino, setor_destino, area_destino,
            obs_movimentacao, usuario_movimentacao, data_inspecao, usuario_inspecao,
            encontrado, estado, obs3, n_conformidade, status2, o_servico,
            data_baixa, centro_custo_unidade, centro_custo_setor,
            unidade_atribuida, setor_atribuido, conciliado, usuario_conciliacao, nota_fiscal,
            fornecedor_nome, fornecedor_cnpj, data_aquisicao, valor_nota, valor_item,
            data_inicio_depreciacao, depreciacao_acumulada, saldo_remanecente, contrato_arrendamento
        FROM cadastro
        $whereAll
        $orderDup
        LIMIT ? OFFSET ?
    ";
    $paramsD = array_merge($params, [$porPagina, $offset]);
    $typesD  = $types . 'ii';
    $stmtD   = $conn->prepare($sqlDados);
    if (!$stmtD) { echo json_encode(['erro' => $conn->error]); exit(); }
    if ($paramsD) $stmtD->bind_param($typesD, ...$paramsD);
    $stmtD->execute();
    $res    = $stmtD->get_result();
    $linhas = [];
    while ($row = $res->fetch_assoc()) $linhas[] = $row;
    $stmtD->close();

} else {

    $sqlCount = "SELECT COUNT(*) FROM cadastro $where";
    $stmtC    = $conn->prepare($sqlCount);
    if ($params) $stmtC->bind_param($types, ...$params);
    $stmtC->execute();
    $stmtC->bind_result($total);
    $stmtC->fetch();
    $stmtC->close();

    $sqlDados = "
        SELECT
            id, descricao, descricao_detalhada, marca, modelo, serie, propriedade, tag_antiga, tag_trocada,
            empresa, tag_alugado, observacao, unidade, setor, pavimento, area, usuario_cadastro,
            grupo, classe, subgrupo, responsavel, periodo, status, movimentado_definitivo, movimentado,
            data_movimentacao, folha, unidade_destino, setor_destino, area_destino,
            obs_movimentacao, usuario_movimentacao, data_inspecao, usuario_inspecao,
            encontrado, estado, obs3, n_conformidade, status2, o_servico,
            data_baixa, centro_custo_unidade, centro_custo_setor,
            unidade_atribuida, setor_atribuido, conciliado, usuario_conciliacao, nota_fiscal,
            fornecedor_nome, fornecedor_cnpj, data_aquisicao, valor_nota, valor_item,
            data_inicio_depreciacao, depreciacao_acumulada, saldo_remanecente, contrato_arrendamento
        FROM cadastro
        $where
        ORDER BY $orderBy
        LIMIT ? OFFSET ?
    ";
    $paramsD = array_merge($params, [$porPagina, $offset]);
    $typesD  = $types . 'ii';
    $stmtD   = $conn->prepare($sqlDados);
    if ($paramsD) $stmtD->bind_param($typesD, ...$paramsD);
    $stmtD->execute();
    $res    = $stmtD->get_result();
    $linhas = [];
    while ($row = $res->fetch_assoc()) $linhas[] = $row;
    $stmtD->close();
}

$conn->close();

echo json_encode([
    'total'  => (int)$total,
    'pagina' => $pagina,
    'linhas' => $linhas,
]);