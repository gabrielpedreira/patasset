<?php
/**
 * eng_clin_os_documento.php
 * ═══════════════════════════════════════════════════════════════════════════
 * Documento de encerramento da Ordem de Serviço.
 *
 * Layout próprio para impressão (o navegador gera o PDF), com todos os
 * registros do protocolo e os campos de assinatura.
 *
 * ?protocolo=CH-000001            → monta a partir dos dados atuais
 * ?protocolo=CH-000001&arquivo=1  → mostra o snapshot salvo no encerramento
 *
 * Por que snapshot: os dados podem mudar depois (item movimentado, peça
 * corrigida). O documento assinado precisa refletir o que valia no dia.
 * ═══════════════════════════════════════════════════════════════════════════
 */
// Quando incluído por eng_clin_os.php para arquivar o snapshot, reaproveita
// a sessão, a conexão e a autenticação de quem chamou — e não fecha o $conn,
// que o chamador ainda precisa.
$EC_DOC_EMBED = defined('EC_DOC_EMBED');

if (!$EC_DOC_EMBED) {
    session_start();
    mysqli_report(MYSQLI_REPORT_OFF);
    include 'conexao.php';
    if (!isset($_SESSION['usuario_logado'])) { header("Location: index.html"); exit(); }
}

$usuario = $_SESSION['usuario_logado'];
if (!$EC_DOC_EMBED) {
    $classe = ''; $status_u = 'ATIVO';
    $st = $conn->prepare("SELECT classe_usuario, status FROM usuarios WHERE usuario=?");
    if ($st) {
        $st->bind_param('s', $usuario); $st->execute();
        $r = $st->get_result();
        if ($r && ($x = $r->fetch_assoc())) {
            $classe = strtoupper(trim($x['classe_usuario'] ?? ''));
            $status_u = $x['status'] ?? 'ATIVO';
        }
        $st->close();
    }
    if ($status_u !== 'ATIVO' || !in_array($classe, ['DEV','ENGENHARIA CLINICA'])) {
        header("Location: acesso_bloqueado.html"); exit();
    }
}

date_default_timezone_set('America/Sao_Paulo');
$protocolo = trim($_GET['protocolo'] ?? '');
if ($protocolo === '') { http_response_code(400); exit('Protocolo não informado.'); }

/* ── Snapshot arquivado ──────────────────────────────────────────────── */
if (isset($_GET['arquivo']) && !$EC_DOC_EMBED) {
    $stA = $conn->prepare("SELECT documento_html FROM ordemservico_engclin WHERE numero_chamado=? LIMIT 1");
    if ($stA) {
        $stA->bind_param('s', $protocolo); $stA->execute();
        $rA = $stA->get_result(); $rowA = $rA ? $rA->fetch_assoc() : null;
        $stA->close();
        if ($rowA && !empty($rowA['documento_html'])) {
            $conn->close();
            echo $rowA['documento_html'];
            exit();
        }
    }
    // Sem snapshot: cai para a montagem ao vivo
}

/* ── Dados ───────────────────────────────────────────────────────────── */
if (!function_exists('um')):
function um(mysqli $c, string $sql, string $p) {
    $st = $c->prepare($sql);
    if (!$st) return null;
    $st->bind_param('s', $p); $st->execute();
    $r = $st->get_result(); $row = $r ? $r->fetch_assoc() : null;
    $st->close(); return $row;
}
function varios(mysqli $c, string $sql, string $p): array {
    $out = []; $st = $c->prepare($sql);
    if (!$st) return $out;
    $st->bind_param('s', $p); $st->execute();
    $r = $st->get_result();
    if ($r) while ($x = $r->fetch_assoc()) $out[] = $x;
    $st->close(); return $out;
}
endif;

$os = um($conn, "SELECT * FROM ordemservico_engclin WHERE numero_chamado=? LIMIT 1", $protocolo);
if (!$os) {
    if ($EC_DOC_EMBED) return;
    $conn->close(); http_response_code(404); exit('Ordem de serviço não encontrada.');
}

$ch  = um($conn, "SELECT * FROM chamado_engclin WHERE numero_chamado=? LIMIT 1", $protocolo) ?: [];
$ivs = varios($conn, "SELECT * FROM maodeobra_engclin WHERE numero_chamado=? ORDER BY id ASC", $protocolo);
$mts = varios($conn, "SELECT * FROM itens_os_engclin WHERE numero_chamado=? ORDER BY id ASC", $protocolo);
$ext = um($conn, "SELECT * FROM manutencao_externa_engclin WHERE numero_chamado=? ORDER BY id DESC LIMIT 1", $protocolo);
$evs = varios($conn, "SELECT tipo_evento, descricao_evento, nome_usuario, data_evento, hora_evento
                      FROM historico_eventos_engclin WHERE numero_chamado=? ORDER BY id ASC", $protocolo);

$item = null;
if (!empty($os['item_id'])) {
    $stI = $conn->prepare("SELECT descricao, marca, modelo, serie, tag_antiga, tag_trocada, unidade, setor FROM cadastro WHERE id=? LIMIT 1");
    if ($stI) {
        $iid = (int)$os['item_id'];
        $stI->bind_param('i', $iid); $stI->execute();
        $rI = $stI->get_result(); $item = $rI ? $rI->fetch_assoc() : null;
        $stI->close();
    }
}
if (!$EC_DOC_EMBED) $conn->close();

$ST_LBL = [
    'EM_ANDAMENTO'=>'Trabalhos em andamento','PROBLEMA_SOLUCIONADO'=>'Problema solucionado',
    'FALTA_DE_PECAS'=>'Aguardando peças','AGUARDANDO_ORCAMENTO'=>'Aguardando orçamento',
    'AGUARDANDO_PATRIMONIO'=>'Aguardando patrimônio','MANUTENCAO_TERCEIROS'=>'Manutenção externa',
    'OBSOLESCENCIA'=>'Obsoleto','SEM_SOLUCAO'=>'Sem solução','OUTROS'=>'Outros',
];
$OC_LBL = [
    'MANUTENCAO_CORRETIVA'=>'Manutenção corretiva','MANUTENCAO_PREVENTIVA'=>'Manutenção preventiva',
    'TREINAMENTO'=>'Treinamento / orientação','INSTALACAO'=>'Instalação / configuração',
    'AVALIACAO_TECNICA'=>'Avaliação técnica','SEM_DEFEITO'=>'Sem defeito constatado',
];
if (!function_exists('ecd')):
function ecd($v){ return $v ? date('d/m/Y', strtotime($v)) : '—'; }
function ech($v){ return $v ? substr((string)$v, 0, 5) : '—'; }
function ece($v){ return htmlspecialchars((string)$v); }
function ecrs($v){ return $v !== null && $v !== '' ? 'R$ '.number_format((float)$v, 2, ',', '.') : '—'; }
endif;

$total_mat = 0.0;
foreach ($mts as $m) $total_mat += (float)$m['quantidade_usada'] * (float)($m['valor_unitario'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>OS <?= ece($protocolo) ?> — Documento de Encerramento</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,Helvetica,sans-serif;background:#f0f0f0;color:#111;font-size:12px;line-height:1.5;padding:20px}
.folha{max-width:820px;margin:0 auto;background:#fff;padding:34px 40px;box-shadow:0 2px 12px rgba(0,0,0,.12)}
.topo{display:flex;align-items:center;gap:16px;border-bottom:3px solid #1d4ed8;padding-bottom:14px;margin-bottom:6px}
.topo img{height:46px;width:auto;object-fit:contain}
.topo-t{flex:1}
.topo-t h1{font-size:17px;color:#1d4ed8;letter-spacing:.5px}
.topo-t p{font-size:11px;color:#666;margin-top:2px}
.topo-p{text-align:right}
.topo-p .lbl{font-size:9px;color:#666;text-transform:uppercase;letter-spacing:1px}
.topo-p .num{font-size:19px;font-weight:bold;color:#1d4ed8}
.emissao{font-size:10px;color:#777;text-align:right;margin-bottom:18px}
h2{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#1d4ed8;background:#eff6ff;
   padding:6px 10px;margin:18px 0 9px;border-left:3px solid #1d4ed8}
table{width:100%;border-collapse:collapse;margin-bottom:4px}
td,th{padding:5px 8px;border:1px solid #d5d5d5;vertical-align:top;font-size:11.5px}
th{background:#f6f6f6;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:#444}
.k{background:#fafafa;font-weight:bold;width:24%;color:#333}
.bloco{border:1px solid #d5d5d5;padding:9px 11px;margin-bottom:9px;background:#fcfcfc}
.bloco-h{font-weight:bold;font-size:12px;margin-bottom:5px;color:#1d4ed8;
         display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.tag{font-size:9.5px;border:1px solid #bbb;border-radius:10px;padding:1px 8px;background:#fff;font-weight:normal;color:#555}
.tag-ok{border-color:#16a34a;color:#16a34a;background:#f0fdf4}
.tag-pend{border-color:#ca8a04;color:#ca8a04;background:#fefce8}
.campo{margin-top:6px}
.campo .cl{font-size:9.5px;text-transform:uppercase;letter-spacing:.4px;color:#777}
.campo .cv{font-size:11.5px;white-space:pre-wrap}
.assin{margin-top:44px;display:flex;gap:44px;page-break-inside:avoid}
.assin div{flex:1;text-align:center}
.assin .linha{border-top:1px solid #333;margin-bottom:5px;height:52px}
.assin .nome{font-size:11px;font-weight:bold}
.assin .sub{font-size:9.5px;color:#666;margin-top:2px}
.rodape{margin-top:26px;border-top:1px solid #ddd;padding-top:9px;font-size:9.5px;color:#888;
        display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
.acoes{max-width:820px;margin:0 auto 14px;display:flex;gap:9px;justify-content:flex-end}
.btn{padding:9px 17px;border-radius:6px;border:1px solid #ccc;background:#fff;color:#333;
     font-size:12.5px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px}
.btn-p{background:#1d4ed8;border-color:#1d4ed8;color:#fff}
.vazio{color:#999;font-style:italic;font-size:11px;padding:5px 0}
@media print{
  body{background:#fff;padding:0;font-size:11px}
  .folha{box-shadow:none;max-width:none;padding:12mm 14mm}
  .acoes{display:none}
  h2{background:#eff6ff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .bloco{page-break-inside:avoid}
}
</style>
</head>
<body>

<div class="acoes">
  <a href="eng_clin_os.php?protocolo=<?= urlencode($protocolo) ?>" class="btn">&larr; Voltar à OS</a>
  <button class="btn btn-p" onclick="window.print()">Imprimir / Salvar PDF</button>
</div>

<div class="folha">

  <div class="topo">
    <img src="lifetechoriginalclaro.png" alt="LifeTech">
    <div class="topo-t">
      <h1>Ordem de Serviço — Engenharia Clínica</h1>
      <p>Rede Hospitalar &middot; Documento de encerramento</p>
    </div>
    <div class="topo-p">
      <div class="lbl">Protocolo</div>
      <div class="num"><?= ece($protocolo) ?></div>
    </div>
  </div>
  <div class="emissao">Emitido em <?= date('d/m/Y \à\s H:i') ?> por <?= ece($usuario) ?></div>

  <!-- ── CHAMADO ── -->
  <h2>1. Dados do Chamado</h2>
  <table>
    <tr><td class="k">Solicitante</td><td><?= ece($ch['nome'] ?? '—') ?></td>
        <td class="k">Cargo</td><td><?= ece($ch['cargo'] ?? '—') ?></td></tr>
    <tr><td class="k">Abertura</td><td><?= ecd($ch['data_chamado'] ?? null) ?> às <?= ech($ch['hora_chamado'] ?? null) ?></td>
        <td class="k">Criticidade</td><td><?= ece($ch['criticidade'] ?? '—') ?></td></tr>
    <tr><td class="k">Unidade</td><td><?= ece($ch['unidade_ocorrencia'] ?? '—') ?></td>
        <td class="k">Setor / Área</td><td><?= ece($ch['setor_ocorrencia'] ?? '—') ?><?= !empty($ch['area_ocorrencia']) ? ' / '.ece($ch['area_ocorrencia']) : '' ?></td></tr>
    <tr><td class="k">Tipo de ocorrência</td><td><?= ece($ch['tipo_ocorrencia'] ?? '—') ?></td>
        <td class="k">Parada do equipamento</td><td><?= ecd($ch['data_parada'] ?? null) ?> <?= ech($ch['hora_parada'] ?? null) ?></td></tr>
    <tr><td class="k">Problema relatado</td><td colspan="3"><?= nl2br(ece($ch['causa'] ?? '—')) ?></td></tr>
    <?php if (!empty($ch['observacao'])): ?>
    <tr><td class="k">Observação</td><td colspan="3"><?= nl2br(ece($ch['observacao'])) ?></td></tr>
    <?php endif; ?>
  </table>

  <!-- ── EQUIPAMENTO ── -->
  <h2>2. Equipamento</h2>
  <table>
    <tr><td class="k">Descrição</td><td colspan="3"><?= ece($item['descricao'] ?? ($ch['descricao_item'] ?? '—')) ?></td></tr>
    <tr><td class="k">Marca / Modelo</td><td><?= ece($item['marca'] ?? '—') ?> / <?= ece($item['modelo'] ?? '—') ?></td>
        <td class="k">Nº de série</td><td><?= ece($item['serie'] ?? ($ch['numero_serie'] ?? '—')) ?></td></tr>
    <tr><td class="k">Tag 1</td><td><?= ece($item['tag_antiga'] ?? ($ch['tag_patrimonio'] ?? '—')) ?></td>
        <td class="k">Tag 2</td><td><?= ece($item['tag_trocada'] ?? '—') ?></td></tr>
    <tr><td class="k">Local de origem</td><td colspan="3">
      <?= ece($os['loc_orig_unidade'] ?: '—') ?> / <?= ece($os['loc_orig_setor'] ?: '—') ?><?= $os['loc_orig_area'] ? ' / '.ece($os['loc_orig_area']) : '' ?>
    </td></tr>
    <tr><td class="k">Item devolvido</td><td colspan="3">
      <?php if (($os['item_devolvido'] ?? '') === 'SIM'): ?>
        Sim — devolvido ao local de origem
      <?php elseif (($os['item_devolvido'] ?? '') === 'NAO'): ?>
        Não — permaneceu na localização atual
      <?php else: ?>—<?php endif; ?>
    </td></tr>
  </table>

  <!-- ── MÃO DE OBRA ── -->
  <h2>3. Mão de Obra e Serviços Executados</h2>
  <?php if (!$ivs): ?>
    <div class="vazio">Nenhuma intervenção registrada.</div>
  <?php else: foreach ($ivs as $i => $iv):
      $st_iv = $iv['status'] ?? 'EM_ANDAMENTO';
      $concl = in_array($st_iv, ['PROBLEMA_SOLUCIONADO','OBSOLESCENCIA','SEM_SOLUCAO'], true);
      $mat_iv = array_filter($mts, fn($m) => (int)($m['id_maodeobra'] ?? 0) === (int)$iv['id']);
  ?>
  <div class="bloco">
    <div class="bloco-h">
      <span><?= ($i+1) ?>. <?= ece($iv['nome_tecnico']) ?></span>
      <span class="tag <?= $concl ? 'tag-ok' : 'tag-pend' ?>"><?= ece($ST_LBL[$st_iv] ?? $st_iv) ?></span>
    </div>
    <table style="margin:6px 0 0">
      <tr><td class="k">Início</td><td><?= ecd($iv['data_inicio']) ?> às <?= ech($iv['hora_inicio']) ?></td>
          <td class="k">Término</td><td><?= ecd($iv['data_fim']) ?> às <?= ech($iv['hora_fim']) ?></td></tr>
      <tr><td class="k">Ocorrência</td><td colspan="3"><?= ece($OC_LBL[$iv['ocorrencia'] ?? ''] ?? ($iv['ocorrencia'] ?: '—')) ?></td></tr>
    </table>
    <div class="campo">
      <div class="cl">Serviço executado</div>
      <div class="cv"><?= nl2br(ece($iv['servico'] ?: '—')) ?></div>
    </div>
    <?php if ($mat_iv): ?>
    <table style="margin-top:8px">
      <thead><tr><th>Material utilizado</th><th style="width:80px">Origem</th><th style="width:60px">Qtd</th><th style="width:90px">Valor unit.</th></tr></thead>
      <tbody>
        <?php foreach ($mat_iv as $m): ?>
        <tr>
          <td><?= ece($m['nome_item']) ?></td>
          <td><?= ($m['origem'] ?? 'ESTOQUE') === 'REAPROVEITADO' ? 'Reaproveitada' : 'Estoque' ?></td>
          <td><?= (float)$m['quantidade_usada'] == floor((float)$m['quantidade_usada']) ? (int)$m['quantidade_usada'] : $m['quantidade_usada'] ?></td>
          <td><?= ecrs($m['valor_unitario']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>

  <?php if ($mts): ?>
  <table style="margin-top:4px">
    <tr><td class="k" style="width:auto">Custo total em materiais</td><td style="font-weight:bold"><?= ecrs($total_mat) ?></td></tr>
  </table>
  <?php endif; ?>

  <!-- ── MANUTENÇÃO EXTERNA ── -->
  <?php if ($ext): ?>
  <h2>4. <?= ($ext['tipo'] ?? 'ENVIO') === 'VISITA' ? 'Visita Técnica' : 'Manutenção Externa' ?></h2>
  <table>
    <tr><td class="k">Empresa</td><td><?= ece($ext['empresa'] ?? '—') ?></td>
        <td class="k">Situação</td><td><?= ece(str_replace('_',' ', $ext['status'] ?? '—')) ?></td></tr>
    <?php if (($ext['tipo'] ?? '') === 'VISITA'): ?>
    <tr><td class="k">Técnico da empresa</td><td><?= ece($ext['visita_tecnico'] ?? '—') ?></td>
        <td class="k">Data da visita</td><td><?= ecd($ext['visita_data'] ?? null) ?></td></tr>
    <tr><td class="k">Chegada</td><td><?= ech($ext['visita_chegada'] ?? null) ?></td>
        <td class="k">Saída</td><td><?= ech($ext['visita_saida'] ?? null) ?></td></tr>
    <tr><td class="k">Solução aplicada</td><td colspan="3"><?= nl2br(ece($ext['visita_solucao'] ?? '—')) ?></td></tr>
    <?php else: ?>
    <tr><td class="k">Saída do equipamento</td><td><?= ecd($ext['data_saida'] ?? null) ?> <?= ech($ext['hora_saida'] ?? null) ?></td>
        <td class="k">Retorno</td><td><?= ecd($ext['data_retorno'] ?? null) ?></td></tr>
    <tr><td class="k">Problema relatado</td><td colspan="3"><?= nl2br(ece($ext['problema'] ?? '—')) ?></td></tr>
    <?php endif; ?>
    <tr><td class="k">Orçamento</td><td><?= ($ext['orcamento'] ?? 'NAO') === 'SIM' ? 'Sim' : 'Não' ?></td>
        <td class="k">Valor</td><td><?= ecrs($ext['valor_orcamento'] ?? null) ?></td></tr>
  </table>
  <?php endif; ?>

  <!-- ── ENCERRAMENTO ── -->
  <h2><?= $ext ? '5' : '4' ?>. Encerramento</h2>
  <table>
    <tr><td class="k">Abertura da OS</td><td><?= ecd($os['data_abertura']) ?> às <?= ech($os['hora_abertura']) ?></td>
        <td class="k">Encerramento</td><td><?= ecd($os['data_fechamento']) ?> às <?= ech($os['hora_fechamento']) ?></td></tr>
    <tr><td class="k">Situação final</td><td><?= ece($ST_LBL[$os['motivo'] ?? ''] ?? ($os['motivo'] ?: 'Problema solucionado')) ?></td>
        <td class="k">Status</td><td><?= ($os['status'] ?? '') === 'ABERTA' ? 'EM ANDAMENTO' : 'CONCLUÍDA' ?></td></tr>
    <?php if (!empty($os['proxima_preventiva'])): ?>
    <tr><td class="k">Próxima preventiva</td><td colspan="3">
      <?= ecd($os['proxima_preventiva']) ?>
      <?= !empty($os['periodicidade_meses']) ? ' (a cada '.(int)$os['periodicidade_meses'].' meses)' : '' ?>
    </td></tr>
    <?php endif; ?>
  </table>

  <!-- ── HISTÓRICO ── -->
  <h2><?= $ext ? '6' : '5' ?>. Histórico do Protocolo</h2>
  <?php if (!$evs): ?>
    <div class="vazio">Sem eventos registrados.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:105px">Data / Hora</th><th style="width:130px">Evento</th><th>Descrição</th><th style="width:110px">Responsável</th></tr></thead>
    <tbody>
      <?php foreach ($evs as $ev): ?>
      <tr>
        <td style="white-space:nowrap"><?= ecd($ev['data_evento']) ?> <?= ech($ev['hora_evento']) ?></td>
        <td style="font-size:10px"><?= ece(str_replace('_',' ', $ev['tipo_evento'])) ?></td>
        <td><?= ece($ev['descricao_evento']) ?></td>
        <td><?= ece($ev['nome_usuario'] ?: '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- ── ASSINATURAS ── -->
  <div class="assin">
    <div>
      <div class="linha"></div>
      <div class="nome">Assinatura do Técnico</div>
      <div class="sub"><?= ece($os['nome_tecnico'] ?? '') ?></div>
    </div>
    <div>
      <div class="linha"></div>
      <div class="nome">Assinatura Engenheiro(a) Responsável</div>
      <div class="sub">Engenharia Clínica &middot; Rede Hospitalar</div>
    </div>
  </div>

  <div class="rodape">
    <span>Protocolo <?= ece($protocolo) ?> &middot; documento gerado pelo sistema LifeTech</span>
    <span>Rede Hospitalar</span>
  </div>

</div>
</body>
</html>
