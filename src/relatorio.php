<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) { header("Location: index.html"); exit(); }

require_once "conexao.php";
require_once 'check_session.php';

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
if ($permicao !== 'A' || !in_array($classe_usuario, ['PATRIMONIO', 'DEV'])) { header("Location: acesso_bloqueado.html"); exit(); }

/* ── Lista de unidades ── */
$resUni = $conn->query("SELECT DISTINCT TRIM(unidade) AS u FROM cadastro WHERE unidade IS NOT NULL AND TRIM(unidade) <> '' ORDER BY u ASC");
$unidades = [];
while ($r = $resUni->fetch_assoc()) $unidades[] = $r['u'];

function valorBRFloat(string $v): float {
    $v = trim(str_replace(['R$',' '], '', $v));
    if ($v === '') return 0.0;
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return (float)$v;
}
function fmtBR(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}

$uni      = trim($_GET['unidade'] ?? '');
$filtrado = ($uni !== '');
$cols     = "unidade, unidade_destino, descricao_detalhada, descricao, tag_antiga, tag_trocada, marca, modelo, serie, subgrupo, data_movimentacao, valor_item";

if ($filtrado) {
    /* ── Com filtro de unidade ── */
    $u = $conn->real_escape_string(strtoupper($uni));

    $r = $conn->query("SELECT COUNT(*) FROM cadastro WHERE UPPER(TRIM(unidade))='$u' AND UPPER(TRIM(movimentado_definitivo))='SIM'");
    $qtd_definitivas = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(*) FROM cadastro
        WHERE UPPER(TRIM(unidade))='$u' AND UPPER(TRIM(movimentado))='SIM'
          AND unidade_destino IS NOT NULL AND TRIM(unidade_destino)<>''
          AND UPPER(TRIM(unidade_destino))<>'$u'");
    $qtd_saidas_inter = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(*) FROM cadastro
        WHERE UPPER(TRIM(unidade))='$u' AND UPPER(TRIM(movimentado))='SIM'
          AND UPPER(TRIM(unidade_destino))='$u'");
    $qtd_internas = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(*) FROM cadastro
        WHERE UPPER(TRIM(unidade_destino))='$u' AND UPPER(TRIM(movimentado))='SIM'
          AND (UPPER(TRIM(unidade))<>'$u' OR unidade IS NULL)");
    $qtd_entradas = (int)$r->fetch_row()[0];

    $qtd_total_mov = $qtd_saidas_inter + $qtd_internas + $qtd_entradas;

    $res_saidas = $conn->query("SELECT $cols FROM cadastro
        WHERE UPPER(TRIM(unidade))='$u' AND UPPER(TRIM(movimentado))='SIM'
          AND unidade_destino IS NOT NULL AND TRIM(unidade_destino)<>''
          AND UPPER(TRIM(unidade_destino))<>'$u'
        ORDER BY descricao ASC");
    $saidas = []; $total_val_saidas = 0.0;
    while ($r = $res_saidas->fetch_assoc()) {
        $total_val_saidas += valorBRFloat($r['valor_item'] ?? '');
        $saidas[] = $r;
    }

    $res_entradas = $conn->query("SELECT $cols FROM cadastro
        WHERE UPPER(TRIM(unidade_destino))='$u' AND UPPER(TRIM(movimentado))='SIM'
          AND (UPPER(TRIM(unidade))<>'$u' OR unidade IS NULL)
        ORDER BY descricao ASC");
    $entradas = []; $total_val_entradas = 0.0;
    while ($r = $res_entradas->fetch_assoc()) {
        $total_val_entradas += valorBRFloat($r['valor_item'] ?? '');
        $entradas[] = $r;
    }

} else {
    /* ── Sem filtro: estatísticas gerais (todas as unidades) ── */
    $r = $conn->query("SELECT COUNT(*) FROM cadastro WHERE UPPER(TRIM(movimentado_definitivo))='SIM'");
    $qtd_definitivas = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(*) FROM cadastro
        WHERE UPPER(TRIM(movimentado))='SIM'
          AND unidade_destino IS NOT NULL AND TRIM(unidade_destino)<>''
          AND UPPER(TRIM(unidade_destino)) <> UPPER(TRIM(unidade))");
    $qtd_saidas_inter = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(*) FROM cadastro
        WHERE UPPER(TRIM(movimentado))='SIM'
          AND unidade_destino IS NOT NULL AND TRIM(unidade_destino)<>''
          AND UPPER(TRIM(unidade_destino)) = UPPER(TRIM(unidade))");
    $qtd_internas = (int)$r->fetch_row()[0];

    // Global: entradas = mesmo conjunto que saídas inter (perspectiva destino)
    $qtd_entradas  = $qtd_saidas_inter;
    $qtd_total_mov = $qtd_saidas_inter + $qtd_internas;

    // Tabela: transferências entre unidades
    $res_saidas = $conn->query("SELECT $cols FROM cadastro
        WHERE UPPER(TRIM(movimentado))='SIM'
          AND unidade_destino IS NOT NULL AND TRIM(unidade_destino)<>''
          AND UPPER(TRIM(unidade_destino)) <> UPPER(TRIM(unidade))
        ORDER BY unidade ASC, descricao ASC");
    $saidas = []; $total_val_saidas = 0.0;
    while ($r = $res_saidas->fetch_assoc()) {
        $total_val_saidas += valorBRFloat($r['valor_item'] ?? '');
        $saidas[] = $r;
    }

    // Tabela: movimentações internas (mesmo filtro que a query de contagem)
    $res_entradas = $conn->query("SELECT $cols FROM cadastro
        WHERE UPPER(TRIM(movimentado))='SIM'
          AND unidade_destino IS NOT NULL AND TRIM(unidade_destino)<>''
          AND UPPER(TRIM(unidade_destino)) = UPPER(TRIM(unidade))
        ORDER BY unidade ASC, descricao ASC");
    $entradas = []; $total_val_entradas = 0.0;
    while ($r = $res_entradas->fetch_assoc()) {
        $total_val_entradas += valorBRFloat($r['valor_item'] ?? '');
        $entradas[] = $r;
    }
}

$dados = compact(
    'qtd_definitivas','qtd_saidas_inter','qtd_internas',
    'qtd_entradas','qtd_total_mov',
    'saidas','total_val_saidas',
    'entradas','total_val_entradas'
);

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relatório Dinâmico</title>
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
.card{background:#fff;padding:28px;border-radius:14px;box-shadow:0 12px 36px rgba(0,0,0,.25);}

/* ══ FILTRO ══ */
.filtro-bar{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:28px;padding:16px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;}
.filtro-bar label{font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px;}
.filtro-bar select{padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;min-width:260px;outline:none;background:#fff;}
.filtro-bar select:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15);}

/* ══ SEÇÃO ══ */
.secao{margin-bottom:32px;}
.secao-titulo{font-size:13px;font-weight:800;color:#1e3a8a;text-transform:uppercase;letter-spacing:.7px;margin-bottom:14px;padding-left:10px;border-left:4px solid #2563eb;}

/* ══ KPI ══ */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:8px;}
.kpi{border-radius:12px;padding:16px 14px;color:#fff;display:flex;flex-direction:column;gap:4px;}
.kpi .kl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;opacity:.85;}
.kpi .kv{font-size:26px;font-weight:800;line-height:1;}
.kpi .ks{font-size:11px;opacity:.75;margin-top:2px;}
.kpi.blue  {background:linear-gradient(135deg,#1d4ed8,#3b82f6);border:1px solid #60a5fa;}
.kpi.green {background:linear-gradient(135deg,#065f46,#10b981);border:1px solid #34d399;}
.kpi.amber {background:linear-gradient(135deg,#78350f,#f59e0b);border:1px solid #fcd34d;}
.kpi.red   {background:linear-gradient(135deg,#7f1d1d,#ef4444);border:1px solid #fca5a5;}
.kpi.purple{background:linear-gradient(135deg,#4c1d95,#8b5cf6);border:1px solid #c4b5fd;}
.kpi.slate {background:linear-gradient(135deg,#1e293b,#475569);border:1px solid #94a3b8;}

/* ══ TABELAS ══ */
.tabela-wrap{overflow-x:auto;overflow-y:auto;max-height:60vh;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:10px;}
table{width:100%;border-collapse:collapse;font-size:12px;min-width:1100px;}
thead tr.header-labels td{background:#1e40af;color:#fff;font-weight:700;padding:9px 10px;white-space:nowrap;position:sticky;top:0;z-index:3;}
thead tr.header-filters td{background:#1a3574;padding:4px 6px;position:sticky;top:37px;z-index:2;}
thead tr.header-filters td select{width:100%;padding:3px 5px;font-size:11px;border:1px solid #3b82f6;border-radius:4px;background:#1e3a8a;color:#fff;outline:none;}
thead tr.header-filters td select option{background:#1e3a8a;color:#fff;}
tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s;}
tbody tr:hover{background:#f0f7ff;}
tbody td{padding:7px 10px;color:#0f172a;white-space:nowrap;}
tbody tr:last-child{border-bottom:none;}
tbody tr.hidden-row{display:none;}

.total-bar{display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:8px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0 0 10px 10px;border-top:none;font-size:13px;font-weight:700;color:#1e3a8a;}
.total-bar span{color:#16a34a;font-size:15px;}
.empty-state{text-align:center;padding:30px;color:#94a3b8;font-size:13px;}
.separador{border:none;border-top:2px solid #e5e7eb;margin:28px 0;}

/* ══ PLACEHOLDER ══ */
.placeholder{text-align:center;padding:60px 20px;color:#94a3b8;}
.placeholder p{font-size:14px;margin-top:14px;}

/* ══ BADGE ══ */
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;}
.badge-blue{background:#dbeafe;color:#1d4ed8;}
.badge-green{background:#dcfce7;color:#166534;}

/* ══ PRINT TOGGLE CHECKBOX ══ */
.print-toggle-label{display:inline-flex;align-items:center;gap:5px;margin-left:12px;font-size:11px;font-weight:600;color:#6b7280;cursor:pointer;vertical-align:middle;}
.print-toggle-label input{cursor:pointer;width:14px;height:14px;}

/* ══ PRINT ══ */
@media print{
    @page{size:landscape;margin:12mm;}
    body{background:#fff !important;display:block;}
    .sidebar,.menu-toggle{display:none !important;}
    .main{padding:10px;}
    .card{box-shadow:none;border-radius:0;padding:12px;}
    .btn-group,.filtro-bar{display:none !important;}
    .kpi{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .page-header{border-bottom:2px solid #000;}
    .page-header h1{color:#000 !important;}
    .secao-titulo{color:#000;}
    thead tr.header-filters{display:none !important;}
    table{min-width:auto !important;font-size:9px;}
    .tabela-wrap{max-height:none !important;overflow:visible !important;border:none;}
    #tbl-saidas td,#tbl-entradas td{display:none;}
    #tbl-saidas td:nth-child(2),
    #tbl-saidas td:nth-child(3),
    #tbl-saidas td:nth-child(4),
    #tbl-saidas td:nth-child(6),
    #tbl-saidas td:nth-child(13),
    #tbl-entradas td:nth-child(2),
    #tbl-entradas td:nth-child(3),
    #tbl-entradas td:nth-child(4),
    #tbl-entradas td:nth-child(6),
    #tbl-entradas td:nth-child(13){display:table-cell;}
    .secao.no-print{display:none !important;}
    .print-toggle-label{display:none !important;}
    .total-bar{display:none !important;}
}

/* ══ HAMBURGUER MOBILE ══ */
.menu-toggle{display:none;position:fixed;top:12px;left:12px;z-index:1100;background:#1e40af;border:none;border-radius:8px;padding:8px 10px;cursor:pointer;color:#fff;font-size:18px;}
@media(max-width:768px){
    .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:1000;transform:translateX(-100%);transition:transform .25s;overflow-y:auto;}
    .sidebar.open{transform:translateX(0);}
    .menu-toggle{display:block;}
    .main{padding:16px;padding-top:56px;}
}
</style>
</head>
<body>

<button class="menu-toggle" id="menuToggle">☰</button>

<!-- ══ SIDEBAR ══ -->
<div class="sidebar" id="sidebar">
    <div class="logo">
        <img src="logo_2.png" alt="PatAsset">
    </div>

    <div class="sec-label">Navegação</div>
    <a href="inicial.php"              class="sb-btn voltar">← Voltar</a>

    <div class="sec-label">Relatórios</div>
    <a href="relatorio.php"            class="sb-btn active">Relatório de Movimentações</a>
    <a href="rel_conciliacao.php"      class="sb-btn secondary">Relatório de Conciliação</a>

    <div class="sec-label">Dashboards</div>
    <a href="indicadores_dashboard.php" class="sb-btn secondary">Dashboard Indicadores</a>
    <a href="dash.php"                  class="sb-btn secondary">Dashboard Patrimônio</a>

    <div class="sec-label">Exportar</div>
    <button class="sb-btn" onclick="window.print()" style="background:#065f46;">🖨 Imprimir</button>
    <button class="sb-btn" onclick="exportarPDF()"  style="background:#1d4ed8;">⬇ Exportar PDF</button>
</div>

<!-- ══ MAIN ══ -->
<div class="main">
<div class="card">

    <div class="page-header">
        <h1>Relatório Dinâmico de Movimentações</h1>
        <div class="btn-group">
            <button class="btn btn-primary" onclick="window.print()">🖨 Imprimir</button>
            <button class="btn btn-success" onclick="exportarPDF()">⬇ Exportar PDF</button>
        </div>
    </div>

    <!-- FILTRO -->
    <form method="GET" action="relatorio.php">
        <div class="filtro-bar">
            <div>
                <label for="sel_unidade">Unidade</label>
                <select name="unidade" id="sel_unidade" onchange="this.form.submit()">
                    <option value="">— Selecione uma unidade —</option>
                    <?php foreach ($unidades as $u): ?>
                    <option value="<?= htmlspecialchars($u) ?>" <?= ($uni === $u) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u) ?>
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

    <!-- KPIs QUANTIDADE -->
    <div class="secao">
        <div class="secao-titulo">
            Área de Movimentações —
            <?= $filtrado ? htmlspecialchars($uni) : 'Todas as Unidades' ?>
        </div>
        <div class="kpi-grid">
            <div class="kpi blue">
                <div class="kl">Movimentações Definitivas</div>
                <div class="kv"><?= number_format($dados['qtd_definitivas'],0,',','.') ?></div>
                <div class="ks">movimentado_definitivo = SIM</div>
            </div>
            <div class="kpi red">
                <div class="kl"><?= $filtrado ? 'Saídas para outra unidade' : 'Transferências entre unidades' ?></div>
                <div class="kv"><?= number_format($dados['qtd_saidas_inter'],0,',','.') ?></div>
                <div class="ks"><?= $filtrado ? 'itens que deixaram esta unidade' : 'itens com unidade_destino diferente da origem' ?></div>
            </div>
            <div class="kpi amber">
                <div class="kl">Movimentações internas</div>
                <div class="kv"><?= number_format($dados['qtd_internas'],0,',','.') ?></div>
                <div class="ks">origem e destino na mesma unidade</div>
            </div>
            <?php if ($filtrado): ?>
            <div class="kpi green">
                <div class="kl">Itens vindos de outra unidade</div>
                <div class="kv"><?= number_format($dados['qtd_entradas'],0,',','.') ?></div>
                <div class="ks">unidade_destino = <?= htmlspecialchars($uni) ?></div>
            </div>
            <?php endif; ?>
            <div class="kpi slate">
                <div class="kl">Total de Movimentações</div>
                <div class="kv"><?= number_format($dados['qtd_total_mov'],0,',','.') ?></div>
                <div class="ks"><?= $filtrado ? 'saídas + internas + entradas' : 'transferências + internas' ?></div>
            </div>
        </div>
    </div>

    <!-- KPIs VALOR -->
    <div class="kpi-grid" style="margin-bottom:28px">
        <?php if ($filtrado): ?>
        <div class="kpi green">
            <div class="kl">Valor dos itens recebidos</div>
            <div class="kv" style="font-size:18px"><?= fmtBR($dados['total_val_entradas']) ?></div>
            <div class="ks"><?= count($dados['entradas']) ?> item(s) que entraram</div>
        </div>
        <?php endif; ?>
        <div class="kpi red">
            <div class="kl"><?= $filtrado ? 'Valor dos itens enviados' : 'Valor — Transferências entre unidades' ?></div>
            <div class="kv" style="font-size:18px"><?= fmtBR($dados['total_val_saidas']) ?></div>
            <div class="ks"><?= count($dados['saidas']) ?> item(s)</div>
        </div>
        <div class="kpi amber">
            <div class="kl">Valor — Movimentações internas</div>
            <div class="kv" style="font-size:18px"><?= fmtBR($dados['total_val_entradas']) ?></div>
            <div class="ks"><?= count($dados['entradas']) ?> item(s)</div>
        </div>
        <div class="kpi purple">
            <div class="kl">Valor total movimentado</div>
            <div class="kv" style="font-size:18px"><?= fmtBR($dados['total_val_entradas'] + $dados['total_val_saidas']) ?></div>
            <div class="ks"><?= $filtrado ? 'entradas + saídas' : 'transferências + internas' ?></div>
        </div>
    </div>

    <hr class="separador">

    <!-- TABELA SAÍDAS / TRANSFERÊNCIAS -->
    <div class="secao" id="secao-saidas">
        <div class="secao-titulo">
            <?= $filtrado ? 'Itens que saíram de ' . htmlspecialchars($uni) : 'Transferências entre unidades' ?>
            <span class="badge badge-blue" style="margin-left:8px" id="badge-saidas"><?= count($dados['saidas']) ?> itens</span>
            <label class="print-toggle-label"><input type="checkbox" id="chk-saidas" checked onchange="togglePrint('secao-saidas',this)"> Imprimir esta tabela</label>
        </div>
        <?php if (empty($dados['saidas'])): ?>
        <div class="empty-state"><?= $filtrado ? 'Nenhum item saiu desta unidade para outra.' : 'Nenhuma transferência entre unidades registrada.' ?></div>
        <?php else: ?>
        <div class="tabela-wrap">
            <table id="tbl-saidas">
                <thead>
                    <tr class="header-labels">
                        <td>#</td>
                        <td>UNIDADE DE ORIGEM</td>
                        <td>UNIDADE DE DESTINO</td>
                        <td>DESC. DETALHADA</td>
                        <td>DESCRIÇÃO</td>
                        <td>TAG PATRIMÔNIO</td>
                        <td>TAG NOVA COMPRA</td>
                        <td>MARCA</td>
                        <td>MODELO</td>
                        <td>Nº SÉRIE</td>
                        <td>SUBGRUPO</td>
                        <td>DATA MOVIMENTAÇÃO</td>
                        <td>VALOR ITEM</td>
                    </tr>
                    <tr class="header-filters">
                        <?php
                        $cols_saidas = ['unidade','unidade_destino','descricao_detalhada','descricao','tag_antiga','tag_trocada','marca','modelo','serie','subgrupo','data_movimentacao','valor_item'];
                        // coluna # sem filtro
                        echo '<td></td>';
                        foreach ($cols_saidas as $col):
                            $vals = array_unique(array_filter(array_column($dados['saidas'], $col)));
                            sort($vals);
                        ?>
                        <td>
                            <select onchange="filtrarTabela('tbl-saidas','badge-saidas')">
                                <option value="">Todos</option>
                                <?php foreach ($vals as $v): ?>
                                <option><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dados['saidas'] as $i => $row): ?>
                <tr>
                    <td style="color:#94a3b8;font-size:11px"><?= $i+1 ?></td>
                    <td><?= htmlspecialchars($row['unidade'] ?? '') ?></td>
                    <td><strong><?= htmlspecialchars($row['unidade_destino'] ?? '') ?></strong></td>
                    <td><?= htmlspecialchars($row['descricao_detalhada'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['descricao'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['tag_antiga'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['tag_trocada'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['marca'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['modelo'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['serie'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['subgrupo'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['data_movimentacao'] ?? '') ?></td>
                    <td style="text-align:right;font-weight:700;color:#16a34a">
                        <?= $row['valor_item'] ? htmlspecialchars($row['valor_item']) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="total-bar" id="total-saidas">
            Valor total dos itens saídos: <span><?= fmtBR($dados['total_val_saidas']) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <hr class="separador">

    <!-- TABELA ENTRADAS / INTERNAS -->
    <div class="secao" id="secao-entradas">
        <div class="secao-titulo">
            <?= $filtrado ? 'Itens que vieram para ' . htmlspecialchars($uni) : 'Movimentações internas por unidade' ?>
            <span class="badge badge-green" style="margin-left:8px" id="badge-entradas"><?= count($dados['entradas']) ?> itens</span>
            <label class="print-toggle-label"><input type="checkbox" id="chk-entradas" checked onchange="togglePrint('secao-entradas',this)"> Imprimir esta tabela</label>
        </div>
        <?php if (empty($dados['entradas'])): ?>
        <div class="empty-state"><?= $filtrado ? 'Nenhum item veio de outra unidade para esta.' : 'Nenhuma movimentação interna registrada.' ?></div>
        <?php else: ?>
        <div class="tabela-wrap">
            <table id="tbl-entradas">
                <thead>
                    <tr class="header-labels">
                        <td>#</td>
                        <td>UNIDADE DE ORIGEM</td>
                        <td>UNIDADE DE DESTINO</td>
                        <td>DESC. DETALHADA</td>
                        <td>DESCRIÇÃO</td>
                        <td>TAG PATRIMÔNIO</td>
                        <td>TAG NOVA COMPRA</td>
                        <td>MARCA</td>
                        <td>MODELO</td>
                        <td>Nº SÉRIE</td>
                        <td>SUBGRUPO</td>
                        <td>DATA MOVIMENTAÇÃO</td>
                        <td>VALOR ITEM</td>
                    </tr>
                    <tr class="header-filters">
                        <?php
                        $cols_entradas = ['unidade','unidade_destino','descricao_detalhada','descricao','tag_antiga','tag_trocada','marca','modelo','serie','subgrupo','data_movimentacao','valor_item'];
                        echo '<td></td>';
                        foreach ($cols_entradas as $col):
                            $vals = array_unique(array_filter(array_column($dados['entradas'], $col)));
                            sort($vals);
                        ?>
                        <td>
                            <select onchange="filtrarTabela('tbl-entradas','badge-entradas')">
                                <option value="">Todos</option>
                                <?php foreach ($vals as $v): ?>
                                <option><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dados['entradas'] as $i => $row): ?>
                <tr>
                    <td style="color:#94a3b8;font-size:11px"><?= $i+1 ?></td>
                    <td><strong><?= htmlspecialchars($row['unidade'] ?? '') ?></strong></td>
                    <td><?= htmlspecialchars($row['unidade_destino'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['descricao_detalhada'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['descricao'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['tag_antiga'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['tag_trocada'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['marca'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['modelo'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['serie'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['subgrupo'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['data_movimentacao'] ?? '') ?></td>
                    <td style="text-align:right;font-weight:700;color:#16a34a">
                        <?= $row['valor_item'] ? htmlspecialchars($row['valor_item']) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="total-bar" id="total-entradas">
            Valor total dos itens recebidos: <span><?= fmtBR($dados['total_val_entradas']) ?></span>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /card -->
</div><!-- /main -->

<script>
/* ── Filtro de colunas nas tabelas ── */
function filtrarTabela(tblId, badgeId) {
    const tbl      = document.getElementById(tblId);
    const selects  = tbl.querySelectorAll('thead tr.header-filters select');
    const rows     = tbl.querySelectorAll('tbody tr');
    let visiveis   = 0;

    rows.forEach(tr => {
        const tds = tr.querySelectorAll('td');
        /* tds[0] = #, tds[1..n] = dados (alinhado com selects[0..n-1]) */
        let mostrar = true;
        selects.forEach((sel, i) => {
            if (!sel.value) return;
            const tdIdx = i + 1; // pula coluna #
            const celVal = (tds[tdIdx]?.textContent ?? '').trim();
            if (celVal !== sel.value) mostrar = false;
        });
        tr.classList.toggle('hidden-row', !mostrar);
        if (mostrar) visiveis++;
    });

    /* atualiza badge */
    const badge = document.getElementById(badgeId);
    if (badge) badge.textContent = visiveis + ' itens';
}

/* ── Toggle impressão por tabela ── */
function togglePrint(secaoId, chk) {
    document.getElementById(secaoId).classList.toggle('no-print', !chk.checked);
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