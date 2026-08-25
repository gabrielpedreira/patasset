<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('Content-Type: application/json');
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}
include 'conexao.php';

// Mesma porta da página nao_localizados.php: A ou B + classe do patrimônio.
seg_exigir_permissao($conn, ['A', 'B'], ['DEV', 'PATRIMONIO']);

$unidade=$_POST['unidade'] ?? '';
$setor=$_POST['setor'] ?? '';

$stmt=$conn->prepare("
SELECT id,
movimentado_definitivo,movimentado,data_movimentacao,folha,
unidade_destino,setor_destino,area_destino,
obs_movimentacao,usuario_movimentacao,
unidade,setor,pavimento,area,
tag_antiga,tag_trocada,
propriedade,empresa,tag_alugado,
descricao,marca,modelo,serie,
observacao,data_inspecao,usuario_inspecao,encontrado
FROM cadastro
WHERE unidade=? AND setor=? 
AND LOWER(REPLACE(encontrado,'ã','a'))='nao'
");

$stmt->bind_param("ss",$unidade,$setor);
$stmt->execute();
$res=$stmt->get_result();

echo json_encode($res->fetch_all(MYSQLI_ASSOC));