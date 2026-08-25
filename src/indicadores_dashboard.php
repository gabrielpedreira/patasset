<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) { header("Location: index.html"); exit(); }
require_once 'check_session.php';
require_once "conexao.php";

/*
 * Este dashboard é linkado apenas de relatorio.php e rel_conciliacao.php, que
 * são exclusivos do nível A — mas a página em si só conferia login, então
 * qualquer usuário logado a abria pela URL direta. Agora exige A + classe do
 * patrimônio, igual às telas que dão acesso a ela. json=false: redireciona.
 */
seg_exigir_permissao($conn, ['A'], ['DEV', 'PATRIMONIO'], false);

$resUni = $conn->query("SELECT DISTINCT TRIM(unidade) AS u FROM cadastro WHERE unidade IS NOT NULL AND TRIM(unidade)<>'' ORDER BY u ASC");
$unidades = [];
while ($r = $resUni->fetch_assoc()) $unidades[] = $r['u'];
$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Indicadores — Gráficos</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
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
.page-header h1{color:#fff;font-size:1.4rem;font-weight:800;letter-spacing:-.3px;}
.btn-group{display:flex;gap:8px;flex-wrap:wrap;}
.btn{padding:8px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;transition:.18s;display:inline-flex;align-items:center;gap:6px;letter-spacing:.1px;}
.btn-primary{background:#2563eb;color:#fff;}.btn-primary:hover{background:#1e40af;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-amber{background:#d97706;color:#fff;}.btn-amber:hover{background:#b45309;}
.btn-slate{background:#475569;color:#fff;}.btn-slate:hover{background:#334155;}
.btn-generate{width:100%;padding:13px;font-size:14px;border-radius:10px;justify-content:center;margin-top:4px;}

/* ══ CARD ══ */
.card{background:#fff;padding:24px 28px;border-radius:14px;box-shadow:0 12px 36px rgba(0,0,0,.25);margin-bottom:20px;}
.card-title{font-size:11px;font-weight:800;color:#1e3a8a;text-transform:uppercase;letter-spacing:.8px;margin-bottom:16px;padding-left:10px;border-left:3px solid #2563eb;}

/* ══ FONTE + FILTROS ══ */
.top-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:22px;}
.field-group{display:flex;flex-direction:column;gap:5px;}
.field-group label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.6px;}
.field-group select,.field-group input[type=text]{padding:9px 11px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;background:#fff;width:100%;color:#111827;}
.field-group select:focus,.field-group input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}

/* ══ TIPO GRÁFICO ══ */
.section-label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;}
.tipo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;margin-bottom:22px;}
.tipo-btn{border:1.5px solid #e2e8f0;border-radius:10px;background:#f8fafc;cursor:pointer;text-align:left;transition:.18s;padding:12px 14px;display:flex;flex-direction:column;gap:6px;}
.tipo-btn:hover{border-color:#93c3fd;background:#eff6ff;}
.tipo-btn.active{border-color:#2563eb;background:#eff6ff;}
.tipo-btn .t-icon{color:#2563eb;display:block;}
.tipo-btn.active .t-icon{color:#1d4ed8;}
.tipo-btn .t-nome{font-size:12px;font-weight:700;color:#1e3a8a;}
.tipo-btn .t-desc{font-size:10px;color:#64748b;line-height:1.45;margin-top:1px;}

/* ══ CHECKBOXES DIM / METRICA ══ */
.check-section{margin-bottom:22px;}
.check-grid{display:flex;flex-wrap:wrap;gap:8px;}
.chk-label{display:inline-flex;align-items:center;gap:7px;padding:7px 13px;border:1.5px solid #e2e8f0;border-radius:8px;background:#f8fafc;cursor:pointer;font-size:12px;font-weight:600;color:#374151;transition:.18s;user-select:none;}
.chk-label:hover{border-color:#93c3fd;background:#eff6ff;color:#1e40af;}
.chk-label input[type=checkbox]{width:14px;height:14px;accent-color:#2563eb;cursor:pointer;}
.chk-label.checked{border-color:#2563eb;background:#dbeafe;color:#1e40af;}

/* ══ GUIA ══ */
.guia-toggle{background:none;border:none;cursor:pointer;font-size:12px;font-weight:700;color:#2563eb;display:inline-flex;align-items:center;gap:5px;padding:0;margin-bottom:0;}
.guia-body{display:none;margin-top:14px;}
.guia-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;}
.guia-card{border-radius:10px;padding:12px 14px;border:1px solid;cursor:pointer;transition:.18s;}
.guia-card:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(0,0,0,.1);}
.guia-card.g-blue  {background:#eff6ff;border-color:#bfdbfe;color:#1e40af;}
.guia-card.g-green {background:#f0fdf4;border-color:#bbf7d0;color:#15803d;}
.guia-card.g-amber {background:#fffbeb;border-color:#fde68a;color:#92400e;}
.guia-card.g-purple{background:#faf5ff;border-color:#e9d5ff;color:#6b21a8;}
.guia-card.g-red   {background:#fef2f2;border-color:#fecaca;color:#991b1b;}
.guia-card .g-titulo{font-size:12px;font-weight:800;margin-bottom:4px;}
.guia-card .g-desc{font-size:11px;line-height:1.5;opacity:.85;}
.guia-card .g-config{font-size:10px;font-weight:700;margin-top:8px;padding-top:7px;border-top:1px dashed currentColor;opacity:.65;}

/* ══ CHART AREA ══ */
/* ══ Cards Localizado × Nota Fiscal ══ */
.loc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;}
.loc-card{border-radius:12px;padding:14px 16px;color:#fff;}
.loc-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;opacity:.9;line-height:1.4;min-height:26px;}
.loc-qtd{font-size:24px;font-weight:800;line-height:1.15;margin-top:6px;}
.loc-val{font-size:11px;opacity:.8;margin-top:2px;}
.loc-green {background:linear-gradient(135deg,#065f46,#10b981);}
.loc-teal  {background:linear-gradient(135deg,#115e59,#14b8a6);}
.loc-blue  {background:linear-gradient(135deg,#1d4ed8,#3b82f6);}
.loc-red   {background:linear-gradient(135deg,#7f1d1d,#ef4444);}
.loc-amber {background:linear-gradient(135deg,#78350f,#f59e0b);}
.loc-slate {background:linear-gradient(135deg,#334155,#64748b);}

#chart-area{background:#fff;border-radius:14px;padding:28px;box-shadow:0 12px 36px rgba(0,0,0,.25);}
#chart-title{font-size:15px;font-weight:800;color:#0f172a;margin-bottom:3px;}
#chart-subtitle{font-size:11px;color:#94a3b8;margin-bottom:20px;}
.chart-canvas-wrap{position:relative;}
.chart-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:20px;padding-top:16px;border-top:1px solid #f1f5f9;}

/* ══ EMPTY / LOADING ══ */
#empty-state{text-align:center;padding:64px 20px;color:#94a3b8;}
#empty-state svg{display:block;margin:0 auto 14px;opacity:.35;}
#empty-state p{font-size:13px;line-height:1.6;}
#loading{display:none;text-align:center;padding:48px;color:#64748b;font-size:13px;}
.spinner{width:32px;height:32px;border:3px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 12px;}
@keyframes spin{to{transform:rotate(360deg)}}
#chart-output{display:none;}

/* ══ PRINT ══ */
@media print{
    @page{size:landscape;margin:10mm;}
    body{background:#fff !important;display:block;}
    .sidebar,.menu-toggle,.page-header,.card:first-of-type,.chart-actions,.guia-toggle{display:none !important;}
    #chart-area{box-shadow:none;padding:8px;}
    .main{padding:0;}
}

/* ══ MOBILE ══ */
.menu-toggle{display:none;position:fixed;top:12px;left:12px;z-index:1100;background:#1e40af;border:none;border-radius:8px;padding:8px 10px;cursor:pointer;color:#fff;font-size:18px;}
@media(max-width:768px){
    .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:1000;transform:translateX(-100%);transition:transform .25s;overflow-y:auto;}
    .sidebar.open{transform:translateX(0);}
    .menu-toggle{display:block;}
    .main{padding:16px;padding-top:56px;}
    .tipo-grid{grid-template-columns:repeat(auto-fill,minmax(110px,1fr));}
}
</style>
</head>
<body>

<button class="menu-toggle" id="menuToggle">&#9776;</button>

<!-- ══ SIDEBAR ══ -->
<div class="sidebar" id="sidebar">
    <div class="logo"><img src="logo_2.png" alt="PatAsset"></div>
    <div class="sec-label">Navegação</div>
    <a href="inicial.php" class="sb-btn voltar">&#8592; Voltar</a>
    <div class="sec-label">Relatórios</div>
    <a href="relatorio.php"       class="sb-btn secondary">Movimentações</a>
    <a href="rel_conciliacao.php" class="sb-btn secondary">Conciliação</a>
    <div class="sec-label">Dashboards</div>
    <a href="indicadores_dashboard.php" class="sb-btn active">Indicadores</a>
    <a href="dash.php"                  class="sb-btn secondary">Patrimônio</a>
</div>

<!-- ══ MAIN ══ -->
<div class="main">

    <div class="page-header">
        <h1>Gerador de Gráficos</h1>
        <div class="btn-group">
            <button class="btn btn-slate"   onclick="imprimirGrafico()">Imprimir</button>
            <button class="btn btn-success" onclick="salvarPNG()">Salvar PNG</button>
            <button class="btn btn-amber"   onclick="salvarPDF()">Salvar PDF</button>
        </div>
    </div>

    <!-- ══ INDICADORES: LOCALIZADO × NOTA FISCAL ══ -->
    <div class="card">
        <div class="card-title" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span style="flex:1">Localizados × Nota Fiscal</span>
            <select id="loc-unidade" onchange="carregarLocalizacao()"
                    style="font-weight:400;text-transform:none;letter-spacing:0;font-size:12px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;color:#0f172a;background:#fff">
                <option value="">Todas as unidades</option>
                <?php foreach ($unidades as $u): ?>
                <option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" style="padding:7px 14px;font-size:12px" onclick="gerarGraficoLocalizacao()">
                Gerar gráfico
            </button>
        </div>

        <div class="loc-grid" id="loc-grid">
            <div class="loc-card loc-green">
                <div class="loc-lbl">Localizados com nota fiscal</div>
                <div class="loc-qtd" id="loc-1-q">—</div>
                <div class="loc-val" id="loc-1-v">—</div>
            </div>
            <div class="loc-card loc-teal">
                <div class="loc-lbl">Localizados sem nota fiscal</div>
                <div class="loc-qtd" id="loc-2-q">—</div>
                <div class="loc-val" id="loc-2-v">—</div>
            </div>
            <div class="loc-card loc-blue">
                <div class="loc-lbl">Total localizados</div>
                <div class="loc-qtd" id="loc-3-q">—</div>
                <div class="loc-val" id="loc-3-v">—</div>
            </div>
            <div class="loc-card loc-red">
                <div class="loc-lbl">Não localizados com nota fiscal</div>
                <div class="loc-qtd" id="loc-4-q">—</div>
                <div class="loc-val" id="loc-4-v">—</div>
            </div>
            <div class="loc-card loc-amber">
                <div class="loc-lbl">Não localizados sem nota fiscal</div>
                <div class="loc-qtd" id="loc-5-q">—</div>
                <div class="loc-val" id="loc-5-v">—</div>
            </div>
            <div class="loc-card loc-slate">
                <div class="loc-lbl">Total não localizados</div>
                <div class="loc-qtd" id="loc-6-q">—</div>
                <div class="loc-val" id="loc-6-v">—</div>
            </div>
        </div>
        <div style="font-size:11px;color:#94a3b8;margin-top:10px;line-height:1.6">
            “Com nota fiscal” = campo NOTA FISCAL preenchido. A coluna LOCALIZADO aceita
            todas as grafias registradas ao longo do tempo (sim, SIM, Sim, não, nao, NÃO, Nao).
            Itens com LOCALIZADO em branco não entram em nenhum dos dois grupos.
        </div>
    </div>

    <!-- ══ BUILDER ══ -->
    <div class="card">
        <div class="card-title">Configurar Gráfico</div>

        <!-- Fonte + filtros -->
        <div class="top-filters">
            <div class="field-group">
                <label>Fonte de dados</label>
                <select id="sel-fonte">
                    <option value="conciliados">Cadastro Geral</option>
                    <option value="movimentacoes">Movimentações</option>
                    <option value="ambos">Ambos (comparativo)</option>
                </select>
            </div>
            <div class="field-group">
                <label>Filtrar por unidade (opcional)</label>
                <select id="sel-unidade">
                    <option value="">Todas as unidades</option>
                    <?php foreach ($unidades as $u): ?>
                    <option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field-group">
                <label>Limite de resultados</label>
                <select id="sel-limite">
                    <option value="10">Top 10</option>
                    <option value="15">Top 15</option>
                    <option value="20" selected>Top 20</option>
                    <option value="30">Top 30</option>
                    <option value="50">Top 50</option>
                </select>
            </div>
            <div class="field-group">
                <label>Título personalizado (opcional)</label>
                <input type="text" id="inp-titulo" placeholder="Ex: Conciliados por Unidade">
            </div>
        </div>

        <!-- Tipo de gráfico -->
        <div class="section-label">Tipo de Gráfico</div>
        <div class="tipo-grid" id="tipo-grid">

            <button class="tipo-btn active" data-tipo="bar" onclick="selecionarTipo(this)">
                <svg class="t-icon" width="28" height="20" viewBox="0 0 28 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="1" y="8" width="5" height="11" rx="1" fill="currentColor" opacity=".5"/>
                    <rect x="8" y="4" width="5" height="15" rx="1" fill="currentColor" opacity=".75"/>
                    <rect x="15" y="1" width="5" height="18" rx="1" fill="currentColor"/>
                    <rect x="22" y="6" width="5" height="13" rx="1" fill="currentColor" opacity=".65"/>
                    <line x1="0" y1="19.5" x2="28" y2="19.5" stroke="currentColor" stroke-width="1.2"/>
                </svg>
                <span class="t-nome">Colunas</span>
                <span class="t-desc">Comparar valores entre diferentes categorias ou períodos.</span>
            </button>

            <button class="tipo-btn" data-tipo="horizontalBar" onclick="selecionarTipo(this)">
                <svg class="t-icon" width="28" height="20" viewBox="0 0 28 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="0" y="1"  width="20" height="4" rx="1" fill="currentColor"/>
                    <rect x="0" y="7"  width="26" height="4" rx="1" fill="currentColor" opacity=".75"/>
                    <rect x="0" y="13" width="14" height="4" rx="1" fill="currentColor" opacity=".5"/>
                    <line x1=".5" y1="0" x2=".5" y2="20" stroke="currentColor" stroke-width="1.2"/>
                </svg>
                <span class="t-nome">Barras</span>
                <span class="t-desc">Comparar categorias, especialmente com muitos itens ou nomes longos.</span>
            </button>

            <button class="tipo-btn" data-tipo="line" onclick="selecionarTipo(this)">
                <svg class="t-icon" width="28" height="20" viewBox="0 0 28 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <polyline points="1,16 7,9 13,12 19,4 27,7" stroke="currentColor" stroke-width="2" fill="none" stroke-linejoin="round" stroke-linecap="round"/>
                    <circle cx="1"  cy="16" r="1.8" fill="currentColor"/>
                    <circle cx="7"  cy="9"  r="1.8" fill="currentColor"/>
                    <circle cx="13" cy="12" r="1.8" fill="currentColor"/>
                    <circle cx="19" cy="4"  r="1.8" fill="currentColor"/>
                    <circle cx="27" cy="7"  r="1.8" fill="currentColor"/>
                    <line x1="0" y1="19.5" x2="28" y2="19.5" stroke="currentColor" stroke-width="1" opacity=".4"/>
                </svg>
                <span class="t-nome">Linhas</span>
                <span class="t-desc">Mostrar tendências, evolução ou variações ao longo do tempo.</span>
            </button>

            <button class="tipo-btn" data-tipo="area" onclick="selecionarTipo(this)">
                <svg class="t-icon" width="28" height="20" viewBox="0 0 28 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <polyline points="1,16 7,9 13,12 19,4 27,7" stroke="currentColor" stroke-width="2" fill="none" stroke-linejoin="round" stroke-linecap="round"/>
                    <polygon points="1,16 7,9 13,12 19,4 27,7 27,19 1,19" fill="currentColor" opacity=".2"/>
                    <line x1="0" y1="19.5" x2="28" y2="19.5" stroke="currentColor" stroke-width="1" opacity=".4"/>
                </svg>
                <span class="t-nome">Área</span>
                <span class="t-desc">Evolução ao longo do tempo, destacando também o volume acumulado.</span>
            </button>

            <button class="tipo-btn" data-tipo="pie" onclick="selecionarTipo(this)">
                <svg class="t-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5" fill="currentColor" opacity=".12"/>
                    <path d="M12 12 L12 2 A10 10 0 0 1 22 12 Z" fill="currentColor" opacity=".85"/>
                    <path d="M12 12 L22 12 A10 10 0 0 1 5 20 Z" fill="currentColor" opacity=".55"/>
                    <path d="M12 12 L5 20 A10 10 0 0 1 12 2 Z" fill="currentColor" opacity=".3"/>
                </svg>
                <span class="t-nome">Pizza</span>
                <span class="t-desc">Exibir a participação percentual de cada categoria em relação ao total.</span>
            </button>

            <button class="tipo-btn" data-tipo="doughnut" onclick="selecionarTipo(this)">
                <svg class="t-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2 A10 10 0 0 1 22 12" stroke="currentColor" stroke-width="4.5" fill="none" stroke-linecap="butt" opacity=".85"/>
                    <path d="M22 12 A10 10 0 0 1 5 20" stroke="currentColor" stroke-width="4.5" fill="none" stroke-linecap="butt" opacity=".55"/>
                    <path d="M5 20 A10 10 0 0 1 12 2" stroke="currentColor" stroke-width="4.5" fill="none" stroke-linecap="butt" opacity=".3"/>
                </svg>
                <span class="t-nome">Rosca</span>
                <span class="t-desc">Semelhante à pizza, com espaço central para destacar um total ou dado adicional.</span>
            </button>

            <button class="tipo-btn" data-tipo="radar" onclick="selecionarTipo(this)">
                <svg class="t-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <polygon points="12,2 22,8.5 22,15.5 12,22 2,15.5 2,8.5" stroke="currentColor" stroke-width="1.2" fill="none" opacity=".3"/>
                    <polygon points="12,6 18,9.5 18,14.5 12,18 6,14.5 6,9.5" stroke="currentColor" stroke-width="1" fill="none" opacity=".2"/>
                    <polygon points="12,4 20,9 19,16 12,20 5,16 4,9" stroke="currentColor" stroke-width="2" fill="currentColor" opacity=".25"/>
                    <polyline points="12,4 20,9 19,16 12,20 5,16 4,9 12,4" stroke="currentColor" stroke-width="1.8" fill="none" opacity=".9"/>
                </svg>
                <span class="t-nome">Radar</span>
                <span class="t-desc">Comparar múltiplas características entre grupos em um único gráfico.</span>
            </button>

        </div>

        <!-- Dimensões (checkboxes) -->
        <div class="check-section">
            <div class="section-label">Dimensão — Eixo X / Fatias <span style="font-weight:400;color:#94a3b8">(selecione uma ou mais)</span></div>
            <div class="check-grid" id="grid-dimensoes">
                <label class="chk-label checked"><input type="checkbox" name="dimensao" value="unidade" checked onchange="syncChkStyle(this)"> Unidade</label>
                <label class="chk-label"><input type="checkbox" name="dimensao" value="ccu" onchange="syncChkStyle(this)"> Centro de Custo</label>
                <label class="chk-label"><input type="checkbox" name="dimensao" value="destino" onchange="syncChkStyle(this)"> Unidade Destino</label>
                <label class="chk-label"><input type="checkbox" name="dimensao" value="conciliado" onchange="syncChkStyle(this)"> Status Conciliação</label>
                <label class="chk-label"><input type="checkbox" name="dimensao" value="localizado" onchange="syncChkStyle(this)"> Localização</label>
                <label class="chk-label"><input type="checkbox" name="dimensao" value="status" onchange="syncChkStyle(this)"> Status do Item</label>
                <label class="chk-label"><input type="checkbox" name="dimensao" value="subgrupo" onchange="syncChkStyle(this)"> Subgrupo</label>
                <label class="chk-label"><input type="checkbox" name="dimensao" value="descricao" onchange="syncChkStyle(this)"> Tipo / Descrição</label>
                <label class="chk-label"><input type="checkbox" name="dimensao" value="mes" onchange="syncChkStyle(this)"> Mês (cronológico)</label>
            </div>
        </div>

        <!-- Métricas (checkboxes) -->
        <div class="check-section">
            <div class="section-label">Métrica — Eixo Y / Valores <span style="font-weight:400;color:#94a3b8">(selecione uma ou mais)</span></div>
            <div class="check-grid" id="grid-metricas">
                <label class="chk-label checked"><input type="checkbox" name="metrica" value="quantidade" checked onchange="syncChkStyle(this)"> Quantidade (itens)</label>
                <label class="chk-label"><input type="checkbox" name="metrica" value="valor" onchange="syncChkStyle(this)"> Valor R$ (soma)</label>
                <label class="chk-label"><input type="checkbox" name="metrica" value="porcentagem" onchange="syncChkStyle(this)"> Porcentagem (%)</label>
            </div>
        </div>

        <!-- Guia -->
        <div style="margin-bottom:18px">
            <button class="guia-toggle" onclick="toggleGuia()">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor" id="guia-arrow-svg"><polygon points="3,2 9,6 3,10"/></svg>
                Guia de criação de gráficos
            </button>
            <div class="guia-body" id="guia-body">
                <div class="guia-cards">
                    <div class="guia-card g-blue" onclick="aplicarGuia('bar',['unidade'],['quantidade'])">
                        <div class="g-titulo">Comparativo por Unidade</div>
                        <div class="g-desc">Quantos itens cada unidade possui. Ideal para comparar volume entre unidades.</div>
                        <div class="g-config">Colunas · Unidade · Quantidade</div>
                    </div>
                    <div class="guia-card g-green" onclick="aplicarGuia('pie',['conciliado'],['porcentagem'])">
                        <div class="g-titulo">Proporção de Conciliação</div>
                        <div class="g-desc">Visualize quanto do patrimônio está Conciliado, Pendente ou Não conciliado.</div>
                        <div class="g-config">Pizza · Status Conciliação · Porcentagem</div>
                    </div>
                    <div class="guia-card g-amber" onclick="aplicarGuia('bar',['unidade'],['valor'])">
                        <div class="g-titulo">Valor por Unidade</div>
                        <div class="g-desc">Quanto cada unidade representa em valor de patrimônio.</div>
                        <div class="g-config">Colunas · Unidade · Valor R$</div>
                    </div>
                    <div class="guia-card g-purple" onclick="aplicarGuia('line',['mes'],['quantidade'],'movimentacoes')">
                        <div class="g-titulo">Crescimento de Movimentações</div>
                        <div class="g-desc">Evolução das movimentações ao longo do tempo, mês a mês.</div>
                        <div class="g-config">Linhas · Mês · Quantidade · Movimentações</div>
                    </div>
                    <div class="guia-card g-red" onclick="aplicarGuia('horizontalBar',['ccu'],['quantidade'])">
                        <div class="g-titulo">Ranking por Centro de Custo</div>
                        <div class="g-desc">Quais centros de custo concentram mais itens.</div>
                        <div class="g-config">Barras · Centro de Custo · Quantidade</div>
                    </div>
                    <div class="guia-card g-blue" onclick="aplicarGuia('doughnut',['subgrupo'],['porcentagem'])">
                        <div class="g-titulo">Composição por Subgrupo</div>
                        <div class="g-desc">Distribuição percentual dos itens por tipo de patrimônio.</div>
                        <div class="g-config">Rosca · Subgrupo · Porcentagem</div>
                    </div>
                    <div class="guia-card g-green" onclick="aplicarGuia('bar',['unidade','destino'],['quantidade'])">
                        <div class="g-titulo">Unidade vs. Destino</div>
                        <div class="g-desc">Comparativo entre itens por unidade de origem e unidade de destino.</div>
                        <div class="g-config">Colunas · Unidade + Destino · Quantidade</div>
                    </div>
                    <div class="guia-card g-amber" onclick="aplicarGuia('bar',['unidade'],['quantidade','valor'])">
                        <div class="g-titulo">Quantidade e Valor por Unidade</div>
                        <div class="g-desc">Veja ao mesmo tempo volume de itens e valor financeiro por unidade.</div>
                        <div class="g-config">Colunas · Unidade · Quantidade + Valor</div>
                    </div>
                    <div class="guia-card g-purple" onclick="aplicarGuia('bar',['unidade'],['quantidade','valor'],'ambos')">
                        <div class="g-titulo">Cadastro vs. Movimentações</div>
                        <div class="g-desc">Comparativo lado a lado entre total cadastrado e itens movimentados por unidade.</div>
                        <div class="g-config">Colunas · Unidade · Ambas as fontes</div>
                    </div>
                    <div class="guia-card g-red" onclick="aplicarGuia('bar',['localizado'],['quantidade','valor'])">
                        <div class="g-titulo">Localizados vs. Não Localizados</div>
                        <div class="g-desc">Compara itens encontrados e não encontrados na auditoria, com a quantidade e o valor de cada grupo.</div>
                        <div class="g-config">Colunas · Localização · Quantidade + Valor</div>
                    </div>
                </div>
            </div>
        </div>

        <button class="btn btn-primary btn-generate" onclick="gerarGrafico()">Gerar Gráfico</button>
    </div>

    <!-- ══ CHART OUTPUT ══ -->
    <div id="chart-area">
        <div id="empty-state">
            <svg width="56" height="56" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="4"  y="28" width="10" height="20" rx="2" fill="#94a3b8"/>
                <rect x="18" y="18" width="10" height="30" rx="2" fill="#94a3b8"/>
                <rect x="32" y="8"  width="10" height="40" rx="2" fill="#94a3b8"/>
                <rect x="46" y="22" width="10" height="26" rx="2" fill="#94a3b8"/>
                <line x1="2" y1="49" x2="58" y2="49" stroke="#94a3b8" stroke-width="2"/>
            </svg>
            <p>Configure as opções acima e clique em <strong>Gerar Gráfico</strong>.</p>
        </div>
        <div id="loading"><div class="spinner"></div>Carregando dados...</div>
        <div id="chart-output">
            <div id="chart-title"></div>
            <div id="chart-subtitle"></div>
            <div class="chart-canvas-wrap"><canvas id="myChart"></canvas></div>
            <div class="chart-actions">
                <button class="btn btn-slate"   onclick="imprimirGrafico()">Imprimir</button>
                <button class="btn btn-success" onclick="salvarPNG()">Salvar PNG</button>
                <button class="btn btn-amber"   onclick="salvarPDF()">Salvar PDF</button>
            </div>
        </div>
    </div>

</div><!-- /main -->

<script>
/* ── Escape de HTML ──────────────────────────────────────────────────────────
   Converte caracteres que o navegador interpretaria como marcação em entidades.
   Sem isso, um valor gravado no banco contendo uma tag é EXECUTADO ao ser
   inserido com innerHTML — e o código roda na sessão de quem abriu a tela.
   Escapar um texto comum não altera nada; o efeito só aparece no que era
   marcação disfarçada de dado. */
function esc(v) {
  if (v === null || v === undefined) return '';
  return String(v)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/* ── Paleta de cores ── */
const CORES = [
    '#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4',
    '#f97316','#84cc16','#ec4899','#6366f1','#14b8a6','#eab308',
    '#0ea5e9','#a855f7','#22c55e','#fb923c','#64748b','#f43f5e',
];

let chartInstance = null;
let tipoAtivo = 'bar';

/* ── Tipo de gráfico ── */
function selecionarTipo(btn) {
    document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    tipoAtivo = btn.dataset.tipo;
}

/* ── Sync estilo checkbox ── */
function syncChkStyle(chk) {
    chk.closest('.chk-label').classList.toggle('checked', chk.checked);
}

/* ── Valores selecionados ── */
function getChecked(name) {
    const vals = [...document.querySelectorAll(`input[name="${name}"]:checked`)].map(c => c.value);
    return vals.length ? vals : [document.querySelector(`input[name="${name}"]`).value];
}

/* ── Guia ── */
function toggleGuia() {
    const body = document.getElementById('guia-body');
    const open = body.style.display === 'none' || body.style.display === '';
    body.style.display = open ? 'block' : 'none';
    const arrow = document.getElementById('guia-arrow-svg');
    arrow.innerHTML = open
        ? '<polygon points="2,3 10,3 6,10"/>'
        : '<polygon points="3,2 9,6 3,10"/>';
}

function aplicarGuia(tipo, dimensoes, metricas, fonte) {
    tipoAtivo = tipo;
    document.querySelectorAll('.tipo-btn').forEach(b => b.classList.toggle('active', b.dataset.tipo === tipo));
    document.querySelectorAll('input[name="dimensao"]').forEach(chk => {
        chk.checked = dimensoes.includes(chk.value);
        syncChkStyle(chk);
    });
    document.querySelectorAll('input[name="metrica"]').forEach(chk => {
        chk.checked = metricas.includes(chk.value);
        syncChkStyle(chk);
    });
    if (fonte) document.getElementById('sel-fonte').value = fonte;
}

/* ── Gerar ── */
function gerarGrafico() {
    const fonte    = document.getElementById('sel-fonte').value;
    const unidade  = document.getElementById('sel-unidade').value;
    const limite   = document.getElementById('sel-limite').value;
    const tituloCustom = document.getElementById('inp-titulo').value.trim();
    const dimensoes = getChecked('dimensao');
    const metricas  = getChecked('metrica');

    document.getElementById('empty-state').style.display = 'none';
    document.getElementById('loading').style.display = 'block';
    document.getElementById('chart-output').style.display = 'none';

    const fd = new FormData();
    fd.append('fonte',   fonte);
    fd.append('unidade', unidade);
    fd.append('limite',  limite);
    dimensoes.forEach(d => fd.append('dimensoes[]', d));
    metricas.forEach(m  => fd.append('metricas[]',  m));

    fetch('indicadores_dados.php', { method:'POST', body:fd, credentials:'same-origin' })
        .then(r => r.json())
        .then(data => {
            document.getElementById('loading').style.display = 'none';
            if (data.erro) { alert('Erro: ' + data.erro); return; }
            renderChart(data, fonte, dimensoes, metricas, tituloCustom);
        })
        .catch(e => {
            document.getElementById('loading').style.display = 'none';
            alert('Erro de comunicação: ' + e.message);
        });
}

/* ═══════════════════════════════════════════
   LOCALIZADO × NOTA FISCAL
   ═══════════════════════════════════════════ */
function fmtBRL(v) {
    return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtNum(v) {
    return Number(v || 0).toLocaleString('pt-BR');
}

let _locCache = null;

function buscarLocalizacao() {
    const fd = new FormData();
    fd.append('acao', 'localizacao');
    fd.append('unidade', document.getElementById('loc-unidade').value);
    return fetch('indicadores_dados.php', { method:'POST', body:fd, credentials:'same-origin' })
        .then(r => r.json());
}

function carregarLocalizacao() {
    buscarLocalizacao().then(d => {
        if (!d || !d.kpis) return;
        _locCache = d;
        const ordem = ['loc_com_nf','loc_sem_nf','loc_total','nloc_com_nf','nloc_sem_nf','nloc_total'];
        ordem.forEach((chave, i) => {
            document.getElementById('loc-' + (i+1) + '-q').textContent = fmtNum(d.kpis[chave].qtd);
            document.getElementById('loc-' + (i+1) + '-v').textContent = fmtBRL(d.kpis[chave].valor);
        });
    }).catch(() => {});
}

function gerarGraficoLocalizacao() {
    const uni = document.getElementById('loc-unidade').value;

    document.getElementById('empty-state').style.display = 'none';
    document.getElementById('loading').style.display = 'block';
    document.getElementById('chart-output').style.display = 'none';

    buscarLocalizacao().then(d => {
        document.getElementById('loading').style.display = 'none';
        if (!d || !d.labels) { alert('Não foi possível carregar os indicadores.'); return; }
        _locCache = d;
        renderChart(
            { labels: d.labels, datasets: d.datasets },
            'conciliados', ['localizacao'], ['quantidade','valor'],
            'Localizados × Nota Fiscal' + (uni ? ' — ' + uni : '')
        );
        document.getElementById('chart-area').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }).catch(e => {
        document.getElementById('loading').style.display = 'none';
        alert('Erro de comunicação: ' + e.message);
    });
}

document.addEventListener('DOMContentLoaded', carregarLocalizacao);

/* ── Labels legíveis ── */
const DIM_LABEL ={unidade:'Unidade',ccu:'Centro de Custo',destino:'Unidade Destino',conciliado:'Status Conciliação',status:'Status',subgrupo:'Subgrupo',descricao:'Tipo',mes:'Mês',localizado:'Localização',localizacao:'Localizado × Nota Fiscal'};
const MET_LABEL = {quantidade:'Quantidade',valor:'Valor R$',porcentagem:'Porcentagem'};
const FONTE_LABEL = {conciliados:'Cadastro Geral',movimentacoes:'Movimentações',ambos:'Comparativo'};

/* ── Renderizar ── */
function renderChart(data, fonte, dimensoes, metricas, tituloCustom) {
    if (chartInstance) { chartInstance.destroy(); chartInstance = null; }

    const { labels, datasets } = data;
    const isPie    = ['pie','doughnut'].includes(tipoAtivo);
    const isRadar  = tipoAtivo === 'radar';
    const isArea   = tipoAtivo === 'area';
    const isHBar   = tipoAtivo === 'horizontalBar';
    const chartType = isHBar ? 'bar' : (isArea ? 'line' : tipoAtivo);

    const chartDatasets = datasets.map((ds, di) => {
        const cor = CORES[di % CORES.length];
        if (isPie || isRadar) {
            return {
                label: ds.label,
                data: ds.data,
                backgroundColor: labels.map((_, i) => CORES[i % CORES.length] + 'cc'),
                borderColor: labels.map((_, i) => CORES[i % CORES.length]),
                borderWidth: 1.5,
            };
        }
        return {
            label: ds.label,
            data: ds.data,
            backgroundColor: cor + (isArea ? '2e' : 'cc'),
            borderColor: cor,
            borderWidth: 2,
            fill: isArea,
            tension: (isArea || chartType === 'line') ? 0.35 : undefined,
            pointRadius: (chartType === 'line' || isArea) ? 4 : undefined,
        };
    });

    /* Detecta métrica dominante para formatação do eixo */
    const metricaPrimaria = metricas[0] ?? 'quantidade';

    const opts = {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: isPie ? 'right' : 'top', labels:{ font:{size:12}, padding:16 } },
            tooltip: {
                callbacks: {
                    label: ctx => {
                        const v = ctx.parsed?.y ?? ctx.parsed ?? 0;
                        const dsLabel = ctx.dataset.label ?? '';
                        const isVal  = dsLabel.includes('Valor') || metricaPrimaria === 'valor';
                        const isPct  = dsLabel.includes('Porcentagem') || metricaPrimaria === 'porcentagem';
                        if (isVal)  return ` ${dsLabel}: R$ ${Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2})}`;
                        if (isPct)  return ` ${dsLabel}: ${v}%`;
                        return ` ${dsLabel}: ${Number(v).toLocaleString('pt-BR')} itens`;
                    }
                }
            }
        },
        scales: (isPie || isRadar) ? {} : {
            x: { ticks:{font:{size:11}}, grid:{color:'#f8fafc'} },
            y: {
                beginAtZero: true,
                ticks: {
                    font:{size:11},
                    callback: v => metricaPrimaria === 'valor'
                        ? 'R$ ' + Number(v).toLocaleString('pt-BR',{maximumFractionDigits:0})
                        : (metricaPrimaria === 'porcentagem' ? v+'%' : Number(v).toLocaleString('pt-BR'))
                },
                grid:{color:'#f1f5f9'}
            }
        }
    };

    if (isHBar) {
        opts.indexAxis = 'y';
        opts.scales = {
            x:{ beginAtZero:true, ticks:{font:{size:11}}, grid:{color:'#f1f5f9'} },
            y:{ ticks:{font:{size:10}} }
        };
    }

    const dimNomes = dimensoes.map(d => DIM_LABEL[d] ?? d).join(' + ');
    const metNomes = metricas.map(m => MET_LABEL[m] ?? m).join(' + ');
    const titulo = tituloCustom || `${FONTE_LABEL[fonte] ?? fonte} — ${dimNomes}`;
    document.getElementById('chart-title').textContent = titulo;
    document.getElementById('chart-subtitle').textContent = `Métrica: ${metNomes} · ${esc(labels.length)} categoria(s) · ${esc(datasets.length)} série(s)`;
    document.getElementById('chart-output').style.display = 'block';

    chartInstance = new Chart(document.getElementById('myChart'), {
        type: chartType,
        data: { labels, datasets: chartDatasets },
        options: opts,
    });
}

/* ── Ações ── */
function imprimirGrafico() {
    if (!chartInstance) { alert('Gere um gráfico primeiro.'); return; }
    window.print();
}

function salvarPNG() {
    if (!chartInstance) { alert('Gere um gráfico primeiro.'); return; }
    const canvas = document.getElementById('myChart');
    const tmp = document.createElement('canvas');
    tmp.width = canvas.width; tmp.height = canvas.height;
    const ctx = tmp.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, tmp.width, tmp.height);
    ctx.drawImage(canvas, 0, 0);
    const a = document.createElement('a');
    a.href = tmp.toDataURL('image/png');
    a.download = (document.getElementById('chart-title').textContent || 'grafico').replace(/[^a-zA-Z0-9]/g,'_') + '.png';
    a.click();
}

function salvarPDF() {
    if (!chartInstance) { alert('Gere um gráfico primeiro.'); return; }
    const modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center';
    modal.innerHTML = `<div style="background:#fff;border-radius:14px;padding:28px 32px;max-width:400px;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.35)">
        <p style="font-size:15px;font-weight:700;color:#111827;margin-bottom:8px">Salvar como PDF</p>
        <p style="font-size:13px;color:#475569;margin-bottom:20px">Na janela de impressão, selecione <strong>"Salvar como PDF"</strong> como destino.</p>
        <button onclick="this.closest('div').parentElement.remove();window.print()"
            style="background:#2563eb;color:#fff;border:none;border-radius:8px;padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;margin-right:8px">Continuar</button>
        <button onclick="this.closest('div').parentElement.remove()"
            style="background:#e5e7eb;color:#111;border:none;border-radius:8px;padding:10px 16px;font-size:13px;font-weight:700;cursor:pointer">Cancelar</button>
    </div>`;
    document.body.appendChild(modal);
}

/* ── Sidebar mobile ── */
document.getElementById('menuToggle').onclick = () => {
    document.getElementById('sidebar').classList.toggle('open');
};
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
