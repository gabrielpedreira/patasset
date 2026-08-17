<?php
session_start();
include 'conexao.php';

header('Content-Type: application/json');

// ─── AUTENTICAÇÃO ─────────────────────────────────────────────────────────────
$usuario_logado = $_SESSION['usuario_logado'] ?? '';
if (!$usuario_logado) {
    echo json_encode(['sucesso' => false, 'erro' => 'Sessão expirada.']);
    exit();
}

// ─── RECEBE JSON ──────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['itens'])) {
    echo json_encode(['sucesso' => false, 'erro' => 'Nenhum item recebido.']);
    exit();
}

$itens        = $input['itens'];
$assistente   = trim($input['assistente']   ?? '');
$unidade      = trim($input['unidade']      ?? '');
$acompanhante = trim($input['acompanhante'] ?? '');
$protocolo    = trim($input['protocolo']    ?? '');
$dt_descarte  = date('Y-m-d');

if (!$assistente || !$unidade || !$acompanhante || !$protocolo) {
    echo json_encode(['sucesso' => false, 'erro' => 'Dados obrigatórios ausentes.']);
    exit();
}

// ─── INICIA TRANSAÇÃO ─────────────────────────────────────────────────────────
$conn->begin_transaction();

try {

    // ── 1. INSERT NA TABELA baixa_definitiva ──────────────────────────────────
    $stmtIns = $conn->prepare("
        INSERT INTO baixa_definitiva
            (tag, descricao, marca, modelo, serie, unidade, setor,
             obs, foto, dt_descarte, nao_conformidade, protocolo,
             ass_patrimonio, ass_acompanhante, resp_tecnico)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($itens as $item) {
        $id           = (int)($item['id']             ?? 0);
        $tag          = strtoupper(trim($item['tag']              ?? ''));
        $descricao    = strtoupper(trim($item['descricao']        ?? ''));
        $marca        = strtoupper(trim($item['marca']            ?? ''));
        $modelo       = strtoupper(trim($item['modelo']           ?? ''));
        $serie        = strtoupper(trim($item['serie']            ?? ''));
        $unidade_item = strtoupper(trim($item['unidade_origem']   ?? $unidade));
        $setor        = strtoupper(trim($item['setor_origem']     ?? ''));
        $obs          = trim($item['obs']                         ?? '');
        $resp_tecnico = strtoupper(trim($item['resp_tecnico']     ?? ''));
        $nao_conf     = ($item['nao_conformidade'] === 'SIM') ? 'SIM' : null;

        if (empty($tag)) {
            $nao_conf = 'SEM TAG';
        }

        // Foto: vem como base64 do front, converte para binário
        $foto = null;
        if (!empty($item['foto_base64'])) {
            $fotoBin = base64_decode($item['foto_base64']);
            $isPng   = substr($fotoBin, 0, 4) === "\x89PNG";
            $isJpg   = substr($fotoBin, 0, 2) === "\xFF\xD8";
            if ($isPng || $isJpg) {
                $foto = $fotoBin;
            }
        }

        $ass_patrimonio   = $assistente;
        $ass_acompanhante = $acompanhante;

        $stmtIns->bind_param(
            "sssssssssssssss",
            $tag, $descricao, $marca, $modelo, $serie,
            $unidade_item, $setor, $obs, $foto,
            $dt_descarte, $nao_conf, $protocolo,
            $ass_patrimonio, $ass_acompanhante, $resp_tecnico
        );

        if (!$stmtIns->execute()) {
            throw new Exception('Erro ao inserir em baixa_definitiva: ' . $stmtIns->error);
        }

        // ── 2. ATUALIZA STATUS NA TABELA cadastro ─────────────────────────────
        if (!empty($tag)) {
            $status  = 'BAIXADO';
            $stmtUpd = $conn->prepare("
                UPDATE cadastro
                SET status = ?, data_baixa = ?
                WHERE tag_antiga = ? OR tag_trocada = ?
            ");
            $stmtUpd->bind_param("ssss", $status, $dt_descarte, $tag, $tag);
            $stmtUpd->execute();
            $stmtUpd->close();
        }

        // ── 3. REMOVE DA TABELA pre_descarte ──────────────────────────────────
        if ($id > 0) {
            $stmtDel = $conn->prepare("DELETE FROM pre_descarte WHERE id = ?");
            $stmtDel->bind_param("i", $id);
            if (!$stmtDel->execute()) {
                throw new Exception('Erro ao remover de pre_descarte: ' . $stmtDel->error);
            }
            $stmtDel->close();
        }
    }

    $stmtIns->close();
    $conn->commit();

    // ─── GRAVA DADOS NA SESSÃO PARA O RELATÓRIO ───────────────────────────────
    $_SESSION['relatorio_itens']        = $itens;
    $_SESSION['relatorio_protocolo']    = $protocolo;
    $_SESSION['relatorio_assistente']   = $assistente;
    $_SESSION['relatorio_acompanhante'] = $acompanhante;
    $_SESSION['relatorio_unidade']      = $unidade;

    echo json_encode(['sucesso' => true]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
}

$conn->close();
?>