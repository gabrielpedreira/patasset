<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'erro', 'msg' => 'Não autorizado.']);
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ===============================
   DEPENDÊNCIAS
================================ */
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
require_once __DIR__ . '/vendor/phpmailer/Exception.php';

require_once "conexao.php";


header("Content-Type: application/json; charset=UTF-8");

/* ===============================
   RECEBE JSON
================================ */
$raw     = file_get_contents("php://input");
$payload = json_decode($raw, true);

$logMsg2 .= "EMAIL DESTINATARIO: " . ($payload['email_destinatario'] ?? 'VAZIO') . "\n";

if (!$payload) {
    echo json_encode(['status' => 'erro', 'msg' => 'Payload inválido.']);
    exit;
}

$itens             = $payload['itens']               ?? [];
$assistente        = trim($payload['assistente']     ?? '');
$unidade           = trim($payload['unidade']        ?? '');
$acompanhante      = trim($payload['acompanhante']   ?? '');
$protocolo         = trim($payload['protocolo']      ?? '');
$emailDestinatario = trim($payload['email_destinatario'] ?? '');
$data              = date('d/m/Y H:i');
$totalItens        = count($itens);

/* ===============================
   VALIDAÇÃO
================================ */
if (empty($emailDestinatario)) {
    echo json_encode(['status' => 'erro', 'msg' => 'E-mail do destinatário não informado.']);
    exit;
}

/* ===============================
   CONFIG GMAIL SMTP
================================ */
require_once __DIR__ . '/config_seguro.php';
define('GMAIL_USER',         PAT_SMTP_USER);
define('GMAIL_APP_PASSWORD', PAT_SMTP_PASS);

/* ===============================
   MONTA LINHAS DOS ITENS
   — apenas dados textuais, sem foto
================================ */
$linhasItens = '';
foreach ($itens as $i => $item) {
    $num        = $i + 1;
    $descricao  = htmlspecialchars($item['descricao']    ?? '-');
    $marca      = htmlspecialchars($item['marca']        ?? '-');
    $modelo     = htmlspecialchars($item['modelo']       ?? '-');
    $serie      = htmlspecialchars($item['serie']        ?? '-');
    $tag        = htmlspecialchars($item['tag']          ?? '-');
    $obs        = htmlspecialchars($item['obs']          ?? '-');
    $respTec    = htmlspecialchars($item['resp_tecnico'] ?? '-');
    $patrimonio = ($item['nao_conformidade'] ?? '') === 'SIM' ? 'SEM PATRIMÔNIO' : $tag;
    $bg         = ($i % 2 === 0) ? '#ffffff' : '#eff6ff';

    $linhasItens .= "
    <tr>
      <td style='padding:8px 10px;border:1px solid #e5e7eb;text-align:center;font-weight:700;
                 background:#1e3a8a;color:#fff;width:36px;'>#{$num}</td>
      <td style='padding:0;border:1px solid #e5e7eb;background:{$bg};'>
        <table width='100%' cellpadding='0' cellspacing='0' style='font-size:13px;border-collapse:collapse;'>
          <tr style='background:#dbeafe;'>
            <td style='padding:7px 12px;color:#1e40af;font-weight:bold;width:180px;border-bottom:1px solid #e5e7eb;'>Descrição</td>
            <td style='padding:7px 12px;font-weight:700;border-bottom:1px solid #e5e7eb;'>{$descricao}</td>
          </tr>
          <tr>
            <td style='padding:7px 12px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Marca</td>
            <td style='padding:7px 12px;border-bottom:1px solid #e5e7eb;'>{$marca}</td>
          </tr>
          <tr style='background:#dbeafe;'>
            <td style='padding:7px 12px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Modelo</td>
            <td style='padding:7px 12px;border-bottom:1px solid #e5e7eb;'>{$modelo}</td>
          </tr>
          <tr>
            <td style='padding:7px 12px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Nº Série</td>
            <td style='padding:7px 12px;border-bottom:1px solid #e5e7eb;'>{$serie}</td>
          </tr>
          <tr style='background:#dbeafe;'>
            <td style='padding:7px 12px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Patrimônio</td>
            <td style='padding:7px 12px;border-bottom:1px solid #e5e7eb;'>{$patrimonio}</td>
          </tr>
          <tr>
            <td style='padding:7px 12px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Observações</td>
            <td style='padding:7px 12px;border-bottom:1px solid #e5e7eb;'>{$obs}</td>
          </tr>
          <tr style='background:#dbeafe;'>
            <td style='padding:7px 12px;color:#1e40af;font-weight:bold;'>Resp. Técnico</td>
            <td style='padding:7px 12px;font-weight:600;color:#1d4ed8;'>{$respTec}</td>
          </tr>
        </table>
      </td>
    </tr>
    <tr><td colspan='2' style='height:10px;background:#f3f4f6;'></td></tr>";
}

/* ===============================
   VARIÁVEIS ESCAPADAS
================================ */
$acompanhanteEsc = htmlspecialchars($acompanhante);
$assistenteEsc   = htmlspecialchars($assistente);
$unidadeEsc      = htmlspecialchars($unidade);

/* ===============================
   BLOCO CABEÇALHO (reutilizado nos dois emails)
================================ */
$cabecalho = "
  <tr>
    <td style='background:#001435;padding:24px 30px;text-align:center;'>
      <h2 style='margin:0;color:#fff;font-size:20px;letter-spacing:1px;'>🏥 Sistema Patrimonial — PatAsset</h2>
      <p style='margin:6px 0 0;color:#93c5fd;font-size:13px;'>Rede Hospitalar</p>
    </td>
  </tr>";

/* ===============================
   BLOCO RESUMO DA BAIXA (reutilizado nos dois emails)
================================ */
$resumoBaixa = "
    <table width='100%' cellpadding='0' cellspacing='0'
           style='border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;font-size:14px;margin-bottom:24px;'>
      <tr style='background:#eff6ff;'>
        <td style='padding:10px 14px;color:#1e40af;font-weight:bold;width:40%;border-bottom:1px solid #e5e7eb;'>Protocolo</td>
        <td style='padding:10px 14px;border-bottom:1px solid #e5e7eb;font-weight:700;'>{$protocolo}</td>
      </tr>
      <tr>
        <td style='padding:10px 14px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Assistente Patrimonial</td>
        <td style='padding:10px 14px;border-bottom:1px solid #e5e7eb;'>{$assistenteEsc}</td>
      </tr>
      <tr style='background:#eff6ff;'>
        <td style='padding:10px 14px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Unidade</td>
        <td style='padding:10px 14px;border-bottom:1px solid #e5e7eb;'>{$unidadeEsc}</td>
      </tr>
      <tr>
        <td style='padding:10px 14px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Responsável Acompanhante</td>
        <td style='padding:10px 14px;border-bottom:1px solid #e5e7eb;'>{$acompanhanteEsc}</td>
      </tr>
      <tr style='background:#eff6ff;'>
        <td style='padding:10px 14px;color:#1e40af;font-weight:bold;border-bottom:1px solid #e5e7eb;'>Data / Hora</td>
        <td style='padding:10px 14px;border-bottom:1px solid #e5e7eb;'>{$data}</td>
      </tr>
      <tr>
        <td style='padding:10px 14px;color:#1e40af;font-weight:bold;'>Total de Itens</td>
        <td style='padding:10px 14px;font-weight:700;color:#dc2626;'>{$totalItens} item(ns)</td>
      </tr>
    </table>
    <h3 style='margin:0 0 12px;color:#1d4ed8;font-size:14px;border-bottom:2px solid #e5e7eb;padding-bottom:8px;'>
      Itens Descartados
    </h3>
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;'>
      {$linhasItens}
    </table>";

$rodape = "
  <tr>
    <td style='background:#f9fafb;padding:16px 30px;text-align:center;border-top:1px solid #e5e7eb;'>
      <p style='margin:0;font-size:12px;color:#9ca3af;'>Equipe Patrimônio · Rede Hospitalar</p>
    </td>
  </tr>";

/* ===============================
   CORPO — EMAIL AO DESTINATÁRIO
================================ */
$corpoDestinatario = "
<!DOCTYPE html>
<html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f3f4f6;padding:30px 0;'>
<tr><td align='center'>
<table width='640' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.1);'>
  {$cabecalho}
  <tr>
    <td style='padding:30px;'>
      <p style='margin:0 0 16px;font-size:15px;color:#111827;'>
        Prezado(a) <strong>{$acompanhanteEsc}</strong>,
      </p>
      <p style='margin:0 0 24px;font-size:14px;color:#374151;line-height:1.6;'>
        Informamos que foi realizado um <strong>descarte de bens patrimoniais</strong>
        em nosso sistema. Confira os detalhes abaixo:
      </p>
      {$resumoBaixa}
      <p style='margin:24px 0 0;font-size:13px;color:#6b7280;line-height:1.6;'>
        Em caso de divergência, entre em contato com o setor responsável.<br>
        Este é um e-mail automático, por favor não responda.
      </p>
    </td>
  </tr>
  {$rodape}
</table>
</td></tr>
</table>
</body></html>";

/* ===============================
   CORPO — CÓPIA INTERNA
================================ */
$corpoInterno = "
<!DOCTYPE html>
<html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f3f4f6;padding:30px 0;'>
<tr><td align='center'>
<table width='640' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.1);'>
  {$cabecalho}
  <tr>
    <td style='padding:30px;'>
      <p style='margin:0 0 24px;font-size:14px;color:#374151;line-height:1.6;'>
        Novo <strong>descarte patrimonial</strong> registrado no sistema (cópia interna):
      </p>
      {$resumoBaixa}
      <p style='margin:24px 0 0;font-size:13px;color:#6b7280;line-height:1.6;'>
        Este é um e-mail automático de registro interno.
      </p>
    </td>
  </tr>
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

    /* 1º — email ao responsável acompanhante */
    $mail->addAddress($emailDestinatario, $acompanhante);
    $mail->isHTML(true);
    $mail->Subject = "Relatório de Descarte Patrimonial — Protocolo {$protocolo}";
    $mail->Body    = $corpoDestinatario;
    $mail->AltBody = "Descarte patrimonial — Protocolo: {$protocolo} | Unidade: {$unidade} | Data: {$data} | Itens: {$totalItens}";
    $mail->send();

        
    /* 2º — cópia interna */
    $mail->clearAddresses();
    $mail->addAddress(GMAIL_USER, 'PatAsset Interno');
    $mail->Body    = $corpoInterno;
    $mail->AltBody = "[INTERNO] Descarte patrimonial — Protocolo: {$protocolo} | Unidade: {$unidade} | Data: {$data} | Itens: {$totalItens}";
    $mail->send();

        
    echo json_encode(['status' => 'sucesso', 'msg' => 'E-mail enviado com sucesso!']);
    exit;

} catch (Exception $e) {
            echo json_encode(['status' => 'erro', 'msg' => 'Falha ao enviar: ' . $mail->ErrorInfo]);
    exit;
}