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
$nivel          = "C";
$classe_usuario = '';
$status         = 'ATIVO';
if ($r = $res->fetch_assoc()) {
    $nivel          = strtoupper(trim($r['permicao']));
    $classe_usuario = strtoupper(trim($r['classe_usuario']));
    $status         = $r['status'] ?? 'ATIVO';
}
$stmt->close();

if ($status !== 'ATIVO') {
    session_destroy();
    header("Location: index.html?erro=bloqueado");
    exit();
}

// Apenas DEV ou ENGENHARIA CLINICA com nível A
if (!in_array($classe_usuario, ['DEV', 'ENGENHARIA CLINICA']) || $nivel !== 'A') {
    header("Location: acesso_bloqueado.html");
    exit();
}

$conn->close();

date_default_timezone_set('America/Sao_Paulo');
$data = date('d/m/Y');
$hora = date('H:i:s');
$usuario_nome  = $usuario;
$usuario_nivel = $nivel;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadastro de Fornecedores — Engenharia Clínica</title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="shortcut icon" href="logo_1.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root {
    --bg-page:           #0f0f0f;
    --bg-sidebar:        #141414;
    --bg-card:           #1a1a1a;
    --bg-card-hover:     #1f1f1f;
    --bg-input:          #222222;
    --border:            rgba(255,255,255,0.07);
    --border-hover:      rgba(255,255,255,0.14);
    --accent:            #c8c8c8;
    --accent-bright:     #ffffff;
    --accent-muted:      #888888;
    --accent-steel:      #a0aec0;
    --highlight:         #e2e8f0;
    --text-primary:      #f0f0f0;
    --text-secondary:    #888;
    --text-muted:        #555;
    --sidebar-w:         260px;
    --sidebar-collapsed: 68px;
    --header-h:          56px;
    --radius:            10px;
    --radius-lg:         16px;
    --transition:        0.22s cubic-bezier(0.4, 0, 0.2, 1);
    --font-ui:           'DM Sans', sans-serif;
    --font-display:      'Syne', sans-serif;
    --status-ok:         #4ade80;
    --status-warn:       #facc15;
    --status-err:        #f87171;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: var(--font-ui);
    background: var(--bg-page);
    color: var(--text-primary);
    min-height: 100vh;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
  }

  /* ── MENU TOGGLE ── */
  .menu-toggle {
    display: none;
    position: fixed;
    top: 10px; left: 10px;
    z-index: 1200;
    background: #2a2a2a;
    color: #e2e8f0;
    border: 1px solid var(--border-hover);
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 20px;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,.4);
    line-height: 1;
  }
  .sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 1000;
  }
  .sidebar-overlay.open { display: block; }

  /* ── SIDEBAR ── */
  #sidebar {
    position: fixed;
    top: 0; left: 0;
    width: var(--sidebar-w);
    height: 100vh;
    background: var(--bg-sidebar);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    z-index: 100;
    transition: width var(--transition);
    overflow: visible;
  }
  #sidebar.collapsed { width: var(--sidebar-collapsed); }

  .sidebar-brand {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px 12px 16px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
    gap: 10px;
  }
  .brand-logo-main {
    width: 56%;
    max-width: 140px;
    height: auto;
    object-fit: contain;
    display: block;
    transition: opacity var(--transition), width var(--transition);
  }
  .brand-subtitle {
    font-family: var(--font-display);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--text-muted);
    white-space: nowrap;
    transition: opacity var(--transition);
  }
  #sidebar.collapsed .brand-logo-main { width: 31px; max-width: 31px; }
  #sidebar.collapsed .brand-subtitle  { opacity: 0; pointer-events: none; }

  .sidebar-toggle {
    position: absolute;
    top: 14px; right: -14px;
    width: 28px; height: 28px;
    background: var(--bg-card);
    border: 1px solid var(--border-hover);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    z-index: 200;
    transition: background var(--transition);
    color: var(--text-secondary);
    font-size: 11px;
  }
  .sidebar-toggle:hover { background: #2a2a2a; color: var(--text-primary); }

  .sidebar-nav {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 8px 10px;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
  }
  .nav-section-label {
    font-size: 9.5px;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text-muted);
    padding: 10px 4px 4px;
    white-space: nowrap;
    overflow: hidden;
    transition: opacity var(--transition);
  }
  #sidebar.collapsed .nav-section-label { opacity: 0; height: 0; padding: 0; }
  #sidebar.collapsed .nav-item {
    padding: 10px 0;
    text-align: center;
    font-size: 0;
    color: transparent;
    overflow: hidden;
    transform: none !important;
  }
  #sidebar.collapsed .nav-item::before {
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    font-size: 15px;
    color: #888;
    display: block;
    line-height: 1;
    transition: color var(--transition);
  }
  #sidebar.collapsed .nav-item:hover::before { color: #e8e9eb; }
  #sidebar.collapsed .nav-item.active::before { color: #fff; }

  .nav-item {
    display: block;
    width: 100%;
    padding: 11px 14px;
    margin: 3px 0;
    border-radius: 6px;
    cursor: pointer;
    text-decoration: none;
    color: #bfc0c2;
    font-size: 14px;
    font-weight: 400;
    transition: background var(--transition), color var(--transition), transform var(--transition);
    white-space: nowrap;
    overflow: hidden;
    position: relative;
    border: none;
    background: #1e2025;
    text-align: left;
    letter-spacing: 0.01em;
  }
  .nav-item:hover  { background: #26282d; color: #e8e9eb; transform: translateX(4px); }
  .nav-item.active { background: #2a2c31; color: #ffffff; font-weight: 500; }
  .nav-label { display: inline; }
  #sidebar.collapsed .nav-label { opacity: 0; }
  .nav-badge {
    float: right;
    background: rgba(255,255,255,0.1);
    color: var(--text-secondary);
    font-size: 10px; font-weight: 600;
    padding: 2px 7px;
    border-radius: 20px;
    margin-top: 1px;
    transition: opacity var(--transition);
  }
  #sidebar.collapsed .nav-badge { opacity: 0; }

  #sidebar.collapsed .nav-item::after {
    content: attr(data-tooltip);
    position: absolute;
    left: calc(var(--sidebar-collapsed) + 8px);
    top: 50%; transform: translateY(-50%);
    background: #2d2d2d;
    color: #e2e8f0;
    font-size: 12px;
    padding: 5px 10px;
    border-radius: 6px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.15s;
    z-index: 200;
    border: 1px solid var(--border-hover);
    box-shadow: 0 4px 12px rgba(0,0,0,.4);
  }
  #sidebar.collapsed .nav-item:hover::after { opacity: 1; }

  .nav-item-sair { color: #f87171 !important; }
  .nav-item-sair:hover { background: rgba(248,113,113,0.08) !important; }

  /* ── MAIN ── */
  #main {
    margin-left: var(--sidebar-w);
    transition: margin-left var(--transition);
    min-height: calc(100vh - 42px);
    display: flex; flex-direction: column;
    flex: 1;
  }
  #main.sidebar-collapsed { margin-left: var(--sidebar-collapsed); }

  /* ── TOPBAR ── */
  .topbar {
    height: var(--header-h);
    background: rgba(20,20,20,0.95);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center;
    padding: 0 20px;
    gap: 10px;
    position: sticky; top: 0; z-index: 50;
  }
  .topbar-breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; color: var(--text-muted);
  }
  .topbar-breadcrumb span:last-child { color: var(--text-primary); font-weight: 500; }
  .topbar-breadcrumb i { font-size: 10px; }
  .topbar-spacer { flex: 1; }
  .topbar-logo-rede {
    height: 32px; width: auto;
    object-fit: contain; opacity: 0.75;
    transition: opacity var(--transition); flex-shrink: 0;
  }
  .topbar-logo-rede:hover { opacity: 1; }
  .topbar-btn {
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: #1c1c1c;
    color: var(--text-secondary);
    cursor: pointer;
    transition: background var(--transition), color var(--transition), border-color var(--transition);
    font-size: 13px;
    position: relative; flex-shrink: 0;
  }
  .topbar-btn:hover { background: #2a2a2a; color: var(--text-primary); border-color: var(--border-hover); }

  /* ── CONTENT ── */
  .content {
    flex: 1;
    padding: 28px;
    width: 100%;
  }

  /* ── PAGE HEADER ── */
  .page-header {
    display: flex; align-items: flex-end; justify-content: space-between;
    margin-bottom: 28px;
    flex-wrap: wrap; gap: 12px;
  }
  .page-title {
    font-family: var(--font-display);
    font-size: 22px; font-weight: 700;
    color: var(--text-primary);
    letter-spacing: -0.01em;
  }
  .page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 3px; }
  .page-actions  { display: flex; gap: 8px; flex-wrap: wrap; }

  /* ── BOTÕES ── */
  .btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px;
    border-radius: 8px;
    font-family: var(--font-ui);
    font-size: 12px; font-weight: 500;
    cursor: pointer;
    transition: all var(--transition);
    border: none;
    text-decoration: none;
  }
  .btn-primary   { background: #232323; border: 1px solid rgba(255,255,255,0.13); color: var(--text-primary); }
  .btn-primary:hover { background: #2e2e2e; border-color: rgba(255,255,255,0.2); }
  .btn-success   { background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.25); color: #4ade80; }
  .btn-success:hover { background: rgba(74,222,128,0.18); border-color: rgba(74,222,128,0.4); }
  .btn-danger    { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.25); color: #f87171; }
  .btn-danger:hover  { background: rgba(248,113,113,0.18); border-color: rgba(248,113,113,0.4); }

  /* ── FORM SECTIONS ── */
  .form-section {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: 16px;
    transition: border-color var(--transition);
  }
  .form-section:hover { border-color: var(--border-hover); }

  .form-section-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px;
    background: rgba(255,255,255,0.02);
  }
  .form-section-icon {
    width: 28px; height: 28px;
    border-radius: 6px;
    background: rgba(160,174,192,0.1);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; color: var(--accent-steel);
    flex-shrink: 0;
  }
  .form-section-title {
    font-size: 12px; font-weight: 600;
    letter-spacing: 0.05em; text-transform: uppercase;
    color: var(--accent-steel);
  }

  .form-section-body {
    padding: 20px;
  }

  /* ── GRID DE CAMPOS ── */
  .form-grid {
    display: grid;
    gap: 14px;
  }
  .form-grid-2 { grid-template-columns: 1fr 1fr; }
  .form-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
  .form-grid-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }

  .col-span-2 { grid-column: span 2; }
  .col-span-3 { grid-column: span 3; }
  .col-span-4 { grid-column: span 4; }

  /* ── CAMPOS ── */
  .field { display: flex; flex-direction: column; gap: 5px; }
  .field-label {
    font-size: 11px; font-weight: 500;
    letter-spacing: 0.04em; text-transform: uppercase;
    color: var(--text-muted);
  }
  .field-label .obrig { color: #f87171; margin-left: 2px; }

  .field input,
  .field select,
  .field textarea {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 9px 12px;
    font-family: var(--font-ui);
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
    transition: border-color var(--transition), background var(--transition);
    width: 100%;
  }
  .field input:focus,
  .field select:focus,
  .field textarea:focus {
    border-color: var(--border-hover);
    background: #282828;
  }
  .field input::placeholder,
  .field textarea::placeholder { color: var(--text-muted); font-size: 12px; }

  .field select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
    cursor: pointer;
  }
  .field select option { background: #1e1e1e; }

  /* ── TOAST ── */
  #toast {
    position: fixed;
    bottom: 28px; right: 28px;
    z-index: 9999;
    display: flex; align-items: center; gap: 10px;
    background: #1e1e1e;
    border: 1px solid var(--border-hover);
    border-radius: 10px;
    padding: 13px 18px;
    font-size: 13px;
    color: var(--text-primary);
    box-shadow: 0 8px 32px rgba(0,0,0,.5);
    opacity: 0;
    transform: translateY(12px);
    pointer-events: none;
    transition: opacity .3s ease, transform .3s ease;
    max-width: 340px;
  }
  #toast.show { opacity: 1; transform: translateY(0); pointer-events: auto; }
  #toast.success { border-color: rgba(74,222,128,0.35); }
  #toast.success i { color: #4ade80; }
  #toast.error   { border-color: rgba(248,113,113,0.35); }
  #toast.error i { color: #f87171; }

  /* ── FOOTER ── */
  .footer {
    background: #181818;
    color: #888;
    border-top: 1px solid var(--border);
    padding: 12px 20px;
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    font-size: 13px;
    gap: 6px;
    margin-left: var(--sidebar-w);
    transition: margin-left var(--transition);
  }
  .footer span, .footer div { color: #666; }

  /* ── SCROLLBAR ── */
  ::-webkit-scrollbar { width: 4px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.07); border-radius: 4px; }
  ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.14); }

  /* ── ANIMATIONS ── */
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .fade-up  { animation: fadeUp 0.35s ease both; }
  .delay-1  { animation-delay: 0.05s; }
  .delay-2  { animation-delay: 0.10s; }
  .delay-3  { animation-delay: 0.15s; }
  .delay-4  { animation-delay: 0.20s; }
  .delay-5  { animation-delay: 0.25s; }

  /* ── TABELA DE FORNECEDORES ── */
  .table-section {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: 28px;
    transition: border-color var(--transition);
  }
  .table-section:hover { border-color: var(--border-hover); }

  .table-toolbar {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 12px;
    flex-wrap: wrap;
  }
  .table-toolbar-title {
    font-size: 12px; font-weight: 600;
    letter-spacing: 0.04em; text-transform: uppercase;
    color: var(--accent-steel); flex: 1;
    display: flex; align-items: center; gap: 8px;
  }
  .table-count {
    background: rgba(160,174,192,0.12);
    color: var(--accent-steel);
    font-size: 10px; font-weight: 600;
    padding: 2px 8px; border-radius: 20px;
  }
  .table-search {
    display: flex; align-items: center; gap: 8px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 12px;
    transition: border-color var(--transition);
    min-width: 240px;
  }
  .table-search:focus-within { border-color: var(--border-hover); }
  .table-search i { font-size: 12px; color: var(--text-muted); flex-shrink: 0; }
  .table-search input {
    background: none; border: none; outline: none;
    font-size: 12px; color: var(--text-primary);
    font-family: var(--font-ui); width: 100%;
  }
  .table-search input::placeholder { color: var(--text-muted); }

  .table-wrap {
    overflow-x: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
  }
  table.fornec-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }
  table.fornec-table thead tr {
    border-bottom: 1px solid var(--border);
  }
  table.fornec-table th {
    padding: 10px 16px;
    text-align: left;
    font-size: 10px; font-weight: 600;
    letter-spacing: 0.07em; text-transform: uppercase;
    color: var(--text-muted);
    white-space: nowrap;
    background: rgba(255,255,255,0.02);
  }
  table.fornec-table td {
    padding: 11px 16px;
    color: var(--text-secondary);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  table.fornec-table tbody tr:last-child td { border-bottom: none; }
  table.fornec-table tbody tr:hover td {
    background: rgba(255,255,255,0.02);
    color: var(--text-primary);
  }
  table.fornec-table td.td-primary {
    color: var(--text-primary);
    font-weight: 500;
  }
  .area-badge {
    display: inline-block;
    font-size: 10px; font-weight: 600;
    padding: 2px 8px; border-radius: 20px;
    background: rgba(160,174,192,0.1);
    color: var(--accent-steel);
    white-space: nowrap;
  }
  .btn-icon {
    width: 28px; height: 28px;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 6px; border: 1px solid var(--border);
    background: none; color: var(--text-muted);
    cursor: pointer; font-size: 12px;
    transition: background var(--transition), color var(--transition), border-color var(--transition);
  }
  .btn-icon:hover { background: #2a2a2a; color: var(--text-primary); border-color: var(--border-hover); }
  .btn-icon.del:hover { background: rgba(248,113,113,0.1); color: #f87171; border-color: rgba(248,113,113,0.3); }

  .table-empty {
    padding: 40px 20px;
    text-align: center;
    color: var(--text-muted);
    font-size: 13px;
  }
  .table-empty i { font-size: 28px; display: block; margin-bottom: 10px; opacity: .4; }

  /* ── COLLAPSED ICONS ── */
#sidebar.collapsed .nav-item[data-tooltip="Página Inicial"]::before { content: "\f015"; }
  #sidebar.collapsed .nav-item[data-tooltip="Sair"]::before           { content: "\f2f5"; }

  /* ── CAIXA DE FERRAMENTAS ── */
  .tools-panel {
    position: fixed;
    top: var(--header-h);
    right: -280px;
    width: 260px;
    height: calc(100vh - var(--header-h));
    background: var(--bg-sidebar);
    border-left: 1px solid var(--border);
    z-index: 200;
    display: flex;
    flex-direction: column;
    transition: right var(--transition);
    box-shadow: -4px 0 24px rgba(0,0,0,.4);
  }
  .tools-panel.open { right: 0; }
  .tools-header {
    padding: 16px 18px 12px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
  }
  .tools-title {
    font-family: var(--font-display);
    font-size: 13px; font-weight: 600;
    letter-spacing: .04em; text-transform: uppercase;
    color: var(--accent-steel);
  }
  .tools-close {
    width: 26px; height: 26px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 6px; border: 1px solid var(--border);
    background: none; color: var(--text-muted);
    cursor: pointer; font-size: 13px;
    transition: background var(--transition), color var(--transition);
  }
  .tools-close:hover { background: #2a2a2a; color: var(--text-primary); }
  .tools-section-label {
    font-size: 9.5px; font-weight: 600;
    letter-spacing: .1em; text-transform: uppercase;
    color: var(--text-muted);
    padding: 12px 18px 6px;
  }
  .tools-body {
    flex: 1; overflow-y: auto; padding: 8px 10px;
    scrollbar-width: thin; scrollbar-color: var(--border) transparent;
  }
  .tool-btn {
    display: flex; align-items: center; gap: 12px;
    width: 100%; padding: 11px 14px; margin: 3px 0;
    border-radius: 6px; border: none;
    background: #1e2025; color: #bfc0c2;
    font-family: var(--font-ui); font-size: 13px; font-weight: 400;
    cursor: pointer; text-align: left;
    text-decoration: none;
    transition: background var(--transition), color var(--transition), transform var(--transition);
    letter-spacing: .01em;
  }
  .tool-btn:hover { background: #26282d; color: #e8e9eb; transform: translateX(4px); }
  .tool-btn i { width: 16px; text-align: center; font-size: 13px; color: var(--text-muted); flex-shrink: 0; }
  .tool-btn:hover i { color: var(--accent-steel); }
  .tool-btn.em-breve { opacity: .45; cursor: not-allowed; }
  .tool-btn.em-breve:hover { transform: none; background: #1e2025; color: #bfc0c2; }
  .topbar-btn.ativo { background: #2a2a2a; color: var(--text-primary); border-color: var(--border-hover); }

  /* ── RESPONSIVE ── */
  @media (max-width: 900px) {
    .form-grid-3 { grid-template-columns: 1fr 1fr; }
    .form-grid-4 { grid-template-columns: 1fr 1fr; }
    .col-span-3  { grid-column: span 2; }
    .col-span-4  { grid-column: span 2; }
    .content { padding: 16px; }
    .topbar-logo-rede { display: none; }
    .footer  { margin-left: var(--sidebar-collapsed); }
    #main    { margin-left: var(--sidebar-collapsed); }
    #sidebar { width: var(--sidebar-collapsed); }
    #sidebar.open { width: var(--sidebar-w); }
  }
  @media (max-width: 640px) {
    #sidebar {
      position: fixed; top: 0; left: 0;
      height: 100vh; z-index: 1100;
      transform: translateX(-100%);
      transition: transform var(--transition);
      width: var(--sidebar-w) !important;
    }
    #sidebar.open { transform: translateX(0); }
    .sidebar-toggle { display: none; }
    .menu-toggle    { display: block; }
    #main { margin-left: 0 !important; }
    .topbar { padding-left: 54px; }
    .form-grid-2,
    .form-grid-3,
    .form-grid-4 { grid-template-columns: 1fr; }
    .col-span-2,
    .col-span-3,
    .col-span-4 { grid-column: span 1; }
    .content { padding: 12px; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-actions { width: 100%; }
    .page-actions .btn { flex: 1; justify-content: center; }
    .footer { margin-left: 0; }
  }
  @media print {
    .menu-toggle, .sidebar-overlay, #sidebar, .topbar, .footer { display: none !important; }
    #main { margin-left: 0 !important; }
  }
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
      <span>Cadastro de Fornecedores</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
      <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <!-- PAGE HEADER -->
    <div class="page-header fade-up">
      <div>
        <div class="page-title">Cadastro de Fornecedores</div>
        <div class="page-subtitle">Engenharia Clínica &middot; <?= eng_clin_data_pt() ?></div>
      </div>
      <div class="page-actions">
        <button class="btn btn-danger" type="button" onclick="limparFormulario()">
          <i class="fas fa-eraser"></i> Limpar Campos
        </button>
        <button class="btn btn-success" type="button" onclick="salvarFornecedor()">
          <i class="fas fa-floppy-disk"></i> Salvar Fornecedor
        </button>
      </div>
    </div>

    <form id="formFornecedor" novalidate>

      <!-- DADOS DA EMPRESA -->
      <div class="form-section fade-up delay-1">
        <div class="form-section-header">
          <div class="form-section-icon"><i class="fas fa-building"></i></div>
          <span class="form-section-title">Dados da Empresa</span>
        </div>
        <div class="form-section-body">
          <div class="form-grid form-grid-3" style="margin-bottom:14px;">
            <div class="field col-span-2">
              <label class="field-label">Razão Social <span class="obrig">*</span></label>
              <input type="text" id="razao_social" name="razao_social" placeholder="Razão social completa" maxlength="200">
            </div>
            <div class="field">
              <label class="field-label">CNPJ <span class="obrig">*</span></label>
              <input type="text" id="cnpj" name="cnpj" placeholder="00.000.000/0000-00" maxlength="18">
            </div>
          </div>
          <div class="form-grid form-grid-3" style="margin-bottom:14px;">
            <div class="field col-span-2">
              <label class="field-label">Nome Fantasia</label>
              <input type="text" id="nome_fantasia" name="nome_fantasia" placeholder="Nome comercial / fantasia" maxlength="200">
            </div>
            <div class="field">
              <label class="field-label">Área de Atuação</label>
              <select id="area_atuacao" name="area_atuacao">
                <option value="">Selecione...</option>
                <option value="Equipamentos Médicos">Equipamentos Médicos</option>
                <option value="Manutenção e Reparo">Manutenção e Reparo</option>
                <option value="Peças e Componentes">Peças e Componentes</option>
                <option value="Calibração e Metrologia">Calibração e Metrologia</option>
                <option value="Gases Medicinais">Gases Medicinais</option>
                <option value="Descartáveis e Insumos">Descartáveis e Insumos</option>
                <option value="Tecnologia da Informação">Tecnologia da Informação</option>
                <option value="Serviços de Engenharia">Serviços de Engenharia</option>
                <option value="Outros">Outros</option>
              </select>
            </div>
          </div>
          <div class="form-grid form-grid-4">
            <div class="field">
              <label class="field-label">Banco</label>
              <input type="text" id="banco" name="banco" placeholder="Nome do banco" maxlength="100">
            </div>
            <div class="field">
              <label class="field-label">Agência</label>
              <input type="text" id="agencia" name="agencia" placeholder="0000-0" maxlength="20">
            </div>
            <div class="field">
              <label class="field-label">Conta</label>
              <input type="text" id="conta" name="conta" placeholder="00000-0" maxlength="30">
            </div>
            <div class="field">
              <label class="field-label">Chave PIX</label>
              <input type="text" id="chave_pix" name="chave_pix" placeholder="CPF, CNPJ, e-mail ou aleatória" maxlength="100">
            </div>
          </div>
        </div>
      </div>

      <!-- ENDEREÇO -->
      <div class="form-section fade-up delay-2">
        <div class="form-section-header">
          <div class="form-section-icon"><i class="fas fa-location-dot"></i></div>
          <span class="form-section-title">Endereço</span>
        </div>
        <div class="form-section-body">
          <div class="form-grid form-grid-4" style="margin-bottom:14px;">
            <div class="field">
              <label class="field-label">CEP</label>
              <input type="text" id="cep" name="cep" placeholder="00000-000" maxlength="9">
            </div>
            <div class="field col-span-2">
              <label class="field-label">Logradouro</label>
              <input type="text" id="logradouro" name="logradouro" placeholder="Rua, Av., Travessa..." maxlength="200">
            </div>
            <div class="field">
              <label class="field-label">Número</label>
              <input type="text" id="numero" name="numero" placeholder="Nº" maxlength="20">
            </div>
          </div>
          <div class="form-grid form-grid-4">
            <div class="field col-span-2">
              <label class="field-label">Complemento</label>
              <input type="text" id="complemento" name="complemento" placeholder="Sala, Andar, Bloco..." maxlength="100">
            </div>
            <div class="field">
              <label class="field-label">Bairro</label>
              <input type="text" id="bairro" name="bairro" placeholder="Bairro" maxlength="100">
            </div>
            <div class="field">
              <label class="field-label">Cidade</label>
              <input type="text" id="cidade" name="cidade" placeholder="Cidade" maxlength="100">
            </div>
          </div>
          <div class="form-grid form-grid-4" style="margin-top:14px;">
            <div class="field">
              <label class="field-label">Estado</label>
              <select id="estado" name="estado">
                <option value="">UF</option>
                <option>AC</option><option>AL</option><option>AP</option><option>AM</option>
                <option>BA</option><option>CE</option><option>DF</option><option>ES</option>
                <option>GO</option><option>MA</option><option>MT</option><option>MS</option>
                <option>MG</option><option>PA</option><option>PB</option><option>PR</option>
                <option>PE</option><option>PI</option><option>RJ</option><option>RN</option>
                <option>RS</option><option>RO</option><option>RR</option><option>SC</option>
                <option>SP</option><option>SE</option><option>TO</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- CONTATO -->
      <div class="form-section fade-up delay-3">
        <div class="form-section-header">
          <div class="form-section-icon"><i class="fas fa-phone"></i></div>
          <span class="form-section-title">Contato</span>
        </div>
        <div class="form-section-body">
          <div class="form-grid form-grid-3" style="margin-bottom:14px;">
            <div class="field">
              <label class="field-label">Telefone Principal</label>
              <input type="text" id="telefone_principal" name="telefone_principal" placeholder="(00) 0000-0000" maxlength="20">
            </div>
            <div class="field">
              <label class="field-label">Telefone Secundário</label>
              <input type="text" id="telefone_secundario" name="telefone_secundario" placeholder="(00) 0000-0000" maxlength="20">
            </div>
            <div class="field">
              <label class="field-label">WhatsApp</label>
              <input type="text" id="whatsapp" name="whatsapp" placeholder="(00) 00000-0000" maxlength="20">
            </div>
          </div>
          <div class="form-grid form-grid-2">
            <div class="field">
              <label class="field-label">E-mail Comercial</label>
              <input type="email" id="email_comercial" name="email_comercial" placeholder="contato@empresa.com.br" maxlength="150">
            </div>
            <div class="field">
              <label class="field-label">Site</label>
              <input type="text" id="site" name="site" placeholder="https://www.empresa.com.br" maxlength="200">
            </div>
          </div>
        </div>
      </div>

      <!-- RESPONSÁVEIS -->
      <div class="form-section fade-up delay-4">
        <div class="form-section-header">
          <div class="form-section-icon"><i class="fas fa-user-tie"></i></div>
          <span class="form-section-title">Responsável / Contato Principal</span>
        </div>
        <div class="form-section-body">
          <div class="form-grid form-grid-2" style="margin-bottom:14px;">
            <div class="field">
              <label class="field-label">Nome do Contato Principal</label>
              <input type="text" id="resp_nome" name="resp_nome" placeholder="Nome completo" maxlength="150">
            </div>
            <div class="field">
              <label class="field-label">Cargo / Função</label>
              <input type="text" id="resp_cargo" name="resp_cargo" placeholder="Gerente, Representante..." maxlength="100">
            </div>
          </div>
          <div class="form-grid form-grid-2">
            <div class="field">
              <label class="field-label">Telefone Direto</label>
              <input type="text" id="resp_telefone" name="resp_telefone" placeholder="(00) 00000-0000" maxlength="20">
            </div>
            <div class="field">
              <label class="field-label">E-mail Direto</label>
              <input type="email" id="resp_email" name="resp_email" placeholder="nome@empresa.com.br" maxlength="150">
            </div>
          </div>
        </div>
      </div>

      <!-- Botões rodapé -->
      <div class="fade-up delay-5" style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;padding-bottom:8px;">
        <button class="btn btn-danger" type="button" onclick="limparFormulario()">
          <i class="fas fa-eraser"></i> Limpar Campos
        </button>
        <button class="btn btn-success" type="button" onclick="salvarFornecedor()">
          <i class="fas fa-floppy-disk"></i> Salvar Fornecedor
        </button>
      </div>

    </form>

    <!-- TABELA DE FORNECEDORES CADASTRADOS -->
    <div class="table-section fade-up" style="animation-delay:.3s; margin-top:28px;">
      <div class="table-toolbar">
        <div class="table-toolbar-title">
          <i class="fas fa-list" style="font-size:12px;"></i>
          Fornecedores Cadastrados
          <span class="table-count" id="tabelaCount">0</span>
        </div>
        <div class="table-search">
          <i class="fas fa-search"></i>
          <input type="text" id="buscaFornecedor" placeholder="Buscar por razão social, CNPJ, cidade, área...">
        </div>
      </div>
      <div class="table-wrap">
        <table class="fornec-table" id="tabelaFornecedores">
          <thead>
            <tr>
              <th>#</th>
              <th>Razão Social</th>
              <th>Nome Fantasia</th>
              <th>CNPJ</th>
              <th>Área de Atuação</th>
              <th>Cidade / UF</th>
              <th>Telefone</th>
              <th>E-mail Comercial</th>
              <th>Cadastrado em</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="tabelaBody">
            <tr><td colspan="10" class="table-empty"><i class="fas fa-truck"></i>Carregando fornecedores...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<div class="footer" id="pageFooter">
  <div>Usuário: <?= htmlspecialchars($usuario_nome) ?> &middot; Nível: <?= htmlspecialchars($usuario_nivel) ?></div>
  <div>Data: <?= $data ?> | Hora: <span id="hora"><?= $hora ?></span></div>
  <div>&copy; GK Soluções</div>
</div>

<!-- TOAST -->
<div id="toast"><i class="fas fa-circle-check" id="toastIcon"></i> <span id="toastMsg"></span></div>

<!-- OVERLAY + PAINEL DE OPÇÕES -->

<script>
/* Relógio */
setInterval(() => {
  document.getElementById('hora').innerText = new Date().toLocaleTimeString('pt-BR');
}, 1000);

/* Sidebar collapse */
const sidebar    = document.getElementById('sidebar');
const mainArea   = document.getElementById('main');
const footer     = document.getElementById('pageFooter');
const toggleBtn  = document.getElementById('toggleBtn');
const toggleIcon = document.getElementById('toggleIcon');

function syncFooter(collapsed) {
  if (footer) footer.style.marginLeft = collapsed ? 'var(--sidebar-collapsed)' : 'var(--sidebar-w)';
}
if (toggleBtn) {
  toggleBtn.addEventListener('click', () => {
    const collapsed = sidebar.classList.toggle('collapsed');
    mainArea.classList.toggle('sidebar-collapsed', collapsed);
    toggleIcon.classList.toggle('fa-chevron-left',  !collapsed);
    toggleIcon.classList.toggle('fa-chevron-right', collapsed);
    syncFooter(collapsed);
  });
}

/* Mobile menu */
document.getElementById('menuToggle').onclick = () => {
  sidebar.classList.add('open');
  document.getElementById('sidebarOverlay').classList.add('open');
};
function fecharSidebar() {
  sidebar.classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('open');
}
sidebar.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', () => { if (window.innerWidth <= 640) fecharSidebar(); });
});

/* Caixa de ferramentas */
/* Máscara CNPJ */
document.getElementById('cnpj').addEventListener('input', function() {
  let v = this.value.replace(/\D/g,'');
  if (v.length > 14) v = v.slice(0,14);
  v = v.replace(/^(\d{2})(\d)/,'$1.$2')
       .replace(/^(\d{2})\.(\d{3})(\d)/,'$1.$2.$3')
       .replace(/\.(\d{3})(\d)/,'.$1/$2')
       .replace(/(\d{4})(\d)/,'$1-$2');
  this.value = v;
});

/* Máscara CEP */
document.getElementById('cep').addEventListener('input', function() {
  let v = this.value.replace(/\D/g,'');
  if (v.length > 8) v = v.slice(0,8);
  v = v.replace(/(\d{5})(\d)/,'$1-$2');
  this.value = v;
});

/* Busca CEP (ViaCEP) */
document.getElementById('cep').addEventListener('blur', function() {
  const cep = this.value.replace(/\D/g,'');
  if (cep.length !== 8) return;
  fetch(`https://viacep.com.br/ws/${cep}/json/`)
    .then(r => r.json())
    .then(d => {
      if (d.erro) return;
      document.getElementById('logradouro').value = d.logradouro || '';
      document.getElementById('bairro').value     = d.bairro     || '';
      document.getElementById('cidade').value     = d.localidade || '';
      document.getElementById('estado').value     = d.uf         || '';
    })
    .catch(() => {});
});

/* Máscaras telefone */
['telefone_principal','telefone_secundario','whatsapp','resp_telefone'].forEach(id => {
  document.getElementById(id).addEventListener('input', function() {
    let v = this.value.replace(/\D/g,'');
    if (v.length > 11) v = v.slice(0,11);
    if (v.length <= 10) {
      v = v.replace(/(\d{2})(\d)/,'($1) $2').replace(/(\d{4})(\d)/,'$1-$2');
    } else {
      v = v.replace(/(\d{2})(\d)/,'($1) $2').replace(/(\d{5})(\d)/,'$1-$2');
    }
    this.value = v;
  });
});

/* Toast */
let toastTimer;
function showToast(msg, tipo = 'success') {
  const t   = document.getElementById('toast');
  const ico = document.getElementById('toastIcon');
  document.getElementById('toastMsg').innerText = msg;
  t.className = 'show ' + tipo;
  ico.className = tipo === 'success' ? 'fas fa-circle-check' : 'fas fa-circle-xmark';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.className = ''; }, 3500);
}

/* Limpar formulário */
function limparFormulario() {
  if (!confirm('Deseja limpar todos os campos?')) return;
  document.getElementById('formFornecedor').reset();
  showToast('Campos limpos com sucesso.', 'success');
}

/* ── Tabela de Fornecedores ── */
let fornecedoresDados = [];

function carregarTabela() {
  fetch('eng_clin_fornecedor_lista.php')
    .then(r => r.json())
    .then(data => {
      fornecedoresDados = data;
      renderizarTabela(data);
    })
    .catch(() => {
      document.getElementById('tabelaBody').innerHTML =
        '<tr><td colspan="10" class="table-empty"><i class="fas fa-circle-exclamation"></i>Erro ao carregar dados.</td></tr>';
    });
}

function renderizarTabela(dados) {
  const tbody = document.getElementById('tabelaBody');
  document.getElementById('tabelaCount').textContent = dados.length;

  if (dados.length === 0) {
    tbody.innerHTML = '<tr><td colspan="10" class="table-empty"><i class="fas fa-truck"></i>Nenhum fornecedor cadastrado ainda.</td></tr>';
    return;
  }

  tbody.innerHTML = dados.map((f, i) => `
    <tr>
      <td style="color:var(--text-muted);font-size:11px;">${String(i + 1).padStart(2,'0')}</td>
      <td class="td-primary">${esc(f.razao_social)}</td>
      <td>${esc(f.nome_fantasia || '—')}</td>
      <td style="font-family:monospace;font-size:12px;">${esc(f.cnpj)}</td>
      <td>${f.area_atuacao ? `<span class="area-badge">${esc(f.area_atuacao)}</span>` : '—'}</td>
      <td>${f.cidade ? esc(f.cidade) + (f.estado ? ' / ' + esc(f.estado) : '') : '—'}</td>
      <td>${esc(f.telefone_principal || '—')}</td>
      <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;">${esc(f.email_comercial || '—')}</td>
      <td style="font-size:11px;color:var(--text-muted);">${formatarData(f.data_cadastro)}</td>
      <td>
        <button class="btn-icon del" title="Excluir" onclick="excluirFornecedor(${f.id}, '${esc(f.razao_social)}')">
          <i class="fas fa-trash"></i>
        </button>
      </td>
    </tr>
  `).join('');
}

function esc(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function formatarData(dt) {
  if (!dt) return '—';
  const d = new Date(dt);
  return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
}

/* Busca em tempo real */
document.getElementById('buscaFornecedor').addEventListener('input', function() {
  const q = this.value.toLowerCase().trim();
  if (!q) { renderizarTabela(fornecedoresDados); return; }
  const filtrado = fornecedoresDados.filter(f =>
    (f.razao_social   || '').toLowerCase().includes(q) ||
    (f.nome_fantasia  || '').toLowerCase().includes(q) ||
    (f.cnpj           || '').toLowerCase().includes(q) ||
    (f.area_atuacao   || '').toLowerCase().includes(q) ||
    (f.cidade         || '').toLowerCase().includes(q) ||
    (f.estado         || '').toLowerCase().includes(q) ||
    (f.email_comercial|| '').toLowerCase().includes(q)
  );
  renderizarTabela(filtrado);
});

/* Excluir */
function excluirFornecedor(id, nome) {
  if (!confirm(`Excluir o fornecedor "${nome}"?\nEsta ação não pode ser desfeita.`)) return;
  fetch('eng_clin_fornecedor_delete.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'id=' + encodeURIComponent(id)
  })
  .then(r => r.json())
  .then(res => {
    if (res.ok) {
      showToast('Fornecedor excluído com sucesso.', 'success');
      carregarTabela();
    } else {
      showToast(res.msg || 'Erro ao excluir.', 'error');
    }
  })
  .catch(() => showToast('Falha na conexão.', 'error'));
}

/* Recarregar tabela após salvar */
const _salvarOriginal = salvarFornecedor;
function salvarFornecedor() {
  const razao = document.getElementById('razao_social').value.trim();
  const cnpj  = document.getElementById('cnpj').value.trim();
  if (!razao) { showToast('Razão Social é obrigatória.', 'error'); document.getElementById('razao_social').focus(); return; }
  if (!cnpj)  { showToast('CNPJ é obrigatório.', 'error'); document.getElementById('cnpj').focus(); return; }

  const campos = [
    'razao_social','nome_fantasia','area_atuacao','cnpj',
    'banco','agencia','conta','chave_pix',
    'cep','logradouro','numero','complemento','bairro','cidade','estado',
    'telefone_principal','telefone_secundario','whatsapp','email_comercial','site',
    'resp_nome','resp_cargo','resp_telefone','resp_email'
  ];
  const dados = new FormData();
  campos.forEach(c => dados.append(c, document.getElementById(c).value));

  fetch('eng_clin_fornecedor_action.php', { method: 'POST', body: dados })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        showToast('Fornecedor cadastrado com sucesso!', 'success');
        document.getElementById('formFornecedor').reset();
        carregarTabela();
      } else {
        showToast(res.msg || 'Erro ao salvar. Tente novamente.', 'error');
      }
    })
    .catch(() => showToast('Falha na conexão com o servidor.', 'error'));
}

/* Carregar ao iniciar */
carregarTabela();
</script>
<?php eng_clin_menu_painel(true); ?>
</body>
</html>
