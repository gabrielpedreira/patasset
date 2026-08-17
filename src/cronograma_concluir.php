<?php
session_start();
require "conexao.php";

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_logado'])) {
    echo json_encode(["ok"=>false,"erro"=>"Não autorizado"]);
    exit();
}

$dados = json_decode(file_get_contents("php://input"), true);

if(!$dados){
    echo json_encode(["ok"=>false,"erro"=>"Dados não recebidos"]);
    exit();
}

$dataFormatada = null;

if(!empty($dados['inicio'])){
    $dt = DateTime::createFromFormat('d/m/Y', $dados['inicio']);
    if($dt){
        $dataFormatada = $dt->format('Y-m-d');
    }else{
        throw new Exception("Data inválida");
    }
}

// Força stts = 'CONCLUIDO' independente do que vier do front
$stts = 'CONCLUIDO';

$conn->begin_transaction();

try{

    $stmt = $conn->prepare("
        INSERT INTO registro_atividades
        (tarefa, unidade, responsavel, inicio, dia, stts, obs)
        VALUES (?,?,?,?,?,?,?)
    ");

    if(!$stmt) throw new Exception($conn->error);

    $stmt->bind_param(
        "sssssss",
        $dados['tarefa'],
        $dados['unidade'],
        $dados['responsavel'],
        $dataFormatada,
        $dados['dia'],
        $stts,
        $dados['obs']
    );

    if(!$stmt->execute()) throw new Exception($stmt->error);

    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM cronograma WHERE id=?");

    if(!$stmt) throw new Exception($conn->error);

    $stmt->bind_param("i", $dados['id']);

    if(!$stmt->execute()) throw new Exception($stmt->error);

    $stmt->close();

    $conn->commit();

    echo json_encode(["ok"=>true]);

}catch(Exception $e){

    $conn->rollback();

    echo json_encode(["ok"=>false,"erro"=>$e->getMessage()]);
}

$conn->close();