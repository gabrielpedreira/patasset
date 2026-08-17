<?php
/**
 * dev_log_js.php
 * Recebe os erros de JavaScript enviados por dev_captura_js.php.
 *
 * Endpoint aberto por natureza — o erro pode acontecer na tela de login, antes
 * de existir sessão. Por isso tem limite de tamanho, limite por IP e nunca
 * devolve conteúdo: é só um receptor.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{}'; exit; }

require_once __DIR__ . '/dev_seguranca.php';

$bruto = file_get_contents('php://input', false, null, 0, 12000);
$d = json_decode((string)$bruto, true);
if (!is_array($d)) { echo '{}'; exit; }

/* Teto por IP: um laço infinito no navegador poderia disparar milhares de
   requisições por minuto. 60 registros por hora por IP é folgado para
   diagnóstico e barra o descontrole. */
$c = dev_conn();
if ($c) {
    $ip = dev_ip();
    $st = $c->prepare("SELECT COUNT(*) AS n FROM dev_log_erros
                       WHERE origem='JS' AND ip=? AND atualizado_em >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    if ($st) {
        $st->bind_param('s', $ip);
        $st->execute();
        $r = $st->get_result();
        $n = $r ? (int)($r->fetch_assoc()['n'] ?? 0) : 0;
        $st->close();
        if ($n > 60) { echo '{"limite":true}'; exit; }
    }
}

$linha = (int)($d['linha'] ?? 0);
$col   = (int)($d['coluna'] ?? 0);

dev_registrar_erro([
    'origem'   => 'JS',
    'nivel'    => in_array($d['nivel'] ?? 'ERROR', ['INFO','WARNING','ERROR','CRITICAL'], true)
                  ? $d['nivel'] : 'ERROR',
    'arquivo'  => (string)($d['arquivo'] ?? ''),
    'linha'    => $linha,
    'url'      => (string)($d['url'] ?? ''),
    'mensagem' => (string)($d['mensagem'] ?? '') . ($col ? " (coluna $col)" : ''),
    'stack'    => (string)($d['stack'] ?? ''),
]);

echo '{"ok":true}';
