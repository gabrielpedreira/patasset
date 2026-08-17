<?php
/**
 * eng_clin_notificar_movimentacao.php
 * Helper reutilizável — envia e-mail ao PatAsset sempre que o LifeTech
 * registrar uma movimentação (manual ou automática via OS).
 *
 * Uso:
 *   require_once 'eng_clin_notificar_movimentacao.php';
 *   eng_clin_notificar_movimentacao([
 *       'tag'       => 'HCSC 001234',
 *       'descricao' => 'MONITOR MULTIPARAMETRICO',
 *       'marca'     => 'MINDRAY',
 *       'modelo'    => 'VS-900',
 *       'serie'     => 'SN12345',
 *       'uni_orig'  => 'RIO BOTAFOGO',
 *       'set_orig'  => 'CTI 1',
 *       'uni_dest'  => 'ENGENHARIA CLINICA',
 *       'set_dest'  => 'SALA DE MANUTENÇÃO',
 *       'area_dest' => '',
 *       'tipo_mov'  => 'MANUTENCAO',
 *       'obs'       => 'OS-000042 aberta',
 *       'usuario'   => 'tecnico.jose',
 *       'data'      => '2026-08-10',
 *   ]);
 */

use PHPMailer\PHPMailer\PHPMailer;

function eng_clin_notificar_movimentacao(array $d): void {
    // Credenciais vindas de fora de public_html (ver config_seguro.php)
    require_once __DIR__ . '/config_seguro.php';
    $GMAIL_USER = PAT_SMTP_USER;
    $GMAIL_PASS = PAT_SMTP_PASS;
    // Movimentação de item é informação operacional — vai para a equipe.
    $DEST_EMAIL = PAT_EMAIL_EQUIPE;
    $DEST_NOME  = PAT_EMAIL_NOME;

    // Fallbacks
    $tag      = $d['tag']       ?? '—';
    $descricao= $d['descricao'] ?? '—';
    $marca    = $d['marca']     ?? '—';
    $modelo   = $d['modelo']    ?? '—';
    $serie    = $d['serie']     ?? '—';
    $uni_orig = $d['uni_orig']  ?? '—';
    $set_orig = $d['set_orig']  ?? '—';
    $uni_dest = $d['uni_dest']  ?? '—';
    $set_dest = $d['set_dest']  ?? '—';
    $area_dest= $d['area_dest'] ?? '';
    $tipo_mov = $d['tipo_mov']  ?? '—';
    $obs      = $d['obs']       ?? '';
    $usuario  = $d['usuario']   ?? '—';
    $data     = $d['data']      ?? date('Y-m-d');

    $data_fmt  = $data ? date('d/m/Y', strtotime($data)) : '—';
    $origem    = $uni_orig . ' / ' . $set_orig;
    $destino   = $uni_dest . ' / ' . $set_dest . ($area_dest ? ' / ' . $area_dest : '');

    $tipo_labels = [
        'INTERNA'    => 'Interna',
        'DEFINITIVA' => 'Definitiva',
        'EMPRESTIMO' => 'Empréstimo',
        'RETORNO'    => 'Retorno',
        'MANUTENCAO' => 'Para Manutenção',
    ];
    $tipo_label = $tipo_labels[$tipo_mov] ?? $tipo_mov;

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

    $corpo = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f3f4f6;padding:30px 0;'>
<tr><td align='center'>
<table width='640' cellpadding='0' cellspacing='0'
       style='background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.1);'>

  <!-- Cabeçalho LifeTech -->
  <tr>
    <td style='background:#0f172a;padding:24px 30px;text-align:center;'>
      <h2 style='margin:0;color:#ffffff;font-size:19px;letter-spacing:1px;'>
        🔧 LifeTech — Engenharia Clínica
      </h2>
      <p style='margin:6px 0 0;color:#94a3b8;font-size:13px;'>
        Notificação automática de movimentação patrimonial
      </p>
    </td>
  </tr>

  <!-- Corpo -->
  <tr><td style='padding:28px 30px;'>

    <p style='margin:0 0 18px;font-size:14px;color:#374151;line-height:1.6;'>
      Prezada <strong>{$esc($DEST_NOME)}</strong>,<br><br>
      O sistema <strong>LifeTech Engenharia Clínica</strong> registrou uma movimentação
      patrimonial. Por gentileza, verifique o histórico no PatAsset para confirmação.
    </p>

    <!-- Equipamento -->
    <p style='margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.06em;
              text-transform:uppercase;color:#6b7280;'>Equipamento</p>
    <table width='100%' cellpadding='0' cellspacing='0'
           style='border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;
                  font-size:13px;margin-bottom:18px;'>
      <tr style='background:#f8fafc;'>
        <td style='padding:9px 14px;color:#1e40af;font-weight:700;width:38%;
                   border-bottom:1px solid #e5e7eb;'>Descrição</td>
        <td style='padding:9px 14px;border-bottom:1px solid #e5e7eb;'>{$esc($descricao)}</td>
      </tr>
      <tr>
        <td style='padding:9px 14px;color:#1e40af;font-weight:700;
                   border-bottom:1px solid #e5e7eb;'>Marca / Modelo</td>
        <td style='padding:9px 14px;border-bottom:1px solid #e5e7eb;'>{$esc($marca)} / {$esc($modelo)}</td>
      </tr>
      <tr style='background:#f8fafc;'>
        <td style='padding:9px 14px;color:#1e40af;font-weight:700;
                   border-bottom:1px solid #e5e7eb;'>Nº de Série</td>
        <td style='padding:9px 14px;border-bottom:1px solid #e5e7eb;'>{$esc($serie)}</td>
      </tr>
      <tr>
        <td style='padding:9px 14px;color:#1e40af;font-weight:700;'>Tag Patrimônio</td>
        <td style='padding:9px 14px;'>{$esc($tag)}</td>
      </tr>
    </table>

    <!-- Movimentação -->
    <p style='margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.06em;
              text-transform:uppercase;color:#6b7280;'>Movimentação</p>
    <table width='100%' cellpadding='0' cellspacing='0'
           style='border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;
                  font-size:13px;margin-bottom:18px;'>
      <tr style='background:#f8fafc;'>
        <td style='padding:9px 14px;color:#1e40af;font-weight:700;width:38%;
                   border-bottom:1px solid #e5e7eb;'>Origem</td>
        <td style='padding:9px 14px;border-bottom:1px solid #e5e7eb;'>{$esc($origem)}</td>
      </tr>
      <tr>
        <td style='padding:9px 14px;color:#1e40af;font-weight:700;
                   border-bottom:1px solid #e5e7eb;'>Destino</td>
        <td style='padding:9px 14px;border-bottom:1px solid #e5e7eb;'>{$esc($destino)}</td>
      </tr>
      <tr style='background:#f8fafc;'>
        <td style='padding:9px 14px;color:#1e40af;font-weight:700;
                   border-bottom:1px solid #e5e7eb;'>Tipo</td>
        <td style='padding:9px 14px;border-bottom:1px solid #e5e7eb;'>{$esc($tipo_label)}</td>
      </tr>
      <tr>
        <td style='padding:9px 14px;color:#1e40af;font-weight:700;
                   border-bottom:1px solid #e5e7eb;'>Técnico responsável</td>
        <td style='padding:9px 14px;border-bottom:1px solid #e5e7eb;'>{$esc($usuario)}</td>
      </tr>
      <tr style='background:#f8fafc;'>
        <td style='padding:9px 14px;color:#1e40af;font-weight:700;
                   border-bottom:1px solid #e5e7eb;'>Data</td>
        <td style='padding:9px 14px;border-bottom:1px solid #e5e7eb;'>{$esc($data_fmt)}</td>
      </tr>
      <tr>
        <td style='padding:9px 14px;color:#1e40af;font-weight:700;'>Observação</td>
        <td style='padding:9px 14px;'>" . ($obs ? $esc($obs) : '<em style=\"color:#9ca3af\">—</em>') . "</td>
      </tr>
    </table>

    <p style='margin:0;font-size:12px;color:#9ca3af;line-height:1.6;'>
      Este é um e-mail automático gerado pelo sistema LifeTech Engenharia Clínica.<br>
      Por favor, não responda a este e-mail.
    </p>
  </td></tr>

  <!-- Rodapé -->
  <tr>
    <td style='background:#f9fafb;padding:14px 30px;text-align:center;
               border-top:1px solid #e5e7eb;'>
      <p style='margin:0;font-size:12px;color:#9ca3af;'>
        LifeTech Engenharia Clínica &middot; Rede Hospitalar
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body></html>";

    // Carrega PHPMailer somente se disponível
    $base = __DIR__;
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        if (file_exists($base . '/vendor/phpmailer/PHPMailer.php')) {
            require_once $base . '/vendor/phpmailer/PHPMailer.php';
            require_once $base . '/vendor/phpmailer/SMTP.php';
            require_once $base . '/vendor/phpmailer/Exception.php';
        } else {
            return; // PHPMailer não disponível — falha silenciosa
        }
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $GMAIL_USER;
        $mail->Password   = $GMAIL_PASS;
        $mail->Port       = 587;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($GMAIL_USER, 'LifeTech Engenharia Clínica');
        $mail->addAddress($DEST_EMAIL, $DEST_NOME);
        $mail->isHTML(true);
        $mail->Subject = "[LifeTech] Movimentação Patrimonial — {$descricao} ({$tag})";
        $mail->Body    = $corpo;
        $mail->AltBody = "Movimentação registrada pelo LifeTech: {$descricao} ({$tag}) | {$origem} → {$destino} | Tipo: {$tipo_label} | Técnico: {$usuario} | {$data_fmt}";
        $mail->send();
    } catch (\Throwable $e) {
        // Falha no e-mail não deve interromper o fluxo principal
        error_log('[LifeTech] Falha ao enviar e-mail de movimentação: ' . $e->getMessage());
    }
}
