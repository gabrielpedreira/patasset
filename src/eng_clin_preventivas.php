<?php
/**
 * eng_clin_preventivas.php
 * Agenda de manutenções preventivas, histórico e cadastro manual.
 */
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = 'preventivas';
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
$hoje     = date('Y-m-d');
$hoje_ts  = strtotime('today');
$msg = ''; $msg_tipo = '';

/** Registra uma linha no histórico da preventiva */
function prev_hist(mysqli $c, array $d): void {
    $st = $c->prepare("INSERT INTO preventiva_hist_engclin
        (id_preventiva,item_id,acao,tecnico_usuario,nome_tecnico,data_exec,hora_exec,
         servico_troca,periodicidade_meses,data_anterior,proxima_data,usuario)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    if (!$st) return;
    // bind_param exige variáveis por referência — nunca expressões.
    $a1 = $d['id_preventiva'] !== null ? (int)$d['id_preventiva'] : null;
    $a2 = (int)$d['item_id'];
    $a3 = $d['acao'];
    $a4 = $d['tecnico_usuario'];
    $a5 = $d['nome_tecnico'];
    $a6 = $d['data_exec'];
    $a7 = $d['hora_exec'];
    $a8 = $d['servico_troca'];
    $a9 = (int)$d['periodicidade_meses'];
    $a10 = ($d['data_anterior'] ?: null);
    $a11 = ($d['proxima_data'] ?: null);
    $a12 = $d['usuario'];
    $st->bind_param('iissssssisss', $a1,$a2,$a3,$a4,$a5,$a6,$a7,$a8,$a9,$a10,$a11,$a12);
    $st->execute(); $st->close();
}

/* ═══════════════════════════════════════════════════════════════════════════
   AJAX — busca de item por tag ou série
   ═══════════════════════════════════════════════════════════════════════════ */
if (($_GET['acao'] ?? '') === 'buscar_item') {
    header('Content-Type: application/json; charset=utf-8');
    $q = strtoupper(trim($_GET['q'] ?? ''));
    if ($q === '') { echo json_encode(['ok'=>false,'msg'=>'Informe a tag ou série.']); exit(); }

    // Tag procura em tag_antiga E tag_trocada; só itens da Engenharia Clínica
    $st = $conn->prepare("
        SELECT id, descricao, marca, modelo, serie, tag_antiga, tag_trocada,
               unidade, setor, area, unidade_destino, setor_destino, area_destino, movimentado
        FROM cadastro
        WHERE (UPPER(TRIM(tag_antiga))  = ?
            OR UPPER(TRIM(tag_trocada)) = ?
            OR UPPER(TRIM(serie))       = ?)
          AND UPPER(TRIM(COALESCE(responsavel,''))) = 'ENGENHARIA CLINICA'
        LIMIT 1
    ");
    if (!$st) { echo json_encode(['ok'=>false,'msg'=>'Erro de banco.']); exit(); }
    $st->bind_param('sss', $q, $q, $q);
    $st->execute();
    $r = $st->get_result();
    $it = $r ? $r->fetch_assoc() : null;
    $st->close();

    if (!$it) {
        echo json_encode(['ok'=>false,'msg'=>'Nenhum equipamento da Engenharia Clínica encontrado com essa tag ou série.']);
        exit();
    }

    // Já está na agenda?
    $ja = 0;
    $stJ = $conn->prepare("SELECT id FROM preventiva_engclin WHERE item_id=? AND ativo=1 LIMIT 1");
    if ($stJ) {
        $stJ->bind_param('i', $it['id']); $stJ->execute();
        $rj = $stJ->get_result(); $ja = ($rj && $rj->fetch_assoc()) ? 1 : 0;
        $stJ->close();
    }
    $it['ja_agendado'] = $ja;
    echo json_encode(['ok'=>true,'item'=>$it]);
    exit();
}

/* ═══════════════════════════════════════════════════════════════════════════
   AÇÕES
   ═══════════════════════════════════════════════════════════════════════════ */
$acao = $_POST['acao'] ?? '';

/* ── Adicionar item à agenda ─────────────────────────────────────────── */
if ($acao === 'add') {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $meses   = max(1, min(120, (int)($_POST['periodicidade'] ?? 6)));
    $primeira= trim($_POST['primeira_data'] ?? '') ?: date('Y-m-d', strtotime("+{$meses} months"));

    if ($item_id <= 0) {
        $msg = 'Selecione um equipamento válido.'; $msg_tipo = 'erro';
    } else {
        $st = $conn->prepare("INSERT INTO preventiva_engclin (item_id,periodicidade_meses,proxima_data,origem,usuario)
                              VALUES (?,?,?,'MANUAL',?)
                              ON DUPLICATE KEY UPDATE periodicidade_meses=VALUES(periodicidade_meses),
                                                      proxima_data=VALUES(proxima_data), ativo=1");
        if ($st) {
            $st->bind_param('iiss', $item_id, $meses, $primeira, $usuario);
            $ok = $st->execute(); $st->close();
            if ($ok) {
                $idp = 0;
                $sg = $conn->prepare("SELECT id FROM preventiva_engclin WHERE item_id=? LIMIT 1");
                if ($sg) { $sg->bind_param('i',$item_id); $sg->execute(); $rg=$sg->get_result();
                           if($rg && ($xg=$rg->fetch_assoc())) $idp=(int)$xg['id']; $sg->close(); }
                prev_hist($conn, ['id_preventiva'=>$idp,'item_id'=>$item_id,'acao'=>'AGENDADA',
                    'tecnico_usuario'=>null,'nome_tecnico'=>null,'data_exec'=>null,'hora_exec'=>null,
                    'servico_troca'=>'Item incluído manualmente na agenda.','periodicidade_meses'=>$meses,
                    'data_anterior'=>null,'proxima_data'=>$primeira,'usuario'=>$usuario]);
                $msg = 'Equipamento incluído na agenda de preventivas.'; $msg_tipo = 'ok';
            } else { $msg = 'Erro ao incluir.'; $msg_tipo = 'erro'; }
        }
    }
    header("Location: eng_clin_preventivas.php?m=".urlencode($msg)."&t=".$msg_tipo); exit();
}

/* ── Registrar manutenção realizada ──────────────────────────────────── */
if ($acao === 'realizada') {
    $idp     = (int)($_POST['id'] ?? 0);
    $manter  = ($_POST['manter_periodicidade'] ?? '1') === '1';
    $novo_m  = max(1, min(120, (int)($_POST['nova_periodicidade'] ?? 6)));
    $tec     = trim($_POST['tecnico'] ?? '');
    $dt      = trim($_POST['data_exec'] ?? '') ?: $hoje;
    $hr      = trim($_POST['hora_exec'] ?? '') ?: date('H:i:s');
    $troca   = trim($_POST['servico_troca'] ?? '');

    $st = $conn->prepare("SELECT * FROM preventiva_engclin WHERE id=? LIMIT 1");
    $pv = null;
    if ($st) { $st->bind_param('i',$idp); $st->execute(); $r=$st->get_result();
               $pv = $r ? $r->fetch_assoc() : null; $st->close(); }

    if (!$pv) { $msg='Registro não encontrado.'; $msg_tipo='erro'; }
    elseif ($tec === '') { $msg='Selecione o técnico que executou.'; $msg_tipo='erro'; }
    else {
        $meses = $manter ? (int)$pv['periodicidade_meses'] : $novo_m;
        // A próxima conta a partir da data em que foi feita, não da agendada
        $prox  = date('Y-m-d', strtotime("+{$meses} months", strtotime($dt)));

        $nome_tec = $tec;
        $sn = $conn->prepare("SELECT nome FROM tecnico WHERE usuario=? LIMIT 1");
        if ($sn) { $sn->bind_param('s',$tec); $sn->execute(); $rn=$sn->get_result();
                   if($rn && ($xn=$rn->fetch_assoc())) $nome_tec=$xn['nome']; $sn->close(); }

        $su = $conn->prepare("UPDATE preventiva_engclin SET periodicidade_meses=?, proxima_data=?, ultima_troca=? WHERE id=?");
        if ($su) { $su->bind_param('issi', $meses, $prox, $troca, $idp); $su->execute(); $su->close(); }

        if (strlen($hr) === 5) $hr .= ':00';
        prev_hist($conn, ['id_preventiva'=>$idp,'item_id'=>(int)$pv['item_id'],'acao'=>'REALIZADA',
            'tecnico_usuario'=>$tec,'nome_tecnico'=>$nome_tec,'data_exec'=>$dt,'hora_exec'=>$hr,
            'servico_troca'=>$troca,'periodicidade_meses'=>$meses,
            'data_anterior'=>$pv['proxima_data'],'proxima_data'=>$prox,'usuario'=>$usuario]);

        $msg = "Manutenção registrada. Próxima revisão em " . date('d/m/Y', strtotime($prox)) . ".";
        $msg_tipo = 'ok';
    }
    header("Location: eng_clin_preventivas.php?m=".urlencode($msg)."&t=".$msg_tipo); exit();
}

/* ── Adiar ───────────────────────────────────────────────────────────── */
if ($acao === 'adiar') {
    $idp    = (int)($_POST['id'] ?? 0);
    $manter = ($_POST['manter_periodicidade'] ?? '1') === '1';
    $novo_m = max(1, min(120, (int)($_POST['nova_periodicidade'] ?? 6)));
    $motivo = trim($_POST['motivo_adiar'] ?? '');

    $st = $conn->prepare("SELECT * FROM preventiva_engclin WHERE id=? LIMIT 1");
    $pv = null;
    if ($st) { $st->bind_param('i',$idp); $st->execute(); $r=$st->get_result();
               $pv = $r ? $r->fetch_assoc() : null; $st->close(); }

    if (!$pv) { $msg='Registro não encontrado.'; $msg_tipo='erro'; }
    else {
        $meses = $manter ? (int)$pv['periodicidade_meses'] : $novo_m;
        // Adiar conta a partir da data agendada, não de hoje — senão o
        // atraso acumulado desapareceria do controle.
        $base  = $pv['proxima_data'] ?: $hoje;
        $prox  = date('Y-m-d', strtotime("+{$meses} months", strtotime($base)));

        $su = $conn->prepare("UPDATE preventiva_engclin SET periodicidade_meses=?, proxima_data=? WHERE id=?");
        if ($su) { $su->bind_param('isi', $meses, $prox, $idp); $su->execute(); $su->close(); }

        prev_hist($conn, ['id_preventiva'=>$idp,'item_id'=>(int)$pv['item_id'],'acao'=>'ADIADA',
            'tecnico_usuario'=>null,'nome_tecnico'=>null,'data_exec'=>$hoje,'hora_exec'=>date('H:i:s'),
            'servico_troca'=>($motivo ?: 'Adiada sem motivo informado.'),'periodicidade_meses'=>$meses,
            'data_anterior'=>$pv['proxima_data'],'proxima_data'=>$prox,'usuario'=>$usuario]);

        $msg = "Adiada para " . date('d/m/Y', strtotime($prox)) . "."; $msg_tipo = 'ok';
    }
    header("Location: eng_clin_preventivas.php?m=".urlencode($msg)."&t=".$msg_tipo); exit();
}

/* ── Remover da agenda ───────────────────────────────────────────────── */
if ($acao === 'remover') {
    $idp = (int)($_POST['id'] ?? 0);
    $st = $conn->prepare("SELECT * FROM preventiva_engclin WHERE id=? LIMIT 1");
    $pv = null;
    if ($st) { $st->bind_param('i',$idp); $st->execute(); $r=$st->get_result();
               $pv = $r ? $r->fetch_assoc() : null; $st->close(); }
    if ($pv) {
        prev_hist($conn, ['id_preventiva'=>$idp,'item_id'=>(int)$pv['item_id'],'acao'=>'REMOVIDA',
            'tecnico_usuario'=>null,'nome_tecnico'=>null,'data_exec'=>$hoje,'hora_exec'=>date('H:i:s'),
            'servico_troca'=>'Removida da agenda de preventivas.','periodicidade_meses'=>(int)$pv['periodicidade_meses'],
            'data_anterior'=>$pv['proxima_data'],'proxima_data'=>null,'usuario'=>$usuario]);
        // Baixa lógica: apagar a linha levaria junto o histórico do equipamento.
        // Reincluir depois reativa a mesma linha (item_id é UNIQUE).
        $sd = $conn->prepare("UPDATE preventiva_engclin SET ativo=0 WHERE id=?");
        if ($sd) { $sd->bind_param('i',$idp); $sd->execute(); $sd->close(); }
        $msg = 'Equipamento removido da agenda.'; $msg_tipo = 'ok';
    }
    header("Location: eng_clin_preventivas.php?m=".urlencode($msg)."&t=".$msg_tipo); exit();
}

if (isset($_GET['m'])) { $msg = $_GET['m']; $msg_tipo = ($_GET['t'] ?? '') === 'ok' ? 'ok' : 'erro'; }

/* ═══════════════════════════════════════════════════════════════════════════
   DADOS DA PÁGINA
   ═══════════════════════════════════════════════════════════════════════════ */
$SQL_ITEM = "
    p.id, p.item_id, p.periodicidade_meses, p.proxima_data, p.origem,
    p.numero_chamado, p.ultima_troca,
    c.descricao, c.marca, c.modelo, c.serie, c.tag_antiga, c.tag_trocada,
    c.unidade, c.setor, c.area, c.unidade_destino, c.setor_destino, c.area_destino, c.movimentado";

$agenda = [];
$res = $conn->query("SELECT $SQL_ITEM FROM preventiva_engclin p
                     LEFT JOIN cadastro c ON c.id = p.item_id
                     WHERE p.ativo = 1
                     ORDER BY p.proxima_data ASC");
if ($res) while ($r = $res->fetch_assoc()) {
    $mov = strtoupper(trim((string)($r['movimentado'] ?? ''))) === 'SIM';
    $r['loc_unidade'] = $mov ? ($r['unidade_destino'] ?: $r['unidade']) : $r['unidade'];
    $r['loc_setor']   = $mov ? ($r['setor_destino']   ?: $r['setor'])   : $r['setor'];
    $r['loc_area']    = $mov ? ($r['area_destino']    ?: $r['area'])    : $r['area'];
    $dias = (int)floor((strtotime($r['proxima_data']) - $hoje_ts) / 86400);
    $r['dias'] = $dias;
    // vencida (vermelho) | hoje (roxo) | 7 dias (amarelo) | no prazo
    $r['sit'] = $dias < 0 ? 'venc' : ($dias === 0 ? 'hoje' : ($dias <= 7 ? 'alerta' : 'ok'));
    $agenda[] = $r;
}
$n_venc   = count(array_filter($agenda, fn($a) => $a['sit'] === 'venc'));
$n_hoje   = count(array_filter($agenda, fn($a) => $a['sit'] === 'hoje'));
$n_semana = count(array_filter($agenda, fn($a) => $a['dias'] >= 0 && $a['dias'] <= 7));

$tecnicos = [];
$rt = $conn->query("SELECT usuario, nome, funcao FROM tecnico WHERE usuario IS NOT NULL AND usuario <> '' ORDER BY nome ASC");
if ($rt) while ($t = $rt->fetch_assoc()) $tecnicos[] = $t;

$historico = [];
$rh = $conn->query("
    SELECT h.*, c.descricao, c.marca, c.modelo, c.serie, c.tag_antiga, c.tag_trocada,
           c.unidade, c.setor, c.area
    FROM preventiva_hist_engclin h
    LEFT JOIN cadastro c ON c.id = h.item_id
    ORDER BY h.id DESC LIMIT 200");
if ($rh) while ($x = $rh->fetch_assoc()) $historico[] = $x;

$conn->close();
$data = date('d/m/Y'); $hora = date('H:i:s');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manutenções Preventivas — Engenharia Clínica</title>
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
  --amarelo:#facc15; --roxo:#a78bfa; --vermelho:#f87171; --verde:#4ade80;
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
.content{flex:1;padding:24px 28px;max-width:1250px;width:100%}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}
.page-title{font-family:var(--font-display);font-size:22px;font-weight:700;letter-spacing:-.01em}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:8px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none}
.btn-ghost{background:#232323;border:1px solid rgba(255,255,255,.12);color:var(--text-primary)}
.btn-ghost:hover{background:#2e2e2e}
.btn-ok{background:rgba(74,222,128,.14);border:1px solid rgba(74,222,128,.4);color:var(--verde);font-weight:600}
.btn-ok:hover{background:rgba(74,222,128,.22)}
.btn-warn{background:rgba(250,204,21,.13);border:1px solid rgba(250,204,21,.35);color:var(--amarelo)}
.btn-warn:hover{background:rgba(250,204,21,.2)}
.btn-del{background:none;border:1px solid var(--border);color:var(--text-muted)}
.btn-del:hover{color:var(--vermelho);border-color:rgba(248,113,113,.3)}
/* Abas */
.abas{display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:18px;flex-wrap:wrap}
.aba{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;font-size:13px;color:var(--text-secondary);text-decoration:none;border-bottom:2px solid transparent;cursor:pointer;background:none;border-top:none;border-left:none;border-right:none;font-family:var(--font-ui)}
.aba:hover{color:var(--text-primary)}
.aba.on{color:var(--text-primary);border-bottom-color:var(--verde);font-weight:600}
.aba-cnt{background:rgba(255,255,255,.07);border:1px solid var(--border);border-radius:20px;padding:1px 8px;font-size:10.5px;font-weight:700}
/* Resumo */
.resumo{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}
.rz{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:15px 18px}
.rz-l{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:7px}
.rz-v{font-family:var(--font-display);font-size:25px;font-weight:700;line-height:1}
.rz.venc{border-color:rgba(248,113,113,.3)} .rz.venc .rz-v{color:var(--vermelho)}
.rz.hoje{border-color:rgba(167,139,250,.35)} .rz.hoje .rz-v{color:var(--roxo)}
.rz.sem{border-color:rgba(250,204,21,.3)} .rz.sem .rz-v{color:var(--amarelo)}
/* Guia de cores */
.guia{display:flex;gap:18px;flex-wrap:wrap;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:11px 16px;margin-bottom:16px;font-size:11.5px;color:var(--text-secondary)}
.guia-i{display:flex;align-items:center;gap:7px}
.guia-c{width:11px;height:11px;border-radius:3px;flex-shrink:0}
/* Cards da agenda */
.pv{display:flex;gap:15px;background:var(--bg-card);border:1px solid var(--border);border-left-width:3px;border-radius:12px;padding:14px 17px;margin-bottom:10px;align-items:flex-start;flex-wrap:wrap}
.pv-ok{border-left-color:var(--border-hover)}
.pv-alerta{border-left-color:var(--amarelo);background:rgba(250,204,21,.03)}
.pv-hoje{border-left-color:var(--roxo);background:rgba(167,139,250,.05)}
.pv-venc{border-left-color:var(--vermelho);background:rgba(248,113,113,.04)}
.pv-data{width:70px;flex-shrink:0;text-align:center}
.pv-dia{font-family:var(--font-display);font-size:16px;font-weight:700;line-height:1.1}
.pv-alerta .pv-dia{color:var(--amarelo)} .pv-hoje .pv-dia{color:var(--roxo)} .pv-venc .pv-dia{color:var(--vermelho)}
.pv-ano{font-size:10px;color:var(--text-muted)}
.pv-prazo{font-size:9.5px;margin-top:3px;white-space:nowrap;color:var(--text-muted)}
.pv-alerta .pv-prazo{color:var(--amarelo)} .pv-hoje .pv-prazo{color:var(--roxo)} .pv-venc .pv-prazo{color:var(--vermelho)}
.pv-corpo{flex:1;min-width:220px}
.pv-nome{font-size:14px;font-weight:500;color:var(--text-primary);margin-bottom:4px}
.pv-meta{display:flex;gap:13px;flex-wrap:wrap;font-size:11px;color:var(--text-muted);margin-bottom:3px}
.pv-meta span{display:inline-flex;align-items:center;gap:5px}
.pv-meta i{font-size:9.5px;opacity:.7}
.pv-troca{font-size:11px;color:var(--text-secondary);margin-top:6px;padding-top:6px;border-top:1px dashed var(--border)}
.pv-troca strong{color:var(--text-primary)}
.pv-acoes{display:flex;gap:6px;flex-wrap:wrap;align-items:flex-start}
.pv-acoes .btn{padding:7px 12px;font-size:11.5px}
/* Modal */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:900;align-items:center;justify-content:center;padding:20px}
.modal-bg.on{display:flex}
.modal{background:var(--bg-card);border:1px solid var(--border-hover);border-radius:var(--radius-lg);max-width:560px;width:100%;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.6)}
.modal-h{padding:17px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:11px;font-family:var(--font-display);font-size:15px;font-weight:700}
.modal-b{padding:20px 22px}
.modal-f{padding:15px 22px;border-top:1px solid var(--border);display:flex;gap:9px;justify-content:flex-end;flex-wrap:wrap;background:rgba(255,255,255,.02)}
.fg{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.flabel{font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted)}
.fi{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-family:var(--font-ui);font-size:13px;color:var(--text-primary);outline:none;width:100%}
.fi:focus{border-color:rgba(160,174,192,.45)}
textarea.fi{resize:vertical;min-height:76px;line-height:1.6}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.radio-lin{display:flex;gap:9px;flex-wrap:wrap}
.radio-op{display:flex;align-items:center;padding:9px 14px;border:1px solid var(--border);border-radius:8px;background:var(--bg-input);cursor:pointer;font-size:12.5px;color:var(--text-secondary);flex:1;min-width:150px}
.radio-op:has(input:checked){border-color:rgba(74,222,128,.45);background:rgba(74,222,128,.07);color:var(--verde);font-weight:600}
.radio-op input{margin-right:8px;accent-color:#4ade80}
/* Seções e tabela */
.sec{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);margin-bottom:16px;overflow:hidden}
.sec-h{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:11px;background:rgba(255,255,255,.02)}
.sec-t{font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--accent-steel);flex:1}
.sec-b{padding:18px 20px}
.tbl-wrap{overflow-x:auto}
.tbl{width:100%;border-collapse:collapse;font-size:12.5px;min-width:900px}
.tbl th{text-align:left;font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--accent-steel);padding:10px 13px;border-bottom:1px solid var(--border);white-space:nowrap}
.tbl td{padding:11px 13px;border-bottom:1px solid var(--border);color:var(--text-secondary);vertical-align:top}
.tbl tr:last-child td{border-bottom:none}
.tbl td.pri{color:var(--text-primary);font-weight:500}
.ac{font-size:9.5px;font-weight:700;padding:2px 9px;border-radius:20px;white-space:nowrap;text-transform:uppercase}
.ac-REALIZADA{background:rgba(74,222,128,.13);color:var(--verde);border:1px solid rgba(74,222,128,.3)}
.ac-ADIADA{background:rgba(250,204,21,.12);color:var(--amarelo);border:1px solid rgba(250,204,21,.3)}
.ac-REMOVIDA{background:rgba(248,113,113,.12);color:var(--vermelho);border:1px solid rgba(248,113,113,.3)}
.ac-AGENDADA{background:rgba(160,174,192,.12);color:var(--accent-steel);border:1px solid rgba(160,174,192,.28)}
.vazio{padding:42px 20px;text-align:center;color:var(--text-muted);font-size:13px}
.vazio i{display:block;font-size:28px;margin-bottom:11px;opacity:.3}
.aviso{border-radius:10px;padding:13px 17px;font-size:13px;display:flex;align-items:flex-start;gap:11px;margin-bottom:16px;line-height:1.6}
.aviso i{font-size:15px;flex-shrink:0;margin-top:1px}
.aviso-erro{background:rgba(248,113,113,.09);border:1px solid rgba(248,113,113,.28);color:#fca5a5}
.aviso-ok{background:rgba(74,222,128,.09);border:1px solid rgba(74,222,128,.28);color:#86efac}
.busca-lin{display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end}
.res-item{margin-top:14px;padding:14px 17px;border:1px solid rgba(74,222,128,.28);background:rgba(74,222,128,.05);border-radius:10px}
.footer{margin-left:var(--sidebar-w);padding:14px 28px;border-top:1px solid var(--border);display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);transition:margin-left var(--transition);flex-wrap:wrap;gap:8px}
@media(max-width:900px){.grid2{grid-template-columns:1fr}.content{padding:16px}.topbar-logo-rede{display:none}.footer,#main{margin-left:var(--sidebar-collapsed)}#sidebar{width:var(--sidebar-collapsed)}}
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
      <span>Manutenções Preventivas</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
    <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <div class="page-header">
      <div>
        <div class="page-title">Manutenções Preventivas</div>
        <div class="page-subtitle">Engenharia Clínica &middot; <?= eng_clin_data_pt() ?></div>
      </div>
      <a href="engenharia_clinica_inicial.php" class="btn btn-ghost">
        <i class="fas fa-arrow-left"></i> Página Inicial
      </a>
    </div>

    <?php if ($msg): ?>
    <div class="aviso <?= $msg_tipo==='ok' ? 'aviso-ok' : 'aviso-erro' ?>">
      <i class="fas <?= $msg_tipo==='ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
      <div><?= htmlspecialchars($msg) ?></div>
    </div>
    <?php endif; ?>

    <!-- ══ RESUMO ═══════════════════════════════════════════════════ -->
    <div class="resumo">
      <div class="rz"><div class="rz-l">Total agendadas</div><div class="rz-v"><?= count($agenda) ?></div></div>
      <div class="rz sem"><div class="rz-l">Para esta semana</div><div class="rz-v"><?= $n_semana ?></div></div>
      <div class="rz hoje"><div class="rz-l">Para hoje</div><div class="rz-v"><?= $n_hoje ?></div></div>
      <div class="rz venc"><div class="rz-l">Vencidas</div><div class="rz-v"><?= $n_venc ?></div></div>
    </div>

    <!-- ══ ABAS ═════════════════════════════════════════════════════ -->
    <div class="abas">
      <button class="aba on" data-aba="agenda" onclick="trocarAba(this)">
        <i class="fas fa-calendar-days"></i> Agenda <span class="aba-cnt"><?= count($agenda) ?></span>
      </button>
      <button class="aba" data-aba="historico" onclick="trocarAba(this)">
        <i class="fas fa-clock-rotate-left"></i> Histórico <span class="aba-cnt"><?= count($historico) ?></span>
      </button>
      <button class="aba" data-aba="novo" onclick="trocarAba(this)">
        <i class="fas fa-plus"></i> Incluir equipamento
      </button>
    </div>

    <!-- ══ ABA AGENDA ═══════════════════════════════════════════════ -->
    <div id="pane-agenda">
      <div class="guia">
        <div class="guia-i"><span class="guia-c" style="background:var(--border-hover)"></span> No prazo</div>
        <div class="guia-i"><span class="guia-c" style="background:var(--amarelo)"></span> Faltam 7 dias ou menos</div>
        <div class="guia-i"><span class="guia-c" style="background:var(--roxo)"></span> Vence hoje</div>
        <div class="guia-i"><span class="guia-c" style="background:var(--vermelho)"></span> Vencida</div>
      </div>

      <?php if (!$agenda): ?>
      <div class="sec"><div class="vazio">
        <i class="fas fa-calendar-days"></i>
        Nenhuma manutenção preventiva agendada.<br>
        <span style="font-size:12px">Use a aba “Incluir equipamento” ou informe a periodicidade ao encerrar uma OS.</span>
      </div></div>
      <?php else: foreach ($agenda as $a): ?>
      <div class="pv pv-<?= $a['sit'] ?>">
        <div class="pv-data">
          <div class="pv-dia"><?= date('d/m', strtotime($a['proxima_data'])) ?></div>
          <div class="pv-ano"><?= date('Y', strtotime($a['proxima_data'])) ?></div>
          <div class="pv-prazo">
            <?php if ($a['dias'] < 0): ?><?= abs($a['dias']) ?>d atrás
            <?php elseif ($a['dias'] === 0): ?>hoje
            <?php else: ?>em <?= $a['dias'] ?>d<?php endif; ?>
          </div>
        </div>
        <div class="pv-corpo">
          <div class="pv-nome"><?= htmlspecialchars($a['descricao'] ?: 'Equipamento não identificado') ?></div>
          <div class="pv-meta">
            <?php if ($a['marca'] || $a['modelo']): ?>
            <span><i class="fas fa-industry"></i><?= htmlspecialchars(($a['marca'] ?: '—').' / '.($a['modelo'] ?: '—')) ?></span>
            <?php endif; ?>
            <?php if ($a['serie']): ?><span><i class="fas fa-barcode"></i><?= htmlspecialchars($a['serie']) ?></span><?php endif; ?>
            <?php if ($a['tag_antiga']): ?><span><i class="fas fa-tag"></i>Tag 1: <?= htmlspecialchars($a['tag_antiga']) ?></span><?php endif; ?>
            <?php if ($a['tag_trocada']): ?><span><i class="fas fa-tag"></i>Tag 2: <?= htmlspecialchars($a['tag_trocada']) ?></span><?php endif; ?>
          </div>
          <div class="pv-meta">
            <span><i class="fas fa-hospital"></i><?= htmlspecialchars($a['loc_unidade'] ?: '—') ?></span>
            <span><i class="fas fa-location-dot"></i><?= htmlspecialchars($a['loc_setor'] ?: '—') ?><?= $a['loc_area'] ? ' / '.htmlspecialchars($a['loc_area']) : '' ?></span>
            <span><i class="fas fa-repeat"></i>a cada <?= (int)$a['periodicidade_meses'] ?> meses</span>
            <?php if ($a['numero_chamado']): ?>
            <span><i class="fas fa-file-lines"></i><a href="eng_clin_os.php?protocolo=<?= urlencode($a['numero_chamado']) ?>" style="color:var(--accent-steel);text-decoration:none"><?= htmlspecialchars($a['numero_chamado']) ?></a></span>
            <?php endif; ?>
          </div>
          <?php if (!empty($a['ultima_troca'])): ?>
          <div class="pv-troca">
            <i class="fas fa-screwdriver-wrench" style="color:var(--accent-steel);font-size:10px;margin-right:5px"></i>
            Última revisão: <strong><?= htmlspecialchars($a['ultima_troca']) ?></strong>
          </div>
          <?php endif; ?>
        </div>
        <div class="pv-acoes">
          <button class="btn btn-ok" onclick='abrirRealizada(<?= json_encode([
              "id"=>(int)$a["id"], "nome"=>$a["descricao"], "per"=>(int)$a["periodicidade_meses"]
          ], JSON_UNESCAPED_UNICODE) ?>)'>
            <i class="fas fa-check"></i> Realizada
          </button>
          <button class="btn btn-warn" onclick='abrirAdiar(<?= json_encode([
              "id"=>(int)$a["id"], "nome"=>$a["descricao"], "per"=>(int)$a["periodicidade_meses"],
              "data"=>$a["proxima_data"]
          ], JSON_UNESCAPED_UNICODE) ?>)'>
            <i class="fas fa-clock"></i> Adiar
          </button>
          <form method="POST" onsubmit="return confirm('Remover este equipamento da agenda de preventivas?')" style="display:inline">
            <input type="hidden" name="acao" value="remover">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button type="submit" class="btn btn-del" title="Remover da agenda"><i class="fas fa-trash"></i></button>
          </form>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- ══ ABA HISTÓRICO ════════════════════════════════════════════ -->
    <div id="pane-historico" style="display:none">
      <div class="sec">
        <div class="sec-h">
          <span class="sec-t">Histórico de Manutenções Preventivas</span>
        </div>
        <?php if (!$historico): ?>
        <div class="vazio"><i class="fas fa-clock-rotate-left"></i>Nenhum registro ainda.</div>
        <?php else: ?>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>Data / Hora</th><th>Ação</th><th>Equipamento</th>
                <th>Marca / Modelo</th><th>Série</th><th>Tag 1</th><th>Tag 2</th>
                <th>Local</th><th>Técnico</th><th>Troca / Observação</th><th>Próxima</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($historico as $h): ?>
              <tr>
                <td style="white-space:nowrap">
                  <?= $h['data_exec'] ? date('d/m/Y', strtotime($h['data_exec'])) : date('d/m/Y', strtotime($h['criado_em'])) ?>
                  <?= $h['hora_exec'] ? ' '.substr($h['hora_exec'],0,5) : '' ?>
                </td>
                <td><span class="ac ac-<?= htmlspecialchars($h['acao']) ?>"><?= htmlspecialchars($h['acao']) ?></span></td>
                <td class="pri"><?= htmlspecialchars($h['descricao'] ?: '—') ?></td>
                <td><?= htmlspecialchars(($h['marca'] ?: '—').' / '.($h['modelo'] ?: '—')) ?></td>
                <td><?= htmlspecialchars($h['serie'] ?: '—') ?></td>
                <td><?= htmlspecialchars($h['tag_antiga'] ?: '—') ?></td>
                <td><?= htmlspecialchars($h['tag_trocada'] ?: '—') ?></td>
                <td style="font-size:11.5px"><?= htmlspecialchars(($h['unidade'] ?: '—').' / '.($h['setor'] ?: '—')) ?></td>
                <td><?= htmlspecialchars($h['nome_tecnico'] ?: '—') ?></td>
                <td style="max-width:230px"><?= htmlspecialchars($h['servico_troca'] ?: '—') ?></td>
                <td style="white-space:nowrap"><?= $h['proxima_data'] ? date('d/m/Y', strtotime($h['proxima_data'])) : '—' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══ ABA INCLUIR ══════════════════════════════════════════════ -->
    <div id="pane-novo" style="display:none">
      <div class="sec">
        <div class="sec-h"><span class="sec-t">Incluir Equipamento na Agenda</span></div>
        <div class="sec-b">
          <div class="busca-lin">
            <div class="fg" style="flex:1;min-width:230px;margin-bottom:0">
              <label class="flabel">Tag ou número de série</label>
              <input type="text" id="inpBusca" class="fi" placeholder="Ex: HCSB 000129 ou 101307075"
                     style="text-transform:uppercase" onkeydown="if(event.key==='Enter'){event.preventDefault();buscarItem();}">
            </div>
            <button type="button" class="btn btn-ghost" onclick="buscarItem()">
              <i class="fas fa-magnifying-glass"></i> Buscar
            </button>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:9px">
            <i class="fas fa-circle-info"></i>
            A tag é procurada em <strong>Tag 1</strong> e <strong>Tag 2</strong>.
            Só aparecem equipamentos com responsável Engenharia Clínica.
          </div>

          <div id="resBusca"></div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ══ MODAL: REALIZADA ═══════════════════════════════════════════ -->
<div class="modal-bg" id="mdRealizada">
  <div class="modal">
    <form method="POST">
      <input type="hidden" name="acao" value="realizada">
      <input type="hidden" name="id" id="rzId">
      <div class="modal-h"><i class="fas fa-circle-check" style="color:var(--verde)"></i> Registrar manutenção realizada</div>
      <div class="modal-b">
        <div style="font-size:13px;color:var(--text-secondary);margin-bottom:16px" id="rzNome"></div>

        <div class="grid2">
          <div class="fg">
            <label class="flabel">Data da execução</label>
            <input type="date" name="data_exec" class="fi" value="<?= $hoje ?>" required>
          </div>
          <div class="fg">
            <label class="flabel">Hora</label>
            <input type="time" name="hora_exec" class="fi" step="1" value="<?= date('H:i:s') ?>">
          </div>
        </div>

        <div class="fg">
          <label class="flabel">Técnico que executou <span style="color:var(--vermelho)">*</span></label>
          <select name="tecnico" class="fi" required>
            <option value="">Selecione o técnico...</option>
            <?php foreach ($tecnicos as $t): ?>
            <option value="<?= htmlspecialchars($t['usuario']) ?>" <?= $t['usuario']===$usuario?'selected':'' ?>>
              <?= htmlspecialchars($t['nome']) ?><?= $t['funcao'] ? ' — '.htmlspecialchars($t['funcao']) : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="fg">
          <label class="flabel">O que foi trocado / feito</label>
          <textarea name="servico_troca" class="fi" placeholder="Ex: troca de filtro, bateria e calibração"></textarea>
        </div>

        <div class="fg">
          <label class="flabel">Periodicidade</label>
          <div class="radio-lin">
            <label class="radio-op">
              <input type="radio" name="manter_periodicidade" value="1" checked onchange="rzPer()">
              Manter <span id="rzPerAtual" style="margin-left:5px"></span>
            </label>
            <label class="radio-op">
              <input type="radio" name="manter_periodicidade" value="0" onchange="rzPer()">
              Alterar
            </label>
          </div>
        </div>
        <div class="fg" id="rzNovaWrap" style="display:none">
          <label class="flabel">Nova periodicidade (meses)</label>
          <input type="number" name="nova_periodicidade" class="fi" min="1" max="120" value="6" id="rzNova">
        </div>

        <div style="font-size:11px;color:var(--text-muted);line-height:1.6">
          <i class="fas fa-circle-info"></i> A próxima revisão é contada a partir da
          <strong>data de execução</strong> informada acima.
        </div>
      </div>
      <div class="modal-f">
        <button type="button" class="btn btn-ghost" onclick="fecharModais()">Cancelar</button>
        <button type="submit" class="btn btn-ok"><i class="fas fa-check"></i> Registrar</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL: ADIAR ═══════════════════════════════════════════════ -->
<div class="modal-bg" id="mdAdiar">
  <div class="modal">
    <form method="POST">
      <input type="hidden" name="acao" value="adiar">
      <input type="hidden" name="id" id="adId">
      <div class="modal-h"><i class="fas fa-clock" style="color:var(--amarelo)"></i> Adiar manutenção</div>
      <div class="modal-b">
        <div style="font-size:13px;color:var(--text-secondary);margin-bottom:16px" id="adNome"></div>

        <div class="fg">
          <label class="flabel">Intervalo do adiamento</label>
          <div class="radio-lin">
            <label class="radio-op">
              <input type="radio" name="manter_periodicidade" value="1" checked onchange="adPer()">
              Manter <span id="adPerAtual" style="margin-left:5px"></span>
            </label>
            <label class="radio-op">
              <input type="radio" name="manter_periodicidade" value="0" onchange="adPer()">
              Alterar
            </label>
          </div>
        </div>
        <div class="fg" id="adNovaWrap" style="display:none">
          <label class="flabel">Novo intervalo (meses)</label>
          <input type="number" name="nova_periodicidade" class="fi" min="1" max="120" value="6" id="adNova">
        </div>

        <div class="fg">
          <label class="flabel">Motivo do adiamento</label>
          <textarea name="motivo_adiar" class="fi" placeholder="Ex: equipamento em uso contínuo, sem janela para parada"></textarea>
        </div>

        <div style="font-size:11px;color:var(--text-muted);line-height:1.6">
          <i class="fas fa-circle-info"></i> O adiamento conta a partir da
          <strong id="adDataBase">data agendada</strong>, não de hoje — assim o
          atraso acumulado não desaparece do controle.
        </div>
      </div>
      <div class="modal-f">
        <button type="button" class="btn btn-ghost" onclick="fecharModais()">Cancelar</button>
        <button type="submit" class="btn btn-warn"><i class="fas fa-clock"></i> Adiar</button>
      </div>
    </form>
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

/* ── Abas ── */
function trocarAba(btn) {
  document.querySelectorAll('.aba').forEach(a => a.classList.remove('on'));
  btn.classList.add('on');
  ['agenda','historico','novo'].forEach(k => {
    document.getElementById('pane-' + k).style.display = (k === btn.dataset.aba) ? '' : 'none';
  });
  if (btn.dataset.aba === 'novo') document.getElementById('inpBusca')?.focus();
}

/* ── Modais ── */
function fecharModais() {
  document.querySelectorAll('.modal-bg').forEach(m => m.classList.remove('on'));
}
document.querySelectorAll('.modal-bg').forEach(m => {
  m.addEventListener('mousedown', e => { if (e.target === m) fecharModais(); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharModais(); });

function abrirRealizada(d) {
  document.getElementById('rzId').value = d.id;
  document.getElementById('rzNome').textContent = d.nome || 'Equipamento';
  document.getElementById('rzPerAtual').textContent = '(' + d.per + ' meses)';
  document.getElementById('rzNova').value = d.per;
  document.querySelector('#mdRealizada [name=manter_periodicidade][value="1"]').checked = true;
  rzPer();
  document.getElementById('mdRealizada').classList.add('on');
}
function rzPer() {
  const manter = document.querySelector('#mdRealizada [name=manter_periodicidade]:checked').value === '1';
  document.getElementById('rzNovaWrap').style.display = manter ? 'none' : '';
}

function abrirAdiar(d) {
  document.getElementById('adId').value = d.id;
  document.getElementById('adNome').textContent = d.nome || 'Equipamento';
  document.getElementById('adPerAtual').textContent = '(' + d.per + ' meses)';
  document.getElementById('adNova').value = d.per;
  if (d.data) {
    const p = d.data.split('-');
    document.getElementById('adDataBase').textContent = p[2] + '/' + p[1] + '/' + p[0];
  }
  document.querySelector('#mdAdiar [name=manter_periodicidade][value="1"]').checked = true;
  adPer();
  document.getElementById('mdAdiar').classList.add('on');
}
function adPer() {
  const manter = document.querySelector('#mdAdiar [name=manter_periodicidade]:checked').value === '1';
  document.getElementById('adNovaWrap').style.display = manter ? 'none' : '';
}

/* ── Busca de equipamento para incluir ── */
function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function buscarItem() {
  const q = document.getElementById('inpBusca').value.trim();
  const box = document.getElementById('resBusca');
  if (!q) { box.innerHTML = ''; return; }
  box.innerHTML = '<div style="margin-top:14px;color:var(--text-muted);font-size:12.5px"><i class="fas fa-circle-notch fa-spin"></i> Buscando...</div>';

  try {
    const r = await fetch('?acao=buscar_item&q=' + encodeURIComponent(q), {credentials:'same-origin'});
    const d = await r.json();
    if (!d.ok) {
      box.innerHTML = '<div class="aviso aviso-erro" style="margin-top:14px"><i class="fas fa-circle-exclamation"></i><div>' + esc(d.msg) + '</div></div>';
      return;
    }
    const it = d.item;
    const mov = String(it.movimentado || '').toUpperCase() === 'SIM';
    const uni = mov ? (it.unidade_destino || it.unidade) : it.unidade;
    const set = mov ? (it.setor_destino   || it.setor)   : it.setor;

    box.innerHTML =
      '<div class="res-item">' +
        '<div style="font-size:14px;font-weight:500;color:var(--text-primary);margin-bottom:6px">' + esc(it.descricao) + '</div>' +
        '<div class="pv-meta">' +
          '<span><i class="fas fa-industry"></i>' + esc(it.marca || '—') + ' / ' + esc(it.modelo || '—') + '</span>' +
          '<span><i class="fas fa-barcode"></i>' + esc(it.serie || '—') + '</span>' +
          '<span><i class="fas fa-tag"></i>Tag 1: ' + esc(it.tag_antiga || '—') + '</span>' +
          '<span><i class="fas fa-tag"></i>Tag 2: ' + esc(it.tag_trocada || '—') + '</span>' +
        '</div>' +
        '<div class="pv-meta"><span><i class="fas fa-hospital"></i>' + esc(uni || '—') + '</span>' +
        '<span><i class="fas fa-location-dot"></i>' + esc(set || '—') + '</span></div>' +
        (it.ja_agendado === 1
          ? '<div style="margin-top:12px;font-size:12.5px;color:var(--amarelo)"><i class="fas fa-triangle-exclamation"></i> Este equipamento já está na agenda. Salvar novamente atualiza a periodicidade e a data.</div>'
          : '') +
        '<form method="POST" style="margin-top:14px">' +
          '<input type="hidden" name="acao" value="add">' +
          '<input type="hidden" name="item_id" value="' + it.id + '">' +
          '<div class="grid2">' +
            '<div class="fg"><label class="flabel">Periodicidade (meses)</label>' +
              '<input type="number" name="periodicidade" class="fi" min="1" max="120" value="6" required></div>' +
            '<div class="fg"><label class="flabel">Primeira revisão</label>' +
              '<input type="date" name="primeira_data" class="fi" value="' + proximaData(6) + '" id="inpPrimeira"></div>' +
          '</div>' +
          '<button type="submit" class="btn btn-ok"><i class="fas fa-plus"></i> Incluir na agenda</button>' +
        '</form>' +
      '</div>';

    // Ao mudar a periodicidade, sugere a data correspondente
    const inpPer = box.querySelector('[name=periodicidade]');
    inpPer.addEventListener('input', () => {
      const m = parseInt(inpPer.value || '0', 10);
      if (m > 0 && m <= 120) document.getElementById('inpPrimeira').value = proximaData(m);
    });
  } catch (e) {
    box.innerHTML = '<div class="aviso aviso-erro" style="margin-top:14px"><i class="fas fa-circle-exclamation"></i><div>Falha na busca.</div></div>';
  }
}

function proximaData(meses) {
  const d = new Date();
  d.setMonth(d.getMonth() + meses);
  return d.toISOString().slice(0, 10);
}

/* Abre direto numa aba via ?aba=historico */
(function(){
  const p = new URLSearchParams(location.search).get('aba');
  if (p) {
    const b = document.querySelector('.aba[data-aba="' + p + '"]');
    if (b) trocarAba(b);
  }
})();

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
