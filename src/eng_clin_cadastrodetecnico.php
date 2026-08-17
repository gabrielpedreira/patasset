<?php
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = '';   // item ativo no menu lateral
include 'conexao.php';

if (!isset($_SESSION['usuario_logado'])) {
    header("Location: index.html");
    exit();
}

$usuario        = $_SESSION['usuario_logado'];
$stmt           = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
$stmt->bind_param("s", $usuario);
$stmt->execute();
$res            = $stmt->get_result();
$nivel          = 'C';
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

$classesPermitidas = ['DEV', 'ENGENHARIA CLINICA'];
if (!in_array($classe_usuario, $classesPermitidas) || $nivel !== 'A') {
    header("Location: acesso_bloqueado.html");
    exit();
}

// ── Mensagem de feedback ──────────────────────────────────────────────────────
$msg      = '';
$msg_tipo = '';

// ── Helpers de férias (dia/mês, sem ano) ─────────────────────────────────────
$MESES_PT = [1=>'janeiro','fevereiro','março','abril','maio','junho',
             'julho','agosto','setembro','outubro','novembro','dezembro'];

/** Monta 'MM-DD' a partir de dia e mês; null se incompleto ou inválido */
function ec_md($dia, $mes): ?string {
    $d = intval($dia); $m = intval($mes);
    if ($d < 1 || $d > 31 || $m < 1 || $m > 12) return null;
    // 29/02 é aceito: em ano não bissexto o sistema trata como 28/02
    $limite = [1=>31,2=>29,3=>31,4=>30,5=>31,6=>30,7=>31,8=>31,9=>30,10=>31,11=>30,12=>31];
    if ($d > $limite[$m]) return null;
    return sprintf('%02d-%02d', $m, $d);
}

/** 'MM-DD' → ['dia'=>int,'mes'=>int] (zeros se vazio) */
function ec_md_partes(?string $md): array {
    if (!$md || !preg_match('/^(\d{2})-(\d{2})$/', $md, $m)) return ['dia'=>0,'mes'=>0];
    return ['dia'=>(int)$m[2], 'mes'=>(int)$m[1]];
}

/** 'MM-DD' → '15 de julho' */
function ec_md_texto(?string $md): string {
    global $MESES_PT;
    $p = ec_md_partes($md);
    if (!$p['mes']) return '';
    return $p['dia'] . ' de ' . $MESES_PT[$p['mes']];
}

/** <option> de 1 a 31 */
function ec_opts_dia(int $sel = 0): string {
    $o = '<option value="">Dia</option>';
    for ($d = 1; $d <= 31; $d++) {
        $o .= '<option value="' . $d . '"' . ($d === $sel ? ' selected' : '') . '>' . $d . '</option>';
    }
    return $o;
}

/** <option> de janeiro a dezembro */
function ec_opts_mes(int $sel = 0): string {
    global $MESES_PT;
    $o = '<option value="">Mês</option>';
    foreach ($MESES_PT as $n => $nome) {
        $o .= '<option value="' . $n . '"' . ($n === $sel ? ' selected' : '') . '>' . $nome . '</option>';
    }
    return $o;
}

// ── Excluir ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'excluir') {
    $id_del = intval($_POST['id'] ?? 0);
    if ($id_del > 0) {
        $stmt = $conn->prepare("DELETE FROM tecnico WHERE id = ?");
        $stmt->bind_param("i", $id_del);
        $stmt->execute() ? ($msg = 'Técnico excluído com sucesso.') && ($msg_tipo = 'ok')
                         : ($msg = 'Erro ao excluir.') && ($msg_tipo = 'erro');
        $stmt->close();
    }
}

// ── Salvar / Editar ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar') {
    $id_edit      = intval($_POST['id_edit'] ?? 0);
    $nome         = strtoupper(trim($_POST['nome']      ?? ''));
    $data_nasc    = trim($_POST['data_nasc']             ?? '') ?: null;
    $funcao       = strtoupper(trim($_POST['funcao']     ?? ''));
    $unidade      = strtoupper(trim($_POST['unidade']    ?? ''));
    $usu          = trim($_POST['usuario_tec']           ?? '');
    $celular      = trim($_POST['celular']               ?? '');
    $email        = strtolower(trim($_POST['email']      ?? ''));
    // ── Férias: dia + mês, sem ano (recorrente todo ano) ──────────────────────
    // Guardado como 'MM-DD' para ordenar corretamente por texto.
    $ferias_ini = ec_md($_POST['ferias_ini_dia'] ?? '', $_POST['ferias_ini_mes'] ?? '');
    $ferias_fim = ec_md($_POST['ferias_fim_dia'] ?? '', $_POST['ferias_fim_mes'] ?? '');

    // Um lado sem o outro não define período — descarta os dois
    if ($ferias_ini === null || $ferias_fim === null) {
        $ferias_ini = null;
        $ferias_fim = null;
    }

    $foto = null;
    if (!empty($_FILES['foto']['tmp_name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $ext  = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        $perm = ['jpg','jpeg','png','webp','gif','bmp','tiff','tif','heic','heif','avif'];
        $max  = 10 * 1024 * 1024;

        if (in_array($ext, $perm) && $_FILES['foto']['size'] <= $max) {
            $binario = file_get_contents($_FILES['foto']['tmp_name']);

            if (in_array($ext, ['heic','heif'])) {
                if (class_exists('Imagick')) {
                    try {
                        $img = new Imagick();
                        $img->readImageBlob($binario);
                        $img->setImageFormat('jpeg');
                        $img->setImageCompressionQuality(88);
                        $binario = $img->getImageBlob();
                        $img->clear();
                    } catch (Exception $e) {}
                }
            }

            $foto = $binario;
        }
    }

    if ($nome && $funcao && $unidade) {
        if ($id_edit > 0) {
            if ($foto) {
                $stmt = $conn->prepare("UPDATE tecnico SET nome=?,data_nasc=?,funcao=?,unidade=?,usuario=?,celular=?,email=?,foto=?,ferias_ini_md=?,ferias_fim_md=? WHERE id=?");
                $stmt->bind_param("ssssssssssi", $nome,$data_nasc,$funcao,$unidade,$usu,$celular,$email,$foto,$ferias_ini,$ferias_fim,$id_edit);
            } else {
                $stmt = $conn->prepare("UPDATE tecnico SET nome=?,data_nasc=?,funcao=?,unidade=?,usuario=?,celular=?,email=?,ferias_ini_md=?,ferias_fim_md=? WHERE id=?");
                $stmt->bind_param("sssssssssi", $nome,$data_nasc,$funcao,$unidade,$usu,$celular,$email,$ferias_ini,$ferias_fim,$id_edit);
            }
            $stmt->execute() ? ($msg = 'Técnico atualizado com sucesso.') && ($msg_tipo = 'ok')
                             : ($msg = 'Erro ao atualizar: '.$stmt->error) && ($msg_tipo = 'erro');
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO tecnico (nome,data_nasc,funcao,unidade,usuario,celular,email,foto,ferias_ini_md,ferias_fim_md) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("ssssssssss", $nome,$data_nasc,$funcao,$unidade,$usu,$celular,$email,$foto,$ferias_ini,$ferias_fim);
            $stmt->execute() ? ($msg = 'Técnico cadastrado com sucesso.') && ($msg_tipo = 'ok')
                             : ($msg = 'Erro ao cadastrar: '.$stmt->error) && ($msg_tipo = 'erro');
            $stmt->close();
        }
    } else {
        $msg      = 'Preencha os campos obrigatórios: Nome, Função e Unidade.';
        $msg_tipo = 'erro';
    }
}

// ── Listar técnicos ───────────────────────────────────────────────────────────
$tecnicos = [];
$res = $conn->query("SELECT id, nome, data_nasc, funcao, unidade, usuario, celular, email, foto, ferias_ini_md, ferias_fim_md, periodo_cadastro FROM tecnico ORDER BY nome ASC");
while ($r = $res->fetch_assoc()) {
    $tecnicos[] = [
        'id'             => $r['id'],
        'nome'           => $r['nome'],
        'data_nasc'      => $r['data_nasc']      ? date('d/m/Y', strtotime($r['data_nasc']))      : '',
        'funcao'         => $r['funcao'],
        'unidade'        => $r['unidade'],
        'usuario'        => $r['usuario'],
        'celular'        => $r['celular'],
        'email'          => $r['email'],
        // Férias sem ano: 'MM-DD' no banco, exibido como '15 de julho'
        'ferias_ini_md'  => $r['ferias_ini_md'] ?? '',
        'ferias_fim_md'  => $r['ferias_fim_md'] ?? '',
        'ferias_inicio'  => ec_md_texto($r['ferias_ini_md'] ?? null),
        'ferias_fim'     => ec_md_texto($r['ferias_fim_md'] ?? null),
        'fi_dia'         => ec_md_partes($r['ferias_ini_md'] ?? null)['dia'],
        'fi_mes'         => ec_md_partes($r['ferias_ini_md'] ?? null)['mes'],
        'ff_dia'         => ec_md_partes($r['ferias_fim_md'] ?? null)['dia'],
        'ff_mes'         => ec_md_partes($r['ferias_fim_md'] ?? null)['mes'],
        'foto_b64'       => $r['foto']            ? base64_encode($r['foto'])                       : null,
        'periodo_cadastro' => $r['periodo_cadastro'] ? date('d/m/Y', strtotime($r['periodo_cadastro'])) : '',
    ];
}

$conn->close();
date_default_timezone_set('America/Sao_Paulo');
$data = date('d/m/Y'); $hora = date('H:i:s');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadastro de Técnico — Engenharia Clínica</title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --bg-page:#0f0f0f; --bg-sidebar:#141414; --bg-card:#1a1a1a;
  --bg-card-hover:#1f1f1f; --bg-input:#222; --border:rgba(255,255,255,0.07);
  --border-hover:rgba(255,255,255,0.14); --accent:#c8c8c8;
  --accent-bright:#fff; --accent-muted:#888; --accent-steel:#a0aec0;
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
.brand-subtitle{font-family:var(--font-display);font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--text-muted);white-space:nowrap;transition:opacity var(--transition)}
#sidebar.collapsed .brand-logo-main{width:31px;max-width:31px}
#sidebar.collapsed .brand-subtitle{opacity:0;pointer-events:none}
.sidebar-toggle{position:absolute;top:14px;right:-15px;width:28px;height:28px;background:var(--bg-card);border:1px solid var(--border-hover);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:200;transition:background var(--transition);color:var(--text-secondary);font-size:11px}
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

#main{margin-left:var(--sidebar-w);transition:margin-left var(--transition);min-height:calc(100vh - 42px);display:flex;flex-direction:column;flex:1}
#main.sidebar-collapsed{margin-left:var(--sidebar-collapsed)}

.topbar{height:var(--header-h);background:rgba(20,20,20,0.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:10px;position:sticky;top:0;z-index:50}
.topbar-breadcrumb{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted)}
.topbar-breadcrumb span:last-child{color:var(--text-primary);font-weight:500}
.topbar-breadcrumb i{font-size:10px}
.topbar-spacer{flex:1}
.topbar-btn{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid var(--border);background:#1c1c1c;color:var(--text-secondary);cursor:pointer;transition:background var(--transition),color var(--transition),border-color var(--transition);font-size:13px}
.topbar-btn:hover{background:#2a2a2a;color:var(--text-primary);border-color:var(--border-hover)}
.topbar-logo-rede{height:32px;width:auto;object-fit:contain;opacity:.75;transition:opacity var(--transition);flex-shrink:0}
.topbar-logo-rede:hover{opacity:1}

.content{flex:1;padding:28px;max-width:1400px;width:100%}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
.page-title{font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--text-primary);letter-spacing:-.01em}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}

.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none}
.btn-primary{background:#232323;border:1px solid rgba(255,255,255,0.13);color:var(--text-primary)}
.btn-primary:hover{background:#2e2e2e;border-color:rgba(255,255,255,.2)}
.btn-danger{background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.25);color:#f87171}
.btn-danger:hover{background:rgba(248,113,113,.2)}
.btn-success{background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.25);color:#4ade80}
.btn-success:hover{background:rgba(74,222,128,.2)}

.panel{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;transition:border-color var(--transition);margin-bottom:20px}
.panel:hover{border-color:var(--border-hover)}
.panel-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.panel-title{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--accent-steel);flex:1}
.panel-body{padding:22px 20px}

.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group.full{grid-column:1/-1}
.form-group label{font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted)}
.form-group label .req{color:#f87171;margin-left:3px}
.form-control{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-family:var(--font-ui);font-size:13px;padding:9px 12px;outline:none;transition:border-color var(--transition),background var(--transition);width:100%}
.form-control:focus{border-color:var(--border-hover);background:#262626}
.form-control::placeholder{color:var(--text-muted)}

.foto-upload-wrap{display:flex;align-items:center;gap:16px}
.foto-preview{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--border);background:#222;flex-shrink:0}
.foto-btn{cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;background:#232323;border:1px solid rgba(255,255,255,.13);color:var(--text-primary);font-size:12px;font-weight:500;transition:all var(--transition)}
.foto-btn:hover{background:#2e2e2e;border-color:rgba(255,255,255,.2)}

.ferias-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
/* Férias sem ano: rótulo + dia + mês */
.ferias-md{display:grid;grid-template-columns:44px 78px 1fr;align-items:center;gap:8px}
.ferias-md-lbl{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em}
@media(max-width:640px){.ferias-row{grid-template-columns:1fr}}

.alert{padding:10px 16px;border-radius:8px;font-size:13px;font-weight:500;margin-bottom:18px;display:flex;align-items:center;gap:8px}
.alert-ok{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.25);color:#4ade80}
.alert-erro{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.25);color:#f87171}

.tbl-wrap{overflow-x:auto}
.tbl-tecnicos{width:100%;border-collapse:collapse;font-size:13px;min-width:800px}
.tbl-tecnicos th{background:#1e2025;color:var(--accent-steel);font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.tbl-tecnicos td{padding:10px 12px;border-bottom:1px solid var(--border);color:var(--text-secondary);vertical-align:middle}
.tbl-tecnicos tr:hover td{background:#1d1d1d;color:var(--text-primary)}
.tbl-tecnicos tr:last-child td{border-bottom:none}

.avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;border:1.5px solid var(--border-hover);background:#222;display:block}

.badge-ferias{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:600;padding:3px 9px;border-radius:20px;background:rgba(250,204,21,.1);color:var(--status-warn);border:1px solid rgba(250,204,21,.2)}
.badge-ativo{background:rgba(74,222,128,.08);color:var(--status-ok);border:1px solid rgba(74,222,128,.2)}

.tbl-search{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:12px;padding:7px 12px;outline:none;width:220px;transition:border-color var(--transition)}
.tbl-search:focus{border-color:var(--border-hover)}
.tbl-search::placeholder{color:var(--text-muted)}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:2000;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal-box{background:#1a1a1a;border:1px solid var(--border-hover);border-radius:var(--radius-lg);width:100%;max-width:680px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,.5);animation:modalIn .2s ease}
@keyframes modalIn{from{transform:scale(.95);opacity:0}to{transform:scale(1);opacity:1}}
.modal-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-title{font-family:var(--font-display);font-size:15px;font-weight:600;color:var(--text-primary)}
.modal-close{background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px;line-height:1;padding:2px 6px;border-radius:4px;transition:color var(--transition)}
.modal-close:hover{color:var(--text-primary)}
.modal-body{padding:20px}
.modal-footer{padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}

.footer{background:#181818;color:#888;border-top:1px solid var(--border);padding:12px 20px;display:flex;justify-content:space-between;flex-wrap:wrap;font-size:13px;gap:6px;margin-left:var(--sidebar-w);transition:margin-left var(--transition)}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeUp .35s ease both}
.delay-1{animation-delay:.05s}
.delay-2{animation-delay:.10s}

::-webkit-scrollbar{width:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.07);border-radius:4px}
::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.14)}

@media(max-width:900px){.content{padding:16px}.footer,#main{margin-left:var(--sidebar-collapsed)}#sidebar{width:var(--sidebar-collapsed)}#sidebar.open{width:var(--sidebar-w)}}
@media(max-width:640px){#sidebar{position:fixed;top:0;left:0;height:100vh;z-index:1100;transform:translateX(-100%);transition:transform var(--transition);width:var(--sidebar-w)!important;overflow-y:auto}#sidebar.open{transform:translateX(0)}.sidebar-toggle{display:none}.menu-toggle{display:block}#main{margin-left:0!important}.topbar{padding-left:54px}.content{padding:12px}.form-grid{grid-template-columns:1fr}.footer{margin-left:0}}

#sidebar.collapsed .nav-item[data-tooltip="Página Inicial"]::before{content:"\f015"}
#sidebar.collapsed .nav-item[data-tooltip="Sair"]::before{content:"\f2f5"}
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
      <span>Cadastro de Técnico</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
      <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <div class="page-header fade-up">
      <div>
        <div class="page-title">Cadastro de Técnico</div>
        <div class="page-subtitle">Engenharia Clínica &middot; <?= eng_clin_data_pt() ?></div>
      </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert <?= $msg_tipo === 'ok' ? 'alert-ok' : 'alert-erro' ?> fade-up">
      <i class="fas <?= $msg_tipo === 'ok' ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <!-- ══ BLOCO 1: CADASTRO ══ -->
    <div class="panel fade-up delay-1">
      <div class="panel-header">
        <span class="panel-title"><i class="fas fa-user-plus" style="margin-right:6px"></i>Novo Técnico</span>
      </div>
      <div class="panel-body">
        <form method="POST" enctype="multipart/form-data" id="formCadastro">
          <input type="hidden" name="action" value="salvar">
          <input type="hidden" name="id_edit" value="0">

          <div class="form-grid">
            <div class="form-group full">
              <label>Nome Completo <span class="req">*</span></label>
              <input type="text" name="nome" class="form-control" placeholder="Nome completo do técnico" required style="text-transform:uppercase">
            </div>
            <div class="form-group">
              <label>Data de Nascimento</label>
              <input type="date" name="data_nasc" class="form-control">
            </div>
            <div class="form-group">
              <label>Função <span class="req">*</span></label>
              <input type="text" name="funcao" class="form-control" placeholder="Ex: Técnico em Equipamentos" required style="text-transform:uppercase">
            </div>
            <div class="form-group">
              <label>Unidade <span class="req">*</span></label>
              <input type="text" name="unidade" class="form-control" placeholder="Unidade de atuação" required style="text-transform:uppercase">
            </div>
            <div class="form-group">
              <label>Usuário do Sistema</label>
              <input type="text" name="usuario_tec" class="form-control" placeholder="Login no sistema">
            </div>
            <div class="form-group">
              <label>Celular</label>
              <input type="text" name="celular" id="celular" class="form-control" placeholder="(00) 00000-0000" maxlength="15">
            </div>
            <div class="form-group">
              <label>E-mail</label>
              <input type="email" name="email" class="form-control" placeholder="email@exemplo.com" style="text-transform:none">
            </div>
            <div class="form-group full">
              <label>Período de Férias
                <span style="color:var(--text-muted);font-size:10px;text-transform:none;letter-spacing:0">(dia e mês — vale para todo ano)</span>
              </label>
              <div class="ferias-row">
                <div class="ferias-md">
                  <span class="ferias-md-lbl">Início</span>
                  <select name="ferias_ini_dia" class="form-control"><?= ec_opts_dia() ?></select>
                  <select name="ferias_ini_mes" class="form-control"><?= ec_opts_mes() ?></select>
                </div>
                <div class="ferias-md">
                  <span class="ferias-md-lbl">Fim</span>
                  <select name="ferias_fim_dia" class="form-control"><?= ec_opts_dia() ?></select>
                  <select name="ferias_fim_mes" class="form-control"><?= ec_opts_mes() ?></select>
                </div>
              </div>
              <div style="font-size:10.5px;color:var(--text-muted);margin-top:6px;text-transform:none;letter-spacing:0">
                Períodos que atravessam o ano (ex.: 20 de dezembro a 10 de janeiro) são aceitos.
              </div>
            </div>
            <div class="form-group full">
              <label>Foto de Perfil <span style="color:var(--text-muted);font-size:10px;text-transform:none;letter-spacing:0">(opcional · máx. 10 MB · JPG, PNG, WEBP, HEIC, AVIF, BMP, TIFF)</span></label>
              <div class="foto-upload-wrap">
                <img id="fotoPreview" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 80 80'%3E%3Crect width='80' height='80' fill='%23333'/%3E%3Ccircle cx='40' cy='30' r='16' fill='%23555'/%3E%3Cellipse cx='40' cy='72' rx='28' ry='18' fill='%23555'/%3E%3C/svg%3E"
                     alt="Foto" class="foto-preview">
                <label class="foto-btn" for="fotoInput"><i class="fas fa-camera"></i> Escolher foto</label>
                <input type="file" name="foto" id="fotoInput" accept="image/*,.heic,.heif,.avif,.tiff,.tif,.bmp" style="display:none">
              </div>
            </div>
          </div>

          <div style="margin-top:20px;display:flex;gap:10px;justify-content:flex-end">
            <button type="reset" class="btn btn-primary" onclick="resetarForm()"><i class="fas fa-rotate-left"></i> Limpar</button>
            <button type="submit" class="btn btn-success"><i class="fas fa-floppy-disk"></i> Salvar Técnico</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ══ BLOCO 2: LISTA ══ -->
    <div class="panel fade-up delay-2">
      <div class="panel-header">
        <span class="panel-title"><i class="fas fa-users" style="margin-right:6px"></i>Técnicos Cadastrados
          <span style="background:rgba(255,255,255,.07);color:var(--text-muted);font-size:11px;padding:2px 8px;border-radius:20px;margin-left:8px;font-weight:400"><?= count($tecnicos) ?></span>
        </span>
        <input type="text" class="tbl-search" id="buscaTecnico" placeholder="Buscar técnico...">
      </div>
      <div class="panel-body" style="padding:0">
        <?php if (empty($tecnicos)): ?>
          <div style="padding:40px;text-align:center;color:var(--text-muted);font-size:13px">
            <i class="fas fa-user-slash" style="font-size:28px;margin-bottom:10px;display:block;opacity:.4"></i>
            Nenhum técnico cadastrado ainda.
          </div>
        <?php else: ?>
        <div class="tbl-wrap">
          <table class="tbl-tecnicos" id="tblTecnicos">
            <thead>
              <tr>
                <th style="width:46px"></th>
                <th>Nome</th>
                <th>Função</th>
                <th>Unidade</th>
                <th>Celular</th>
                <th>Férias</th>
                <th>Cadastrado</th>
                <th style="width:90px;text-align:center">Ações</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tecnicos as $t):
                $semFoto = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 80 80'%3E%3Crect width='80' height='80' fill='%23333'/%3E%3Ccircle cx='40' cy='30' r='16' fill='%23555'/%3E%3Cellipse cx='40' cy='72' rx='28' ry='18' fill='%23555'/%3E%3C/svg%3E";
                $imgSrc  = $t['foto_b64'] ? 'data:image/jpeg;base64,'.$t['foto_b64'] : $semFoto;
                $emFerias = $t['ferias_inicio'] && $t['ferias_fim'];
              ?>
              <tr>
                <td><img src="<?= $imgSrc ?>" alt="" class="avatar"></td>
                <td>
                  <div style="font-weight:500;color:var(--text-primary)"><?= htmlspecialchars($t['nome']) ?></div>
                  <?php if ($t['email']): ?>
                  <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($t['email']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($t['funcao']) ?></td>
                <td><?= htmlspecialchars($t['unidade']) ?></td>
                <td><?= htmlspecialchars($t['celular'] ?: '—') ?></td>
                <td>
                  <?php if ($emFerias): ?>
                    <span class="badge-ferias"><i class="fas fa-umbrella-beach"></i> <?= $t['ferias_inicio'] ?> – <?= $t['ferias_fim'] ?></span>
                  <?php else: ?>
                    <span class="badge-ferias badge-ativo"><i class="fas fa-check"></i> Ativo</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:11px"><?= $t['periodo_cadastro'] ?></td>
                <td style="text-align:center">
                  <button class="btn btn-primary" style="padding:5px 10px;font-size:11px" onclick='abrirEditar(<?= json_encode($t) ?>)'>
                    <i class="fas fa-pen"></i>
                  </button>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Excluir este técnico?')">
                    <input type="hidden" name="action" value="excluir">
                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                    <button type="submit" class="btn btn-danger" style="padding:5px 10px;font-size:11px">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<!-- ══ MODAL EDITAR ══ -->
<div class="modal-overlay" id="modalEditar">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-pen" style="margin-right:8px"></i>Editar Técnico</span>
      <button class="modal-close" onclick="fecharModal()">&times;</button>
    </div>
    <form method="POST" enctype="multipart/form-data" id="formEditar">
      <input type="hidden" name="action" value="salvar">
      <input type="hidden" name="id_edit" id="edit_id">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full">
            <label>Nome Completo <span class="req">*</span></label>
            <input type="text" name="nome" id="edit_nome" class="form-control" required style="text-transform:uppercase">
          </div>
          <div class="form-group">
            <label>Data de Nascimento</label>
            <input type="date" name="data_nasc" id="edit_data_nasc" class="form-control">
          </div>
          <div class="form-group">
            <label>Função <span class="req">*</span></label>
            <input type="text" name="funcao" id="edit_funcao" class="form-control" required style="text-transform:uppercase">
          </div>
          <div class="form-group">
            <label>Unidade <span class="req">*</span></label>
            <input type="text" name="unidade" id="edit_unidade" class="form-control" required style="text-transform:uppercase">
          </div>
          <div class="form-group">
            <label>Usuário do Sistema</label>
            <input type="text" name="usuario_tec" id="edit_usuario" class="form-control">
          </div>
          <div class="form-group">
            <label>Celular</label>
            <input type="text" name="celular" id="edit_celular" class="form-control" maxlength="15">
          </div>
          <div class="form-group full">
            <label>E-mail</label>
            <input type="email" name="email" id="edit_email" class="form-control" style="text-transform:none">
          </div>
          <div class="form-group full">
            <label>Período de Férias
              <span style="color:var(--text-muted);font-size:10px;text-transform:none;letter-spacing:0">(dia e mês — vale para todo ano)</span>
            </label>
            <div class="ferias-row">
              <div class="ferias-md">
                <span class="ferias-md-lbl">Início</span>
                <select name="ferias_ini_dia" id="edit_fi_dia" class="form-control"><?= ec_opts_dia() ?></select>
                <select name="ferias_ini_mes" id="edit_fi_mes" class="form-control"><?= ec_opts_mes() ?></select>
              </div>
              <div class="ferias-md">
                <span class="ferias-md-lbl">Fim</span>
                <select name="ferias_fim_dia" id="edit_ff_dia" class="form-control"><?= ec_opts_dia() ?></select>
                <select name="ferias_fim_mes" id="edit_ff_mes" class="form-control"><?= ec_opts_mes() ?></select>
              </div>
            </div>
          </div>
          <div class="form-group full">
            <label>Nova Foto <span style="color:var(--text-muted);font-size:10px;text-transform:none;letter-spacing:0">(deixe em branco para manter · máx. 10 MB · JPG, PNG, WEBP, HEIC, AVIF, BMP, TIFF)</span></label>
            <div class="foto-upload-wrap">
              <!-- Placeholder em data URI, nunca src="". Endereço vazio faz o
                   navegador buscar a PRÓPRIA página como imagem: uma segunda
                   requisição completa a cada abertura da tela, com o PHP
                   rodando e consultando o banco de novo, para o resultado ser
                   descartado. -->
              <img id="edit_fotoPreview" alt="Foto" class="foto-preview"
                   src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 80 80'%3E%3Crect width='80' height='80' fill='%23333'/%3E%3Ccircle cx='40' cy='30' r='16' fill='%23555'/%3E%3Cellipse cx='40' cy='72' rx='28' ry='18' fill='%23555'/%3E%3C/svg%3E">
              <label class="foto-btn" for="edit_fotoInput"><i class="fas fa-camera"></i> Trocar foto</label>
              <input type="file" name="foto" id="edit_fotoInput" accept="image/*,.heic,.heif,.avif,.tiff,.tif,.bmp" style="display:none">
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" onclick="fecharModal()"><i class="fas fa-xmark"></i> Cancelar</button>
        <button type="submit" class="btn btn-success"><i class="fas fa-floppy-disk"></i> Salvar Alterações</button>
      </div>
    </form>
  </div>
</div>

<div class="footer" id="pageFooter">
  <div>Usuário: <?= htmlspecialchars($usuario) ?></div>
  <div>Data: <?= $data ?> | Hora: <span id="hora"><?= $hora ?></span></div>
  <div>&copy; GK Soluções</div>
</div>

<script>
setInterval(() => { document.getElementById('hora').innerText = new Date().toLocaleTimeString('pt-BR'); }, 1000);

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
sidebar.querySelectorAll('.nav-item').forEach(i => {
  i.addEventListener('click', () => { if (window.innerWidth <= 640) fecharSidebar(); });
});

function maskCel(v) {
  v = v.replace(/\D/g,'');
  v = v.replace(/^(\d{2})(\d)/, '($1) $2');
  v = v.replace(/(\d{5})(\d)/, '$1-$2');
  return v;
}
['celular','edit_celular'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('input', function(){ this.value = maskCel(this.value); });
});

document.getElementById('fotoInput').addEventListener('change', function() {
  const f = this.files[0]; if (!f) return;
  const r = new FileReader();
  r.onload = e => { document.getElementById('fotoPreview').src = e.target.result; };
  r.readAsDataURL(f);
});

document.getElementById('edit_fotoInput').addEventListener('change', function() {
  const f = this.files[0]; if (!f) return;
  const r = new FileReader();
  r.onload = e => { document.getElementById('edit_fotoPreview').src = e.target.result; };
  r.readAsDataURL(f);
});

function resetarForm() {
  const semFoto = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 80 80'%3E%3Crect width='80' height='80' fill='%23333'/%3E%3Ccircle cx='40' cy='30' r='16' fill='%23555'/%3E%3Cellipse cx='40' cy='72' rx='28' ry='18' fill='%23555'/%3E%3C/svg%3E";
  document.getElementById('fotoPreview').src = semFoto;
}

function abrirEditar(t) {
  document.getElementById('edit_id').value         = t.id;
  document.getElementById('edit_nome').value       = t.nome;
  document.getElementById('edit_funcao').value     = t.funcao;
  document.getElementById('edit_unidade').value    = t.unidade;
  document.getElementById('edit_usuario').value    = t.usuario;
  document.getElementById('edit_celular').value    = t.celular || '';
  document.getElementById('edit_email').value      = t.email  || '';

  function brToIso(d) {
    if (!d) return '';
    const p = d.split('/');
    return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : '';
  }
  document.getElementById('edit_data_nasc').value  = brToIso(t.data_nasc);
  document.getElementById('edit_fi_dia').value = t.fi_dia || '';
  document.getElementById('edit_fi_mes').value = t.fi_mes || '';
  document.getElementById('edit_ff_dia').value = t.ff_dia || '';
  document.getElementById('edit_ff_mes').value = t.ff_mes || '';

  const semFoto = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 80 80'%3E%3Crect width='80' height='80' fill='%23333'/%3E%3Ccircle cx='40' cy='30' r='16' fill='%23555'/%3E%3Cellipse cx='40' cy='72' rx='28' ry='18' fill='%23555'/%3E%3C/svg%3E";
  document.getElementById('edit_fotoPreview').src = t.foto_b64
    ? 'data:image/jpeg;base64,' + t.foto_b64
    : semFoto;

  document.getElementById('modalEditar').classList.add('open');
}
function fecharModal() {
  document.getElementById('modalEditar').classList.remove('open');
}
document.getElementById('modalEditar').addEventListener('click', function(e) {
  if (e.target === this) fecharModal();
});

document.getElementById('buscaTecnico').addEventListener('input', function() {
  const t = this.value.toLowerCase();
  document.querySelectorAll('#tblTecnicos tbody tr').forEach(tr => {
    tr.style.display = tr.innerText.toLowerCase().includes(t) ? '' : 'none';
  });
});
</script>
<?php eng_clin_menu_painel(true); ?>
</body>
</html>
