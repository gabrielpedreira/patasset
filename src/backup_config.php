<?php
/**
 * PatAsset — Configurações do Sistema de Backup
 * Arquivo: backup_config.php
 */

// ─── Credenciais ──────────────────────────────────────────────────────────────
// Vêm de fora de public_html. O cron também passa por aqui: como o caminho é
// resolvido de forma absoluta, funciona igual pela web e pela linha de comando.
require_once __DIR__ . '/config_seguro.php';

// ─── Banco de Dados ───────────────────────────────────────────────────────────
define('DB_HOST', PAT_DB_HOST);
define('DB_NAME', PAT_DB_NAME);
define('DB_USER', PAT_DB_USER);
define('DB_PASS', PAT_DB_PASS);

// ─── Google Drive ─────────────────────────────────────────────────────────────
// Pasta "Backup - PatAsset/LifeTech", conta nova (ago/2026).
// A conta de serviço patasset-backup@meu-projeto-backup.iam.gserviceaccount.com
// precisa estar compartilhada nessa pasta como Editor — o acesso vem daí, não
// de permissão no projeto do Google Cloud.
define('DRIVE_FOLDER_ID',        'COLE_AQUI_O_ID_DA_PASTA_DO_DRIVE');
define('DRIVE_CREDENTIALS_PATH',  __DIR__ . '/google-credentials.json');

// ─── Email de Alertas (somente erros) ────────────────────────────────────────
// Falha de backup é problema técnico: vai para o desenvolvedor, não para a
// equipe de patrimônio, que não teria o que fazer com a informação.
define('ALERT_EMAIL',    PAT_EMAIL_DEV);
define('SMTP_HOST',      PAT_SMTP_HOST);
define('SMTP_PORT',      PAT_SMTP_PORT);
define('SMTP_USER',      PAT_SMTP_USER);
define('SMTP_PASS',      PAT_SMTP_PASS);
define('SMTP_FROM_NAME', PAT_SMTP_NOME . ' Backup');

// ─── Diretórios ───────────────────────────────────────────────────────────────
define('BACKUP_TMP_DIR',  __DIR__ . '/tmp');
define('BACKUP_LOG_FILE', __DIR__ . '/logs/backup.log');

// ─── LOCAL DE SALVAMENTO ─────────────────────────────────────────────────────
//
// O backup é gravado em DOIS lugares. Um só destino é um ponto único de
// falha: se o Drive recusar o envio (cota, token expirado) o backup daquele
// dia simplesmente não existe, e ninguém percebe até precisar dele.
//
//  1) Pasta no servidor  → cópia imediata, sempre funciona
//  2) Google Drive       → cópia fora do servidor, sobrevive a perda da conta
//
// A pasta local fica FORA de public_html de propósito: dentro dela o arquivo
// .sql ficaria acessível pela internet por quem adivinhasse o nome.
define('BACKUP_LOCAL_ATIVO', true);
define('BACKUP_LOCAL_DIR',   dirname(__DIR__) . '/backups_sistema');

// Envio ao Google Drive (usa DRIVE_FOLDER_ID acima)
define('BACKUP_DRIVE_ATIVO', true);

// Quantas cópias locais manter. As mais antigas são apagadas a cada execução.
define('BACKUP_MANTER', 30);

// Compactar o .sql em .gz antes de guardar (reduz ~90% do tamanho)
define('BACKUP_COMPACTAR', true);

// ─── Cria diretórios automaticamente se não existirem ────────────────────────
$dirs = [BACKUP_TMP_DIR, dirname(BACKUP_LOG_FILE)];
if (BACKUP_LOCAL_ATIVO) $dirs[] = BACKUP_LOCAL_DIR;

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
}

// Bloqueia acesso pela web às pastas de trabalho.
// tmp/ e logs/ ficam DENTRO de public_html: durante a execução o dump completo
// passa pelo tmp/, e nesse intervalo o banco inteiro estaria disponível para
// quem soubesse o nome do arquivo. O .htaccess fecha isso.
$_proteger = [BACKUP_TMP_DIR, dirname(BACKUP_LOG_FILE)];
if (BACKUP_LOCAL_ATIVO) $_proteger[] = BACKUP_LOCAL_DIR;

foreach ($_proteger as $_dir) {
    if (is_dir($_dir) && !file_exists($_dir . '/.htaccess')) {
        @file_put_contents($_dir . '/.htaccess',
            "# Gerado por backup_config.php — bloqueia acesso pela web\n" .
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
}