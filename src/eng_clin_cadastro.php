<?php
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = 'cadastro';   // item ativo no menu lateral
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
$nivel          = 'C';
$classe_usuario = '';
$status_user    = 'ATIVO';
if ($r = $res->fetch_assoc()) {
    $nivel          = strtoupper(trim($r['permicao']));
    $classe_usuario = strtoupper(trim($r['classe_usuario']));
    $status_user    = $r['status'] ?? 'ATIVO';
}
$stmt->close();

if ($status_user !== 'ATIVO') {
    session_destroy();
    header("Location: index.html?erro=bloqueado");
    exit();
}

if (!in_array($classe_usuario, ['DEV', 'ENGENHARIA CLINICA']) || !in_array($nivel, ['A', 'B'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}

// ── Buscar descrição pela descrição_detalhada (AJAX) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'buscar_descricao') {
    header('Content-Type: application/json');
    $dd = strtoupper(trim($_POST['descricao_detalhada'] ?? ''));
    $resultado = '';
    if ($dd !== '') {
        $st = $conn->prepare("SELECT descricao FROM descricoes WHERE descricao_detalhada=? LIMIT 1");
        $st->bind_param("s", $dd);
        $st->execute();
        $r2 = $st->get_result();
        if ($row2 = $r2->fetch_assoc()) $resultado = $row2['descricao'];
        $st->close();
    }
    $conn->close();
    echo json_encode(['descricao' => $resultado]);
    exit();
}

// ── Buscar setores por unidade (AJAX) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['acao'] ?? '') === 'setores_por_unidade') {
    header('Content-Type: application/json');
    $uni = strtoupper(trim($_GET['unidade'] ?? ''));
    $setores = [];
    if ($uni !== '') {
        $st = $conn->prepare("SELECT DISTINCT setor FROM cadastro WHERE unidade=? AND setor IS NOT NULL AND setor <> '' ORDER BY setor ASC");
        $st->bind_param("s", $uni);
        $st->execute();
        $r3 = $st->get_result();
        while ($row3 = $r3->fetch_assoc()) $setores[] = $row3['setor'];
        $st->close();
    }
    $conn->close();
    echo json_encode($setores);
    exit();
}

// ── Valores fixos para esta página ──────────────────────────────────────────
$GRUPO    = 'MATERIAL HOSPITALAR';
$CLASSE   = 'MATERIAL PERMANENTE';
$SUBGRUPO = 'EQUIPAMENTO HOSPITALAR';
// Tudo cadastrado por esta tela é da Engenharia Clínica — é o que define
// a visibilidade no LifeTech (substituiu o critério por subgrupo).
$RESPONSAVEL = 'ENGENHARIA CLINICA';

// ── Mensagem e valores de formulário ────────────────────────────────────────
$msg      = '';
$msg_tipo = '';

$f_unidade            = '';
$f_setor              = '';
$f_pavimento          = '';
$f_area               = '';
$f_propriedade        = '';
$f_empresa            = '';
$f_tagAlugado         = '';
$f_descricaoDetalhada = '';
$f_descricao          = '';
$f_marca              = '';
$f_modelo             = '';
$f_serie              = '';
$f_tagAntiga          = '';
$f_observacao         = '';
$f_notaFiscal         = '';

function formatarTag($v) {
    $v = strtoupper(trim($v));
    if ($v === '') return '';
    $letras  = preg_replace('/\d/', '', $v);
    $numeros = preg_replace('/\D/', '', $v);
    if ($numeros !== '') $numeros = str_pad(substr($numeros, -6), 6, '0', STR_PAD_LEFT);
    return $letras . $numeros;
}

// ── Salvar ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar') {

    $f_unidade            = strtoupper(trim($_POST['unidade']            ?? ''));
    $f_setor              = strtoupper(trim($_POST['setor']              ?? ''));
    $f_pavimento          = strtoupper(trim($_POST['pavimento']          ?? ''));
    $f_area               = strtoupper(trim($_POST['area']               ?? ''));
    $f_propriedade        = strtoupper(trim($_POST['propriedade']        ?? ''));
    $f_empresa            = strtoupper(trim($_POST['empresa']            ?? ''));
    $f_tagAlugado         = formatarTag($_POST['tagAlugado']             ?? '');
    $f_descricaoDetalhada = strtoupper(trim($_POST['descricaoDetalhada'] ?? ''));
    $f_descricao          = strtoupper(trim($_POST['descricao']          ?? ''));
    $f_marca              = strtoupper(trim($_POST['marca']              ?? ''));
    $f_modelo             = strtoupper(trim($_POST['modelo']             ?? ''));
    $f_serie              = strtoupper(trim($_POST['numeroSerie']        ?? ''));
    $f_tagAntiga          = formatarTag($_POST['tagAntiga']              ?? '');
    $f_observacao         = strtoupper(trim($_POST['observacao']         ?? ''));
    $f_notaFiscal         = strtoupper(trim($_POST['notaFiscal']         ?? ''));

    $status_cad = 'ATIVO';
    $periodo    = date('Y-m-d H:i:s');

    // Valores nulos quando vazios
    $p_unidade   = $f_unidade            ?: null;
    $p_setor     = $f_setor              ?: null;
    $p_pavi      = $f_pavimento          ?: null;
    $p_area      = $f_area               ?: null;
    $p_prop      = $f_propriedade        ?: null;
    $p_emp       = $f_empresa            ?: null;
    $p_tagAlug   = $f_tagAlugado         ?: null;
    $p_dd        = $f_descricaoDetalhada ?: null;
    $p_desc      = $f_descricao          ?: null;
    $p_marca     = $f_marca              ?: null;
    $p_modelo    = $f_modelo             ?: null;
    $p_serie     = $f_serie              ?: null;
    $p_tagAnt    = $f_tagAntiga          ?: null;
    $p_obs       = $f_observacao         ?: null;
    $p_nf        = $f_notaFiscal         ?: null;
    $p_usu       = $usuario;
    $p_grupo     = $GRUPO;
    $p_classe    = $CLASSE;
    $p_subgrupo  = $SUBGRUPO;
    $p_resp      = $RESPONSAVEL;

    if ($f_notaFiscal !== '') {
        $p_conc = 'NAO';
        $sql = "INSERT INTO cadastro (
            status, periodo,
            unidade, setor, pavimento, area,
            tag_antiga, tag_alugado,
            propriedade, empresa,
            descricao_detalhada, descricao, marca, modelo,
            serie, observacao, nota_fiscal,
            usuario_cadastro,
            grupo, classe, subgrupo, responsavel, conciliado
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param("sssssssssssssssssssssss",
                $status_cad, $periodo,
                $p_unidade, $p_setor, $p_pavi, $p_area,
                $p_tagAnt, $p_tagAlug,
                $p_prop, $p_emp,
                $p_dd, $p_desc, $p_marca, $p_modelo,
                $p_serie, $p_obs, $p_nf,
                $p_usu,
                $p_grupo, $p_classe, $p_subgrupo, $p_resp, $p_conc
            );
        }
    } else {
        $sql = "INSERT INTO cadastro (
            status, periodo,
            unidade, setor, pavimento, area,
            tag_antiga, tag_alugado,
            propriedade, empresa,
            descricao_detalhada, descricao, marca, modelo,
            serie, observacao, nota_fiscal,
            usuario_cadastro,
            grupo, classe, subgrupo, responsavel
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param("ssssssssssssssssssssss",
                $status_cad, $periodo,
                $p_unidade, $p_setor, $p_pavi, $p_area,
                $p_tagAnt, $p_tagAlug,
                $p_prop, $p_emp,
                $p_dd, $p_desc, $p_marca, $p_modelo,
                $p_serie, $p_obs, $p_nf,
                $p_usu,
                $p_grupo, $p_classe, $p_subgrupo, $p_resp
            );
        }
    }

    if (isset($st) && $st !== false) {
        if ($st->execute()) {
            $msg      = 'Equipamento cadastrado com sucesso!';
            $msg_tipo = 'ok';
            // Mantém unidade/setor/pavimento/area, limpa os demais
            $f_propriedade        = '';
            $f_empresa            = '';
            $f_tagAlugado         = '';
            $f_descricaoDetalhada = '';
            $f_descricao          = '';
            $f_marca              = '';
            $f_modelo             = '';
            $f_serie              = '';
            $f_tagAntiga          = '';
            $f_observacao         = '';
            $f_notaFiscal         = '';
        } else {
            $msg      = 'Erro ao cadastrar: ' . $st->error;
            $msg_tipo = 'erro';
        }
        $st->close();
    } else {
        $msg      = 'Erro ao preparar query: ' . $conn->error;
        $msg_tipo = 'erro';
    }
}

// ── Buscar unidades disponíveis ───────────────────────────────────────────────
$unidades = [];
$res_uni = $conn->query("SELECT DISTINCT unidade FROM cadastro WHERE unidade IS NOT NULL AND unidade <> '' ORDER BY unidade ASC");
if ($res_uni) while ($r = $res_uni->fetch_assoc()) $unidades[] = $r['unidade'];

$conn->close();

date_default_timezone_set('America/Sao_Paulo');
$data = date('d/m/Y');
$hora = date('H:i:s');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadastro de Equipamento — Engenharia Clínica</title>
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

#main{margin-left:var(--sidebar-w);transition:margin-left var(--transition);min-height:calc(100vh - 42px);display:flex;flex-direction:column;flex:1}
#main.sidebar-collapsed{margin-left:var(--sidebar-collapsed)}

.topbar{height:var(--header-h);background:rgba(20,20,20,0.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:10px;position:sticky;top:0;z-index:50}
.topbar-breadcrumb{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted)}
.topbar-breadcrumb span:last-child{color:var(--text-primary);font-weight:500}
.topbar-breadcrumb i{font-size:10px}
.topbar-spacer{flex:1}
.topbar-logo-rede{height:32px;width:auto;object-fit:contain;opacity:.75;transition:opacity var(--transition);flex-shrink:0}
.topbar-logo-rede:hover{opacity:1}

.content{flex:1;padding:28px;width:100%}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
.page-title{font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--text-primary);letter-spacing:-.01em}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}
.page-actions{display:flex;gap:8px;flex-wrap:wrap}

.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none}
.btn-primary{background:#232323;border:1px solid rgba(255,255,255,0.13);color:var(--text-primary)}
.btn-primary:hover{background:#2e2e2e;border-color:rgba(255,255,255,.2)}
.btn-success{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.25);color:#4ade80}
.btn-success:hover{background:rgba(74,222,128,.18);border-color:rgba(74,222,128,.4)}
.btn-danger{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.25);color:#f87171}
.btn-danger:hover{background:rgba(248,113,113,.18);border-color:rgba(248,113,113,.4)}

/* ── ALERT ── */
.alert{padding:11px 16px;border-radius:8px;font-size:13px;font-weight:500;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.alert-ok{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.2);color:#4ade80}
.alert-erro{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.2);color:#f87171}

/* ── FORM SECTION ── */
.form-section{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:16px;transition:border-color var(--transition)}
.form-section:hover{border-color:var(--border-hover)}
.form-section-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.02)}
.form-section-icon{width:28px;height:28px;border-radius:6px;background:rgba(160,174,192,.1);display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--accent-steel);flex-shrink:0}
.form-section-title{font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--accent-steel)}
.form-section-body{padding:20px}

/* ── CAMPOS ── */
.form-grid{display:grid;gap:14px}
.fg-2{grid-template-columns:1fr 1fr}
.fg-3{grid-template-columns:1fr 1fr 1fr}
.fg-4{grid-template-columns:1fr 1fr 1fr 1fr}
.span-2{grid-column:span 2}
.span-3{grid-column:span 3}
.span-4{grid-column:span 4}

.field{display:flex;flex-direction:column;gap:5px}
.field-label{font-size:11px;font-weight:500;letter-spacing:.04em;text-transform:uppercase;color:var(--text-muted)}
.field-label .obrig{color:#f87171;margin-left:2px}
.field-label .hint{font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-muted);margin-left:4px;font-style:italic}

.field input,.field select,.field textarea{
  background:var(--bg-input);border:1px solid var(--border);border-radius:8px;
  padding:9px 12px;font-family:var(--font-ui);font-size:13px;color:var(--text-primary);
  outline:none;transition:border-color var(--transition),background var(--transition);
  width:100%;text-transform:uppercase
}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--border-hover);background:#282828}
.field input::placeholder,.field textarea::placeholder{color:var(--text-muted);font-size:12px;text-transform:none}
.field input[readonly]{opacity:.55;cursor:default}
.field select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:32px;cursor:pointer}
.field select option{background:#1e1e1e}
.field select:disabled{opacity:.45;cursor:not-allowed}

/* autopreenchido */
.field input.autopreenchido{border-color:rgba(74,222,128,.4);background:rgba(74,222,128,.05);color:#4ade80}

/* badge fixo */
.fixed-badge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:600;padding:3px 10px;border-radius:20px;background:rgba(160,174,192,.08);color:var(--accent-steel);border:1px solid rgba(160,174,192,.2);margin-top:2px}

/* ── TOAST ── */
#toast{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;align-items:center;gap:10px;background:#1e1e1e;border:1px solid var(--border-hover);border-radius:10px;padding:13px 18px;font-size:13px;color:var(--text-primary);box-shadow:0 8px 32px rgba(0,0,0,.5);opacity:0;transform:translateY(12px);pointer-events:none;transition:opacity .3s ease,transform .3s ease;max-width:360px}
#toast.show{opacity:1;transform:translateY(0);pointer-events:auto}
#toast.success{border-color:rgba(74,222,128,.35)}
#toast.success i{color:#4ade80}
#toast.error{border-color:rgba(248,113,113,.35)}
#toast.error i{color:#f87171}

/* ── FOOTER ── */
.footer{background:#181818;color:#888;border-top:1px solid var(--border);padding:12px 20px;display:flex;justify-content:space-between;flex-wrap:wrap;font-size:13px;gap:6px;margin-left:var(--sidebar-w);transition:margin-left var(--transition)}
.footer div{color:#666}

/* ── ANIMATIONS ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeUp .35s ease both}
.delay-1{animation-delay:.05s}
.delay-2{animation-delay:.10s}
.delay-3{animation-delay:.15s}
.delay-4{animation-delay:.20s}

::-webkit-scrollbar{width:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.07);border-radius:4px}
::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.14)}

@media(max-width:900px){.fg-3,.fg-4{grid-template-columns:1fr 1fr}.span-3,.span-4{grid-column:span 2}.content{padding:16px}.footer,#main{margin-left:var(--sidebar-collapsed)}#sidebar{width:var(--sidebar-collapsed)}#sidebar.open{width:var(--sidebar-w)}.topbar-logo-rede{display:none}}
@media(max-width:640px){#sidebar{position:fixed;top:0;left:0;height:100vh;z-index:1100;transform:translateX(-100%);transition:transform var(--transition);width:var(--sidebar-w)!important}#sidebar.open{transform:translateX(0)}.sidebar-toggle{display:none}.menu-toggle{display:block}#main{margin-left:0!important}.topbar{padding-left:54px}.fg-2,.fg-3,.fg-4{grid-template-columns:1fr}.span-2,.span-3,.span-4{grid-column:span 1}.content{padding:12px}.footer{margin-left:0}}
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
      <span>Cadastro de Equipamento</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
      <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <!-- PAGE HEADER -->
    <div class="page-header fade-up">
      <div>
        <div class="page-title">Cadastro de Equipamento</div>
        <div class="page-subtitle">Engenharia Clínica &middot; <?= eng_clin_data_pt() ?></div>
      </div>
      <div class="page-actions">
        <a href="engenharia_clinica_inicial.php" class="btn btn-primary">
          <i class="fas fa-arrow-left"></i> Voltar
        </a>
      </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert <?= $msg_tipo === 'ok' ? 'alert-ok' : 'alert-erro' ?> fade-up" id="alertMsg">
      <i class="fas <?= $msg_tipo === 'ok' ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="formCadastro" novalidate>
      <input type="hidden" name="acao" value="salvar">

      <!-- ── SEÇÃO 1: LOCALIZAÇÃO ── -->
      <div class="form-section fade-up delay-1">
        <div class="form-section-header">
          <div class="form-section-icon"><i class="fas fa-location-dot"></i></div>
          <span class="form-section-title">Localização</span>
        </div>
        <div class="form-section-body">
          <div class="form-grid fg-4" style="margin-bottom:14px">
            <div class="field span-2">
              <label class="field-label">Unidade</label>
              <select name="unidade" id="selUnidade">
                <option value="">Selecione a unidade...</option>
                <?php foreach ($unidades as $u): ?>
                <option value="<?= htmlspecialchars($u) ?>" <?= $f_unidade === $u ? 'selected' : '' ?>>
                  <?= htmlspecialchars($u) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field span-2">
              <label class="field-label">Setor</label>
              <select name="setor" id="selSetor">
                <option value="">Selecione a unidade primeiro</option>
                <?php if ($f_setor): ?>
                <option value="<?= htmlspecialchars($f_setor) ?>" selected><?= htmlspecialchars($f_setor) ?></option>
                <?php endif; ?>
              </select>
            </div>
          </div>
          <div class="form-grid fg-2">
            <div class="field">
              <label class="field-label">Pavimento</label>
              <input type="text" name="pavimento" placeholder="Ex: TÉRREO, 1º ANDAR"
                     value="<?= htmlspecialchars($f_pavimento) ?>">
            </div>
            <div class="field">
              <label class="field-label">Área</label>
              <input type="text" name="area" placeholder="Ex: UTI, CENTRO CIRÚRGICO"
                     value="<?= htmlspecialchars($f_area) ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- ── SEÇÃO 2: PROPRIEDADE ── -->
      <div class="form-section fade-up delay-2">
        <div class="form-section-header">
          <div class="form-section-icon"><i class="fas fa-file-contract"></i></div>
          <span class="form-section-title">Propriedade</span>
        </div>
        <div class="form-section-body">
          <div class="form-grid fg-3">
            <div class="field">
              <label class="field-label">Propriedade</label>
              <select name="propriedade" id="selPropriedade">
                <option value="">Selecione...</option>
                <option value="PATRIMONIO"  <?= $f_propriedade === 'PATRIMONIO'  ? 'selected' : '' ?>>PATRIMÔNIO</option>
                <option value="COMODATO"    <?= $f_propriedade === 'COMODATO'    ? 'selected' : '' ?>>COMODATO</option>
                <option value="ALUGADO"     <?= $f_propriedade === 'ALUGADO'     ? 'selected' : '' ?>>ALUGADO</option>
                <option value="EMPRESTADO"  <?= $f_propriedade === 'EMPRESTADO'  ? 'selected' : '' ?>>EMPRESTADO</option>
              </select>
            </div>
            <div class="field">
              <label class="field-label">Empresa</label>
              <input type="text" name="empresa" placeholder="Nome da empresa"
                     value="<?= htmlspecialchars($f_empresa) ?>">
            </div>
            <div class="field">
              <label class="field-label">Tag Alugado <span class="hint">(se aplicável)</span></label>
              <input type="text" name="tagAlugado" id="inpTagAlugado"
                     placeholder="TAG do item alugado"
                     value="<?= htmlspecialchars($f_tagAlugado) ?>"
                     <?= $f_propriedade === 'PATRIMONIO' ? 'disabled' : '' ?>>
            </div>
          </div>
        </div>
      </div>

      <!-- ── SEÇÃO 3: IDENTIFICAÇÃO DO EQUIPAMENTO ── -->
      <div class="form-section fade-up delay-3">
        <div class="form-section-header">
          <div class="form-section-icon"><i class="fas fa-stethoscope"></i></div>
          <span class="form-section-title">Identificação do Equipamento</span>
        </div>
        <div class="form-section-body">
          <div class="form-grid fg-2" style="margin-bottom:14px">
            <div class="field">
              <label class="field-label">Descrição Detalhada <span class="hint">(preencha primeiro)</span></label>
              <input type="text" name="descricaoDetalhada" id="inpDD"
                     placeholder="Ex: MONITOR MULTIPARAMETRO"
                     value="<?= htmlspecialchars($f_descricaoDetalhada) ?>">
            </div>
            <div class="field">
              <label class="field-label">Descrição <span class="hint">(preenchida automaticamente)</span></label>
              <input type="text" name="descricao" id="inpDesc"
                     placeholder="Preenchimento automático ou manual"
                     value="<?= htmlspecialchars($f_descricao) ?>"
                     class="<?= $f_descricao && $f_descricaoDetalhada ? 'autopreenchido' : '' ?>">
            </div>
          </div>
          <div class="form-grid fg-3" style="margin-bottom:14px">
            <div class="field">
              <label class="field-label">Marca</label>
              <input type="text" name="marca" placeholder="Marca / Fabricante"
                     value="<?= htmlspecialchars($f_marca) ?>">
            </div>
            <div class="field">
              <label class="field-label">Modelo</label>
              <input type="text" name="modelo" placeholder="Modelo do equipamento"
                     value="<?= htmlspecialchars($f_modelo) ?>">
            </div>
            <div class="field">
              <label class="field-label">Nº de Série</label>
              <input type="text" name="numeroSerie" placeholder="Número de série"
                     value="<?= htmlspecialchars($f_serie) ?>">
            </div>
          </div>
          <div class="form-grid fg-3">
            <div class="field">
              <label class="field-label">Tag Patrimônio</label>
              <input type="text" name="tagAntiga" placeholder="Ex: HCSC 123456"
                     value="<?= htmlspecialchars($f_tagAntiga) ?>">
            </div>
            <div class="field">
              <label class="field-label">Nota Fiscal</label>
              <input type="text" name="notaFiscal" id="inpNF"
                     placeholder="Número da nota fiscal"
                     value="<?= htmlspecialchars($f_notaFiscal) ?>">
            </div>
            <div class="field">
              <label class="field-label">Observação</label>
              <input type="text" name="observacao" placeholder="Observações adicionais"
                     value="<?= htmlspecialchars($f_observacao) ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- ── SEÇÃO 4: CLASSIFICAÇÃO CONTÁBIL (somente leitura) ── -->
      <div class="form-section fade-up delay-4">
        <div class="form-section-header">
          <div class="form-section-icon"><i class="fas fa-tag"></i></div>
          <span class="form-section-title">Classificação Contábil</span>
        </div>
        <div class="form-section-body">
          <div class="form-grid fg-3">
            <div class="field">
              <label class="field-label">Classe</label>
              <input type="text" value="<?= htmlspecialchars($CLASSE) ?>" readonly>
            </div>
            <div class="field">
              <label class="field-label">Grupo</label>
              <input type="text" value="<?= htmlspecialchars($GRUPO) ?>" readonly>
            </div>
            
            <div class="field">
              <label class="field-label">Subgrupo</label>
              <input type="text" value="<?= htmlspecialchars($SUBGRUPO) ?>" readonly>
            </div>
          </div>
          <div style="margin-top:10px">
            <span class="fixed-badge"><i class="fas fa-lock" style="font-size:9px"></i> Valores fixos para cadastros desta página</span>
          </div>
        </div>
      </div>

      <!-- Botões rodapé -->
      <div class="fade-up" style="animation-delay:.25s;display:flex;gap:10px;justify-content:flex-end;margin-top:4px;padding-bottom:8px">
        <a href="engenharia_clinica_inicial.php" class="btn btn-primary">
          <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <button type="button" class="btn btn-danger" onclick="limparCampos()">
          <i class="fas fa-eraser"></i> Limpar
        </button>
        <button type="submit" class="btn btn-success">
          <i class="fas fa-floppy-disk"></i> Salvar Equipamento
        </button>
      </div>

    </form>

  </div><!-- /content -->
</div><!-- /main -->

<div class="footer" id="pageFooter">
  <div>Usuário: <?= htmlspecialchars($usuario) ?> &middot; Nível: <?= htmlspecialchars($nivel) ?></div>
  <div>Data: <?= $data ?> | Hora: <span id="hora"><?= $hora ?></span></div>
  <div>&copy; GK Soluções</div>
</div>

<div id="toast"><i class="fas fa-circle-check" id="toastIcon"></i> <span id="toastMsg"></span></div>

<script>
/* ── Relógio ── */
setInterval(() => { document.getElementById('hora').innerText = new Date().toLocaleTimeString('pt-BR'); }, 1000);

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
sidebar.querySelectorAll('.nav-item').forEach(i => {
  i.addEventListener('click', () => { if (window.innerWidth <= 640) fecharSidebar(); });
});

/* ── Toast ── */
let toastTimer;
function showToast(msg, tipo = 'success') {
  const t = document.getElementById('toast');
  document.getElementById('toastMsg').innerText = msg;
  document.getElementById('toastIcon').className = tipo === 'success' ? 'fas fa-circle-check' : 'fas fa-circle-xmark';
  t.className = 'show ' + tipo;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.className = ''; }, 4000);
}

/* ── Auto-sumir alert de sucesso ── */
const alertEl = document.getElementById('alertMsg');
if (alertEl && alertEl.classList.contains('alert-ok')) {
  setTimeout(() => {
    alertEl.style.transition = 'opacity .5s ease';
    alertEl.style.opacity = '0';
    setTimeout(() => alertEl.remove(), 500);
  }, 3500);
}

/* ── Propriedade → Tag Alugado ── */
const selProp    = document.getElementById('selPropriedade');
const inpTagAlug = document.getElementById('inpTagAlugado');
function controlarTagAlugado() {
  if (selProp.value === 'PATRIMONIO') {
    inpTagAlug.value    = '';
    inpTagAlug.disabled = true;
  } else {
    inpTagAlug.disabled = false;
  }
}
selProp.addEventListener('change', controlarTagAlugado);
controlarTagAlugado();

/* ── Busca de setores por unidade ── */
const selUnidade = document.getElementById('selUnidade');
const selSetor   = document.getElementById('selSetor');
function carregarSetores(unidade, setorSalvo) {
  if (!unidade) {
    selSetor.innerHTML = '<option value="">Selecione a unidade primeiro</option>';
    return;
  }
  selSetor.innerHTML = '<option value="">Carregando...</option>';
  fetch('eng_clin_cadastro.php?acao=setores_por_unidade&unidade=' + encodeURIComponent(unidade))
    .then(r => r.json())
    .then(setores => {
      selSetor.innerHTML = '<option value="">Selecione o setor...</option>';
      setores.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s; opt.textContent = s;
        if (s === setorSalvo) opt.selected = true;
        selSetor.appendChild(opt);
      });
    })
    .catch(() => { selSetor.innerHTML = '<option value="">Erro ao carregar</option>'; });
}
selUnidade.addEventListener('change', () => carregarSetores(selUnidade.value, ''));
const setorSalvo = <?= json_encode($f_setor) ?>;
if (selUnidade.value) carregarSetores(selUnidade.value, setorSalvo);

/* ── Auto-preenchimento Descrição via Descrição Detalhada ── */
const inpDD   = document.getElementById('inpDD');
const inpDesc = document.getElementById('inpDesc');
let timerDD   = null;

function buscarDescricao() {
  const val = inpDD.value.trim();
  if (!val) {
    inpDesc.value = '';
    inpDesc.classList.remove('autopreenchido');
    return;
  }
  const fd = new URLSearchParams();
  fd.append('acao', 'buscar_descricao');
  fd.append('descricao_detalhada', val);
  fetch('eng_clin_cadastro.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: fd.toString()
  })
  .then(r => r.json())
  .then(res => {
    if (res.descricao) {
      inpDesc.value = res.descricao;
      inpDesc.classList.add('autopreenchido');
    } else {
      inpDesc.value = val.toUpperCase();
      inpDesc.classList.remove('autopreenchido');
    }
  })
  .catch(() => {
    inpDesc.value = val.toUpperCase();
    inpDesc.classList.remove('autopreenchido');
  });
}
inpDD.addEventListener('input',  () => { clearTimeout(timerDD); timerDD = setTimeout(buscarDescricao, 400); });
inpDD.addEventListener('blur',   buscarDescricao);
inpDesc.addEventListener('input', () => inpDesc.classList.remove('autopreenchido'));

/* ── Limpar campos (mantém localização) ── */
function limparCampos() {
  if (!confirm('Limpar os campos do formulário?\nUnidade, Setor, Pavimento e Área serão mantidos.')) return;
  const manter = ['unidade','setor','pavimento','area','acao'];
  document.querySelectorAll('#formCadastro input, #formCadastro select').forEach(el => {
    if (!manter.includes(el.name)) {
      el.value = '';
      el.disabled && (el.disabled = false);
    }
  });
  inpDesc.classList.remove('autopreenchido');
  controlarTagAlugado();
}

/* ── Prevenir duplo submit ── */
document.getElementById('formCadastro').addEventListener('submit', function() {
  const btn = this.querySelector('button[type="submit"]');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Salvando...';
  }
});

/* ── Heartbeat ── */
(function hb() {
  fetch('heartbeat.php?_=' + Date.now(), { method:'POST', credentials:'same-origin', cache:'no-store' })
    .then(r => r.json())
    .then(d => { if (d.revogada) window.location.href = 'index.html?error=Sua+sessao+foi+encerrada'; })
    .catch(() => {});
  setTimeout(hb, 30000);
})();
</script>
<?php eng_clin_menu_painel(true); ?>
</body>
</html>