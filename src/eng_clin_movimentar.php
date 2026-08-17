<?php
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = 'movimentar';   // item ativo no menu lateral
mysqli_report(MYSQLI_REPORT_OFF);
include 'conexao.php';

if (!isset($_SESSION['usuario_logado'])) { header("Location: index.html"); exit(); }
$usuario = $_SESSION['usuario_logado'];

$nivel = 'C'; $classe_usuario = ''; $status_u = 'ATIVO';
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
$stmt->bind_param("s", $usuario); $stmt->execute();
if ($r = $stmt->get_result()->fetch_assoc()) {
    $nivel          = strtoupper(trim($r['permicao']    ?? 'C'));
    $classe_usuario = strtoupper(trim($r['classe_usuario'] ?? ''));
    $status_u       = $r['status'] ?? 'ATIVO';
}
$stmt->close();

if ($status_u !== 'ATIVO') { session_destroy(); header("Location: index.html?erro=bloqueado"); exit(); }
$is_dev = ($classe_usuario === 'DEV' || $nivel === 'DEV');
if (!$is_dev && !in_array($classe_usuario, ['ENGENHARIA CLINICA'])) { header("Location: acesso_bloqueado.html"); exit(); }

date_default_timezone_set('America/Sao_Paulo');
$data_hoje  = date('Y-m-d');
$hora_agora = date('H:i:s');

// ── AJAX: Buscar item por ID (vindo da planilha) ─────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'buscar_por_id') {
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['encontrado' => false]); exit; }
    $st = $conn->prepare("SELECT id, descricao, marca, modelo, serie, tag_antiga, tag_trocada, unidade_destino, setor_destino, area_destino, unidade, setor, status, estado FROM cadastro WHERE id=? LIMIT 1");
    $st->bind_param('i', $id); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if ($row) {
        echo json_encode(['encontrado' => true, 'item_id' => (int)$row['id'], 'descricao' => $row['descricao'] ?? '', 'marca' => $row['marca'] ?? '', 'modelo' => $row['modelo'] ?? '', 'serie' => $row['serie'] ?? '', 'tag' => $row['tag_trocada'] ?: ($row['tag_antiga'] ?? ''), 'unidade_atual' => $row['unidade_destino'] ?: ($row['unidade'] ?? ''), 'setor_atual' => $row['setor_destino'] ?: ($row['setor'] ?? ''), 'area_atual' => $row['area_destino'] ?? '', 'status' => $row['status'] ?? '', 'estado' => $row['estado'] ?? '']);
    } else {
        echo json_encode(['encontrado' => false]);
    }
    exit;
}

// ── AJAX: Buscar item por tag ou série ────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'buscar_item') {
    header('Content-Type: application/json; charset=utf-8');
    $busca = strtoupper(trim($_GET['q'] ?? ''));
    $campo = trim($_GET['campo'] ?? 'tag');
    if ($busca === '') { echo json_encode(['encontrado'=>false]); exit; }

    if ($campo === 'serie') {
        $st = $conn->prepare("SELECT id, descricao, marca, modelo, serie, tag_antiga, tag_trocada, unidade_destino, setor_destino, area_destino, unidade, setor, status, estado FROM cadastro WHERE UPPER(TRIM(serie))=? LIMIT 1");
        $st->bind_param('s',$busca);
    } else {
        $st = $conn->prepare("SELECT id, descricao, marca, modelo, serie, tag_antiga, tag_trocada, unidade_destino, setor_destino, area_destino, unidade, setor, status, estado FROM cadastro WHERE UPPER(TRIM(tag_antiga))=? OR UPPER(TRIM(tag_trocada))=? OR UPPER(TRIM(tag_alugado))=? LIMIT 1");
        $st->bind_param('sss',$busca,$busca,$busca);
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();

    if ($row) {
        echo json_encode(['encontrado'=>true, 'item_id'=>(int)$row['id'], 'descricao'=>$row['descricao']??'', 'marca'=>$row['marca']??'', 'modelo'=>$row['modelo']??'', 'serie'=>$row['serie']??'', 'tag'=>$row['tag_trocada']?:($row['tag_antiga']??''), 'unidade_atual'=>$row['unidade_destino']?:($row['unidade']??''), 'setor_atual'=>$row['setor_destino']?:($row['setor']??''), 'area_atual'=>$row['area_destino']??'', 'status'=>$row['status']??'', 'estado'=>$row['estado']??'']);
    } else {
        echo json_encode(['encontrado'=>false]);
    }
    exit;
}

// ── POST: Executar movimentação ───────────────────────────────────────────────
$msg = ''; $msg_tipo = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='movimentar') {
    $item_id       = intval($_POST['item_id']        ?? 0);
    $uni_dest      = strtoupper(trim($_POST['uni_dest']   ?? ''));
    $set_dest      = strtoupper(trim($_POST['set_dest']   ?? ''));
    $area_dest     = strtoupper(trim($_POST['area_dest']  ?? ''));
    $tipo_mov      = trim($_POST['tipo_mov']          ?? 'INTERNA');
    $obs           = trim($_POST['obs']               ?? '');
    $valid_tipos   = ['INTERNA','DEFINITIVA','EMPRESTIMO','RETORNO','MANUTENCAO'];
    if (!in_array($tipo_mov,$valid_tipos)) $tipo_mov='INTERNA';

    if (!$item_id || !$uni_dest || !$set_dest) {
        $msg = 'Informe o item e o destino completo (unidade e setor).'; $msg_tipo = 'erro';
    } else {
        try {
            // Buscar dados atuais do item
            $stBk = $conn->prepare("SELECT tag_antiga, tag_trocada, descricao, marca, modelo, serie, unidade, setor, unidade_destino, setor_destino, status FROM cadastro WHERE id=? LIMIT 1");
            $stBk->bind_param('i',$item_id); $stBk->execute();
            $itemAtual = $stBk->get_result()->fetch_assoc(); $stBk->close();

            if (!$itemAtual) throw new Exception("Item não encontrado.");

            $tag_mov  = $itemAtual['tag_trocada'] ?: $itemAtual['tag_antiga'];
            $uni_orig = $itemAtual['unidade_destino'] ?: $itemAtual['unidade'];
            $set_orig = $itemAtual['setor_destino']   ?: $itemAtual['setor'];

            // Atualizar localização no cadastro
            $stUpd = $conn->prepare("UPDATE cadastro SET unidade_destino=?, setor_destino=?, area_destino=?, obs_movimentacao=?, usuario_movimentacao=?, data_movimentacao=? WHERE id=?");
            $stUpd->bind_param('ssssssi',$uni_dest,$set_dest,$area_dest,$obs,$usuario,$data_hoje,$item_id);
            $stUpd->execute(); $stUpd->close();

            // Registrar no histórico patrimonial (colunas corretas = historico.php)
            $pav_dest_vazio = '';
            $stH = $conn->prepare("INSERT INTO historico (data, descricao, marca, modelo, serie, tag, unidade, setor, unidade_dest, setor_dest, pav_dest, local_dest, obs_mov, tipo_mov, usuario_mov) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if ($stH) {
                $stH->bind_param('sssssssssssssss',
                    $data_hoje,
                    $itemAtual['descricao'], $itemAtual['marca'], $itemAtual['modelo'], $itemAtual['serie'],
                    $tag_mov,
                    $uni_orig, $set_orig,
                    $uni_dest, $set_dest, $pav_dest_vazio, $area_dest,
                    $obs, $tipo_mov, $usuario
                );
                $stH->execute(); $stH->close();
            }

            // Notificar PatAsset por e-mail
            require_once __DIR__ . '/eng_clin_notificar_movimentacao.php';
            eng_clin_notificar_movimentacao([
                'tag'       => $tag_mov,
                'descricao' => $itemAtual['descricao'],
                'marca'     => $itemAtual['marca']  ?? '',
                'modelo'    => $itemAtual['modelo'] ?? '',
                'serie'     => $itemAtual['serie']  ?? '',
                'uni_orig'  => $uni_orig,
                'set_orig'  => $set_orig,
                'uni_dest'  => $uni_dest,
                'set_dest'  => $set_dest,
                'area_dest' => $area_dest,
                'tipo_mov'  => $tipo_mov,
                'obs'       => $obs,
                'usuario'   => $usuario,
                'data'      => $data_hoje,
            ]);

            $msg = "Movimentação registrada! {$tag_mov} → {$uni_dest} / {$set_dest}" . ($area_dest ? " / {$area_dest}" : "");
            $msg_tipo = 'ok';
        } catch (Throwable $e) {
            $msg = 'Erro ao registrar movimentação: '.$e->getMessage(); $msg_tipo = 'erro';
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Movimentar Equipamento — Engenharia Clínica</title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg-page:#0f0f0f;--bg-sidebar:#141414;--bg-card:#1a1a1a;--bg-card2:#1e1e1e;
  --bg-input:#222;--border:rgba(255,255,255,0.07);--border-hover:rgba(255,255,255,0.14);
  --border-focus:rgba(255,255,255,0.28);--accent-steel:#a0aec0;
  --text-primary:#f0f0f0;--text-secondary:#888;--text-muted:#555;
  --sidebar-w:260px;--sidebar-collapsed:68px;--header-h:56px;
  --status-ok:#4ade80;--status-err:#f87171;--status-warn:#facc15;
  --radius:10px;--radius-lg:16px;--transition:0.22s cubic-bezier(0.4,0,0.2,1);
  --font-ui:'DM Sans',sans-serif;--font-display:'Syne',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-ui);background:var(--bg-page);color:var(--text-primary);min-height:100vh;display:flex;flex-direction:column}

/* ── SIDEBAR ── */
.menu-toggle{display:none;position:fixed;top:10px;left:10px;z-index:1200;background:#2a2a2a;color:#e2e8f0;border:1px solid var(--border-hover);border-radius:8px;padding:8px 12px;font-size:20px;cursor:pointer}
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
.nav-item-voltar{color:#a0aec0!important;border-top:1px solid var(--border);margin-top:6px!important}
.nav-item-voltar:hover{background:rgba(160,174,192,.08)!important}
#sidebar.collapsed .nav-item{padding:10px 0;text-align:center;font-size:0;color:transparent;overflow:hidden;transform:none!important}
#sidebar.collapsed .nav-item::before{font-family:'Font Awesome 6 Free';font-weight:900;font-size:15px;color:#888;display:block;line-height:1;transition:color var(--transition)}
#sidebar.collapsed .nav-item:hover::before{color:#e8e9eb}
#sidebar.collapsed .nav-item.active::before{color:#fff}
#sidebar.collapsed .nav-item::after{content:attr(data-tooltip);position:absolute;left:calc(var(--sidebar-collapsed)+8px);top:50%;transform:translateY(-50%);background:#2d2d2d;color:#e2e8f0;font-size:12px;padding:5px 10px;border-radius:6px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:200;border:1px solid var(--border-hover);box-shadow:0 4px 12px rgba(0,0,0,.4)}
#sidebar.collapsed .nav-item:hover::after{opacity:1}
#sidebar.collapsed .nav-item[data-tooltip="Abertura de Chamado"]::before   {content:"\f46d"}
#sidebar.collapsed .nav-item[data-tooltip="Cadastro de Equipamento"]::before{content:"\f0fe"}
#sidebar.collapsed .nav-item[data-tooltip="Planilha"]::before              {content:"\f0ce"}
#sidebar.collapsed .nav-item[data-tooltip="Ordem de Serviço"]::before      {content:"\f46d"}
#sidebar.collapsed .nav-item[data-tooltip="Estoque"]::before               {content:"\f49e"}
#sidebar.collapsed .nav-item[data-tooltip="Movimentar"]::before            {content:"\f362"}
#sidebar.collapsed .nav-item[data-tooltip="Página Inicial"]::before        {content:"\f200"}
#sidebar.collapsed .nav-item[data-tooltip="Voltar"]::before                {content:"\f060"}

/* ── MAIN ── */
#main{margin-left:var(--sidebar-w);transition:margin-left var(--transition);min-height:100vh;display:flex;flex-direction:column;flex:1}
#main.sidebar-collapsed{margin-left:var(--sidebar-collapsed)}
.topbar{height:var(--header-h);background:#141414;border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 24px;gap:12px;position:sticky;top:0;z-index:50;flex-shrink:0}
.topbar-breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary);flex:1}
.topbar-breadcrumb span:last-child{color:var(--text-primary);font-weight:500}
.topbar-breadcrumb i{font-size:10px;color:var(--text-muted)}
.topbar-logo-rede{height:32px;width:auto;object-fit:contain;opacity:.75;transition:opacity var(--transition);flex-shrink:0}
.topbar-logo-rede:hover{opacity:1}
.content{flex:1;padding:28px 28px;max-width:860px;width:100%}

/* ── CARDS ── */
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:20px}
.card-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--bg-card2)}
.card-icon{width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,.05);display:flex;align-items:center;justify-content:center;font-size:13px;color:var(--accent-steel);flex-shrink:0}
.card-title{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--accent-steel)}
.card-body{padding:20px 18px}

/* ── ALERT ── */
.alert{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:20px;font-weight:500}
.alert-ok  {background:rgba(74,222,128,.07);border:1px solid rgba(74,222,128,.2);color:#4ade80}
.alert-erro{background:rgba(248,113,113,.07);border:1px solid rgba(248,113,113,.2);color:#f87171}
.alert-warn{background:rgba(250,204,21,.07);border:1px solid rgba(250,204,21,.2);color:#facc15}

/* ── FORM ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid .full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group label{font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted)}
.form-control{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-family:var(--font-ui);font-size:14px;padding:10px 13px;outline:none;width:100%;transition:border-color var(--transition),background var(--transition);-webkit-appearance:none;appearance:none}
.form-control:focus{border-color:var(--border-focus);background:#272727}
.form-control::placeholder{color:var(--text-muted)}
.form-control.found{border-color:rgba(74,222,128,.5)!important}
.form-control.not-found{border-color:rgba(248,113,113,.4)!important}
select.form-control{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23666' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;background-size:12px;padding-right:36px;cursor:pointer}
select.form-control option{background:#222;color:#f0f0f0}
textarea.form-control{resize:vertical;min-height:80px;line-height:1.5}

/* ── BUSCA ── */
.search-wrap{position:relative}
.search-wrap .form-control{padding-right:42px}
.search-spinner{position:absolute;right:13px;top:50%;transform:translateY(-50%);font-size:13px;color:var(--text-muted);display:none;pointer-events:none}
.search-spinner.visible{display:block}

/* ── ITEM ENCONTRADO ── */
.item-card{display:none;padding:14px;background:rgba(74,222,128,.05);border:1px solid rgba(74,222,128,.2);border-radius:10px;margin-top:10px;animation:fadeUp .2s ease}
.item-card.visible{display:block}
.item-card-title{font-size:13px;font-weight:600;color:var(--status-ok);margin-bottom:10px;display:flex;align-items:center;gap:8px}
.item-card-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.item-card-field{background:rgba(255,255,255,.03);border-radius:6px;padding:8px 10px}
.item-card-field .label{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:3px}
.item-card-field .value{font-size:12px;color:var(--text-primary)}
.item-notfound{display:none;padding:10px 13px;background:rgba(248,113,113,.06);border:1px solid rgba(248,113,113,.2);border-radius:8px;font-size:12px;color:var(--status-err);margin-top:8px;animation:fadeUp .2s ease}
.item-notfound.visible{display:block}

/* ── BOTÃO ── */
.btn-submit{padding:13px 24px;border-radius:10px;background:linear-gradient(135deg,#2a2a2a,#333);border:1px solid rgba(255,255,255,.15);color:var(--text-primary);font-family:var(--font-ui);font-size:14px;font-weight:600;cursor:pointer;transition:all var(--transition);display:flex;align-items:center;gap:8px}
.btn-submit:hover{background:linear-gradient(135deg,#333,#3d3d3d);border-color:rgba(255,255,255,.25);transform:translateY(-1px)}

/* ── HISTÓRICO ── */
.hist-table{width:100%;border-collapse:collapse;font-size:12px}
.hist-table th{text-align:left;padding:8px 12px;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border)}
.hist-table td{padding:9px 12px;border-bottom:1px solid var(--border);color:var(--text-secondary)}
.hist-table tr:last-child td{border-bottom:none}
.hist-table tr:hover td{background:rgba(255,255,255,.02);color:var(--text-primary)}
.tipo-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.tipo-INTERNA     {background:rgba(160,174,192,.1);color:#a0aec0;border:1px solid rgba(160,174,192,.2)}
.tipo-DEFINITIVA  {background:rgba(248,113,113,.1);color:#f87171;border:1px solid rgba(248,113,113,.2)}
.tipo-EMPRESTIMO  {background:rgba(250,204,21,.1);color:#facc15;border:1px solid rgba(250,204,21,.2)}
.tipo-RETORNO     {background:rgba(74,222,128,.1);color:#4ade80;border:1px solid rgba(74,222,128,.2)}
.tipo-MANUTENCAO  {background:rgba(251,146,60,.1);color:#fb923c;border:1px solid rgba(251,146,60,.2)}

@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

@media(max-width:768px){
  .form-grid{grid-template-columns:1fr}
  .form-grid .full{grid-column:1}
  .item-card-grid{grid-template-columns:1fr 1fr}
  .content{padding:16px 14px}
  #main{margin-left:0!important}
  .menu-toggle{display:block}
  #sidebar{transform:translateX(-100%);width:var(--sidebar-w)!important;transition:transform var(--transition)}
  #sidebar.mobile-open{transform:translateX(0)}
  .topbar{padding:0 16px}
  .topbar-logo-rede{display:none}
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
      <span>Movimentar Equipamento</span>
    </div>
    <div style="flex:1"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
      <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msg_tipo ?>">
      <i class="fas fa-<?= $msg_tipo==='ok'?'circle-check':($msg_tipo==='warn'?'triangle-exclamation':'circle-xmark') ?>"></i>
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <?php
      // Chegou aqui pelo encerramento de uma OS de manutenção externa:
      // a devolução não é automática, o técnico escolhe o destino.
      $os_enc = trim($_GET['os_encerrada'] ?? '');
      if ($os_enc !== ''):
    ?>
    <div class="alert alert-warn" style="align-items:flex-start">
      <i class="fas fa-truck"></i>
      <div style="line-height:1.6">
        A OS <strong><?= htmlspecialchars($os_enc) ?></strong> foi encerrada e passou por
        <strong>manutenção externa</strong>. O equipamento já está carregado abaixo —
        informe para onde ele vai.
        <a href="eng_clin_os.php?protocolo=<?= urlencode($os_enc) ?>" style="color:inherit;text-decoration:underline;margin-left:6px">
          Ver a OS
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Busca de Item ── -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><i class="fas fa-magnifying-glass"></i></div>
        <span class="card-title">Localizar Equipamento</span>
      </div>
      <div class="card-body">
        <div class="form-grid">
          <div class="form-group">
            <label>Tag de Patrimônio</label>
            <div class="search-wrap">
              <input type="text" id="inputTag" class="form-control" placeholder="Ex: HCSC 001234"
                     style="text-transform:uppercase" autocomplete="off">
              <i class="fas fa-circle-notch fa-spin search-spinner" id="spinnerTag"></i>
            </div>
            <div class="item-notfound" id="nfTag"></div>
          </div>
          <div class="form-group">
            <label>Número de Série</label>
            <div class="search-wrap">
              <input type="text" id="inputSerie" class="form-control" placeholder="Nº de série"
                     autocomplete="off">
              <i class="fas fa-circle-notch fa-spin search-spinner" id="spinnerSerie"></i>
            </div>
            <div class="item-notfound" id="nfSerie"></div>
          </div>
        </div>

        <div class="item-card" id="itemCard">
          <div class="item-card-title">
            <i class="fas fa-circle-check"></i>
            <span id="itemCardNome"></span>
          </div>
          <div class="item-card-grid">
            <div class="item-card-field"><div class="label">Marca / Modelo</div><div class="value" id="iMarca">—</div></div>
            <div class="item-card-field"><div class="label">Série</div><div class="value" id="iSerie">—</div></div>
            <div class="item-card-field"><div class="label">Tag</div><div class="value" id="iTag">—</div></div>
            <div class="item-card-field"><div class="label">Localização Atual</div><div class="value" id="iLocal">—</div></div>
            <div class="item-card-field"><div class="label">Estado</div><div class="value" id="iEstado">—</div></div>
            <div class="item-card-field"><div class="label">ID Cadastro</div><div class="value" id="iId">—</div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Formulário de Movimentação ── -->
    <div class="card" id="formCard" style="display:none">
      <div class="card-header">
        <div class="card-icon"><i class="fas fa-right-left"></i></div>
        <span class="card-title">Registrar Movimentação</span>
      </div>
      <div class="card-body">
        <form method="POST" id="formMov">
          <input type="hidden" name="action"  value="movimentar">
          <input type="hidden" name="item_id" id="hiddenItemId" value="">

          <div class="form-grid">
            <div class="form-group">
              <label>Unidade de Destino *</label>
              <select name="uni_dest" class="form-control" id="selUniDest" required>
                <option value="">Selecione a unidade...</option>
                <option value="CASA DE PORTUGAL">Casa de Portugal</option>
                <option value="EVANGELICO">Evangélico</option>
                <option value="EGAS MONIZ">Egas Moniz</option>
                <option value="ILHA DO GOVERNADOR">Ilha do Governador</option>
                <option value="RIO LARANJEIRAS">Rio Laranjeiras</option>
                <option value="RIO BOTAFOGO">Rio Botafogo</option>
                <option value="PRONTOCOR">Prontocor</option>
                <option value="SANTA CRUZ">Santa Cruz</option>
                <option value="PREMIUM">Premium</option>
                <option value="MENSSANA">Menssana</option>
                <option value="SÃO BERNARDO">São Bernardo</option>
                <option value="OFTALMOCASA">Oftalmocasa</option>
                <option value="ENGENHARIA CLINICA">Engenharia Clínica</option>
                <option value="OUTRO">Outro</option>
              </select>
              <div style="display:none;margin-top:6px" id="wrapUniOutro">
                <input type="text" id="inputUniOutro" class="form-control" placeholder="Digite a unidade..." style="text-transform:uppercase">
              </div>
            </div>

            <div class="form-group">
              <label>Setor de Destino *</label>
              <input type="text" name="set_dest" class="form-control" id="inputSetDest"
                     placeholder="Ex: CTI 1, EMERGENCIA..." style="text-transform:uppercase" required>
            </div>

            <div class="form-group">
              <label>Área / Local <span style="font-weight:400;text-transform:none;font-size:10px">(leito, sala...)</span></label>
              <input type="text" name="area_dest" class="form-control"
                     placeholder="Ex: Leito 05, Sala de Parto..." style="text-transform:uppercase">
            </div>

            <div class="form-group">
              <label>Tipo de Movimentação</label>
              <select name="tipo_mov" class="form-control">
                <option value="INTERNA">Interna (mesmo hospital)</option>
                <option value="DEFINITIVA">Definitiva (transferência)</option>
                <option value="EMPRESTIMO">Empréstimo</option>
                <option value="RETORNO">Retorno de empréstimo</option>
                <option value="MANUTENCAO">Para manutenção</option>
              </select>
            </div>

            <div class="form-group full">
              <label>Observação</label>
              <textarea name="obs" class="form-control" placeholder="Motivo da movimentação, responsável, referência de chamado..."></textarea>
            </div>
          </div>

          <div style="display:flex;justify-content:flex-end;margin-top:6px">
            <button type="submit" class="btn-submit">
              <i class="fas fa-right-left"></i> Registrar Movimentação
            </button>
          </div>
        </form>
      </div>
    </div>


  </div><!-- /.content -->
</div><!-- /#main -->

<script>
const ID_PRECARREGADO = <?= intval($_GET['id'] ?? 0) ?>;

// ── Sidebar toggle ──
const sidebar = document.getElementById('sidebar');
const mainEl  = document.getElementById('main');
const chevron = document.getElementById('chevronToggle');
let isCollapsed = localStorage.getItem('ecMovSidebar') === '1';
if (isCollapsed) { sidebar.classList.add('collapsed'); mainEl.classList.add('sidebar-collapsed'); chevron.classList.replace('fa-chevron-left','fa-chevron-right'); }
function toggleSidebar(){
  isCollapsed = !isCollapsed;
  sidebar.classList.toggle('collapsed', isCollapsed);
  mainEl.classList.toggle('sidebar-collapsed', isCollapsed);
  chevron.classList.toggle('fa-chevron-left',!isCollapsed);
  chevron.classList.toggle('fa-chevron-right', isCollapsed);
  localStorage.setItem('ecMovSidebar', isCollapsed?'1':'0');
}
function toggleSidebarMobile(){ sidebar.classList.add('mobile-open'); document.getElementById('sidebarOverlay').classList.add('open'); }
function fecharSidebar(){ sidebar.classList.remove('mobile-open'); document.getElementById('sidebarOverlay').classList.remove('open'); }

// ── Busca de item ──
let buscaTimer = null;
let itemAtual  = null;

function buscarItem(campo, valor) {
  valor = valor.trim();
  const spinner   = document.getElementById(campo==='tag'?'spinnerTag':'spinnerSerie');
  const nfCard    = document.getElementById(campo==='tag'?'nfTag':'nfSerie');
  const inputEl   = document.getElementById(campo==='tag'?'inputTag':'inputSerie');
  const itemCard  = document.getElementById('itemCard');
  const formCard  = document.getElementById('formCard');

  nfCard.classList.remove('visible');
  inputEl.classList.remove('found','not-found');
  if (valor.length < 2) { itemCard.classList.remove('visible'); formCard.style.display='none'; return; }

  spinner.classList.add('visible');
  clearTimeout(buscaTimer);
  buscaTimer = setTimeout(() => {
    fetch(`eng_clin_movimentar.php?action=buscar_item&campo=${campo}&q=${encodeURIComponent(valor.toUpperCase())}`)
      .then(r=>r.json())
      .then(data => {
        spinner.classList.remove('visible');
        if (data.encontrado) {
          itemAtual = data;
          preencherItemCard(data);
          inputEl.classList.add('found');
          itemCard.classList.add('visible');
          formCard.style.display = 'block';
          document.getElementById('hiddenItemId').value = data.item_id;
          // Preencher campo oposto se vazio
          if (campo==='tag' && !document.getElementById('inputSerie').value.trim()) document.getElementById('inputSerie').value = data.serie||'';
          if (campo==='serie' && !document.getElementById('inputTag').value.trim()) document.getElementById('inputTag').value = data.tag||'';
        } else {
          itemAtual = null;
          itemCard.classList.remove('visible');
          formCard.style.display='none';
          inputEl.classList.add('not-found');
          nfCard.textContent = 'Item não encontrado no cadastro de patrimônio.';
          nfCard.classList.add('visible');
        }
      })
      .catch(()=>spinner.classList.remove('visible'));
  }, 600);
}

function preencherItemCard(d) {
  document.getElementById('itemCardNome').textContent = d.descricao;
  document.getElementById('iMarca').textContent = [d.marca,d.modelo].filter(Boolean).join(' / ') || '—';
  document.getElementById('iSerie').textContent = d.serie || '—';
  document.getElementById('iTag').textContent   = d.tag   || '—';
  document.getElementById('iId').textContent    = '#' + d.item_id;
  document.getElementById('iEstado').textContent = d.estado || d.status || '—';
  const local = [d.unidade_atual, d.setor_atual, d.area_atual].filter(Boolean).join(' / ') || '—';
  document.getElementById('iLocal').textContent  = local;
}

document.getElementById('inputTag').addEventListener('input', function(){
  this.value = this.value.toUpperCase();
  buscarItem('tag', this.value);
});
document.getElementById('inputSerie').addEventListener('input', function(){
  buscarItem('serie', this.value);
});

// ── Auto-preenchimento via ?id= (vindo da planilha) ──
if (ID_PRECARREGADO) {
  fetch('eng_clin_movimentar.php?action=buscar_por_id&id=' + ID_PRECARREGADO)
    .then(r => r.json())
    .then(data => {
      if (data.encontrado) {
        itemAtual = data;
        preencherItemCard(data);
        document.getElementById('inputTag').value  = data.tag   || '';
        document.getElementById('inputSerie').value = data.serie || '';
        document.getElementById('inputTag').classList.add('found');
        document.getElementById('itemCard').classList.add('visible');
        document.getElementById('formCard').style.display = 'block';
        document.getElementById('hiddenItemId').value = data.item_id;
      }
    })
    .catch(() => {});
}

// ── Unidade "Outro" ──
document.getElementById('selUniDest').addEventListener('change', function(){
  const wrap = document.getElementById('wrapUniOutro');
  const inp  = document.getElementById('inputUniOutro');
  if (this.value === 'OUTRO') {
    wrap.style.display = 'block';
    inp.focus();
    inp.oninput = () => { inp.value = inp.value.toUpperCase(); };
  } else {
    wrap.style.display = 'none';
  }
});

// ── Submit: garantir unidade se "Outro" ──
document.getElementById('formMov').addEventListener('submit', function(e){
  const sel = document.getElementById('selUniDest');
  if (sel.value === 'OUTRO') {
    const inp = document.getElementById('inputUniOutro').value.trim().toUpperCase();
    if (!inp) { e.preventDefault(); alert('Informe o nome da unidade de destino.'); return; }
    sel.value = inp;
  }
  const btn = this.querySelector('.btn-submit');
  if (btn) { btn.disabled=true; btn.innerHTML='<i class="fas fa-circle-notch fa-spin"></i> Salvando...'; }
});
</script>
<?php eng_clin_menu_painel(true); ?>
</body>
</html>
