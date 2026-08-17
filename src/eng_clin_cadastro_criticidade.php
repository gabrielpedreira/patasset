<?php
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = '';   // item ativo no menu lateral
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
$nivel = 'C'; $classe_usuario = ''; $status_u = 'ATIVO';
if ($r = $res->fetch_assoc()) {
    $nivel          = strtoupper(trim($r['permicao']));
    $classe_usuario = strtoupper(trim($r['classe_usuario']));
    $status_u       = $r['status'] ?? 'ATIVO';
}
$stmt->close();

if ($status_u !== 'ATIVO') { session_destroy(); header("Location: index.html?erro=bloqueado"); exit(); }
if (!in_array($classe_usuario, ['DEV','ENGENHARIA CLINICA']) || !in_array($nivel, ['A','B'])) {
    header("Location: acesso_bloqueado.html"); exit();
}

$msg = ''; $msg_tipo = '';

// ── CADASTRAR novo ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cadastrar') {
    $desc  = trim($_POST['descricao_item']  ?? '');
    $prio  = trim($_POST['criticidade_nivel'] ?? '');
    $ativo = ($_POST['ativo'] ?? '1') === '1' ? 1 : 0;
    $validas = ['ALTA','MEDIA','BAIXA'];

    if ($desc === '') {
        $msg = 'A descrição do item é obrigatória.'; $msg_tipo = 'erro';
    } elseif (!in_array($prio, $validas)) {
        $msg = 'Selecione um nível de criticidade válido.'; $msg_tipo = 'erro';
    } else {
        // Verificar duplicidade
        $stD = $conn->prepare("SELECT id FROM criticidade_item_engclin WHERE descricao_item=? LIMIT 1");
        $stD->bind_param('s', $desc); $stD->execute();
        $dup = $stD->get_result()->num_rows; $stD->close();

        if ($dup > 0) {
            $msg = 'Já existe um item com esta descrição.'; $msg_tipo = 'erro';
        } else {
            $stI = $conn->prepare("INSERT INTO criticidade_item_engclin (descricao_item, criticidade_nivel, ativo) VALUES (?,?,?)");
            $stI->bind_param('ssi', $desc, $prio, $ativo);
            $stI->execute()
                ? ($msg = 'Item cadastrado com sucesso!') && ($msg_tipo = 'ok')
                : ($msg = 'Erro ao cadastrar: '.$stI->error) && ($msg_tipo = 'erro');
            $stI->close();
        }
    }
}

// ── EDITAR existente ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'editar') {
    $id    = intval($_POST['id'] ?? 0);
    $desc  = trim($_POST['descricao_item']   ?? '');
    $prio  = trim($_POST['criticidade_nivel'] ?? '');
    $ativo = ($_POST['ativo'] ?? '1') === '1' ? 1 : 0;
    $validas = ['ALTA','MEDIA','BAIXA'];

    if ($id <= 0 || $desc === '' || !in_array($prio, $validas)) {
        $msg = 'Dados inválidos para edição.'; $msg_tipo = 'erro';
    } else {
        $stU = $conn->prepare("UPDATE criticidade_item_engclin SET descricao_item=?, criticidade_nivel=?, ativo=? WHERE id=?");
        $stU->bind_param('ssii', $desc, $prio, $ativo, $id);
        $stU->execute()
            ? ($msg = 'Item atualizado com sucesso!') && ($msg_tipo = 'ok')
            : ($msg = 'Erro ao atualizar: '.$stU->error) && ($msg_tipo = 'erro');
        $stU->close();
    }
}

// ── Listar todos ──────────────────────────────────────────────────────
$lista = [];
$resL = $conn->query("SELECT * FROM criticidade_item_engclin ORDER BY FIELD(criticidade_nivel,'ALTA','MEDIA','BAIXA'), descricao_item ASC");
if ($resL) while ($r = $resL->fetch_assoc()) $lista[] = $r;

$conn->close();

date_default_timezone_set('America/Sao_Paulo');
$data = date('d/m/Y');
$hora = date('H:i:s');

$total    = count($lista);
$ativos   = count(array_filter($lista, fn($r) => $r['ativo'] == 1));
$inativas = $total - $ativos;
$altas    = count(array_filter($lista, fn($r) => $r['criticidade_nivel'] === 'ALTA'));
$medias   = count(array_filter($lista, fn($r) => $r['criticidade_nivel'] === 'MEDIA'));
$baixas   = count(array_filter($lista, fn($r) => $r['criticidade_nivel'] === 'BAIXA'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Criticidades de Itens — Engenharia Clínica</title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="shortcut icon" href="logo_1.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --bg-page:#0f0f0f; --bg-sidebar:#141414; --bg-card:#1a1a1a;
  --bg-card-hover:#1f1f1f; --bg-input:#222;
  --border:rgba(255,255,255,0.07); --border-hover:rgba(255,255,255,0.14);
  --accent-steel:#a0aec0;
  --text-primary:#f0f0f0; --text-secondary:#888; --text-muted:#555;
  --sidebar-w:260px; --sidebar-collapsed:68px; --header-h:56px;
  --radius:10px; --radius-lg:16px;
  --transition:0.22s cubic-bezier(0.4,0,0.2,1);
  --font-ui:'DM Sans',sans-serif; --font-display:'Syne',sans-serif;
  --status-ok:#4ade80; --status-warn:#facc15; --status-err:#f87171;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-ui);background:var(--bg-page);color:var(--text-primary);min-height:100vh;overflow-x:hidden;display:flex;flex-direction:column}

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
#sidebar.collapsed .nav-item[data-tooltip="Dashboard"]::before        { content:"\f200" }
#sidebar.collapsed .nav-item[data-tooltip="Ordem de Serviço"]::before { content:"\f46d" }
#sidebar.collapsed .nav-item[data-tooltip="Estoque"]::before          { content:"\f49e" }
#sidebar.collapsed .nav-item[data-tooltip="Criticidades"]::before      { content:"\f522" }
#sidebar.collapsed .nav-item[data-tooltip="Sair"]::before             { content:"\f2f5" }

/* ── MAIN ── */
#main{margin-left:var(--sidebar-w);transition:margin-left var(--transition);min-height:calc(100vh - 42px);display:flex;flex-direction:column;flex:1}
#main.sidebar-collapsed{margin-left:var(--sidebar-collapsed)}

/* ── TOPBAR ── */
.topbar{height:var(--header-h);background:rgba(20,20,20,0.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:10px;position:sticky;top:0;z-index:50}
.topbar-breadcrumb{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted)}
.topbar-breadcrumb span:last-child{color:var(--text-primary);font-weight:500}
.topbar-breadcrumb i{font-size:10px}
.topbar-spacer{flex:1}
.topbar-logo-rede{height:32px;width:auto;object-fit:contain;opacity:.75;transition:opacity var(--transition);flex-shrink:0}
.topbar-logo-rede:hover{opacity:1}

/* ── CONTENT ── */
.content{flex:1;padding:28px;width:100%}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
.page-title{font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--text-primary);letter-spacing:-.01em}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}

/* ── BOTÕES ── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;white-space:nowrap}
.btn-primary{background:#232323;border:1px solid rgba(255,255,255,0.13);color:var(--text-primary)}
.btn-primary:hover{background:#2e2e2e;border-color:rgba(255,255,255,.2)}
.btn-success{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.25);color:#4ade80}
.btn-success:hover{background:rgba(74,222,128,.18);border-color:rgba(74,222,128,.4)}
.btn-back{background:rgba(160,174,192,.08);border:1px solid rgba(160,174,192,.18);color:var(--accent-steel)}
.btn-back:hover{background:rgba(160,174,192,.15);border-color:rgba(160,174,192,.3)}

/* ── ALERT ── */
.alert{padding:11px 16px;border-radius:8px;font-size:13px;font-weight:500;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.alert-ok  {background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.2);color:#4ade80}
.alert-erro{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.2);color:#f87171}

/* ── MINI KPIs ── */
.kpi-row{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap}
.kpi-chip{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:12px 18px;display:flex;align-items:center;gap:12px;transition:border-color var(--transition)}
.kpi-chip:hover{border-color:var(--border-hover)}
.kpi-chip-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.kpi-chip-val{font-family:var(--font-display);font-size:22px;font-weight:700;line-height:1;letter-spacing:-.02em}
.kpi-chip-lbl{font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-top:1px}
.kc-total  .kpi-chip-icon{background:rgba(160,174,192,.1);color:var(--accent-steel)}
.kc-alta   .kpi-chip-icon{background:rgba(248,113,113,.1);color:#f87171}
.kc-media  .kpi-chip-icon{background:rgba(250,204,21,.1);color:#facc15}
.kc-baixa  .kpi-chip-icon{background:rgba(74,222,128,.1);color:#4ade80}
.kc-inatv  .kpi-chip-icon{background:rgba(255,255,255,.05);color:var(--text-muted)}

/* ── LAYOUT COLUNAS ── */
.two-col{display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start}

/* ── FORM CARD ── */
.form-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;transition:border-color var(--transition);position:sticky;top:calc(var(--header-h) + 16px)}
.form-card:hover{border-color:var(--border-hover)}
.card-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.02)}
.card-icon{width:28px;height:28px;border-radius:7px;background:rgba(255,255,255,.05);display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--accent-steel);flex-shrink:0}
.card-title{font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--accent-steel);flex:1}
.card-body{padding:20px}

/* ── CAMPOS ── */
.field{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.field:last-of-type{margin-bottom:0}
.field-label{font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted);display:flex;align-items:center;gap:4px}
.field-label .req{color:var(--status-err)}
.field input,.field select{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:10px 13px;font-family:var(--font-ui);font-size:13px;color:var(--text-primary);outline:none;transition:border-color var(--transition),background var(--transition);width:100%;-webkit-appearance:none;appearance:none}
.field input:focus,.field select:focus{border-color:var(--border-hover);background:#272727}
.field input::placeholder{color:var(--text-muted)}
.field select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23666' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;background-size:11px;padding-right:32px;cursor:pointer}
.field select option{background:#1e1e1e;color:#f0f0f0}

/* preview de criticidade no form */
.prio-preview{display:none;align-items:center;gap:6px;margin-top:6px}
.prio-dot{width:8px;height:8px;border-radius:50%}
.prio-preview-lbl{font-size:11px;font-weight:600;letter-spacing:.04em}

/* ── TABELA ── */
.table-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;transition:border-color var(--transition)}
.table-card:hover{border-color:var(--border-hover)}
.table-toolbar{padding:13px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:rgba(255,255,255,.02)}
.table-toolbar-title{font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--accent-steel);flex:1;display:flex;align-items:center;gap:8px}
.tbl-count{background:rgba(160,174,192,.12);color:var(--accent-steel);font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px}
.search-box{display:flex;align-items:center;gap:8px;background:var(--bg-input);border:1px solid var(--border);border-radius:7px;padding:6px 11px;transition:border-color var(--transition);min-width:190px}
.search-box:focus-within{border-color:var(--border-hover)}
.search-box i{font-size:11px;color:var(--text-muted);flex-shrink:0}
.search-box input{background:none;border:none;outline:none;font-size:12px;color:var(--text-primary);font-family:var(--font-ui);width:100%}
.search-box input::placeholder{color:var(--text-muted)}
.filter-sel{background:var(--bg-input);border:1px solid var(--border);border-radius:7px;padding:7px 28px 7px 10px;font-family:var(--font-ui);font-size:12px;color:var(--text-primary);outline:none;-webkit-appearance:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23666' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;background-size:11px;cursor:pointer;transition:border-color var(--transition)}
.filter-sel:focus{border-color:var(--border-hover)}
.filter-sel option{background:#1e1e1e}

.table-wrap{overflow-x:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
table.main-table{width:100%;border-collapse:collapse;font-size:12px;min-width:600px}
table.main-table thead tr{border-bottom:1px solid var(--border)}
table.main-table th{padding:9px 13px;text-align:left;font-size:10px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--text-muted);white-space:nowrap;background:rgba(255,255,255,.015)}
table.main-table td{padding:9px 13px;color:var(--text-secondary);border-bottom:1px solid var(--border);vertical-align:middle}
table.main-table tbody tr:last-child td{border-bottom:none}
table.main-table tbody tr:hover td{background:rgba(255,255,255,.02);color:var(--text-primary)}
table.main-table td.td-primary{color:var(--text-primary);font-weight:500}

/* Edição inline */
.td-edit input,.td-edit select{background:var(--bg-input);border:1px solid var(--border-hover);border-radius:6px;padding:5px 8px;font-family:var(--font-ui);font-size:12px;color:var(--text-primary);outline:none;width:100%;min-width:80px;-webkit-appearance:none;appearance:none}
.td-edit select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23666' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 7px center;background-size:10px;padding-right:24px;cursor:pointer}
.td-edit select option{background:#1e1e1e}
.td-edit input:focus,.td-edit select:focus{border-color:rgba(255,255,255,.28)}

/* Criticidade badges */
.crit-badge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.crit-alta {background:rgba(248,113,113,.1);color:#f87171;border:1px solid rgba(248,113,113,.2)}
.crit-media{background:rgba(250,204,21,.1);color:#facc15;border:1px solid rgba(250,204,21,.2)}
.crit-baixa{background:rgba(74,222,128,.1);color:#4ade80;border:1px solid rgba(74,222,128,.2)}

/* Ativo badges */
.ativo-sim{background:rgba(74,222,128,.08);color:#4ade80;font-size:10px;font-weight:600;padding:3px 9px;border-radius:20px;border:1px solid rgba(74,222,128,.2)}
.ativo-nao{background:rgba(255,255,255,.04);color:var(--text-muted);font-size:10px;font-weight:600;padding:3px 9px;border-radius:20px;border:1px solid var(--border)}

/* Ação botões ícone */
.btn-icon{width:27px;height:27px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid var(--border);background:none;color:var(--text-muted);cursor:pointer;font-size:11px;transition:background var(--transition),color var(--transition),border-color var(--transition)}
.btn-icon:hover{background:#2a2a2a;color:var(--text-primary);border-color:var(--border-hover)}
.btn-icon.save{color:#4ade80;border-color:rgba(74,222,128,.25)}
.btn-icon.save:hover{background:rgba(74,222,128,.1)}
.btn-icon.cancel:hover{background:rgba(250,204,21,.08);color:#facc15;border-color:rgba(250,204,21,.3)}

.table-empty{padding:44px 20px;text-align:center;color:var(--text-muted);font-size:13px}
.table-empty i{font-size:30px;display:block;margin-bottom:10px;opacity:.35}

/* ── TOAST ── */
#toast{position:fixed;bottom:26px;right:26px;z-index:9999;display:flex;align-items:center;gap:10px;background:#1e1e1e;border:1px solid var(--border-hover);border-radius:10px;padding:12px 18px;font-size:13px;color:var(--text-primary);box-shadow:0 8px 32px rgba(0,0,0,.5);opacity:0;transform:translateY(10px);pointer-events:none;transition:opacity .3s,transform .3s;max-width:340px}
#toast.show{opacity:1;transform:translateY(0);pointer-events:auto}
#toast.ok   i{color:#4ade80}
#toast.erro i{color:#f87171}

/* ── FOOTER ── */
.footer{background:#181818;color:#888;border-top:1px solid var(--border);padding:12px 20px;display:flex;justify-content:space-between;flex-wrap:wrap;font-size:13px;gap:6px;margin-left:var(--sidebar-w);transition:margin-left var(--transition)}
.footer div{color:#666}

/* ── ANIMATIONS ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.fade-up {animation:fadeUp .32s ease both}
.delay-1 {animation-delay:.06s}
.delay-2 {animation-delay:.12s}

::-webkit-scrollbar{width:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.07);border-radius:4px}

/* ── RESPONSIVE ── */
@media(max-width:1050px){.two-col{grid-template-columns:1fr}.form-card{position:static}}
@media(max-width:900px){.content{padding:16px}.footer,#main{margin-left:var(--sidebar-collapsed)}#sidebar{width:var(--sidebar-collapsed)}#sidebar.open{width:var(--sidebar-w)}.topbar-logo-rede{display:none}}
@media(max-width:640px){#sidebar{position:fixed;top:0;left:0;height:100vh;z-index:1100;transform:translateX(-100%);transition:transform var(--transition);width:var(--sidebar-w)!important}#sidebar.open{transform:translateX(0)}.sidebar-toggle{display:none}.menu-toggle{display:block}#main{margin-left:0!important}.topbar{padding-left:54px}.content{padding:12px}.footer{margin-left:0}.kpi-row{gap:8px}.search-box{min-width:unset;width:100%}.table-toolbar{gap:8px}}
@media print{.menu-toggle,.sidebar-overlay,#sidebar,.topbar,.footer{display:none!important}#main{margin-left:0!important}}
</style>
<?php eng_clin_menu_css(); ?>
</head>
<body>

<?php eng_clin_menu_sidebar(); ?>

<div id="main">
  <header class="topbar">
    <div class="topbar-breadcrumb">
      <span>Engenharia Clínica</span>
      <i class="fas fa-chevron-right"></i>
      <span>Criticidades de Itens</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
      <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <!-- PAGE HEADER -->
    <div class="page-header fade-up">
      <div>
        <div class="page-title">Criticidades de Itens</div>
        <div class="page-subtitle">Engenharia Clínica &middot; Classificação automática de chamados</div>
      </div>
      
    </div>

    <!-- ALERT -->
    <?php if ($msg): ?>
    <div class="alert alert-<?= $msg_tipo ?> fade-up">
      <i class="fas <?= $msg_tipo === 'ok' ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <!-- MINI KPIs -->
    <div class="kpi-row fade-up delay-1">
      <div class="kpi-chip kc-total">
        <div class="kpi-chip-icon"><i class="fas fa-list"></i></div>
        <div>
          <div class="kpi-chip-val"><?= $total ?></div>
          <div class="kpi-chip-lbl">Total</div>
        </div>
      </div>
      <div class="kpi-chip kc-alta">
        <div class="kpi-chip-icon"><i class="fas fa-circle-exclamation"></i></div>
        <div>
          <div class="kpi-chip-val" style="color:#f87171"><?= $altas ?></div>
          <div class="kpi-chip-lbl">Alta</div>
        </div>
      </div>
      <div class="kpi-chip kc-media">
        <div class="kpi-chip-icon"><i class="fas fa-circle-minus"></i></div>
        <div>
          <div class="kpi-chip-val" style="color:#facc15"><?= $medias ?></div>
          <div class="kpi-chip-lbl">Média</div>
        </div>
      </div>
      <div class="kpi-chip kc-baixa">
        <div class="kpi-chip-icon"><i class="fas fa-circle-check"></i></div>
        <div>
          <div class="kpi-chip-val" style="color:#4ade80"><?= $baixas ?></div>
          <div class="kpi-chip-lbl">Baixa</div>
        </div>
      </div>
      <div class="kpi-chip kc-inatv">
        <div class="kpi-chip-icon"><i class="fas fa-eye-slash"></i></div>
        <div>
          <div class="kpi-chip-val" style="color:var(--text-muted)"><?= $inativas ?></div>
          <div class="kpi-chip-lbl">Inativos</div>
        </div>
      </div>
    </div>

    <!-- LAYOUT DUAS COLUNAS -->
    <div class="two-col fade-up delay-2">

      <!-- ── FORMULÁRIO DE CADASTRO ──────────────────────────── -->
      <div class="form-card">
        <div class="card-header">
          <div class="card-icon"><i class="fas fa-plus"></i></div>
          <span class="card-title">Novo Item</span>
        </div>
        <div class="card-body">
          <form method="POST" id="formCadastro" autocomplete="off">
            <input type="hidden" name="action" value="cadastrar">

            <div class="field">
              <label class="field-label">
                Descrição do Item <span class="req">*</span>
              </label>
              <input
                type="text"
                name="descricao_item"
                id="inputDesc"
                placeholder="Ex: Monitor Multiparâmetro, Bomba de Infusão..."
                required
                value="<?= htmlspecialchars($_POST['descricao_item'] ?? '') ?>">
            </div>

            <div class="field">
              <label class="field-label">
                Nível de Criticidade <span class="req">*</span>
              </label>
              <select name="criticidade_nivel" id="selPrio" required onchange="atualizarPreview(this.value)">
                <option value="">Selecione...</option>
                <option value="ALTA"  <?= (($_POST['criticidade_nivel'] ?? '') === 'ALTA')  ? 'selected' : '' ?>>Alta</option>
                <option value="MEDIA" <?= (($_POST['criticidade_nivel'] ?? '') === 'MEDIA') ? 'selected' : '' ?>>Média</option>
                <option value="BAIXA" <?= (($_POST['criticidade_nivel'] ?? '') === 'BAIXA') ? 'selected' : '' ?>>Baixa</option>
              </select>
              <!-- Preview visual da criticidade selecionada -->
              <div class="prio-preview" id="prioPreview">
                <div class="prio-dot" id="prioDot"></div>
                <span class="prio-preview-lbl" id="prioLbl"></span>
                <span style="font-size:11px;color:var(--text-muted);margin-left:2px">— atribuída automaticamente ao abrir chamado</span>
              </div>
            </div>

            <div class="field">
              <label class="field-label">Status</label>
              <select name="ativo">
                <option value="1" <?= (($_POST['ativo'] ?? '1') === '1') ? 'selected' : '' ?>>Ativo</option>
                <option value="0" <?= (($_POST['ativo'] ?? '1') === '0') ? 'selected' : '' ?>>Inativo</option>
              </select>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px">
              <button type="reset" class="btn btn-primary" onclick="resetPreview()">
                <i class="fas fa-rotate-left"></i> Limpar
              </button>
              <button type="submit" class="btn btn-success">
                <i class="fas fa-floppy-disk"></i> Cadastrar
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- ── TABELA DE ITENS ─────────────────────────────────── -->
      <div class="table-card">
        <div class="table-toolbar">
          <div class="table-toolbar-title">
            <i class="fas fa-table-list" style="font-size:11px"></i>
            Itens Cadastrados
            <span class="tbl-count" id="tblCount"><?= $total ?></span>
          </div>
          <!-- Filtro criticidade -->
          <select class="filter-sel" id="filtroPrio">
            <option value="">Todas as criticidades</option>
            <option value="ALTA">Alta</option>
            <option value="MEDIA">Média</option>
            <option value="BAIXA">Baixa</option>
          </select>
          <!-- Filtro ativo -->
          <select class="filter-sel" id="filtroAtivo">
            <option value="">Todos os status</option>
            <option value="1">Ativos</option>
            <option value="0">Inativos</option>
          </select>
          <!-- Busca -->
          <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="buscaTbl" placeholder="Buscar item...">
          </div>
        </div>

        <div class="table-wrap">
          <table class="main-table" id="tblCriticidades">
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>Descrição do Item</th>
                <th style="width:120px">Criticidade</th>
                <th style="width:80px;text-align:center">Ativo</th>
                <th style="width:80px;text-align:center">Ações</th>
              </tr>
            </thead>
            <tbody id="tblBody">
            <?php if (empty($lista)): ?>
              <tr><td colspan="5" class="table-empty">
                <i class="fas fa-list"></i>Nenhum item cadastrado ainda.
              </td></tr>
            <?php else: ?>
              <?php foreach ($lista as $item):
                $prioCls = match($item['criticidade_nivel']) {
                  'ALTA'  => 'crit-alta',
                  'MEDIA' => 'crit-media',
                  'BAIXA' => 'crit-baixa',
                  default => 'crit-media',
                };
                $prioIcon = match($item['criticidade_nivel']) {
                  'ALTA'  => 'fa-circle-exclamation',
                  'MEDIA' => 'fa-circle-minus',
                  'BAIXA' => 'fa-circle-check',
                  default => 'fa-circle',
                };
                $prioLbl = match($item['criticidade_nivel']) {
                  'ALTA'  => 'Alta',
                  'MEDIA' => 'Média',
                  'BAIXA' => 'Baixa',
                  default => $item['criticidade_nivel'],
                };
                $ativoCls = $item['ativo'] ? 'ativo-sim' : 'ativo-nao';
                $ativoLbl = $item['ativo'] ? 'Ativo'     : 'Inativo';
              ?>
              <tr
                data-id="<?= $item['id'] ?>"
                data-prio="<?= htmlspecialchars($item['criticidade_nivel']) ?>"
                data-ativo="<?= $item['ativo'] ?>"
                data-desc="<?= htmlspecialchars(strtolower($item['descricao_item'])) ?>">

                <!-- ── VIEW MODE ── -->
                <td class="view-mode" style="color:var(--text-muted);font-size:11px"><?= $item['id'] ?></td>
                <td class="view-mode td-primary"><?= htmlspecialchars($item['descricao_item']) ?></td>
                <td class="view-mode">
                  <span class="crit-badge <?= $prioCls ?>">
                    <i class="fas <?= $prioIcon ?>"></i> <?= $prioLbl ?>
                  </span>
                </td>
                <td class="view-mode" style="text-align:center">
                  <span class="<?= $ativoCls ?>"><?= $ativoLbl ?></span>
                </td>

                <!-- ── EDIT MODE (hidden) ── -->
                <td class="edit-mode" style="display:none;color:var(--text-muted);font-size:11px"><?= $item['id'] ?></td>
                <td class="edit-mode td-edit" style="display:none">
                  <input type="text" class="edit-desc" value="<?= htmlspecialchars($item['descricao_item']) ?>" style="min-width:160px">
                </td>
                <td class="edit-mode td-edit" style="display:none">
                  <select class="edit-prio" style="min-width:90px">
                    <option value="ALTA"  <?= $item['criticidade_nivel']==='ALTA'  ? 'selected' : '' ?>>Alta</option>
                    <option value="MEDIA" <?= $item['criticidade_nivel']==='MEDIA' ? 'selected' : '' ?>>Média</option>
                    <option value="BAIXA" <?= $item['criticidade_nivel']==='BAIXA' ? 'selected' : '' ?>>Baixa</option>
                  </select>
                </td>
                <td class="edit-mode td-edit" style="display:none;text-align:center">
                  <select class="edit-ativo" style="min-width:80px">
                    <option value="1" <?= $item['ativo'] ? 'selected' : '' ?>>Ativo</option>
                    <option value="0" <?= !$item['ativo'] ? 'selected' : '' ?>>Inativo</option>
                  </select>
                </td>

                <!-- ── AÇÕES ── -->
                <td style="text-align:center">
                  <span class="view-mode" style="display:inline-flex;gap:4px">
                    <button class="btn-icon" title="Editar" onclick="modoEditar(this)">
                      <i class="fas fa-pen"></i>
                    </button>
                  </span>
                  <span class="edit-mode" style="display:none;gap:4px">
                    <button class="btn-icon save" title="Salvar" onclick="salvarEdicao(this)">
                      <i class="fas fa-check"></i>
                    </button>
                    <button class="btn-icon cancel" title="Cancelar" onclick="cancelarEdicao(this)">
                      <i class="fas fa-xmark"></i>
                    </button>
                  </span>
                </td>

              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /two-col -->

  </div><!-- /content -->
</div><!-- /main -->

<div class="footer" id="pageFooter">
  <div>Usuário: <?= htmlspecialchars($usuario) ?> &middot; Nível: <?= htmlspecialchars($nivel) ?></div>
  <div>Data: <?= $data ?> | Hora: <span id="hora"><?= $hora ?></span></div>
  <div>&copy; GK Soluções</div>
</div>

<div id="toast">
  <i class="fas fa-circle-check" id="toastIcon"></i>
  <span id="toastMsg"></span>
</div>

<script>
/* ── Relógio ── */
setInterval(() => {
  document.getElementById('hora').innerText = new Date().toLocaleTimeString('pt-BR');
}, 1000);

/* ── Sidebar ── */
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
    toggleIcon.classList.toggle('fa-chevron-left',  !col);
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
sidebar.querySelectorAll('.nav-item').forEach(i =>
  i.addEventListener('click', () => { if (window.innerWidth <= 640) fecharSidebar(); })
);

/* ── Preview de criticidade no formulário ── */
const prioColors = { ALTA: '#f87171', MEDIA: '#facc15', BAIXA: '#4ade80' };
const prioNames  = { ALTA: 'Alta',    MEDIA: 'Média',   BAIXA: 'Baixa'   };

function atualizarPreview(val) {
  const wrap = document.getElementById('prioPreview');
  const dot  = document.getElementById('prioDot');
  const lbl  = document.getElementById('prioLbl');
  if (val && prioColors[val]) {
    dot.style.background  = prioColors[val];
    lbl.style.color       = prioColors[val];
    lbl.style.fontWeight  = '700';
    lbl.textContent       = prioNames[val];
    wrap.style.display    = 'flex';
  } else {
    wrap.style.display    = 'none';
  }
}
function resetPreview() {
  document.getElementById('prioPreview').style.display = 'none';
}

// Restaurar preview se voltou com erro de POST
(function() {
  const sel = document.getElementById('selPrio');
  if (sel && sel.value) atualizarPreview(sel.value);
})();

/* ── Toast ── */
let toastTimer;
function showToast(msg, tipo = 'ok') {
  const t    = document.getElementById('toast');
  const icons = { ok: 'fa-circle-check', erro: 'fa-circle-xmark' };
  document.getElementById('toastMsg').textContent = msg;
  document.getElementById('toastIcon').className  = 'fas ' + (icons[tipo] || 'fa-circle-check');
  t.className = 'show ' + tipo;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.className = ''; }, 3500);
}

<?php if ($msg): ?>
setTimeout(() => showToast(<?= json_encode($msg) ?>, <?= json_encode($msg_tipo) ?>), 200);
<?php endif; ?>

/* ── Filtro + Busca da tabela ── */
const filtroPrio  = document.getElementById('filtroPrio');
const filtroAtivo = document.getElementById('filtroAtivo');
const buscaTbl    = document.getElementById('buscaTbl');
const tblCount    = document.getElementById('tblCount');

function filtrarTabela() {
  const prio  = filtroPrio.value;
  const ativo = filtroAtivo.value;
  const q     = buscaTbl.value.toLowerCase().trim();
  const rows  = document.querySelectorAll('#tblBody tr[data-id]');
  let vis = 0;
  rows.forEach(tr => {
    const mPrio  = !prio  || tr.dataset.prio  === prio;
    const mAtivo = !ativo || tr.dataset.ativo  === ativo;
    const mBusca = !q     || tr.dataset.desc.includes(q);
    tr.style.display = (mPrio && mAtivo && mBusca) ? '' : 'none';
    if (mPrio && mAtivo && mBusca) vis++;
  });
  tblCount.textContent = vis;
}
filtroPrio.addEventListener('change',  filtrarTabela);
filtroAtivo.addEventListener('change', filtrarTabela);
buscaTbl.addEventListener('input',     filtrarTabela);

/* ── Edição inline ── */
function modoEditar(btn) {
  const tr = btn.closest('tr');
  tr.querySelectorAll('.view-mode').forEach(el => el.style.display = 'none');
  tr.querySelectorAll('.edit-mode').forEach(el => el.style.display = '');
  tr.querySelector('span.edit-mode').style.display = 'inline-flex';
  tr.querySelector('span.view-mode').style.display = 'none';
}

function cancelarEdicao(btn) {
  const tr = btn.closest('tr');
  tr.querySelectorAll('.view-mode').forEach(el => el.style.display = '');
  tr.querySelectorAll('.edit-mode').forEach(el => el.style.display = 'none');
  tr.querySelector('span.view-mode').style.display = 'inline-flex';
  tr.querySelector('span.edit-mode').style.display = 'none';
}

function salvarEdicao(btn) {
  const tr    = btn.closest('tr');
  const id    = tr.dataset.id;
  const desc  = tr.querySelector('.edit-desc').value.trim();
  const prio  = tr.querySelector('.edit-prio').value;
  const ativo = tr.querySelector('.edit-ativo').value;

  if (!desc) { showToast('A descrição não pode ficar vazia.', 'erro'); return; }

  const fd = new FormData();
  fd.append('action',           'editar');
  fd.append('id',               id);
  fd.append('descricao_item',   desc);
  fd.append('criticidade_nivel', prio);
  fd.append('ativo',            ativo);

  fetch('', { method: 'POST', body: fd })
    .then(r => {
      if (r.ok) {
        showToast('Item atualizado com sucesso!', 'ok');
        setTimeout(() => location.reload(), 900);
      } else {
        showToast('Erro ao salvar.', 'erro');
      }
    })
    .catch(() => showToast('Falha na conexão.', 'erro'));
}
</script>
<?php eng_clin_menu_painel(true); ?>
</body>
</html>