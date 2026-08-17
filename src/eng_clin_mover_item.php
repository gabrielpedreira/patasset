<?php
/**
 * eng_clin_mover_item.php
 * ═══════════════════════════════════════════════════════════════════════════
 * FONTE ÚNICA de movimentação patrimonial do LifeTech.
 *
 * Executa, numa única chamada, as três coisas que toda movimentação exige:
 *   1. Atualiza os campos de destino em `cadastro`
 *   2. Registra em `historico` (mesma tabela e mesmas 15 colunas usadas por
 *      movimentar_action.php do PatAsset)
 *   3. Notifica o PatAsset por e-mail
 *
 * MOTIVO DE EXISTIR: antes cada ponto do fluxo repetia esse bloco à mão, e um
 * deles tinha 6 placeholders com 5 valores no bind_param — a movimentação de
 * saída falhava silenciosamente ("The number of variables must match...").
 * Centralizando, o erro não pode se repetir em três lugares diferentes.
 *
 * ── USO ────────────────────────────────────────────────────────────────────
 *   require_once 'eng_clin_mover_item.php';
 *
 *   $r = eng_clin_mover_item($conn, $item_id, [
 *       'unidade'  => null,                    // null = mantém a atual
 *       'setor'    => 'ENGENHARIA CLINICA',
 *       'area'     => 'SALA DE MANUTENÇÃO',
 *       'tipo_mov' => 'MANUTENCAO',
 *       'obs'      => "OS CH-000001 iniciada",
 *       'usuario'  => $usuario,
 *   ]);
 *
 *   $r['ok']       true/false
 *   $r['erro']     mensagem, quando ok = false
 *   $r['tag']      tag usada
 *   $r['de']       'UNIDADE / SETOR' de origem
 *   $r['para']     'UNIDADE / SETOR / AREA' de destino
 * ═══════════════════════════════════════════════════════════════════════════
 */

if (!function_exists('eng_clin_mover_item')) {

function eng_clin_mover_item(mysqli $conn, int $item_id, array $d): array {

    $out = ['ok' => false, 'erro' => '', 'tag' => '', 'de' => '', 'para' => ''];

    if ($item_id <= 0) { $out['erro'] = 'ID do item inválido.'; return $out; }

    $tipo_mov = $d['tipo_mov'] ?? 'INTERNA';
    $obs      = (string)($d['obs']     ?? '');
    $usuario  = (string)($d['usuario'] ?? '');
    $data_mov = $d['data'] ?? date('Y-m-d');

    /* ── 1. Ler o estado atual do item ──────────────────────────────────── */
    $stC = $conn->prepare("
        SELECT tag_antiga, tag_trocada, descricao, marca, modelo, serie,
               unidade, setor, unidade_destino, setor_destino, area_destino
        FROM cadastro WHERE id=? LIMIT 1
    ");
    if (!$stC) { $out['erro'] = 'Falha ao preparar leitura do cadastro.'; return $out; }
    $stC->bind_param('i', $item_id);
    $stC->execute();
    $resC = $stC->get_result();
    $it   = $resC ? $resC->fetch_assoc() : null;
    $stC->close();

    if (!$it) { $out['erro'] = "Item #{$item_id} não encontrado no cadastro."; return $out; }

    // Origem = onde o item está agora. Mesmo critério de movimentar_action.php:
    // usa o destino da última movimentação e cai para unidade/setor de cadastro
    // se o item nunca foi movimentado.
    $uni_de = !empty($it['unidade_destino']) ? $it['unidade_destino'] : ($it['unidade'] ?? '');
    $set_de = !empty($it['setor_destino'])   ? $it['setor_destino']   : ($it['setor']   ?? '');

    // Destino: null/'' mantém o valor atual (usado para não mexer na unidade)
    $uni_para = ($d['unidade'] ?? null) !== null && $d['unidade'] !== '' ? $d['unidade'] : $uni_de;
    $set_para = ($d['setor']   ?? null) !== null && $d['setor']   !== '' ? $d['setor']   : $set_de;
    $are_para = (string)($d['area'] ?? '');
    $pav_para = (string)($d['pavimento'] ?? '');

    $tag = $it['tag_trocada'] ?: $it['tag_antiga'];

    /* ── 2. Atualizar o destino em `cadastro` ───────────────────────────── */
    // 8 placeholders / 8 valores — a contagem que estava errada antes.
    $stU = $conn->prepare("
        UPDATE cadastro SET
            unidade_destino      = ?,
            setor_destino        = ?,
            area_destino         = ?,
            data_movimentacao    = ?,
            obs_movimentacao     = ?,
            movimentado          = 'SIM',
            usuario_movimentacao = ?
        WHERE id = ? LIMIT 1
    ");
    if (!$stU) { $out['erro'] = 'Falha ao preparar update do cadastro: '.$conn->error; return $out; }
    $stU->bind_param('ssssssi',
        $uni_para, $set_para, $are_para, $data_mov, $obs, $usuario, $item_id
    );
    if (!$stU->execute()) {
        $out['erro'] = 'Falha ao atualizar cadastro: '.$stU->error;
        $stU->close(); return $out;
    }
    $stU->close();

    /* ── 3. Registrar em `historico` (padrão do PatAsset) ───────────────── */
    // ID e DATA: id é auto_increment, data vem em $data_mov.
    $stH = $conn->prepare("
        INSERT INTO historico (
            data, descricao, marca, modelo, serie, tag,
            unidade, setor,
            unidade_dest, setor_dest, pav_dest, local_dest,
            obs_mov, tipo_mov, usuario_mov
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    if ($stH) {
        // 15 placeholders / 15 tipos / 15 valores
        $desc = (string)($it['descricao'] ?? '');
        $marc = (string)($it['marca']     ?? '');
        $mode = (string)($it['modelo']    ?? '');
        $seri = (string)($it['serie']     ?? '');
        $stH->bind_param('sssssssssssssss',
            $data_mov, $desc, $marc, $mode, $seri, $tag,
            $uni_de, $set_de,
            $uni_para, $set_para, $pav_para, $are_para,
            $obs, $tipo_mov, $usuario
        );
        $stH->execute();
        $stH->close();
    }

    /* ── 4. Notificar o PatAsset por e-mail ─────────────────────────────── */
    // Falha de e-mail nunca interrompe a movimentação.
    if (file_exists(__DIR__ . '/eng_clin_notificar_movimentacao.php')) {
        require_once __DIR__ . '/eng_clin_notificar_movimentacao.php';
        if (function_exists('eng_clin_notificar_movimentacao')) {
            try {
                eng_clin_notificar_movimentacao([
                    'tag'       => $tag,
                    'descricao' => $it['descricao'],
                    'marca'     => $it['marca'],
                    'modelo'    => $it['modelo'],
                    'serie'     => $it['serie'],
                    'uni_orig'  => $uni_de,
                    'set_orig'  => $set_de,
                    'uni_dest'  => $uni_para,
                    'set_dest'  => $set_para,
                    'area_dest' => $are_para,
                    'tipo_mov'  => $tipo_mov,
                    'obs'       => $obs,
                    'usuario'   => $usuario,
                    'data'      => $data_mov,
                ]);
            } catch (\Throwable $e) {
                error_log('[LifeTech] e-mail de movimentação falhou: '.$e->getMessage());
            }
        }
    }

    $out['ok']   = true;
    $out['tag']  = $tag;
    $out['de']   = trim(($uni_de ?: '—') . ' / ' . ($set_de ?: '—'));
    $out['para'] = trim(($uni_para ?: '—') . ' / ' . ($set_para ?: '—') . ($are_para ? ' / '.$are_para : ''));
    return $out;
}

/* ═══════════════════════════════════════════════════════════════════════════
   Destinos padronizados do fluxo de OS
   ═══════════════════════════════════════════════════════════════════════════ */

/** Saída para manutenção interna: mantém a unidade, troca setor e área. */
function eng_clin_destino_manutencao(): array {
    return [
        'unidade'  => null,                    // mantém a unidade atual
        'setor'    => 'ENGENHARIA CLINICA',
        'area'     => 'SALA DE MANUTENÇÃO',
        'tipo_mov' => 'MANUTENCAO',
    ];
}

/** Saída para manutenção externa (empresa terceirizada). */
function eng_clin_destino_externa(): array {
    return [
        'unidade'  => 'EMPRESA EXTERNA',
        'setor'    => 'MANUTENÇÃO EXTERNA',
        'area'     => '',
        'tipo_mov' => 'MANUTENCAO',
    ];
}

/** Devolução ao local registrado na abertura do chamado. */
function eng_clin_destino_retorno(array $os): array {
    return [
        'unidade'   => $os['loc_orig_unidade'] ?: null,
        'setor'     => $os['loc_orig_setor']   ?: null,
        'area'      => $os['loc_orig_area']    ?: '',
        'pavimento' => $os['loc_orig_pav']     ?: '',
        'tipo_mov'  => 'RETORNO',
    ];
}

} // function_exists
