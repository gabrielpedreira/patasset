<?php
session_start();
include "conexao.php";

header("Content-Type: application/json");

// Recebe JSON
$data = json_decode(file_get_contents("php://input"), true);

if(!$data){
    echo json_encode(["ok"=>false,"msg"=>"Dados inválidos"]);
    exit;
}

$id          = $data["id"] ?? null;
$tarefa      = $data["tarefa"] ?? "";
$unidade     = $data["unidade"] ?? "";
$prioridade  = $data["prioridade"] ?? "";
$responsavel = $data["responsavel"] ?? "";
$inicio      = $data["inicio"] ?? "";
$dia         = $data["dia"] ?? "";
$stts      = $data["stts"] ?? "";
$obs         = $data["obs"] ?? "";


/* =========================
   CONVERTE DATA
========================= */

if($inicio){
    // DD/MM/AAAA -> AAAA-MM-DD
    $p = explode("/",$inicio);
    if(count($p)==3){
        $inicio = $p[2]."-".$p[1]."-".$p[0];
    }
}


/* =========================
   UPDATE OU INSERT
========================= */

if($id){

    // Atualizar
    $sql = "UPDATE cronograma SET
        tarefa=?,
        unidade=?,
        prioridade=?,
        responsavel=?,
        inicio=?,
        dia=?,
        stts=?,
        obs=?
        WHERE id=?";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        "ssssssssi",
        $tarefa,
        $unidade,
        $prioridade,
        $responsavel,
        $inicio,
        $dia,
        $stts,
        $obs,
        $id
    );

}else{

    // Novo
    $sql = "INSERT INTO cronograma
    (tarefa,unidade,prioridade,responsavel,inicio,dia,stts,obs)
    VALUES (?,?,?,?,?,?,?,?)";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        "ssssssss",
        $tarefa,
        $unidade,
        $prioridade,
        $responsavel,
        $inicio,
        $dia,
        $stts,
        $obs
    );
}


if($stmt->execute()){
    echo json_encode(["ok"=>true]);
}else{
    echo json_encode([
        "ok"=>false,
        "erro"=>$stmt->error
    ]);
}

$stmt->close();
$conn->close();
