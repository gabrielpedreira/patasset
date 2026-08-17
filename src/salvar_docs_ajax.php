<?php
/**
 * salvar_docs_ajax.php
 * Endpoint AJAX exclusivo para salvar alterações na tabela de documentos.
 */

register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'Erro interno: ' . $e['message']]);
    }
});

ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/conexao.php';

header('Content-Type: application/json; charset=utf-8');

/* ── autenticação ── */
$usuario_logado = $_SESSION['usuario_logado'] ?? '';
if (!$usuario_logado) {
    echo json_encode(['ok' => false, 'msg' => 'Sessão expirada. Recarregue a página.']);
    exit();
}

$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario = ?");
if (!$stmt) {
    echo json_encode(['ok' => false, 'msg' => 'Erro de banco: ' . $conn->error]);
    exit();
}
$stmt->bind_param("s", $usuario_logado);
$stmt->execute();
$res = $stmt->get_result();
$permicao = $classe_usuario = ''; $status = 'ATIVO';
if ($row = $res->fetch_assoc()) {
    $permicao       = $row['permicao'];
    $classe_usuario = $row['classe_usuario'];
    $status         = $row['status'] ?? 'ATIVO';
}
$stmt->close();

if ($status !== 'ATIVO' || !in_array($permicao, ['A','B']) || !in_array($classe_usuario, ['DEV','PATRIMONIO'])) {
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado.']);
    exit();
}

/* ── valida ação ── */
if (($_POST['acao'] ?? '') !== 'salvar_linhas') {
    echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
    exit();
}

/* ── processa linhas ── */
$linhas = json_decode($_POST['linhas'] ?? '[]', true);
if (!is_array($linhas) || empty($linhas)) {
    echo json_encode(['ok' => false, 'msg' => 'Nenhuma linha recebida.']);
    exit();
}

$erros  = 0;
$salvos = 0;

foreach ($linhas as $linha) {
    $id             = (int)($linha['id']            ?? 0);
    $tipo_doc_e     = trim($linha['tipo_doc']       ?? '');
    $titulo_doc_e   = trim($linha['titulo_doc']     ?? '');
    $numero_nota_e  = trim($linha['numero_nota']    ?? '');
    $numero_serie_e = trim($linha['numero_serie']   ?? '');
    $tag_pat_e      = trim($linha['tag_patrimonio'] ?? '');

    /* converte data dd/mm/yyyy → yyyy-mm-dd */
    $dt_raw = trim($linha['dt_referencia'] ?? '');
    $dt_ref = '';
    if ($dt_raw !== '') {
        $dt_raw = str_replace('/', '-', $dt_raw);
        $dt_obj = DateTime::createFromFormat('d-m-Y', $dt_raw)
               ?: DateTime::createFromFormat('Y-m-d', $dt_raw);
        if ($dt_obj) $dt_ref = $dt_obj->format('Y-m-d');
    }

    if ($id <= 0) { $erros++; continue; }

    if ($dt_ref === '') {
        /* sem data: 5 strings + 1 int = "ssssi" + id → "sssssi" — 6 tipos, 6 vars */
        $stmt = $conn->prepare(
            "UPDATE nota SET tipo_doc=?, titulo_doc=?, dt_referencia=NULL,
             numero_nota=?, numero_serie=?, tag_patrimonio=? WHERE id=?"
        );
        if (!$stmt) { $erros++; continue; }
        $stmt->bind_param("sssssi",
            $tipo_doc_e, $titulo_doc_e,
            $numero_nota_e, $numero_serie_e, $tag_pat_e, $id
        );
    } else {
        /* com data: 6 strings + 1 int = "ssssssi" — 7 tipos, 7 vars */
        $stmt = $conn->prepare(
            "UPDATE nota SET tipo_doc=?, titulo_doc=?, dt_referencia=?,
             numero_nota=?, numero_serie=?, tag_patrimonio=? WHERE id=?"
        );
        if (!$stmt) { $erros++; continue; }
        $stmt->bind_param("ssssssi",
            $tipo_doc_e, $titulo_doc_e, $dt_ref,
            $numero_nota_e, $numero_serie_e, $tag_pat_e, $id
        );
    }

    if ($stmt->execute()) $salvos++;
    else $erros++;
    $stmt->close();
}

echo json_encode([
    'ok'     => $erros === 0,
    'msg'    => $erros === 0
                ? "Alterações salvas! ({$salvos} registro(s) atualizado(s))"
                : "Salvo com {$erros} erro(s). {$salvos} registro(s) atualizado(s).",
    'salvos' => $salvos,
    'erros'  => $erros,
]);