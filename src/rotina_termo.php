<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();
if (!isset($_SESSION['usuario_logado'])) { header("Location: index.html"); exit(); }
require_once 'conexao.php';
require_once __DIR__ . '/config_seguro.php';   // credenciais de SMTP
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
require_once __DIR__ . '/vendor/phpmailer/Exception.php';

$unidade    = strtoupper(trim($_POST['unidade']    ?? ''));
$setor      = strtoupper(trim($_POST['setor']      ?? ''));
$pavimento  = strtoupper(trim($_POST['pavimento']  ?? ''));
$area       = strtoupper(trim($_POST['area']       ?? ''));
$coordenador= trim($_POST['coordenador'] ?? '');
$assistente = trim($_POST['assistente']  ?? '');
$data_str   = trim($_POST['data']        ?? date('Y-m-d'));
$itensRaw   = trim($_POST['itens']       ?? '[]');
$usuario    = $_SESSION['usuario_logado'] ?? '';
if (!$assistente) $assistente = $usuario;

$itens = json_decode($itensRaw, true);
if (!is_array($itens)) $itens = [];

$dataFmt = '';
if ($data_str) {
    $dt = DateTime::createFromFormat('Y-m-d', $data_str);
    $dataFmt = $dt ? $dt->format('d/m/Y') : $data_str;
}
$qtd = count($itens);

$emailOk = false;
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = PAT_SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = PAT_SMTP_USER;
    $mail->Password   = PAT_SMTP_PASS;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = PAT_SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(PAT_SMTP_USER, PAT_SMTP_NOME . ' — Patrimônio');
    $mail->addAddress(PAT_EMAIL_EQUIPE, 'Equipe Patrimônio');
    $mail->isHTML(true);
    $mail->Subject = "Termo de Responsabilidade — $unidade" . ($setor ? " / $setor" : '') . " — $dataFmt";

    $rows = '';
    foreach ($itens as $i => $it) {
        $bg = ($i % 2 === 0) ? '#eff6ff' : '#fff';
        $n  = $i + 1;
        $desc  = htmlspecialchars($it['descricao']          ?? '');
        $marca = htmlspecialchars(($it['marca']??'') . ($it['modelo'] ? '/'.$it['modelo'] : ''));
        $tag   = htmlspecialchars($it['tag_antiga']         ?? '');
        $serie = htmlspecialchars($it['serie']              ?? '');
        $rows .= "<tr style='background:$bg'><td style='padding:6px 10px;border-bottom:1px solid #e5e7eb;text-align:center'>$n</td><td style='padding:6px 10px;border-bottom:1px solid #e5e7eb'>$desc</td><td style='padding:6px 10px;border-bottom:1px solid #e5e7eb'>$marca</td><td style='padding:6px 10px;border-bottom:1px solid #e5e7eb'>$tag</td><td style='padding:6px 10px;border-bottom:1px solid #e5e7eb'>$serie</td></tr>";
    }

    $local = array_filter([$unidade, $setor, $area, $pavimento]);

    $mail->Body = "
<table width='640' cellpadding='0' cellspacing='0' style='font-family:Arial,sans-serif;margin:0 auto'>
  <tr><td style='background:#1d4ed8;padding:24px 30px;text-align:center'>
    <h2 style='margin:0;color:#fff;font-size:18px;letter-spacing:1px'>Sistema Patrimonial — PatAsset</h2>
    <p style='margin:4px 0 0;color:#bfdbfe;font-size:13px'>Rede Hospitalar</p>
  </td></tr>
  <tr><td style='padding:24px 30px;background:#fff'>
    <h3 style='margin:0 0 16px;font-size:15px;color:#1e3a8a'>Termo de Responsabilidade Patrimonial</h3>
    <table width='100%' style='font-size:13px;margin-bottom:20px'>
      <tr><td style='padding:4px 0;color:#6b7280;width:120px'>Local:</td><td style='font-weight:700'>".htmlspecialchars(implode(' / ',$local))."</td></tr>
      <tr><td style='padding:4px 0;color:#6b7280'>Coordenador:</td><td style='font-weight:700'>".htmlspecialchars($coordenador)."</td></tr>
      <tr><td style='padding:4px 0;color:#6b7280'>Data:</td><td style='font-weight:700'>$dataFmt</td></tr>
      <tr><td style='padding:4px 0;color:#6b7280'>Gerado por:</td><td style='font-weight:700'>".htmlspecialchars($usuario)."</td></tr>
      <tr><td style='padding:4px 0;color:#6b7280'>Itens:</td><td style='font-weight:700'>".count($itens)."</td></tr>
    </table>
    <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #e5e7eb;font-size:12px'>
      <thead><tr style='background:#1d4ed8'>
        <th style='padding:7px 10px;color:#fff;text-align:center'>#</th>
        <th style='padding:7px 10px;color:#fff;text-align:left'>Descrição</th>
        <th style='padding:7px 10px;color:#fff;text-align:left'>Marca/Modelo</th>
        <th style='padding:7px 10px;color:#fff;text-align:left'>Tag Patrimônio</th>
        <th style='padding:7px 10px;color:#fff;text-align:left'>Nº Série</th>
      </tr></thead>
      <tbody>$rows</tbody>
    </table>
  </td></tr>
  <tr><td style='background:#f9fafb;padding:14px 30px;text-align:center;border-top:1px solid #e5e7eb'>
    <p style='margin:0;font-size:11px;color:#9ca3af'>Equipe Patrimônio · Rede Hospitalar · Cópia interna gerada automaticamente</p>
  </td></tr>
</table>";

    $mail->send();
    $emailOk = true;
} catch (Exception $e) {
    // email falhou — não bloqueia impressão
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Termo de Responsabilidade Patrimonial</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;background:#f0f4f8;padding:24px;}
.page{background:#fff;max-width:800px;margin:0 auto;padding:40px 48px;box-shadow:0 4px 24px rgba(0,0,0,.15);}
.no-print{margin-bottom:16px;display:flex;gap:8px;}
.btn-p{padding:9px 22px;background:#1d4ed8;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;}
.btn-p:hover{background:#1e40af;}
.btn-s{padding:9px 22px;background:#6b7280;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;}

/* Documento */
.doc-header{text-align:center;margin-bottom:28px;padding-bottom:20px;border-bottom:2px solid #1d4ed8;}
.doc-logo{font-size:11px;color:#6b7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px;}
.doc-title{font-size:15px;font-weight:900;color:#1e3a8a;text-transform:uppercase;letter-spacing:.5px;line-height:1.4;margin-bottom:10px;}
.doc-local{font-size:12px;color:#374151;display:grid;grid-template-columns:1fr 1fr;gap:6px 24px;text-align:left;max-width:560px;margin:0 auto;}
.doc-local span{display:block;}
.doc-local strong{color:#1e3a8a;}

.section{margin-bottom:22px;}
.section-title{font-size:11px;font-weight:900;color:#1d4ed8;text-transform:uppercase;letter-spacing:.7px;border-bottom:1.5px solid #dbeafe;padding-bottom:5px;margin-bottom:10px;}
.section-text{font-size:11px;color:#374151;line-height:1.7;text-align:justify;}

/* Tabela de itens */
.items-table{width:100%;border-collapse:collapse;font-size:11px;margin-top:8px;}
.items-table th{background:#1d4ed8;color:#fff;padding:7px 9px;text-align:left;font-weight:700;}
.items-table th:first-child{text-align:center;width:36px;}
.items-table td{padding:6px 9px;border-bottom:1px solid #e5e7eb;vertical-align:top;}
.items-table td:first-child{text-align:center;color:#9ca3af;font-size:10px;}
.items-table tr:nth-child(even){background:#f0f7ff;}
.items-table tr:last-child td{border-bottom:none;}

/* Assinaturas */
.assinaturas{margin-top:28px;display:grid;grid-template-columns:1fr 1fr;gap:32px;}
.assinatura{text-align:center;}
.assinatura .linha{border-top:1px solid #374151;margin-bottom:6px;}
.assinatura .label{font-size:10px;color:#374151;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.assinatura .sub{font-size:10px;color:#6b7280;margin-top:2px;}

.doc-footer{margin-top:32px;padding-top:14px;border-top:1px solid #e5e7eb;font-size:10px;color:#9ca3af;text-align:center;}
.email-status{font-size:11px;color:<?= $emailOk ? '#15803d' : '#dc2626' ?>;margin-left:12px;align-self:center;}

@media print{
    body{background:#fff;padding:0;}
    .page{box-shadow:none;padding:20px 28px;max-width:none;}
    .no-print{display:none !important;}
}
</style>
</head>
<body>
<div class="no-print">
    <button class="btn-p" onclick="window.print()">Imprimir / Salvar PDF</button>
    <button class="btn-s" onclick="window.close()">Fechar</button>
    <span class="email-status"><?= $emailOk ? '✓ Cópia enviada por e-mail' : '⚠ Falha ao enviar e-mail (impressão disponível)' ?></span>
</div>

<div class="page">

    <!-- Cabeçalho -->
    <div class="doc-header">
        <div class="doc-logo">Rede Hospitalar — Sistema Patrimonial PatAsset</div>
        <div class="doc-title">Termo de Conferência e Responsabilidade<br>de Bens Patrimoniais</div>
        <div class="doc-local">
            <span><strong>Unidade:</strong> <?= htmlspecialchars($unidade ?: '—') ?></span>
            <span><strong>Setor:</strong> <?= htmlspecialchars($setor ?: '—') ?></span>
            <?php if ($pavimento): ?><span><strong>Pavimento:</strong> <?= htmlspecialchars($pavimento) ?></span><?php endif; ?>
            <span><strong>Área / Local:</strong> <?= htmlspecialchars($area ?: '—') ?></span>
            <span><strong>Data da Conferência:</strong> <?= htmlspecialchars($dataFmt) ?></span>
            <span><strong>Total de Itens:</strong> <?= $qtd ?></span>
        </div>
    </div>

    <!-- I — Declaração da Equipe -->
    <div class="section">
        <div class="section-title">I — Declaração da Equipe de Patrimônio</div>
        <p class="section-text">
            Declaro, para os devidos fins, que foi realizada a conferência patrimonial no setor acima identificado,
            conforme rotina do Setor de Patrimônio. Durante a conferência foram localizados, identificados e
            registrados os bens patrimoniais relacionados neste documento, existentes no setor na data da vistoria.
        </p>
        <?php if ($coordenador): ?>
        <p class="section-text" style="margin-top:8px">
            <strong>Responsável pela inspeção:</strong> <?= htmlspecialchars($coordenador) ?>
        </p>
        <?php endif; ?>
    </div>

    <!-- II — Relação dos Bens -->
    <div class="section">
        <div class="section-title">II — Relação dos Bens Patrimoniais</div>
        <?php if (empty($itens)): ?>
        <p class="section-text" style="color:#9ca3af;font-style:italic">Nenhum item registrado para este local.</p>
        <?php else: ?>
        <table class="items-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Descrição</th>
                    <th>Marca / Modelo</th>
                    <th>Tag Patrimônio</th>
                    <th>Nº de Série</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($itens as $i => $it):
                $marcaModelo = trim(($it['marca'] ?? '') . ($it['modelo'] ? ' / '.$it['modelo'] : ''));
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($it['descricao'] ?? '') ?></td>
                <td><?= htmlspecialchars($marcaModelo) ?></td>
                <td><?= htmlspecialchars($it['tag_antiga'] ?? '') ?></td>
                <td><?= htmlspecialchars($it['serie'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Obs -->
    <div class="section">
        <p class="section-text" style="font-size:10px;color:#6b7280;line-height:1.6">
            <strong>Observação:</strong>
            A presente conferência possui finalidade exclusivamente administrativa, destinada ao controle interno
            dos bens patrimoniais da instituição. Os bens patrimoniais deverão permanecer no setor de origem,
            sendo vedada sua alteração de localização ou situação sem prévia autorização formal da Gerência e/ou
            Administração competente, bem como do Patrimônio. Quaisquer movimentações, transferências, baixas ou
            ocorrências deverão ser comunicadas previamente ao Setor de Patrimônio.
        </p>
    </div>

    <!-- III — Ciência do Responsável -->
    <div class="section">
        <div class="section-title">III — Ciência do Responsável do Setor</div>
        <p class="section-text">
            Declaro estar ciente da relação dos bens patrimoniais conferidos pela equipe do Setor de Patrimônio
            durante a conferência realizada neste setor. Na qualidade de responsável pelo setor, comprometo-me a
            orientar e assegurar, juntamente com a equipe sob minha responsabilização, a adequada utilização,
            guarda e conservação dos bens relacionados neste documento, bem como comunicar ao Setor de Patrimônio
            qualquer movimentação, transferência, substituição, manutenção, baixa ou outra ocorrência que altere
            a localização ou a situação dos bens citados acima.
        </p>
    </div>

    <!-- Assinaturas -->
    <div class="assinaturas">
        <div class="assinatura">
            <div style="height:50px"></div>
            <div class="linha"></div>
            <div class="label">Assistente de Patrimônio</div>
            <div class="sub"><?= htmlspecialchars($assistente ?: '___________________________') ?></div>
        </div>
        <div class="assinatura">
            <div style="height:50px"></div>
            <div class="linha"></div>
            <div class="label">Responsável do Setor</div>
            <div class="sub"><?= htmlspecialchars($coordenador ?: '___________________________') ?></div>
        </div>
        <div class="assinatura" style="margin-top:24px">
            <div style="height:50px"></div>
            <div class="linha"></div>
            <div class="label">Administrador(a) da Unidade</div>
            <div class="sub">Nome e Assinatura</div>
        </div>
        <div class="assinatura" style="margin-top:24px">
            <div style="height:50px"></div>
            <div class="linha"></div>
            <div class="label">Gerência / Coordenação</div>
            <div class="sub">Nome e Assinatura</div>
        </div>
    </div>

    <div class="doc-footer">
        Gerado em <?= date('d/m/Y H:i') ?> por <?= htmlspecialchars($usuario) ?> · PatAsset — Rede Hospitalar · Documento de controle interno
    </div>

</div>

<script>
// Auto-print ao abrir
window.addEventListener('load', () => { window.print(); });
</script>
</body>
</html>
