<?php
// ── Sem autenticação — acesso público via QR Code ────────────────────────────
session_start();
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

include 'conexao.php';

date_default_timezone_set('America/Sao_Paulo');
$data_atual = date('d/m/Y');
$hora_atual = date('H:i');

$ip_rede = trim(explode(',',
    $_SERVER['HTTP_X_FORWARDED_FOR'] ??
    $_SERVER['HTTP_X_REAL_IP']       ??
    $_SERVER['REMOTE_ADDR']          ?? ''
)[0]);

// ── AJAX: busca de item por tag ou série ──────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'buscar_item') {
    header('Content-Type: application/json; charset=utf-8');
    $busca = trim($_GET['q'] ?? '');
    $campo = trim($_GET['campo'] ?? 'tag'); // 'tag' ou 'serie'

    if ($busca === '') {
        echo json_encode(['encontrado' => false]);
        exit;
    }

    if ($campo === 'serie') {
        $stmt = $conn->prepare("
            SELECT id, descricao, descricao_detalhada, marca, modelo, serie,
                   tag_antiga, tag_trocada, tag_alugado
            FROM cadastro
            WHERE UPPER(TRIM(serie)) = ?
              AND UPPER(TRIM(COALESCE(responsavel,''))) = 'ENGENHARIA CLINICA'
            LIMIT 1
        ");
        $busca_upper = strtoupper($busca);
        $stmt->bind_param('s', $busca_upper);
    } else {
        // busca por tag em tag_antiga, tag_trocada e tag_alugado
        $stmt = $conn->prepare("
            SELECT id, descricao, descricao_detalhada, marca, modelo, serie,
                   tag_antiga, tag_trocada, tag_alugado
            FROM cadastro
            WHERE (UPPER(TRIM(tag_antiga))  = ?
                OR UPPER(TRIM(tag_trocada)) = ?
                OR UPPER(TRIM(tag_alugado)) = ?)
              AND UPPER(TRIM(COALESCE(responsavel,''))) = 'ENGENHARIA CLINICA'
            LIMIT 1
        ");
        $busca_upper = strtoupper($busca);
        $stmt->bind_param('sss', $busca_upper, $busca_upper, $busca_upper);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        echo json_encode([
            'encontrado'         => true,
            'item_id'            => (int)$row['id'],
            'descricao'          => $row['descricao']          ?? '',
            'descricao_detalhada'=> $row['descricao_detalhada']?? '',
            'marca'              => $row['marca']              ?? '',
            'modelo'             => $row['modelo']             ?? '',
            'serie'              => $row['serie']              ?? '',
            'tag_antiga'         => $row['tag_antiga']         ?? '',
            'tag_trocada'        => $row['tag_trocada']        ?? '',
        ]);
    } else {
        echo json_encode(['encontrado' => false]);
    }
    exit;
}

// ── Itens com criticidade ──────────────────────────────────────────────────────
$itens_criticidade = [];
$res_it = $conn->query("SELECT descricao_item, criticidade_nivel FROM criticidade_item_engclin WHERE ativo=1 ORDER BY descricao_item ASC");
if ($res_it) while ($r = $res_it->fetch_assoc()) $itens_criticidade[] = $r;

$bloqueado   = false;
$msg_enviado = false;
$msg_erro    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar'])) {

    // Rate limit
    try {
        $tbExists = $conn->query("SHOW TABLES LIKE 'chamado_engclin'")->num_rows > 0;
        if ($tbExists) {
            $stmtRL = $conn->prepare("SELECT COUNT(*) AS c FROM chamado_engclin WHERE ip_origem = ? AND criado_em >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)");
            if ($stmtRL) {
                $stmtRL->bind_param('s', $ip_rede);
                $stmtRL->execute();
                $rowRL = $stmtRL->get_result()->fetch_assoc();
                if ((int)($rowRL['c'] ?? 0) >= 10) $bloqueado = true;
                $stmtRL->close();
            }
        }
    } catch (Throwable $e) {}

    if (!$bloqueado) {
        $nome        = strtoupper(trim($_POST['nome']        ?? ''));
        $cargo       = strtoupper(trim($_POST['cargo']       ?? ''));
        $unidade     = strtoupper(trim($_POST['unidade']     ?? ''));
        $setor       = strtoupper(trim($_POST['setor']       ?? ''));
        $area        = strtoupper(trim($_POST['area']        ?? ''));
        $email       = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $tag         = strtoupper(trim($_POST['tag']         ?? ''));
        $serie       = trim($_POST['serie']       ?? '');
        $tipo_item   = trim($_POST['tipo_item']   ?? '');
        $criticidade  = trim($_POST['criticidade']  ?? 'MEDIA');
        $data_parada = trim($_POST['data_parada'] ?? '');
        $hora_parada = trim($_POST['hora_parada'] ?? '');
        $tipo_ocorr  = trim($_POST['tipo_ocorr']  ?? '');
        $causa       = trim($_POST['causa']       ?? '');
        $obs         = trim($_POST['obs']         ?? '');
        $item_id_raw = intval($_POST['item_id']   ?? 0);
        $item_id     = $item_id_raw > 0 ? $item_id_raw : null;

        // Sanitizar
        $nome       = htmlspecialchars($nome,      ENT_QUOTES);
        $cargo      = htmlspecialchars($cargo,     ENT_QUOTES);
        $unidade    = htmlspecialchars($unidade,   ENT_QUOTES);
        $setor      = htmlspecialchars($setor,     ENT_QUOTES);
        $area       = htmlspecialchars($area,      ENT_QUOTES);
        $tag        = htmlspecialchars($tag,       ENT_QUOTES);
        $tipo_item  = htmlspecialchars($tipo_item, ENT_QUOTES);
        $causa      = htmlspecialchars($causa,     ENT_QUOTES);
        $obs        = htmlspecialchars($obs,       ENT_QUOTES);
        $tipo_ocorr = htmlspecialchars($tipo_ocorr,ENT_QUOTES);

        if (!in_array($criticidade, ['ALTA','MEDIA','BAIXA'])) $criticidade = 'MEDIA';

        if ($nome === '' || $unidade === '' || $setor === '' || $tipo_ocorr === '' || $causa === '') {
            $msg_erro = 'Preencha os campos obrigatórios marcados com *.';
        } else {
            try {
                // Gerar número do chamado
                $res_num = $conn->query("SELECT MAX(CAST(SUBSTRING(numero_chamado, 4) AS UNSIGNED)) AS ultimo FROM chamado_engclin");
                $ult_num = 0;
                if ($rn = $res_num->fetch_assoc()) $ult_num = (int)($rn['ultimo'] ?? 0);
                $numero_chamado = 'CH-' . str_pad($ult_num + 1, 6, '0', STR_PAD_LEFT);

                $dt_now = date('Y-m-d');
                $hr_now = date('H:i:s');
                $dp = $data_parada ?: null;
                $hp = $hora_parada ?: null;

                $stmt = $conn->prepare("
                    INSERT INTO chamado_engclin
                        (numero_chamado, nome, cargo, email,
                         unidade_ocorrencia, setor_ocorrencia, area_ocorrencia,
                         tag_patrimonio, numero_serie, descricao_item, item_id, criticidade,
                         data_chamado, hora_chamado, data_parada, hora_parada,
                         tipo_ocorrencia, causa, observacao, status, ip_origem)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ABERTO',?)
                ");
                if ($stmt) {
                    $stmt->bind_param('ssssssssssisssssssss',
                        $numero_chamado, $nome, $cargo, $email,
                        $unidade, $setor, $area,
                        $tag, $serie, $tipo_item, $item_id, $criticidade,
                        $dt_now, $hr_now, $dp, $hp,
                        $tipo_ocorr, $causa, $obs, $ip_rede
                    );
                    $stmt->execute();
                    $stmt->close();

                    // Histórico de eventos
                    $item_info = $item_id ? " | ID do item no cadastro: #{$item_id}" : '';
                    $desc_hist = "Chamado aberto por {$nome} ({$unidade} / {$setor}" . ($area ? " / {$area}" : "") . "). Tipo: {$tipo_ocorr}. Problema relatado: {$causa}{$item_info}";
                    $stH = $conn->prepare("INSERT INTO historico_eventos_engclin (numero_chamado,usuario,nome_usuario,tipo_evento,descricao_evento,data_evento,hora_evento) VALUES (?,?,?,?,?,?,?)");
                    if ($stH) {
                        $tipo_ev = 'ABERTURA_CHAMADO';
                        $stH->bind_param('sssssss', $numero_chamado, $email, $nome, $tipo_ev, $desc_hist, $dt_now, $hr_now);
                        $stH->execute();
                        $stH->close();
                    }
                }
                $msg_enviado = true;
            } catch (Throwable $e) {
                $msg_erro = 'Erro ao registrar o chamado. Tente novamente.';
            }
        }
    }
}

// ── Botão "Voltar" — só para DEV ou ENGENHARIA CLINICA logado ────────────────
// Esta página é pública (QR Code). Usuários anônimos NÃO devem ver navegação
// interna, então o botão só aparece com sessão ativa e classe autorizada.
$mostrar_voltar = false;
if (!empty($_SESSION['usuario_logado'])) {
    $stPerm = $conn->prepare("SELECT classe_usuario, status FROM usuarios WHERE usuario=? LIMIT 1");
    if ($stPerm) {
        $stPerm->bind_param('s', $_SESSION['usuario_logado']);
        $stPerm->execute();
        $resPerm = $stPerm->get_result();
        $rowPerm = $resPerm ? $resPerm->fetch_assoc() : null;
        $stPerm->close();
        if ($rowPerm
            && strtoupper(trim($rowPerm['status'] ?? 'ATIVO')) === 'ATIVO'
            && in_array(strtoupper(trim($rowPerm['classe_usuario'] ?? '')), ['DEV','ENGENHARIA CLINICA'], true)) {
            $mostrar_voltar = true;
        }
    }
}

$conn->close();

// Valores para repopular o form em caso de erro
$v_nome       = strtoupper($_POST['nome']        ?? '');
$v_cargo      = strtoupper($_POST['cargo']       ?? '');
$v_unidade    = strtoupper($_POST['unidade']     ?? '');
$v_setor      = strtoupper($_POST['setor']       ?? '');
$v_area       = strtoupper($_POST['area']        ?? '');
$v_email      = $_POST['email']      ?? '';
$v_tag        = strtoupper($_POST['tag']         ?? '');
$v_serie      = $_POST['serie']      ?? '';
$v_tipo_item  = $_POST['tipo_item']  ?? '';
$v_item_id    = intval($_POST['item_id'] ?? 0);
$v_dp         = $_POST['data_parada']?? '';
$v_hp         = $_POST['hora_parada']?? '';
$v_tipo_ocorr = $_POST['tipo_ocorr'] ?? '';
$v_causa      = $_POST['causa']      ?? '';
$v_obs        = $_POST['obs']        ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<meta name="theme-color" content="#0f0f0f">
<title>Abertura de Chamado — Engenharia Clínica</title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --bg-page:       #0f0f0f;
  --bg-card:       #1a1a1a;
  --bg-card2:      #1e1e1e;
  --bg-input:      #222;
  --border:        rgba(255,255,255,0.07);
  --border-hover:  rgba(255,255,255,0.14);
  --border-focus:  rgba(255,255,255,0.28);
  --text-primary:  #f0f0f0;
  --text-secondary:#888;
  --text-muted:    #555;
  --accent-steel:  #a0aec0;
  --status-ok:     #4ade80;
  --status-err:    #f87171;
  --status-warn:   #facc15;
  --crit-alta:     #f87171;
  --crit-media:    #facc15;
  --crit-baixa:    #4ade80;
  --radius:        10px;
  --radius-lg:     16px;
  --transition:    0.2s cubic-bezier(0.4,0,0.2,1);
  --font-ui:       'DM Sans', sans-serif;
  --font-display:  'Syne', sans-serif;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: var(--font-ui);
  background: var(--bg-page);
  color: var(--text-primary);
  min-height: 100vh;
  padding: 0 0 40px;
}

/* ── HEADER ── */
.page-header {
  background: #141414;
  border-bottom: 1px solid var(--border);
  padding: 16px 20px;
  display: flex; align-items: center; gap: 14px;
  position: sticky; top: 0; z-index: 10;
}
.header-logo { height: 36px; width: auto; object-fit: contain; flex-shrink: 0; }
.header-info { flex: 1; min-width: 0; }
.header-title { font-family: var(--font-display); font-size: 15px; font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.header-datetime { text-align: right; flex-shrink: 0; }
.header-datetime .date { font-size: 12px; color: var(--text-secondary); }
.header-datetime .time { font-size: 11px; color: var(--text-muted); }
.header-logo-rede { height: 32px; width: auto; object-fit: contain; opacity: .75; flex-shrink: 0; padding-left: 14px; border-left: 1px solid var(--border); }
/* Voltar — só renderizado para DEV / ENGENHARIA CLINICA logado.
   Mesmo tratamento visual do .btn-enviar. */
.btn-voltar { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; padding: 15px; margin-bottom: 16px; border-radius: 10px; background: linear-gradient(135deg, #2a2a2a, #333); border: 1px solid rgba(255,255,255,.15); color: var(--text-primary); text-decoration: none; font-family: var(--font-ui); font-size: 15px; font-weight: 600; letter-spacing: .02em; transition: all var(--transition); }
.btn-voltar:hover { background: linear-gradient(135deg, #333, #3d3d3d); border-color: rgba(255,255,255,.25); transform: translateY(-1px); box-shadow: 0 4px 16px rgba(0,0,0,.4); }
.btn-voltar:active { transform: translateY(0); }
.container { max-width: 680px; margin: 0 auto; padding: 24px 16px; }

/* ── SUCESSO ── */
.success-screen { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 60vh; text-align: center; padding: 40px 20px; animation: fadeUp .4s ease; }
.success-icon { width: 72px; height: 72px; border-radius: 50%; background: rgba(74,222,128,.1); border: 2px solid rgba(74,222,128,.3); display: flex; align-items: center; justify-content: center; font-size: 32px; color: var(--status-ok); margin-bottom: 20px; }
.success-title { font-family: var(--font-display); font-size: 22px; font-weight: 700; color: var(--text-primary); margin-bottom: 8px; }
.success-sub { font-size: 14px; color: var(--text-muted); max-width: 320px; line-height: 1.6; }
.btn-novo { margin-top: 28px; padding: 12px 28px; border-radius: 10px; background: #232323; border: 1px solid var(--border-hover); color: var(--text-primary); font-family: var(--font-ui); font-size: 14px; font-weight: 500; cursor: pointer; transition: all var(--transition); text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
.btn-novo:hover { background: #2e2e2e; border-color: rgba(255,255,255,.2); }

/* ── BLOQUEADO ── */
.blocked-screen { text-align: center; padding: 60px 20px; }
.blocked-screen i { font-size: 40px; color: var(--status-err); margin-bottom: 16px; display: block; }
.blocked-screen p { color: var(--text-secondary); font-size: 14px; line-height: 1.6; }

/* ── ALERT ── */
.alert-erro { background: rgba(248,113,113,.08); border: 1px solid rgba(248,113,113,.2); color: #f87171; border-radius: 10px; padding: 12px 16px; font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }

/* ── SEÇÃO ── */
.section { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; margin-bottom: 16px; transition: border-color var(--transition); }
.section-header { padding: 13px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; background: var(--bg-card2); }
.section-icon { width: 28px; height: 28px; border-radius: 7px; background: rgba(255,255,255,.05); display: flex; align-items: center; justify-content: center; font-size: 12px; color: var(--accent-steel); flex-shrink: 0; }
.section-title { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--accent-steel); }
.section-body { padding: 18px 16px; }

/* ── FORM GRID ── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-grid .full { grid-column: 1 / -1; }

.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group label { font-size: 11px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }
.form-group label .req { color: var(--status-err); }
.form-group label .hint { font-size: 10px; font-weight: 400; text-transform: none; letter-spacing: 0; color: var(--text-muted); margin-left: 2px; }

.form-control {
  background: var(--bg-input);
  border: 1px solid var(--border);
  border-radius: 8px;
  color: var(--text-primary);
  font-family: var(--font-ui);
  font-size: 14px;
  padding: 11px 13px;
  outline: none;
  width: 100%;
  transition: border-color var(--transition), background var(--transition);
  -webkit-appearance: none; appearance: none;
}
.form-control:focus { border-color: var(--border-focus); background: #272727; }
.form-control::placeholder { color: var(--text-muted); }
.form-control:disabled { opacity: .5; cursor: not-allowed; }
.form-control.found    { border-color: rgba(74,222,128,.5) !important; }
.form-control.not-found{ border-color: rgba(248,113,113,.4) !important; }

select.form-control {
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23666' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  background-size: 12px;
  padding-right: 36px;
  cursor: pointer;
}
select.form-control option { background: #222; color: #f0f0f0; }
textarea.form-control { resize: vertical; min-height: 90px; line-height: 1.5; }

/* ── BUSCA DE ITEM ── */
.item-search-wrap { position: relative; }
.item-search-wrap .form-control { padding-right: 42px; }
.item-search-spinner {
  position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
  font-size: 13px; color: var(--text-muted); display: none;
  pointer-events: none;
}
.item-search-spinner.visible { display: block; }

.item-found-card {
  display: none;
  margin-top: 8px;
  padding: 10px 13px;
  background: rgba(74,222,128,.07);
  border: 1px solid rgba(74,222,128,.25);
  border-radius: 8px;
  font-size: 12px;
  line-height: 1.6;
  animation: fadeUp .2s ease;
}
.item-found-card.visible { display: block; }
.item-found-card .found-title { color: var(--status-ok); font-weight: 600; display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
.item-found-card .found-detail { color: var(--text-secondary); }
.item-found-card .found-id { font-size: 10px; color: var(--text-muted); margin-top: 3px; font-family: monospace; }

.item-notfound-card {
  display: none;
  margin-top: 8px;
  padding: 8px 13px;
  background: rgba(248,113,113,.06);
  border: 1px solid rgba(248,113,113,.2);
  border-radius: 8px;
  font-size: 12px;
  color: var(--status-err);
  animation: fadeUp .2s ease;
}
.item-notfound-card.visible { display: block; }

/* ── TIPO DO ITEM (auto ou manual) ── */
.tipo-item-auto {
  display: none;
  padding: 10px 13px;
  background: rgba(74,222,128,.06);
  border: 1px solid rgba(74,222,128,.2);
  border-radius: 8px;
  font-size: 13px;
  color: var(--text-primary);
  align-items: center;
  gap: 8px;
}
.tipo-item-auto.visible { display: flex; }
.tipo-item-auto i { color: var(--status-ok); font-size: 12px; flex-shrink: 0; }
.tipo-item-auto .tipo-nome { flex: 1; }
.tipo-item-auto .btn-limpar-item {
  background: none; border: none; color: var(--text-muted);
  cursor: pointer; font-size: 12px; padding: 2px 5px;
  border-radius: 4px; transition: color var(--transition);
  flex-shrink: 0;
}
.tipo-item-auto .btn-limpar-item:hover { color: var(--status-err); }

/* ── COMBOBOX CUSTOMIZADO ── */
.combo-wrap { position: relative; }
.combo-input-wrap { margin-top: 6px; display: none; align-items: center; gap: 6px; }
.combo-input-wrap.visible { display: flex; }
.combo-input-wrap input {
  flex: 1; background: var(--bg-input); border: 1px solid var(--border-hover);
  border-radius: 8px; color: var(--text-primary); font-family: var(--font-ui);
  font-size: 14px; padding: 10px 13px; outline: none; text-transform: uppercase;
  transition: border-color var(--transition), background var(--transition); width: 100%;
}
.combo-input-wrap input:focus { border-color: var(--border-focus); background: #272727; }
.combo-input-wrap input::placeholder { color: var(--text-muted); text-transform: none; }
.combo-hint { font-size: 10px; color: var(--text-muted); margin-top: 4px; display: none; }
.combo-hint.visible { display: block; }

/* ── CRITICIDADE BADGE ── */
.prio-wrap { display: flex; align-items: center; gap: 10px; margin-top: 6px; }
.crit-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; letter-spacing: .06em; text-transform: uppercase; transition: all var(--transition); }
.crit-badge.ALTA  { background: rgba(248,113,113,.12); color: var(--crit-alta);  border: 1px solid rgba(248,113,113,.3); }
.crit-badge.MEDIA { background: rgba(250,204,21,.10);  color: var(--crit-media); border: 1px solid rgba(250,204,21,.3);  }
.crit-badge.BAIXA { background: rgba(74,222,128,.10);  color: var(--crit-baixa); border: 1px solid rgba(74,222,128,.3);  }

/* ── BOTÃO ENVIAR ── */
.btn-enviar { width: 100%; padding: 15px; border-radius: 10px; background: linear-gradient(135deg, #2a2a2a, #333); border: 1px solid rgba(255,255,255,.15); color: var(--text-primary); font-family: var(--font-ui); font-size: 15px; font-weight: 600; cursor: pointer; transition: all var(--transition); display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 8px; letter-spacing: .02em; }
.btn-enviar:hover { background: linear-gradient(135deg, #333, #3d3d3d); border-color: rgba(255,255,255,.25); transform: translateY(-1px); box-shadow: 0 4px 16px rgba(0,0,0,.4); }
.btn-enviar:active { transform: translateY(0); }

/* ── FOOTER ── */
.page-footer { text-align: center; padding: 20px 16px 0; font-size: 11px; color: var(--text-muted); }

/* ── ANIMATIONS ── */
@keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
.fade-up  { animation: fadeUp .3s ease both; }
.delay-1  { animation-delay: .06s; }
.delay-2  { animation-delay: .12s; }
.delay-3  { animation-delay: .18s; }
.delay-4  { animation-delay: .24s; }

::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,.07); border-radius: 4px; }

/* ── MOBILE ──
   640px em vez de 500: entre 500 e 640 (celular grande na horizontal, celular
   moderno na vertical) as duas colunas do formulário ficavam com ~140px cada,
   estreitas demais para os rótulos. */
@media (max-width: 640px) {
  .page-header { padding: 12px 14px; gap: 10px; }
  .header-logo { height: 30px; }
  .header-logo-rede { display: none; }
  .header-title { font-size: 14px; }
  .btn-voltar { font-size: 15px; padding: 14px; }
  .container { padding: 16px 12px; }
  .form-grid { grid-template-columns: 1fr; gap: 12px; }
  .form-grid .full { grid-column: 1; }
  .section-body { padding: 14px 12px; }
  .form-control { font-size: 16px; }
  .btn-enviar { font-size: 16px; padding: 16px; }
  .combo-input-wrap input { font-size: 16px; }
}
</style>
</head>
<body>

<!-- ── HEADER ── -->
<header class="page-header">
  <img src="lifetechoriginalclaro.png" alt="Engenharia Clínica" class="header-logo">
  <div class="header-info">
    <div class="header-title">Abertura de Chamado</div>
  </div>
  <div class="header-datetime">
    <div class="date"><?= $data_atual ?></div>
    <div class="time" id="relogio"><?= $hora_atual ?></div>
  </div>
  <img src="logo_rede.png" alt="Rede Hospitalar" class="header-logo-rede">
</header>

<div class="container">

<?php if ($mostrar_voltar): ?>
<a href="engenharia_clinica_inicial.php" class="btn-voltar fade-up">Voltar a tela Inicial</a>
<?php endif; ?>

<?php if ($bloqueado): ?>
<div class="blocked-screen fade-up">
  <i class="fas fa-ban"></i>
  <p>Muitas solicitações enviadas.<br>Aguarde alguns minutos e tente novamente.</p>
</div>

<?php elseif ($msg_enviado): ?>
<div class="success-screen fade-up">
  <div class="success-icon"><i class="fas fa-check"></i></div>
  <div class="success-title">Chamado Enviado!</div>
  <div class="success-sub">Sua solicitação foi registrada com sucesso. A equipe de Engenharia Clínica entrará em contato em breve.</div>
  <a href="eng_clin_aberturadechamado.php" class="btn-novo">
    <i class="fas fa-plus"></i> Abrir novo chamado
  </a>
</div>

<?php else: ?>

<?php if ($msg_erro): ?>
<div class="alert-erro fade-up">
  <i class="fas fa-circle-xmark"></i> <?= $msg_erro ?>
</div>
<?php endif; ?>

<form method="POST" autocomplete="off" id="formChamado">
<input type="hidden" name="enviar"      value="1">
<input type="hidden" name="criticidade"  id="hiddenCriticidade" value="MEDIA">
<input type="hidden" name="item_id"     id="hiddenItemId"     value="<?= $v_item_id ?: '' ?>">
<input type="hidden" name="tipo_item"   id="hiddenTipoItem"   value="<?= htmlspecialchars($v_tipo_item) ?>">

<!-- SEÇÃO 1: IDENTIFICAÇÃO DA PESSOA -->
<div class="section fade-up delay-1">
  <div class="section-header">
    <div class="section-icon"><i class="fas fa-user"></i></div>
    <span class="section-title">Identificação da Pessoa</span>
  </div>
  <div class="section-body">
    <div class="form-grid">

      <div class="form-group full">
        <label>Nome <span class="req">*</span></label>
        <input type="text" name="nome" class="form-control" placeholder="Seu nome completo"
               style="text-transform:uppercase" required
               value="<?= htmlspecialchars($v_nome) ?>">
      </div>

      <div class="form-group">
        <label>Cargo / Função</label>
        <input type="text" name="cargo" class="form-control" placeholder="Ex: ENFERMEIRO"
               style="text-transform:uppercase"
               value="<?= htmlspecialchars($v_cargo) ?>">
      </div>

      <div class="form-group">
        <label>E-mail</label>
        <input type="email" name="email" class="form-control" placeholder="seu@email.com"
               value="<?= htmlspecialchars($v_email) ?>">
      </div>

      <!-- UNIDADE -->
      <div class="form-group">
        <label>Unidade da Ocorrência <span class="req">*</span></label>
        <div class="combo-wrap">
          <select class="form-control combo-select" id="selUnidade"
                  onchange="comboChange(this,'inputUnidade','wrapUnidade','hintUnidade','unidade')">
            <option value="">Selecione a unidade...</option>
            <option value="CASA DE PORTUGAL"  <?= $v_unidade === 'CASA DE PORTUGAL'  ? 'selected':'' ?>>Casa de Portugal</option>
            <option value="EVANGELICO"        <?= $v_unidade === 'EVANGELICO'        ? 'selected':'' ?>>Evangélico</option>
            <option value="EGAS MONIZ"        <?= $v_unidade === 'EGAS MONIZ'        ? 'selected':'' ?>>Egas Moniz</option>
            <option value="ILHA DO GOVERNADOR"<?= $v_unidade === 'ILHA DO GOVERNADOR'? 'selected':'' ?>>Ilha do Governador</option>
            <option value="RIO LARANJEIRAS"   <?= $v_unidade === 'RIO LARANJEIRAS'   ? 'selected':'' ?>>Rio Laranjeiras</option>
            <option value="RIO BOTAFOGO"      <?= $v_unidade === 'RIO BOTAFOGO'      ? 'selected':'' ?>>Rio Botafogo</option>
            <option value="PRONTOCOR"         <?= $v_unidade === 'PRONTOCOR'         ? 'selected':'' ?>>Prontocor</option>
            <option value="SANTA CRUZ"        <?= $v_unidade === 'SANTA CRUZ'        ? 'selected':'' ?>>Santa Cruz</option>
            <option value="PREMIUM"           <?= $v_unidade === 'PREMIUM'           ? 'selected':'' ?>>Premium</option>
            <option value="MENSSANA"          <?= $v_unidade === 'MENSSANA'          ? 'selected':'' ?>>Menssana</option>
            <option value="SÃO BERNARDO"      <?= $v_unidade === 'SÃO BERNARDO'      ? 'selected':'' ?>>São Bernardo</option>
            <option value="OFTALMOCASA"       <?= $v_unidade === 'OFTALMOCASA'       ? 'selected':'' ?>>Oftalmocasa</option>
            <option value="OUTRO"             <?= ($v_unidade !== '' && !in_array($v_unidade, ['CASA DE PORTUGAL','EVANGELICO','EGAS MONIZ','ILHA DO GOVERNADOR','RIO LARANJEIRAS','RIO BOTAFOGO','PRONTOCOR','SANTA CRUZ','PREMIUM','MENSSANA','SÃO BERNARDO','OFTALMOCASA','OUTRO'])) ? 'selected':'' ?>>Outro</option>
          </select>
          <div class="combo-input-wrap" id="wrapUnidade">
            <input type="text" id="inputUnidade" placeholder="Digite a unidade..."
                   value="<?= (!in_array($v_unidade, ['CASA DE PORTUGAL','EVANGELICO','EGAS MONIZ','ILHA DO GOVERNADOR','RIO LARANJEIRAS','RIO BOTAFOGO','PRONTOCOR','SANTA CRUZ','PREMIUM','MENSSANA','SÃO BERNARDO','OFTALMOCASA','','OUTRO']) ? htmlspecialchars($v_unidade) : '') ?>">
          </div>
          <div class="combo-hint" id="hintUnidade">Digite o nome da unidade acima.</div>
          <input type="hidden" name="unidade" id="hiddenUnidade" value="<?= htmlspecialchars($v_unidade) ?>">
        </div>
      </div>

      <!-- SETOR -->
      <div class="form-group">
        <label>Setor da Ocorrência <span class="req">*</span></label>
        <div class="combo-wrap">
          <select class="form-control combo-select" id="selSetor"
                  onchange="comboChange(this,'inputSetor','wrapSetor','hintSetor','setor')">
            <option value="">Selecione o setor...</option>
            <option value="CLINICA MÉDICA"  <?= $v_setor === 'CLINICA MÉDICA'  ? 'selected':'' ?>>Clínica Médica</option>
            <option value="EMERGENCIA"      <?= $v_setor === 'EMERGENCIA'      ? 'selected':'' ?>>Emergência</option>
            <option value="CTI 1"           <?= $v_setor === 'CTI 1'           ? 'selected':'' ?>>CTI 1</option>
            <option value="CTI 2"           <?= $v_setor === 'CTI 2'           ? 'selected':'' ?>>CTI 2</option>
            <option value="CTI 3"           <?= $v_setor === 'CTI 3'           ? 'selected':'' ?>>CTI 3</option>
            <option value="CTI 4"           <?= $v_setor === 'CTI 4'           ? 'selected':'' ?>>CTI 4</option>
            <option value="CENTRO CIRURGICO"<?= $v_setor === 'CENTRO CIRURGICO'? 'selected':'' ?>>Centro Cirúrgico</option>
            <option value="NEO NATAL"       <?= $v_setor === 'NEO NATAL'       ? 'selected':'' ?>>Neo Natal</option>
            <option value="OUTRO"           <?= ($v_setor !== '' && !in_array($v_setor, ['CLINICA MÉDICA','EMERGENCIA','CTI 1','CTI 2','CTI 3','CTI 4','CENTRO CIRURGICO','NEO NATAL','OUTRO'])) ? 'selected':'' ?>>Outro</option>
          </select>
          <div class="combo-input-wrap" id="wrapSetor">
            <input type="text" id="inputSetor" placeholder="Digite o setor..."
                   value="<?= (!in_array($v_setor, ['CLINICA MÉDICA','EMERGENCIA','CTI 1','CTI 2','CTI 3','CTI 4','CENTRO CIRURGICO','NEO NATAL','','OUTRO']) ? htmlspecialchars($v_setor) : '') ?>">
          </div>
          <div class="combo-hint" id="hintSetor">Digite o nome do setor acima.</div>
          <input type="hidden" name="setor" id="hiddenSetor" value="<?= htmlspecialchars($v_setor) ?>">
        </div>
      </div>

      <!-- ÁREA -->
      <div class="form-group full">
        <label>Área <span class="hint">(localização dentro do setor)</span></label>
        <input type="text" name="area" class="form-control"
               placeholder="Ex: Leito 01, Quarto 12, Sala de Procedimentos..."
               style="text-transform:uppercase"
               value="<?= htmlspecialchars($v_area) ?>">
      </div>

    </div>
  </div>
</div>

<!-- SEÇÃO 2: IDENTIFICAÇÃO DO ITEM -->
<div class="section fade-up delay-2">
  <div class="section-header">
    <div class="section-icon"><i class="fas fa-tag"></i></div>
    <span class="section-title">Identificação do Item</span>
  </div>
  <div class="section-body">
    <div class="form-grid">

      <!-- TAG -->
      <div class="form-group">
        <label>Tag de Patrimônio <span class="hint">(placa)</span></label>
        <div class="item-search-wrap">
          <input type="text" id="inputTag" class="form-control" placeholder="Ex: HCSC 001234"
                 style="text-transform:uppercase"
                 value="<?= htmlspecialchars($v_tag) ?>"
                 autocomplete="off">
          <i class="fas fa-circle-notch fa-spin item-search-spinner" id="spinnerTag"></i>
        </div>
        <!-- hidden que vai no POST -->
        <input type="hidden" name="tag" id="hiddenTag" value="<?= htmlspecialchars($v_tag) ?>">
        <div class="item-found-card"    id="foundCardTag"></div>
        <div class="item-notfound-card" id="notFoundCardTag"></div>
      </div>

      <!-- SÉRIE -->
      <div class="form-group">
        <label>Número de Série</label>
        <div class="item-search-wrap">
          <input type="text" id="inputSerie" class="form-control" placeholder="Nº de série"
                 value="<?= htmlspecialchars($v_serie) ?>"
                 autocomplete="off">
          <i class="fas fa-circle-notch fa-spin item-search-spinner" id="spinnerSerie"></i>
        </div>
        <input type="hidden" name="serie" id="hiddenSerie" value="<?= htmlspecialchars($v_serie) ?>">
        <div class="item-found-card"    id="foundCardSerie"></div>
        <div class="item-notfound-card" id="notFoundCardSerie"></div>
      </div>

      <!-- TIPO DO ITEM -->
      <div class="form-group full">
        <label>Tipo do Item <span class="hint">(descrição)</span></label>

        <!-- Card exibido quando o item é localizado automaticamente -->
        <div class="tipo-item-auto" id="tipoItemAuto">
          <i class="fas fa-circle-check"></i>
          <span class="tipo-nome" id="tipoItemAutoNome"></span>
          <button type="button" class="btn-limpar-item" onclick="limparItemEncontrado()" title="Remover e preencher manualmente">
            <i class="fas fa-xmark"></i>
          </button>
        </div>

        <!-- Select manual — oculto quando auto-preenchido -->
        <div id="tipoItemManualWrap">
          <select class="form-control" id="selTipoItem" onchange="tipoItemManualChange()">
            <option value="">Selecione o tipo de equipamento...</option>
            <?php foreach ($itens_criticidade as $it): ?>
            <option value="<?= htmlspecialchars($it['descricao_item']) ?>"
                    data-criticidade="<?= $it['criticidade_nivel'] ?>"
                    <?= ($v_tipo_item === $it['descricao_item']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($it['descricao_item']) ?>
            </option>
            <?php endforeach; ?>
            <option value="__MANUAL__">Outro (digitar manualmente)</option>
          </select>
          <!-- Input livre para quando selecionar "Outro" manualmente -->
          <div class="combo-input-wrap" id="wrapTipoItemManual">
            <input type="text" id="inputTipoItemManual" placeholder="Digite o tipo do item..."
                   style="text-transform:uppercase">
          </div>
        </div>

        <div class="prio-wrap" id="prioBadgeWrap" style="display:none">
          <span style="font-size:11px;color:var(--text-muted)">Criticidade automática:</span>
          <span class="crit-badge" id="prioBadge"></span>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- SEÇÃO 3: DESCRIÇÃO DA OCORRÊNCIA -->
<div class="section fade-up delay-3">
  <div class="section-header">
    <div class="section-icon"><i class="fas fa-clipboard-list"></i></div>
    <span class="section-title">Descrição da Ocorrência</span>
  </div>
  <div class="section-body">
    <div class="form-grid">
      <div class="form-group">
        <label>Data do Chamado</label>
        <input type="text" class="form-control" value="<?= $data_atual ?>" disabled>
      </div>
      <div class="form-group">
        <label>Hora do Chamado</label>
        <input type="text" class="form-control" id="horaChamado" value="<?= $hora_atual ?>" disabled>
      </div>
      <div class="form-group">
        <label>Data da Parada <span class="hint">(do equipamento)</span></label>
        <input type="date" name="data_parada" class="form-control" value="<?= htmlspecialchars($v_dp) ?>">
      </div>
      <div class="form-group">
        <label>Hora da Parada</label>
        <input type="time" name="hora_parada" class="form-control" value="<?= htmlspecialchars($v_hp) ?>">
      </div>
      <div class="form-group full">
        <label>Tipo da Ocorrência <span class="req">*</span></label>
        <select name="tipo_ocorr" class="form-control" required>
          <option value="">Selecione...</option>
          <option value="CORRETIVA"  <?= $v_tipo_ocorr === 'CORRETIVA'  ? 'selected':'' ?>>Manutenção Corretiva</option>
          <option value="PREVENTIVA" <?= $v_tipo_ocorr === 'PREVENTIVA' ? 'selected':'' ?>>Manutenção Preventiva</option>
          <option value="CALIBRACAO" <?= $v_tipo_ocorr === 'CALIBRACAO' ? 'selected':'' ?>>Calibração</option>
          <option value="INSTALACAO" <?= $v_tipo_ocorr === 'INSTALACAO' ? 'selected':'' ?>>Instalação</option>
          <option value="INSPECAO"   <?= $v_tipo_ocorr === 'INSPECAO'   ? 'selected':'' ?>>Inspeção</option>
          <option value="OUTRO"      <?= $v_tipo_ocorr === 'OUTRO'      ? 'selected':'' ?>>Outro</option>
        </select>
      </div>
      <div class="form-group full">
        <label>Problema relatado <span class="req">*</span></label>
        <input type="text" name="causa" class="form-control"
               placeholder="Descreva o que está acontecendo com o equipamento" required
               value="<?= htmlspecialchars($v_causa) ?>">
      </div>
      <div class="form-group full">
        <label>Observações</label>
        <textarea name="obs" class="form-control"
                  placeholder="Informações adicionais, histórico, contexto..."
                  style="min-height:110px"><?= htmlspecialchars($v_obs) ?></textarea>
      </div>
    </div>
  </div>
</div>

<div class="fade-up delay-4">
  <button type="submit" class="btn-enviar">
    <i class="fas fa-paper-plane"></i> Enviar Chamado
  </button>
</div>

</form>
<?php endif; ?>

<div class="page-footer">
  Engenharia Clínica · Rede Hospitalar &nbsp;·&nbsp; &copy; GK Soluções
</div>
</div>

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

// ── Relógio ──
setInterval(() => {
  const now = new Date();
  const hh  = String(now.getHours()).padStart(2,'0');
  const mm  = String(now.getMinutes()).padStart(2,'0');
  const el  = document.getElementById('relogio');
  const hc  = document.getElementById('horaChamado');
  if (el) el.textContent = hh + ':' + mm;
  if (hc) hc.value       = hh + ':' + mm;
}, 30000);

// ── Combobox + input livre ──
function comboChange(sel, inputId, wrapId, hintId, hiddenFieldName) {
  const wrap   = document.getElementById(wrapId);
  const hint   = document.getElementById(hintId);
  const input  = document.getElementById(inputId);
  const hidden = document.querySelector(`input[name="${hiddenFieldName}"]`);

  if (sel.value === 'OUTRO') {
    wrap.classList.add('visible');
    hint.classList.add('visible');
    input.value = '';
    input.focus();
    if (hidden) hidden.value = '';
    input.oninput = () => {
      if (hidden) hidden.value = input.value.toUpperCase();
      input.value = input.value.toUpperCase();
    };
  } else {
    wrap.classList.remove('visible');
    hint.classList.remove('visible');
    if (hidden) hidden.value = sel.value;
  }
}

// Repopular combos com valor personalizado (retorno de POST)
(function() {
  const predefinedUnidades = ['CASA DE PORTUGAL','EVANGELICO','EGAS MONIZ','ILHA DO GOVERNADOR','RIO LARANJEIRAS','RIO BOTAFOGO','PRONTOCOR','SANTA CRUZ','PREMIUM','MENSSANA','SÃO BERNARDO','OFTALMOCASA'];
  const predefinedSetores  = ['CLINICA MÉDICA','EMERGENCIA','CTI 1','CTI 2','CTI 3','CTI 4','CENTRO CIRURGICO','NEO NATAL'];
  const vUnidade = <?= json_encode($v_unidade) ?>;
  const vSetor   = <?= json_encode($v_setor) ?>;

  if (vUnidade && !predefinedUnidades.includes(vUnidade)) {
    document.getElementById('selUnidade').value = 'OUTRO';
    document.getElementById('wrapUnidade').classList.add('visible');
    document.getElementById('hintUnidade').classList.add('visible');
    const inp = document.getElementById('inputUnidade');
    inp.value = vUnidade;
    inp.oninput = () => { inp.value = inp.value.toUpperCase(); document.querySelector('input[name="unidade"]').value = inp.value; };
  }
  if (vSetor && !predefinedSetores.includes(vSetor)) {
    document.getElementById('selSetor').value = 'OUTRO';
    document.getElementById('wrapSetor').classList.add('visible');
    document.getElementById('hintSetor').classList.add('visible');
    const inp = document.getElementById('inputSetor');
    inp.value = vSetor;
    inp.oninput = () => { inp.value = inp.value.toUpperCase(); document.querySelector('input[name="setor"]').value = inp.value; };
  }
})();

// ── BUSCA DE ITEM POR TAG OU SÉRIE ──────────────────────────────────────────
let buscaTimer = null;
let itemEncontrado = null; // guarda o objeto retornado pela API

function buscarItem(campo, valor) {
  valor = valor.trim();
  const spinner    = document.getElementById(campo === 'tag' ? 'spinnerTag'        : 'spinnerSerie');
  const foundCard  = document.getElementById(campo === 'tag' ? 'foundCardTag'      : 'foundCardSerie');
  const nfCard     = document.getElementById(campo === 'tag' ? 'notFoundCardTag'   : 'notFoundCardSerie');
  const inputEl    = document.getElementById(campo === 'tag' ? 'inputTag'          : 'inputSerie');

  // Limpa estado anterior
  foundCard.classList.remove('visible');
  nfCard.classList.remove('visible');
  inputEl.classList.remove('found','not-found');

  if (valor.length < 2) return;

  spinner.classList.add('visible');

  clearTimeout(buscaTimer);
  buscaTimer = setTimeout(() => {
    fetch(`eng_clin_aberturadechamado.php?action=buscar_item&campo=${campo}&q=${encodeURIComponent(valor)}`)
      .then(r => r.json())
      .then(data => {
        spinner.classList.remove('visible');
        if (data.encontrado) {
          aplicarItemEncontrado(data, campo);
          inputEl.classList.add('found');
          foundCard.innerHTML = `
            <div class="found-title"><i class="fas fa-circle-check"></i> Item localizado no cadastro</div>
            <div class="found-detail"><strong>${esc(data.descricao)}</strong>${data.marca ? ' · ' + esc(data.marca) : ''}${data.modelo ? ' / ' + esc(data.modelo) : ''}</div>
            <div class="found-id">ID cadastro: #${esc(data.item_id)} · Série: ${esc(data.serie || '—')} · Tag: ${esc(data.tag_antiga || data.tag_trocada || '—')}</div>`;
          foundCard.classList.add('visible');
          nfCard.classList.remove('visible');
        } else {
          limparItemEncontrado(false);
          inputEl.classList.add('not-found');
          nfCard.textContent = 'Item não localizado no cadastro. Preencha o tipo manualmente abaixo.';
          nfCard.classList.add('visible');
          foundCard.classList.remove('visible');
        }
      })
      .catch(() => spinner.classList.remove('visible'));
  }, 600);
}

function aplicarItemEncontrado(data, origemCampo) {
  itemEncontrado = data;
  document.getElementById('hiddenItemId').value   = data.item_id;
  document.getElementById('hiddenTipoItem').value = data.descricao;

  // Preenche o campo oposto se estiver vazio
  if (origemCampo === 'tag' && !document.getElementById('inputSerie').value.trim()) {
    document.getElementById('inputSerie').value  = data.serie || '';
    document.getElementById('hiddenSerie').value = data.serie || '';
  }
  if (origemCampo === 'serie' && !document.getElementById('inputTag').value.trim()) {
    const tag = data.tag_trocada || data.tag_antiga || '';
    document.getElementById('inputTag').value  = tag;
    document.getElementById('hiddenTag').value = tag;
  }

  // Exibe card "auto" no tipo do item e oculta o select manual
  document.getElementById('tipoItemAutoNome').textContent = data.descricao;
  document.getElementById('tipoItemAuto').classList.add('visible');
  document.getElementById('tipoItemManualWrap').style.display = 'none';
  document.getElementById('prioBadgeWrap').style.display = 'none';
}

function limparItemEncontrado(limparInputs = true) {
  itemEncontrado = null;
  document.getElementById('hiddenItemId').value   = '';
  document.getElementById('hiddenTipoItem').value = '';
  document.getElementById('tipoItemAuto').classList.remove('visible');
  document.getElementById('tipoItemManualWrap').style.display = '';

  if (limparInputs) {
    ['inputTag','inputSerie'].forEach(id => {
      const el = document.getElementById(id);
      el.classList.remove('found','not-found');
    });
    ['foundCardTag','foundCardSerie','notFoundCardTag','notFoundCardSerie'].forEach(id => {
      document.getElementById(id).classList.remove('visible');
    });
    document.getElementById('hiddenTag').value   = '';
    document.getElementById('hiddenSerie').value = '';
  }
}

// Listeners nos campos de busca
document.getElementById('inputTag').addEventListener('input', function() {
  const v = this.value.toUpperCase();
  this.value = v;
  document.getElementById('hiddenTag').value = v;
  buscarItem('tag', v);
});
document.getElementById('inputSerie').addEventListener('input', function() {
  document.getElementById('hiddenSerie').value = this.value;
  buscarItem('serie', this.value);
});

// ── Tipo do Item — seleção manual ──
const selTipoItem   = document.getElementById('selTipoItem');
const prioBadge     = document.getElementById('prioBadge');
const prioBadgeWrap = document.getElementById('prioBadgeWrap');
const hiddenPrio    = document.getElementById('hiddenCriticidade');
const prioIcons     = { ALTA: 'fa-circle-exclamation', MEDIA: 'fa-circle-minus', BAIXA: 'fa-circle-check' };

function tipoItemManualChange() {
  const opt  = selTipoItem.options[selTipoItem.selectedIndex];
  const val  = selTipoItem.value;
  const prio = opt?.dataset?.criticidade || '';
  const wrapManual = document.getElementById('wrapTipoItemManual');

  if (val === '__MANUAL__') {
    wrapManual.classList.add('visible');
    document.getElementById('inputTipoItemManual').focus();
    document.getElementById('hiddenTipoItem').value = '';
    document.getElementById('prioBadgeWrap').style.display = 'none';
    hiddenPrio.value = 'MEDIA';
    return;
  }

  wrapManual.classList.remove('visible');

  if (val && prio) {
    hiddenPrio.value            = prio;
    prioBadge.className         = 'crit-badge ' + prio;
    prioBadge.innerHTML         = `<i class="fas ${esc(prioIcons[prio])}"></i> ${prio === 'ALTA' ? 'Alta' : prio === 'MEDIA' ? 'Média' : 'Baixa'}`;
    prioBadgeWrap.style.display = 'flex';
  } else {
    hiddenPrio.value            = 'MEDIA';
    prioBadgeWrap.style.display = 'none';
  }
  document.getElementById('hiddenTipoItem').value = val;
}

if (selTipoItem) {
  selTipoItem.addEventListener('change', tipoItemManualChange);
  if (selTipoItem.value) tipoItemManualChange();
}

// Sincronizar input manual de tipo
const inputTipoManual = document.getElementById('inputTipoItemManual');
if (inputTipoManual) {
  inputTipoManual.addEventListener('input', function() {
    this.value = this.value.toUpperCase();
    document.getElementById('hiddenTipoItem').value = this.value;
  });
}

// ── Validação antes de enviar ──
const form = document.getElementById('formChamado');
if (form) {
  form.addEventListener('submit', function(e) {
    // Hiddens de unidade/setor
    const hU = document.querySelector('input[name="unidade"]');
    const hS = document.querySelector('input[name="setor"]');
    const selU = document.getElementById('selUnidade');
    const selS = document.getElementById('selSetor');
    if (selU && selU.value === 'OUTRO') {
      const inp = document.getElementById('inputUnidade');
      if (inp && inp.value.trim()) hU.value = inp.value.trim().toUpperCase();
    }
    if (selS && selS.value === 'OUTRO') {
      const inp = document.getElementById('inputSetor');
      if (inp && inp.value.trim()) hS.value = inp.value.trim().toUpperCase();
    }
    if (!hU || !hU.value.trim()) { e.preventDefault(); alert('Selecione ou informe a Unidade da Ocorrência.'); return; }
    if (!hS || !hS.value.trim()) { e.preventDefault(); alert('Selecione ou informe o Setor da Ocorrência.'); return; }

    // Garantir tipo_item: se select manual está em __MANUAL__, pega o input
    if (selTipoItem && selTipoItem.value === '__MANUAL__') {
      const mVal = document.getElementById('inputTipoItemManual').value.trim();
      document.getElementById('hiddenTipoItem').value = mVal;
    }

    // Spinner
    const btn = this.querySelector('.btn-enviar');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Enviando...'; }
  });
}
</script>
</body>
</html>
