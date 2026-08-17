<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_logado'])) {
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}

require_once 'conexao.php';

$body = file_get_contents('php://input');
$itens = json_decode($body, true);

if (!is_array($itens) || count($itens) === 0) {
    echo json_encode(['erro' => 'Nenhum item recebido']);
    exit;
}

$usuario = $_SESSION['usuario_logado'];

foreach ($itens as $item) {
    $id              = intval($item['id'] ?? 0);
    $unidade         = $item['unidade']         ?? '';
    $setor           = $item['setor']           ?? '';
    $pavimento       = $item['pavimento']       ?? '';
    $area            = $item['area']            ?? '';
    $unidade_destino = $item['unidade_destino'] ?? '';
    $setor_destino   = $item['setor_destino']   ?? '';
    $area_destino    = $item['area_destino']    ?? '';
    $descricao       = $item['descricao']       ?? '';
    $marca           = $item['marca']           ?? '';
    $modelo          = $item['modelo']          ?? '';
    $serie           = $item['serie']           ?? '';
    $tag_antiga      = $item['tag_antiga']      ?? '';
    $tag_trocada     = $item['tag_trocada']     ?? '';
    $encontrado      = $item['encontrado']      ?? '';
    $estado          = $item['estado']          ?? '';
    $obs3            = $item['obs3']            ?? '';
    $n_conformidade  = $item['n_conformidade']  ?? '';
    $status2         = $item['status2']         ?? '';
    $o_servico       = $item['o_servico']       ?? '';
    $reg_inspecao    = !empty($item['_registrar_inspecao']);

    if ($id <= 0) continue;

    if ($reg_inspecao) {
        $stmt = $conn->prepare(
            "UPDATE cadastro
             SET unidade=?, setor=?, pavimento=?, area=?,
                 unidade_destino=?, setor_destino=?, area_destino=?,
                 descricao=?, marca=?, modelo=?, serie=?, tag_antiga=?, tag_trocada=?,
                 encontrado=?, estado=?, obs3=?, n_conformidade=?, status2=?, o_servico=?,
                 usuario_inspecao=?, data_inspecao=NOW()
             WHERE id=?"
        );
        if (!$stmt) { echo json_encode(['erro' => $conn->error]); exit; }
        $stmt->bind_param('ssssssssssssssssssssi',
            $unidade, $setor, $pavimento, $area,
            $unidade_destino, $setor_destino, $area_destino,
            $descricao, $marca, $modelo, $serie, $tag_antiga, $tag_trocada,
            $encontrado, $estado, $obs3, $n_conformidade, $status2, $o_servico,
            $usuario, $id);
    } else {
        $stmt = $conn->prepare(
            "UPDATE cadastro
             SET unidade=?, setor=?, pavimento=?, area=?,
                 unidade_destino=?, setor_destino=?, area_destino=?,
                 descricao=?, marca=?, modelo=?, serie=?, tag_antiga=?, tag_trocada=?,
                 encontrado=?, estado=?, obs3=?, n_conformidade=?, status2=?, o_servico=?
             WHERE id=?"
        );
        if (!$stmt) { echo json_encode(['erro' => $conn->error]); exit; }
        $stmt->bind_param('sssssssssssssssssssi',
            $unidade, $setor, $pavimento, $area,
            $unidade_destino, $setor_destino, $area_destino,
            $descricao, $marca, $modelo, $serie, $tag_antiga, $tag_trocada,
            $encontrado, $estado, $obs3, $n_conformidade, $status2, $o_servico, $id);
    }

    if (!$stmt->execute()) {
        echo json_encode(['erro' => $stmt->error]);
        $stmt->close();
        exit;
    }
    $stmt->close();
}

echo json_encode(['sucesso' => true]);
