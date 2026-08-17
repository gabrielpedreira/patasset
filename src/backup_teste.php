<?php
/**
 * backup_teste.php — Diagnóstico do sistema de backup (acesso DEV)
 *
 * Roda cada etapa do backup isoladamente e mostra onde trava.
 * Não substitui o backup: gera no máximo um arquivo de teste de poucos bytes.
 *
 * Apague este arquivo do servidor depois que o backup estiver funcionando.
 */
session_start();
mysqli_report(MYSQLI_REPORT_OFF);
require_once 'conexao.php';

if (!isset($_SESSION['usuario_logado'])) { header('Location: index.html'); exit; }

$classe = '';
$st = $conn->prepare("SELECT classe_usuario FROM usuarios WHERE usuario=? LIMIT 1");
if ($st) {
    $st->bind_param('s', $_SESSION['usuario_logado']);
    $st->execute();
    $r = $st->get_result();
    if ($r && ($x = $r->fetch_assoc())) $classe = strtoupper(trim($x['classe_usuario'] ?? ''));
    $st->close();
}
if ($classe !== 'DEV') { header('Location: acesso_bloqueado.html'); exit; }

@set_time_limit(180);
$rodar = isset($_GET['rodar']);
$etapas = [];

/** Registra o resultado de uma etapa */
function etapa(string $nome, bool $ok, string $detalhe = '', string $dica = ''): void {
    global $etapas;
    $etapas[] = compact('nome', 'ok', 'detalhe', 'dica');
}

require_once __DIR__ . '/backup_config.php';
require_once __DIR__ . '/backup_log.php';
require_once __DIR__ . '/backup_dump.php';
require_once __DIR__ . '/backup_drive_oauth.php';

// Qual caminho está em uso para o Drive. A conta de serviço só é testada
// se o OAuth ainda não foi configurado — testá-la depois disso seria reportar
// como falha um caminho que abandonamos de propósito.
$usa_oauth = oauth_configurado();

if ($rodar) {

    // ── 1. Caminhos ──────────────────────────────────────────────────────────
    etapa('Pasta do site', true, __DIR__);
    etapa('Pasta temporária', is_writable(BACKUP_TMP_DIR),
        BACKUP_TMP_DIR,
        'Crie a pasta e dê permissão 750 ao usuário do PHP.');

    $log_dir = dirname(BACKUP_LOG_FILE);
    etapa('Pasta de logs', is_dir($log_dir) && is_writable($log_dir),
        $log_dir,
        'Sem ela o backup roda mas não deixa registro de nada.');

    // ── 2. Destino local ─────────────────────────────────────────────────────
    if (BACKUP_LOCAL_ATIVO) {
        $dir = BACKUP_LOCAL_DIR;
        $existe = is_dir($dir);
        if (!$existe) @mkdir($dir, 0750, true);
        $existe = is_dir($dir);
        $grav   = $existe && is_writable($dir);

        etapa('Pasta local de backup', $grav, $dir,
            'Crie manualmente pelo Gerenciador de Arquivos do cPanel, no mesmo nível de public_html.');

        if ($grav) {
            $teste = $dir . '/_teste_escrita.txt';
            $ok_w  = @file_put_contents($teste, 'teste ' . date('c')) !== false;
            @unlink($teste);
            etapa('Gravação na pasta local', $ok_w, $ok_w ? 'Escrita e remoção OK' : 'Não conseguiu escrever');
        }

        // Aviso se a pasta ficou dentro da área pública
        $publica = str_starts_with(realpath($dir) ?: $dir, realpath(__DIR__) ?: __DIR__);
        etapa('Pasta fora da área pública', !$publica,
            $publica ? 'ATENÇÃO: a pasta está dentro de public_html' : 'Fora de public_html, correto',
            'Dentro de public_html qualquer pessoa que adivinhe o nome do arquivo baixa o banco inteiro.');
    }

    // ── 3. Dependências ──────────────────────────────────────────────────────
    etapa('Extensão OpenSSL', extension_loaded('openssl'), 'Assina o token do Google');
    etapa('Extensão cURL',    extension_loaded('curl'),    'Envia o arquivo ao Drive');
    etapa('Compactação gzip', function_exists('gzopen'),   'Reduz o arquivo em ~90%');

    // ── 4. Drive ─────────────────────────────────────────────────────────────
    if ($usa_oauth) {

        $cfg_o = oauth_carregar();
        etapa('Modo de envio ao Drive', true,
            'OAuth da conta do usuário (autorizado em ' . ($cfg_o['autorizado_em'] ?? '?') . ')');

        $tk = oauth_access_token();
        etapa('Renovação do token', (bool)$tk,
            $tk ? 'Token renovado a partir do refresh token' : 'Não foi possível renovar',
            'Reautorize em backup_oauth.php. Se o app estiver em modo "Teste" no Google '
          . 'Cloud, o token expira a cada 7 dias — publique o app.');

        if ($tk) {
            $pid = oauth_pasta_id($tk);
            etapa('Pasta no Drive', (bool)$pid,
                $pid ? "Pasta \"" . ($cfg_o['pasta_nome'] ?? '?') . "\" (ID $pid)" : 'Indisponível');

            if ($pid) {
                $tmpo = BACKUP_TMP_DIR . '/_teste_oauth.txt';
                @file_put_contents($tmpo, 'Teste ' . date('c'));
                $ro = oauth_enviar($tmpo, '_TESTE_patasset_' . date('Ymd_His') . '.txt');
                @unlink($tmpo);
                etapa('Envio de arquivo ao Drive', $ro['ok'],
                    $ro['ok'] ? 'Arquivo de teste enviado (ID ' . $ro['id'] . ') — pode apagar do Drive'
                              : $ro['erro']);
            }
        }

    } else {
    // ── Caminho legado: conta de serviço ─────────────────────────────────────

    etapa('Modo de envio ao Drive', false,
        'Conta de serviço (legado) — sem cota de armazenamento própria',
        'Configure o OAuth em backup_oauth.php. Enquanto isso o envio ao Drive '
      . 'sempre falhará com storageQuotaExceeded; a cópia local continua funcionando.');

    $cred_ok = file_exists(DRIVE_CREDENTIALS_PATH);
    etapa('Arquivo de credenciais', $cred_ok, DRIVE_CREDENTIALS_PATH);

    $cred = null;
    if ($cred_ok) {
        $cred = json_decode(file_get_contents(DRIVE_CREDENTIALS_PATH), true);
        etapa('Credenciais legíveis', !empty($cred['private_key']),
            'Conta de serviço: ' . ($cred['client_email'] ?? '???'));
    }

    // ── 5. Token do Google ───────────────────────────────────────────────────
    $token = null;
    if ($cred && !empty($cred['private_key']) && extension_loaded('curl')) {
        require_once __DIR__ . '/backup_drive.php';
        $token = obter_token_drive($cred);
        etapa('Autenticação no Google', (bool)$token,
            $token ? 'Token obtido' : 'Não foi possível obter o token',
            'Verifique se a API do Google Drive está ativada no projeto patasset-backup.');
    }

    // ── 6. Acesso à pasta do Drive ───────────────────────────────────────────
    if ($token) {
        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . DRIVE_FOLDER_ID
                      . '?fields=id,name,mimeType&supportsAllDrives=true');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $info = json_decode($resp, true);
        etapa('Pasta do Drive acessível', $code === 200,
            $code === 200
                ? 'Pasta: "' . ($info['name'] ?? '?') . '"'
                : "HTTP $code — " . substr((string)$resp, 0, 220),
            'Abra a pasta no Drive → Compartilhar → adicione '
            . ($cred['client_email'] ?? 'a conta de serviço') . ' como Editor.');

        // ── 7. Upload de teste ───────────────────────────────────────────────
        // Feito aqui com curl direto (em vez de chamar upload_arquivo_drive)
        // só para poder MOSTRAR a resposta do Google. A função de produção
        // engole o erro no log, o que não ajuda em diagnóstico.
        if ($code === 200) {
            $conteudo = "Teste de escrita do PatAsset em " . date('d/m/Y H:i:s');
            $meta = json_encode([
                'name'    => '_TESTE_patasset_' . date('Ymd_His') . '.txt',
                'parents' => [DRIVE_FOLDER_ID],
            ]);
            $bd   = 'TesteBoundary' . uniqid();
            $body = "--$bd\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n"
                  . "--$bd\r\nContent-Type: text/plain\r\n\r\n$conteudo\r\n--$bd--";

            $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files'
                          . '?uploadType=multipart&supportsAllDrives=true');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: multipart/related; boundary=' . $bd,
                    'Content-Length: ' . strlen($body),
                ],
            ]);
            $up_resp = curl_exec($ch);
            $up_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $up = json_decode((string)$up_resp, true);
            $motivo = $up['error']['errors'][0]['reason'] ?? ($up['error']['status'] ?? '');
            $texto  = $up['error']['message'] ?? '';

            $dica = 'Veja a mensagem acima.';
            if (stripos($motivo . $texto, 'storageQuota') !== false) {
                $dica = 'CONFIRMADO: conta de serviço não tem cota de armazenamento própria. '
                      . 'Em conta Google pessoal (sem Workspace) não há Drive Compartilhado, '
                      . 'então esse caminho não tem solução — precisamos trocar para OAuth '
                      . 'da sua própria conta, onde os arquivos contam na SUA cota de 15 GB.';
            } elseif ($up_code === 403) {
                $dica = 'Permissão negada. Confirme que a conta de serviço está como Editor na pasta.';
            } elseif ($up_code === 404) {
                $dica = 'Pasta não encontrada pela conta de serviço — revise o compartilhamento.';
            }

            etapa('Envio de arquivo ao Drive', $up_code === 200,
                $up_code === 200
                    ? 'Arquivo de teste criado (ID ' . ($up['id'] ?? '?') . ') — pode apagar do Drive'
                    : "HTTP $up_code" . ($motivo ? " — motivo: $motivo" : '')
                      . "\n" . substr((string)$up_resp, 0, 600),
                $dica);
        }
    }
    } // fim do caminho legado (conta de serviço)

    // ── 8. Dump do banco ─────────────────────────────────────────────────────
    $linhas = 0; $tabelas = 0;
    $conta = function (string $t) use (&$linhas) { $linhas += substr_count($t, "\n"); };
    try {
        $res = backup_gerar_dump($conn, $conta);
        $tabelas = $res['tabelas'];
        etapa('Leitura do banco', $tabelas > 0,
            "{$res['tabelas']} tabelas, {$res['linhas']} linhas");
    } catch (Throwable $e) {
        etapa('Leitura do banco', false, $e->getMessage());
    }

    // ── 8b. Tarefas agendadas (cron) ─────────────────────────────────────────
    // Tenta ler a crontab do usuário. Em muitas hospedagens compartilhadas
    // shell_exec vem desativado — nesse caso resta o painel do cPanel.
    $cron_txt = '';
    if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim',
            explode(',', (string)ini_get('disable_functions'))), true)) {
        $cron_txt = trim((string)@shell_exec('crontab -l 2>&1'));
    }

    if ($cron_txt === '') {
        etapa('Tarefas agendadas (cron)', false,
            'Não foi possível ler a crontab por aqui (shell_exec desativado).',
            'Veja em cPanel → Avançado → Cron Jobs → "Tarefas cron atuais".');
    } else {
        $linhas_cron = array_values(array_filter(
            preg_split('/\R/', $cron_txt),
            fn($l) => trim($l) !== '' && !str_starts_with(trim($l), '#')
        ));
        $tem_backup = (bool)preg_grep('/backup/i', $linhas_cron);
        etapa('Tarefas agendadas (cron)', $tem_backup,
            $linhas_cron ? implode("\n", $linhas_cron) : 'Nenhuma tarefa agendada.',
            'Nenhuma linha menciona backup — provavelmente o backup automático nunca rodou.');
    }

    // ── 8c. Caminho do interpretador PHP ─────────────────────────────────────
    // O cron roda com PATH mínimo: um comando começando por "php" costuma
    // falhar por não encontrar o binário. Precisa do caminho absoluto.
    $php_bin = PHP_BINARY;
    if (!$php_bin || !is_file($php_bin) || str_contains(basename($php_bin), 'apache')) {
        $php_bin = '';
        foreach (['/usr/local/bin/php','/usr/bin/php','/opt/cpanel/ea-php83/root/usr/bin/php',
                  '/opt/cpanel/ea-php82/root/usr/bin/php','/usr/local/bin/ea-php83'] as $cand) {
            if (is_file($cand)) { $php_bin = $cand; break; }
        }
    }
    $GLOBALS['PHP_BIN_CRON'] = $php_bin ?: '/usr/local/bin/php';
    etapa('Caminho do PHP para o cron', $php_bin !== '',
        $php_bin ?: 'Não localizado automaticamente',
        'Veja em cPanel → Selecionar versão do PHP, ou use /usr/local/bin/php.');

    // ── 9. Tabelas do LifeTech no backup ─────────────────────────────────────
    $esperadas = ['cadastro','historico','usuarios','chamado_engclin','ordemservico_engclin',
                  'preventiva_engclin','estoque_engclin','maodeobra_engclin'];
    $faltando = [];
    foreach ($esperadas as $t) {
        $q = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
        if (!$q || $q->num_rows === 0) $faltando[] = $t;
    }
    etapa('Tabelas principais presentes', true,
        $faltando ? 'Não existem no banco (normal se mudaram de nome): ' . implode(', ', $faltando)
                  : 'Todas encontradas — e todas entram no backup, pois o dump varre SHOW TABLES');
}

$ok_total  = count(array_filter($etapas, fn($e) => $e['ok']));
$falhas    = count($etapas) - $ok_total;
$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnóstico do Backup — PatAsset</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f172a;color:#e2e8f0;padding:24px 16px;line-height:1.6}
.wrap{max-width:840px;margin:0 auto}
h1{font-size:20px;margin-bottom:4px}
.sub{font-size:13px;color:#94a3b8;margin-bottom:22px}
.btn{display:inline-flex;align-items:center;gap:8px;background:#2563eb;color:#fff;border:none;
     padding:12px 20px;border-radius:10px;font-size:14px;cursor:pointer;text-decoration:none}
.btn:hover{background:#1d4ed8}
.btn-ghost{background:#1e293b;border:1px solid #334155}
.btn-ghost:hover{background:#293548}
.resumo{display:flex;gap:10px;margin:20px 0;flex-wrap:wrap}
.chip{padding:8px 16px;border-radius:999px;font-size:13px;font-weight:600}
.chip-ok{background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.35)}
.chip-err{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.35)}
.et{background:#1e293b;border:1px solid #334155;border-left:3px solid #475569;
    border-radius:10px;padding:14px 16px;margin-bottom:9px}
.et.ok{border-left-color:#22c55e}
.et.err{border-left-color:#ef4444;background:rgba(239,68,68,.06)}
.et-t{font-size:14px;font-weight:600;display:flex;align-items:center;gap:9px}
.et-d{font-size:12.5px;color:#94a3b8;margin-top:5px;word-break:break-all;
      font-family:ui-monospace,monospace;white-space:pre-wrap}
.et-dica{font-size:12.5px;color:#fbbf24;margin-top:8px;padding-top:8px;border-top:1px dashed #475569}
.nota{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px 18px;
      margin-top:22px;font-size:13px;color:#cbd5e1}
.nota b{color:#e2e8f0}
code{background:#0f172a;padding:2px 7px;border-radius:5px;font-size:12.5px;color:#93c5fd}
</style>
</head>
<body>
<div class="wrap">

  <h1>Diagnóstico do Backup</h1>
  <div class="sub">Verifica cada etapa isoladamente. Não altera nada no banco.</div>

  <?php if (!$rodar): ?>
    <div class="nota" style="margin-top:0;margin-bottom:18px">
      O teste vai: conferir as pastas, autenticar no Google, <b>criar um arquivo de teste
      de poucos bytes na pasta do Drive</b> (pode apagar depois) e ler o banco inteiro uma
      vez para medir o tamanho. Leva de 10 a 60 segundos.
    </div>
    <a class="btn" href="?rodar=1"><i class="fas fa-play"></i> Executar diagnóstico</a>
    <a class="btn btn-ghost" href="dev_painel.php" style="margin-left:8px">
      <i class="fas fa-arrow-left"></i> Voltar
    </a>
  <?php else: ?>

    <div class="resumo">
      <span class="chip chip-ok"><?= $ok_total ?> etapa(s) OK</span>
      <?php if ($falhas): ?><span class="chip chip-err"><?= $falhas ?> com problema</span><?php endif; ?>
    </div>

    <?php foreach ($etapas as $e): ?>
    <div class="et <?= $e['ok'] ? 'ok' : 'err' ?>">
      <div class="et-t">
        <i class="fas <?= $e['ok'] ? 'fa-circle-check' : 'fa-circle-xmark' ?>"
           style="color:<?= $e['ok'] ? '#22c55e' : '#ef4444' ?>"></i>
        <?= htmlspecialchars($e['nome']) ?>
      </div>
      <?php if ($e['detalhe'] !== ''): ?>
      <div class="et-d"><?= htmlspecialchars($e['detalhe']) ?></div>
      <?php endif; ?>
      <?php if (!$e['ok'] && $e['dica'] !== ''): ?>
      <div class="et-dica"><i class="fas fa-lightbulb"></i> <?= htmlspecialchars($e['dica']) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="nota">
      <b>Cron do backup automático</b> — no cPanel, em Cron Jobs, use exatamente esta linha
      (diário às 02:00). O caminho do PHP precisa ser absoluto: o cron não herda o PATH
      do seu usuário, então um comando começando por <code>php</code> simplesmente não roda.<br><br>
      <code>0 2 * * * <?= htmlspecialchars($GLOBALS['PHP_BIN_CRON'] ?? '/usr/local/bin/php') ?> <?= htmlspecialchars(__DIR__) ?>/backup_run.php</code>
    </div>

    <a class="btn btn-ghost" href="?rodar=1" style="margin-top:18px">
      <i class="fas fa-rotate-right"></i> Rodar de novo
    </a>
    <a class="btn btn-ghost" href="dev_painel.php" style="margin-top:18px;margin-left:8px">
      <i class="fas fa-arrow-left"></i> Voltar
    </a>

  <?php endif; ?>
</div>
</body>
</html>
