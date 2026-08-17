<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
require_once __DIR__ . '/vendor/phpmailer/Exception.php';
require_once "conexao.php";

header("Content-Type: application/json; charset=UTF-8");

/* ===============================
   DADOS RECEBIDOS
================================ */

$apenasInterno    = ($_POST['apenas_interno'] ?? '0') === '1';
$idDestinatario   = intval($_POST['resp_recebe_id'] ?? 0);
$nomeManual       = trim($_POST['resp_recebe_manual'] ?? '');

$responsavel      = trim($_POST['responsavel']      ?? '');
$dataMov          = trim($_POST['data_mov']          ?? '');
$unidadeOrigem    = trim($_POST['unidade_origem']    ?? '');
$unidadeDestino   = trim($_POST['unidade_destino']   ?? '');
$setorOrigem      = trim($_POST['setor_origem']      ?? '');
$setorDestino     = trim($_POST['setor_destino']     ?? '');

// itens: JSON string com array de objetos
$itensRaw = trim($_POST['itens'] ?? '[]');
$itens    = json_decode($itensRaw, true);
if (!is_array($itens)) $itens = [];

/* ===============================
   DESTINATÁRIO
================================ */

$nomeDestino  = '';
$emailDestino = '';

if ($apenasInterno) {
    // Sem email ao destinatário — usa nome manual
    $nomeDestino  = $nomeManual ?: 'Não informado';
    $emailDestino = ''; // não vai usar
} else {
    if ($idDestinatario <= 0) {
        echo json_encode(['status' => 'erro', 'msg' => 'Destinatário não informado.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT nome, email FROM cadastro_destinatarios WHERE id = ?");
    $stmt->bind_param("i", $idDestinatario);
    $stmt->execute();
    $dest = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$dest) {
        echo json_encode(['status' => 'erro', 'msg' => 'Destinatário não encontrado.']);
        exit;
    }

    $nomeDestino  = $dest['nome'];
    $emailDestino = $dest['email'];

    if (empty($emailDestino)) {
        echo json_encode(['status' => 'erro', 'msg' => 'Destinatário sem e-mail cadastrado.']);
        exit;
    }
}

/* ===============================
   CONFIG
================================ */

require_once __DIR__ . '/config_seguro.php';
define('GMAIL_USER',         PAT_SMTP_USER);
define('GMAIL_APP_PASSWORD', PAT_SMTP_PASS);

/* ===============================
   MONTAR TABELA DE ITENS (HTML)
================================ */

function montarTabelaItens(array $itens): string {
    $linhas = '';
    foreach ($itens as $idx => $item) {
        $n   = $idx + 1;
        $desc = htmlspecialchars($item['descricao'] ?? '');
        $tag  = htmlspecialchars($item['tag']       ?? '');
        $marc = htmlspecialchars($item['marca']     ?? '');
        $mod  = htmlspecialchars($item['modelo']    ?? '');
        $ser  = htmlspecialchars($item['serie']     ?? '');
        $bg   = ($idx % 2 === 0) ? '#eff6ff' : '#ffffff';
        $linhas .= "
        <tr style='background:{$bg};'>
            <td style='padding:7px 10px;border-bottom:1px solid #e5e7eb;text-align:center;'>{$n}</td>
            <td style='padding:7px 10px;border-bottom:1px solid #e5e7eb;'>{$tag}</td>
            <td style='padding:7px 10px;border-bottom:1px solid #e5e7eb;'>{$desc}</td>
            <td style='padding:7px 10px;border-bottom:1px solid #e5e7eb;'>{$marc}</td>
            <td style='padding:7px 10px;border-bottom:1px solid #e5e7eb;'>{$mod}</td>
            <td style='padding:7px 10px;border-bottom:1px solid #e5e7eb;'>{$ser}</td>
        </tr>";
    }

    return "
    <table width='100%' cellpadding='0' cellspacing='0'
           style='border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;font-size:13px;margin:16px 0;'>
        <thead>
            <tr style='background:#1d4ed8;'>
                <th style='padding:8px 10px;color:#fff;text-align:center;'>#</th>
                <th style='padding:8px 10px;color:#fff;text-align:left;'>Tag/Pat.</th>
                <th style='padding:8px 10px;color:#fff;text-align:left;'>Descrição</th>
                <th style='padding:8px 10px;color:#fff;text-align:left;'>Marca</th>
                <th style='padding:8px 10px;color:#fff;text-align:left;'>Modelo</th>
                <th style='padding:8px 10px;color:#fff;text-align:left;'>Nº Série</th>
            </tr>
        </thead>
        <tbody>{$linhas}</tbody>
    </table>";
}

$tabelaItens  = montarTabelaItens($itens);
$qtd          = count($itens);

/* ===============================
   CABEÇALHO COMUM
================================ */

$cabecalho = "
<tr>
  <td style='background:#1d4ed8;padding:24px 30px;text-align:center;'>
    <h2 style='margin:0;color:#ffffff;font-size:20px;letter-spacing:1px;'>
      🏥 Sistema Patrimonial — PatAsset
    </h2>
    <p style='margin:6px 0 0;color:#bfdbfe;font-size:13px;'>Rede Hospitalar</p>
  </td>
</tr>";

$rodape = "
<tr>
  <td style='background:#f9fafb;padding:16px 30px;text-align:center;border-top:1px solid #e5e7eb;'>
    <p style='margin:0;font-size:12px;color:#9ca3af;'>Equipe Patrimônio · Rede Hospitalar</p>
  </td>
</tr>";

/* ===============================
   BLOCO DE INFO COMUM
================================ */

$infoBloco = "
<table width='100%' cellpadding='0' cellspacing='0'
       style='border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;font-size:14px;margin-bottom:8px;'>
  <tr style='background:#eff6ff;'>
    <td style='padding:9px 14px;color:#1e40af;font-weight:bold;width:40%;border-bottom:1px solid #e5e7eb;'>Unidade de Origem</td>
    <td style='padding:9px 14px;color:#111827;border-bottom:1px solid #e5e7eb;'>{$unidadeOrigem}</td>
  </tr>
  <tr>
    <td style='padding:9px 14px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Setor de Origem</td>
    <td style='padding:9px 14px;color:#111827;border-bottom:1px solid #e5e7eb;'>{$setorOrigem}</td>
  </tr>
  <tr style='background:#eff6ff;'>
    <td style='padding:9px 14px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Unidade de Destino</td>
    <td style='padding:9px 14px;color:#111827;border-bottom:1px solid #e5e7eb;'>{$unidadeDestino}</td>
  </tr>
  <tr>
    <td style='padding:9px 14px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Setor de Destino</td>
    <td style='padding:9px 14px;color:#111827;border-bottom:1px solid #e5e7eb;'>{$setorDestino}</td>
  </tr>
  <tr style='background:#eff6ff;'>
    <td style='padding:9px 14px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Data</td>
    <td style='padding:9px 14px;color:#111827;border-bottom:1px solid #e5e7eb;'>{$dataMov}</td>
  </tr>
  <tr>
    <td style='padding:9px 14px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Responsável que Cede</td>
    <td style='padding:9px 14px;color:#111827;border-bottom:1px solid #e5e7eb;'>{$responsavel}</td>
  </tr>
  <tr style='background:#eff6ff;'>
    <td style='padding:9px 14px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Responsável que Recebe</td>
    <td style='padding:9px 14px;color:#111827;border-bottom:1px solid #e5e7eb;'>{$nomeDestino}</td>
  </tr>
  <tr>
    <td style='padding:9px 14px;color:#1e40af;font-weight:bold;'>Quantidade de Itens</td>
    <td style='padding:9px 14px;color:#111827;'>{$qtd}</td>
  </tr>
</table>";

/* ===============================
   CORPO — DESTINATÁRIO
================================ */

$corpoDestinatario = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f3f4f6;padding:30px 0;'>
<tr><td align='center'>
<table width='640' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.1);'>
{$cabecalho}
<tr><td style='padding:30px;'>
  <p style='margin:0 0 16px;font-size:15px;color:#111827;'>Prezado(a) <strong>{$nomeDestino}</strong>,</p>
  <p style='margin:0 0 20px;font-size:14px;color:#374151;line-height:1.6;'>
    Informamos que foi registrada uma <strong>movimentação patrimonial</strong> no sistema. Confira os detalhes abaixo:
  </p>
  {$infoBloco}
  {$tabelaItens}
  <p style='margin:16px 0 0;font-size:13px;color:#6b7280;line-height:1.6;'>
    Em caso de divergência, entre em contato com o setor responsável.<br>
    Este é um e-mail automático, por favor não responda.
  </p>
</td></tr>
{$rodape}
</table>
</td></tr>
</table>
</body></html>";

/* ===============================
   CORPO — INTERNO
================================ */

$corpoInterno = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f3f4f6;padding:30px 0;'>
<tr><td align='center'>
<table width='640' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.1);'>
{$cabecalho}
<tr><td style='padding:30px;'>
  <p style='margin:0 0 20px;font-size:14px;color:#374151;line-height:1.6;'>
    Nova <strong>movimentação patrimonial</strong> registrada no sistema" .
    ($apenasInterno ? " <em>(e-mail ao destinatário não foi enviado)</em>" : "") . ":
  </p>
  {$infoBloco}
  {$tabelaItens}
  <p style='margin:16px 0 0;font-size:13px;color:#6b7280;'>Este é um e-mail automático de registro interno.</p>
</td></tr>
{$rodape}
</table>
</td></tr>
</table>
</body></html>";

/* ===============================
   ENVIO
================================ */

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = GMAIL_USER;
    $mail->Password   = GMAIL_APP_PASSWORD;
    $mail->Port       = 587;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(GMAIL_USER, 'Sistema Patrimonial - PatAsset');
    $mail->isHTML(true);
    $mail->Subject = 'Confirmação de Movimentação Patrimonial';

    // Email ao destinatário — somente se não for apenas_interno
    if (!$apenasInterno) {
        $mail->addAddress($emailDestino, $nomeDestino);
        $mail->Body    = $corpoDestinatario;
        $mail->AltBody = "Movimentação de {$qtd} item(ns) de {$unidadeOrigem}/{$setorOrigem} para {$unidadeDestino}/{$setorDestino} em {$dataMov}. Cede: {$responsavel} | Recebe: {$nomeDestino}";
        $mail->send();
        $mail->clearAddresses();
    }

    // Cópia interna sempre
    $mail->addAddress(GMAIL_USER);
    $mail->Body    = $corpoInterno;
    $mail->AltBody = "[INTERNO] Movimentação de {$qtd} item(ns) de {$unidadeOrigem}/{$setorOrigem} para {$unidadeDestino}/{$setorDestino} em {$dataMov}. Cede: {$responsavel} | Recebe: {$nomeDestino}";
    $mail->send();

    echo json_encode(['status' => 'sucesso', 'msg' => 'E-mail enviado com sucesso!']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'erro', 'msg' => 'Falha ao enviar: ' . $mail->ErrorInfo]);
    exit;
}