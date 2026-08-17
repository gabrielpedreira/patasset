<?php
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = 'os';   // item ativo no menu lateral
// Desativar exceções automáticas do MySQLi — erros tratados manualmente
mysqli_report(MYSQLI_REPORT_OFF);

include 'conexao.php';

if (!isset($_SESSION['usuario_logado'])) {
    header("Location: index.html");
    exit();
}

$usuario = $_SESSION['usuario_logado'];

// Buscar dados do usuário com tratamento robusto
$nome_usuario   = $usuario;
$nivel          = 'C';
$classe_usuario = '';
$status_u       = 'ATIVO';

try {
    // Tenta buscar com campo 'nome'
    $stmt = $conn->prepare("SELECT permicao, classe_usuario, status, nome FROM usuarios WHERE usuario=?");
    if (!$stmt) {
        // 'nome' pode não existir — tenta sem ele
        $stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
    }
    if ($stmt) {
        $stmt->bind_param("s", $usuario);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($r = $res->fetch_assoc()) {
            $nivel          = strtoupper(trim($r['permicao']        ?? 'C'));
            $classe_usuario = strtoupper(trim($r['classe_usuario']  ?? ''));
            $status_u       = $r['status'] ?? 'ATIVO';
            $nome_usuario   = $r['nome']   ?? $usuario;
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    // Falha crítica de banco — redireciona ao login
    session_destroy();
    header("Location: index.html?erro=db");
    exit();
}

if ($status_u !== 'ATIVO') { session_destroy(); header("Location: index.html?erro=bloqueado"); exit(); }
if (!in_array($classe_usuario, ['DEV','ENGENHARIA CLINICA']) || !in_array($nivel, ['A','B','C'])) {
    header("Location: acesso_bloqueado.html"); exit();
}

date_default_timezone_set('America/Sao_Paulo');
$data_hoje = date('Y-m-d');
$hora_agora = date('H:i:s');

$msg = ''; $msg_tipo = '';

// ── Helpers ──────────────────────────────────────────────────────────
function registrar_evento($conn, $num_ch, $num_os, $usuario, $nome, $tipo, $desc) {
    $d = date('Y-m-d'); $h = date('H:i:s');
    $st = $conn->prepare("INSERT INTO historico_eventos_engclin (numero_chamado,numero_os,usuario,nome_usuario,tipo_evento,descricao_evento,data_evento,hora_evento) VALUES (?,?,?,?,?,?,?,?)");
    if ($st) { $st->bind_param('ssssssss',$num_ch,$num_os,$usuario,$nome,$tipo,$desc,$d,$h); $st->execute(); $st->close(); }
}

// ── POST: Abrir OS ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'abrir_os') {
    $num_ch = trim($_POST['numero_chamado'] ?? '');
    if ($num_ch) {
        try {
            $stC = $conn->prepare("SELECT numero_chamado, item_id, unidade_ocorrencia, setor_ocorrencia, area_ocorrencia FROM chamado_engclin WHERE numero_chamado=? LIMIT 1");
            if (!$stC) throw new Exception("Prepare falhou: ".$conn->error);
            $stC->bind_param('s',$num_ch); $stC->execute();
            $rowC = $stC->get_result()->fetch_assoc(); $stC->close();

            if ($rowC) {
                // ── PROTOCOLO ÚNICO ──────────────────────────────────────────
                // A OS herda o número do chamado. O mesmo protocolo acompanha
                // chamado, OS, materiais, manutenção externa e histórico.
                $num_os = $num_ch;

                $item_id_os = $rowC['item_id'] ? (int)$rowC['item_id'] : null;

                // ── LOCAL DE DEVOLUÇÃO (congelado agora) ─────────────────────
                // Precisa ser capturado ANTES da movimentação: logo abaixo o
                // helper grava movimentado='SIM' e sobrescreve os campos de
                // destino com a sala de manutenção, destruindo a referência.
                //
                // CRITÉRIO (definido pelo Gabriel):
                //   movimentado = 'SIM'  → volta para unidade_destino / setor_destino / area_destino
                //   caso contrário       → volta para unidade / setor / area
                //
                // A localização informada no chamado NÃO é usada aqui: ela diz
                // onde o problema foi relatado, não onde o item está lotado.
                $lo_uni = ''; $lo_set = ''; $lo_are = ''; $lo_pav = '';
                if ($item_id_os) {
                    $stLoc = $conn->prepare("SELECT unidade, setor, area, pavimento, unidade_destino, setor_destino, area_destino, movimentado FROM cadastro WHERE id=? LIMIT 1");
                    if ($stLoc) {
                        $stLoc->bind_param('i', $item_id_os); $stLoc->execute();
                        $resLoc = $stLoc->get_result();
                        $rowLoc = $resLoc ? $resLoc->fetch_assoc() : null;
                        $stLoc->close();
                        if ($rowLoc) {
                            $ja_movimentado = strtoupper(trim((string)($rowLoc['movimentado'] ?? ''))) === 'SIM';
                            if ($ja_movimentado) {
                                $lo_uni = (string)($rowLoc['unidade_destino'] ?? '');
                                $lo_set = (string)($rowLoc['setor_destino']   ?? '');
                                $lo_are = (string)($rowLoc['area_destino']    ?? '');
                            } else {
                                $lo_uni = (string)($rowLoc['unidade'] ?? '');
                                $lo_set = (string)($rowLoc['setor']   ?? '');
                                $lo_are = (string)($rowLoc['area']    ?? '');
                            }
                            $lo_pav = (string)($rowLoc['pavimento'] ?? '');
                        }
                    }
                }

                $st = $conn->prepare("
                    INSERT INTO ordemservico_engclin
                        (numero_os, numero_chamado, usuario, nome_tecnico,
                         data_abertura, hora_abertura, data_inicio, hora_inicio,
                         loc_orig_unidade, loc_orig_setor, loc_orig_area, loc_orig_pav,
                         etapas_salvas, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'inicio','ABERTA')
                ");
                if (!$st) throw new Exception("Prepare OS falhou: ".$conn->error);
                $st->bind_param('ssssssssssss',
                    $num_os, $num_ch, $usuario, $nome_usuario,
                    $data_hoje, $hora_agora, $data_hoje, $hora_agora,
                    $lo_uni, $lo_set, $lo_are, $lo_pav
                );
                if ($st->execute()) {
                    // Propagar item_id do chamado para a OS (se existir)
                    if ($item_id_os) {
                        $stIid = $conn->prepare("UPDATE ordemservico_engclin SET item_id=? WHERE numero_os=?");
                        if ($stIid) { $stIid->bind_param('is',$item_id_os,$num_os); $stIid->execute(); $stIid->close(); }
                    }
                    // Primeira intervenção: o técnico que iniciou a OS.
                    // Outros técnicos adicionam as suas na tela de atendimento.
                    $stMo1 = $conn->prepare("INSERT INTO maodeobra_engclin (numero_chamado,usuario,nome_tecnico,data_inicio,hora_inicio) VALUES (?,?,?,?,?)");
                    if ($stMo1) {
                        $stMo1->bind_param('sssss', $num_ch, $usuario, $nome_usuario, $data_hoje, $hora_agora);
                        $stMo1->execute(); $stMo1->close();
                    }

                    // EM_ATENDIMENTO = OS iniciada e não finalizada = PENDÊNCIA
                    $conn->query("UPDATE chamado_engclin SET status='EM_ATENDIMENTO' WHERE numero_chamado='".mysqli_real_escape_string($conn,$num_ch)."'");
                    registrar_evento($conn,$num_ch,$num_os,$usuario,$nome_usuario,'ABERTURA_OS',
                        "OS iniciada pelo técnico {$nome_usuario} — protocolo {$num_os}. " .
                        "Local de devolução registrado: " . ($lo_uni ?: '—') . " / " . ($lo_set ?: '—') . ($lo_are ? " / {$lo_are}" : ""));

                    // ── Movimentação patrimonial automática ──────────────────
                    if ($item_id_os) {
                        try {
                            // Movimentação centralizada em eng_clin_mover_item.php:
                            // atualiza cadastro + grava em `historico` + envia e-mail.
                            require_once __DIR__ . '/eng_clin_mover_item.php';
                            $destino = eng_clin_destino_manutencao();
                            $destino['obs']     = "OS {$num_os} iniciada — equipamento recolhido para manutenção";
                            $destino['usuario'] = $usuario;
                            $destino['data']    = $data_hoje;

                            $mv = eng_clin_mover_item($conn, $item_id_os, $destino);

                            if ($mv['ok']) {
                                $conn->query("UPDATE ordemservico_engclin SET movimentacao_patrimonio=1 WHERE numero_os='".mysqli_real_escape_string($conn,$num_os)."'");
                                registrar_evento($conn,$num_ch,$num_os,$usuario,$nome_usuario,'MOV_PATRIMONIO',
                                    "Equipamento #{$item_id_os} ({$mv['tag']}) movido de {$mv['de']} para {$mv['para']}. Motivo: INÍCIO DA OS.");
                            } else {
                                registrar_evento($conn,$num_ch,$num_os,$usuario,$nome_usuario,'MOV_PATRIMONIO_ERRO',
                                    "Falha na movimentação automática: ".$mv['erro']);
                            }
                        } catch (Throwable $eMov) {
                            // Falha na movimentação não impede criação da OS — apenas registra
                            registrar_evento($conn,$num_ch,$num_os,$usuario,$nome_usuario,'MOV_PATRIMONIO_ERRO',"Falha ao registrar movimentação patrimonial automática: ".$eMov->getMessage());
                        }
                    }

                    // Post-Redirect-Get: sem o redirect, o botão "voltar" do
                    // navegador tenta reenviar o POST e o Chrome mostra
                    // "Confirmar nova submissão de formulário" (ERR_CACHE_MISS).
                    $st->close();
                    header("Location: eng_clin_os.php?protocolo=" . urlencode($num_os));
                    exit();
                } else {
                    $msg = 'Erro ao criar OS: '.$st->error; $msg_tipo = 'erro';
                }
                $st->close();
            } else {
                $msg = 'Chamado não encontrado.'; $msg_tipo = 'erro';
            }
        } catch (Throwable $e) {
            $msg = 'Erro ao abrir OS: '.$e->getMessage(); $msg_tipo = 'erro';
        }
    }
}
/* ═══════════════════════════════════════════════════════════════════════════
   ESTA PÁGINA É SÓ A LISTA.
   O atendimento (preenchimento, materiais, manutenção externa e encerramento)
   vive em eng_clin_os.php. Manter os dois caminhos gerava regras divergentes.
   ═══════════════════════════════════════════════════════════════════════════ */

$aba   = $_GET['aba']   ?? 'abertos';
if (!in_array($aba, ['abertos','pendentes','historico'], true)) $aba = 'abertos';
$busca = trim($_GET['q'] ?? '');

if (isset($_GET['m'])) { $msg = $_GET['m']; $msg_tipo = ($_GET['t'] ?? '') === 'ok' ? 'ok' : 'erro'; }

/* ── Contadores das abas ───────────────────────────────────────────────── */
$cnt = ['abertos'=>0, 'pendentes'=>0, 'historico'=>0];
$r = $conn->query("SELECT COUNT(*) c FROM chamado_engclin WHERE status='ABERTO'");
if ($r) $cnt['abertos'] = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c FROM ordemservico_engclin WHERE status='ABERTA'");
if ($r) $cnt['pendentes'] = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c FROM ordemservico_engclin WHERE status <> 'ABERTA'");
if ($r) $cnt['historico'] = (int)$r->fetch_assoc()['c'];

/* ── Consulta da aba ativa ─────────────────────────────────────────────── */
$linhas = [];
$like   = '%' . $busca . '%';

if ($aba === 'abertos') {
    // Chamados aguardando técnico — ainda não viraram OS
    $sql = "SELECT c.numero_chamado, c.descricao_item, c.tag_patrimonio, c.nome,
                   c.unidade_ocorrencia, c.setor_ocorrencia, c.area_ocorrencia,
                   c.criticidade, c.data_chamado, c.hora_chamado, c.causa
            FROM chamado_engclin c
            WHERE c.status='ABERTO'";
    if ($busca !== '') $sql .= " AND (c.numero_chamado LIKE ? OR c.descricao_item LIKE ? OR c.tag_patrimonio LIKE ? OR c.unidade_ocorrencia LIKE ? OR c.nome LIKE ?)";
    $sql .= " ORDER BY FIELD(c.criticidade,'ALTA','MEDIA','BAIXA'), c.data_chamado ASC, c.hora_chamado ASC";
    $st = $conn->prepare($sql);
    if ($st) {
        if ($busca !== '') $st->bind_param('sssss', $like, $like, $like, $like, $like);
        $st->execute(); $rs = $st->get_result();
        if ($rs) while ($x = $rs->fetch_assoc()) $linhas[] = $x;
        $st->close();
    }
} else {
    // Pendentes = OS ABERTA | Histórico = OS encerrada
    $filtro_st = ($aba === 'pendentes') ? "o.status = 'ABERTA'" : "o.status <> 'ABERTA'";
    $sql = "SELECT o.numero_chamado, o.status AS st_os, o.motivo, o.manutencao_externa,
                   o.data_inicio, o.hora_inicio, o.data_fechamento, o.hora_fechamento,
                   o.item_devolvido,
                   c.descricao_item, c.tag_patrimonio, c.nome, c.criticidade,
                   c.unidade_ocorrencia, c.setor_ocorrencia, c.area_ocorrencia,
                   c.data_chamado, c.hora_chamado,
                   (SELECT COUNT(*) FROM maodeobra_engclin m WHERE m.numero_chamado=o.numero_chamado) AS qt_tec,
                   (SELECT COUNT(*) FROM itens_os_engclin i WHERE i.numero_chamado=o.numero_chamado) AS qt_mat,
                   (SELECT GROUP_CONCAT(DISTINCT m2.nome_tecnico ORDER BY m2.id SEPARATOR ', ')
                      FROM maodeobra_engclin m2 WHERE m2.numero_chamado=o.numero_chamado) AS tecnicos
            FROM ordemservico_engclin o
            LEFT JOIN chamado_engclin c ON c.numero_chamado = o.numero_chamado
            WHERE $filtro_st";
    if ($busca !== '') $sql .= " AND (o.numero_chamado LIKE ? OR c.descricao_item LIKE ? OR c.tag_patrimonio LIKE ? OR c.unidade_ocorrencia LIKE ? OR c.nome LIKE ?)";
    $sql .= ($aba === 'pendentes')
        ? " ORDER BY FIELD(c.criticidade,'ALTA','MEDIA','BAIXA'), o.data_inicio ASC"
        : " ORDER BY o.data_fechamento DESC, o.hora_fechamento DESC";
    $sql .= " LIMIT 300";
    $st = $conn->prepare($sql);
    if ($st) {
        if ($busca !== '') $st->bind_param('sssss', $like, $like, $like, $like, $like);
        $st->execute(); $rs = $st->get_result();
        if ($rs) while ($x = $rs->fetch_assoc()) $linhas[] = $x;
        $st->close();
    }
}

$MOTIVOS_LBL = [
    'PROBLEMA_SOLUCIONADO'  => 'Problema solucionado',
    'FALTA_DE_PECAS'        => 'Aguardando peças',
    'AGUARDANDO_ORCAMENTO'  => 'Aguardando orçamento',
    'MANUTENCAO_TERCEIROS'  => 'Manutenção externa',
    'AGUARDANDO_PATRIMONIO' => 'Aguardando patrimônio',
    'OBSOLESCENCIA'         => 'Obsoleto',
    'ITEM_ALUGADO'          => 'Item alugado',
    'OUTROS'                => 'Outros',
];

$conn->close();
$data = date('d/m/Y'); $hora = date('H:i:s');

function crit_cls($p) { return match($p) {'ALTA'=>'crit-alta','MEDIA'=>'crit-media','BAIXA'=>'crit-baixa',default=>'crit-media'}; }
function crit_lbl($p) { return match($p) {'ALTA'=>'Alta','MEDIA'=>'Média','BAIXA'=>'Baixa',default=>'—'}; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ordens de Serviço — Engenharia Clínica</title>
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
.brand-logo-main{width:56%;max-width:140px;height:auto;object-fit:contain;display:block;transition:opacity var(--transition),width var(--transition)}
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
.content{flex:1;padding:24px 28px;max-width:1300px;width:100%}
.page-header{margin-bottom:18px}
.page-title{font-family:var(--font-display);font-size:22px;font-weight:700;letter-spacing:-.01em}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:8px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none}
.btn-ghost{background:#232323;border:1px solid rgba(255,255,255,.12);color:var(--text-primary)}
.btn-ghost:hover{background:#2e2e2e}
.btn-ok{background:rgba(74,222,128,.14);border:1px solid rgba(74,222,128,.4);color:#4ade80;font-weight:600}
.btn-ok:hover{background:rgba(74,222,128,.22)}
/* ── Abas ── */
.abas{display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:18px;flex-wrap:wrap}
.aba{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;font-size:13px;color:var(--text-secondary);text-decoration:none;border-bottom:2px solid transparent;transition:all var(--transition)}
.aba:hover{color:var(--text-primary)}
.aba.on{color:var(--text-primary);border-bottom-color:#4ade80;font-weight:600}
.aba-cnt{background:rgba(255,255,255,.07);border:1px solid var(--border);border-radius:20px;padding:1px 8px;font-size:10.5px;font-weight:700}
.aba.on .aba-cnt{background:rgba(74,222,128,.14);border-color:rgba(74,222,128,.3);color:#4ade80}
/* ── Busca ── */
.busca{display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
.busca input{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:9px 13px;font-family:var(--font-ui);font-size:13px;color:var(--text-primary);outline:none;min-width:260px;flex:1;max-width:420px}
.busca input:focus{border-color:rgba(160,174,192,.45)}
/* ── Cards de chamado ── */
.ch{display:block;text-decoration:none;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:14px 17px;margin-bottom:10px;transition:border-color var(--transition),background var(--transition)}
.ch:hover{border-color:var(--border-hover);background:#1f1f1f}
.ch-top{display:flex;align-items:center;gap:11px;flex-wrap:wrap;margin-bottom:7px}
.ch-num{font-family:var(--font-display);font-size:14px;font-weight:700;color:#4ade80;letter-spacing:.02em}
.ch-item{font-size:14px;color:var(--text-primary);font-weight:500;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ch-meta{display:flex;gap:14px;flex-wrap:wrap;font-size:11.5px;color:var(--text-muted)}
.ch-meta span{display:inline-flex;align-items:center;gap:5px}
.ch-meta i{font-size:10px;opacity:.7}
.crit-badge{font-size:9.5px;font-weight:700;padding:2px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:.04em}
.crit-alta{background:rgba(248,113,113,.14);color:#f87171;border:1px solid rgba(248,113,113,.3)}
.crit-media{background:rgba(250,204,21,.12);color:#facc15;border:1px solid rgba(250,204,21,.3)}
.crit-baixa{background:rgba(160,174,192,.12);color:var(--accent-steel);border:1px solid rgba(160,174,192,.25)}
.tag-mini{font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;background:rgba(255,255,255,.05);color:var(--text-secondary);border:1px solid var(--border)}
.tag-ext{background:rgba(96,165,250,.12);color:#60a5fa;border-color:rgba(96,165,250,.28)}
.tag-ok{background:rgba(74,222,128,.12);color:#4ade80;border-color:rgba(74,222,128,.28)}
.tag-warn{background:rgba(250,204,21,.12);color:#facc15;border-color:rgba(250,204,21,.28)}
.vazio{padding:44px 20px;text-align:center;color:var(--text-muted);font-size:13px;background:var(--bg-card);border:1px solid var(--border);border-radius:12px}
.vazio i{display:block;font-size:30px;margin-bottom:11px;opacity:.3}
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
      <span>Ordens de Serviço</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
    <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <div class="page-header">
      <div class="page-title">Ordens de Serviço</div>
      <div class="page-subtitle">Engenharia Clínica &middot; <?= eng_clin_data_pt() ?></div>
    </div>

    <?php if ($msg): ?>
    <div class="aviso <?= $msg_tipo==='ok' ? 'aviso-ok' : 'aviso-erro' ?>">
      <i class="fas <?= $msg_tipo==='ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
      <div><?= htmlspecialchars($msg) ?></div>
    </div>
    <?php endif; ?>

    <!-- ══ ABAS ══════════════════════════════════════════════════════ -->
    <div class="abas">
      <a href="?aba=abertos<?= $busca ? '&q='.urlencode($busca) : '' ?>" class="aba <?= $aba==='abertos'?'on':'' ?>">
        <i class="fas fa-inbox"></i> Chamados Abertos
        <span class="aba-cnt"><?= $cnt['abertos'] ?></span>
      </a>
      <a href="?aba=pendentes<?= $busca ? '&q='.urlencode($busca) : '' ?>" class="aba <?= $aba==='pendentes'?'on':'' ?>">
        <i class="fas fa-screwdriver-wrench"></i> Pendências
        <span class="aba-cnt"><?= $cnt['pendentes'] ?></span>
      </a>
      <a href="?aba=historico<?= $busca ? '&q='.urlencode($busca) : '' ?>" class="aba <?= $aba==='historico'?'on':'' ?>">
        <i class="fas fa-clock-rotate-left"></i> Histórico
        <span class="aba-cnt"><?= $cnt['historico'] ?></span>
      </a>
    </div>

    <!-- ══ BUSCA ═════════════════════════════════════════════════════ -->
    <form method="GET" class="busca">
      <input type="hidden" name="aba" value="<?= htmlspecialchars($aba) ?>">
      <input type="text" name="q" value="<?= htmlspecialchars($busca) ?>"
             placeholder="Protocolo, item, tag, unidade ou quem abriu...">
      <button type="submit" class="btn btn-ghost"><i class="fas fa-search"></i> Buscar</button>
      <?php if ($busca !== ''): ?>
      <a href="?aba=<?= urlencode($aba) ?>" class="btn btn-ghost"><i class="fas fa-xmark"></i> Limpar</a>
      <?php endif; ?>
    </form>

    <!-- ══ LISTA ═════════════════════════════════════════════════════ -->
    <?php if (!$linhas): ?>
      <div class="vazio">
        <i class="fas <?= $aba==='abertos'?'fa-inbox':($aba==='pendentes'?'fa-screwdriver-wrench':'fa-clock-rotate-left') ?>"></i>
        <?php if ($busca !== ''): ?>
          Nenhum resultado para “<?= htmlspecialchars($busca) ?>”.
        <?php elseif ($aba==='abertos'): ?>
          Nenhum chamado aguardando atendimento.
        <?php elseif ($aba==='pendentes'): ?>
          Nenhuma OS em andamento.
        <?php else: ?>
          Nenhuma OS encerrada ainda.
        <?php endif; ?>
      </div>

    <?php elseif ($aba === 'abertos'): ?>
      <?php foreach ($linhas as $x): ?>
      <div class="ch">
        <div class="ch-top">
          <span class="ch-num"><?= htmlspecialchars($x['numero_chamado']) ?></span>
          <span class="ch-item"><?= htmlspecialchars($x['descricao_item'] ?: 'Item não especificado') ?></span>
          <span class="crit-badge <?= crit_cls($x['criticidade']) ?>"><?= crit_lbl($x['criticidade']) ?></span>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="abrir_os">
            <input type="hidden" name="numero_chamado" value="<?= htmlspecialchars($x['numero_chamado']) ?>">
            <button type="submit" class="btn btn-ok"><i class="fas fa-play"></i> Iniciar Atendimento</button>
          </form>
        </div>
        <div class="ch-meta">
          <?php if ($x['tag_patrimonio']): ?><span><i class="fas fa-tag"></i><?= htmlspecialchars($x['tag_patrimonio']) ?></span><?php endif; ?>
          <span><i class="fas fa-hospital"></i><?= htmlspecialchars($x['unidade_ocorrencia']) ?></span>
          <span><i class="fas fa-location-dot"></i><?= htmlspecialchars($x['setor_ocorrencia']) ?><?= $x['area_ocorrencia'] ? ' / '.htmlspecialchars($x['area_ocorrencia']) : '' ?></span>
          <span><i class="fas fa-user"></i><?= htmlspecialchars($x['nome']) ?></span>
          <span><i class="fas fa-calendar"></i><?= $x['data_chamado'] ? date('d/m/Y', strtotime($x['data_chamado'])) : '—' ?> <?= substr((string)$x['hora_chamado'],0,5) ?></span>
        </div>
        <?php if (!empty($x['causa'])): ?>
        <div style="font-size:12px;color:var(--text-secondary);margin-top:7px;line-height:1.5">
          <i class="fas fa-comment" style="font-size:10px;opacity:.6;margin-right:5px"></i><?= htmlspecialchars($x['causa']) ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

    <?php else: ?>
      <?php foreach ($linhas as $x): $fim = ($aba === 'historico'); ?>
      <a href="eng_clin_os.php?protocolo=<?= urlencode($x['numero_chamado']) ?>" class="ch">
        <div class="ch-top">
          <span class="ch-num"><?= htmlspecialchars($x['numero_chamado']) ?></span>
          <span class="ch-item"><?= htmlspecialchars($x['descricao_item'] ?: 'Item não especificado') ?></span>
          <?php if (!$fim): ?>
            <span class="crit-badge <?= crit_cls($x['criticidade']) ?>"><?= crit_lbl($x['criticidade']) ?></span>
          <?php endif; ?>
          <?php if ($x['manutencao_externa'] === 'SIM'): ?>
            <span class="tag-mini tag-ext"><i class="fas fa-truck"></i> Externa</span>
          <?php endif; ?>
          <?php if ($fim && $x['motivo']): ?>
            <span class="tag-mini <?= $x['motivo']==='PROBLEMA_SOLUCIONADO' ? 'tag-ok' : 'tag-warn' ?>">
              <?= htmlspecialchars($MOTIVOS_LBL[$x['motivo']] ?? $x['motivo']) ?>
            </span>
          <?php endif; ?>
          <i class="fas fa-chevron-right" style="color:var(--text-muted);font-size:11px"></i>
        </div>
        <div class="ch-meta">
          <?php if ($x['tag_patrimonio']): ?><span><i class="fas fa-tag"></i><?= htmlspecialchars($x['tag_patrimonio']) ?></span><?php endif; ?>
          <span><i class="fas fa-hospital"></i><?= htmlspecialchars($x['unidade_ocorrencia'] ?: '—') ?></span>
          <span><i class="fas fa-location-dot"></i><?= htmlspecialchars($x['setor_ocorrencia'] ?: '—') ?></span>
          <span><i class="fas fa-user-tie"></i><?= htmlspecialchars($x['nome'] ?: '—') ?></span>
          <?php if (!empty($x['tecnicos'])): ?>
          <span><i class="fas fa-user-gear"></i><?= htmlspecialchars($x['tecnicos']) ?></span>
          <?php endif; ?>
          <?php if ((int)$x['qt_mat'] > 0): ?>
          <span><i class="fas fa-boxes-stacked"></i><?= (int)$x['qt_mat'] ?> material(is)</span>
          <?php endif; ?>
          <?php if ($fim): ?>
            <span><i class="fas fa-flag-checkered"></i><?= $x['data_fechamento'] ? date('d/m/Y', strtotime($x['data_fechamento'])) : '—' ?> <?= substr((string)$x['hora_fechamento'],0,5) ?></span>
            <?php if ($x['item_devolvido'] === 'NAO'): ?>
            <span style="color:var(--status-warn)"><i class="fas fa-triangle-exclamation"></i>item não devolvido</span>
            <?php endif; ?>
          <?php else: ?>
            <span><i class="fas fa-play"></i>desde <?= $x['data_inicio'] ? date('d/m/Y', strtotime($x['data_inicio'])) : '—' ?> <?= substr((string)$x['hora_inicio'],0,5) ?></span>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    <?php endif; ?>

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
