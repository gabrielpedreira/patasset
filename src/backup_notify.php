<?php
/**
 * PatAsset — Módulo de Notificação
 * Arquivo: backup_notify.php
 * 
 * Envia email de alerta SOMENTE em caso de erros.
 * Usa PHPMailer (já presente no projeto PatAsset).
 */

require_once __DIR__ . '/backup_config.php';
require_once __DIR__ . '/backup_log.php';

// Carregamento defensivo: se o PHPMailer não estiver no servidor, o alerta
// deixa de ser enviado, mas o BACKUP continua. Antes era require_once direto —
// um vendor/ ausente derrubava o cron com erro fatal antes mesmo de gerar o
// dump, ou seja, a falta do avisador impedia o que ele deveria avisar.
$_pm_base = __DIR__ . '/vendor/phpmailer/';
define('BACKUP_TEM_PHPMAILER', file_exists($_pm_base . 'PHPMailer.php'));

if (BACKUP_TEM_PHPMAILER) {
    require_once $_pm_base . 'PHPMailer.php';
    require_once $_pm_base . 'SMTP.php';
    require_once $_pm_base . 'Exception.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function notify_error(string $assunto, string $detalhe): void
{
    if (!BACKUP_TEM_PHPMAILER) {
        log_backup("AVISO: PHPMailer ausente — alerta não enviado. Motivo: $assunto — $detalhe");
        return;
    }
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress(ALERT_EMAIL);

        $mail->isHTML(true);
        $mail->Subject = '[PatAsset Backup] ERRO: ' . $assunto;
        $mail->Body    = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto">
            <div style="background:#c0392b;padding:16px 24px;border-radius:8px 8px 0 0">
                <h2 style="color:#fff;margin:0;font-size:18px">&#9888; Erro no Backup PatAsset</h2>
            </div>
            <div style="background:#f9f9f9;padding:24px;border:1px solid #ddd;border-top:none;border-radius:0 0 8px 8px">
                <p style="margin:0 0 12px"><strong>Problema:</strong> ' . htmlspecialchars($assunto) . '</p>
                <p style="margin:0 0 8px"><strong>Detalhe:</strong></p>
                <pre style="background:#fff;border:1px solid #ddd;padding:12px;border-radius:4px;font-size:13px;white-space:pre-wrap">' . htmlspecialchars($detalhe) . '</pre>
                <p style="margin:16px 0 0;font-size:13px;color:#666">
                    Data/hora: ' . date('d/m/Y H:i:s') . '<br>
                    Sistema: PatAsset — Rede Hospitalar
                </p>
            </div>
        </div>';

        $mail->send();
        log_backup("Alerta de erro enviado para " . ALERT_EMAIL);

    } catch (Exception $e) {
        // Não lança exceção — apenas registra no log para não travar nada
        log_backup("AVISO: Não foi possível enviar email de alerta: " . $e->getMessage());
    }
}

/**
 * Relatório de cada execução do backup — êxito, parcial ou falha.
 *
 * Diferente do notify_error, que só grita quando dá problema, este chega
 * sempre. A razão é prática: e-mail que só aparece em falha não distingue
 * "está tudo bem" de "o cron parou de rodar e ninguém percebeu". Foi
 * exatamente isso que manteve o backup quebrado por meses.
 *
 * Espera: situacao, tabelas, linhas, tamanho, arquivo, duracao,
 *         local_ok, local_caminho, drive_ok, drive_pasta, iniciado_em
 */
function notify_backup(array $d): void
{
    if (!BACKUP_TEM_PHPMAILER) {
        log_backup("AVISO: PHPMailer ausente — relatório de backup não enviado.");
        return;
    }

    $situacao = $d['situacao'] ?? 'FALHA';

    $visual = [
        'EXITO'   => ['#15803d', '&#10003;', 'Backup concluído'],
        'PARCIAL' => ['#b45309', '&#9888;',  'Backup parcial'],
        'FALHA'   => ['#b91c1c', '&#10007;', 'Backup falhou'],
    ][$situacao] ?? ['#b91c1c', '&#10007;', 'Backup falhou'];

    $mb = fn($b) => $b > 0 ? number_format($b / 1048576, 2, ',', '.') . ' MB' : '—';

    // Cada destino com a situação e o caminho completo
    $destino = function (bool $ok, string $rotulo, string $onde): string {
        $cor  = $ok ? '#15803d' : '#b91c1c';
        $icon = $ok ? '&#10003;' : '&#10007;';
        return '<tr>'
             . '<td style="padding:7px 10px;border-bottom:1px solid #eee;white-space:nowrap">'
             . '<span style="color:' . $cor . ';font-weight:bold">' . $icon . '</span> ' . $rotulo . '</td>'
             . '<td style="padding:7px 10px;border-bottom:1px solid #eee;font-family:monospace;'
             . 'font-size:12px;color:#444;word-break:break-all">' . htmlspecialchars($onde) . '</td>'
             . '</tr>';
    };

    $linha = fn($k, $v) => '<tr>'
        . '<td style="padding:7px 10px;border-bottom:1px solid #eee;color:#666;white-space:nowrap">' . $k . '</td>'
        . '<td style="padding:7px 10px;border-bottom:1px solid #eee;font-weight:600">' . $v . '</td></tr>';

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress(ALERT_EMAIL);

        $mail->isHTML(true);
        $mail->Subject = '[PatAsset Backup] ' . $visual[2] . ' — ' . date('d/m/Y H:i');

        $mail->Body = '
        <div style="font-family:Arial,sans-serif;max-width:640px;margin:0 auto">
          <div style="background:' . $visual[0] . ';padding:16px 24px;border-radius:8px 8px 0 0">
            <h2 style="color:#fff;margin:0;font-size:18px">' . $visual[1] . ' ' . $visual[2] . '</h2>
            <p style="color:rgba(255,255,255,.85);margin:4px 0 0;font-size:13px">
              PatAsset &middot; LifeTech — Rede Hospitalar</p>
          </div>
          <div style="background:#fff;padding:20px 24px;border:1px solid #ddd;border-top:none;
                      border-radius:0 0 8px 8px">

            <h3 style="font-size:13px;color:#555;text-transform:uppercase;letter-spacing:.5px;
                       margin:0 0 8px">Onde foi guardado</h3>
            <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px">
              ' . $destino((bool)($d['local_ok'] ?? false), 'Servidor',
                           (string)($d['local_caminho'] ?? '—')) . '
              ' . $destino((bool)($d['drive_ok'] ?? false), 'Google Drive',
                           (string)($d['drive_pasta'] ?? '—')) . '
            </table>

            <h3 style="font-size:13px;color:#555;text-transform:uppercase;letter-spacing:.5px;
                       margin:0 0 8px">Conteúdo</h3>
            <table style="width:100%;border-collapse:collapse;font-size:13px">
              ' . $linha('Arquivo',   htmlspecialchars((string)($d['arquivo'] ?? '—'))) . '
              ' . $linha('Tamanho',   $mb((int)($d['tamanho'] ?? 0))) . '
              ' . $linha('Tabelas',   number_format((int)($d['tabelas'] ?? 0), 0, ',', '.')) . '
              ' . $linha('Registros', number_format((int)($d['linhas'] ?? 0), 0, ',', '.')) . '
              ' . $linha('Início',    htmlspecialchars((string)($d['iniciado_em'] ?? '—'))) . '
              ' . $linha('Duração',   (int)($d['duracao'] ?? 0) . ' segundos') . '
              ' . $linha('Retenção',  (defined('BACKUP_MANTER') ? BACKUP_MANTER : '?')
                                      . ' cópias mais recentes em cada destino') . '
            </table>

            ' . ($situacao === 'EXITO' ? '' :
            '<div style="margin-top:18px;padding:12px 14px;background:#fffbeb;
                         border-left:3px solid ' . $visual[0] . ';font-size:13px;color:#78350f">
               <strong>Atenção:</strong> ' .
               ($situacao === 'PARCIAL'
                 ? 'apenas um dos destinos recebeu a cópia. O dado está protegido, mas há algo para consertar.'
                 : 'nenhum destino recebeu a cópia. Não existe backup desta execução.') . '
             </div>') . '

            <p style="margin:18px 0 0;font-size:12px;color:#888;border-top:1px solid #eee;padding-top:12px">
              Mensagem automática. Enviada em toda execução — inclusive nas bem-sucedidas,
              para que a ausência de e-mail seja sinal de que o agendamento parou.
            </p>
          </div>
        </div>';

        $mail->send();
        log_backup("Relatório de backup ($situacao) enviado para " . ALERT_EMAIL);

    } catch (Exception $e) {
        log_backup("AVISO: não foi possível enviar o relatório: " . $e->getMessage());
    }
}