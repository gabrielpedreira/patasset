<?php
/**
 * backup_drive_oauth.php
 * Envio ao Google Drive usando OAuth da conta do usuário.
 *
 * POR QUE NÃO CONTA DE SERVIÇO:
 * conta de serviço é uma identidade sem pessoa por trás e sem cota de
 * armazenamento. Qualquer upload dela é recusado com storageQuotaExceeded,
 * porque o arquivo ficaria sendo propriedade dela. A saída oficial é Drive
 * Compartilhado, que só existe no Google Workspace — a conta aqui é pessoal.
 * Com OAuth o arquivo é propriedade do usuário e ocupa a cota dele (15 GB).
 *
 * ESCOPO drive.file (e não drive):
 * drive.file dá acesso apenas ao que o próprio app cria. É um escopo
 * NÃO sensível, o que significa que o app pode ser publicado sem passar pela
 * verificação do Google. Com o escopo 'drive' completo seria necessário
 * verificação, e enquanto o app ficasse em modo Teste o refresh token
 * expiraria a cada 7 dias — o backup pararia sozinho toda semana.
 * O preço é que a pasta precisa ser criada pelo próprio sistema.
 */

if (defined('BACKUP_OAUTH_CARREGADO')) return;
define('BACKUP_OAUTH_CARREGADO', true);

require_once __DIR__ . '/backup_config.php';
require_once __DIR__ . '/backup_log.php';

/** Onde ficam client_id, client_secret e refresh_token.
 *  Fora de public_html: é um arquivo que dá acesso ao Drive. */
function oauth_arquivo(): string {
    $dir = defined('BACKUP_LOCAL_DIR') ? BACKUP_LOCAL_DIR : __DIR__;
    return $dir . '/oauth_patasset.json';
}

function oauth_carregar(): array {
    $f = oauth_arquivo();
    if (!file_exists($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function oauth_salvar(array $dados): bool {
    $f = oauth_arquivo();
    $ok = @file_put_contents($f, json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    if ($ok) @chmod($f, 0600);
    return $ok;
}

function oauth_configurado(): bool {
    $c = oauth_carregar();
    return !empty($c['client_id']) && !empty($c['client_secret']) && !empty($c['refresh_token']);
}

/** Escopo pedido na autorização */
function oauth_escopo(): string {
    return 'https://www.googleapis.com/auth/drive.file';
}

/**
 * Troca o refresh_token por um access_token válido (1 hora).
 * O refresh_token não expira enquanto o app estiver publicado em produção.
 */
function oauth_access_token(): ?string {
    $c = oauth_carregar();
    if (empty($c['refresh_token'])) return null;

    // Reaproveita o token em cache enquanto estiver válido
    if (!empty($c['access_token']) && !empty($c['expira_em']) && $c['expira_em'] > time() + 60) {
        return $c['access_token'];
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'refresh_token' => $c['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        log_backup("OAuth ERRO: refresh falhou. HTTP $code — " . substr((string)$resp, 0, 300));
        return null;
    }

    $d = json_decode((string)$resp, true);
    if (empty($d['access_token'])) return null;

    $c['access_token'] = $d['access_token'];
    $c['expira_em']    = time() + (int)($d['expires_in'] ?? 3600);
    oauth_salvar($c);

    return $c['access_token'];
}

/**
 * Devolve o ID da pasta de backup, criando-a se necessário.
 * Com escopo drive.file o app só enxerga o que ele mesmo criou — por isso a
 * pasta é criada pelo sistema e o ID fica guardado.
 */
function oauth_pasta_id(string $token): ?string {
    $c = oauth_carregar();

    // Já temos o ID: confirma que a pasta continua existindo
    if (!empty($c['pasta_id'])) {
        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . $c['pasta_id']
                      . '?fields=id,trashed');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);
        $r = curl_exec($ch);
        $cd = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $info = json_decode((string)$r, true);
        if ($cd === 200 && empty($info['trashed'])) return $c['pasta_id'];
        log_backup("OAuth: pasta anterior indisponível — será criada outra.");
    }

    // Cria a pasta
    $nome = $c['pasta_nome'] ?? 'Backup PatAsset LifeTech';
    $ch = curl_init('https://www.googleapis.com/drive/v3/files?fields=id');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'name'     => $nome,
            'mimeType' => 'application/vnd.google-apps.folder',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    $r  = curl_exec($ch);
    $cd = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($cd !== 200) {
        log_backup("OAuth ERRO: não foi possível criar a pasta. HTTP $cd — " . substr((string)$r, 0, 300));
        return null;
    }

    $d = json_decode((string)$r, true);
    if (empty($d['id'])) return null;

    $c['pasta_id'] = $d['id'];
    oauth_salvar($c);
    log_backup("OAuth: pasta de backup criada no Drive (ID {$d['id']}).");
    return $d['id'];
}

/**
 * Envia um arquivo. Retorna ['ok'=>bool, 'id'=>?string, 'erro'=>string].
 * Usa upload resumível: multipart carrega o arquivo inteiro em memória e
 * dumps de banco crescem — resumível envia em blocos.
 */
function oauth_enviar(string $filepath, string $filename): array {
    if (!oauth_configurado()) {
        return ['ok' => false, 'id' => null, 'erro' => 'OAuth não configurado.'];
    }

    $token = oauth_access_token();
    if (!$token) return ['ok' => false, 'id' => null, 'erro' => 'Não foi possível renovar o token.'];

    $pasta = oauth_pasta_id($token);
    if (!$pasta) return ['ok' => false, 'id' => null, 'erro' => 'Pasta de destino indisponível.'];

    $tamanho = filesize($filepath);

    // 1) Abre a sessão de upload
    $meta = json_encode(['name' => $filename, 'parents' => [$pasta]]);
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $meta,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Length: ' . $tamanho,
        ],
    ]);
    $resp = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        return ['ok' => false, 'id' => null, 'erro' => "Início do upload recusado (HTTP $code): "
              . substr($resp, 0, 300)];
    }

    if (!preg_match('/^location:\s*(\S+)/mi', $resp, $m)) {
        return ['ok' => false, 'id' => null, 'erro' => 'Google não devolveu a URL de upload.'];
    }
    $url = trim($m[1]);

    // 2) Envia o conteúdo em blocos de 8 MB
    $fh = fopen($filepath, 'rb');
    if (!$fh) return ['ok' => false, 'id' => null, 'erro' => 'Não foi possível ler o arquivo.'];

    $bloco = 8 * 1024 * 1024;
    $pos   = 0;
    $id    = null;

    while ($pos < $tamanho) {
        $pedaco = fread($fh, $bloco);
        $fim    = $pos + strlen($pedaco) - 1;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $pedaco,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_HTTPHEADER     => [
                'Content-Length: ' . strlen($pedaco),
                "Content-Range: bytes $pos-$fim/$tamanho",
            ],
        ]);
        $r  = (string)curl_exec($ch);
        $cd = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 308 = bloco aceito, continue. 200/201 = terminou.
        if ($cd === 308) {
            $pos = $fim + 1;
            continue;
        }
        if ($cd === 200 || $cd === 201) {
            $d  = json_decode($r, true);
            $id = $d['id'] ?? 'sem-id';
            break;
        }

        fclose($fh);
        return ['ok' => false, 'id' => null, 'erro' => "Falha no envio (HTTP $cd): " . substr($r, 0, 300)];
    }
    fclose($fh);

    return ['ok' => (bool)$id, 'id' => $id, 'erro' => $id ? '' : 'Upload terminou sem confirmação.'];
}

/**
 * Apaga backups antigos do Drive, mantendo os N mais recentes.
 * Sem isso a pasta cresce para sempre e come os 15 GB da conta.
 */
function oauth_rotacionar(int $manter): void {
    $token = oauth_access_token();
    if (!$token) return;
    $c = oauth_carregar();
    if (empty($c['pasta_id'])) return;

    $q = rawurlencode("'{$c['pasta_id']}' in parents and trashed = false");
    $ch = curl_init("https://www.googleapis.com/drive/v3/files?q=$q"
                  . "&orderBy=createdTime desc&pageSize=200&fields=files(id,name,createdTime)");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    $r = curl_exec($ch);
    curl_close($ch);

    $d = json_decode((string)$r, true);
    $arquivos = $d['files'] ?? [];
    if (count($arquivos) <= $manter) return;

    foreach (array_slice($arquivos, $manter) as $a) {
        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . $a['id']);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);
        curl_exec($ch);
        curl_close($ch);
        log_backup("Drive: removido por rotação — " . $a['name']);
    }
}
