<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_logado'])) {
    echo json_encode(['status' => 'erro', 'msg' => 'Não autorizado']);
    exit;
}

// Este arquivo não usa banco, então não passa pelo conexao.php — onde a
// verificação de sessão e token é chamada nas outras telas. Sem esta linha,
// ficaria sendo o único endpoint de POST fora da proteção.
require_once __DIR__ . '/seguranca_sessao.php';
seg_guardar();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
require_once __DIR__ . '/vendor/phpmailer/Exception.php';

$unidade     = trim($_POST['unidade']     ?? '');
$setor       = trim($_POST['setor']       ?? '');
$area        = trim($_POST['area']        ?? '');
$coordenador = trim($_POST['coordenador'] ?? '');
$data        = trim($_POST['data']        ?? '');
$itensJson   = trim($_POST['itens']       ?? '[]');
$itens       = json_decode($itensJson, true);
if (!is_array($itens)) $itens = [];

require_once __DIR__ . '/config_seguro.php';
$GMAIL_USER     = PAT_SMTP_USER;
$GMAIL_PASSWORD = PAT_SMTP_PASS;

// Build items table rows
$tableRows = '';
foreach ($itens as $i => $item) {
    $num     = $i + 1;
    $desc    = htmlspecialchars($item['descricao']          ?? '', ENT_QUOTES);
    $marca   = htmlspecialchars($item['marca']              ?? '', ENT_QUOTES);
    $modelo  = htmlspecialchars($item['modelo']             ?? '', ENT_QUOTES);
    $tag     = htmlspecialchars($item['tag_trocada'] !== '' ? ($item['tag_trocada'] ?? '') : ($item['tag_antiga'] ?? ''), ENT_QUOTES);
    $serie   = htmlspecialchars($item['serie']              ?? '', ENT_QUOTES);
    $marcaMod = trim("$marca / $modelo", ' /');
    $bg = ($i % 2 === 0) ? '#f9f9f9' : '#ffffff';
    $tableRows .= "
        <tr style='background:$bg;'>
            <td style='padding:6px 10px;border:1px solid #ddd;text-align:center;'>$num</td>
            <td style='padding:6px 10px;border:1px solid #ddd;'>$desc</td>
            <td style='padding:6px 10px;border:1px solid #ddd;'>$marcaMod</td>
            <td style='padding:6px 10px;border:1px solid #ddd;'>$tag</td>
            <td style='padding:6px 10px;border:1px solid #ddd;'>$serie</td>
        </tr>";
}

$htmlBody = "
<!DOCTYPE html>
<html lang='pt-BR'>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;font-family:Arial,sans-serif;background:#f4f4f4;'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f4f4;padding:20px 0;'>
    <tr><td align='center'>
      <table width='680' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);'>

        <!-- Header -->
        <tr>
          <td style='background:#1a5fb4;padding:24px 32px;'>
            <h1 style='margin:0;color:#ffffff;font-size:20px;letter-spacing:.5px;'>
              Sistema Patrimonial &mdash; PatAsset / Rede Hospitalar
            </h1>
            <p style='margin:6px 0 0;color:#c9daef;font-size:13px;'>Termo de Responsabilidade</p>
          </td>
        </tr>

        <!-- Local info -->
        <tr>
          <td style='padding:24px 32px 12px;'>
            <table width='100%' cellpadding='4' cellspacing='0'>
              <tr>
                <td style='width:140px;color:#555;font-size:13px;font-weight:bold;'>Unidade:</td>
                <td style='color:#222;font-size:13px;'>" . htmlspecialchars($unidade) . "</td>
              </tr>
              <tr>
                <td style='color:#555;font-size:13px;font-weight:bold;'>Setor:</td>
                <td style='color:#222;font-size:13px;'>" . htmlspecialchars($setor) . "</td>
              </tr>
              <tr>
                <td style='color:#555;font-size:13px;font-weight:bold;'>Área:</td>
                <td style='color:#222;font-size:13px;'>" . htmlspecialchars($area) . "</td>
              </tr>
              <tr>
                <td style='color:#555;font-size:13px;font-weight:bold;'>Coordenador:</td>
                <td style='color:#222;font-size:13px;'>" . htmlspecialchars($coordenador) . "</td>
              </tr>
              <tr>
                <td style='color:#555;font-size:13px;font-weight:bold;'>Data:</td>
                <td style='color:#222;font-size:13px;'>" . htmlspecialchars($data) . "</td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Items table -->
        <tr>
          <td style='padding:12px 32px 24px;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;font-size:13px;'>
              <thead>
                <tr style='background:#1a5fb4;color:#fff;'>
                  <th style='padding:8px 10px;border:1px solid #1a5fb4;width:36px;'>#</th>
                  <th style='padding:8px 10px;border:1px solid #1a5fb4;text-align:left;'>Descrição</th>
                  <th style='padding:8px 10px;border:1px solid #1a5fb4;text-align:left;'>Marca / Modelo</th>
                  <th style='padding:8px 10px;border:1px solid #1a5fb4;text-align:left;'>Tag Patrimônio</th>
                  <th style='padding:8px 10px;border:1px solid #1a5fb4;text-align:left;'>Nº Série</th>
                </tr>
              </thead>
              <tbody>$tableRows</tbody>
            </table>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style='background:#f0f4fb;padding:16px 32px;border-top:2px solid #1a5fb4;'>
            <p style='margin:0;color:#555;font-size:12px;text-align:center;'>
              Equipe Patrimônio &middot; Rede Hospitalar
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>";

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $GMAIL_USER;
    $mail->Password   = $GMAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($GMAIL_USER, 'PatAsset — Hospital Exemplo');
    $mail->addAddress($GMAIL_USER, 'PatAsset — Hospital Exemplo');

    $subject = "Termo de Responsabilidade — $unidade — $data";
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body    = $htmlBody;
    $mail->AltBody = "Termo de Responsabilidade\nUnidade: $unidade\nSetor: $setor\nÁrea: $area\nCoordenador: $coordenador\nData: $data";

    $mail->send();
    echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
    echo json_encode(['status' => 'erro', 'msg' => $mail->ErrorInfo]);
}
