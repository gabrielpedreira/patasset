<?php
// Erros não são exibidos ao usuário em produção: a mensagem do PHP revela
// caminho do servidor, nome de tabela e trecho de SQL. Continuam sendo
// registrados e ficam visíveis no painel DEV → Erros (ver dev_captura.php).
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['usuario_logado'])) { header("Location: index.html"); exit(); }

require_once "conexao.php";
require_once 'check_session.php';
require_once 'indicadores_localizacao.php';

$usuario_logado = $_SESSION['usuario_logado'] ?? '';
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $usuario_logado);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$permicao       = $row['permicao']       ?? '';
$classe_usuario = $row['classe_usuario'] ?? '';
$status         = $row['status']         ?? 'ATIVO';

if ($status !== 'ATIVO') { session_destroy(); header("Location: index.html?erro=bloqueado"); exit(); }
if ($permicao !== 'A' || !in_array($classe_usuario, ['PATRIMONIO','DEV'])) { header("Location: acesso_bloqueado.html"); exit(); }

/* ── Helpers ── */
function valorBRFloat(string $v): float {
    $v = trim(str_replace(['R$',' '], '', $v));
    if ($v === '') return 0.0;
    return (float)str_replace(',', '.', str_replace('.', '', $v));
}
function fmtBR(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}
function somaValorItem($conn, string $where): float {
    $sql = "SELECT SUM(CAST(REPLACE(REPLACE(NULLIF(TRIM(valor_item),''),'.',''),',','.') AS DECIMAL(20,2)))
            FROM cadastro WHERE $where";
    $r = $conn->query($sql);
    return $r ? (float)($r->fetch_row()[0] ?? 0) : 0.0;
}

/* ── Lista de unidades ── */
$resUni = $conn->query("SELECT DISTINCT TRIM(unidade) AS u FROM cadastro WHERE unidade IS NOT NULL AND TRIM(unidade)<>'' ORDER BY u ASC");
$unidades = [];
while ($r = $resUni->fetch_assoc()) $unidades[] = $r['u'];

/* ── Filtro ── */
$uni  = trim($_GET['unidade'] ?? '');
$u    = $uni !== '' ? $conn->real_escape_string(strtoupper($uni)) : '';

/* ════════════════════════════════════════
   ESTATÍSTICAS GLOBAIS (sem filtro)
════════════════════════════════════════ */
function qtd($conn, $where) { $r=$conn->query("SELECT COUNT(*) FROM cadastro WHERE $where"); return $r?(int)$r->fetch_row()[0]:0; }

$g_conc_qtd   = qtd($conn, "LOWER(TRIM(conciliado))='sim'");
$g_conc_val   = somaValorItem($conn, "LOWER(TRIM(conciliado))='sim'");
$g_nconc_qtd  = qtd($conn, "LOWER(TRIM(conciliado))<>'sim' AND UPPER(TRIM(conciliado))<>'PENDENTE'");
$g_nconc_val  = somaValorItem($conn, "LOWER(TRIM(conciliado))<>'sim' AND UPPER(TRIM(conciliado))<>'PENDENTE'");
$g_pend_qtd   = qtd($conn, "UPPER(TRIM(conciliado))='PENDENTE'");
$g_pend_val   = somaValorItem($conn, "UPPER(TRIM(conciliado))='PENDENTE'");

/* ── Localizados × Nota Fiscal (ver indicadores_localizacao.php) ── */
$g_loc      = il_calcular($conn);
$loc_filtro = ($u !== '') ? il_calcular($conn, "UPPER(TRIM(unidade))='$u'") : null;

/* ════════════════════════════════════════
   ESTATÍSTICAS POR FILTRO
════════════════════════════════════════ */
$f = null;
if ($u !== '') {
    $wUni  = "UPPER(TRIM(unidade))='$u'";
    $wCCU  = "UPPER(TRIM(centro_custo_unidade))='$u'";
    $wDest = "UPPER(TRIM(unidade_destino))='$u'";

    $f = [
        /* conciliados */
        'conc_uni_qtd'    => qtd($conn, "$wUni  AND LOWER(TRIM(conciliado))='sim'"),
        'conc_uni_val'    => somaValorItem($conn, "$wUni  AND LOWER(TRIM(conciliado))='sim'"),
        'conc_ccu_qtd'    => qtd($conn, "$wCCU  AND LOWER(TRIM(conciliado))='sim'"),
        'conc_ccu_val'    => somaValorItem($conn, "$wCCU  AND LOWER(TRIM(conciliado))='sim'"),
        'conc_dest_qtd'   => qtd($conn, "$wDest AND LOWER(TRIM(conciliado))='sim'"),
        'conc_dest_val'   => somaValorItem($conn, "$wDest AND LOWER(TRIM(conciliado))='sim'"),
        /* não conciliados */
        'nconc_uni_qtd'   => qtd($conn, "$wUni  AND LOWER(TRIM(conciliado))<>'sim' AND UPPER(TRIM(conciliado))<>'PENDENTE'"),
        'nconc_uni_val'   => somaValorItem($conn, "$wUni  AND LOWER(TRIM(conciliado))<>'sim' AND UPPER(TRIM(conciliado))<>'PENDENTE'"),
        'nconc_ccu_qtd'   => qtd($conn, "$wCCU  AND LOWER(TRIM(conciliado))<>'sim' AND UPPER(TRIM(conciliado))<>'PENDENTE'"),
        'nconc_ccu_val'   => somaValorItem($conn, "$wCCU  AND LOWER(TRIM(conciliado))<>'sim' AND UPPER(TRIM(conciliado))<>'PENDENTE'"),
        'nconc_dest_qtd'  => qtd($conn, "$wDest AND LOWER(TRIM(conciliado))<>'sim' AND UPPER(TRIM(conciliado))<>'PENDENTE'"),
        'nconc_dest_val'  => somaValorItem($conn, "$wDest AND LOWER(TRIM(conciliado))<>'sim' AND UPPER(TRIM(conciliado))<>'PENDENTE'"),
        /* pendentes */
        'pend_uni_qtd'    => qtd($conn, "$wUni  AND UPPER(TRIM(conciliado))='PENDENTE'"),
        'pend_uni_val'    => somaValorItem($conn, "$wUni  AND UPPER(TRIM(conciliado))='PENDENTE'"),
        'pend_ccu_qtd'    => qtd($conn, "$wCCU  AND UPPER(TRIM(conciliado))='PENDENTE'"),
        'pend_ccu_val'    => somaValorItem($conn, "$wCCU  AND UPPER(TRIM(conciliado))='PENDENTE'"),
        'pend_dest_qtd'   => qtd($conn, "$wDest AND UPPER(TRIM(conciliado))='PENDENTE'"),
        'pend_dest_val'   => somaValorItem($conn, "$wDest AND UPPER(TRIM(conciliado))='PENDENTE'"),
    ];
}

/* ════════════════════════════════════════
   TABELA DE ITENS (filtro por unidade)
════════════════════════════════════════ */
$itens = [];
if ($u !== '') {
    // Três queries separadas + deduplicação por id (mais eficiente que OR em 3 colunas)
    $campos = "id, unidade, centro_custo_unidade, descricao, descricao_detalhada,
               marca, modelo, serie, tag_antiga, tag_trocada, unidade_atribuida, unidade_destino,
               setor_atribuido, nota_fiscal, data_aquisicao, valor_item, conciliado";
    $visto = [];
    foreach ([
        "UPPER(TRIM(unidade))='$u'",
        "UPPER(TRIM(centro_custo_unidade))='$u'",
        "UPPER(TRIM(unidade_destino))='$u'",
    ] as $wh) {
        $res = $conn->query("SELECT $campos FROM cadastro WHERE $wh ORDER BY descricao ASC");
        if (!$res) continue;
        while ($r = $res->fetch_assoc()) {
            $id = $r['id'];
            if (!isset($visto[$id])) { $visto[$id] = true; $itens[] = $r; }
        }
    }
    usort($itens, fn($a,$b) => strcmp($a['descricao']??'',$b['descricao']??''));
}

$conn->close();

// Ordem das colunas: CC unidade, unidade, destino primeiro — depois dados do item
$colsTabela = [
    'centro_custo_unidade','unidade','unidade_destino',
    'descricao_detalhada','descricao','marca','modelo','serie',
    'tag_antiga','tag_trocada','unidade_atribuida','setor_atribuido',
    'nota_fiscal','data_aquisicao','valor_item'
];
$labelsTabela = [
    'CC UNIDADE','UNIDADE','UNIDADE DESTINO',
    'DESC. DETALHADA','DESCRIÇÃO','MARCA','MODELO','SÉRIE',
    'TAG ANTIGA','TAG TROCADA','UNIDADE ATRIBUÍDA','SETOR ATRIBUÍDO',
    'NOTA FISCAL','DATA AQUISIÇÃO','VALOR ITEM'
];
// Colunas visíveis na impressão (índice 0-base em $colsTabela):
// descricao_detalhada=3, tag_antiga=8, unidade_atribuida=10, nota_fiscal=12, valor_item=14
// Em nth-child da tabela (# ocupa posição 1, colunas começam em 2):
// → nth-child: 5, 10, 12, 14, 16
$printNth = [5, 10, 12, 14, 16];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relatório de Conciliação</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;background:linear-gradient(135deg,#001435,#60a5fa);display:flex;min-height:100vh;}

/* ══ SIDEBAR ══ */
.sidebar{width:220px;min-width:220px;background:#1a2b4a;display:flex;flex-direction:column;padding:20px 12px;gap:6px;box-shadow:4px 0 18px rgba(0,0,0,.4);flex-shrink:0;}
.sidebar .logo{text-align:center;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid rgba(255,255,255,.12);}
.sidebar .logo img{width:130px;}
.sidebar .sec-label{font-size:10px;color:#93c5fd;font-weight:700;text-transform:uppercase;letter-spacing:.8px;padding:8px 4px 4px;margin-top:4px;}
.sidebar .sb-btn{display:block;width:100%;padding:11px 14px;background:#2563eb;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;text-align:left;transition:.18s;text-decoration:none;line-height:1.3;}
.sidebar .sb-btn:hover{background:#3b82f6;transform:translateX(3px);}
.sidebar .sb-btn.active{background:#1d4ed8;border-left:4px solid #60a5fa;padding-left:10px;}
.sidebar .sb-btn.secondary{background:#1e3a5a;}.sidebar .sb-btn.secondary:hover{background:#2a4f7a;}
.sidebar .sb-btn.voltar{background:#374151;}.sidebar .sb-btn.voltar:hover{background:#4b5563;}

/* ══ MAIN ══ */
.main{flex:1;padding:28px;overflow-y:auto;min-width:0;}
.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px;padding-bottom:16px;border-bottom:2px solid rgba(255,255,255,.2);}
.page-header h1{color:#000000;font-size:1.4rem;font-weight:800;}
.btn-group{display:flex;gap:8px;flex-wrap:wrap;}
.btn{padding:8px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;transition:.18s;}
.btn-primary{background:#2563eb;color:#fff;}.btn-primary:hover{background:#1e40af;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}

/* ══ CARD ══ */
.card{background:#fff;padding:28px;border-radius:14px;box-shadow:0 12px 36px rgba(0,0,0,.25);margin-bottom:20px;}

/* ══ FILTRO ══ */
.filtro-bar{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;padding:16px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:24px;}
.filtro-bar label{font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px;}
.filtro-bar select{padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;min-width:260px;outline:none;background:#fff;}
.filtro-bar select:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15);}

/* ══ SEÇÃO ══ */
.secao-titulo{font-size:13px;font-weight:800;color:#1e3a8a;text-transform:uppercase;letter-spacing:.7px;margin-bottom:14px;padding-left:10px;border-left:4px solid #2563eb;}
.separador{border:none;border-top:2px solid #e5e7eb;margin:24px 0;}

/* ══ KPI ══ */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:14px;}
.kpi-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;}
.kpi{border-radius:12px;padding:14px 12px;color:#fff;display:flex;flex-direction:column;gap:3px;}
.kpi .kl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;opacity:.85;}
.kpi .kv{font-size:20px;font-weight:800;line-height:1.1;}
.kpi .ks{font-size:10px;opacity:.7;margin-top:2px;}
.kpi.blue  {background:linear-gradient(135deg,#1d4ed8,#3b82f6);border:1px solid #60a5fa;}
.kpi.green {background:linear-gradient(135deg,#065f46,#10b981);border:1px solid #34d399;}
.kpi.amber {background:linear-gradient(135deg,#78350f,#f59e0b);border:1px solid #fcd34d;}
.kpi.red   {background:linear-gradient(135deg,#7f1d1d,#ef4444);border:1px solid #fca5a5;}
.kpi.purple{background:linear-gradient(135deg,#4c1d95,#8b5cf6);border:1px solid #c4b5fd;}
.kpi.slate {background:linear-gradient(135deg,#1e293b,#475569);border:1px solid #94a3b8;}
.kpi.teal  {background:linear-gradient(135deg,#134e4a,#14b8a6);border:1px solid #5eead4;}

/* ══ GRUPO KPI com label ══ */
.kpi-group{margin-bottom:20px;}
.kpi-group-label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;padding:4px 0 4px 8px;border-left:3px solid #e5e7eb;}

/* ══ TABELA ══ */
.tabela-wrap{overflow-x:auto;overflow-y:auto;max-height:60vh;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:10px;}
table{width:100%;border-collapse:collapse;font-size:12px;min-width:1400px;}
thead tr.header-labels td{background:#1e40af;color:#fff;font-weight:700;padding:9px 10px;white-space:nowrap;position:sticky;top:0;z-index:3;}
thead tr.header-filters td{background:#1a3574;padding:4px 6px;position:sticky;top:37px;z-index:2;}
thead tr.header-filters td select{width:100%;padding:3px 5px;font-size:11px;border:1px solid #3b82f6;border-radius:4px;background:#1e3a8a;color:#fff;outline:none;}
thead tr.header-filters td select option{background:#1e3a8a;color:#fff;}
tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s;}
tbody tr:hover{background:#f0f7ff;}
tbody td{padding:7px 10px;color:#0f172a;white-space:nowrap;}
tbody tr.hidden-row{display:none;}
tbody tr.conc-sim td:first-child{border-left:3px solid #10b981;}
tbody tr.conc-nao td:first-child{border-left:3px solid #ef4444;}
tbody tr.conc-pend td:first-child{border-left:3px solid #f59e0b;}

.total-bar{display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:8px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0 0 10px 10px;border-top:none;font-size:13px;font-weight:700;color:#1e3a8a;}
.total-bar span{color:#16a34a;font-size:15px;}
.empty-state{text-align:center;padding:40px;color:#94a3b8;font-size:13px;}
.badge-legenda{display:inline-flex;gap:14px;margin-bottom:10px;font-size:11px;font-weight:700;flex-wrap:wrap;}
.badge-legenda span{display:flex;align-items:center;gap:5px;color:#374151;}
.badge-legenda span::before{content:'';display:inline-block;width:12px;height:12px;border-radius:2px;}
.leg-sim::before{background:#10b981;}
.leg-nao::before{background:#ef4444;}
.leg-pend::before{background:#f59e0b;}

/* ══ PLACEHOLDER ══ */
.placeholder{text-align:center;padding:50px 20px;color:#94a3b8;}
.placeholder p{font-size:14px;margin-top:12px;}

/* ══ BOTÕES FILTRO CONCILIAÇÃO ══ */
.filtros-conc{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:center;}
.filtros-conc-label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-right:4px;}
.btn-fconc{padding:6px 14px;border:2px solid transparent;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer;transition:.18s;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;}
.btn-fconc-todos{background:#f1f5f9;color:#475569;border-color:#cbd5e1;}
.btn-fconc-todos.ativo{background:#475569;color:#fff;border-color:#475569;}
.btn-fconc-sim{background:#dcfce7;color:#166534;border-color:#86efac;}
.btn-fconc-sim.ativo{background:#16a34a;color:#fff;border-color:#15803d;}
.btn-fconc-nao{background:#fee2e2;color:#991b1b;border-color:#fca5a5;}
.btn-fconc-nao.ativo{background:#dc2626;color:#fff;border-color:#b91c1c;}
.btn-fconc-pend{background:#fef3c7;color:#92400e;border-color:#fde68a;}
.btn-fconc-pend.ativo{background:#d97706;color:#fff;border-color:#b45309;}

/* ══ PRINT ══ */
@media print{
    @page{size:landscape;margin:12mm;}
    body{background:#fff !important;display:block;}
    .sidebar,.menu-toggle,.btn-group,.filtro-bar,.filtros-conc,.badge-print-hide{display:none !important;}
    .main{padding:6px;}
    .card{box-shadow:none;border-radius:0;padding:8px;margin-bottom:8px;page-break-inside:avoid;}
    .kpi{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .page-header h1{color:#000 !important;}
    thead tr.header-filters{display:none !important;}
    .tabela-wrap{max-height:none !important;overflow:visible !important;border:none;}
    table{min-width:auto !important;font-size:9px;}
    tbody td{padding:4px 5px;}
    .total-bar{font-size:12px;padding:6px 10px;}
    /* Oculta todas as colunas da tabela */
    #tbl-itens td{display:none;}
    /* Exibe apenas: desc_detalhada(5), tag_antiga(10), unidade_atribuida(12), nota_fiscal(14), valor_item(16) */
    #tbl-itens td:nth-child(5),
    #tbl-itens td:nth-child(10),
    #tbl-itens td:nth-child(12),
    #tbl-itens td:nth-child(14),
    #tbl-itens td:nth-child(16){display:table-cell;}
}

/* ══ MOBILE ══ */
.menu-toggle{display:none;position:fixed;top:12px;left:12px;z-index:1100;background:#1e40af;border:none;border-radius:8px;padding:8px 10px;cursor:pointer;color:#fff;font-size:18px;}
@media(max-width:768px){
    .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:1000;transform:translateX(-100%);transition:transform .25s;overflow-y:auto;}
    .sidebar.open{transform:translateX(0);}
    .menu-toggle{display:block;}
    .main{padding:16px;padding-top:56px;}
    .kpi-grid-3{grid-template-columns:1fr 1fr;}
}
</style>
</head>
<body>

<button class="menu-toggle" id="menuToggle">☰</button>

<!-- ══ SIDEBAR ══ -->
<div class="sidebar" id="sidebar">
    <div class="logo"><img src="logo_2.png" alt="PatAsset"></div>

    <div class="sec-label">Navegação</div>
    <a href="relatorio.php"             class="sb-btn voltar">← Voltar</a>

    <div class="sec-label">Relatórios</div>
    <a href="relatorio.php"             class="sb-btn secondary">Relatório de Movimentações</a>
    <a href="rel_conciliacao.php"       class="sb-btn active">Relatório de Conciliação</a>

    <div class="sec-label">Dashboards</div>
    <a href="indicadores_dashboard.php" class="sb-btn secondary">Dashboard Indicadores</a>
    <a href="dash.php"                  class="sb-btn secondary">Dashboard Patrimônio</a>

    <div class="sec-label">Exportar</div>
    <button class="sb-btn" onclick="window.print()"  style="background:#065f46;">🖨 Imprimir</button>
    <button class="sb-btn" onclick="exportarPDF()"   style="background:#1d4ed8;">⬇ Exportar PDF</button>
</div>

<!-- ══ MAIN ══ -->
<div class="main">

    <!-- ══════════════════════════════════
         CARD PRINCIPAL (header + filtro + KPIs globais)
    ══════════════════════════════════ -->
    <div class="card">

        <div class="page-header">
            <h1>Relatório de Conciliação</h1>
            <div class="btn-group">
                <button class="btn btn-primary" onclick="window.print()">🖨 Imprimir</button>
                <button class="btn btn-success" onclick="exportarPDF()">⬇ Exportar PDF</button>
            </div>
        </div>

        <!-- ══ FILTRO ══ -->
        <form method="GET" action="rel_conciliacao.php">
            <div class="filtro-bar">
                <div>
                    <label for="sel_unidade">Unidade</label>
                    <select name="unidade" id="sel_unidade" onchange="this.form.submit()">
                        <option value="">— Geral (todas as unidades) —</option>
                        <?php foreach ($unidades as $uo): ?>
                        <option value="<?= htmlspecialchars($uo) ?>" <?= ($uni===$uo)?'selected':'' ?>>
                            <?= htmlspecialchars($uo) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($uni): ?>
                <span style="font-size:12px;color:#6b7280;align-self:center">
                    Filtrando: <strong style="color:#1e3a8a"><?= htmlspecialchars($uni) ?></strong>
                </span>
                <?php endif; ?>
            </div>
        </form>

        <div class="secao-titulo">Indicadores Gerais (todos os itens)</div>

        <div class="kpi-group">
            <div class="kpi-group-label">Conciliados</div>
            <div class="kpi-grid">
                <div class="kpi green">
                    <div class="kl">Total de itens conciliados</div>
                    <div class="kv"><?= number_format($g_conc_qtd,0,',','.') ?></div>
                    <div class="ks">conciliado = SIM</div>
                </div>
                <div class="kpi green">
                    <div class="kl">Valor dos conciliados</div>
                    <div class="kv" style="font-size:15px"><?= fmtBR($g_conc_val) ?></div>
                    <div class="ks">soma valor_item (SIM)</div>
                </div>
            </div>
        </div>

        <div class="kpi-group">
            <div class="kpi-group-label">Não Conciliados</div>
            <div class="kpi-grid">
                <div class="kpi red">
                    <div class="kl">Total de itens não conciliados</div>
                    <div class="kv"><?= number_format($g_nconc_qtd,0,',','.') ?></div>
                    <div class="ks">conciliado ≠ SIM</div>
                </div>
                <div class="kpi red">
                    <div class="kl">Valor dos não conciliados</div>
                    <div class="kv" style="font-size:15px"><?= fmtBR($g_nconc_val) ?></div>
                    <div class="ks">soma valor_item (≠ SIM)</div>
                </div>
            </div>
        </div>

        <div class="kpi-group">
            <div class="kpi-group-label">Pendentes</div>
            <div class="kpi-grid">
                <div class="kpi amber">
                    <div class="kl">Total de itens pendentes</div>
                    <div class="kv"><?= number_format($g_pend_qtd,0,',','.') ?></div>
                    <div class="ks">conciliado = PENDENTE</div>
                </div>
                <div class="kpi amber">
                    <div class="kl">Valor dos pendentes</div>
                    <div class="kv" style="font-size:15px"><?= fmtBR($g_pend_val) ?></div>
                    <div class="ks">soma valor_item (PENDENTE)</div>
                </div>
            </div>
        </div>

        <!-- ══ LOCALIZADOS × NOTA FISCAL ══ -->
        <div class="kpi-group">
            <div class="kpi-group-label">Localizados (LOCALIZADO = SIM)</div>
            <div class="kpi-grid-3">
                <div class="kpi green">
                    <div class="kl">Com nota fiscal</div>
                    <div class="kv"><?= number_format($g_loc['loc_com_nf']['qtd'],0,',','.') ?></div>
                    <div class="ks"><?= il_fmt_brl($g_loc['loc_com_nf']['valor']) ?></div>
                </div>
                <div class="kpi teal">
                    <div class="kl">Sem nota fiscal</div>
                    <div class="kv"><?= number_format($g_loc['loc_sem_nf']['qtd'],0,',','.') ?></div>
                    <div class="ks"><?= il_fmt_brl($g_loc['loc_sem_nf']['valor']) ?></div>
                </div>
                <div class="kpi blue">
                    <div class="kl">Total localizados</div>
                    <div class="kv"><?= number_format($g_loc['loc_total']['qtd'],0,',','.') ?></div>
                    <div class="ks"><?= il_fmt_brl($g_loc['loc_total']['valor']) ?></div>
                </div>
            </div>
        </div>

        <div class="kpi-group">
            <div class="kpi-group-label">Não localizados (LOCALIZADO = NÃO)</div>
            <div class="kpi-grid-3">
                <div class="kpi red">
                    <div class="kl">Com nota fiscal</div>
                    <div class="kv"><?= number_format($g_loc['nloc_com_nf']['qtd'],0,',','.') ?></div>
                    <div class="ks"><?= il_fmt_brl($g_loc['nloc_com_nf']['valor']) ?></div>
                </div>
                <div class="kpi amber">
                    <div class="kl">Sem nota fiscal</div>
                    <div class="kv"><?= number_format($g_loc['nloc_sem_nf']['qtd'],0,',','.') ?></div>
                    <div class="ks"><?= il_fmt_brl($g_loc['nloc_sem_nf']['valor']) ?></div>
                </div>
                <div class="kpi slate">
                    <div class="kl">Total não localizados</div>
                    <div class="kv"><?= number_format($g_loc['nloc_total']['qtd'],0,',','.') ?></div>
                    <div class="ks"><?= il_fmt_brl($g_loc['nloc_total']['valor']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════
         KPIs POR FILTRO
    ══════════════════════════════════ -->
    <?php if ($f): ?>
    <div class="card">
        <div class="secao-titulo">Indicadores por filtro — <?= htmlspecialchars($uni) ?></div>

        <!-- CONCILIADOS POR FILTRO -->
        <div class="kpi-group">
            <div class="kpi-group-label">Conciliados — por contexto</div>
            <div class="kpi-grid-3">
                <div class="kpi green">
                    <div class="kl">Qtd — Unidade</div>
                    <div class="kv"><?= number_format($f['conc_uni_qtd'],0,',','.') ?></div>
                    <div class="ks">unidade = <?= htmlspecialchars($uni) ?></div>
                </div>
                <div class="kpi green">
                    <div class="kl">Qtd — Centro de Custo</div>
                    <div class="kv"><?= number_format($f['conc_ccu_qtd'],0,',','.') ?></div>
                    <div class="ks">centro_custo_unidade = <?= htmlspecialchars($uni) ?></div>
                </div>
                <div class="kpi green">
                    <div class="kl">Qtd — Unidade Destino</div>
                    <div class="kv"><?= number_format($f['conc_dest_qtd'],0,',','.') ?></div>
                    <div class="ks">unidade_destino = <?= htmlspecialchars($uni) ?></div>
                </div>
                <div class="kpi teal">
                    <div class="kl">Valor — Unidade</div>
                    <div class="kv" style="font-size:14px"><?= fmtBR($f['conc_uni_val']) ?></div>
                    <div class="ks">valor_item (SIM, unidade)</div>
                </div>
                <div class="kpi teal">
                    <div class="kl">Valor — Centro de Custo</div>
                    <div class="kv" style="font-size:14px"><?= fmtBR($f['conc_ccu_val']) ?></div>
                    <div class="ks">valor_item (SIM, CC)</div>
                </div>
                <div class="kpi teal">
                    <div class="kl">Valor — Unidade Destino</div>
                    <div class="kv" style="font-size:14px"><?= fmtBR($f['conc_dest_val']) ?></div>
                    <div class="ks">valor_item (SIM, destino)</div>
                </div>
            </div>
        </div>

        <div class="separador"></div>

        <!-- NÃO CONCILIADOS POR FILTRO -->
        <div class="kpi-group">
            <div class="kpi-group-label">Não Conciliados — por contexto</div>
            <div class="kpi-grid-3">
                <div class="kpi red">
                    <div class="kl">Qtd — Unidade</div>
                    <div class="kv"><?= number_format($f['nconc_uni_qtd'],0,',','.') ?></div>
                    <div class="ks">unidade = <?= htmlspecialchars($uni) ?></div>
                </div>
                <div class="kpi red">
                    <div class="kl">Qtd — Centro de Custo</div>
                    <div class="kv"><?= number_format($f['nconc_ccu_qtd'],0,',','.') ?></div>
                    <div class="ks">centro_custo_unidade = <?= htmlspecialchars($uni) ?></div>
                </div>
                <div class="kpi red">
                    <div class="kl">Qtd — Unidade Destino</div>
                    <div class="kv"><?= number_format($f['nconc_dest_qtd'],0,',','.') ?></div>
                    <div class="ks">unidade_destino = <?= htmlspecialchars($uni) ?></div>
                </div>
                <div class="kpi slate">
                    <div class="kl">Valor — Unidade</div>
                    <div class="kv" style="font-size:14px"><?= fmtBR($f['nconc_uni_val']) ?></div>
                    <div class="ks">valor_item (≠ SIM, unidade)</div>
                </div>
                <div class="kpi slate">
                    <div class="kl">Valor — Centro de Custo</div>
                    <div class="kv" style="font-size:14px"><?= fmtBR($f['nconc_ccu_val']) ?></div>
                    <div class="ks">valor_item (≠ SIM, CC)</div>
                </div>
                <div class="kpi slate">
                    <div class="kl">Valor — Unidade Destino</div>
                    <div class="kv" style="font-size:14px"><?= fmtBR($f['nconc_dest_val']) ?></div>
                    <div class="ks">valor_item (≠ SIM, destino)</div>
                </div>
            </div>
        </div>

        <div class="separador"></div>

        <!-- PENDENTES POR FILTRO -->
        <div class="kpi-group">
            <div class="kpi-group-label">Pendentes — por contexto</div>
            <div class="kpi-grid-3">
                <div class="kpi amber">
                    <div class="kl">Qtd — Unidade</div>
                    <div class="kv"><?= number_format($f['pend_uni_qtd'],0,',','.') ?></div>
                    <div class="ks">unidade = <?= htmlspecialchars($uni) ?></div>
                </div>
                <div class="kpi amber">
                    <div class="kl">Qtd — Centro de Custo</div>
                    <div class="kv"><?= number_format($f['pend_ccu_qtd'],0,',','.') ?></div>
                    <div class="ks">centro_custo_unidade = <?= htmlspecialchars($uni) ?></div>
                </div>
                <div class="kpi amber">
                    <div class="kl">Qtd — Unidade Destino</div>
                    <div class="kv"><?= number_format($f['pend_dest_qtd'],0,',','.') ?></div>
                    <div class="ks">unidade_destino = <?= htmlspecialchars($uni) ?></div>
                </div>
                <div class="kpi purple">
                    <div class="kl">Valor — Unidade</div>
                    <div class="kv" style="font-size:14px"><?= fmtBR($f['pend_uni_val']) ?></div>
                    <div class="ks">valor_item (PENDENTE, unidade)</div>
                </div>
                <div class="kpi purple">
                    <div class="kl">Valor — Centro de Custo</div>
                    <div class="kv" style="font-size:14px"><?= fmtBR($f['pend_ccu_val']) ?></div>
                    <div class="ks">valor_item (PENDENTE, CC)</div>
                </div>
                <div class="kpi purple">
                    <div class="kl">Valor — Unidade Destino</div>
                    <div class="kv" style="font-size:14px"><?= fmtBR($f['pend_dest_val']) ?></div>
                    <div class="ks">valor_item (PENDENTE, destino)</div>
                </div>
            </div>
        </div>

        <?php if ($loc_filtro): ?>
        <div class="separador"></div>

        <!-- LOCALIZADOS × NF POR FILTRO -->
        <div class="kpi-group">
            <div class="kpi-group-label">Localizados × Nota Fiscal — <?= htmlspecialchars($uni) ?></div>
            <div class="kpi-grid-3">
                <div class="kpi green">
                    <div class="kl">Localizados c/ NF</div>
                    <div class="kv"><?= number_format($loc_filtro['loc_com_nf']['qtd'],0,',','.') ?></div>
                    <div class="ks"><?= il_fmt_brl($loc_filtro['loc_com_nf']['valor']) ?></div>
                </div>
                <div class="kpi teal">
                    <div class="kl">Localizados s/ NF</div>
                    <div class="kv"><?= number_format($loc_filtro['loc_sem_nf']['qtd'],0,',','.') ?></div>
                    <div class="ks"><?= il_fmt_brl($loc_filtro['loc_sem_nf']['valor']) ?></div>
                </div>
                <div class="kpi blue">
                    <div class="kl">Total localizados</div>
                    <div class="kv"><?= number_format($loc_filtro['loc_total']['qtd'],0,',','.') ?></div>
                    <div class="ks"><?= il_fmt_brl($loc_filtro['loc_total']['valor']) ?></div>
                </div>
                <div class="kpi red">
                    <div class="kl">Não localizados c/ NF</div>
                    <div class="kv"><?= number_format($loc_filtro['nloc_com_nf']['qtd'],0,',','.') ?></div>
                    <div class="ks"><?= il_fmt_brl($loc_filtro['nloc_com_nf']['valor']) ?></div>
                </div>
                <div class="kpi amber">
                    <div class="kl">Não localizados s/ NF</div>
                    <div class="kv"><?= number_format($loc_filtro['nloc_sem_nf']['qtd'],0,',','.') ?></div>
                    <div class="ks"><?= il_fmt_brl($loc_filtro['nloc_sem_nf']['valor']) ?></div>
                </div>
                <div class="kpi slate">
                    <div class="kl">Total não localizados</div>
                    <div class="kv"><?= number_format($loc_filtro['nloc_total']['qtd'],0,',','.') ?></div>
                    <div class="ks"><?= il_fmt_brl($loc_filtro['nloc_total']['valor']) ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div><!-- /card KPIs filtro -->
    <?php elseif (!$uni): ?>
    <div class="card" style="display:none"><!-- placeholder removido --><div class="placeholder">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
            </svg>
            <p>Selecione uma <strong>unidade</strong> para ver os indicadores filtrados e a tabela de itens.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════
         TABELA DE ITENS
    ══════════════════════════════════ -->
    <?php if (!empty($itens)): ?>
    <div class="card">
        <div class="secao-titulo">
            Itens da unidade <?= htmlspecialchars($uni) ?>
            <span style="margin-left:8px;background:#dbeafe;color:#1d4ed8;border-radius:999px;font-size:11px;font-weight:700;padding:2px 10px" id="badge-itens"><?= count($itens) ?> itens</span>
        </div>

        <div class="filtros-conc">
            <span class="filtros-conc-label">Conciliação:</span>
            <button class="btn-fconc btn-fconc-todos ativo" id="fconc-todos" onclick="toggleFiltroConc('')">Todos</button>
            <button class="btn-fconc btn-fconc-sim"  id="fconc-sim"  onclick="toggleFiltroConc('sim')">● Conciliado (SIM)</button>
            <button class="btn-fconc btn-fconc-nao"  id="fconc-nao"  onclick="toggleFiltroConc('nao')">● Não conciliado</button>
            <button class="btn-fconc btn-fconc-pend" id="fconc-pend" onclick="toggleFiltroConc('pend')">● Pendente</button>
        </div>
        <div class="filtros-conc" style="margin-top:6px">
            <span class="filtros-conc-label">Contexto:</span>
            <button class="btn-fconc" style="background:#eff6ff;color:#1e40af;border-color:#93c5fd" id="fctx-todos" onclick="toggleFiltroCtx('todos')">Todos</button>
            <button class="btn-fconc" style="background:#eff6ff;color:#1e40af;border-color:#93c5fd" id="fctx-uni"   onclick="toggleFiltroCtx('uni')">■ Unidade</button>
            <button class="btn-fconc" style="background:#eff6ff;color:#1e40af;border-color:#93c5fd" id="fctx-ccu"   onclick="toggleFiltroCtx('ccu')">■ CC Unidade</button>
            <button class="btn-fconc" style="background:#eff6ff;color:#1e40af;border-color:#93c5fd" id="fctx-dest"  onclick="toggleFiltroCtx('dest')">■ Unidade Destino</button>
        </div>

        <div class="tabela-wrap">
            <table id="tbl-itens">
                <thead>
                    <tr class="header-labels">
                        <td>#</td>
                        <?php foreach ($labelsTabela as $l): ?>
                        <td><?= $l ?></td>
                        <?php endforeach; ?>
                        <td>CONCILIADO</td>
                    </tr>
                    <tr class="header-filters">
                        <td></td>
                        <?php
                        // Colunas 0,1,2 (CCU, UNI, DEST) — filtradas pelos botões de contexto, sem select
                        // Colunas 3+ — select estático gerado pelo PHP
                        foreach ($colsTabela as $ci => $col):
                            if ($ci < 3): ?>
                        <td></td>
                            <?php else:
                                $vals = array_values(array_unique(array_filter(array_column($itens, $col))));
                                sort($vals); ?>
                        <td><select onchange="filtrarLinhas()">
                            <option value="">Todos</option>
                            <?php foreach ($vals as $v): ?><option><?= htmlspecialchars($v) ?></option><?php endforeach; ?>
                        </select></td>
                            <?php endif;
                        endforeach;
                        // Conciliado select
                        $valsConc = array_values(array_unique(array_filter(array_column($itens, 'conciliado'))));
                        sort($valsConc); ?>
                        <td><select onchange="filtrarLinhas()">
                            <option value="">Todos</option>
                            <?php foreach ($valsConc as $v): ?><option><?= htmlspecialchars($v) ?></option><?php endforeach; ?>
                        </select></td>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($itens as $i => $row):
                    $conc = strtoupper(trim($row['conciliado'] ?? ''));
                    $cls  = $conc === 'SIM' ? 'conc-sim' : ($conc === 'PENDENTE' ? 'conc-pend' : 'conc-nao');
                ?>
                <tr class="<?= $cls ?>">
                    <td style="color:#94a3b8;font-size:11px"><?= $i+1 ?></td>
                    <?php foreach ($colsTabela as $col): ?>
                    <td><?= htmlspecialchars($row[$col] ?? '') ?></td>
                    <?php endforeach; ?>
                    <td style="font-weight:700;color:<?= $conc==='SIM'?'#16a34a':($conc==='PENDENTE'?'#d97706':'#dc2626') ?>">
                        <?= htmlspecialchars($row['conciliado'] ?? '') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        $totalValTabela = array_sum(array_map(fn($r) => valorBRFloat($r['valor_item'] ?? ''), $itens));
        ?>
        <div class="total-bar">
            Valor total dos itens exibidos: <span id="total-tabela"><?= fmtBR($totalValTabela) ?></span>
        </div>
    </div>
    <?php elseif ($uni): ?>
    <div class="card">
        <div class="empty-state" style="text-align:center;padding:30px;color:#94a3b8;font-size:13px">
            Nenhum item encontrado para esta unidade.
        </div>
    </div>
    <?php endif; ?>

</div><!-- /main -->

<script>
/* ══ DADOS (emitidos pelo PHP — sem leitura do DOM) ══ */
<?php if (!empty($itens)):
$rowJson = array_map(function($r) use ($u) {
    $conc = strtoupper(trim($r['conciliado'] ?? ''));
    $concClass = $conc === 'SIM' ? 'sim' : ($conc === 'PENDENTE' ? 'pend' : 'nao');
    return [
        strtoupper(trim($r['centro_custo_unidade'] ?? '')), // 0 I_CCU
        strtoupper(trim($r['unidade']              ?? '')), // 1 I_UNI
        strtoupper(trim($r['unidade_destino']      ?? '')), // 2 I_DEST
        $concClass,                                         // 3 I_CONC: 'sim'|'pend'|'nao'
        $r['valor_item'] ?? '',                             // 4 I_VAL
        strtoupper(trim($r['unidade']              ?? '')) === $u ? 1 : 0, // 5 isUni
        strtoupper(trim($r['centro_custo_unidade'] ?? '')) === $u ? 1 : 0, // 6 isCcu
        strtoupper(trim($r['unidade_destino']      ?? '')) === $u ? 1 : 0, // 7 isDest
    ];
}, $itens);
?>
const ROW_DATA = <?= json_encode(array_values($rowJson)) ?>;
<?php else: ?>
const ROW_DATA = [];
<?php endif; ?>

/* Índices no ROW_DATA */
const I_CCU=0, I_UNI=1, I_DEST=2, I_CONC=3, I_VAL=4, I_IS_UNI=5, I_IS_CCU=6, I_IS_DEST=7;

let filtroConc = '';       // '' | 'sim' | 'nao' | 'pend'
let filtroCtx  = '';       // '' | 'uni' | 'ccu' | 'dest'
let tblRows    = null;

function getTblRows() {
    if (!tblRows) tblRows = document.querySelectorAll('#tbl-itens tbody tr');
    return tblRows;
}
function valorBRFloat(v) {
    v = (v||'').replace(/R\$|\s/g,'');
    if (!v) return 0;
    return parseFloat(v.replace(/\./g,'').replace(',','.')) || 0;
}
function fmtBR(v) {
    return 'R$ ' + Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function filtrarLinhas() {
    // Selects estáticos (colunas 3+ da tabela; primeiras 3 não têm select)
    const selects = Array.from(document.querySelectorAll('#tbl-itens thead tr.header-filters select'));

    const rows = getTblRows();
    let visiveis = 0, totalVal = 0;

    rows.forEach((tr, i) => {
        const rd = ROW_DATA[i];
        if (!rd) return;

        let show = true;

        // Filtro conciliação
        if (filtroConc && rd[I_CONC] !== filtroConc) show = false;

        // Filtro contexto (botões unidade / CC / destino)
        if (show && filtroCtx) {
            if (filtroCtx === 'uni'  && !rd[I_IS_UNI])  show = false;
            if (filtroCtx === 'ccu'  && !rd[I_IS_CCU])  show = false;
            if (filtroCtx === 'dest' && !rd[I_IS_DEST]) show = false;
        }

        // Filtros de coluna estáticos (select no thead, tds[4+])
        if (show) {
            const tds = tr.querySelectorAll('td');
            for (let j = 0; j < selects.length; j++) {
                if (!selects[j].value) continue;
                const celVal = (tds[j + 4]?.textContent ?? '').trim(); // +4: #,CCU,UNI,DEST
                if (celVal !== selects[j].value) { show = false; break; }
            }
        }

        tr.classList.toggle('hidden-row', !show);
        if (show) { visiveis++; totalVal += valorBRFloat(rd[I_VAL]); }
    });

    const badge = document.getElementById('badge-itens');
    if (badge) badge.textContent = visiveis + ' itens';
    const totalEl = document.getElementById('total-tabela');
    if (totalEl) totalEl.textContent = fmtBR(totalVal);
}

function toggleFiltroConc(tipo) {
    filtroConc = tipo;
    ['todos','sim','nao','pend'].forEach(id => {
        const btn = document.getElementById('fconc-' + id);
        if (btn) btn.classList.toggle('ativo', tipo === '' ? id === 'todos' : id === tipo);
    });
    filtrarLinhas();
}

function toggleFiltroCtx(ctx) {
    filtroCtx = (filtroCtx === ctx && ctx !== 'todos') ? '' : (ctx === 'todos' ? '' : ctx);
    ['todos','uni','ccu','dest'].forEach(id => {
        const btn = document.getElementById('fctx-' + id);
        if (!btn) return;
        const ativo = (filtroCtx === '' && id === 'todos') || (filtroCtx !== '' && id === filtroCtx);
        btn.style.background    = ativo ? '#1d4ed8' : '#eff6ff';
        btn.style.color         = ativo ? '#fff'    : '#1e40af';
        btn.style.borderColor   = ativo ? '#1d4ed8' : '#93c5fd';
    });
    filtrarLinhas();
}

/* ── Sidebar mobile ── */
document.getElementById('menuToggle').onclick = () => {
    document.getElementById('sidebar').classList.toggle('open');
};

/* ── Exportar PDF ── */
function exportarPDF() {
    const aviso = document.createElement('div');
    aviso.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center';
    aviso.innerHTML = `
        <div style="background:#fff;border-radius:14px;padding:28px 32px;max-width:420px;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.4)">
            <div style="font-size:36px;margin-bottom:12px">🖨</div>
            <h3 style="font-size:16px;color:#111827;margin-bottom:8px">Exportar como PDF</h3>
            <p style="font-size:13px;color:#475569;margin-bottom:20px">
                Na janela de impressão, selecione<br>
                <strong>"Salvar como PDF"</strong> como destino.
            </p>
            <button onclick="this.closest('div').parentElement.remove();window.print()"
                style="background:#2563eb;color:#fff;border:none;border-radius:8px;padding:10px 24px;font-size:13px;font-weight:700;cursor:pointer;margin-right:8px">
                Continuar
            </button>
            <button onclick="this.closest('div').parentElement.remove()"
                style="background:#e5e7eb;color:#111;border:none;border-radius:8px;padding:10px 16px;font-size:13px;font-weight:700;cursor:pointer">
                Cancelar
            </button>
        </div>`;
    document.body.appendChild(aviso);
}
</script>

<script>
(function hb(){
    fetch('heartbeat.php?_=' + Date.now(), { method:'POST', credentials:'same-origin', cache:'no-store' })
    .then(r => r.json())
    .then(d => { if (d.revogada) location.href = 'index.html?error=Sua+sessao+foi+encerrada'; })
    .catch(() => {});
    setTimeout(hb, 30000);
})();
</script>
</body>
</html>