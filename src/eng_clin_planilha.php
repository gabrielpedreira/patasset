<?php
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = 'planilha';   // item ativo no menu lateral
include 'conexao.php';

if (!isset($_SESSION['usuario_logado'])) {
    header("Location: index.html");
    exit();
}

$usuario = $_SESSION['usuario_logado'];
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
$stmt->bind_param("s", $usuario);
$stmt->execute();
$res = $stmt->get_result();
$nivel = 'C'; $classe_usuario = ''; $status = 'ATIVO';
if ($r = $res->fetch_assoc()) {
    $nivel          = strtoupper(trim($r['permicao']));
    $classe_usuario = strtoupper(trim($r['classe_usuario']));
    $status         = $r['status'] ?? 'ATIVO';
}
$stmt->close();
$conn->close();

if ($status !== 'ATIVO') {
    session_destroy();
    header("Location: index.html?erro=bloqueado");
    exit();
}

if (!in_array($classe_usuario, ['DEV','ENGENHARIA CLINICA']) || !in_array($nivel, ['A','B','C'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}

$podeEditar    = in_array($nivel, ['A','B']) || $classe_usuario === 'DEV';
$podeMovimentar= in_array($nivel, ['A','B','C']) || $classe_usuario === 'DEV';

$colunas = [
    'DESCRIÇÃO'              => 'descricao',
    'DESCRIÇÃO DETALHADA'    => 'descricao_detalhada',
    'MARCA'                  => 'marca',
    'MODELO'                 => 'modelo',
    'SÉRIE'                  => 'serie',
    'PROPRIEDADE'            => 'propriedade',
    'TAG PATRIMÔNIO'         => 'tag_antiga',
    'TAG NOVA COMPRA'        => 'tag_trocada',
    'EMPRESA'                => 'empresa',
    'TAG ALUGADO'            => 'tag_alugado',
    'OBSERVAÇÃO'             => 'observacao',
    'UNIDADE'                => 'unidade',
    'SETOR'                  => 'setor',
    'PAVIMENTO'              => 'pavimento',
    'ÁREA'                   => 'area',
    'USUÁRIO CADASTRO'       => 'usuario_cadastro',
    'PERÍODO'                => 'periodo',
    'STATUS'                 => 'status',
    'DATA MOVIMENTAÇÃO'      => 'data_movimentacao',
    'UNIDADE DESTINO'        => 'unidade_destino',
    'SETOR DESTINO'          => 'setor_destino',
    'ÁREA DESTINO'           => 'area_destino',
    'USUÁRIO MOVIMENTAÇÃO'   => 'usuario_movimentacao',
];

date_default_timezone_set('America/Sao_Paulo');
$data = date('d/m/Y');
$hora = date('H:i:s');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Planilha — Engenharia Clínica</title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="shortcut icon" href="logo_1.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --bg-page:#0f0f0f; --bg-sidebar:#141414; --bg-card:#1a1a1a;
  --bg-card-hover:#1f1f1f; --bg-input:#222; --border:rgba(255,255,255,0.07);
  --border-hover:rgba(255,255,255,0.14); --accent-steel:#a0aec0;
  --text-primary:#f0f0f0; --text-secondary:#888; --text-muted:#555;
  --sidebar-w:260px; --sidebar-collapsed:68px; --header-h:56px;
  --radius:10px; --radius-lg:16px;
  --transition:0.22s cubic-bezier(0.4,0,0.2,1);
  --font-ui:'DM Sans',sans-serif; --font-display:'Syne',sans-serif;
  --status-ok:#4ade80; --status-warn:#facc15; --status-err:#f87171;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-ui);background:var(--bg-page);color:var(--text-primary);height:100vh;overflow:hidden;display:flex;flex-direction:column}

/* ── SIDEBAR ── */
.menu-toggle{display:none;position:fixed;top:10px;left:10px;z-index:1200;background:#2a2a2a;color:#e2e8f0;border:1px solid var(--border-hover);border-radius:8px;padding:8px 12px;font-size:20px;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.4);line-height:1}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000}
.sidebar-overlay.open{display:block}
#sidebar{position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;background:var(--bg-sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;transition:width var(--transition);overflow:visible}
#sidebar.collapsed{width:var(--sidebar-collapsed)}
.sidebar-brand{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px 12px 16px;border-bottom:1px solid var(--border);flex-shrink:0;gap:10px}
.brand-logo-main{width:56%;max-width:140px;height:auto;object-fit:contain;display:block;transition:opacity var(--transition),width var(--transition)}
#sidebar.collapsed .brand-logo-main{width:31px;max-width:31px}
.sidebar-toggle{position:absolute;top:14px;right:-14px;width:28px;height:28px;background:var(--bg-card);border:1px solid var(--border-hover);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:200;transition:background var(--transition);color:var(--text-secondary);font-size:11px}
.sidebar-toggle:hover{background:#2a2a2a;color:var(--text-primary)}
.sidebar-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:8px 10px;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.nav-item{display:block;width:100%;padding:11px 14px;margin:3px 0;border-radius:6px;cursor:pointer;text-decoration:none;color:#bfc0c2;font-size:14px;font-weight:400;transition:background var(--transition),color var(--transition),transform var(--transition);white-space:nowrap;overflow:hidden;position:relative;border:none;background:#1e2025;text-align:left;letter-spacing:.01em}
.nav-item:hover{background:#26282d;color:#e8e9eb;transform:translateX(4px)}
.nav-item.active{background:#2a2c31;color:#fff;font-weight:500}
.nav-label{display:inline}
#sidebar.collapsed .nav-label{opacity:0}
.nav-item-sair{color:#f87171!important}
.nav-item-sair:hover{background:rgba(248,113,113,0.08)!important}
#sidebar.collapsed .nav-item{padding:10px 0;text-align:center;font-size:0;color:transparent;overflow:hidden;transform:none!important}
#sidebar.collapsed .nav-item::before{font-family:'Font Awesome 6 Free';font-weight:900;font-size:15px;color:#888;display:block;line-height:1;transition:color var(--transition)}
#sidebar.collapsed .nav-item:hover::before{color:#e8e9eb}
#sidebar.collapsed .nav-item.active::before{color:#fff}
#sidebar.collapsed .nav-item::after{content:attr(data-tooltip);position:absolute;left:calc(var(--sidebar-collapsed) + 8px);top:50%;transform:translateY(-50%);background:#2d2d2d;color:#e2e8f0;font-size:12px;padding:5px 10px;border-radius:6px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:200;border:1px solid var(--border-hover);box-shadow:0 4px 12px rgba(0,0,0,.4)}
#sidebar.collapsed .nav-item:hover::after{opacity:1}
#sidebar.collapsed .nav-item[data-tooltip="Abertura de Chamado"]::before  {content:"\f46d"}
#sidebar.collapsed .nav-item[data-tooltip="Cadastro de Equipamento"]::before{content:"\f0fe"}
#sidebar.collapsed .nav-item[data-tooltip="Planilha"]::before             {content:"\f0ce"}
#sidebar.collapsed .nav-item[data-tooltip="Ordem de Serviço"]::before     {content:"\f46d"}
#sidebar.collapsed .nav-item[data-tooltip="Estoque"]::before              {content:"\f49e"}
#sidebar.collapsed .nav-item[data-tooltip="Movimentar"]::before           {content:"\f362"}
#sidebar.collapsed .nav-item[data-tooltip="Página Inicial"]::before       {content:"\f200"}
#sidebar.collapsed .nav-item[data-tooltip="Voltar"]::before               {content:"\f060"}

/* ── MAIN ── */
#main{margin-left:var(--sidebar-w);transition:margin-left var(--transition);display:flex;flex-direction:column;height:100vh;overflow:hidden}
#main.sidebar-collapsed{margin-left:var(--sidebar-collapsed)}

/* ── TOPBAR ── */
.topbar{height:var(--header-h);background:rgba(20,20,20,0.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:10px;position:sticky;top:0;z-index:50;flex-shrink:0}
.topbar-breadcrumb{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted)}
.topbar-breadcrumb span:last-child{color:var(--text-primary);font-weight:500}
.topbar-breadcrumb i{font-size:10px}
.topbar-spacer{flex:1}
.topbar-logo-rede{height:32px;width:auto;object-fit:contain;opacity:.75;flex-shrink:0}

/* ── CONTENT ── */
.content{flex:1;display:flex;flex-direction:column;padding:20px;gap:14px;overflow:hidden;min-height:0}

/* ── PAGE HEADER ── */
.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;flex-shrink:0}
.page-title{font-family:var(--font-display);font-size:20px;font-weight:700;color:var(--text-primary);letter-spacing:-.01em}
.page-subtitle{font-size:12px;color:var(--text-muted);margin-top:2px}

/* ── TOOLBAR ── */
.toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:12px 16px;flex-shrink:0}
.toolbar-left{display:flex;align-items:center;gap:8px;flex:1;flex-wrap:wrap}
.toolbar-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:7px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none;white-space:nowrap}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn-ghost{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text-secondary)}
.btn-ghost:not(:disabled):hover{background:rgba(255,255,255,.1);color:var(--text-primary);border-color:var(--border-hover)}
.btn-primary{background:#232323;border:1px solid rgba(255,255,255,.13);color:var(--text-primary)}
.btn-primary:not(:disabled):hover{background:#2e2e2e;border-color:rgba(255,255,255,.2)}
.btn-success{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.25);color:#4ade80}
.btn-success:not(:disabled):hover{background:rgba(74,222,128,.18)}
.btn-warn{background:rgba(250,204,21,.1);border:1px solid rgba(250,204,21,.25);color:#facc15}
.btn-warn:not(:disabled):hover{background:rgba(250,204,21,.18)}
.btn-danger{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.25);color:#f87171}
.btn-danger:not(:disabled):hover{background:rgba(248,113,113,.18)}
/* Contador */
.contador-box{background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:12px;color:var(--text-secondary);white-space:nowrap}

/* Barra de pesquisa */
.search-wrap{display:flex;align-items:center;gap:7px;background:var(--bg-input);border:1px solid var(--border);border-radius:7px;padding:6px 11px;transition:border-color var(--transition)}
.search-wrap:focus-within{border-color:var(--border-hover)}
.search-wrap i{font-size:12px;color:var(--text-muted);flex-shrink:0}
.search-wrap input{background:none;border:none;outline:none;font-size:12px;color:var(--text-primary);font-family:var(--font-ui);width:200px}
.search-wrap input::placeholder{color:var(--text-muted)}

/* banner filtros ativos */
.banner-filtros{font-size:11px;color:var(--text-muted);background:rgba(250,204,21,.07);border:1px solid rgba(250,204,21,.15);border-radius:6px;padding:4px 10px;display:none;white-space:nowrap}
.banner-filtros.vis{display:block}

/* ── TABELA ── */
.table-wrap{flex:1;overflow:auto;border-radius:var(--radius-lg);border:1px solid var(--border);background:var(--bg-card);min-height:0}
.table-wrap::-webkit-scrollbar{width:6px;height:6px}
.table-wrap::-webkit-scrollbar-track{background:transparent}
.table-wrap::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:4px}

table#planilha{border-collapse:collapse;width:100%;min-width:1600px;font-size:12px}
table#planilha thead tr{position:sticky;top:0;z-index:10}
table#planilha th{background:#1a1c22;color:var(--accent-steel);font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding:9px 10px;white-space:nowrap;border-bottom:1px solid var(--border);border-right:1px solid var(--border)}
table#planilha th:last-child{border-right:none}
table#planilha td{padding:8px 10px;border-bottom:1px solid var(--border);border-right:1px solid var(--border-hover);color:var(--text-secondary);white-space:nowrap;max-width:220px;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}
table#planilha td:last-child{border-right:none}
table#planilha tbody tr:hover td{background:rgba(255,255,255,.025);color:var(--text-primary)}
table#planilha tr.selecionada td{background:rgba(160,174,192,.12)!important;color:var(--text-primary)!important}
table#planilha tr.linha-inativa td{background:rgba(248,113,113,.08)!important}
table#planilha tr.linha-movimentada td{background:rgba(250,204,21,.07)!important}
table#planilha tr.linha-rotina td{background:rgba(247,131,23,.08)!important}
table#planilha tr.editada td{outline:1px solid rgba(74,222,128,.3) inset}
table#planilha td[contenteditable="true"]{outline:2px solid rgba(74,222,128,.5);background:rgba(74,222,128,.05)!important;color:var(--text-primary)!important}

/* th-inner */
.th-inner{display:flex;align-items:center;gap:4px;min-width:70px}
.th-label{flex:1}
.th-actions{display:flex;gap:2px;flex-shrink:0}
.filtro-btn{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:3px;color:#fff;cursor:pointer;font-size:9px;padding:2px 4px;transition:background .15s;user-select:none}
.filtro-btn:hover{background:rgba(255,255,255,.3)}
.filtro-btn.ativo{background:#facc15;border-color:#d97706;color:#1e1e1e}
.pin-btn{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.25);border-radius:3px;color:rgba(255,255,255,.7);cursor:pointer;font-size:9px;padding:2px 4px;transition:background .15s;user-select:none}
.pin-btn:hover{background:rgba(255,255,255,.25)}
.pin-btn.fixada{background:#fbbf24;border-color:#f59e0b;color:#1e3a5f;font-weight:700}

/* colunas fixas */
th.col-fixa{position:sticky;z-index:11!important;background:#1a1c22}
td.col-fixa{position:sticky;z-index:9;background:var(--bg-card)}
tr.selecionada td.col-fixa{background:rgba(160,174,192,.12)!important}
tr.linha-inativa td.col-fixa{background:rgba(248,113,113,.08)!important}
tr.linha-movimentada td.col-fixa{background:rgba(250,204,21,.07)!important}

/* fill handle */
td{position:relative}
.fill-handle{display:none;position:absolute;right:0;bottom:0;width:8px;height:8px;background:#4ade80;cursor:crosshair;z-index:10;pointer-events:none}
td[contenteditable="true"] .fill-handle,td.fill-source .fill-handle{display:block;pointer-events:auto}
td.fill-source{outline:2px solid #4ade80!important}
td.fill-preview{outline:2px dashed #4ade80!important;background:rgba(74,222,128,.07)!important}

/* ── DROPDOWN FILTRO ── */
.filtro-dropdown{display:none;position:fixed;z-index:9000;background:#1e1e1e;border:1px solid var(--border-hover);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.5);min-width:220px;max-width:300px;font-size:13px;color:var(--text-primary);padding:8px 0 6px}
.filtro-dropdown.aberto{display:block}
/* Ordenação A→Z / Z→A dentro do dropdown de coluna */
.sort-section{display:flex;gap:5px;padding:0 8px 7px;margin-bottom:6px;border-bottom:1px solid var(--border)}
.sort-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:6px 4px;border:1px solid var(--border-hover);border-radius:5px;background:#282828;cursor:pointer;color:var(--text-secondary);font-size:11px;font-weight:500;font-family:var(--font-ui);transition:background var(--transition),border-color var(--transition),color var(--transition)}
.sort-btn:hover{background:#333;color:var(--text-primary)}
.sort-btn.ativo{background:rgba(74,222,128,.14);border-color:rgba(74,222,128,.4);color:#4ade80}
.sort-btn i{font-size:11px}
.filtro-btn.ordenada{background:#4ade80;border-color:#22c55e;color:#0f0f0f}
.filtro-search{display:block;width:calc(100% - 16px);margin:0 8px 6px;padding:6px 10px;border:1px solid var(--border-hover);border-radius:6px;font-size:12px;outline:none;background:#282828;color:var(--text-primary);font-family:var(--font-ui)}
.filtro-search:focus{border-color:rgba(255,255,255,.3)}
.filtro-search::placeholder{color:var(--text-muted)}
.filtro-actions{display:flex;gap:4px;padding:0 8px 6px;border-bottom:1px solid var(--border);margin-bottom:4px}
.filtro-actions button{flex:1;font-size:11px;padding:4px 0;border:1px solid var(--border-hover);border-radius:4px;background:#282828;cursor:pointer;color:var(--text-secondary);font-family:var(--font-ui)}
.filtro-actions button:hover{background:#333;color:var(--text-primary)}
.filtro-lista{max-height:200px;overflow-y:auto;padding:0 4px;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.filtro-item{display:flex;align-items:center;gap:7px;padding:4px 7px;border-radius:4px;cursor:pointer}
.filtro-item:hover{background:rgba(255,255,255,.05)}
.filtro-item input[type="checkbox"]{cursor:pointer;margin:0;flex-shrink:0;accent-color:#4ade80}
.filtro-item label{cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;font-size:12px;color:var(--text-secondary)}
.filtro-item.item-todos label{font-weight:600;color:var(--text-primary)}
.filtro-footer{display:flex;gap:6px;padding:6px 8px 0;border-top:1px solid var(--border);margin-top:4px}
.filtro-footer button{flex:1;padding:6px 0;border:none;border-radius:5px;font-size:12px;cursor:pointer;font-family:var(--font-ui)}
.btn-ok-filtro{background:#4ade80;color:#0f0f0f;font-weight:600}
.btn-ok-filtro:hover{background:#22c55e}
.btn-cancel-filtro{background:#282828;color:var(--text-secondary);border:1px solid var(--border-hover)!important}
.btn-cancel-filtro:hover{background:#333}
.filtro-loading{text-align:center;padding:12px;color:var(--text-muted);font-size:12px}

/* ── PAGINAÇÃO ── */
.paginacao{display:flex;gap:5px;justify-content:center;align-items:center;flex-wrap:wrap;flex-shrink:0;padding-top:4px}
.paginacao button{padding:5px 10px;font-size:12px;border-radius:6px;border:1px solid var(--border);background:#1c1c1c;color:var(--text-secondary);cursor:pointer;font-family:var(--font-ui);transition:all var(--transition)}
.paginacao button:hover:not(:disabled){background:#2a2a2a;color:var(--text-primary);border-color:var(--border-hover)}
.paginacao button.atual{background:#2a2a2a;color:var(--text-primary);border-color:var(--border-hover)}
.paginacao button:disabled{opacity:.35;cursor:not-allowed}

/* ── LOADING OVERLAY ── */
#loadingOverlay{position:fixed;inset:0;background:var(--bg-page);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:9999}
.loader-ring{width:48px;height:48px;border:4px solid rgba(255,255,255,.08);border-top-color:#4ade80;border-radius:50%;animation:spin .9s linear infinite;margin-bottom:14px}
@keyframes spin{to{transform:rotate(360deg)}}
#loadingOverlay p{color:var(--text-muted);font-size:13px}

/* ── TOAST ── */
#toast{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;align-items:center;gap:10px;background:#1e1e1e;border:1px solid var(--border-hover);border-radius:10px;padding:12px 18px;font-size:13px;color:var(--text-primary);box-shadow:0 8px 32px rgba(0,0,0,.5);opacity:0;transform:translateY(10px);pointer-events:none;transition:opacity .3s,transform .3s;max-width:340px}
#toast.show{opacity:1;transform:translateY(0);pointer-events:auto}
#toast.success{border-color:rgba(74,222,128,.4)}
#toast.success i{color:#4ade80}
#toast.error{border-color:rgba(248,113,113,.4)}
#toast.error i{color:#f87171}

/* ── FOOTER ── */
.footer{background:#181818;border-top:1px solid var(--border);padding:10px 20px;display:flex;justify-content:space-between;flex-wrap:wrap;font-size:12px;gap:6px;margin-left:var(--sidebar-w);transition:margin-left var(--transition);flex-shrink:0}
.footer div{color:#555}

::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.07);border-radius:4px}

@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeUp .3s ease both}

@media(max-width:900px){#main,footer{margin-left:var(--sidebar-collapsed)!important}#sidebar{width:var(--sidebar-collapsed)}#sidebar.open{width:var(--sidebar-w)}}
@media(max-width:640px){#sidebar{transform:translateX(-100%);position:fixed;top:0;left:0;height:100vh;z-index:1100;width:var(--sidebar-w)!important}#sidebar.open{transform:translateX(0)}.sidebar-toggle{display:none}.menu-toggle{display:block}#main{margin-left:0!important;height:100vh}.topbar{padding-left:52px}.footer{margin-left:0!important}}
</style>
<?php eng_clin_menu_css(); ?>
</head>
<body>

<div id="loadingOverlay">
  <div class="loader-ring"></div>
  <p>Carregando planilha...</p>
</div>

<?php eng_clin_menu_sidebar(); ?>

<div id="main">
  <header class="topbar">
    <div class="topbar-breadcrumb">
      <span>Engenharia Clínica</span>
      <i class="fas fa-chevron-right"></i>
      <span>Planilha de Equipamentos</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
      <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <!-- PAGE HEADER -->
    <div class="page-header fade-up">
      <div>
        <div class="page-title">Planilha de Equipamentos</div>
        <div class="page-subtitle">Engenharia Clínica &middot; Equipamentos Hospitalares &middot; <?= date('d/m/Y') ?></div>
      </div>
    </div>

    <!-- TOOLBAR -->
    <div class="toolbar fade-up">
      <div class="toolbar-left">
        <span class="contador-box" id="contadorBox">Carregando...</span>
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="inputPesquisa" placeholder="Pesquisar em todos os campos...">
        </div>
        <span class="banner-filtros" id="bannerFiltros"></span>
      </div>
      <div class="toolbar-right">
        <?php if ($podeEditar): ?>
        <button class="btn btn-success" id="btnSalvar" onclick="salvar()" disabled>
          <i class="fas fa-floppy-disk"></i> Salvar
        </button>
        <button class="btn btn-warn" id="btnDesfazer" onclick="desfazer()" disabled>
          <i class="fas fa-rotate-left"></i> Desfazer
        </button>
        <?php endif; ?>
        <button class="btn btn-ghost" onclick="location.reload()">
          <i class="fas fa-arrows-rotate"></i> Atualizar
        </button>
        <button class="btn btn-ghost" onclick="exportarXLSX()">
          <i class="fas fa-file-excel"></i> Exportar XLSX
        </button>
        <button class="btn btn-ghost" onclick="movimentar()" <?= !$podeMovimentar ? 'disabled' : '' ?> id="btnMovimentar">
          <i class="fas fa-arrow-right-arrow-left"></i> Movimentar
        </button>
        <button class="btn btn-danger" id="btnLimparFiltros" onclick="limparTodosFiltros()" disabled>
          <i class="fas fa-xmark"></i> Limpar Filtros
        </button>
      </div>
    </div>

    <!-- TABELA -->
    <div class="table-wrap" id="tableWrap">
      <table id="planilha">
        <thead>
          <tr>
            <?php foreach ($colunas as $titulo => $campo): ?>
            <th data-col="<?= $campo ?>">
              <div class="th-inner">
                <span class="th-label"><?= $titulo ?></span>
                <div class="th-actions">
                  <button class="pin-btn" data-col="<?= $campo ?>" title="Fixar">📌</button>
                  <button class="filtro-btn" data-col="<?= $campo ?>" title="Filtrar">▼</button>
                </div>
              </div>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody id="tblBody"></tbody>
      </table>
    </div>

    <!-- PAGINAÇÃO -->
    <div class="paginacao" id="paginacao"></div>

  </div><!-- /content -->
</div><!-- /main -->

<div class="footer" id="pageFooter">
  <div>Usuário: <?= htmlspecialchars($usuario) ?> &middot; Nível: <?= htmlspecialchars($nivel) ?> <?= !$podeEditar ? '&middot; <span style="color:var(--status-warn)">Somente leitura</span>' : '' ?></div>
  <div>Data: <?= $data ?> | Hora: <span id="hora"><?= $hora ?></span></div>
  <div>&copy; GK Soluções</div>
</div>

<!-- Dropdown filtro -->
<div class="filtro-dropdown" id="filtroDropdown">
  <div class="sort-section">
    <button class="sort-btn" id="sortAZ" title="Ordenar A → Z / 0 → 9">
      <i class="fas fa-arrow-down-a-z"></i> A → Z
    </button>
    <button class="sort-btn" id="sortZA" title="Ordenar Z → A / 9 → 0">
      <i class="fas fa-arrow-up-a-z"></i> Z → A
    </button>
  </div>
  <input type="text" class="filtro-search" id="filtroSearch" placeholder="Pesquisar valores...">
  <div class="filtro-actions">
    <button id="btnSelTodos">Selecionar tudo</button>
    <button id="btnLimparFiltro">Limpar</button>
  </div>
  <div class="filtro-lista" id="filtroLista"></div>
  <div class="filtro-footer">
    <button class="btn-ok-filtro"     id="btnOkFiltro">OK</button>
    <button class="btn-cancel-filtro" id="btnCancelFiltro">Cancelar</button>
  </div>
</div>

<div id="toast"><i class="fas fa-circle-check" id="toastIcon"></i><span id="toastMsg"></span></div>

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

/* ══════════════════════════════════════════
   CONFIG
══════════════════════════════════════════ */
const COLUNAS      = <?= json_encode(array_values($colunas), JSON_UNESCAPED_UNICODE) ?>;
const PODE_EDITAR  = <?= $podeEditar ? 'true' : 'false' ?>;
const POR_PAGINA   = 100;
const VAZIO        = '__vazio__';
const ENDPOINT     = 'eng_clin_planilha_dados.php';

const colunasRestritas = ['usuario_movimentacao','usuario_cadastro'];

/* ══════════════════════════════════════════
   ESTADO
══════════════════════════════════════════ */
let pagina          = 1;
let termo           = '';
let filtrosAtivos   = {};
let filtrosLike     = {};
let sortCol         = null;   // coluna ordenada
let sortDir         = null;   // 'asc' | 'desc'

let linhaSel        = null;
let linhasAlteradas = new Set();
let historico       = [];
let colunasFix      = new Set();
let fillSrc         = null;
let fillDrag        = false;
let fillPrevs       = [];

/* ══════════════════════════════════════════
   RELÓGIO
══════════════════════════════════════════ */
setInterval(() => { document.getElementById('hora').innerText = new Date().toLocaleTimeString('pt-BR'); }, 1000);

/* ══════════════════════════════════════════
   SIDEBAR
══════════════════════════════════════════ */
const sidebar    = document.getElementById('sidebar');
const mainArea   = document.getElementById('main');
const footer     = document.getElementById('pageFooter');
const toggleBtn  = document.getElementById('toggleBtn');
const toggleIcon = document.getElementById('toggleIcon');
function syncFooter(col) {
  if (footer) footer.style.marginLeft = col ? 'var(--sidebar-collapsed)' : 'var(--sidebar-w)';
}
if (toggleBtn) {
  toggleBtn.addEventListener('click', () => {
    const col = sidebar.classList.toggle('collapsed');
    mainArea.classList.toggle('sidebar-collapsed', col);
    toggleIcon.classList.toggle('fa-chevron-left', !col);
    toggleIcon.classList.toggle('fa-chevron-right', col);
    syncFooter(col);
  });
}
document.getElementById('menuToggle').onclick = () => {
  sidebar.classList.add('open');
  document.getElementById('sidebarOverlay').classList.add('open');
};
function fecharSidebar() {
  sidebar.classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('open');
}
sidebar.querySelectorAll('.nav-item').forEach(i => i.addEventListener('click', () => { if (window.innerWidth <= 640) fecharSidebar(); }));

/* ══════════════════════════════════════════
   TOAST
══════════════════════════════════════════ */
let toastTimer;
function showToast(msg, tipo = 'success') {
  const t = document.getElementById('toast');
  document.getElementById('toastMsg').innerText = msg;
  document.getElementById('toastIcon').className = tipo === 'success' ? 'fas fa-circle-check' : 'fas fa-circle-xmark';
  t.className = 'show ' + tipo;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.className = '', 3500);
}

/* ══════════════════════════════════════════
   HELPERS
══════════════════════════════════════════ */
function getCellVal(td) { const s = td.querySelector('.cell-text'); return s ? s.textContent.trim() : ''; }
function setCellVal(td, v) { const s = td.querySelector('.cell-text'); if (s) s.textContent = v; }

function formatarData(v) {
  if (!v) return '';
  if (!/^\d{4}-\d{2}-\d{2}/.test(v)) return v;
  const [dt, hr] = v.split(' ');
  const [a, m, d] = dt.split('-');
  return hr ? `${d}-${m}-${a} ${hr}` : `${d}-${m}-${a}`;
}
function normalizarData(v) {
  if (!v) return '';
  const mt = v.match(/^(\d{2})-(\d{2})-(\d{4})(.*)$/);
  return mt ? `${esc(mt[3])}-${esc(mt[2])}-${esc(mt[1])}${esc(mt[4] || '')}` : v;
}

/* ══════════════════════════════════════════
   QUERYSTRING
══════════════════════════════════════════ */
function buildQS(extras = {}) {
  const p = new URLSearchParams();
  p.set('pagina', pagina); p.set('porPagina', POR_PAGINA);
  if (termo) p.set('termo', termo);
  if (sortCol) { p.set('sort_col', sortCol); p.set('sort_dir', sortDir); }
  Object.entries(filtrosAtivos).forEach(([col, set]) => set.forEach(v => p.append(`filtros[${col}][]`, v === '' ? VAZIO : v)));
  Object.entries(filtrosLike).forEach(([col, t]) => p.set(`like[${col}]`, t));
  Object.entries(extras).forEach(([k, v]) => p.set(k, v));
  return p.toString();
}

function buildQSOpcoes(col) {
  const p = new URLSearchParams();
  p.set('modo', 'opcoes'); p.set('coluna', col);
  p.set('pagina', 1); p.set('porPagina', POR_PAGINA);
  if (termo) p.set('termo', termo);
  Object.entries(filtrosAtivos).forEach(([c, set]) => { if (c === col) return; set.forEach(v => p.append(`filtros[${c}][]`, v === '' ? VAZIO : v)); });
  Object.entries(filtrosLike).forEach(([c, t]) => { if (c !== col) p.set(`like[${c}]`, t); });
  return p.toString();
}

/* ══════════════════════════════════════════
   BUSCAR DADOS
══════════════════════════════════════════ */
function buscarDados() {
  fetch(ENDPOINT + '?' + buildQS())
    .then(r => r.json())
    .then(d => {
      renderTabela(d.linhas || []);
      renderPaginacao(d.total || 0);
      const ini = (pagina - 1) * POR_PAGINA + 1;
      const fim = Math.min(ini + (d.linhas?.length || 0) - 1, d.total || 0);
      document.getElementById('contadorBox').textContent =
        d.total > 0 ? `Exibindo ${ini}–${fim} de ${esc(d.total)}` : 'Nenhum resultado';
      document.getElementById('loadingOverlay').style.display = 'none';
      recalcFixas();
    })
    .catch(() => { document.getElementById('loadingOverlay').style.display = 'none'; });
}

/* ══════════════════════════════════════════
   RENDER TABELA
══════════════════════════════════════════ */
function renderTabela(linhas) {
  const tbody = document.getElementById('tblBody');
  tbody.innerHTML = '';
  linhaSel = null;

  linhas.forEach(linha => {
    const tr = document.createElement('tr');
    tr.dataset.id = linha.id;

    const status  = (linha.status  ?? '').toUpperCase().trim();
    const movDef  = (linha.movimentado_definitivo ?? '').toUpperCase().trim();
    const mov     = (linha.movimentado ?? '').toUpperCase().trim();
    const unidade = (linha.unidade ?? '').trim().toUpperCase();
    const setor   = (linha.setor   ?? '').trim().toUpperCase();
    const uDest   = (linha.unidade_destino ?? '').trim().toUpperCase();
    const sDest   = (linha.setor_destino   ?? '').trim().toUpperCase();
    const movimentado   = movDef === 'SIM' || mov === 'SIM';
    const destinoIgual  = uDest && sDest && unidade === uDest && setor === sDest;
    const naoloc = (linha.encontrado ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toUpperCase().trim();

    if (status === 'BAIXADO')                    tr.classList.add('linha-inativa');
    else if (movimentado && !destinoIgual)        tr.classList.add('linha-movimentada');
    else if (naoloc === 'NAO')                    tr.classList.add('linha-rotina');

    COLUNAS.forEach(c => {
      const td   = document.createElement('td');
      td.dataset.coluna = c;
      td.contentEditable = 'false';

      const span = document.createElement('span');
      span.className = 'cell-text';
      let val = formatarData(linha[c] ?? '');
      val = val ? val.toString().toUpperCase() : '';
      span.textContent = val;
      td.dataset.original = val;
      td.appendChild(span);

      const handle = document.createElement('div');
      handle.className = 'fill-handle';
      td.appendChild(handle);

      td.addEventListener('click', () => selecionarLinha(tr));

      if (PODE_EDITAR) {
        td.addEventListener('dblclick', () => {
          const restrita = colunasRestritas.includes(c);
          if (restrita) return;
          span.contentEditable = 'true';
          td.classList.add('fill-source');
          fillSrc = td;
          span.focus();
          const rng = document.createRange(); rng.selectNodeContents(span); rng.collapse(false);
          window.getSelection().removeAllRanges(); window.getSelection().addRange(rng);
        });

        span.addEventListener('blur', () => {
          setTimeout(() => {
            if (fillDrag) return;
            span.contentEditable = 'false';
            td.classList.remove('fill-source');
            registrarAlteracao(td, tr);
          }, 80);
        });

        span.addEventListener('keydown', e => {
          if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); span.blur(); }
          if (e.key === 'Escape') { span.textContent = td.dataset.original; span.blur(); }
        });

        handle.addEventListener('mousedown', e => { e.preventDefault(); e.stopPropagation(); fillSrc = td; fillDrag = true; });
      }

      tr.appendChild(td);
    });

    tbody.appendChild(tr);
  });

  recalcFixas();
}

/* ── Fill-down ── */
document.addEventListener('mousemove', e => {
  if (!fillDrag || !fillSrc) return;
  fillPrevs.forEach(el => el.classList.remove('fill-preview'));
  fillPrevs = [];
  const alvo = document.elementFromPoint(e.clientX, e.clientY);
  if (!alvo) return;
  const tdAlvo = alvo.closest('td');
  if (!tdAlvo) return;
  const colIdx  = Array.from(fillSrc.parentElement.children).indexOf(fillSrc);
  const trIni   = fillSrc.parentElement;
  const trFim   = tdAlvo.closest('tr');
  if (!trFim) return;
  const rows = Array.from(document.getElementById('tblBody').querySelectorAll('tr'));
  const iIni = rows.indexOf(trIni), iFim = rows.indexOf(trFim);
  if (iIni === -1 || iFim === -1 || iFim <= iIni) return;
  for (let i = iIni + 1; i <= iFim; i++) {
    const td2 = rows[i].children[colIdx];
    if (td2) { td2.classList.add('fill-preview'); fillPrevs.push(td2); }
  }
});

document.addEventListener('mouseup', () => {
  if (!fillDrag || !fillSrc) { fillDrag = false; return; }
  const valor   = getCellVal(fillSrc);
  const colIdx  = Array.from(fillSrc.parentElement.children).indexOf(fillSrc);
  fillPrevs.forEach(td => {
    td.classList.remove('fill-preview');
    const tr = td.closest('tr');
    const ant = getCellVal(td);
    setCellVal(td, valor);
    historico.push({ td, anterior: ant });
    tr.classList.add('editada'); linhasAlteradas.add(tr);
  });
  fillPrevs = []; fillDrag = false;
  fillSrc.contentEditable = 'false'; fillSrc.classList.remove('fill-source'); fillSrc = null;
  atualizarBtns();
});

/* ══════════════════════════════════════════
   ALTERAÇÕES
══════════════════════════════════════════ */
function registrarAlteracao(td, tr) {
  const atual = getCellVal(td), orig = td.dataset.original ?? '';
  if (atual !== orig) {
    historico.push({ td, anterior: orig });
    td.dataset.original = atual;
    tr.classList.add('editada'); linhasAlteradas.add(tr);
    atualizarBtns();
  }
}
function atualizarBtns() {
  const btnS = document.getElementById('btnSalvar');
  const btnD = document.getElementById('btnDesfazer');
  if (btnS) btnS.disabled = linhasAlteradas.size === 0;
  if (btnD) btnD.disabled = historico.length === 0;
}
function desfazer() {
  if (!historico.length) return;
  const { td, anterior } = historico.pop();
  const tr = td.closest('tr');
  setCellVal(td, anterior); td.dataset.original = anterior;
  const todasOrig = Array.from(tr.querySelectorAll('td')).every(c => getCellVal(c) === (c.dataset.original ?? ''));
  if (todasOrig) { tr.classList.remove('editada'); linhasAlteradas.delete(tr); }
  atualizarBtns();
}

/* ══════════════════════════════════════════
   SELECIONAR
══════════════════════════════════════════ */
function selecionarLinha(tr) {
  document.querySelectorAll('#tblBody tr').forEach(r => r.classList.remove('selecionada'));
  tr.classList.add('selecionada'); linhaSel = tr;
}

/* ══════════════════════════════════════════
   PAGINAÇÃO
══════════════════════════════════════════ */
function renderPaginacao(total) {
  const cont = document.getElementById('paginacao');
  cont.innerHTML = '';
  const pags = Math.ceil(total / POR_PAGINA);
  if (pags <= 1) return;
  const prev = document.createElement('button');
  prev.textContent = '‹'; prev.disabled = pagina === 1;
  prev.onclick = () => { pagina--; buscarDados(); };
  cont.appendChild(prev);
  let ini = Math.max(1, pagina - 2), fim = Math.min(pags, ini + 4);
  for (let i = ini; i <= fim; i++) {
    const b = document.createElement('button');
    b.textContent = i;
    if (i === pagina) b.classList.add('atual');
    b.onclick = () => { pagina = i; buscarDados(); };
    cont.appendChild(b);
  }
  const next = document.createElement('button');
  next.textContent = '›'; next.disabled = pagina === pags;
  next.onclick = () => { pagina++; buscarDados(); };
  cont.appendChild(next);
}

/* ══════════════════════════════════════════
   PESQUISA
══════════════════════════════════════════ */
const inputPesq = document.getElementById('inputPesquisa');
function disparaBusca() { termo = inputPesq.value.trim(); pagina = 1; atualizarBotoesFiltro(); buscarDados(); }
inputPesq.addEventListener('keydown', e => { if (e.key === 'Enter') disparaBusca(); });
inputPesq.addEventListener('input',   () => { if (!inputPesq.value) { termo = ''; pagina = 1; atualizarBotoesFiltro(); buscarDados(); } });

/* ══════════════════════════════════════════
   SALVAR
══════════════════════════════════════════ */
function salvar() {
  if (!linhasAlteradas.size) { showToast('Nenhuma alteração para salvar.', 'error'); return; }
  const dados = [];
  linhasAlteradas.forEach(tr => {
    const row = [tr.dataset.id];
    Array.from(tr.querySelectorAll('td')).forEach(td => row.push(normalizarData(getCellVal(td))));
    dados.push(row);
  });
  fetch('salvar_planilha.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ dados })
  })
  .then(r => r.json())
  .then(j => {
    showToast(j.mensagem || j.erro_fatal || 'Salvo.', j.mensagem ? 'success' : 'error');
    if (j.mensagem) { linhasAlteradas.clear(); historico = []; atualizarBtns(); }
  })
  .catch(() => showToast('Erro de conexão.', 'error'));
}

/* ══════════════════════════════════════════
   MOVIMENTAR
══════════════════════════════════════════ */
function movimentar() {
  if (!linhaSel) { showToast('Selecione um item primeiro.', 'error'); return; }
  const id = linhaSel.dataset.id;
  if (!id) { showToast('ID não encontrado.', 'error'); return; }
  location.href = 'eng_clin_movimentar.php?id=' + encodeURIComponent(id);
}

/* ══════════════════════════════════════════
   EXPORTAR XLSX (via SheetJS via servidor)
══════════════════════════════════════════ */
function exportarXLSX() {
  fetch(ENDPOINT + '?' + buildQS({ modo: 'exportar' }))
    .then(r => r.json())
    .then(rows => {
      if (!rows.length) { showToast('Nenhum dado para exportar.', 'error'); return; }
      const gerarArquivo = () => {
        const headers = <?= json_encode(array_keys($colunas)) ?>;
        const cols    = <?= json_encode(array_values($colunas)) ?>;
        const wsData  = [headers, ...rows.map(r => cols.map(c => r[c] ?? ''))];
        const ws      = XLSX.utils.aoa_to_sheet(wsData);
        const wb      = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Equipamentos');
        XLSX.writeFile(wb, 'equipamentos_hospitalares.xlsx');
        showToast('Arquivo exportado com sucesso!', 'success');
      };
      if (window.XLSX) { gerarArquivo(); return; }
      const script = document.createElement('script');
      script.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
      script.onload = gerarArquivo;
      document.head.appendChild(script);
    })
    .catch(() => showToast('Erro ao exportar.', 'error'));
}

/* ══════════════════════════════════════════
   COLUNAS FIXAS
══════════════════════════════════════════ */
function recalcFixas() {
  const ths = document.querySelectorAll('#planilha thead th');
  let acum = 0;
  ths.forEach((th, idx) => {
    const col = th.dataset.col, fixa = colunasFix.has(col);
    if (fixa) {
      th.classList.add('col-fixa'); th.style.left = acum + 'px';
    } else {
      th.classList.remove('col-fixa'); th.style.left = ''; th.style.position = '';
    }
    document.querySelectorAll(`#planilha tbody td:nth-child(${idx + 1})`).forEach(td => {
      if (fixa) { td.classList.add('col-fixa'); td.style.left = acum + 'px'; }
      else { td.classList.remove('col-fixa'); td.style.left = ''; td.style.position = ''; }
    });
    const pin = th.querySelector('.pin-btn');
    if (pin) pin.classList.toggle('fixada', fixa);
    if (fixa) acum += th.offsetWidth;
  });
}

document.querySelectorAll('.pin-btn').forEach(btn => {
  btn.addEventListener('click', e => {
    e.stopPropagation();
    const col = btn.dataset.col;
    if (colunasFix.has(col)) colunasFix.delete(col); else colunasFix.add(col);
    recalcFixas();
  });
});
window.addEventListener('resize', () => { recalcFixas(); posicionarDropdown(); });

/* ══════════════════════════════════════════
   FILTRO DROPDOWN
══════════════════════════════════════════ */
const dropdown   = document.getElementById('filtroDropdown');
const filtroSrch = document.getElementById('filtroSearch');
const filtroList = document.getElementById('filtroLista');
const colFiltAti = new Set();
let ddColAtual = null, ddOpcoes = [], ddSel = new Set(), ddSnap = null;

function posicionarDropdown(btn) {
  if (!btn || !dropdown.classList.contains('aberto')) return;
  const rect = btn.getBoundingClientRect(), ddW = 240;
  let left = rect.left, top = rect.bottom + 2;
  if (left + ddW > window.innerWidth - 8) left = window.innerWidth - ddW - 8;
  dropdown.style.left = left + 'px'; dropdown.style.top = top + 'px'; dropdown.style.width = ddW + 'px';
}

function ordenarOpcoes(arr) {
  const v = arr.filter(o => o === '');
  const n = arr.filter(o => o !== '').sort((a, b) => { const na = parseFloat(a), nb = parseFloat(b); return (!isNaN(na) && !isNaN(nb)) ? na - nb : String(a).localeCompare(String(b), 'pt-BR', {sensitivity:'base'}); });
  return [...v, ...n];
}

function renderLista(t) {
  const termo2 = t.toLowerCase();
  const vis    = ddOpcoes.filter(o => (o === '' ? '(vazio)' : String(o).toLowerCase()).includes(termo2));
  filtroList.innerHTML = '';
  if (!termo2) { const tm = vis.length > 0 && vis.every(o => ddSel.has(o)); filtroList.appendChild(mkItem('__todos__', '(Selecionar Tudo)', tm, true)); }
  if (!vis.length) { filtroList.innerHTML = '<div class="filtro-loading">Nenhum resultado</div>'; return; }
  vis.forEach(o => filtroList.appendChild(mkItem(o, o === '' ? '(Vazio)' : o, ddSel.has(o), false)));
}

function mkItem(val, lbl, chk, isTodos) {
  const uid = 'fc_' + Math.random().toString(36).slice(2);
  const div = document.createElement('div'); div.className = 'filtro-item' + (isTodos ? ' item-todos' : '');
  const cb  = document.createElement('input'); cb.type = 'checkbox'; cb.id = uid; cb.checked = chk;
  const lb  = document.createElement('label'); lb.htmlFor = uid; lb.textContent = lbl; lb.title = lbl;
  cb.addEventListener('change', () => {
    if (isTodos) {
      const t2 = filtroSrch.value.toLowerCase();
      const vs = ddOpcoes.filter(o => (o===''?'(vazio)':String(o).toLowerCase()).includes(t2));
      vs.forEach(o => cb.checked ? ddSel.add(o) : ddSel.delete(o));
      renderLista(filtroSrch.value);
    } else {
      cb.checked ? ddSel.add(val) : ddSel.delete(val);
      sincTodos();
    }
  });
  div.appendChild(cb); div.appendChild(lb); return div;
}

function sincTodos() {
  const ct = filtroList.querySelector('.item-todos input'); if (!ct) return;
  const t2 = filtroSrch.value.toLowerCase();
  const vs = ddOpcoes.filter(o => (o===''?'(vazio)':String(o).toLowerCase()).includes(t2));
  ct.checked = vs.length > 0 && vs.every(o => ddSel.has(o));
}

let ddBtnAtual = null;
function abrirDropdown(btn, col) {
  if (ddColAtual === col && dropdown.classList.contains('aberto')) { fecharDropdown(false); return; }
  ddColAtual = col; ddBtnAtual = btn;
  filtroSrch.value = ''; filtroList.innerHTML = '<div class="filtro-loading">Carregando...</div>';
  dropdown.classList.add('aberto');
  sincronizarBotoesSort();
  posicionarDropdown(btn);
  fetch(ENDPOINT + '?' + buildQSOpcoes(col))
    .then(r => r.json())
    .then(ops => {
      ddOpcoes = ordenarOpcoes(ops);
      const salvo = filtrosAtivos[col];
      if (salvo?.size > 0) { const opSet = new Set(ddOpcoes); ddSel = new Set([...salvo].filter(v => opSet.has(v))); ddSnap = new Set(ddSel); }
      else { ddSel = new Set(ddOpcoes); ddSnap = null; }
      renderLista('');
    })
    .catch(() => { filtroList.innerHTML = '<div class="filtro-loading">Erro</div>'; });
}

function fecharDropdown(salvar) {
  if (salvar && ddColAtual) {
    const col = ddColAtual, t2 = filtroSrch.value.trim();
    delete filtrosAtivos[col]; delete filtrosLike[col]; colFiltAti.delete(col);
    if (t2) {
      const vis = ddOpcoes.filter(o => (o===''?'(vazio)':String(o).toLowerCase()).includes(t2.toLowerCase()));
      if (vis.length && vis.every(o => ddSel.has(o))) { filtrosLike[col] = t2; colFiltAti.add(col); }
      else { const sv = vis.filter(o => ddSel.has(o)); if (sv.length) { filtrosAtivos[col] = new Set(sv); colFiltAti.add(col); } }
    } else {
      const todM = ddOpcoes.length > 0 && ddOpcoes.every(o => ddSel.has(o));
      if (!todM && ddSel.size > 0) { filtrosAtivos[col] = new Set(ddSel); colFiltAti.add(col); }
    }
    atualizarBotoesFiltro(); pagina = 1; buscarDados();
  }
  dropdown.classList.remove('aberto'); ddColAtual = null;
}

/* ══════════════════════════════════════════
   ORDENAÇÃO A→Z / Z→A
══════════════════════════════════════════ */
function sincronizarBotoesSort() {
  const az = document.getElementById('sortAZ');
  const za = document.getElementById('sortZA');
  const naCol = sortCol !== null && sortCol === ddColAtual;
  az.classList.toggle('ativo', naCol && sortDir === 'asc');
  za.classList.toggle('ativo', naCol && sortDir === 'desc');
}

function aplicarSort(dir) {
  if (!ddColAtual) return;
  // Clicar de novo na mesma direção remove a ordenação
  if (sortCol === ddColAtual && sortDir === dir) { sortCol = null; sortDir = null; }
  else { sortCol = ddColAtual; sortDir = dir; }
  sincronizarBotoesSort();
  atualizarBotoesFiltro();
  pagina = 1; buscarDados();
}

function atualizarBotoesFiltro() {
  document.querySelectorAll('.filtro-btn').forEach(b => {
    const col = b.dataset.col;
    const temFiltro = colFiltAti.has(col);
    const temSort   = sortCol === col;
    b.classList.toggle('ativo', temFiltro);
    b.classList.toggle('ordenada', !temFiltro && temSort);
    b.title = temFiltro ? 'Filtro ativo — clique para alterar'
            : temSort   ? `Ordenado (${sortDir === 'asc' ? 'A→Z' : 'Z→A'}) — clique para alterar`
                        : 'Filtrar / Ordenar';
  });
  const temAlgo = colFiltAti.size > 0 || termo !== '' || sortCol !== null;
  document.getElementById('btnLimparFiltros').disabled = !temAlgo;
  const ban = document.getElementById('bannerFiltros');
  const partes = [];
  if (colFiltAti.size > 0) partes.push(`${esc(colFiltAti.size)} filtro(s) ativo(s)`);
  if (sortCol) partes.push(`ordenado por ${sortCol.replace(/_/g, ' ')} ${sortDir === 'asc' ? 'A→Z' : 'Z→A'}`);
  if (partes.length) { ban.textContent = partes.join(' · '); ban.classList.add('vis'); }
  else { ban.classList.remove('vis'); }
}

function limparTodosFiltros() {
  filtrosAtivos = {}; filtrosLike = {}; termo = ''; colFiltAti.clear();
  sortCol = null; sortDir = null;
  inputPesq.value = '';
  if (dropdown.classList.contains('aberto')) fecharDropdown(false);
  pagina = 1; atualizarBotoesFiltro(); buscarDados();
}

document.getElementById('sortAZ').addEventListener('click', e => { e.stopPropagation(); aplicarSort('asc'); });
document.getElementById('sortZA').addEventListener('click', e => { e.stopPropagation(); aplicarSort('desc'); });


filtroSrch.addEventListener('input', () => renderLista(filtroSrch.value));
document.getElementById('btnSelTodos').addEventListener('click', () => { const t2 = filtroSrch.value.toLowerCase(); ddOpcoes.filter(o => (o===''?'(vazio)':String(o).toLowerCase()).includes(t2)).forEach(o => ddSel.add(o)); renderLista(filtroSrch.value); });
document.getElementById('btnLimparFiltro').addEventListener('click', () => { ddSel.clear(); renderLista(filtroSrch.value); });
document.getElementById('btnOkFiltro').addEventListener('click', () => fecharDropdown(true));
document.getElementById('btnCancelFiltro').addEventListener('click', () => { ddSel = ddSnap === null ? new Set(ddOpcoes) : new Set(ddSnap); fecharDropdown(false); });

document.addEventListener('mousedown', e => {
  if (!dropdown.contains(e.target) && !e.target.classList.contains('filtro-btn') && !e.target.classList.contains('pin-btn') && !e.target.closest('.sort-btn')) {
    if (dropdown.classList.contains('aberto')) fecharDropdown(false);
  }
});
document.getElementById('tableWrap').addEventListener('scroll', () => { if (dropdown.classList.contains('aberto')) fecharDropdown(false); });
document.querySelectorAll('.filtro-btn').forEach(btn => btn.addEventListener('click', e => { e.stopPropagation(); abrirDropdown(btn, btn.dataset.col); }));

/* ══════════════════════════════════════════
   INIT
══════════════════════════════════════════ */
buscarDados();

/* ── Heartbeat ── */
(function hb() {
  fetch('heartbeat.php?_=' + Date.now(), { method:'POST', credentials:'same-origin', cache:'no-store' })
    .then(r => r.json()).then(d => { if (d.revogada) location.href = 'index.html?error=sessao+encerrada'; }).catch(() => {});
  setTimeout(hb, 30000);
})();
</script>
<?php eng_clin_menu_painel(true); ?>
</body>
</html>