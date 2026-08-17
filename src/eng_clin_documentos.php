<?php
/**
 * eng_clin_documentos.php
 * Cadastro e consulta de documentos da Engenharia Clínica.
 * Campos: título, data, valor, número e anexo PDF. Indicador de valor total.
 */
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = '';
mysqli_report(MYSQLI_REPORT_OFF);
include 'conexao.php';

if (!isset($_SESSION['usuario_logado'])) { header("Location: index.html"); exit(); }

$usuario = $_SESSION['usuario_logado'];
$nivel = 'C'; $classe_usuario = ''; $status_user = 'ATIVO'; $nome_usuario = $usuario;
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status, nome FROM usuarios WHERE usuario=?");
if (!$stmt) $stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
if ($stmt) {
    $stmt->bind_param("s", $usuario); $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($r = $res->fetch_assoc())) {
        $nivel          = strtoupper(trim($r['permicao']       ?? 'C'));
        $classe_usuario = strtoupper(trim($r['classe_usuario'] ?? ''));
        $status_user    = $r['status'] ?? 'ATIVO';
        $nome_usuario   = trim($r['nome'] ?? '') ?: $usuario;
    }
    $stmt->close();
}
if ($status_user !== 'ATIVO') { session_destroy(); header("Location: index.html?erro=bloqueado"); exit(); }
if (!in_array($classe_usuario, ['DEV','ENGENHARIA CLINICA']) || !in_array($nivel, ['A','B','C'])) {
    header("Location: acesso_bloqueado.html"); exit();
}

date_default_timezone_set('America/Sao_Paulo');
$msg = ''; $msg_tipo = '';
$podeEditar = in_array($nivel, ['A','B']) || $classe_usuario === 'DEV';

/* ── Abrir o PDF ─────────────────────────────────────────────────────── */
if (isset($_GET['pdf'])) {
    $idp = (int)$_GET['pdf'];
    $stP = $conn->prepare("SELECT nome_arquivo, mime, conteudo FROM documentos_engclin WHERE id=? LIMIT 1");
    if ($stP) {
        $stP->bind_param('i', $idp); $stP->execute();
        $rP = $stP->get_result(); $doc = $rP ? $rP->fetch_assoc() : null;
        $stP->close();
        if ($doc && !empty($doc['conteudo'])) {
            header('Content-Type: ' . ($doc['mime'] ?: 'application/pdf'));
            header('Content-Disposition: inline; filename="' . basename($doc['nome_arquivo'] ?: 'documento.pdf') . '"');
            header('Content-Length: ' . strlen($doc['conteudo']));
            echo $doc['conteudo']; exit();
        }
    }
    http_response_code(404); exit('Arquivo não encontrado.');
}

/* ── Excluir ─────────────────────────────────────────────────────────── */
if (($_POST['acao'] ?? '') === 'excluir' && $podeEditar) {
    $idd = (int)($_POST['id'] ?? 0);
    $stD = $conn->prepare("DELETE FROM documentos_engclin WHERE id=?");
    if ($stD) { $stD->bind_param('i', $idd); $stD->execute(); $stD->close(); }
    header("Location: eng_clin_documentos.php?m=" . urlencode('Documento removido.') . "&t=ok");
    exit();
}

/* ── Cadastrar ───────────────────────────────────────────────────────── */
if (($_POST['acao'] ?? '') === 'salvar' && $podeEditar) {
    $titulo = trim($_POST['titulo'] ?? '');
    $numero = trim($_POST['numero'] ?? '');
    $data_d = trim($_POST['data_doc'] ?? '') ?: null;
    $vraw   = str_replace(['.', ','], ['', '.'], trim($_POST['valor'] ?? ''));
    $valor  = is_numeric($vraw) ? (float)$vraw : 0.0;

    if ($titulo === '') {
        $msg = 'Informe o título do documento.'; $msg_tipo = 'erro';
    } else {
        $nome_arq = null; $mime = null; $tam = 0; $bin = null; $recusa = '';

        if (!empty($_FILES['anexo']['tmp_name']) && ($_FILES['anexo']['error'] ?? 1) === UPLOAD_ERR_OK) {
            $tam = (int)$_FILES['anexo']['size'];
            if ($tam > 8 * 1024 * 1024) {
                $recusa = 'Arquivo acima de 8 MB.';
            } else {
                // Confere o tipo pelo conteúdo, não pela extensão enviada
                $m = function_exists('mime_content_type') ? (mime_content_type($_FILES['anexo']['tmp_name']) ?: '') : '';
                if ($m !== 'application/pdf') {
                    $recusa = 'Somente arquivos PDF são aceitos.';
                } else {
                    $bin = file_get_contents($_FILES['anexo']['tmp_name']);
                    $nome_arq = $_FILES['anexo']['name'];
                    $mime = $m;
                }
            }
        }

        if ($recusa) {
            $msg = $recusa; $msg_tipo = 'erro';
        } else {
            $stI = $conn->prepare("INSERT INTO documentos_engclin (titulo,numero,data_doc,valor,nome_arquivo,mime,tamanho,conteudo,usuario) VALUES (?,?,?,?,?,?,?,?,?)");
            if ($stI) {
                $nulo = null;
                $stI->bind_param('sssdssibs', $titulo, $numero, $data_d, $valor, $nome_arq, $mime, $tam, $nulo, $usuario);
                if ($bin !== null) $stI->send_long_data(7, $bin);
                $ok = $stI->execute();
                $stI->close();
                $msg = $ok ? 'Documento cadastrado.' : 'Erro ao cadastrar.';
                $msg_tipo = $ok ? 'ok' : 'erro';
                if ($ok) {
                    header("Location: eng_clin_documentos.php?m=" . urlencode($msg) . "&t=ok");
                    exit();
                }
            }
        }
    }
}
if (isset($_GET['m'])) { $msg = $_GET['m']; $msg_tipo = ($_GET['t'] ?? '') === 'ok' ? 'ok' : 'erro'; }

/* ── Listagem e total ────────────────────────────────────────────────── */
$busca = trim($_GET['q'] ?? '');
$docs = [];
$like = '%' . $busca . '%';
$sql = "SELECT id, titulo, numero, data_doc, valor, nome_arquivo, tamanho, usuario, criado_em
        FROM documentos_engclin";
if ($busca !== '') $sql .= " WHERE titulo LIKE ? OR numero LIKE ?";
$sql .= " ORDER BY COALESCE(data_doc, DATE(criado_em)) DESC, id DESC";
$stL = $conn->prepare($sql);
if ($stL) {
    if ($busca !== '') $stL->bind_param('ss', $like, $like);
    $stL->execute();
    $rL = $stL->get_result();
    if ($rL) while ($x = $rL->fetch_assoc()) $docs[] = $x;
    $stL->close();
}

// Total: soma apenas o que está listado, respeitando a busca
$total = 0.0;
foreach ($docs as $d) $total += (float)$d['valor'];

$conn->close();
$data = date('d/m/Y'); $hora = date('H:i:s');
function fmt_rs($v){ return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Documentos — Engenharia Clínica</title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg-page:#0f0f0f; --bg-sidebar:#141414; --bg-card:#1a1a1a;
  --bg-input:#222; --border:rgba(255,255,255,0.07);
  --border-hover:rgba(255,255,255,0.14); --accent-steel:#a0aec0;
  --text-primary:#f0f0f0; --text-secondary:#888; --text-muted:#555;
  --sidebar-w:260px; --sidebar-collapsed:68px; --header-h:56px;
  --radius:10px; --radius-lg:16px;
  --transition:0.22s cubic-bezier(0.4,0,0.2,1);
  --font-ui:'DM Sans',sans-serif; --font-display:'Syne',sans-serif;
  --status-ok:#4ade80; --status-warn:#facc15; --status-err:#f87171;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-ui);background:var(--bg-page);color:var(--text-primary);min-height:100vh}
.menu-toggle{display:none;position:fixed;top:10px;left:10px;z-index:1200;background:#2a2a2a;color:#e2e8f0;border:1px solid var(--border-hover);border-radius:8px;padding:8px 12px;font-size:20px;cursor:pointer;line-height:1}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000}
.sidebar-overlay.open{display:block}
#sidebar{position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;background:var(--bg-sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;transition:width var(--transition);overflow:visible}
#sidebar.collapsed{width:var(--sidebar-collapsed)}
.sidebar-brand{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px 12px 16px;border-bottom:1px solid var(--border);flex-shrink:0;gap:10px}
.brand-logo-main{width:56%;max-width:140px;height:auto;object-fit:contain;display:block}
#sidebar.collapsed .brand-logo-main{width:31px;max-width:31px}
.sidebar-toggle{position:absolute;top:14px;right:-14px;width:28px;height:28px;background:var(--bg-card);border:1px solid var(--border-hover);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:200;color:var(--text-secondary);font-size:11px}
.sidebar-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:8px 10px;scrollbar-width:thin}
.nav-item{display:block;width:100%;padding:11px 14px;margin:3px 0;border-radius:6px;cursor:pointer;text-decoration:none;color:#bfc0c2;font-size:14px;transition:background var(--transition),transform var(--transition);white-space:nowrap;overflow:hidden;position:relative;background:#1e2025;text-align:left}
.nav-item:hover{background:#26282d;color:#e8e9eb;transform:translateX(4px)}
.nav-item.active{background:#2a2c31;color:#fff;font-weight:500}
#sidebar.collapsed .nav-label{opacity:0}
.nav-item-sair{color:#f87171!important}
#sidebar.collapsed .nav-item{padding:10px 0;text-align:center;font-size:0;color:transparent;overflow:hidden;transform:none!important}
#sidebar.collapsed .nav-item::before{font-family:'Font Awesome 6 Free';font-weight:900;font-size:15px;color:#888;display:block;line-height:1}
#sidebar.collapsed .nav-item::after{content:attr(data-tooltip);position:absolute;left:calc(var(--sidebar-collapsed) + 8px);top:50%;transform:translateY(-50%);background:#2d2d2d;color:#e2e8f0;font-size:12px;padding:5px 10px;border-radius:6px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:200;border:1px solid var(--border-hover)}
#sidebar.collapsed .nav-item:hover::after{opacity:1}
#main{margin-left:var(--sidebar-w);transition:margin-left var(--transition);min-height:100vh;display:flex;flex-direction:column}
#main.sidebar-collapsed{margin-left:var(--sidebar-collapsed)}
.topbar{height:var(--header-h);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;padding:0 24px;background:var(--bg-page);position:sticky;top:0;z-index:50}
.topbar-breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary)}
.topbar-breadcrumb span:last-child{color:var(--text-primary);font-weight:500}
.topbar-breadcrumb i{font-size:10px;color:var(--text-muted)}
.topbar-spacer{flex:1}
.topbar-logo-rede{height:32px;width:auto;object-fit:contain;opacity:.75;flex-shrink:0}
.content{flex:1;padding:24px 28px;max-width:1200px;width:100%}
.page-header{margin-bottom:18px}
.page-title{font-family:var(--font-display);font-size:22px;font-weight:700;letter-spacing:-.01em}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:8px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none}
.btn-ghost{background:#232323;border:1px solid rgba(255,255,255,.12);color:var(--text-primary)}
.btn-ghost:hover{background:#2e2e2e}
.btn-ok{background:rgba(74,222,128,.14);border:1px solid rgba(74,222,128,.4);color:#4ade80;font-weight:600}
.btn-ok:hover{background:rgba(74,222,128,.22)}
/* Indicador de total */
.total-box{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:var(--bg-card);border:1px solid rgba(74,222,128,.25);border-radius:var(--radius-lg);padding:18px 22px;margin-bottom:18px}
.total-ic{width:44px;height:44px;border-radius:12px;background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.25);display:flex;align-items:center;justify-content:center;color:#4ade80;font-size:18px;flex-shrink:0}
.total-lbl{font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted)}
.total-val{font-family:var(--font-display);font-size:28px;font-weight:700;color:#4ade80;line-height:1.1;letter-spacing:-.02em}
.total-sep{width:1px;height:38px;background:var(--border)}
.total-sec{font-size:12.5px;color:var(--text-secondary)}
.total-sec strong{color:var(--text-primary)}
/* Seções */
.sec{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);margin-bottom:16px;overflow:hidden}
.sec-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:11px;background:rgba(255,255,255,.02)}
.sec-icon{width:28px;height:28px;border-radius:7px;background:rgba(160,174,192,.1);display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--accent-steel);flex-shrink:0}
.sec-title{font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--accent-steel);flex:1}
.sec-body{padding:18px 20px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:15px}
.fg{display:flex;flex-direction:column;gap:6px}
.fg.full{grid-column:1/-1}
.flabel{font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted)}
.fi{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-family:var(--font-ui);font-size:13px;color:var(--text-primary);outline:none;transition:border-color var(--transition);width:100%}
.fi:focus{border-color:rgba(160,174,192,.45)}
/* Tabela */
.tbl-wrap{overflow-x:auto}
.tbl{width:100%;border-collapse:collapse;font-size:12.5px;min-width:760px}
.tbl th{text-align:left;font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--accent-steel);padding:10px 14px;border-bottom:1px solid var(--border);white-space:nowrap}
.tbl td{padding:11px 14px;border-bottom:1px solid var(--border);color:var(--text-secondary)}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.02)}
.tbl td.tit{color:var(--text-primary);font-weight:500}
.tbl td.val{color:#4ade80;font-weight:600;white-space:nowrap}
.pdf-link{display:inline-flex;align-items:center;gap:6px;color:var(--accent-steel);text-decoration:none;font-size:12px}
.pdf-link:hover{color:var(--text-primary)}
.sem-pdf{font-size:11px;color:var(--text-muted);font-style:italic}
.bico{background:none;border:1px solid var(--border);color:var(--text-muted);border-radius:6px;padding:5px 8px;cursor:pointer;font-size:11px}
.bico:hover{color:#f87171;border-color:rgba(248,113,113,.3)}
.busca{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.busca input{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:9px 13px;font-family:var(--font-ui);font-size:13px;color:var(--text-primary);outline:none;min-width:240px;flex:1;max-width:400px}
.vazio{padding:40px 20px;text-align:center;color:var(--text-muted);font-size:13px}
.vazio i{display:block;font-size:28px;margin-bottom:11px;opacity:.3}
.aviso{border-radius:10px;padding:13px 17px;font-size:13px;display:flex;align-items:flex-start;gap:11px;margin-bottom:16px;line-height:1.6}
.aviso i{font-size:15px;flex-shrink:0;margin-top:1px}
.aviso-erro{background:rgba(248,113,113,.09);border:1px solid rgba(248,113,113,.28);color:#fca5a5}
.aviso-ok{background:rgba(74,222,128,.09);border:1px solid rgba(74,222,128,.28);color:#86efac}
.footer{margin-left:var(--sidebar-w);padding:14px 28px;border-top:1px solid var(--border);display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);transition:margin-left var(--transition);flex-wrap:wrap;gap:8px}
@media(max-width:900px){.content{padding:16px}.topbar-logo-rede{display:none}.footer,#main{margin-left:var(--sidebar-collapsed)}#sidebar{width:var(--sidebar-collapsed)}}
@media(max-width:640px){#sidebar{position:fixed;transform:translateX(-100%);width:var(--sidebar-w)!important}#sidebar.open{transform:translateX(0)}.sidebar-toggle{display:none}.menu-toggle{display:block}#main{margin-left:0!important}.topbar{padding-left:54px}.footer{margin-left:0}}
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
      <span>Documentos</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
    <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <div class="page-header">
      <div class="page-title">Documentos</div>
      <div class="page-subtitle">Engenharia Clínica &middot; <?= eng_clin_data_pt() ?></div>
    </div>

    <?php if ($msg): ?>
    <div class="aviso <?= $msg_tipo==='ok' ? 'aviso-ok' : 'aviso-erro' ?>">
      <i class="fas <?= $msg_tipo==='ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
      <div><?= htmlspecialchars($msg) ?></div>
    </div>
    <?php endif; ?>

    <!-- ══ VALOR TOTAL ═══════════════════════════════════════════════ -->
    <div class="total-box">
      <div class="total-ic"><i class="fas fa-sack-dollar"></i></div>
      <div>
        <div class="total-lbl">Valor total</div>
        <div class="total-val"><?= fmt_rs($total) ?></div>
      </div>
      <div class="total-sep"></div>
      <div class="total-sec">
        <strong><?= count($docs) ?></strong> documento(s)
        <?= $busca !== '' ? ' no filtro aplicado' : ' cadastrado(s)' ?>
      </div>
    </div>

    <!-- ══ CADASTRO ══════════════════════════════════════════════════ -->
    <?php if ($podeEditar): ?>
    <div class="sec">
      <div class="sec-head">
        <div class="sec-icon"><i class="fas fa-file-circle-plus"></i></div>
        <span class="sec-title">Novo Documento</span>
      </div>
      <div class="sec-body">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="acao" value="salvar">
          <div class="grid">
            <div class="fg full">
              <label class="flabel">Título <span style="color:var(--status-err)">*</span></label>
              <input type="text" name="titulo" class="fi" required placeholder="Ex: Nota fiscal — contrato de manutenção">
            </div>
            <div class="fg">
              <label class="flabel">Número</label>
              <input type="text" name="numero" class="fi" placeholder="Ex: NF 12345">
            </div>
            <div class="fg">
              <label class="flabel">Data</label>
              <input type="date" name="data_doc" class="fi" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="fg">
              <label class="flabel">Valor (R$)</label>
              <input type="text" name="valor" class="fi" id="inpValor" placeholder="0,00">
            </div>
            <div class="fg">
              <label class="flabel">Anexo PDF</label>
              <input type="file" name="anexo" class="fi" accept="application/pdf">
            </div>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:10px">
            <i class="fas fa-circle-info"></i> Somente PDF, até 8 MB.
          </div>
          <div style="display:flex;justify-content:flex-end;margin-top:14px">
            <button type="submit" class="btn btn-ok"><i class="fas fa-floppy-disk"></i> Cadastrar documento</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- ══ LISTA ═════════════════════════════════════════════════════ -->
    <div class="sec">
      <div class="sec-head">
        <div class="sec-icon"><i class="fas fa-folder-open"></i></div>
        <span class="sec-title">Documentos Cadastrados</span>
      </div>
      <div class="sec-body" style="padding-bottom:0">
        <form method="GET" class="busca">
          <input type="text" name="q" value="<?= htmlspecialchars($busca) ?>" placeholder="Buscar por título ou número...">
          <button type="submit" class="btn btn-ghost"><i class="fas fa-search"></i> Buscar</button>
          <?php if ($busca !== ''): ?>
          <a href="eng_clin_documentos.php" class="btn btn-ghost"><i class="fas fa-xmark"></i> Limpar</a>
          <?php endif; ?>
        </form>
      </div>
      <div class="tbl-wrap">
        <?php if (!$docs): ?>
        <div class="vazio">
          <i class="fas fa-folder-open"></i>
          <?= $busca !== '' ? 'Nenhum documento encontrado para “'.htmlspecialchars($busca).'”.' : 'Nenhum documento cadastrado ainda.' ?>
        </div>
        <?php else: ?>
        <table class="tbl">
          <thead>
            <tr>
              <th>Título</th><th>Número</th><th>Data</th><th>Valor</th>
              <th>Anexo</th><th>Cadastrado por</th>
              <?php if ($podeEditar): ?><th style="width:60px;text-align:center">Ações</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($docs as $d): ?>
            <tr>
              <td class="tit"><?= htmlspecialchars($d['titulo']) ?></td>
              <td><?= htmlspecialchars($d['numero'] ?: '—') ?></td>
              <td style="white-space:nowrap"><?= $d['data_doc'] ? date('d/m/Y', strtotime($d['data_doc'])) : '—' ?></td>
              <td class="val"><?= fmt_rs($d['valor']) ?></td>
              <td>
                <?php if (!empty($d['nome_arquivo'])): ?>
                <a href="?pdf=<?= (int)$d['id'] ?>" target="_blank" rel="noopener" class="pdf-link"
                   title="<?= htmlspecialchars($d['nome_arquivo']) ?>">
                  <i class="fas fa-file-pdf"></i>
                  <span><?= number_format($d['tamanho']/1024, 0, ',', '.') ?> KB</span>
                </a>
                <?php else: ?>
                <span class="sem-pdf">sem anexo</span>
                <?php endif; ?>
              </td>
              <td style="font-size:11.5px"><?= htmlspecialchars($d['usuario'] ?: '—') ?></td>
              <?php if ($podeEditar): ?>
              <td style="text-align:center">
                <form method="POST" style="display:inline" onsubmit="return confirm('Remover este documento?')">
                  <input type="hidden" name="acao" value="excluir">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button type="submit" class="bico" title="Remover"><i class="fas fa-trash"></i></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<div class="footer" id="pageFooter">
  <div>Usuário: <?= htmlspecialchars($nome_usuario) ?></div>
  <div>Data: <?= $data ?> | Hora: <span id="hora"><?= $hora ?></span></div>
  <div>&copy; GK Soluções</div>
</div>

<?php eng_clin_menu_painel(); ?>

<script>
setInterval(() => {
  const h = document.getElementById('hora');
  if (h) h.innerText = new Date().toLocaleTimeString('pt-BR');
}, 1000);

const sidebar = document.getElementById('sidebar');
const mainEl  = document.getElementById('main');
const footEl  = document.getElementById('pageFooter');
const tBtn    = document.getElementById('toggleBtn');
const tIcon   = document.getElementById('toggleIcon');
if (tBtn) {
  tBtn.addEventListener('click', () => {
    const col = sidebar.classList.toggle('collapsed');
    mainEl.classList.toggle('sidebar-collapsed', col);
    if (tIcon) { tIcon.classList.toggle('fa-chevron-left', !col); tIcon.classList.toggle('fa-chevron-right', col); }
    if (footEl) footEl.style.marginLeft = col ? 'var(--sidebar-collapsed)' : 'var(--sidebar-w)';
  });
}
const mBtn = document.getElementById('menuToggle');
if (mBtn) mBtn.addEventListener('click', () => {
  sidebar.classList.add('open');
  const ov = document.getElementById('sidebarOverlay'); if (ov) ov.classList.add('open');
});
function fecharSidebar(){
  sidebar.classList.remove('open');
  const ov = document.getElementById('sidebarOverlay'); if (ov) ov.classList.remove('open');
}

/* Valor: aceita só números, ponto e vírgula */
const inpValor = document.getElementById('inpValor');
if (inpValor) inpValor.addEventListener('input', () => {
  inpValor.value = inpValor.value.replace(/[^\d.,]/g, '');
});

(function hb() {
  fetch('heartbeat.php?_=' + Date.now(), { method:'POST', credentials:'same-origin', cache:'no-store' })
    .then(r => r.json()).then(d => { if (d.revogada) location.href = 'index.html?error=sessao+encerrada'; })
    .catch(() => {});
  setTimeout(hb, 30000);
})();

<?php eng_clin_menu_js(); ?>
</script>
</body>
</html>
