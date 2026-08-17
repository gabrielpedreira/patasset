<?php
/**
 * PatAsset — Módulo de Log
 * Arquivo: backup_log.php
 */

require_once __DIR__ . '/backup_config.php';

function log_backup(string $mensagem): void
{
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL;
    file_put_contents(BACKUP_LOG_FILE, $linha, FILE_APPEND | LOCK_EX);
}
