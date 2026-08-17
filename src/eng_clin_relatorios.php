<?php
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = 'relatorios';   // item ativo no menu lateral
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

/* ═══════════════════════════════════════════════════════════════════════════
   FILTRO DE PERÍODO
   ═══════════════════════════════════════════════════════════════════════════ */
$per = $_GET['periodo'] ?? '12m';
$PERIODOS = ['3m'=>'Últimos 3 meses','6m'=>'Últimos 6 meses','12m'=>'Últimos 12 meses','tudo'=>'Todo o período'];
if (!isset($PERIODOS[$per])) $per = '12m';
$meses_tras = ['3m'=>3, '6m'=>6, '12m'=>12, 'tudo'=>0][$per];
$dt_ini = $meses_tras ? date('Y-m-01', strtotime("-".($meses_tras-1)." months")) : '1900-01-01';

/* Helper: primeiro valor escalar de uma query */
function q1(mysqli $c, string $sql, $def = 0) {
    $r = $c->query($sql);
    if (!$r) return $def;
    $row = $r->fetch_row();
    return $row ? ($row[0] ?? $def) : $def;
}
function fmt_int($v){ return number_format((float)$v, 0, ',', '.'); }
function fmt_rs($v){ return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function fmt_dias($h) {
    if ($h === null) return '—';
    $h = (float)$h;
    if ($h < 1)  return round($h * 60) . ' min';
    if ($h < 48) return number_format($h, 1, ',', '.') . ' h';
    return number_format($h / 24, 1, ',', '.') . ' dias';
}

$RESP = "'ENGENHARIA CLINICA'";

/* ── 1. PATRIMÔNIO SOB RESPONSABILIDADE ──────────────────────────────────── */
$tot_itens   = (int)q1($conn, "SELECT COUNT(*) FROM cadastro WHERE responsavel = $RESP");
$itens_ativos= (int)q1($conn, "SELECT COUNT(*) FROM cadastro WHERE responsavel = $RESP AND status = 'ATIVO'");
$itens_baix  = (int)q1($conn, "SELECT COUNT(*) FROM cadastro WHERE responsavel = $RESP AND status = 'BAIXADO'");
$valor_equip = (float)q1($conn, "SELECT COALESCE(SUM(valor_item),0) FROM cadastro WHERE responsavel = $RESP");
$itens_manut = (int)q1($conn, "SELECT COUNT(*) FROM cadastro WHERE responsavel = $RESP AND UPPER(setor_destino) = 'ENGENHARIA CLINICA'");

/* ── 2. CHAMADOS E OS ────────────────────────────────────────────────────── */
$w_per = ($per === 'tudo') ? '' : " AND data_chamado >= '$dt_ini'";
$tot_chamados  = (int)q1($conn, "SELECT COUNT(*) FROM chamado_engclin WHERE 1=1 $w_per");
$ch_abertos    = (int)q1($conn, "SELECT COUNT(*) FROM chamado_engclin WHERE status='ABERTO' $w_per");
$os_pendentes  = (int)q1($conn, "SELECT COUNT(*) FROM ordemservico_engclin WHERE status='ABERTA'");
$os_concluidas = (int)q1($conn, "SELECT COUNT(*) FROM ordemservico_engclin o WHERE o.status <> 'ABERTA'"
                              . ($per === 'tudo' ? '' : " AND o.data_fechamento >= '$dt_ini'"));
$os_externa    = (int)q1($conn, "SELECT COUNT(*) FROM ordemservico_engclin WHERE manutencao_externa='SIM'");
$nao_devolvido = (int)q1($conn, "SELECT COUNT(*) FROM ordemservico_engclin WHERE item_devolvido='NAO'");

/* Taxa de resolução: concluídas com problema solucionado */
$os_resolvidas = (int)q1($conn, "SELECT COUNT(*) FROM ordemservico_engclin WHERE status <> 'ABERTA' AND motivo='PROBLEMA_SOLUCIONADO'"
                              . ($per === 'tudo' ? '' : " AND data_fechamento >= '$dt_ini'"));
$taxa_resol = $os_concluidas > 0 ? round($os_resolvidas / $os_concluidas * 100) : 0;

/* ── 3. PENDÊNCIAS POR MOTIVO ────────────────────────────────────────────── */
$MOT_LBL = [
    'PROBLEMA_SOLUCIONADO'=>'Problema solucionado','FALTA_DE_PECAS'=>'Aguardando peças',
    'EM_ANDAMENTO'=>'Trabalhos em andamento','SEM_SOLUCAO'=>'Sem solução',
    'AGUARDANDO_ORCAMENTO'=>'Aguardando orçamento','MANUTENCAO_TERCEIROS'=>'Manutenção externa',
    'AGUARDANDO_PATRIMONIO'=>'Aguardando patrimônio','OBSOLESCENCIA'=>'Obsoleto',
    'ITEM_ALUGADO'=>'Item alugado','OUTROS'=>'Outros',
];
$pend_motivo = [];
$r = $conn->query("SELECT COALESCE(NULLIF(motivo,''),'SEM_MOTIVO') m, COUNT(*) c
                   FROM ordemservico_engclin WHERE status='ABERTA' GROUP BY m ORDER BY c DESC");
if ($r) while ($x = $r->fetch_assoc()) $pend_motivo[] = $x;

/* ── 4. CHAMADOS POR MÊS ─────────────────────────────────────────────────── */
$serie = [];
$lim = $meses_tras ?: 12;
$r = $conn->query("
    SELECT DATE_FORMAT(data_chamado,'%Y-%m') ym, COUNT(*) abertos
    FROM chamado_engclin
    WHERE data_chamado >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL $lim MONTH),'%Y-%m-01')
    GROUP BY ym ORDER BY ym ASC");
if ($r) while ($x = $r->fetch_assoc()) $serie[$x['ym']] = ['abertos'=>(int)$x['abertos'], 'concl'=>0];

$r = $conn->query("
    SELECT DATE_FORMAT(data_fechamento,'%Y-%m') ym, COUNT(*) c
    FROM ordemservico_engclin
    WHERE status <> 'ABERTA' AND data_fechamento IS NOT NULL
      AND data_fechamento >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL $lim MONTH),'%Y-%m-01')
    GROUP BY ym ORDER BY ym ASC");
if ($r) while ($x = $r->fetch_assoc()) {
    if (!isset($serie[$x['ym']])) $serie[$x['ym']] = ['abertos'=>0,'concl'=>0];
    $serie[$x['ym']]['concl'] = (int)$x['c'];
}
ksort($serie);
$max_serie = 1;
foreach ($serie as $v) $max_serie = max($max_serie, $v['abertos'], $v['concl']);

/* ── 5. TEMPOS MÉDIOS (em horas) ─────────────────────────────────────────── */
$w_t = ($per === 'tudo') ? '' : " AND o.data_fechamento >= '$dt_ini'";

// Chamado → início do atendimento
$t_espera = q1($conn, "
    SELECT AVG(TIMESTAMPDIFF(MINUTE,
              TIMESTAMP(c.data_chamado, COALESCE(c.hora_chamado,'00:00:00')),
              TIMESTAMP(o.data_inicio,  COALESCE(o.hora_inicio,'00:00:00')))) / 60
    FROM ordemservico_engclin o
    JOIN chamado_engclin c ON c.numero_chamado = o.numero_chamado
    WHERE o.data_inicio IS NOT NULL AND c.data_chamado IS NOT NULL
      AND TIMESTAMP(o.data_inicio, COALESCE(o.hora_inicio,'00:00:00'))
          >= TIMESTAMP(c.data_chamado, COALESCE(c.hora_chamado,'00:00:00'))", null);

// Início → conclusão
$t_execucao = q1($conn, "
    SELECT AVG(TIMESTAMPDIFF(MINUTE,
              TIMESTAMP(o.data_inicio,     COALESCE(o.hora_inicio,'00:00:00')),
              TIMESTAMP(o.data_fechamento, COALESCE(o.hora_fechamento,'00:00:00')))) / 60
    FROM ordemservico_engclin o
    WHERE o.status <> 'ABERTA' AND o.data_fechamento IS NOT NULL AND o.data_inicio IS NOT NULL $w_t", null);

// Chamado → conclusão (total)
$t_total = q1($conn, "
    SELECT AVG(TIMESTAMPDIFF(MINUTE,
              TIMESTAMP(c.data_chamado,    COALESCE(c.hora_chamado,'00:00:00')),
              TIMESTAMP(o.data_fechamento, COALESCE(o.hora_fechamento,'00:00:00')))) / 60
    FROM ordemservico_engclin o
    JOIN chamado_engclin c ON c.numero_chamado = o.numero_chamado
    WHERE o.status <> 'ABERTA' AND o.data_fechamento IS NOT NULL AND c.data_chamado IS NOT NULL $w_t", null);

/* ── 6. VALORES ──────────────────────────────────────────────────────────── */
$valor_estoque = (float)q1($conn, "SELECT COALESCE(SUM(quantidade * COALESCE(valor,0)),0) FROM estoque_engenharia WHERE ativo=1");
$qtd_estoque   = (float)q1($conn, "SELECT COALESCE(SUM(quantidade),0) FROM estoque_engenharia WHERE ativo=1");

// Economia: peças removidas de equipamentos baixados e reaproveitadas
$economia = (float)q1($conn, "
    SELECT COALESCE(SUM(cat.valor_estimado),0)
    FROM retiradadepecas_status st
    JOIN retiradadepecas_catalogo cat ON cat.id = st.id_catalogo
    WHERE st.status = 'REMOVIDO'");
$qtd_pecas_reap = (int)q1($conn, "SELECT COUNT(*) FROM retiradadepecas_status WHERE status='REMOVIDO'");

// Custo com manutenção externa
$custo_externa = (float)q1($conn, "
    SELECT COALESCE(SUM(valor_orcamento),0) FROM manutencao_externa_engclin
    WHERE orcamento='SIM' AND valor_orcamento IS NOT NULL");

/* ── 7. RANKINGS ─────────────────────────────────────────────────────────── */
$por_unidade = [];
$r = $conn->query("SELECT unidade_ocorrencia u, COUNT(*) c FROM chamado_engclin
                   WHERE unidade_ocorrencia <> '' $w_per GROUP BY u ORDER BY c DESC LIMIT 10");
if ($r) while ($x = $r->fetch_assoc()) $por_unidade[] = $x;

$por_equip = [];
$r = $conn->query("SELECT descricao_item d, COUNT(*) c FROM chamado_engclin
                   WHERE descricao_item <> '' $w_per GROUP BY d ORDER BY c DESC LIMIT 10");
if ($r) while ($x = $r->fetch_assoc()) $por_equip[] = $x;

$por_tecnico = [];
$r = $conn->query("
    SELECT m.nome_tecnico t, COUNT(*) intervencoes,
           AVG(TIMESTAMPDIFF(MINUTE,
               TIMESTAMP(m.data_inicio, COALESCE(m.hora_inicio,'00:00:00')),
               TIMESTAMP(m.data_fim,    COALESCE(m.hora_fim,'00:00:00')))) / 60 AS h_medio
    FROM maodeobra_engclin m
    GROUP BY m.nome_tecnico ORDER BY intervencoes DESC LIMIT 10");
if ($r) while ($x = $r->fetch_assoc()) $por_tecnico[] = $x;

$pecas_top = [];
$r = $conn->query("
    SELECT i.nome_item n, SUM(i.quantidade_usada) q,
           SUM(i.quantidade_usada * COALESCE(i.valor_unitario,0)) v
    FROM itens_os_engclin i GROUP BY i.nome_item ORDER BY q DESC LIMIT 10");
if ($r) while ($x = $r->fetch_assoc()) $pecas_top[] = $x;

$conn->close();
$data = date('d/m/Y'); $hora = date('H:i:s');
$max_uni = $por_unidade ? max(array_column($por_unidade,'c')) : 1;
$max_eq  = $por_equip   ? max(array_column($por_equip,'c'))   : 1;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relatórios — Engenharia Clínica</title>
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
  --status-ok:#4ade80; --status-warn:#facc15; --status-err:#f87171; --azul:#60a5fa;
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
.content{flex:1;padding:24px 28px;max-width:1400px;width:100%}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:20px}
.page-title{font-family:var(--font-display);font-size:22px;font-weight:700;letter-spacing:-.01em}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:8px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none}
.btn-ghost{background:#232323;border:1px solid rgba(255,255,255,.12);color:var(--text-primary)}
.btn-ghost:hover{background:#2e2e2e}
/* Filtro de período */
.filtros{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:20px}
.fil{padding:7px 15px;border-radius:20px;font-size:12px;text-decoration:none;color:var(--text-secondary);background:#1c1c1c;border:1px solid var(--border);transition:all var(--transition)}
.fil:hover{border-color:var(--border-hover);color:var(--text-primary)}
.fil.on{background:rgba(74,222,128,.13);border-color:rgba(74,222,128,.35);color:#4ade80;font-weight:600}
/* Seções */
.sec-lbl{font-size:10.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);margin:26px 0 12px;display:flex;align-items:center;gap:9px}
.sec-lbl::after{content:'';flex:1;height:1px;background:var(--border)}
.sec-lbl:first-of-type{margin-top:0}
/* KPIs */
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:13px}
.kpi{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:17px 19px;transition:border-color var(--transition)}
.kpi:hover{border-color:var(--border-hover)}
.kpi-lbl{font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted);display:flex;align-items:center;gap:7px;margin-bottom:9px}
.kpi-lbl i{font-size:11px;color:var(--accent-steel)}
.kpi-val{font-family:var(--font-display);font-size:27px;font-weight:700;line-height:1.05;letter-spacing:-.02em}
.kpi-val.sm{font-size:21px}
.kpi-sub{font-size:11px;color:var(--text-muted);margin-top:6px;line-height:1.5}
.v-ok{color:#4ade80} .v-warn{color:#facc15} .v-err{color:#f87171} .v-blue{color:var(--azul)} .v-steel{color:var(--accent-steel)}
/* Painéis */
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.painel{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden}
.painel-h{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px;background:rgba(255,255,255,.02)}
.painel-t{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--accent-steel);flex:1}
.painel-b{padding:16px 18px}
/* Gráfico de barras mensal */
.chart{display:flex;align-items:flex-end;gap:10px;height:210px;padding:10px 4px 0;overflow-x:auto}
.col{display:flex;flex-direction:column;align-items:center;gap:7px;min-width:52px;flex:1;height:100%}
.col-bars{display:flex;align-items:flex-end;gap:3px;height:100%;width:100%;justify-content:center}
.bar{width:16px;border-radius:4px 4px 0 0;position:relative;transition:opacity var(--transition);min-height:2px}
.bar:hover{opacity:.8}
.bar-a{background:linear-gradient(180deg,#60a5fa,rgba(96,165,250,.35))}
.bar-c{background:linear-gradient(180deg,#4ade80,rgba(74,222,128,.35))}
.bar-n{position:absolute;top:-17px;left:50%;transform:translateX(-50%);font-size:10px;font-weight:700;color:var(--text-secondary)}
.col-lbl{font-size:10px;color:var(--text-muted);white-space:nowrap}
.legenda{display:flex;gap:16px;justify-content:center;margin-top:14px;font-size:11.5px;color:var(--text-secondary)}
.leg{display:flex;align-items:center;gap:6px}
.leg-c{width:11px;height:11px;border-radius:3px}
/* Listas com barra */
.lst-item{margin-bottom:11px}
.lst-item:last-child{margin-bottom:0}
.lst-top{display:flex;justify-content:space-between;gap:12px;font-size:12.5px;margin-bottom:5px}
.lst-nome{color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lst-val{color:var(--text-primary);font-weight:600;flex-shrink:0}
.lst-bar{height:5px;background:rgba(255,255,255,.05);border-radius:3px;overflow:hidden}
.lst-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,rgba(160,174,192,.85),rgba(160,174,192,.35))}
.lst-fill.verde{background:linear-gradient(90deg,rgba(74,222,128,.85),rgba(74,222,128,.3))}
/* Tabela */
.tb{width:100%;border-collapse:collapse;font-size:12.5px}
.tb th{text-align:left;font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--accent-steel);padding:8px 10px;border-bottom:1px solid var(--border)}
.tb td{padding:9px 10px;border-bottom:1px solid var(--border);color:var(--text-secondary)}
.tb tr:last-child td{border-bottom:none}
.tb td:first-child{color:var(--text-primary)}
.vazio{padding:26px;text-align:center;color:var(--text-muted);font-size:12px}
.vazio i{display:block;font-size:22px;margin-bottom:8px;opacity:.3}
.footer{margin-left:var(--sidebar-w);padding:14px 28px;border-top:1px solid var(--border);display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);transition:margin-left var(--transition);flex-wrap:wrap;gap:8px}
@media(max-width:900px){.grid2{grid-template-columns:1fr}.content{padding:16px}.topbar-logo-rede{display:none}.footer,#main{margin-left:var(--sidebar-collapsed)}#sidebar{width:var(--sidebar-collapsed)}}
@media(max-width:640px){#sidebar{position:fixed;transform:translateX(-100%);width:var(--sidebar-w)!important}#sidebar.open{transform:translateX(0)}.sidebar-toggle{display:none}.menu-toggle{display:block}#main{margin-left:0!important}.topbar{padding-left:54px}.footer{margin-left:0}}
@media print{#sidebar,.topbar,.footer,.filtros,.menu-toggle{display:none!important}#main{margin-left:0!important}body{background:#fff;color:#000}}
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
      <span>Relatórios</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
    <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <div class="page-header">
      <div>
        <div class="page-title">Relatórios e Estatísticas</div>
        <div class="page-subtitle">Engenharia Clínica &middot; <?= eng_clin_data_pt() ?></div>
      </div>
      <button class="btn btn-ghost" onclick="window.print()">
        <i class="fas fa-print"></i> Imprimir
      </button>
    </div>

    <div class="filtros">
      <?php foreach ($PERIODOS as $k => $lbl): ?>
      <a href="?periodo=<?= $k ?>" class="fil <?= $per===$k?'on':'' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>

    <!-- ══ PATRIMÔNIO ══════════════════════════════════════════════ -->
    <div class="sec-lbl"><i class="fas fa-boxes-stacked"></i> Patrimônio sob responsabilidade</div>
    <div class="kpis">
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-microscope"></i> Itens controlados</div>
        <div class="kpi-val"><?= fmt_int($tot_itens) ?></div>
        <div class="kpi-sub"><?= fmt_int($itens_ativos) ?> ativos &middot; <?= fmt_int($itens_baix) ?> baixados</div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-sack-dollar"></i> Valor em equipamentos</div>
        <div class="kpi-val sm v-ok"><?= fmt_rs($valor_equip) ?></div>
        <div class="kpi-sub">Soma de valor_item dos itens da Engenharia Clínica</div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-warehouse"></i> Valor em estoque</div>
        <div class="kpi-val sm v-blue"><?= fmt_rs($valor_estoque) ?></div>
        <div class="kpi-sub"><?= fmt_int($qtd_estoque) ?> peças em estoque</div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-screwdriver-wrench"></i> Em manutenção agora</div>
        <div class="kpi-val <?= $itens_manut > 0 ? 'v-warn' : '' ?>"><?= fmt_int($itens_manut) ?></div>
        <div class="kpi-sub">Itens na Sala de Manutenção</div>
      </div>
    </div>

    <!-- ══ ATENDIMENTO ═════════════════════════════════════════════ -->
    <div class="sec-lbl"><i class="fas fa-headset"></i> Atendimento — <?= $PERIODOS[$per] ?></div>
    <div class="kpis">
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-inbox"></i> Total de chamados</div>
        <div class="kpi-val"><?= fmt_int($tot_chamados) ?></div>
        <div class="kpi-sub"><?= fmt_int($ch_abertos) ?> aguardando técnico</div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-circle-check"></i> Concluídos</div>
        <div class="kpi-val v-ok"><?= fmt_int($os_concluidas) ?></div>
        <div class="kpi-sub"><?= $taxa_resol ?>% com problema solucionado</div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-clock"></i> Pendências</div>
        <div class="kpi-val <?= $os_pendentes > 0 ? 'v-warn' : 'v-ok' ?>"><?= fmt_int($os_pendentes) ?></div>
        <div class="kpi-sub">OS iniciadas e não encerradas</div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-truck"></i> Manutenção externa</div>
        <div class="kpi-val v-blue"><?= fmt_int($os_externa) ?></div>
        <div class="kpi-sub"><?= $custo_externa > 0 ? fmt_rs($custo_externa).' em orçamentos' : 'Nenhum orçamento lançado' ?></div>
      </div>
    </div>

    <!-- ══ TEMPOS ══════════════════════════════════════════════════ -->
    <div class="sec-lbl"><i class="fas fa-stopwatch"></i> Tempos médios</div>
    <div class="kpis">
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-hourglass-start"></i> Espera até o atendimento</div>
        <div class="kpi-val sm"><?= fmt_dias($t_espera) ?></div>
        <div class="kpi-sub">Da abertura do chamado ao início da OS</div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-hourglass-half"></i> Execução</div>
        <div class="kpi-val sm"><?= fmt_dias($t_execucao) ?></div>
        <div class="kpi-sub">Do início da OS até o encerramento</div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-hourglass-end"></i> Ciclo total</div>
        <div class="kpi-val sm v-blue"><?= fmt_dias($t_total) ?></div>
        <div class="kpi-sub">Da abertura do chamado à conclusão</div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-triangle-exclamation"></i> Itens não devolvidos</div>
        <div class="kpi-val <?= $nao_devolvido > 0 ? 'v-err' : 'v-ok' ?>"><?= fmt_int($nao_devolvido) ?></div>
        <div class="kpi-sub">OS encerradas sem devolver o equipamento</div>
      </div>
    </div>

    <!-- ══ ECONOMIA ════════════════════════════════════════════════ -->
    <div class="sec-lbl"><i class="fas fa-recycle"></i> Reaproveitamento</div>
    <div class="kpis">
      <div class="kpi" style="border-color:rgba(74,222,128,.25)">
        <div class="kpi-lbl"><i class="fas fa-piggy-bank" style="color:#4ade80"></i> Economia com peças reaproveitadas</div>
        <div class="kpi-val sm v-ok"><?= fmt_rs($economia) ?></div>
        <div class="kpi-sub"><?= fmt_int($qtd_pecas_reap) ?> peças retiradas de equipamentos baixados</div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl"><i class="fas fa-scale-balanced"></i> Economia vs. custo externo</div>
        <?php $saldo = $economia - $custo_externa; ?>
        <div class="kpi-val sm <?= $saldo >= 0 ? 'v-ok' : 'v-err' ?>"><?= fmt_rs($saldo) ?></div>
        <div class="kpi-sub">Reaproveitado menos gasto com terceiros</div>
      </div>
    </div>

    <!-- ══ GRÁFICO MENSAL ══════════════════════════════════════════ -->
    <div class="sec-lbl"><i class="fas fa-chart-column"></i> Evolução mensal</div>
    <div class="painel">
      <div class="painel-h"><span class="painel-t">Chamados abertos e OS concluídas por mês</span></div>
      <div class="painel-b">
        <?php if (!$serie): ?>
        <div class="vazio"><i class="fas fa-chart-column"></i>Sem dados no período.</div>
        <?php else: ?>
        <div class="chart">
          <?php foreach ($serie as $ym => $v):
            $ha = round($v['abertos'] / $max_serie * 100);
            $hc = round($v['concl']   / $max_serie * 100);
            [$yy,$mm] = explode('-', $ym);
            $mlbl = ['01'=>'jan','02'=>'fev','03'=>'mar','04'=>'abr','05'=>'mai','06'=>'jun',
                     '07'=>'jul','08'=>'ago','09'=>'set','10'=>'out','11'=>'nov','12'=>'dez'][$mm] ?? $mm;
          ?>
          <div class="col">
            <div class="col-bars">
              <div class="bar bar-a" style="height:<?= max(2,$ha) ?>%" title="<?= $v['abertos'] ?> chamados abertos">
                <?php if ($v['abertos'] > 0): ?><span class="bar-n"><?= $v['abertos'] ?></span><?php endif; ?>
              </div>
              <div class="bar bar-c" style="height:<?= max(2,$hc) ?>%" title="<?= $v['concl'] ?> OS concluídas"></div>
            </div>
            <div class="col-lbl"><?= $mlbl ?>/<?= substr($yy,2) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="legenda">
          <div class="leg"><span class="leg-c" style="background:#60a5fa"></span> Chamados abertos</div>
          <div class="leg"><span class="leg-c" style="background:#4ade80"></span> OS concluídas</div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══ PENDÊNCIAS POR MOTIVO + UNIDADES ════════════════════════ -->
    <div class="sec-lbl"><i class="fas fa-list-check"></i> Distribuição</div>
    <div class="grid2">
      <div class="painel">
        <div class="painel-h"><span class="painel-t">Pendências por motivo</span></div>
        <div class="painel-b">
          <?php if (!$pend_motivo): ?>
          <div class="vazio"><i class="fas fa-circle-check"></i>Nenhuma pendência aberta.</div>
          <?php else: $mx = max(array_column($pend_motivo,'c')); foreach ($pend_motivo as $p): ?>
          <div class="lst-item">
            <div class="lst-top">
              <span class="lst-nome"><?= htmlspecialchars($MOT_LBL[$p['m']] ?? ($p['m']==='SEM_MOTIVO'?'OS em andamento':$p['m'])) ?></span>
              <span class="lst-val"><?= $p['c'] ?></span>
            </div>
            <div class="lst-bar"><div class="lst-fill" style="width:<?= round($p['c']/$mx*100) ?>%"></div></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="painel">
        <div class="painel-h"><span class="painel-t">Chamados por unidade</span></div>
        <div class="painel-b">
          <?php if (!$por_unidade): ?>
          <div class="vazio"><i class="fas fa-hospital"></i>Sem dados no período.</div>
          <?php else: foreach ($por_unidade as $u): ?>
          <div class="lst-item">
            <div class="lst-top">
              <span class="lst-nome"><?= htmlspecialchars($u['u']) ?></span>
              <span class="lst-val"><?= $u['c'] ?></span>
            </div>
            <div class="lst-bar"><div class="lst-fill" style="width:<?= round($u['c']/$max_uni*100) ?>%"></div></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="painel">
        <div class="painel-h"><span class="painel-t">Equipamentos com mais chamados</span></div>
        <div class="painel-b">
          <?php if (!$por_equip): ?>
          <div class="vazio"><i class="fas fa-microscope"></i>Sem dados no período.</div>
          <?php else: foreach ($por_equip as $e): ?>
          <div class="lst-item">
            <div class="lst-top">
              <span class="lst-nome"><?= htmlspecialchars($e['d']) ?></span>
              <span class="lst-val"><?= $e['c'] ?></span>
            </div>
            <div class="lst-bar"><div class="lst-fill" style="width:<?= round($e['c']/$max_eq*100) ?>%"></div></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="painel">
        <div class="painel-h"><span class="painel-t">Produtividade por técnico</span></div>
        <div class="painel-b" style="padding:0">
          <?php if (!$por_tecnico): ?>
          <div class="vazio"><i class="fas fa-user-gear"></i>Nenhuma intervenção registrada.</div>
          <?php else: ?>
          <table class="tb">
            <thead><tr><th>Técnico</th><th style="text-align:center">Intervenções</th><th style="text-align:right">Tempo médio</th></tr></thead>
            <tbody>
              <?php foreach ($por_tecnico as $t): ?>
              <tr>
                <td><?= htmlspecialchars($t['t']) ?></td>
                <td style="text-align:center"><?= (int)$t['intervencoes'] ?></td>
                <td style="text-align:right"><?= $t['h_medio'] !== null ? fmt_dias($t['h_medio']) : '—' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══ PEÇAS MAIS USADAS ═══════════════════════════════════════ -->
    <div class="sec-lbl"><i class="fas fa-screwdriver-wrench"></i> Consumo de materiais</div>
    <div class="painel">
      <div class="painel-h"><span class="painel-t">Peças mais utilizadas em ordens de serviço</span></div>
      <div class="painel-b" style="padding:0">
        <?php if (!$pecas_top): ?>
        <div class="vazio"><i class="fas fa-boxes-stacked"></i>Nenhum material lançado ainda.</div>
        <?php else: ?>
        <table class="tb">
          <thead><tr><th>Peça</th><th style="text-align:center">Quantidade</th><th style="text-align:right">Valor total</th></tr></thead>
          <tbody>
            <?php foreach ($pecas_top as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['n']) ?></td>
              <td style="text-align:center"><?= fmt_int($p['q']) ?></td>
              <td style="text-align:right"><?= (float)$p['v'] > 0 ? fmt_rs($p['v']) : '—' ?></td>
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
