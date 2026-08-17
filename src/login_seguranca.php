<?php
/**
 * login_seguranca.php
 * Controle de tentativas de login — proteção contra força bruta.
 *
 * Duas chaves independentes são contadas:
 *   USUARIO → 5 falhas = 15 min de bloqueio (o alvo real de um ataque)
 *   IP      → 20 falhas = 15 min de bloqueio
 *
 * O limite do IP é bem mais alto de propósito: dentro do hospital todo mundo
 * sai pelo mesmo IP público. Um limite baixo por IP derrubaria o acesso de
 * todos os setores porque uma pessoa errou a senha algumas vezes.
 *
 * Bloqueios reincidentes crescem: 15 min → 30 min → 60 min (teto).
 */

if (defined('LOGIN_SEGURANCA_CARREGADO')) return;
define('LOGIN_SEGURANCA_CARREGADO', true);

// ─── Parâmetros ──────────────────────────────────────────────────────────────
const LS_MAX_USUARIO   = 5;    // falhas por usuário antes do bloqueio
const LS_MAX_IP        = 20;   // falhas por IP antes do bloqueio
const LS_JANELA_MIN    = 15;   // falhas mais velhas que isso são esquecidas
const LS_BLOQUEIO_MIN  = 15;   // duração do primeiro bloqueio
const LS_BLOQUEIO_TETO = 60;   // duração máxima em reincidência

/** Garante a tabela. Silencioso: nunca derruba a tela de login. */
function ls_preparar(mysqli $conn): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $r = @$conn->query("SHOW TABLES LIKE 'login_tentativas'");
    $ok = ($r && $r->num_rows > 0);
    return $ok;
}

/** Normaliza a chave de contagem */
function ls_chave(string $tipo, string $valor): string {
    return ($tipo === 'USUARIO' ? 'U:' : 'I:') . strtolower(trim($valor));
}

/**
 * Estado atual de uma chave.
 * Retorna ['bloqueado'=>bool, 'segundos'=>int, 'tentativas'=>int]
 */
function ls_estado(mysqli $conn, string $tipo, string $valor): array {
    $vazio = ['bloqueado' => false, 'segundos' => 0, 'tentativas' => 0];
    if (!ls_preparar($conn) || trim($valor) === '') return $vazio;

    $chave = ls_chave($tipo, $valor);
    $st = $conn->prepare("
        SELECT tentativas, bloqueios,
               GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), bloqueado_ate)) AS restam,
               TIMESTAMPDIFF(MINUTE, ultima_falha, NOW())               AS idade
        FROM login_tentativas WHERE chave = ? LIMIT 1");
    if (!$st) return $vazio;
    $st->bind_param('s', $chave);
    $st->execute();
    $r = $st->get_result();
    $row = $r ? $r->fetch_assoc() : null;
    $st->close();
    if (!$row) return $vazio;

    $restam = (int)$row['restam'];
    if ($restam > 0) {
        return ['bloqueado' => true, 'segundos' => $restam, 'tentativas' => (int)$row['tentativas']];
    }
    // Fora da janela e sem bloqueio ativo: contador já não vale
    $tent = ((int)$row['idade'] >= LS_JANELA_MIN) ? 0 : (int)$row['tentativas'];
    return ['bloqueado' => false, 'segundos' => 0, 'tentativas' => $tent];
}

/**
 * Verifica se o login deve ser barrado antes mesmo de consultar a senha.
 * Retorna null se liberado, ou a mensagem de bloqueio.
 */
function ls_verificar(mysqli $conn, string $usuario, string $ip): ?string {
    $eu = ls_estado($conn, 'USUARIO', $usuario);
    if ($eu['bloqueado']) {
        return 'Muitas tentativas com este usuário. Aguarde ' . ls_tempo($eu['segundos']) . ' e tente novamente.';
    }
    $ei = ls_estado($conn, 'IP', $ip);
    if ($ei['bloqueado']) {
        return 'Muitas tentativas a partir desta rede. Aguarde ' . ls_tempo($ei['segundos']) . '.';
    }
    return null;
}

/** Formata segundos em texto curto */
function ls_tempo(int $seg): string {
    if ($seg <= 60) return $seg . ' segundo' . ($seg === 1 ? '' : 's');
    $min = (int)ceil($seg / 60);
    return $min . ' minuto' . ($min === 1 ? '' : 's');
}

/** Registra uma falha e aplica o bloqueio quando o limite é atingido. */
function ls_registrar_falha(mysqli $conn, string $usuario, string $ip): void {
    if (!ls_preparar($conn)) return;
    foreach ([['USUARIO', $usuario, LS_MAX_USUARIO], ['IP', $ip, LS_MAX_IP]] as [$tipo, $valor, $limite]) {
        if (trim($valor) === '') continue;
        $chave = ls_chave($tipo, $valor);

        // Zera o contador se a última falha ficou fora da janela.
        $st = $conn->prepare("
            INSERT INTO login_tentativas (chave, tipo, valor, tentativas, primeira_falha, ultima_falha)
            VALUES (?, ?, ?, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                tentativas = IF(TIMESTAMPDIFF(MINUTE, ultima_falha, NOW()) >= " . LS_JANELA_MIN . "
                                AND (bloqueado_ate IS NULL OR bloqueado_ate < NOW()),
                                1, tentativas + 1),
                ultima_falha = NOW()");
        if ($st) {
            $st->bind_param('sss', $chave, $tipo, $valor);
            $st->execute(); $st->close();
        }

        // Atingiu o limite? Bloqueia, com duração crescente a cada reincidência.
        $st2 = $conn->prepare("
            UPDATE login_tentativas
            SET bloqueios     = bloqueios + 1,
                -- bloqueios já foi incrementado na linha acima (MySQL avalia o SET
                -- da esquerda para a direita), por isso o expoente usa -1:
                -- 1º bloqueio = 15 min, 2º = 30 min, 3º em diante = 60 min.
                bloqueado_ate = DATE_ADD(NOW(), INTERVAL CAST(LEAST(?, ? * POW(2, bloqueios - 1)) AS SIGNED) MINUTE),
                tentativas    = 0
            WHERE chave = ? AND tentativas >= ?
              AND (bloqueado_ate IS NULL OR bloqueado_ate < NOW())");
        if ($st2) {
            $teto = LS_BLOQUEIO_TETO; $base = LS_BLOQUEIO_MIN;
            $st2->bind_param('iisi', $teto, $base, $chave, $limite);
            $st2->execute(); $st2->close();
        }
    }
}

/** Limpa o contador do usuário depois de um login bem-sucedido. */
function ls_registrar_sucesso(mysqli $conn, string $usuario): void {
    if (!ls_preparar($conn)) return;
    $chave = ls_chave('USUARIO', $usuario);
    $st = $conn->prepare("DELETE FROM login_tentativas WHERE chave = ?");
    if ($st) { $st->bind_param('s', $chave); $st->execute(); $st->close(); }
}

/**
 * Atraso proporcional às falhas recentes. Não incomoda quem digitou errado
 * uma vez e torna inviável varrer milhares de senhas por minuto.
 */
function ls_atraso(int $tentativas): void {
    $ms = min(2000, 300 + ($tentativas * 350));
    usleep($ms * 1000);
}
