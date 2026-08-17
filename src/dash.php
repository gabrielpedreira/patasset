<?php
// Erros não são exibidos ao usuário em produção: a mensagem do PHP revela
// caminho do servidor, nome de tabela e trecho de SQL. Continuam sendo
// registrados e ficam visíveis no painel DEV → Erros (ver dev_captura.php).
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();
if(!isset($_SESSION['usuario_logado'])){ header("Location: index.html"); exit(); }
require_once "conexao.php";
require_once 'check_session.php';

$usuario_logado = $_SESSION['usuario_logado'] ?? '';
if (!$usuario_logado) { header("Location: login.php"); exit(); }

$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $usuario_logado);
$stmt->execute();
$res = $stmt->get_result();
$permicao       = '';
$classe_usuario = '';
$status         = 'ATIVO';
if ($row = $res->fetch_assoc()) {
    $permicao       = $row['permicao'];
    $classe_usuario = $row['classe_usuario'];
    $status         = $row['status'] ?? 'ATIVO';
}
$stmt->close();

if ($status !== 'ATIVO') {
    session_destroy();
    header("Location: index.html?erro=bloqueado");
    exit();
}

if (!in_array($permicao, ['A']) || !in_array($classe_usuario, ['DEV','PATRIMONIO'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Patrimônio</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:linear-gradient(135deg,#0f172a,#1e3a8a);display:flex;min-height:100vh;align-items:flex-start}

/* ── SIDEBAR ── */
.sidebar{width:215px;min-width:200px;background:#0f172a;color:#fff;display:flex;flex-direction:column;padding:18px 10px 28px;gap:4px;box-shadow:4px 0 20px rgba(0,0,0,.5);flex-shrink:0;transition:transform .25s;min-height:100vh;height:auto;overflow-y:visible}
.sidebar .logo{font-size:14px;font-weight:800;color:#fff;margin-bottom:10px;padding:0 4px;letter-spacing:.5px}
.sidebar hr{border:none;border-top:1px solid #1e3a5a;margin:7px 0}
.sidebar .sec{font-size:10px;color:#64748b;padding:5px 4px 2px;text-transform:uppercase;letter-spacing:.8px}
.sidebar button{background:#1e40af;color:#fff;border:none;padding:7px 10px;border-radius:9px;font-weight:600;font-size:12px;cursor:pointer;text-align:left;transition:.18s;width:100%}
.sidebar button:hover{background:#3b82f6;transform:translateX(3px)}
.sidebar button.active{background:#2563eb;border-left:4px solid #60a5fa;padding-left:8px}
.sidebar button.export-btn{background:#065f46;color:#fff}
.sidebar button.export-btn:hover{background:#10b981}

/* ── HAMBURGUER ── */
.menu-toggle{display:none;position:fixed;top:12px;left:12px;z-index:1100;background:#1e40af;border:none;border-radius:8px;padding:8px 10px;cursor:pointer;color:#fff;font-size:20px;line-height:1;box-shadow:0 4px 12px rgba(0,0,0,.4)}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:900}
.sidebar-overlay.open{display:block}

/* ── MAIN ── */
.main{flex:1;padding:22px 26px;overflow-y:auto;min-width:0}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;gap:10px;flex-wrap:wrap}
.topbar h1{color:#fff;font-size:20px;font-weight:800}
.topbar a{background:#2563eb;color:#fff;padding:7px 16px;border-radius:8px;text-decoration:none;font-weight:700;font-size:12px;white-space:nowrap}
.topbar a:hover{background:#3b82f6}
.filter-badge{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#93c5fd;padding:6px 14px;border-radius:999px;font-size:12px;font-weight:700;margin-bottom:18px;display:inline-block;max-width:100%;word-break:break-word}

/* ── KPI ── */
.kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:8px}
.kpi-row2{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:20px;margin-top:10px}
.kpi{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:14px 12px;color:#fff;transition:.2s}
.kpi:hover{background:rgba(255,255,255,.13);transform:translateY(-2px)}
.kpi .kl{font-size:10px;color:#93c5fd;text-transform:uppercase;letter-spacing:.7px;margin-bottom:4px}
.kpi .kv{font-size:22px;font-weight:800}
.kpi .ks{font-size:10px;color:#cbd5e1;margin-top:2px}
.kpi.accent{background:linear-gradient(135deg,#1d4ed8,#3b82f6);border-color:#60a5fa}
.kpi.green{background:linear-gradient(135deg,#065f46,#10b981);border-color:#34d399}
.kpi.red{background:linear-gradient(135deg,#7f1d1d,#ef4444);border-color:#fca5a5}
.kpi.yellow{background:linear-gradient(135deg,#78350f,#f59e0b);border-color:#fcd34d}
.kpi.purple{background:linear-gradient(135deg,#4c1d95,#8b5cf6);border-color:#c4b5fd}
.kpi.teal{background:linear-gradient(135deg,#134e4a,#14b8a6);border-color:#5eead4}

/* ── SECTION TITLE / CARD ── */
.stitle{color:#fff;font-size:14px;font-weight:700;margin-bottom:10px;margin-top:4px;padding-left:6px;border-left:4px solid #3b82f6}
.card{background:#f1f5f9;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.18);padding:18px;margin-bottom:18px;transition:.2s}
.card:hover{transform:translateY(-2px);box-shadow:0 16px 36px rgba(0,0,0,.26)}
.card h2{color:#1e3a8a;font-size:14px;font-weight:700;margin-bottom:14px;text-align:center}

.g2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}

.blist{display:flex;flex-direction:column;gap:7px}
.brow{display:flex;flex-direction:column;gap:2px}
.blabel{display:flex;justify-content:space-between;font-size:11px;font-weight:600;color:#0f172a}
.btrack{height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden}
.bfill{height:100%;border-radius:99px;transition:.5s}

.tabs{display:flex;gap:7px;margin-bottom:12px;flex-wrap:wrap}
.tab{padding:6px 14px;border-radius:999px;border:2px solid transparent;font-weight:700;font-size:11px;cursor:pointer;transition:.18s;background:#e2e8f0;color:#334155}
.tab.active{background:#2563eb;color:#fff;border-color:#1d4ed8}

.chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:8px}
.chip{padding:6px 12px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1d4ed8}
.chip.g{background:#dcfce7;color:#15803d}
.chip.r{background:#fee2e2;color:#b91c1c}
.chip.y{background:#fef9c3;color:#854d0e}
.chip.p{background:#ede9fe;color:#6d28d9}

/* ── TABLE ── */
.ftable-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.ftable{width:100%;border-collapse:collapse;font-size:12px;margin-top:14px;min-width:400px}
.ftable th{background:#1e40af;color:#fff;padding:8px 10px;text-align:left;font-size:11px}
.ftable td{padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#0f172a}
.ftable tr:hover td{background:#e0f2fe}
.bmt{height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin-top:3px}
.bmf{height:100%;background:#3b82f6;border-radius:99px}

.desc-clickable{cursor:pointer;color:#1d4ed8;text-decoration:underline dotted;font-weight:700}
.desc-clickable:hover{color:#2563eb}
.drill-row td{background:#eff6ff !important;padding:0 !important}
.drill-inner{padding:10px 14px;display:flex;flex-wrap:wrap;gap:8px}
.drill-chip{background:#dbeafe;color:#1d4ed8;border-radius:8px;padding:5px 11px;font-size:11px;font-weight:600}
.drill-chip span{font-weight:800;margin-left:4px;color:#1e40af}
.drill-empty{padding:10px 14px;font-size:11px;color:#64748b;font-style:italic}

/* ── TOOLBAR TIPOS ── */
.tipos-toolbar{
    display:flex;align-items:center;gap:8px;flex-wrap:wrap;
    margin-bottom:4px;padding:10px 12px;
    background:#e8f0fe;border:1px solid #c7d9fb;border-radius:12px;
}
.tipos-search{
    flex:1;min-width:160px;padding:7px 12px;
    border:1px solid #93c5fd;border-radius:8px;
    font-size:13px;outline:none;background:#fff;color:#0f172a;
}
.tipos-search:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.tipos-search::placeholder{color:#94a3b8}
.sort-grupo{display:flex;gap:5px;flex-wrap:wrap}
.sort-btn{
    padding:6px 13px;border-radius:8px;
    border:1px solid #93c5fd;background:#fff;
    color:#1e40af;font-size:11px;font-weight:700;
    cursor:pointer;transition:.15s;white-space:nowrap;
}
.sort-btn.active{background:#2563eb;color:#fff;border-color:#1d4ed8}
.sort-btn:hover:not(.active){background:#dbeafe}
.tipos-count{font-size:11px;color:#475569;font-weight:600;white-space:nowrap;margin-left:auto}

.loader{display:none;text-align:center;color:#fff;padding:40px;font-size:15px}

/* ── RESPONSIVO ── */
@media(max-width:900px){.g2{grid-template-columns:1fr}}
@media(max-width:640px){
    .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:1000;transform:translateX(-100%);width:220px;overflow-y:auto}
    .sidebar.open{transform:translateX(0)}
    .menu-toggle{display:block}
    .main{padding:14px 12px;padding-top:56px}
    .topbar h1{font-size:16px}
    .kpi .kv{font-size:18px}
    .kpi-row{grid-template-columns:repeat(2,1fr);gap:8px}
    .kpi-row2{grid-template-columns:repeat(2,1fr);gap:8px}
    .tipos-toolbar{gap:6px;padding:8px 10px}
    .sort-btn{font-size:10px;padding:5px 10px}
}
@media(max-width:380px){.kpi-row{grid-template-columns:1fr}.kpi-row2{grid-template-columns:1fr}}

/* ── PRINT ── */
@media print{
    body{background:#fff !important;display:block}
    .sidebar,.topbar,.filter-badge,.loader,#errBox,.menu-toggle,.sidebar-overlay,.tipos-toolbar{display:none !important}
    .main{padding:0}
    .kpi{background:#e0f2fe !important;color:#0f172a !important;border:1px solid #bfdbfe}
    .kpi .kl,.kpi .kv,.kpi .ks{color:#0f172a !important}
    #secDash{display:none !important}
    #secTipos,#secClass{display:block !important}
    .card{box-shadow:none;border:1px solid #cbd5e1}
    .card:hover{transform:none}
}
</style>
</head>
<body>

<button class="menu-toggle" id="menuToggle" aria-label="Menu">☰</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="fecharSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="logo">Dashboard Patrimônio</div>
    <div class="sec">Visão</div>
    <button class="active" onclick="carregar('geral',this);fecharSidebar()">Geral</button>
    <hr>
    <div class="sec">Unidades</div>
    <button onclick="carregar('casa-de-portugal',this);fecharSidebar()">Casa de Portugal</button>
    <button onclick="carregar('premium',this);fecharSidebar()">Premium</button>
    <button onclick="carregar('evangelico',this);fecharSidebar()">Evangelico</button>
    <button onclick="carregar('sao-bernardo',this);fecharSidebar()">São Bernardo</button>
    <button onclick="carregar('oftalmo-casa',this);fecharSidebar()">Oftalmo Casa</button>
    <button onclick="carregar('menssana',this);fecharSidebar()">Menssana</button>
    <button onclick="carregar('santa-cruz',this);fecharSidebar()">Santa Cruz</button>
    <button onclick="carregar('ilha-do-governador',this);fecharSidebar()">Ilha do Governador</button>
    <button onclick="carregar('rio-laranjeiras',this);fecharSidebar()">Rio Laranjeiras</button>
    <button onclick="carregar('rio-botafogo',this);fecharSidebar()">Rio Botafogo</button>
    <button onclick="carregar('prontocor',this);fecharSidebar()">Prontocor</button>
    <button onclick="carregar('egas-moniz',this);fecharSidebar()">Egas Moniz</button>
    <button onclick="carregar('3d-diagnose',this);fecharSidebar()">3D Diagnose</button>
    <hr>
    <div class="sec">Analise</div>
    <button onclick="mostrarSecao('tipos',this);fecharSidebar()">Tipos de Itens</button>
    <button onclick="mostrarSecao('classificacao',this);fecharSidebar()">Classificação</button>
    <hr>
    <div class="sec">Exportar</div>
    <button class="export-btn" onclick="exportarDados();fecharSidebar()">⬇ Exportar / Imprimir</button>
</div>

<div class="main">
    <div class="topbar">
        <h1>Dashboard Patrimônio</h1>
        <a href="inicial.php">Voltar</a>
    </div>

    <div class="loader" id="loader">Carregando dados...</div>
    <div id="errBox" style="display:none;background:#fee2e2;color:#7f1d1d;padding:14px;border-radius:10px;margin-bottom:16px;font-size:13px"></div>
    <div class="filter-badge" id="filterBadge">Exibindo: Geral (todos os itens)</div>

    <div class="kpi-row"  id="kpiRow"></div>
    <div class="kpi-row2" id="kpiRow2"></div>

    <!-- SECAO PRINCIPAL -->
    <div id="secDash">
        <p class="stitle" id="tituloDistrib">Distribuição por Unidade</p>
        <div class="card" style="margin-bottom:18px">
            <h2 id="h2barlist">Quantidade por Unidade</h2>
            <div class="blist" id="barList"></div>
        </div>
        <div class="card"><h2 id="h2coluna">Comparativo</h2><canvas id="chartColuna" style="max-height:300px"></canvas></div>
        <p class="stitle">Status, Propriedade e Conciliação</p>
        <div class="g2">
            <div class="card">
                <h2>Status dos Itens</h2>
                <div class="tabs" id="statusTabs">
                    <div class="tab active" onclick="filtrarStatus('TODOS',this)">TODOS</div>
                    <div class="tab" onclick="filtrarStatus('ATIVO',this)">ATIVO</div>
                    <div class="tab" onclick="filtrarStatus('INATIVO',this)">INATIVO</div>
                    <div class="tab" onclick="filtrarStatus('BAIXADO',this)">BAIXADO</div>
                </div>
                <canvas id="chartStatus" style="max-height:220px"></canvas>
                <div class="chips" id="statusChips"></div>
            </div>
            <div class="card">
                <h2>Propriedade dos Itens</h2>
                <canvas id="chartProp" style="max-height:220px"></canvas>
                <div class="chips" id="propChips"></div>
            </div>
        </div>
        <div class="g2">
            <div class="card">
                <h2>Conciliação</h2>
                <canvas id="chartConc" style="max-height:210px"></canvas>
                <div class="chips" id="concChips"></div>
            </div>
            <div class="card">
                <h2>Movimentação</h2>
                <canvas id="chartMov" style="max-height:210px"></canvas>
                <div class="chips" id="movChips"></div>
            </div>
        </div>
        <p class="stitle">Usuarios</p>
        <div class="card"><h2>Cadastros por Usuario</h2><canvas id="chartUsuarios" style="max-height:280px"></canvas></div>

        <p class="stitle">Centro de Custo por Unidade</p>
        <div class="card">
            <h2>Quantidade e Valor por Centro de Custo (Unidade)</h2>
            <div class="ftable-wrap">
                <table class="ftable" id="tbCCU">
                    <thead><tr><th>#</th><th>Centro de Custo (Unidade)</th><th>Qtd</th><th>% Qtd</th><th>Valor (R$)</th><th style="width:90px">Dist. Qtd</th></tr></thead>
                    <tbody id="tbCCUBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- SECAO TIPOS -->
    <div id="secTipos" style="display:none">
        <p class="stitle">Tipos de Itens (por Descricao)</p>
        <div class="card"><h2>Top 20</h2><canvas id="chartDesc" style="max-height:380px"></canvas></div>
        <div class="card">
            <h2>Lista Completa <small style="font-size:10px;color:#64748b;font-weight:400">(clique em um item para ver detalhes)</small></h2>
            <div class="tipos-toolbar">
                <input class="tipos-search" id="tiposBusca" type="text"
                       placeholder=" Pesquisar descrição..."
                       oninput="aplicarFiltroTipos()">
                <div class="sort-grupo">
                    <button class="sort-btn active" id="btnSortQtd" onclick="definirOrdemTipos('qtd')">↓ Qtd</button>
                    <button class="sort-btn"         id="btnSortAZ"  onclick="definirOrdemTipos('az')">A → Z</button>
                    <button class="sort-btn"         id="btnSortZA"  onclick="definirOrdemTipos('za')">Z → A</button>
                </div>
                <span class="tipos-count" id="tiposCount"></span>
            </div>
            <div class="ftable-wrap">
                <table class="ftable">
                    <thead><tr><th>#</th><th>Descricao</th><th>Qtd</th><th>%</th><th style="width:100px">Dist.</th></tr></thead>
                    <tbody id="tbDesc"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- SECAO CLASSIFICACAO -->
    <div id="secClass" style="display:none">
        <p class="stitle">Classificação (Subgrupo)</p>
        <div class="card"><h2>Top 20</h2><canvas id="chartSub" style="max-height:380px"></canvas></div>
        <div class="card"><h2>Lista Completa</h2>
            <div class="ftable-wrap">
                <table class="ftable">
                    <thead><tr><th>#</th><th>Subgrupo</th><th>Qtd</th><th>%</th><th style="width:100px">Dist.</th></tr></thead>
                    <tbody id="tbSub"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

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

/* ── HEARTBEAT ── */
(function hb(){
    fetch('heartbeat.php?_=' + Date.now(), { method:'POST', credentials:'same-origin', cache:'no-store' })
    .then(r => r.json())
    .then(data => { if (data.revogada) window.location.href = 'index.html?error=Sua+sessao+foi+encerrada'; })
    .catch(() => {});
    setTimeout(hb, 30000);
})();

document.getElementById('menuToggle').onclick = () => {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
};
function fecharSidebar(){
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
}

const CORES=['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#f97316','#e11d48','#06b6d4','#facc15','#a3e635','#fb7185','#38bdf8','#a78bfa','#34d399'];
const soma=a=>a.reduce((s,v)=>s+Number(v),0);
const pct=(v,t)=>t>0?((v/t)*100).toFixed(1):0;
const fmt=v=>Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});

const slugNomes={
    'geral':'Geral (todos os itens)',
    'casa-de-portugal':'Casa de Portugal','premium':'Premium','evangelico':'Evangelico',
    'sao-bernardo':'São Bernardo','oftalmo-casa':'Oftalmo Casa','menssana':'Menssana',
    'santa-cruz':'Santa Cruz','ilha-do-governador':'Ilha do Governador',
    'rio-laranjeiras':'Rio Laranjeiras','rio-botafogo':'Rio Botafogo',
    'prontocor':'Prontocor','egas-moniz':'Egas Moniz','3d-diagnose':'3D Diagnose',
};

let D={}, statusFiltro='TODOS';
const CH={};
function mk(id,cfg){ if(CH[id]) CH[id].destroy(); CH[id]=new Chart(document.getElementById(id),cfg); }
const dlPlugin=[ChartDataLabels];

function donutCfg(labels,dados,colors){
    return{type:'doughnut',data:{labels,datasets:[{data:dados,backgroundColor:colors||CORES,borderWidth:2}]},
        options:{responsive:true,plugins:{legend:{position:'bottom'},datalabels:{color:'#fff',font:{weight:'bold',size:11},
        formatter:(v,ctx)=>{const t=soma(ctx.chart.data.datasets[0].data);return t>0?((v/t)*100).toFixed(1)+'%':''}}}},plugins:dlPlugin};
}
function barCfg(labels,dados,horiz){
    return{type:'bar',data:{labels,datasets:[{label:'Qtd',data:dados,backgroundColor:CORES,borderRadius:7}]},
        options:{indexAxis:horiz?'y':'x',responsive:true,plugins:{legend:{display:false},
        datalabels:{anchor:'end',align:'end',color:'#1e3a8a',font:{weight:'bold',size:11},formatter:v=>v}},
        scales:{x:{beginAtZero:true},y:{beginAtZero:true}}},plugins:dlPlugin};
}
function barList(elId,labels,dados){
    const el=document.getElementById(elId); el.innerHTML='';
    const max=Math.max(...dados,1); const t=soma(dados);
    labels.forEach((l,i)=>{
        const v=Number(dados[i]); const w=((v/max)*100).toFixed(1);
        el.innerHTML+=`<div class="brow"><div class="blabel"><span>${l}</span><span>${v} (${pct(v,t)}%)</span></div>
        <div class="btrack"><div class="bfill" style="width:${w}%;background:${esc(CORES[i%CORES.length])}"></div></div></div>`;
    });
}

function renderizar(slug){
    const total=D.total||0,emOutra=D.em_outra||0,isGeral=(slug==='geral');
    document.getElementById('filterBadge').textContent='Exibindo: '+(slugNomes[slug]||slug);
    const movP=pct(D.movimentados||0,total),movDefP=pct(D.mov_definitivo||0,total);
    const concP=pct(D.conciliados||0,total),emOutraP=pct(emOutra,total);

    /* ── KPI ROW 1 ──────────────────────────────────────────────────────────
       Alterações:
       • "Valor Total (R$)"      → "Valor Total Aproximado (R$)" (soma valor_item)
       • Nova caixa "Valor de Conciliados (R$)" após "Patrimônio Próprio (R$)"
    ─────────────────────────────────────────────────────────────────────── */
    document.getElementById('kpiRow').innerHTML=`
        <div class="kpi accent"><div class="kl">Total de Itens</div><div class="kv">${total.toLocaleString('pt-BR')}</div><div class="ks">${isGeral?'Todos os cadastros':'Itens da unidade'}</div></div>
        <div class="kpi"><div class="kl">Valor Total Aproximado (R$)</div><div class="kv" style="font-size:16px">${fmt(D.valor_total||0)}</div><div class="ks">Soma de valor_item</div></div>
        <div class="kpi"><div class="kl">Patrimônio Próprio (R$)</div><div class="kv" style="font-size:16px">${fmt(D.valor_proprio||0)}</div><div class="ks">Itens PATRIMÔNIO</div></div>
        <div class="kpi teal"><div class="kl">Valor de Conciliados (R$)</div><div class="kv" style="font-size:16px">${fmt(D.valor_conciliados||0)}</div><div class="ks"></div></div>
        <div class="kpi"><div class="kl">Conciliados</div><div class="kv">${D.conciliados||0}</div><div class="ks">${concP}% do total</div></div>
        <div class="kpi"><div class="kl">Movimentados</div><div class="kv">${D.movimentados||0}</div><div class="ks">${movP}% do total</div></div>
        <div class="kpi"><div class="kl">Mov. Definitivo</div><div class="kv">${D.mov_definitivo||0}</div><div class="ks">${movDefP}% do total</div></div>
        <div class="kpi"><div class="kl">Em Outra Unidade</div><div class="kv">${emOutra}</div><div class="ks">${emOutraP}% do total</div></div>`;

    /* ── KPI ROW 2 ──────────────────────────────────────────────────────────
       Alteração: removida caixa "Inativos"
    ─────────────────────────────────────────────────────────────────────── */
    const ativos=D.qtd_ativos||0,baixados=D.qtd_baixados||0;
    const comodato=D.qtd_comodato||0,alugado=D.qtd_alugado||0,emprest=D.qtd_emprestado||0,terceiros=D.qtd_terceiros||0;
    document.getElementById('kpiRow2').innerHTML=`
        <div class="kpi green"><div class="kl">Ativos</div><div class="kv">${ativos.toLocaleString('pt-BR')}</div><div class="ks">${pct(ativos,total)}% do total</div></div>
        <div class="kpi red"><div class="kl">Baixados</div><div class="kv">${baixados.toLocaleString('pt-BR')}</div><div class="ks">${pct(baixados,total)}% do total</div></div>
        <div class="kpi purple"><div class="kl">Comodatos</div><div class="kv">${comodato.toLocaleString('pt-BR')}</div><div class="ks">${pct(comodato,total)}% do total</div></div>
        <div class="kpi purple"><div class="kl">Alugados</div><div class="kv">${alugado.toLocaleString('pt-BR')}</div><div class="ks">${pct(alugado,total)}% do total</div></div>
        <div class="kpi purple"><div class="kl">Emprestados</div><div class="kv">${emprest.toLocaleString('pt-BR')}</div><div class="ks">${pct(emprest,total)}% do total</div></div>
        <div class="kpi"><div class="kl">Total Terceiros</div><div class="kv">${terceiros.toLocaleString('pt-BR')}</div><div class="ks">${pct(terceiros,total)}% do total</div></div>`;

    const distLabels=isGeral?(D.unidades||[]):(D.setores||[]);
    const distQtd=isGeral?(D.unidades_qtd||[]):(D.setores_qtd||[]);
    document.getElementById('h2barlist').textContent=isGeral?'Quantidade por Unidade':'Quantidade por Setor';
    document.getElementById('h2coluna').textContent=isGeral?'Comparativo por Unidade':'Comparativo por Setor';
    document.getElementById('tituloDistrib').textContent=isGeral?'Distribuição por Unidade':'Distribuição por Setor';
    barList('barList',distLabels,distQtd);
    mk('chartColuna',barCfg(distLabels,distQtd,false));
    renderStatus();
    mk('chartProp',donutCfg(D.prop_labels||[],D.prop_qtd||[]));
    const qP=D.qtd_proprio||0,qT=D.qtd_terceiros||0;
    document.getElementById('propChips').innerHTML=`<span class="chip">Proprio: ${qP} (${pct(qP,total)}%)</span><span class="chip p">Terceiros: ${qT} (${pct(qT,total)}%)</span>`;
    const conc=D.conciliados||0,nConc=D.nao_conciliados||0;
    mk('chartConc',donutCfg(['Conciliados','Nao Conciliados'],[conc,nConc],['#10b981','#ef4444']));
    document.getElementById('concChips').innerHTML=`<span class="chip g">Conciliados: ${conc} (${pct(conc,total)}%)</span><span class="chip r">Nao conciliados: ${nConc} (${pct(nConc,total)}%)</span>`;
    const mov=D.movimentados||0,movN=total-mov,movDef=D.mov_definitivo||0;
    mk('chartMov',donutCfg(['Movimentados','Nao Movimentados'],[mov,movN],['#f59e0b','#3b82f6']));
    document.getElementById('movChips').innerHTML=`<span class="chip y">Movimentados: ${mov} (${pct(mov,total)}%)</span><span class="chip p">Mov. Definitivo: ${movDef} (${pct(movDef,total)}%)</span><span class="chip r">Em outra unidade: ${emOutra} (${emOutraP}%)</span>`;
    mk('chartUsuarios',barCfg(D.usu_labels||[],D.usu_qtd||[],true));
    renderCCU();
    if(document.getElementById('secTipos').style.display!=='none') renderTipos();
    if(document.getElementById('secClass').style.display!=='none') renderClass();
}

function renderCCU(){
    const labels = D.ccu_labels || [];
    const qtds   = D.ccu_qtd   || [];
    const vals   = D.ccu_valor || [];
    const totalQtd = qtds.reduce((a,b)=>a+b,0) || 1;
    const maxQtd   = Math.max(...qtds, 1);
    const tb = document.getElementById('tbCCUBody');
    tb.innerHTML = '';
    labels.forEach((l,i) => {
        const w = ((qtds[i]/maxQtd)*100).toFixed(1);
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="color:#64748b;font-size:10px">${i+1}</td>
            <td><strong>${l}</strong></td>
            <td>${qtds[i].toLocaleString('pt-BR')}</td>
            <td>${pct(qtds[i],totalQtd)}%</td>
            <td>R$ ${fmt(vals[i]||0)}</td>
            <td><div class="bmt"><div class="bmf" style="width:${w}%;background:${esc(CORES[i%CORES.length])}"></div></div></td>`;
        tb.appendChild(tr);
    });
    if(!labels.length){
        tb.innerHTML='<tr><td colspan="6" style="color:#94a3b8;text-align:center;padding:14px">Nenhum dado</td></tr>';
    }
}

function renderStatus(){
    const sL=D.status_labels||[],sQ=D.status_qtd||[],sV=D.valor_status||{};
    const fL=statusFiltro==='TODOS'?sL:sL.filter(x=>x===statusFiltro);
    const fQ=statusFiltro==='TODOS'?sQ:sQ.filter((_,i)=>sL[i]===statusFiltro);
    mk('chartStatus',donutCfg(fL,fQ,['#10b981','#f59e0b','#ef4444']));
    const map={ATIVO:'g',INATIVO:'y',BAIXADO:'r'};
    const total=D.total||0; let html='';
    sL.forEach((l,i)=>{ if(statusFiltro!=='TODOS'&&l!==statusFiltro) return; html+=`<span class="chip ${esc(map[l] || '')}">${l}: ${esc(sQ[i])} (${pct(sQ[i],total)}%) — R$ ${fmt(sV[l]||0)}</span>`; });
    document.getElementById('statusChips').innerHTML=html;
}
function filtrarStatus(s,el){
    document.querySelectorAll('#statusTabs .tab').forEach(t=>t.classList.remove('active'));
    el.classList.add('active'); statusFiltro=s; renderStatus();
}

/* ── TIPOS ── */
let tiposOrdem = 'qtd';
let drillAberto = null;

function definirOrdemTipos(ordem){
    tiposOrdem = ordem;
    document.querySelectorAll('.sort-btn').forEach(b => b.classList.remove('active'));
    const map = {qtd:'btnSortQtd', az:'btnSortAZ', za:'btnSortZA'};
    document.getElementById(map[ordem]).classList.add('active');
    aplicarFiltroTipos();
}

function aplicarFiltroTipos(){
    const dL = D.desc_labels || [];
    const dQ = D.desc_qtd   || [];
    const busca = (document.getElementById('tiposBusca').value || '').trim().toLowerCase();
    const totalGeral = soma(dQ);
    const maxGlobal  = Math.max(...dQ, 1);

    let pares = dL.map((l, i) => ({ l, v: Number(dQ[i]), i }));
    if(busca) pares = pares.filter(p => p.l.toLowerCase().includes(busca));
    if(tiposOrdem === 'az') pares.sort((a, b) => a.l.localeCompare(b.l, 'pt-BR'));
    else if(tiposOrdem === 'za') pares.sort((a, b) => b.l.localeCompare(a.l, 'pt-BR'));

    const countEl = document.getElementById('tiposCount');
    countEl.textContent = busca
        ? `${esc(pares.length)} de ${esc(dL.length)} resultado${pares.length !== 1 ? 's' : ''}`
        : `${esc(dL.length)} item${dL.length !== 1 ? 'ns' : ''}`;

    const tb = document.getElementById('tbDesc');
    tb.innerHTML = '';
    drillAberto = null;

    pares.forEach((p, rank) => {
        const w = ((p.v / maxGlobal) * 100).toFixed(1);
        const tr = document.createElement('tr');
        tr.id = 'main_' + p.i;
        tr.innerHTML = `
            <td style="color:#64748b;font-size:10px">${rank + 1}</td>
            <td><span class="desc-clickable" onclick="toggleDrill(${esc(p.i)},'${encodeURIComponent(p.l)}')">${esc(p.l)}</span></td>
            <td><strong>${esc(p.v)}</strong></td>
            <td>${pct(p.v, totalGeral)}%</td>
            <td><div class="bmt"><div class="bmf" style="width:${w}%;background:${esc(CORES[p.i % CORES.length])}"></div></div></td>`;
        tb.appendChild(tr);

        const drTr = document.createElement('tr');
        drTr.id = 'dr_' + p.i;
        drTr.classList.add('drill-row');
        drTr.style.display = 'none';
        drTr.innerHTML = `<td colspan="5"><div class="drill-inner" id="drill_inner_${esc(p.i)}"><em style="font-size:11px;color:#94a3b8">Carregando...</em></div></td>`;
        tb.appendChild(drTr);
    });
}

function renderTipos(){
    const dL = D.desc_labels || [], dQ = D.desc_qtd || [];
    mk('chartDesc', barCfg(dL.slice(0, 20), dQ.slice(0, 20), true));
    document.getElementById('tiposBusca').value = '';
    tiposOrdem = 'qtd';
    document.querySelectorAll('.sort-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('btnSortQtd').classList.add('active');
    aplicarFiltroTipos();
}

function toggleDrill(idx, encDesc){
    const rowId = 'dr_' + idx;
    const drRow = document.getElementById(rowId);
    if(!drRow) return;
    if(drillAberto === idx){ drRow.style.display = 'none'; drillAberto = null; return; }
    if(drillAberto !== null){
        const prev = document.getElementById('dr_' + drillAberto);
        if(prev) prev.style.display = 'none';
    }
    drRow.style.display = '';
    drillAberto = idx;
    const innerId = 'drill_inner_' + idx;
    const inner = document.getElementById(innerId);
    if(inner.dataset.loaded) return;
    fetch('desc_detalhada.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'slug='+encodeURIComponent(slugAtual)+'&descricao='+encDesc
    })
    .then(r => r.json()).then(data => {
        inner.innerHTML = (!data || !data.length)
            ? '<span class="drill-empty">Sem subdivisões cadastradas para este item.</span>'
            : data.map(d => `<div class="drill-chip">${esc(d.descricao_detalhada)}<span>${esc(d.qtd)}</span></div>`).join('');
        inner.dataset.loaded = '1';
    }).catch(() => { inner.innerHTML = '<span class="drill-empty">Erro ao carregar detalhes.</span>'; });
}

function renderClass(){
    const sL=D.sub_labels||[],sQ=D.sub_qtd||[];
    mk('chartSub',barCfg(sL.slice(0,20),sQ.slice(0,20),true));
    const tb=document.getElementById('tbSub'); tb.innerHTML='';
    const t=soma(sQ),max=Math.max(...sQ,1);
    sL.forEach((l,i)=>{
        const v=sQ[i],w=((v/max)*100).toFixed(1);
        tb.innerHTML+=`<tr><td style="color:#64748b;font-size:10px">${i+1}</td><td>${l}</td><td><strong>${v}</strong></td><td>${pct(v,t)}%</td>
        <td><div class="bmt"><div class="bmf" style="width:${w}%;background:${esc(CORES[i%CORES.length])}"></div></div></td></tr>`;
    });
}

const secoes=['secDash','secTipos','secClass'];
const secMap={'tipos':'secTipos','classificacao':'secClass'};
function mostrarSecao(sec,btn){
    secoes.forEach(s=>document.getElementById(s).style.display='none');
    document.getElementById(secMap[sec]).style.display='';
    document.querySelectorAll('.sidebar button').forEach(b=>b.classList.remove('active'));
    if(btn) btn.classList.add('active');
    if(sec==='tipos') renderTipos();
    if(sec==='classificacao') renderClass();
}

let slugAtual='geral';
function carregar(slug,btn){
    slugAtual=slug; statusFiltro='TODOS';
    document.querySelectorAll('#statusTabs .tab').forEach((t,i)=>t.classList.toggle('active',i===0));
    document.querySelectorAll('.sidebar button').forEach(b=>b.classList.remove('active'));
    if(btn) btn.classList.add('active');
    secoes.forEach(s=>document.getElementById(s).style.display='none');
    document.getElementById('secDash').style.display='';
    document.getElementById('loader').style.display='block';
    fetch('filtro_dashboard.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'slug='+encodeURIComponent(slug)})
    .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.text(); })
    .then(txt=>{
        document.getElementById('loader').style.display='none';
        let data;
        try{ data=JSON.parse(txt); }
        catch(e){ document.getElementById('errBox').style.display='block'; document.getElementById('errBox').innerHTML='<strong>Erro:</strong><br><pre style="font-size:11px;white-space:pre-wrap">'+txt.substring(0,2000)+'</pre>'; return; }
        if(data.erro){ alert('Erro: '+data.erro); return; }
        D=data; renderizar(slug);
    })
    .catch(err=>{ document.getElementById('loader').style.display='none'; document.getElementById('errBox').style.display='block'; document.getElementById('errBox').textContent='Falha na requisição: '+err.message; });
}

/* ── EXPORTAR / IMPRIMIR ── */
async function gerarHtmlRelatorio(){
    const filtro=document.getElementById('filterBadge').textContent;
    const total=D.total||0;
    function imgChart(id){ const c=document.getElementById(id); if(!c) return ''; try{ return `<img src="${c.toDataURL('image/png')}" style="max-width:100%;display:block;margin:0 auto">`; }catch(e){ return ''; } }
    const ativos=D.qtd_ativos||0,baixados=D.qtd_baixados||0;
    const comodato=D.qtd_comodato||0,alugado=D.qtd_alugado||0,emprest=D.qtd_emprestado||0,terceiros=D.qtd_terceiros||0;

    /* KPI HTML do relatório — mesmas alterações: sem Inativos, novo Valor de Conciliados */
    const kpiHtml=`<div class="kpi-grid">
        <div class="kpi-box accent"><div class="kl">Total de Itens</div><div class="kv">${total.toLocaleString('pt-BR')}</div></div>
        <div class="kpi-box"><div class="kl">Valor Total Aproximado (R$)</div><div class="kv">${fmt(D.valor_total||0)}</div></div>
        <div class="kpi-box"><div class="kl">Patrimônio Próprio (R$)</div><div class="kv">${fmt(D.valor_proprio||0)}</div></div>
        <div class="kpi-box"><div class="kl">Valor de Conciliados (R$)</div><div class="kv">${fmt(D.valor_conciliados||0)}</div></div>
        <div class="kpi-box"><div class="kl">Conciliados</div><div class="kv">${D.conciliados||0} (${pct(D.conciliados||0,total)}%)</div></div>
        <div class="kpi-box"><div class="kl">Movimentados</div><div class="kv">${D.movimentados||0} (${pct(D.movimentados||0,total)}%)</div></div>
        <div class="kpi-box"><div class="kl">Mov. Definitivo</div><div class="kv">${D.mov_definitivo||0} (${pct(D.mov_definitivo||0,total)}%)</div></div>
        <div class="kpi-box"><div class="kl">Em Outra Unidade</div><div class="kv">${D.em_outra||0}</div></div>
        <div class="kpi-box green"><div class="kl">Ativos</div><div class="kv">${ativos} (${pct(ativos,total)}%)</div></div>
        <div class="kpi-box red"><div class="kl">Baixados</div><div class="kv">${baixados} (${pct(baixados,total)}%)</div></div>
        <div class="kpi-box purple"><div class="kl">Comodatos</div><div class="kv">${comodato} (${pct(comodato,total)}%)</div></div>
        <div class="kpi-box purple"><div class="kl">Alugados</div><div class="kv">${alugado} (${pct(alugado,total)}%)</div></div>
        <div class="kpi-box purple"><div class="kl">Emprestados</div><div class="kv">${emprest} (${pct(emprest,total)}%)</div></div>
        <div class="kpi-box"><div class="kl">Total Terceiros</div><div class="kv">${terceiros} (${pct(terceiros,total)}%)</div></div>
    </div>`;

    const isGeral=(slugAtual==='geral');
    const graficosHtml=`<div class="grafico-grid">
        <div class="grafico-box"><div class="gh2">${isGeral?'Distribuição por Unidade':'Distribuição por Setor'}</div>${imgChart('chartColuna')}</div>
        <div class="grafico-box"><div class="gh2">Status dos Itens</div>${imgChart('chartStatus')}</div>
        <div class="grafico-box"><div class="gh2">Propriedade dos Itens</div>${imgChart('chartProp')}</div>
        <div class="grafico-box"><div class="gh2">Conciliação</div>${imgChart('chartConc')}</div>
        <div class="grafico-box"><div class="gh2">Movimentação</div>${imgChart('chartMov')}</div>
        <div class="grafico-box"><div class="gh2">Cadastros por Usuário</div>${imgChart('chartUsuarios')}</div>
    </div>`;
    const secTipos=document.getElementById('secTipos');
    const tiposEraOculto=secTipos.style.display==='none';
    if(tiposEraOculto) secTipos.style.display='';
    renderTipos(); if(CH['chartDesc']) CH['chartDesc'].resize();
    await new Promise(res=>requestAnimationFrame(()=>requestAnimationFrame(()=>setTimeout(res,150))));
    const grafTiposHtml=imgChart('chartDesc');
    if(tiposEraOculto) secTipos.style.display='none';
    const dL=D.desc_labels||[],dQ=D.desc_qtd||[],tDesc=soma(dQ);
    const rowsTipos=dL.map((l,i)=>`<tr><td>${i+1}</td><td>${l}</td><td>${esc(dQ[i])}</td><td>${pct(dQ[i],tDesc)}%</td></tr>`).join('');
    const sL=D.sub_labels||[],sQ=D.sub_qtd||[],tSub=soma(sQ);
    const rowsSub=sL.map((l,i)=>`<tr><td>${i+1}</td><td>${l}</td><td>${esc(sQ[i])}</td><td>${pct(sQ[i],tSub)}%</td></tr>`).join('');

    const scriptOpen  = '<scr' + 'ipt>';
    const scriptClose = '<\/script>';

    return `<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Relatório — Dashboard Patrimônio</title>
    <style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Segoe UI',Arial,sans-serif;padding:28px;color:#0f172a;font-size:13px}h1{font-size:18px;color:#1e3a8a;margin-bottom:4px}.sub{font-size:12px;color:#64748b;margin-bottom:18px}.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:24px}.kpi-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 14px}.kpi-box.accent{background:#1d4ed8;color:#fff}.kpi-box.accent .kl,.kpi-box.accent .kv{color:#fff}.kpi-box.green{background:#065f46;color:#fff}.kpi-box.green .kl,.kpi-box.green .kv{color:#fff}.kpi-box.red{background:#7f1d1d;color:#fff}.kpi-box.red .kl,.kpi-box.red .kv{color:#fff}.kpi-box.yellow{background:#78350f;color:#fff}.kpi-box.yellow .kl,.kpi-box.yellow .kv{color:#fff}.kpi-box.purple{background:#4c1d95;color:#fff}.kpi-box.purple .kl,.kpi-box.purple .kv{color:#fff}.kl{font-size:10px;color:#3b82f6;text-transform:uppercase;letter-spacing:.6px;margin-bottom:3px}.kv{font-size:15px;font-weight:800;color:#1e40af}h2{font-size:14px;font-weight:700;color:#1e3a8a;margin:28px 0 10px;border-left:4px solid #3b82f6;padding-left:8px}.grafico-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}.grafico-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px}.gh2{font-size:12px;font-weight:700;color:#1e3a8a;margin-bottom:10px;text-align:center}table{width:100%;border-collapse:collapse;font-size:12px}th{background:#1e40af;color:#fff;padding:7px 10px;text-align:left}td{padding:6px 10px;border-bottom:1px solid #e2e8f0}tr:nth-child(even) td{background:#f8fafc}.page-break{page-break-before:always;margin-top:20px}@media print{body{padding:10px}.kpi-grid{grid-template-columns:repeat(4,1fr)}}</style>
    </head><body>
    <h1>Dashboard Patrimônio — Relatório de Dados</h1>
    <div class="sub">${filtro} · Gerado em ${new Date().toLocaleString('pt-BR')}</div>
    <h2>Indicadores Gerais</h2>${kpiHtml}
    <h2>Gráficos Gerais</h2>${graficosHtml}
    <div class="page-break"></div>
    <h2>Tipos de Itens — Top 20 (Gráfico)</h2>${grafTiposHtml||'<p style="color:#94a3b8;font-size:12px">Gráfico não disponível.</p>'}
    <h2>Tipos de Itens — Lista Completa</h2>
    <table><thead><tr><th>#</th><th>Descricao</th><th>Qtd</th><th>%</th></tr></thead><tbody>${rowsTipos||'<tr><td colspan="4">Sem dados</td></tr>'}</tbody></table>
    <div class="page-break"></div>
    <h2>Classificação — Lista Completa</h2>
    <table><thead><tr><th>#</th><th>Subgrupo</th><th>Qtd</th><th>%</th></tr></thead><tbody>${rowsSub||'<tr><td colspan="4">Sem dados</td></tr>'}</tbody></table>
    ${scriptOpen}window.onload=()=>window.print()${scriptClose}
    </body></html>`;
}

async function exportarDados(){
    const html=await gerarHtmlRelatorio();
    const win=window.open('','_blank');
    win.document.write(html);
    win.document.close();
}

carregar('geral',document.querySelector('.sidebar button'));
</script>
</body>
</html>