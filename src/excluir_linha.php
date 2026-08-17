<?php
session_start();
require_once "conexao.php";

header('Content-Type: application/json');

if(!isset($_SESSION['usuario_logado'])){
    echo json_encode([
        'sucesso'=>false,
        'mensagem'=>'Não autenticado'
    ]);
    exit();
}


// PERMISSÃO
$usuario = $_SESSION['usuario_logado'];

$stmt = $conn->prepare("SELECT permicao FROM usuarios WHERE usuario=?");
$stmt->bind_param("s",$usuario);
$stmt->execute();
$res = $stmt->get_result();

$perm = ($r = $res->fetch_assoc()) ? $r['permicao'] : '';

$stmt->close();

if(!in_array($perm, ['A', 'B'])){
    echo json_encode([
        'sucesso'=>false,
        'mensagem'=>'Sem permissão'
    ]);
    exit();
}


// RECEBE ID
$dados = json_decode(file_get_contents("php://input"), true);

$id = intval($dados['id'] ?? 0);

if($id <= 0){

    echo json_encode([
        'sucesso'=>false,
        'mensagem'=>'ID inválido'
    ]);
    exit();
}


// EXCLUI
$stmt = $conn->prepare("DELETE FROM cadastro WHERE id=? LIMIT 1");
$stmt->bind_param("i",$id);


if($stmt->execute()){

    echo json_encode([
        'sucesso'=>true
    ]);

}else{

    echo json_encode([
        'sucesso'=>false,
        'mensagem'=>$stmt->error
    ]);
}

$stmt->close();
$conn->close();