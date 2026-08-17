<?php
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = 'inicial';   // item ativo no menu lateral
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

if (!in_array($classe_usuario, ['DEV', 'ENGENHARIA CLINICA']) || !in_array($nivel, ['A', 'B', 'C'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}

// ── Chamados ativos para o painel ────────────────────────────────────
$chamados_painel = [];
$res_ch = $conn->query("
    SELECT numero_chamado, unidade_ocorrencia, setor_ocorrencia,
           descricao_item, criticidade, status, data_chamado, hora_chamado,
           nome, causa
    FROM chamado_engclin
    WHERE status IN ('ABERTO','EM_ATENDIMENTO','EM_MANUTENCAO_EXTERNA')
    ORDER BY
        FIELD(criticidade,'ALTA','MEDIA','BAIXA'),
        FIELD(status,'ABERTO','EM_ATENDIMENTO','EM_MANUTENCAO_EXTERNA'),
        data_chamado ASC, hora_chamado ASC
    LIMIT 30
");
if ($res_ch) {
    while ($r = $res_ch->fetch_assoc()) $chamados_painel[] = $r;
}

// ── KPIs ─────────────────────────────────────────────────────────────
// REGRA DE PENDÊNCIA: pendência é a OS iniciada e ainda não finalizada.
// Um chamado apenas ABERTO (sem OS) NÃO é pendência — está aguardando técnico.
$kpi = ['abertos'=>0,'em_atendimento'=>0,'manut_externa'=>0,'concluidos_mes'=>0];

// Chamados abertos, ainda sem OS — fila de espera, não pendência
$res_ab = $conn->query("SELECT COUNT(*) AS c FROM chamado_engclin WHERE status='ABERTO'");
if ($res_ab) $kpi['abertos'] = (int)$res_ab->fetch_assoc()['c'];

// PENDÊNCIAS: OS iniciada e não concluída
$res_pend = $conn->query("SELECT COUNT(*) AS c FROM ordemservico_engclin WHERE status='ABERTA'");
if ($res_pend) $kpi['em_atendimento'] = (int)$res_pend->fetch_assoc()['c'];

// Em manutenção externa — aceita o modelo antigo (status) e o novo (coluna
// manutencao_externa). A Fase 4 consolida no campo novo.
$res_me = $conn->query("
    SELECT COUNT(*) AS c FROM ordemservico_engclin
    WHERE status='MANUTENCAO_EXTERNA'
       OR (status='ABERTA' AND manutencao_externa='SIM')
");
if ($res_me) $kpi['manut_externa'] = (int)$res_me->fetch_assoc()['c'];

// Concluídos no mês — qualquer OS encerrada (status deixa de ser ABERTA)
// com data de fechamento no mês corrente.
$res_mes = $conn->query("
    SELECT COUNT(*) AS c FROM ordemservico_engclin
    WHERE status <> 'ABERTA' AND data_fechamento IS NOT NULL
      AND MONTH(data_fechamento)=MONTH(NOW()) AND YEAR(data_fechamento)=YEAR(NOW())
");
if ($res_mes) $kpi['concluidos_mes'] = (int)$res_mes->fetch_assoc()['c'];

$total_ativos = $kpi['abertos'] + $kpi['em_atendimento'];

// ── Pendências detalhadas por motivo ─────────────────────────────────
// Substituiu o painel "Distribuição por Setor", que exibia dados fictícios
// fixos no código. Aqui o número diz por que a fila não anda.
$MOT_LBL = [
    'EM_ANDAMENTO'          => 'Trabalhos em andamento',
    'SEM_SOLUCAO'           => 'Sem solução',
    'FALTA_DE_PECAS'        => 'Aguardando peças',
    'AGUARDANDO_ORCAMENTO'  => 'Aguardando orçamento',
    'MANUTENCAO_TERCEIROS'  => 'Em manutenção externa',
    'AGUARDANDO_PATRIMONIO' => 'Aguardando patrimônio',
    'PROBLEMA_SOLUCIONADO'  => 'Problema solucionado',
    'OBSOLESCENCIA'         => 'Obsoleto',
    'OUTROS'                => 'Outros',
];
$MOT_COR = [
    'FALTA_DE_PECAS'        => '250,204,21',
    'AGUARDANDO_ORCAMENTO'  => '250,204,21',
    'MANUTENCAO_TERCEIROS'  => '96,165,250',
    'AGUARDANDO_PATRIMONIO' => '160,174,192',
    'SEM_MOTIVO'            => '136,136,136',
    'EM_ANDAMENTO'          => '136,136,136',
    'OUTROS'                => '160,174,192',
];
$pend_motivo = []; $max_pend = 1;
$res_pm = $conn->query("
    SELECT COALESCE(NULLIF(motivo,''),'SEM_MOTIVO') AS m, COUNT(*) AS c
    FROM ordemservico_engclin
    WHERE status = 'ABERTA'
    GROUP BY m ORDER BY c DESC
");
if ($res_pm) while ($r = $res_pm->fetch_assoc()) {
    $pend_motivo[] = $r;
    $max_pend = max($max_pend, (int)$r['c']);
}

date_default_timezone_set('America/Sao_Paulo');
$hoje_ts = strtotime('today');

// ── MANUTENÇÕES PREVENTIVAS AGENDADAS ────────────────────────────────
// A agenda vive em preventiva_engclin (uma linha por equipamento), alimentada
// pelo encerramento da OS ou por inclusão manual em eng_clin_preventivas.php.
// Antes lia direto de ordemservico_engclin.proxima_preventiva, que gerava uma
// linha por OS e não permitia registrar realizada/adiada/removida.
$preventivas = [];
$res_pv = $conn->query("
    SELECT p.id, p.numero_chamado, p.proxima_data AS proxima_preventiva,
           p.periodicidade_meses, p.ultima_troca AS pecas_trocadas, p.origem,
           c.descricao, c.marca, c.modelo, c.serie,
           c.tag_antiga, c.tag_trocada, c.movimentado,
           c.unidade, c.setor, c.area,
           c.unidade_destino, c.setor_destino, c.area_destino
    FROM preventiva_engclin p
    LEFT JOIN cadastro c ON c.id = p.item_id
    WHERE p.ativo = 1
    ORDER BY p.proxima_data ASC
    LIMIT 40
");
if ($res_pv) while ($r = $res_pv->fetch_assoc()) {
    // Localização atual: destino se já movimentado, origem caso contrário
    $mov = strtoupper(trim((string)($r['movimentado'] ?? ''))) === 'SIM';
    $r['loc_unidade'] = $mov ? ($r['unidade_destino'] ?: $r['unidade']) : $r['unidade'];
    $r['loc_setor']   = $mov ? ($r['setor_destino']   ?: $r['setor'])   : $r['setor'];
    $r['loc_area']    = $mov ? ($r['area_destino']    ?: $r['area'])    : $r['area'];

    $dias = (int)floor((strtotime($r['proxima_preventiva']) - $hoje_ts) / 86400);
    $r['dias'] = $dias;
    $r['sit']  = $dias < 0 ? 'atrasada' : ($dias <= 30 ? 'proxima' : 'futura');
    $preventivas[] = $r;
}
$pv_atrasadas = count(array_filter($preventivas, fn($p) => $p['sit'] === 'atrasada'));
$pv_proximas  = count(array_filter($preventivas, fn($p) => $p['sit'] === 'proxima'));
// Vencendo nos próximos 7 dias (inclui hoje) — contador do painel
$pv_semana    = count(array_filter($preventivas, fn($p) => $p['dias'] >= 0 && $p['dias'] <= 7));

// ── EQUIPE: aniversários e férias (base: tabela tecnico) ─────────────────────

$aniversariantes = [];   // próximos 7 dias (inclui hoje)
$ferias_agora    = [];   // de férias neste momento
$ferias_proximas = [];   // entram de férias nos próximos 7 dias
$ids_foto        = [];   // só quem aparece no painel tem a foto carregada

/** Timestamp de dia/mês num ano, com o dia limitado ao fim do mês (29/02 → 28/02) */
function ec_ts_md(int $ano, int $mes, int $dia): int {
    $max = (int)date('t', mktime(0, 0, 0, $mes, 1, $ano));
    return mktime(0, 0, 0, $mes, min($dia, $max), $ano);
}

// A coluna `foto` é BLOB (até 10MB por técnico); não entra nesta varredura
$resTec = $conn->query("SELECT id, nome, funcao, unidade, data_nasc, ferias_ini_md, ferias_fim_md FROM tecnico ORDER BY nome ASC");
if ($resTec) {
    while ($t = $resTec->fetch_assoc()) {
        $nome = trim($t['nome'] ?? '');
        if ($nome === '') continue;
        $tid  = (int)$t['id'];

        // ── Aniversário: compara só dia/mês, ignorando o ano de nascimento
        $nasc = $t['data_nasc'] ?? '';
        if ($nasc && $nasc !== '0000-00-00') {
            $ts_nasc = strtotime($nasc);
            if ($ts_nasc !== false) {
                // Aniversário deste ano; se já passou, considera o do ano seguinte
                $aniv_ts = strtotime(date('Y', $hoje_ts) . '-' . date('m-d', $ts_nasc));
                if ($aniv_ts !== false) {
                    if ($aniv_ts < $hoje_ts) {
                        $aniv_ts = strtotime((date('Y', $hoje_ts) + 1) . '-' . date('m-d', $ts_nasc));
                    }
                    $dias = (int)round(($aniv_ts - $hoje_ts) / 86400);
                    if ($dias >= 0 && $dias <= 7) {
                        $idade = (int)date('Y', $aniv_ts) - (int)date('Y', $ts_nasc);
                        $aniversariantes[] = [
                            'nome'   => $nome,
                            'funcao' => $t['funcao']  ?? '',
                            'unidade'=> $t['unidade'] ?? '',
                            'id'     => $tid,
                            'dia'    => date('d/m', $aniv_ts),
                            'dias'   => $dias,
                            'idade'  => ($idade > 0 && $idade < 120) ? $idade : null,
                            'hoje'   => ($dias === 0),
                        ];
                        $ids_foto[$tid] = true;
                    }
                }
            }
        }

        // ── Férias: dia/mês sem ano, recorrente todo ano ('MM-DD')
        $md_i = $t['ferias_ini_md'] ?? '';
        $md_f = $t['ferias_fim_md'] ?? '';
        if (preg_match('/^(\d{2})-(\d{2})$/', (string)$md_i, $mi)
         && preg_match('/^(\d{2})-(\d{2})$/', (string)$md_f, $mf)) {

            $mes_i = (int)$mi[1]; $dia_i = (int)$mi[2];
            $mes_f = (int)$mf[1]; $dia_f = (int)$mf[2];

            // Se o fim vem antes do início, o período atravessa 31/12
            $vira_ano = ($mes_f < $mes_i) || ($mes_f === $mes_i && $dia_f < $dia_i);

            // Ancora o período no ano anterior, no atual e no seguinte para
            // que a virada de ano seja avaliada corretamente
            $ano = (int)date('Y', $hoje_ts);
            $periodos = [];
            foreach ([$ano - 1, $ano, $ano + 1] as $yy) {
                $ts_i = ec_ts_md($yy, $mes_i, $dia_i);
                $ts_f = ec_ts_md($vira_ano ? $yy + 1 : $yy, $mes_f, $dia_f);
                $periodos[] = [$ts_i, $ts_f];
            }

            $base = [
                'nome'   => $nome,
                'funcao' => $t['funcao']  ?? '',
                'unidade'=> $t['unidade'] ?? '',
                'id'     => $tid,
                'ini'    => $dia_i . '/' . str_pad((string)$mes_i, 2, '0', STR_PAD_LEFT),
                'fim'    => $dia_f . '/' . str_pad((string)$mes_f, 2, '0', STR_PAD_LEFT),
            ];

            // 1) Está de férias agora?
            $agora = null;
            foreach ($periodos as [$ts_i, $ts_f]) {
                if ($hoje_ts >= $ts_i && $hoje_ts <= $ts_f) { $agora = $ts_f; break; }
            }

            if ($agora !== null) {
                $base['restam']  = (int)round(($agora - $hoje_ts) / 86400);
                $ferias_agora[]  = $base;
                $ids_foto[$tid]  = true;
            } else {
                // 2) Começa nos próximos 7 dias? (menor início futuro)
                $prox = null;
                foreach ($periodos as [$ts_i, $ts_f]) {
                    if ($ts_i > $hoje_ts && ($prox === null || $ts_i < $prox)) $prox = $ts_i;
                }
                if ($prox !== null) {
                    $dias_ate = (int)round(($prox - $hoje_ts) / 86400);
                    if ($dias_ate > 0 && $dias_ate <= 7) {
                        $base['dias'] = $dias_ate;
                        $ferias_proximas[] = $base;
                        $ids_foto[$tid]    = true;
                    }
                }
            }
        }
    }
}
// Mais próximos primeiro
usort($aniversariantes, fn($a, $b) => $a['dias'] <=> $b['dias']);
usort($ferias_proximas, fn($a, $b) => $a['dias'] <=> $b['dias']);
usort($ferias_agora,    fn($a, $b) => $a['restam'] <=> $b['restam']);

// Fotos: só dos técnicos que realmente aparecem no painel
$fotos_b64 = [];
if ($ids_foto) {
    $lista = implode(',', array_map('intval', array_keys($ids_foto)));
    $resF  = $conn->query("SELECT id, foto FROM tecnico WHERE id IN ($lista) AND foto IS NOT NULL");
    if ($resF) {
        while ($rf = $resF->fetch_assoc()) {
            if (!empty($rf['foto'])) $fotos_b64[(int)$rf['id']] = base64_encode($rf['foto']);
        }
    }
}

$tem_equipe = $aniversariantes || $ferias_agora || $ferias_proximas;

/** Avatar: foto do técnico ou círculo com as iniciais */
function ec_avatar(array $p, array $fotos, string $cls = 'eq-avatar'): string {
    $id = $p['id'] ?? 0;
    if (!empty($fotos[$id])) {
        return '<img class="' . $cls . '" src="data:image/jpeg;base64,' . $fotos[$id] . '" alt="">';
    }
    $n  = preg_split('/\s+/', trim($p['nome'] ?? ''));
    $in = mb_strtoupper(mb_substr($n[0] ?? '', 0, 1) . (count($n) > 1 ? mb_substr($n[count($n) - 1], 0, 1) : ''));
    return '<div class="' . $cls . ' ' . $cls . '-txt">' . htmlspecialchars($in) . '</div>';
}

$conn->close();

$data = date('d/m/Y');
$hora = date('H:i:s');

// Helpers
function prio_class($p) {
    return match($p) { 'ALTA'=>'crit-alta','MEDIA'=>'crit-media','BAIXA'=>'crit-baixa', default=>'crit-media' };
}
function status_label($s) {
    return match($s) {
        'ABERTO'              => 'Aberto',
        'EM_ATENDIMENTO'      => 'Em Atendimento',
        'EM_MANUTENCAO_EXTERNA'=>'Manutenção Ext.',
        default               => $s
    };
}
function dias_aberto($dt) {
    $diff = (new DateTime())->diff(new DateTime($dt));
    $d = $diff->days;
    if ($d === 0) return 'Aberto hoje';
    if ($d === 1) return 'Aberto há 1 dia';
    return "Aberto há {$d} dias";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Engenharia Clínica — Rede Hospitalar</title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="shortcut icon" href="logo_1.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --bg-page:#0f0f0f; --bg-sidebar:#141414; --bg-card:#1a1a1a;
  --bg-card-hover:#1f1f1f; --bg-input:#222;
  --border:rgba(255,255,255,0.07); --border-hover:rgba(255,255,255,0.14);
  --accent-steel:#a0aec0; --text-primary:#f0f0f0;
  --text-secondary:#888; --text-muted:#555;
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
#sidebar.collapsed .nav-item[data-tooltip="Abertura de Chamado"]::before  { content:"\f46d"; }
#sidebar.collapsed .nav-item[data-tooltip="Cadastro de Equipamento"]::before { content:"\f0fe"; }
#sidebar.collapsed .nav-item[data-tooltip="Planilha"]::before             { content:"\f0ce"; }
#sidebar.collapsed .nav-item[data-tooltip="Ordem de Serviço"]::before     { content:"\f46d"; }
#sidebar.collapsed .nav-item[data-tooltip="Estoque"]::before              { content:"\f49e"; }
#sidebar.collapsed .nav-item[data-tooltip="Movimentar"]::before           { content:"\f362"; }
#sidebar.collapsed .nav-item[data-tooltip="Página Inicial"]::before       { content:"\f200"; }
#sidebar.collapsed .nav-item[data-tooltip="Sair"]::before                 { content:"\f2f5"; }

#main{margin-left:var(--sidebar-w);transition:margin-left var(--transition);min-height:calc(100vh - 42px);display:flex;flex-direction:column;flex:1}
#main.sidebar-collapsed{margin-left:var(--sidebar-collapsed)}
.topbar{height:var(--header-h);background:rgba(20,20,20,0.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:10px;position:sticky;top:0;z-index:50}
.topbar-breadcrumb{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted)}
.topbar-breadcrumb span:last-child{color:var(--text-primary);font-weight:500}
.topbar-breadcrumb i{font-size:10px}
.topbar-spacer{flex:1}
.topbar-logo-rede{height:32px;width:auto;object-fit:contain;opacity:.75;transition:opacity var(--transition);flex-shrink:0}
.topbar-logo-rede:hover{opacity:1}
.topbar-btn{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid var(--border);background:#1c1c1c;color:var(--text-secondary);cursor:pointer;transition:background var(--transition),color var(--transition),border-color var(--transition);font-size:13px;position:relative;flex-shrink:0}
.topbar-btn:hover{background:#2a2a2a;color:var(--text-primary);border-color:var(--border-hover)}
.topbar-btn .notif-dot{position:absolute;top:5px;right:5px;width:6px;height:6px;border-radius:50%;background:var(--status-warn);border:1.5px solid var(--bg-page)}
.topbar-btn.ativo{background:#2a2a2a;color:var(--text-primary);border-color:var(--border-hover)}

.content{flex:1;padding:28px;width:100%}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
.page-title{font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--text-primary);letter-spacing:-.01em}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none}
.btn-primary{background:#232323;border:1px solid rgba(255,255,255,0.13);color:var(--text-primary)}
.btn-primary:hover{background:#2e2e2e;border-color:rgba(255,255,255,0.2)}

/* KPI */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px}
.kpi-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px 20px;transition:border-color var(--transition),background var(--transition);cursor:default}
.kpi-card:hover{border-color:var(--border-hover);background:var(--bg-card-hover)}
.kpi-label{font-size:11px;font-weight:500;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted);margin-bottom:10px;display:flex;align-items:center;gap:7px}
.kpi-label i{font-size:12px}
.kpi-value{font-family:var(--font-display);font-size:30px;font-weight:700;color:var(--text-primary);line-height:1;letter-spacing:-.02em}
.kpi-delta{font-size:11px;margin-top:7px;display:flex;align-items:center;gap:4px}
.kpi-delta.up{color:var(--status-ok)}
.kpi-delta.down{color:var(--status-err)}
.kpi-delta.neutral{color:var(--text-muted)}
.kpi-delta.warn{color:var(--status-warn)}

/* GRID */
.grid-main{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.panel{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;transition:border-color var(--transition)}
.panel:hover{border-color:var(--border-hover)}
.panel-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.panel-title{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--accent-steel);flex:1}
.panel-count{background:rgba(160,174,192,.12);color:var(--accent-steel);font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px}
.panel-action{font-size:11px;color:var(--text-muted);cursor:pointer;transition:color var(--transition);text-decoration:none}
.panel-action:hover{color:var(--text-primary)}
.panel-body{padding:0}

/* CHAMADOS LIST */
.chamado-list{display:flex;flex-direction:column;max-height:420px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.chamado-row{display:flex;align-items:flex-start;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border);transition:background var(--transition);text-decoration:none;color:inherit}
.chamado-row:last-child{border-bottom:none}
.chamado-row:hover{background:rgba(255,255,255,.025)}
.chamado-num{font-family:monospace;font-size:10px;font-weight:700;color:var(--text-muted);padding-top:2px;flex-shrink:0;min-width:70px}
.chamado-info{flex:1;min-width:0}
.chamado-title{font-size:13px;font-weight:500;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chamado-sub{font-size:11px;color:var(--text-muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chamado-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}
.crit-badge{font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;text-transform:uppercase;flex-shrink:0}
.crit-alta  {background:rgba(248,113,113,.1);color:#f87171;border:1px solid rgba(248,113,113,.2)}
.crit-media {background:rgba(250,204,21,.1);color:#facc15;border:1px solid rgba(250,204,21,.2)}
.crit-baixa {background:rgba(74,222,128,.1);color:#4ade80;border:1px solid rgba(74,222,128,.2)}
.status-badge{font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;text-transform:uppercase;white-space:nowrap}
.st-aberto{background:rgba(160,174,192,.1);color:#a0aec0;border:1px solid rgba(160,174,192,.2)}
.st-em-atendimento{background:rgba(250,204,21,.1);color:#facc15;border:1px solid rgba(250,204,21,.2)}
.st-manut-ext{background:rgba(251,146,60,.1);color:#fb923c;border:1px solid rgba(251,146,60,.2)}
.chamado-empty{padding:36px 18px;text-align:center;color:var(--text-muted);font-size:13px}
.chamado-empty i{font-size:26px;display:block;margin-bottom:10px;opacity:.35}

/* Setores */
.panel-body-pad{padding:14px 18px}
.setor-item{margin-bottom:12px}
.setor-item:last-child{margin-bottom:0}
.setor-label{display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px}
.setor-label span:first-child{color:var(--text-secondary)}
.setor-label span:last-child{color:var(--text-muted);font-size:11px}
.setor-bar{height:5px;background:rgba(255,255,255,0.06);border-radius:4px;overflow:hidden}
.setor-fill{height:100%;border-radius:4px;transition:width .8s cubic-bezier(.4,0,.2,1)}

.footer{background:#181818;color:#888;border-top:1px solid var(--border);padding:12px 20px;display:flex;justify-content:space-between;flex-wrap:wrap;font-size:13px;gap:6px;margin-left:var(--sidebar-w);transition:margin-left var(--transition)}
.footer div{color:#666}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeUp .35s ease both}
.delay-1{animation-delay:.05s}
.delay-2{animation-delay:.10s}
.delay-3{animation-delay:.15s}
::-webkit-scrollbar{width:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.07);border-radius:4px}

/* ── CAIXA DE FERRAMENTAS ── */
.tools-panel{position:fixed;top:var(--header-h);right:-280px;width:260px;height:calc(100vh - var(--header-h));background:var(--bg-sidebar);border-left:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:right var(--transition);box-shadow:-4px 0 24px rgba(0,0,0,.4)}
.tools-panel.open{right:0}
.tools-header{padding:16px 18px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.tools-title{font-family:var(--font-display);font-size:13px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--accent-steel)}
.tools-close{width:26px;height:26px;display:flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid var(--border);background:none;color:var(--text-muted);cursor:pointer;font-size:13px;transition:background var(--transition),color var(--transition)}
.tools-close:hover{background:#2a2a2a;color:var(--text-primary)}
.tools-section-label{font-size:9.5px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);padding:12px 18px 6px}
.tools-body{flex:1;overflow-y:auto;padding:8px 10px;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.tool-btn{display:flex;align-items:center;gap:12px;width:100%;padding:11px 14px;margin:3px 0;border-radius:6px;border:none;background:#1e2025;color:#bfc0c2;font-family:var(--font-ui);font-size:13px;font-weight:400;cursor:pointer;text-align:left;text-decoration:none;transition:background var(--transition),color var(--transition),transform var(--transition);letter-spacing:.01em}
.tool-btn:hover{background:#26282d;color:#e8e9eb;transform:translateX(4px)}
.tool-btn i{width:16px;text-align:center;font-size:13px;color:var(--text-muted);flex-shrink:0}
.tool-btn:hover i{color:var(--accent-steel)}
.tool-btn.em-breve{opacity:.45;cursor:not-allowed}
.tool-btn.em-breve:hover{transform:none;background:#1e2025;color:#bfc0c2}

/* ── EQUIPE: aniversários e férias ── */
.equipe-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:18px}
.eq-list{display:flex;flex-direction:column}
.eq-row{display:flex;align-items:center;gap:12px;padding:11px 18px;border-bottom:1px solid var(--border)}
.eq-row:last-child{border-bottom:none}
.eq-avatar{width:38px;height:38px;border-radius:50%;object-fit:cover;background:#2a2a2a;border:1px solid var(--border-hover);flex-shrink:0}
.eq-avatar-txt{display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--accent-steel);letter-spacing:.02em}
.eq-info{flex:1;min-width:0}
.eq-nome{font-size:13px;font-weight:500;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.eq-sub{font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.eq-tag{font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;flex-shrink:0;letter-spacing:.02em}
.eq-tag-hoje{background:rgba(74,222,128,.14);color:#4ade80;border:1px solid rgba(74,222,128,.3)}
.eq-tag-prox{background:rgba(160,174,192,.12);color:var(--accent-steel);border:1px solid rgba(160,174,192,.22)}
.eq-tag-ferias{background:rgba(96,165,250,.13);color:#60a5fa;border:1px solid rgba(96,165,250,.28)}
.eq-tag-warn{background:rgba(250,204,21,.12);color:#facc15;border:1px solid rgba(250,204,21,.26)}
/* Faixa de parabéns do dia */
.eq-parabens{display:flex;align-items:center;gap:12px;margin:0 14px 10px;padding:12px 16px;border-radius:10px;background:linear-gradient(135deg,rgba(74,222,128,.11),rgba(74,222,128,.03));border:1px solid rgba(74,222,128,.26)}
.eq-parabens i{font-size:17px;color:#4ade80;flex-shrink:0}
.eq-parabens-txt{font-size:12.5px;color:var(--text-primary);line-height:1.55}
.eq-parabens-txt strong{color:#4ade80}
.eq-vazio{padding:22px 18px;text-align:center;color:var(--text-muted);font-size:12px}
.eq-vazio i{display:block;font-size:22px;margin-bottom:8px;opacity:.35}
.eq-cnt{font-size:10px;font-weight:700;background:rgba(255,255,255,.07);border:1px solid var(--border);color:var(--text-secondary);border-radius:20px;padding:1px 8px;margin-left:8px}
.eq-sec-label{font-size:9.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);padding:12px 18px 5px}
/* ── Manutenções preventivas agendadas ── */
.pv-cnt{font-size:10px;font-weight:700;padding:2px 9px;border-radius:20px;white-space:nowrap;margin-left:6px}
.pv-cnt-err{background:rgba(248,113,113,.14);color:#f87171;border:1px solid rgba(248,113,113,.3)}
.pv-cnt-warn{background:rgba(250,204,21,.12);color:#facc15;border:1px solid rgba(250,204,21,.3)}
/* A altura é ajustada por JS para caber exatamente 3 itens (eles têm
   alturas diferentes conforme a quantidade de tags/peças). O valor abaixo
   é só o fallback até o script rodar. */
.pv-lista{max-height:330px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.pv-lista::-webkit-scrollbar{width:6px}
.pv-lista::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:4px}
.pv-item{display:flex;gap:15px;padding:13px 18px;border-bottom:1px solid var(--border);align-items:flex-start}
.pv-item:last-child{border-bottom:none}
.pv-data{width:64px;flex-shrink:0;text-align:center;padding:6px 4px;border-radius:8px;background:rgba(255,255,255,.03);border:1px solid var(--border)}
.pv-atrasada .pv-data{background:rgba(248,113,113,.1);border-color:rgba(248,113,113,.28)}
.pv-proxima  .pv-data{background:rgba(250,204,21,.09);border-color:rgba(250,204,21,.26)}
.pv-dia{font-family:var(--font-display);font-size:15px;font-weight:700;line-height:1.1}
.pv-atrasada .pv-dia{color:#f87171} .pv-proxima .pv-dia{color:#facc15}
.pv-ano{font-size:10px;color:var(--text-muted)}
.pv-prazo{font-size:9.5px;color:var(--text-muted);margin-top:3px;white-space:nowrap}
.pv-corpo{flex:1;min-width:0}
.pv-nome{font-size:13.5px;font-weight:500;color:var(--text-primary);margin-bottom:4px}
.pv-meta{display:flex;gap:13px;flex-wrap:wrap;font-size:11px;color:var(--text-muted);margin-bottom:3px}
.pv-meta span{display:inline-flex;align-items:center;gap:5px}
.pv-meta i{font-size:9.5px;opacity:.7}
.pv-pecas{font-size:11px;color:var(--text-secondary);margin-top:6px;padding-top:6px;border-top:1px dashed var(--border);display:flex;align-items:flex-start;gap:7px}
.pv-pecas i{color:var(--accent-steel);font-size:10px;margin-top:2px}
.pv-pecas strong{color:var(--text-primary)}
.pv-link{font-size:11px;color:var(--accent-steel);text-decoration:none;white-space:nowrap;flex-shrink:0;display:inline-flex;align-items:center;gap:5px}
.pv-link:hover{color:var(--text-primary)}
.pv-btn-agenda{display:inline-flex;align-items:center;gap:7px;margin-left:8px;padding:6px 12px;border-radius:8px;background:#232323;border:1px solid rgba(255,255,255,.12);color:var(--text-primary);font-size:11.5px;font-weight:500;text-decoration:none;white-space:nowrap;transition:background var(--transition),border-color var(--transition)}
.pv-btn-agenda:hover{background:#2e2e2e;border-color:var(--border-hover)}
.pv-btn-agenda i{font-size:10.5px;color:var(--accent-steel)}
@media(max-width:640px){.pv-item{flex-wrap:wrap}.pv-link{width:100%}}

/* Botão que recolhe/expande férias e aniversários */
.equipe-toggle{display:flex;align-items:center;gap:11px;width:100%;margin-top:18px;padding:14px 18px;border-radius:var(--radius-lg);background:var(--bg-card);border:1px solid var(--border);color:var(--text-primary);font-family:var(--font-ui);font-size:13px;font-weight:500;cursor:pointer;transition:border-color var(--transition),background var(--transition);text-align:left}
.equipe-toggle:hover{border-color:var(--border-hover);background:var(--bg-card-hover)}
.equipe-toggle > i:first-child{color:var(--accent-steel);font-size:14px}
.equipe-toggle.aberto{border-bottom-left-radius:0;border-bottom-right-radius:0}
.equipe-toggle.aberto #iconEquipe{transform:rotate(180deg)}
#iconEquipe{transition:transform var(--transition)}

@media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.grid-main{grid-template-columns:1fr}.content{padding:16px}.topbar-logo-rede{display:none}.footer,#main{margin-left:var(--sidebar-collapsed)}#sidebar{width:var(--sidebar-collapsed)}#sidebar.open{width:var(--sidebar-w)}}
@media(max-width:640px){#sidebar{position:fixed;top:0;left:0;height:100vh;z-index:1100;transform:translateX(-100%);transition:transform var(--transition);width:var(--sidebar-w)!important}#sidebar.open{transform:translateX(0)}.sidebar-toggle{display:none}.menu-toggle{display:block}#main{margin-left:0!important}.topbar{padding-left:54px}.kpi-grid{grid-template-columns:1fr 1fr}.content{padding:12px}.footer{margin-left:0}}
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
      <span>Página Inicial</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
      <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <div class="page-header fade-up">
      <div>
        <div class="page-title">Página Inicial</div>
        <div class="page-subtitle">Engenharia Clínica &middot; <?= eng_clin_data_pt() ?></div>
      </div>
    </div>

    <!-- KPIs -->
    <div class="kpi-grid fade-up delay-1">
      <div class="kpi-card">
        <div class="kpi-label"><i class="fas fa-folder-open"></i> Chamados Abertos</div>
        <div class="kpi-value"><?= $kpi['abertos'] ?></div>
        <div class="kpi-delta <?= $kpi['abertos'] > 0 ? 'warn' : 'neutral' ?>">
          <i class="fas <?= $kpi['abertos'] > 0 ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
          <?= $kpi['abertos'] > 0 ? 'Aguardando técnico' : 'Nenhum na fila' ?>
        </div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label"><i class="fas fa-screwdriver-wrench"></i> Pendências</div>
        <div class="kpi-value"><?= $kpi['em_atendimento'] ?></div>
        <div class="kpi-delta neutral"><i class="fas fa-circle"></i> OS iniciadas, não encerradas</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label"><i class="fas fa-truck"></i> Manutenção Externa</div>
        <div class="kpi-value"><?= $kpi['manut_externa'] ?></div>
        <div class="kpi-delta neutral"><i class="fas fa-circle"></i> Enviados a fornecedores</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label"><i class="fas fa-circle-check"></i> Concluídos no Mês</div>
        <div class="kpi-value"><?= $kpi['concluidos_mes'] ?></div>
        <div class="kpi-delta up"><i class="fas fa-check"></i> Este mês</div>
      </div>
    </div>

    <div class="grid-main fade-up delay-2">

      <!-- CHAMADOS ATIVOS -->
      <div class="panel">
        <div class="panel-header">
          <span class="panel-title">Chamados Prioritários</span>
          <span class="panel-count"><?= count($chamados_painel) ?></span>
          <a href="eng_clin_ordemdeservico.php" class="panel-action" style="margin-left:8px">Ver OS &rarr;</a>
        </div>
        <div class="chamado-list">
          <?php if (empty($chamados_painel)): ?>
            <div class="chamado-empty">
              <i class="fas fa-inbox"></i>
              Nenhum chamado ativo no momento.
            </div>
          <?php else: ?>
            <?php foreach ($chamados_painel as $ch):
              $pClass = prio_class($ch['criticidade']);
              $prioLabel = match($ch['criticidade']) { 'ALTA'=>'Alta','MEDIA'=>'Média','BAIXA'=>'Baixa', default=>'—' };
              $stLabel = status_label($ch['status']);
              $stClass = match($ch['status']) {
                'ABERTO'              => 'st-aberto',
                'EM_ATENDIMENTO'      => 'st-em-atendimento',
                'EM_MANUTENCAO_EXTERNA'=> 'st-manut-ext',
                default               => 'st-aberto'
              };
              $subInfo = $ch['setor_ocorrencia'] . ' · ' . dias_aberto($ch['data_chamado']);
            ?>
            <a href="eng_clin_ordemdeservico.php?chamado=<?= urlencode($ch['numero_chamado']) ?>"
               class="chamado-row" title="Abrir OS para este chamado">
              <div class="chamado-num"><?= htmlspecialchars($ch['numero_chamado']) ?></div>
              <div class="chamado-info">
                <div class="chamado-title">
                  <?= htmlspecialchars($ch['descricao_item'] ?: 'Item não especificado') ?>
                  — <?= htmlspecialchars($ch['unidade_ocorrencia']) ?>
                </div>
                <div class="chamado-sub"><?= htmlspecialchars($subInfo) ?> · <?= htmlspecialchars($ch['causa']) ?></div>
              </div>
              <div class="chamado-meta">
                <span class="crit-badge <?= $pClass ?>"><?= $prioLabel ?></span>
                <span class="status-badge <?= $stClass ?>"><?= $stLabel ?></span>
              </div>
            </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- PENDÊNCIAS POR MOTIVO — por que a fila não anda -->
      <div class="panel">
        <div class="panel-header">
          <span class="panel-title">Pendências por Motivo</span>
          <?php if ($kpi['em_atendimento']): ?>
          <span style="font-size:11px;font-weight:700;color:var(--status-warn);background:rgba(250,204,21,.12);border:1px solid rgba(250,204,21,.28);border-radius:20px;padding:2px 10px">
            <?= $kpi['em_atendimento'] ?> aberta(s)
          </span>
          <?php endif; ?>
        </div>
        <div class="panel-body-pad">
          <?php if (!$pend_motivo): ?>
          <div style="padding:26px 8px;text-align:center;color:var(--text-muted);font-size:12.5px">
            <i class="fas fa-circle-check" style="display:block;font-size:24px;margin-bottom:9px;opacity:.4;color:#4ade80"></i>
            Nenhuma pendência aberta.
          </div>
          <?php else: foreach ($pend_motivo as $pm):
            $lbl = $MOT_LBL[$pm['m']] ?? ($pm['m'] === 'SEM_MOTIVO' ? 'OS em andamento' : $pm['m']);
            $cor = $MOT_COR[$pm['m']] ?? '160,174,192';
            $pct = round((int)$pm['c'] / $max_pend * 100);
          ?>
          <div class="setor-item">
            <div class="setor-label">
              <span><?= htmlspecialchars($lbl) ?></span>
              <span><?= (int)$pm['c'] ?> OS</span>
            </div>
            <div class="setor-bar">
              <div class="setor-fill" style="width:<?= max(4,$pct) ?>%;background:rgba(<?= $cor ?>,.75)"></div>
            </div>
          </div>
          <?php endforeach; endif; ?>

          <a href="eng_clin_ordemdeservico.php?aba=pendentes"
             style="display:block;margin-top:14px;text-align:center;font-size:12px;color:var(--accent-steel);text-decoration:none">
            Ver todas as pendências <i class="fas fa-arrow-right" style="font-size:10px"></i>
          </a>
        </div>
      </div>

    </div>

    <!-- ══ MANUTENÇÕES PREVENTIVAS AGENDADAS ═══════════════════════════ -->
    <div class="panel fade-up delay-2" style="margin-top:18px<?= $pv_atrasadas ? ';border-color:rgba(248,113,113,.3)' : '' ?>">
      <div class="panel-header">
        <span class="panel-title"><i class="fas fa-calendar-check" style="margin-right:7px;font-size:11px"></i>Manutenções Preventivas Agendadas</span>
        <?php if ($pv_atrasadas): ?>
        <span class="pv-cnt pv-cnt-err"><?= $pv_atrasadas ?> atrasada(s)</span>
        <?php endif; ?>
        <?php if ($pv_semana): ?>
        <span class="pv-cnt pv-cnt-warn"><?= $pv_semana ?> manutenç<?= $pv_semana === 1 ? 'ão' : 'ões' ?> de revisão para essa semana</span>
        <?php endif; ?>
        <a href="eng_clin_preventivas.php" class="pv-btn-agenda">
          <i class="fas fa-calendar-days"></i> Ver agenda completa
        </a>
      </div>
      <?php if (!$preventivas): ?>
      <div class="eq-vazio">
        <i class="fas fa-calendar-check"></i>
        Nenhuma manutenção preventiva agendada.
      </div>
      <?php else: ?>
      <div class="pv-lista" id="pvLista">
        <?php foreach ($preventivas as $pv): ?>
        <div class="pv-item pv-<?= $pv['sit'] ?>">
          <div class="pv-data">
            <div class="pv-dia"><?= date('d/m', strtotime($pv['proxima_preventiva'])) ?></div>
            <div class="pv-ano"><?= date('Y', strtotime($pv['proxima_preventiva'])) ?></div>
            <div class="pv-prazo">
              <?php if ($pv['dias'] < 0): ?><?= abs($pv['dias']) ?>d atrás
              <?php elseif ($pv['dias'] === 0): ?>hoje
              <?php else: ?>em <?= $pv['dias'] ?>d<?php endif; ?>
            </div>
          </div>
          <div class="pv-corpo">
            <div class="pv-nome"><?= htmlspecialchars($pv['descricao'] ?: 'Equipamento não identificado') ?></div>
            <div class="pv-meta">
              <?php if ($pv['marca'] || $pv['modelo']): ?>
              <span><i class="fas fa-industry"></i><?= htmlspecialchars(trim(($pv['marca'] ?: '—').' / '.($pv['modelo'] ?: '—'))) ?></span>
              <?php endif; ?>
              <?php if ($pv['serie']): ?><span><i class="fas fa-barcode"></i><?= htmlspecialchars($pv['serie']) ?></span><?php endif; ?>
              <?php if ($pv['tag_antiga']): ?><span><i class="fas fa-tag"></i>Tag 1: <?= htmlspecialchars($pv['tag_antiga']) ?></span><?php endif; ?>
              <?php if ($pv['tag_trocada']): ?><span><i class="fas fa-tag"></i>Tag 2: <?= htmlspecialchars($pv['tag_trocada']) ?></span><?php endif; ?>
            </div>
            <div class="pv-meta">
              <span><i class="fas fa-hospital"></i><?= htmlspecialchars($pv['loc_unidade'] ?: '—') ?></span>
              <span><i class="fas fa-location-dot"></i><?= htmlspecialchars($pv['loc_setor'] ?: '—') ?><?= $pv['loc_area'] ? ' / '.htmlspecialchars($pv['loc_area']) : '' ?></span>
              <?php if (!empty($pv['periodicidade_meses'])): ?>
              <span><i class="fas fa-repeat"></i>a cada <?= (int)$pv['periodicidade_meses'] ?> meses</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($pv['pecas_trocadas'])): ?>
            <div class="pv-pecas">
              <i class="fas fa-screwdriver-wrench"></i>
              Trocado na anterior: <strong><?= htmlspecialchars($pv['pecas_trocadas']) ?></strong>
            </div>
            <?php endif; ?>
          </div>
          <?php if (!empty($pv['numero_chamado'])): ?>
          <a href="eng_clin_os.php?protocolo=<?= urlencode($pv['numero_chamado']) ?>" class="pv-link" title="Ver a OS anterior">
            <?= htmlspecialchars($pv['numero_chamado']) ?> <i class="fas fa-chevron-right"></i>
          </a>
          <?php else: ?>
          <a href="eng_clin_preventivas.php" class="pv-link" title="Abrir agenda">
            Manual <i class="fas fa-chevron-right"></i>
          </a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ EQUIPE: ANIVERSÁRIOS E FÉRIAS (recolhido) ═══════════════════ -->
    <button type="button" class="equipe-toggle fade-up delay-2" onclick="alternarEquipe()" id="btnEquipe">
      <i class="fas fa-cake-candles"></i>
      <span>Férias e Aniversários</span>
      <?php $tot_eq = count($aniversariantes) + count($ferias_agora) + count($ferias_proximas); ?>
      <?php if ($tot_eq): ?><span class="eq-cnt"><?= $tot_eq ?></span><?php endif; ?>
      <i class="fas fa-chevron-down" id="iconEquipe" style="margin-left:auto;font-size:11px"></i>
    </button>

    <div class="equipe-grid" id="painelEquipe" style="display:none">

      <!-- ── Aniversariantes da semana ── -->
      <div class="panel">
        <div class="panel-header">
          <span class="panel-title"><i class="fas fa-cake-candles" style="margin-right:7px;font-size:11px"></i>Aniversariantes da Semana</span>
          <?php if ($aniversariantes): ?><span class="eq-cnt"><?= count($aniversariantes) ?></span><?php endif; ?>
        </div>

        <?php
        // Mensagem de parabéns para quem faz aniversário hoje
        $hoje_aniv = array_values(array_filter($aniversariantes, fn($a) => $a['hoje']));
        if ($hoje_aniv):
            $nomes = array_map(fn($a) => explode(' ', $a['nome'])[0], $hoje_aniv);
            if (count($nomes) === 1)      $lista_nomes = $nomes[0];
            elseif (count($nomes) === 2)  $lista_nomes = $nomes[0] . ' e ' . $nomes[1];
            else                          $lista_nomes = implode(', ', array_slice($nomes, 0, -1)) . ' e ' . end($nomes);
        ?>
        <div class="eq-parabens" style="margin-top:12px">
          <i class="fas fa-champagne-glasses"></i>
          <div class="eq-parabens-txt">
            Parabéns, <strong><?= htmlspecialchars($lista_nomes) ?></strong>!<br>
            A equipe da Engenharia Clínica deseja muitas felicidades e um ótimo dia. 🎉
          </div>
        </div>
        <?php endif; ?>

        <div class="eq-list">
          <?php if (!$aniversariantes): ?>
          <div class="eq-vazio">
            <i class="fas fa-cake-candles"></i>
            Nenhum aniversário nos próximos 7 dias.
          </div>
          <?php else: foreach ($aniversariantes as $a): ?>
          <div class="eq-row">
            <?= ec_avatar($a, $fotos_b64) ?>
            <div class="eq-info">
              <div class="eq-nome"><?= htmlspecialchars($a['nome']) ?></div>
              <div class="eq-sub">
                <?= htmlspecialchars($a['funcao'] ?: '—') ?>
                <?php if ($a['unidade']): ?>&middot; <?= htmlspecialchars($a['unidade']) ?><?php endif; ?>
              </div>
            </div>
            <span class="eq-tag <?= $a['hoje'] ? 'eq-tag-hoje' : 'eq-tag-prox' ?>">
              <?php if ($a['hoje']): ?>
                🎂 Hoje<?= $a['idade'] ? ' &middot; ' . $a['idade'] . ' anos' : '' ?>
              <?php else: ?>
                <?= $a['dia'] ?> &middot; <?= $a['dias'] === 1 ? 'amanhã' : 'em ' . $a['dias'] . ' dias' ?>
              <?php endif; ?>
            </span>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- ── Férias ── -->
      <div class="panel">
        <div class="panel-header">
          <span class="panel-title"><i class="fas fa-umbrella-beach" style="margin-right:7px;font-size:11px"></i>Férias da Equipe</span>
          <?php $tot_fer = count($ferias_agora) + count($ferias_proximas); ?>
          <?php if ($tot_fer): ?><span class="eq-cnt"><?= $tot_fer ?></span><?php endif; ?>
        </div>

        <?php if (!$ferias_agora && !$ferias_proximas): ?>
        <div class="eq-vazio">
          <i class="fas fa-umbrella-beach"></i>
          Ninguém de férias e nenhuma programada para os próximos 7 dias.
        </div>
        <?php else: ?>

          <?php if ($ferias_agora): ?>
          <div class="eq-sec-label">De férias agora</div>
          <div class="eq-list">
            <?php foreach ($ferias_agora as $f): ?>
            <div class="eq-row">
              <?= ec_avatar($f, $fotos_b64) ?>
              <div class="eq-info">
                <div class="eq-nome"><?= htmlspecialchars($f['nome']) ?></div>
                <div class="eq-sub"><?= htmlspecialchars($f['ini']) ?> &rarr; <?= htmlspecialchars($f['fim']) ?></div>
              </div>
              <span class="eq-tag eq-tag-ferias">
                <?php if ($f['restam'] === 0): ?>Último dia
                <?php elseif ($f['restam'] === 1): ?>Volta amanhã
                <?php else: ?>Restam <?= $f['restam'] ?> dias<?php endif; ?>
              </span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if ($ferias_proximas): ?>
          <div class="eq-sec-label">Entram de férias em breve</div>
          <div class="eq-list">
            <?php foreach ($ferias_proximas as $f): ?>
            <div class="eq-row">
              <?= ec_avatar($f, $fotos_b64) ?>
              <div class="eq-info">
                <div class="eq-nome"><?= htmlspecialchars($f['nome']) ?></div>
                <div class="eq-sub"><?= htmlspecialchars($f['ini']) ?> &rarr; <?= htmlspecialchars($f['fim']) ?></div>
              </div>
              <span class="eq-tag eq-tag-warn">
                <?= $f['dias'] === 1 ? 'Amanhã' : 'Em ' . $f['dias'] . ' dias' ?>
              </span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>

    </div>

  </div>
</div>

<div class="footer" id="pageFooter">
  <div>Usuário: <?= htmlspecialchars($usuario) ?></div>
  <div>Data: <?= $data ?> | Hora: <span id="hora"><?= $hora ?></span></div>
  <div>&copy; GK Soluções</div>
</div>

<!-- CAIXA DE FERRAMENTAS -->

<script>
setInterval(() => { document.getElementById('hora').innerText = new Date().toLocaleTimeString('pt-BR'); }, 1000);

/* Férias e aniversários: recolhido por padrão, lembra a escolha do usuário */
function alternarEquipe() {
  const p = document.getElementById('painelEquipe');
  const b = document.getElementById('btnEquipe');
  const abrir = p.style.display === 'none';
  p.style.display = abrir ? '' : 'none';
  b.classList.toggle('aberto', abrir);
  try { localStorage.setItem('ec_equipe_aberto', abrir ? '1' : '0'); } catch(e) {}
}
try {
  if (localStorage.getItem('ec_equipe_aberto') === '1') alternarEquipe();
} catch(e) {}

/* Mostra 3 preventivas e deixa o resto no scroll. Os itens têm alturas
   diferentes (tags, peças trocadas), então a altura é medida em vez de
   fixada em CSS. */
(function limitarPreventivas() {
  const lista = document.getElementById('pvLista');
  if (!lista) return;
  const itens = lista.querySelectorAll('.pv-item');
  if (itens.length <= 3) { lista.style.maxHeight = 'none'; return; }
  const topo = lista.getBoundingClientRect().top;
  const fim  = itens[2].getBoundingClientRect().bottom;
  lista.style.maxHeight = Math.round(fim - topo) + 'px';
})();

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

</script>
<?php eng_clin_menu_painel(true); ?>
</body>
</html>