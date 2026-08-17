<?php
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = '';   // item ativo no menu lateral
include 'conexao.php';

if (!isset($_SESSION['usuario_logado'])) { header("Location: index.html"); exit(); }

$usuario = $_SESSION['usuario_logado'];
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
$stmt->bind_param("s", $usuario); $stmt->execute();
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

date_default_timezone_set('America/Sao_Paulo');
$msg = ''; $msg_tipo = '';

// ── Próximo código disponível (busca lacunas na sequência) ────────────────
function proximo_codigo_disponivel($conn) {
    $res = $conn->query("SELECT codigo FROM engclin_cadastro_pecas ORDER BY CAST(codigo AS UNSIGNED) ASC");
    $existentes = [];
    if ($res) while ($r = $res->fetch_assoc()) $existentes[] = (int)$r['codigo'];
    if (empty($existentes)) return str_pad(1, 5, '0', STR_PAD_LEFT);
    // Buscar primeira lacuna
    for ($i = 1; $i <= max($existentes) + 1; $i++) {
        if (!in_array($i, $existentes)) return str_pad($i, 5, '0', STR_PAD_LEFT);
    }
    return str_pad(max($existentes) + 1, 5, '0', STR_PAD_LEFT);
}

// ── EXCLUIR ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excluir') {
    $id_del = intval($_POST['id'] ?? 0);
    if ($id_del > 0) {
        // Verificar se há itens de estoque usando esta peça
        $stChk = $conn->prepare("SELECT COUNT(*) AS c FROM estoque_engenharia WHERE codigo_peca=(SELECT codigo FROM engclin_cadastro_pecas WHERE id=?) AND ativo=1");
        $stChk->bind_param('i', $id_del); $stChk->execute();
        $em_uso = (int)$stChk->get_result()->fetch_assoc()['c']; $stChk->close();
        if ($em_uso > 0) {
            $msg = "Não é possível excluir: esta peça possui {$em_uso} entrada(s) no estoque.";
            $msg_tipo = 'erro';
        } else {
            $stD = $conn->prepare("DELETE FROM engclin_cadastro_pecas WHERE id=?");
            $stD->bind_param('i', $id_del);
            $stD->execute() && $stD->affected_rows > 0
                ? ($msg = 'Peça excluída com sucesso.') && ($msg_tipo = 'ok')
                : ($msg = 'Erro ao excluir.') && ($msg_tipo = 'erro');
            $stD->close();
        }
    }
}

// ── CADASTRAR ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cadastrar') {
    $desc = strtoupper(trim($_POST['descricao'] ?? ''));
    if ($desc === '') {
        $msg = 'A descrição é obrigatória.'; $msg_tipo = 'erro';
    } else {
        // Verificar duplicidade
        $stChk = $conn->prepare("SELECT id FROM engclin_cadastro_pecas WHERE descricao=? LIMIT 1");
        $stChk->bind_param('s', $desc); $stChk->execute();
        $dup = $stChk->get_result()->num_rows; $stChk->close();
        if ($dup > 0) {
            $msg = 'Já existe uma peça com esta descrição.'; $msg_tipo = 'erro';
        } else {
            $cod = proximo_codigo_disponivel($conn);
            $stI = $conn->prepare("INSERT INTO engclin_cadastro_pecas (codigo, descricao, usuario) VALUES (?,?,?)");
            $stI->bind_param('sss', $cod, $desc, $usuario);
            $stI->execute()
                ? ($msg = "Peça cadastrada! Código: {$cod}") && ($msg_tipo = 'ok')
                : ($msg = 'Erro ao cadastrar: '.$stI->error) && ($msg_tipo = 'erro');
            $stI->close();
        }
    }
}

// ── PAGINAÇÃO ─────────────────────────────────────────────────────────────
$por_pagina = 50;
$pagina     = max(1, intval($_GET['pag'] ?? 1));
$busca      = trim($_GET['busca'] ?? '');
$offset     = ($pagina - 1) * $por_pagina;

$where_sql  = $busca ? "WHERE ativo=1 AND (descricao LIKE ? OR codigo LIKE ?)" : "WHERE ativo=1";
$param_busca = "%{$busca}%";

// Total
$stTotal = $conn->prepare("SELECT COUNT(*) AS c FROM engclin_cadastro_pecas {$where_sql}");
if ($busca) { $stTotal->bind_param('ss', $param_busca, $param_busca); }
$stTotal->execute();
$total_registros = (int)$stTotal->get_result()->fetch_assoc()['c'];
$stTotal->close();
$total_paginas = max(1, ceil($total_registros / $por_pagina));

// Lista
$lista = [];
$stL = $conn->prepare("SELECT id, codigo, descricao, usuario, data_cadastro FROM engclin_cadastro_pecas {$where_sql} ORDER BY CAST(codigo AS UNSIGNED) ASC LIMIT ? OFFSET ?");
if ($busca) $stL->bind_param('ssii', $param_busca, $param_busca, $por_pagina, $offset);
else        $stL->bind_param('ii', $por_pagina, $offset);
$stL->execute();
$res_l = $stL->get_result();
while ($r = $res_l->fetch_assoc()) $lista[] = $r;
$stL->close();

$proximo_cod = proximo_codigo_disponivel($conn);
$conn->close();

$data = date('d/m/Y'); $hora = date('H:i:s');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadastro de Peças — Engenharia Clínica</title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="shortcut icon" href="logo_1.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg-page:#0f0f0f;--bg-sidebar:#141414;--bg-card:#1a1a1a;--bg-card-hover:#1f1f1f;--bg-input:#222;
  --border:rgba(255,255,255,.07);--border-hover:rgba(255,255,255,.14);--accent-steel:#a0aec0;
  --text-primary:#f0f0f0;--text-secondary:#888;--text-muted:#555;
  --sidebar-w:260px;--sidebar-collapsed:68px;--header-h:56px;
  --radius:10px;--radius-lg:16px;--transition:0.22s cubic-bezier(.4,0,.2,1);
  --font-ui:'DM Sans',sans-serif;--font-display:'Syne',sans-serif;
  --status-ok:#4ade80;--status-warn:#facc15;--status-err:#f87171;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-ui);background:var(--bg-page);color:var(--text-primary);min-height:100vh;overflow-x:hidden;display:flex;flex-direction:column}
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
.nav-item-sair:hover{background:rgba(248,113,113,.08)!important}
#sidebar.collapsed .nav-item{padding:10px 0;text-align:center;font-size:0;color:transparent;overflow:hidden;transform:none!important}
#sidebar.collapsed .nav-item::before{font-family:'Font Awesome 6 Free';font-weight:900;font-size:15px;color:#888;display:block;line-height:1}
#sidebar.collapsed .nav-item:hover::before{color:#e8e9eb}
#sidebar.collapsed .nav-item.active::before{color:#fff}
#sidebar.collapsed .nav-item::after{content:attr(data-tooltip);position:absolute;left:calc(var(--sidebar-collapsed)+8px);top:50%;transform:translateY(-50%);background:#2d2d2d;color:#e2e8f0;font-size:12px;padding:5px 10px;border-radius:6px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:200;border:1px solid var(--border-hover);box-shadow:0 4px 12px rgba(0,0,0,.4)}
#sidebar.collapsed .nav-item:hover::after{opacity:1}
#sidebar.collapsed .nav-item[data-tooltip="Dashboard"]::before{content:"\f200"}
#sidebar.collapsed .nav-item[data-tooltip="Estoque"]::before{content:"\f49e"}
#sidebar.collapsed .nav-item[data-tooltip="Cadastro de Peças"]::before{content:"\f466"}
#sidebar.collapsed .nav-item[data-tooltip="Sair"]::before{content:"\f2f5"}
#main{margin-left:var(--sidebar-w);transition:margin-left var(--transition);min-height:calc(100vh - 42px);display:flex;flex-direction:column;flex:1}
#main.sidebar-collapsed{margin-left:var(--sidebar-collapsed)}
.topbar{height:var(--header-h);background:rgba(20,20,20,.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:10px;position:sticky;top:0;z-index:50}
.topbar-breadcrumb{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted)}
.topbar-breadcrumb span:last-child{color:var(--text-primary);font-weight:500}
.topbar-breadcrumb i{font-size:10px}
.topbar-spacer{flex:1}
.topbar-logo-rede{height:32px;width:auto;object-fit:contain;opacity:.75;flex-shrink:0}
.content{flex:1;padding:28px;width:100%}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.page-title{font-family:var(--font-display);font-size:22px;font-weight:700;letter-spacing:-.01em}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none}
.btn-primary{background:#232323;border:1px solid rgba(255,255,255,.13);color:var(--text-primary)}
.btn-primary:hover{background:#2e2e2e;border-color:rgba(255,255,255,.2)}
.btn-success{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.25);color:#4ade80}
.btn-success:hover{background:rgba(74,222,128,.18)}
.btn-danger{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.25);color:#f87171}
.btn-danger:hover{background:rgba(248,113,113,.18)}
.btn-back{background:rgba(160,174,192,.08);border:1px solid rgba(160,174,192,.18);color:var(--accent-steel)}
.btn-back:hover{background:rgba(160,174,192,.15)}
.alert{padding:11px 16px;border-radius:8px;font-size:13px;font-weight:500;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.alert-ok  {background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.2);color:#4ade80}
.alert-erro{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.2);color:#f87171}

/* ── LAYOUT ── */
.two-col{display:grid;grid-template-columns:320px 1fr;gap:20px;align-items:start}
.form-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;transition:border-color var(--transition);position:sticky;top:calc(var(--header-h)+16px)}
.form-card:hover{border-color:var(--border-hover)}
.card-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.02)}
.card-icon{width:28px;height:28px;border-radius:7px;background:rgba(255,255,255,.05);display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--accent-steel);flex-shrink:0}
.card-title{font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--accent-steel);flex:1}
.card-body{padding:20px}
.field{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.field:last-of-type{margin-bottom:0}
.field-label{font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted)}
.field-label .req{color:var(--status-err)}
.field input{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:10px 13px;font-family:var(--font-ui);font-size:13px;color:var(--text-primary);outline:none;transition:border-color var(--transition),background var(--transition);width:100%}
.field input:focus{border-color:var(--border-hover);background:#272727}
.field input::placeholder{color:var(--text-muted)}
.field input:read-only{opacity:.55;cursor:default}
.codigo-preview{font-family:monospace;font-size:20px;font-weight:700;color:var(--accent-steel);background:rgba(160,174,192,.08);border:1px solid rgba(160,174,192,.2);border-radius:8px;padding:10px 14px;letter-spacing:.12em;text-align:center;margin-top:4px}

/* ── TABELA ── */
.table-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;transition:border-color var(--transition);display:flex;flex-direction:column}
.table-card:hover{border-color:var(--border-hover)}
.table-toolbar{padding:13px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:rgba(255,255,255,.02);flex-shrink:0}
.toolbar-title{font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--accent-steel);flex:1;display:flex;align-items:center;gap:8px}
.tbl-count{background:rgba(160,174,192,.12);color:var(--accent-steel);font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px}
.search-box{display:flex;align-items:center;gap:8px;background:var(--bg-input);border:1px solid var(--border);border-radius:7px;padding:6px 11px;transition:border-color var(--transition);min-width:180px}
.search-box:focus-within{border-color:var(--border-hover)}
.search-box i{font-size:11px;color:var(--text-muted);flex-shrink:0}
.search-box input{background:none;border:none;outline:none;font-size:12px;color:var(--text-primary);font-family:var(--font-ui);width:100%}
.search-box input::placeholder{color:var(--text-muted)}
.table-wrap{overflow-x:auto;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent;max-height:calc(100vh - 340px)}
table.peca-table{width:100%;border-collapse:collapse;font-size:12px}
table.peca-table thead tr{border-bottom:1px solid var(--border);position:sticky;top:0;z-index:5}
table.peca-table th{padding:9px 13px;text-align:left;font-size:10px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--text-muted);white-space:nowrap;background:#181818}
table.peca-table td{padding:10px 13px;color:var(--text-secondary);border-bottom:1px solid var(--border)}
table.peca-table tbody tr:last-child td{border-bottom:none}
table.peca-table tbody tr{cursor:pointer;transition:background var(--transition)}
table.peca-table tbody tr:hover td{background:rgba(255,255,255,.025);color:var(--text-primary)}
table.peca-table tbody tr.selected td{background:rgba(160,174,192,.08);color:var(--text-primary)}
table.peca-table tbody tr.selected td:first-child{border-left:2px solid var(--accent-steel)}
.td-primary{color:var(--text-primary)!important;font-weight:500}
.td-mono{font-family:monospace;font-size:11px;color:var(--accent-steel)!important;font-weight:700}
.table-empty{padding:44px;text-align:center;color:var(--text-muted);font-size:13px}
.table-empty i{font-size:28px;display:block;margin-bottom:10px;opacity:.3}

/* ── TOOLBAR AÇÕES ── */
.action-bar{padding:10px 16px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex-shrink:0}
.selected-info{font-size:11px;color:var(--text-muted);flex:1}
.selected-info strong{color:var(--text-primary)}

/* ── PAGINAÇÃO ── */
.pagination{display:flex;align-items:center;gap:6px;padding:10px 16px;border-top:1px solid var(--border);flex-shrink:0;flex-wrap:wrap}
.pag-info{font-size:11px;color:var(--text-muted);flex:1}
.pag-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid var(--border);background:none;color:var(--text-muted);font-size:11px;cursor:pointer;text-decoration:none;transition:background var(--transition),color var(--transition),border-color var(--transition)}
.pag-btn:hover{background:#2a2a2a;color:var(--text-primary);border-color:var(--border-hover)}
.pag-btn.active{background:rgba(160,174,192,.1);color:var(--accent-steel);border-color:rgba(160,174,192,.3);font-weight:700}
.pag-btn.disabled{opacity:.3;pointer-events:none}

/* ── TOAST ── */
#toast{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;align-items:center;gap:10px;background:#1e1e1e;border:1px solid var(--border-hover);border-radius:10px;padding:12px 18px;font-size:13px;color:var(--text-primary);box-shadow:0 8px 32px rgba(0,0,0,.5);opacity:0;transform:translateY(10px);pointer-events:none;transition:opacity .3s,transform .3s;max-width:340px}
#toast.show{opacity:1;transform:translateY(0);pointer-events:auto}
#toast.ok i{color:#4ade80}
#toast.erro i{color:#f87171}
.footer{background:#181818;color:#888;border-top:1px solid var(--border);padding:12px 20px;display:flex;justify-content:space-between;flex-wrap:wrap;font-size:13px;gap:6px;margin-left:var(--sidebar-w);transition:margin-left var(--transition)}
.footer div{color:#666}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeUp .32s ease both}
.delay-1{animation-delay:.06s}
.delay-2{animation-delay:.12s}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.07);border-radius:4px}
@media(max-width:1050px){.two-col{grid-template-columns:1fr}.form-card{position:static}}
@media(max-width:900px){.content{padding:16px}.footer,#main{margin-left:var(--sidebar-collapsed)}#sidebar{width:var(--sidebar-collapsed)}#sidebar.open{width:var(--sidebar-w)}}
@media(max-width:640px){#sidebar{position:fixed;top:0;left:0;height:100vh;z-index:1100;transform:translateX(-100%);transition:transform var(--transition);width:var(--sidebar-w)!important}#sidebar.open{transform:translateX(0)}.sidebar-toggle{display:none}.menu-toggle{display:block}#main{margin-left:0!important}.topbar{padding-left:54px}.content{padding:12px}.footer{margin-left:0}.search-box{min-width:unset;width:100%}}
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
      <span>Cadastro de Peças</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
      <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <div class="page-header fade-up">
      <div>
        <div class="page-title">Cadastro de Peças</div>
        <div class="page-subtitle">Catálogo de insumos e materiais — Engenharia Clínica</div>
      </div>
      
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msg_tipo ?> fade-up">
      <i class="fas <?= $msg_tipo === 'ok' ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <div class="two-col fade-up delay-1">

      <!-- ── FORMULÁRIO ── -->
      <div class="form-card">
        <div class="card-header">
          <div class="card-icon"><i class="fas fa-plus"></i></div>
          <span class="card-title">Nova Peça / Insumo</span>
        </div>
        <div class="card-body">
          <form method="POST" id="formCadastro" autocomplete="off">
            <input type="hidden" name="action" value="cadastrar">

            <div class="field">
              <label class="field-label">Próximo Código Disponível</label>
              <div class="codigo-preview"><?= htmlspecialchars($proximo_cod) ?></div>
            </div>

            <div class="field" style="margin-top:16px">
              <label class="field-label">Nome / Descrição <span class="req">*</span></label>
              <input type="text" name="descricao" id="inputDesc"
                     placeholder="Ex: Cabo de Fibra Óptica, Mangueira de Esteto..."
                     style="text-transform:uppercase"
                     required>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
              <button type="reset" class="btn btn-primary">
                <i class="fas fa-rotate-left"></i> Limpar
              </button>
              <button type="submit" class="btn btn-success">
                <i class="fas fa-floppy-disk"></i> Cadastrar
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- ── TABELA ── -->
      <div class="table-card fade-up delay-2">
        <div class="table-toolbar">
          <div class="toolbar-title">
            <i class="fas fa-list" style="font-size:11px"></i>
            Peças Cadastradas
            <span class="tbl-count"><?= $total_registros ?></span>
          </div>
          <form method="GET" style="display:flex;align-items:center;gap:8px">
            <div class="search-box">
              <i class="fas fa-search"></i>
              <input type="text" name="busca" placeholder="Buscar código ou descrição..."
                     value="<?= htmlspecialchars($busca) ?>"
                     onchange="this.form.submit()">
            </div>
            <?php if ($busca): ?>
            <a href="eng_clin_cadastro_pecas.php" class="btn btn-primary" style="padding:6px 12px">
              <i class="fas fa-xmark"></i>
            </a>
            <?php endif; ?>
          </form>
        </div>

        <div class="table-wrap">
          <table class="peca-table" id="tblPecas">
            <thead>
              <tr>
                <th style="width:90px">Código</th>
                <th>Descrição</th>
                <th style="width:130px">Cadastrado por</th>
                <th style="width:110px">Data</th>
              </tr>
            </thead>
            <tbody id="tblBody">
            <?php if (empty($lista)): ?>
              <tr><td colspan="4" class="table-empty">
                <i class="fas fa-box-open"></i>
                <?= $busca ? 'Nenhum resultado para "'.$busca.'".' : 'Nenhuma peça cadastrada ainda.' ?>
              </td></tr>
            <?php else: ?>
              <?php foreach ($lista as $item): ?>
              <tr data-id="<?= $item['id'] ?>" onclick="selecionarLinha(this)">
                <td class="td-mono"><?= htmlspecialchars($item['codigo']) ?></td>
                <td class="td-primary"><?= htmlspecialchars($item['descricao']) ?></td>
                <td style="font-size:11px"><?= htmlspecialchars($item['usuario'] ?? '—') ?></td>
                <td style="font-size:11px;color:var(--text-muted)"><?= date('d/m/Y', strtotime($item['data_cadastro'])) ?></td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Action bar -->
        <div class="action-bar">
          <span class="selected-info" id="selectedInfo">
            Clique em uma linha para selecioná-la.
          </span>
          <form method="POST" id="formExcluir" onsubmit="return confirmarExclusao()">
            <input type="hidden" name="action" value="excluir">
            <input type="hidden" name="id" id="hiddenIdExcluir" value="0">
            <?php if ($busca): ?>
            <input type="hidden" name="busca_redirect" value="<?= htmlspecialchars($busca) ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-danger" id="btnExcluir" disabled>
              <i class="fas fa-trash"></i> Excluir Selecionado
            </button>
          </form>
        </div>

        <!-- Paginação -->
        <?php if ($total_paginas > 1): ?>
        <div class="pagination">
          <span class="pag-info">
            Página <?= $pagina ?> de <?= $total_paginas ?> &middot;
            <?= $total_registros ?> registros
          </span>
          <?php
          $url_base = '?'.http_build_query(array_filter(['busca'=>$busca])).'&pag=';
          // Prev
          if ($pagina > 1):
          ?>
          <a href="<?= $url_base.($pagina-1) ?>" class="pag-btn"><i class="fas fa-chevron-left"></i></a>
          <?php endif; ?>
          <?php
          $inicio = max(1, $pagina - 2);
          $fim    = min($total_paginas, $pagina + 2);
          for ($p = $inicio; $p <= $fim; $p++):
          ?>
          <a href="<?= $url_base.$p ?>" class="pag-btn <?= $p === $pagina ? 'active' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($pagina < $total_paginas): ?>
          <a href="<?= $url_base.($pagina+1) ?>" class="pag-btn"><i class="fas fa-chevron-right"></i></a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<div class="footer" id="pageFooter">
  <div>Usuário: <?= htmlspecialchars($usuario) ?> · Nível: <?= htmlspecialchars($nivel) ?></div>
  <div>Data: <?= $data ?> | Hora: <span id="hora"><?= $hora ?></span></div>
  <div>&copy; GK Soluções</div>
</div>

<div id="toast"><i class="fas fa-circle-check" id="toastIcon"></i><span id="toastMsg" style="margin-left:4px"></span></div>

<script>
setInterval(()=>{ document.getElementById('hora').innerText=new Date().toLocaleTimeString('pt-BR'); },1000);

// Sidebar
const sidebar=document.getElementById('sidebar'),mainArea=document.getElementById('main'),footer=document.getElementById('pageFooter'),toggleBtn=document.getElementById('toggleBtn'),toggleIcon=document.getElementById('toggleIcon');
function syncFooter(c){if(footer)footer.style.marginLeft=c?'var(--sidebar-collapsed)':'var(--sidebar-w)';}
if(toggleBtn)toggleBtn.addEventListener('click',()=>{const c=sidebar.classList.toggle('collapsed');mainArea.classList.toggle('sidebar-collapsed',c);toggleIcon.classList.toggle('fa-chevron-left',!c);toggleIcon.classList.toggle('fa-chevron-right',c);syncFooter(c);});
document.getElementById('menuToggle').onclick=()=>{sidebar.classList.add('open');document.getElementById('sidebarOverlay').classList.add('open');};
function fecharSidebar(){sidebar.classList.remove('open');document.getElementById('sidebarOverlay').classList.remove('open');}

// Uppercase no input
document.getElementById('inputDesc').addEventListener('input',function(){this.value=this.value.toUpperCase();});

// Seleção de linha
let selectedId = 0, selectedDesc = '';
function selecionarLinha(tr) {
  // Deselect all
  document.querySelectorAll('#tblBody tr.selected').forEach(r=>r.classList.remove('selected'));
  if (selectedId == tr.dataset.id) {
    // Clicou na mesma → desselecionar
    selectedId = 0; selectedDesc = '';
    document.getElementById('hiddenIdExcluir').value = 0;
    document.getElementById('btnExcluir').disabled = true;
    document.getElementById('selectedInfo').innerHTML = 'Clique em uma linha para selecioná-la.';
  } else {
    tr.classList.add('selected');
    selectedId   = tr.dataset.id;
    selectedDesc = tr.querySelector('.td-primary')?.innerText || '';
    document.getElementById('hiddenIdExcluir').value = selectedId;
    document.getElementById('btnExcluir').disabled = false;
    document.getElementById('selectedInfo').innerHTML = `Selecionado: <strong>${selectedDesc}</strong>`;
  }
}

function confirmarExclusao() {
  if (!selectedId || selectedId == 0) return false;
  return confirm(`Excluir a peça "${selectedDesc}"?\n\nEsta ação não pode ser desfeita.`);
}

// Toast após feedback do servidor
<?php if ($msg): ?>
setTimeout(()=>{
  const t=document.getElementById('toast');
  document.getElementById('toastMsg').textContent=<?= json_encode($msg) ?>;
  const tipo=<?= json_encode($msg_tipo) ?>;
  document.getElementById('toastIcon').className='fas '+(tipo==='ok'?'fa-circle-check':'fa-circle-xmark');
  t.className='show '+tipo;
  setTimeout(()=>{t.className='';},4000);
},200);
<?php endif; ?>
</script>
<?php eng_clin_menu_painel(true); ?>
</body>
</html>