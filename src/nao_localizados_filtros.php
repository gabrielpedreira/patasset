<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('Content-Type: application/json');
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}
include 'conexao.php';

$nivel = $_GET['nivel'] ?? '';

if($nivel=='unidade'){
    $sql="SELECT DISTINCT unidade FROM cadastro 
          WHERE LOWER(REPLACE(encontrado,'ã','a')) IN ('nao')
          ORDER BY unidade";
    $r=$conn->query($sql);
    echo json_encode($r->fetch_all(MYSQLI_ASSOC));
    exit;
}

if($nivel=='setor'){
    $unidade=$_GET['unidade'] ?? '';
    $stmt=$conn->prepare("SELECT DISTINCT setor FROM cadastro 
        WHERE unidade=? 
        AND LOWER(REPLACE(encontrado,'ã','a')) IN ('nao')
        ORDER BY setor");
    $stmt->bind_param("s",$unidade);
    $stmt->execute();
    $res=$stmt->get_result();
    echo json_encode($res->fetch_all(MYSQLI_ASSOC));
    exit;
}