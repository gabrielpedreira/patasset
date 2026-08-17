<?php
/**
 * PatAsset / LifeTech — Backup automático
 * Arquivo: backup_run.php
 *
 * Gera o dump COMPLETO do banco, guarda uma cópia local e envia ao Drive.
 *
 * Cron diário às 02:00:
 *   0 2 * * * /usr/local/bin/php /home/usuario/public_html/backup_run.php
 *
 * Antes: usava mysqldump com uma lista fixa de 8 tabelas. A lista nunca foi
 * atualizada, então nenhuma tabela do LifeTech (chamados, OS, estoque,
 * preventivas) estava sendo salva. Agora o dump varre SHOW TABLES, e tabela
 * nova entra no backup sozinha.
 */

require_once __DIR__ . '/backup_config.php';
require_once __DIR__ . '/backup_log.php';
require_once __DIR__ . '/backup_dump.php';
require_once __DIR__ . '/backup_notify.php';
if (BACKUP_DRIVE_ATIVO) require_once __DIR__ . '/backup_drive_oauth.php';

@set_time_limit(0);
@ini_set('memory_limit', '512M');
date_default_timezone_set('America/Sao_Paulo');

$timestamp = date('Y-m-d_H-i-s');
$base      = 'backup_patasset_' . $timestamp . '.sql';
$tmp_path  = BACKUP_TMP_DIR . '/' . $base;

$inicio_ts  = time();
$inicio_str = date('Y-m-d H:i:s');
$origem_exec = (PHP_SAPI === 'cli') ? 'AUTOMATICO' : 'MANUAL';

log_backup("==== INÍCIO DO BACKUP: $timestamp ====");

/**
 * Grava o resultado no banco, para o painel do DEV poder mostrar.
 * O arquivo de log continua existindo, mas ele não é consultável pela tela
 * e some se alguém limpar a pasta.
 */
function registrar_execucao(array $d): void {
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($c->connect_errno) return;
    @$c->set_charset('utf8mb4');

    $st = $c->prepare("INSERT INTO dev_backups
        (iniciado_em, terminado_em, origem, situacao, tabelas, linhas, tamanho,
         arquivo, local_ok, drive_ok, duracao, detalhe)
        VALUES (?,NOW(),?,?,?,?,?,?,?,?,?,?)");
    if ($st) {
        $st->bind_param('sssiiisiiis',
            $d['iniciado_em'], $d['origem'], $d['situacao'], $d['tabelas'], $d['linhas'],
            $d['tamanho'], $d['arquivo'], $d['local_ok'], $d['drive_ok'],
            $d['duracao'], $d['detalhe']);
        @$st->execute();
        $st->close();
    }
    $c->close();
}

/** Encerra registrando a falha antes de sair */
function abortar(string $motivo, array $ctx): void {
    log_backup("ERRO: $motivo");
    registrar_execucao($ctx + [
        'situacao' => 'FALHA', 'tabelas' => 0, 'linhas' => 0, 'tamanho' => 0,
        'arquivo'  => null, 'local_ok' => 0, 'drive_ok' => 0,
        'detalhe'  => $motivo,
    ]);
    notify_error("Falha no backup", $motivo);
    exit(1);
}

$ctx = ['iniciado_em' => $inicio_str, 'origem' => $origem_exec, 'duracao' => 0];

// ─── 1. Conecta ──────────────────────────────────────────────────────────────
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_errno) {
    abortar("Não foi possível conectar ao banco: " . $conn->connect_error, $ctx);
}
$conn->set_charset('utf8mb4');

// ─── 2. Gera o dump ──────────────────────────────────────────────────────────
$fh = @fopen($tmp_path, 'w');
if (!$fh) {
    abortar("Não foi possível criar o arquivo temporário em " . BACKUP_TMP_DIR, $ctx);
}

try {
    $res = backup_gerar_dump($conn, function (string $t) use ($fh) { fwrite($fh, $t); });
} catch (Throwable $e) {
    fclose($fh); @unlink($tmp_path);
    abortar("Exceção ao gerar o dump — " . $e->getMessage(), $ctx);
}
fclose($fh);
$conn->close();

if (!file_exists($tmp_path) || filesize($tmp_path) < 1024) {
    @unlink($tmp_path);
    abortar("Dump vazio ou muito pequeno — provável falha silenciosa.", $ctx);
}

log_backup("Dump gerado: {$res['tabelas']} tabelas, {$res['linhas']} linhas, "
         . round(filesize($tmp_path) / 1048576, 2) . " MB");

// ─── 3. Compacta ─────────────────────────────────────────────────────────────
$arquivo = $tmp_path;
$nome    = $base;

if (BACKUP_COMPACTAR && function_exists('gzopen')) {
    $gz_path = $tmp_path . '.gz';
    $in  = fopen($tmp_path, 'rb');
    $out = gzopen($gz_path, 'wb9');
    if ($in && $out) {
        while (!feof($in)) gzwrite($out, fread($in, 262144));
        fclose($in); gzclose($out);
        @unlink($tmp_path);
        $arquivo = $gz_path;
        $nome    = $base . '.gz';
        log_backup("Compactado: " . round(filesize($gz_path) / 1048576, 2) . " MB");
    } else {
        if ($in)  fclose($in);
        if ($out) gzclose($out);
        log_backup("AVISO: falha ao compactar — seguindo com o .sql puro.");
    }
}

// ─── 4. Cópia local ──────────────────────────────────────────────────────────
$local_ok = false;
if (BACKUP_LOCAL_ATIVO) {
    $destino = BACKUP_LOCAL_DIR . '/' . $nome;
    $local_ok = @copy($arquivo, $destino);
    log_backup($local_ok
        ? "Cópia local salva em: $destino"
        : "ERRO: não foi possível salvar a cópia local em " . BACKUP_LOCAL_DIR);

    // Expurgo: mantém apenas as N mais recentes
    if ($local_ok) {
        $antigos = glob(BACKUP_LOCAL_DIR . '/backup_patasset_*.sql*') ?: [];
        usort($antigos, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($antigos, BACKUP_MANTER) as $velho) {
            @unlink($velho);
            log_backup("Removido por rotação: " . basename($velho));
        }
    }
}

// ─── 5. Google Drive ─────────────────────────────────────────────────────────
// Prefere OAuth. A conta de serviço fica só como caminho legado: ela não tem
// cota de armazenamento própria, então em conta Google pessoal todo upload
// dela é recusado com storageQuotaExceeded.
$drive_ok = false;
if (BACKUP_DRIVE_ATIVO) {
    try {
        if (oauth_configurado()) {
            $res_d = oauth_enviar($arquivo, $nome);
            $drive_ok = $res_d['ok'];
            log_backup($drive_ok
                ? "Drive (OAuth): OK — ID {$res_d['id']}"
                : "Drive (OAuth): FALHA — {$res_d['erro']}");
            if ($drive_ok) oauth_rotacionar(BACKUP_MANTER);
        } else {
            require_once __DIR__ . '/backup_drive.php';
            $drive_ok = enviar_drive($arquivo, $nome);
            log_backup("Drive (conta de serviço): " . ($drive_ok ? "OK" : "FALHA"));
        }
    } catch (Throwable $e) {
        log_backup("ERRO no Drive: " . $e->getMessage());
        $drive_ok = false;
    }
}

// ─── 6. Limpeza e resultado ──────────────────────────────────────────────────
$tamanho_final = (int)@filesize($arquivo);
@unlink($arquivo);

// EXITO = os dois destinos. PARCIAL = só um. É uma distinção que importa:
// parcial ainda protege o dado, mas indica algo para consertar.
$situacao = ($local_ok && $drive_ok) ? 'EXITO'
          : (($local_ok || $drive_ok) ? 'PARCIAL' : 'FALHA');

$detalhe = 'Local: ' . ($local_ok ? 'OK' : 'falhou')
         . ' | Drive: ' . ($drive_ok ? 'OK' : (BACKUP_DRIVE_ATIVO ? 'falhou' : 'desativado'));

$dados_exec = [
    'iniciado_em' => $inicio_str,
    'origem'      => $origem_exec,
    'situacao'    => $situacao,
    'tabelas'     => (int)$res['tabelas'],
    'linhas'      => (int)$res['linhas'],
    'tamanho'     => $tamanho_final,
    'arquivo'     => $nome,
    'local_ok'    => $local_ok ? 1 : 0,
    'drive_ok'    => $drive_ok ? 1 : 0,
    'duracao'     => time() - $inicio_ts,
    'detalhe'     => $detalhe,
];

registrar_execucao($dados_exec);

// Relatório por e-mail em TODA execução, não só nas falhas. E-mail que só
// chega quando dá problema não distingue "está tudo bem" de "o agendamento
// parou e ninguém percebeu" — foi assim que o backup ficou meses quebrado.
$pasta_drive = '(não configurado)';
if (BACKUP_DRIVE_ATIVO && function_exists('oauth_carregar')) {
    $cfg_d = oauth_carregar();
    $pasta_drive = 'Google Drive → pasta "' . ($cfg_d['pasta_nome'] ?? '?') . '"';
}

notify_backup($dados_exec + [
    'local_caminho' => $local_ok
        ? (BACKUP_LOCAL_ATIVO ? BACKUP_LOCAL_DIR . '/' . $nome : '(desativado)')
        : (BACKUP_LOCAL_ATIVO ? BACKUP_LOCAL_DIR . ' — falhou' : '(desativado)'),
    'drive_pasta'   => $drive_ok ? $pasta_drive : $pasta_drive . ' — falhou',
]);

// Só alerta se AMBOS os destinos falharam. Um destino de pé já garante o dado.
if ($situacao === 'FALHA') {
    notify_error(
        "Backup sem destino válido",
        "O dump foi gerado, mas não foi possível salvá-lo nem localmente nem no Google Drive."
    );
    log_backup("==== FIM DO BACKUP — SEM DESTINO VÁLIDO ====\n");
    exit(1);
}

log_backup("==== FIM DO BACKUP — $situacao ($detalhe) ====\n");
