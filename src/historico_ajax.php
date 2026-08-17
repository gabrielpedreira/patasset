<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_logado'])) {
    echo json_encode(['ok' => false, 'msg' => 'Sessão expirada.']);
    exit();
}

require_once __DIR__ . '/conexao.php';

/* ── autenticação: apenas DEV pode salvar/excluir ── */
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $_SESSION['usuario_logado']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$isDev = ($row['classe_usuario'] === 'DEV');

if (!$row
    || (!$isDev && $row['status'] !== 'ATIVO')
    || !in_array($row['permicao'],       ['A','B'], true)
    || !in_array($row['classe_usuario'], ['DEV','PATRIMONIO'], true)
    || !$isDev) {
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado. Apenas DEV pode editar/excluir.']);
    exit();
}

$acao = $_POST['acao'] ?? '';

/* ══════════════════════════════
   SALVAR ALTERAÇÕES
══════════════════════════════ */
if ($acao === 'salvar') {
    $linhas = json_decode($_POST['linhas'] ?? '[]', true);
    if (!is_array($linhas) || empty($linhas)) {
        echo json_encode(['ok' => false, 'msg' => 'Nenhuma linha recebida.']);
        exit();
    }

    /* colunas permitidas para UPDATE */
    $permitidas = ['data','descricao','marca','modelo','serie','tag',
                   'unidade','setor','unidade_dest','setor_dest',
                   'local_dest','obs_mov','tipo_mov','usuario_mov'];

    $salvos = 0; $erros = 0;

    foreach ($linhas as $linha) {
        $id = (int)($linha['id'] ?? 0);
        if ($id <= 0) { $erros++; continue; }

        $sets   = [];
        $params = [];
        $types  = '';

        foreach ($permitidas as $col) {
            if (!array_key_exists($col, $linha)) continue;
            $sets[]   = "`$col` = ?";
            $params[] = $linha[$col];
            $types   .= 's';
        }

        if (empty($sets)) { $erros++; continue; }

        $params[] = $id;
        $types   .= 'i';

        $stmt = $conn->prepare("UPDATE historico SET " . implode(', ', $sets) . " WHERE id = ?");
        if (!$stmt) { $erros++; continue; }
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) $salvos++;
        else $erros++;
        $stmt->close();
    }

    echo json_encode([
        'ok'  => $erros === 0,
        'msg' => $erros === 0
                 ? "{$salvos} registro(s) salvo(s) com sucesso!"
                 : "{$salvos} salvo(s), {$erros} erro(s).",
    ]);
    exit();
}

/* ══════════════════════════════
   EXCLUIR
══════════════════════════════ */
if ($acao === 'excluir') {
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    if (!is_array($ids) || empty($ids)) {
        echo json_encode(['ok' => false, 'msg' => 'Nenhum ID recebido.']);
        exit();
    }

    /* sanitiza: apenas inteiros positivos */
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (empty($ids)) {
        echo json_encode(['ok' => false, 'msg' => 'IDs inválidos.']);
        exit();
    }

    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt = $conn->prepare("DELETE FROM historico WHERE id IN ($ph)");
    $stmt->bind_param($types, ...$ids);

    if ($stmt->execute()) {
        $n = $stmt->affected_rows;
        echo json_encode(['ok' => true, 'msg' => "{$n} registro(s) excluído(s) com sucesso!"]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Erro ao excluir: ' . $stmt->error]);
    }
    $stmt->close();
    exit();
}

echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida.']);