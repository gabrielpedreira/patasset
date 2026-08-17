<?php
/**
 * PatAsset — Módulo Google Drive
 * Arquivo: backup_drive.php
 * 
 * Faz upload do backup para o Google Drive via API v3.
 * Usa Service Account (sem OAuth manual).
 * 
 * Cada arquivo tem nome único com timestamp — nunca sobrescreve anteriores.
 * Em caso de falha, NÃO trava o sistema — apenas notifica por email.
 */

require_once __DIR__ . '/backup_config.php';
require_once __DIR__ . '/backup_log.php';
require_once __DIR__ . '/backup_notify.php';

/**
 * Envia o arquivo para o Google Drive.
 * Retorna true em sucesso, false em falha.
 */
function enviar_drive(string $filepath, string $filename): bool
{
    log_backup("Drive: Iniciando upload de $filename...");

    try {
        // Carrega credenciais
        if (!file_exists(DRIVE_CREDENTIALS_PATH)) {
            throw new Exception("Arquivo de credenciais não encontrado: " . DRIVE_CREDENTIALS_PATH);
        }

        $credentials = json_decode(file_get_contents(DRIVE_CREDENTIALS_PATH), true);

        if (!$credentials || empty($credentials['private_key'])) {
            throw new Exception("Arquivo de credenciais inválido ou corrompido.");
        }

        // Obtém token de acesso
        $access_token = obter_token_drive($credentials);

        if (!$access_token) {
            throw new Exception("Não foi possível obter token de acesso do Google.");
        }

        // Faz upload — nome do arquivo já contém timestamp, nunca sobrescreve
        $file_id = upload_arquivo_drive($access_token, $filepath, $filename);

        if (!$file_id) {
            throw new Exception("Falha no upload do arquivo para o Drive.");
        }

        log_backup("Drive: Upload concluído. ID do arquivo: $file_id");
        return true;

    } catch (Exception $e) {
        $msg = $e->getMessage();
        log_backup("Drive ERRO: $msg");
        notify_error(
            "Falha no backup para o Google Drive",
            "Erro: $msg\n\nArquivo: $filename\nData: " . date('d/m/Y H:i:s')
        );
        return false;
    }
}

/**
 * Obtém token OAuth2 via JWT assinado com a chave privada da service account.
 */
function obter_token_drive(array $credentials): ?string
{
    $now    = time();
    $expiry = $now + 3600;

    $header  = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode([
        'iss'   => $credentials['client_email'],
        // Escopo 'drive' (completo) e não 'drive.file'.
        // O drive.file só dá acesso a arquivos que o PRÓPRIO app criou — uma
        // pasta que alguém compartilhou com a conta de serviço fica invisível,
        // e a API responde 404 "File not found" mesmo com o token válido e o
        // compartilhamento correto. Era exatamente o erro do diagnóstico.
        'scope' => 'https://www.googleapis.com/auth/drive',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $expiry,
        'iat'   => $now,
    ]));

    $signing_input = $header . '.' . $payload;

    $private_key = openssl_pkey_get_private($credentials['private_key']);
    if (!$private_key) {
        log_backup("Drive ERRO: Chave privada inválida no arquivo de credenciais.");
        return null;
    }

    openssl_sign($signing_input, $signature, $private_key, 'SHA256');
    $jwt = $signing_input . '.' . base64url_encode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        log_backup("Drive ERRO: Falha ao obter token. HTTP $http_code. Resposta: $response");
        return null;
    }

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

/**
 * Faz upload multipart do arquivo para o Google Drive.
 * O nome do arquivo já contém timestamp — nunca haverá conflito com arquivos anteriores.
 * Retorna o ID do arquivo criado ou null em falha.
 */
function upload_arquivo_drive(string $access_token, string $filepath, string $filename): ?string
{
    // Metadados: nome único + pasta de destino
    $metadata = json_encode([
        'name'    => $filename,           // ex: backup_patasset_2026-05-18_02-00-00.sql
        'parents' => [DRIVE_FOLDER_ID],   // pasta BACKUP SISTEMA PATASSET
    ]);

    $file_content = file_get_contents($filepath);
    if ($file_content === false) {
        log_backup("Drive ERRO: Não foi possível ler o arquivo $filepath");
        return null;
    }

    $boundary = 'PatAssetBackupBoundary' . uniqid();
    $body     = "--$boundary\r\n"
              . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
              . $metadata . "\r\n"
              . "--$boundary\r\n"
              . "Content-Type: application/octet-stream\r\n\r\n"
              . $file_content . "\r\n"
              . "--$boundary--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ],
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        log_backup("Drive ERRO: Upload falhou. HTTP $http_code. Resposta: $response");
        return null;
    }

    $data = json_decode($response, true);
    return $data['id'] ?? null;
}

/**
 * Codifica em base64 URL-safe (sem padding).
 */
function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}