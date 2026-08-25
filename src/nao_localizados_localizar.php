<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('Content-Type: application/json');
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}
include 'conexao.php';

// Marcar item como localizado altera o cadastro: exige A ou B + classe dona
// do patrimônio, igual à página nao_localizados.php. Ver seg_exigir_permissao().
seg_exigir_permissao($conn, ['A', 'B'], ['DEV', 'PATRIMONIO']);

$data=json_decode(file_get_contents("php://input"),true);
$id=$data['id'] ?? 0;

$stmt=$conn->prepare("UPDATE cadastro SET encontrado='SIM' WHERE id=?");
$stmt->bind_param("i",$id);

if($stmt->execute()){
    echo json_encode(["sucesso"=>true]);
}else{
    echo json_encode(["erro"=>"Erro ao atualizar"]);
}