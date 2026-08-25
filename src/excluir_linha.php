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

// Excluir item exige A ou B + classe dona do patrimônio. A checagem de nível
// já existia aqui; faltava a classe, então um usuário de outra classe (ex.:
// ENGENHARIA CLINICA) com nível A ou B conseguia excluir patrimônio.
// Unificado com o resto do sistema. Ver seg_exigir_permissao().
seg_exigir_permissao($conn, ['A', 'B'], ['DEV', 'PATRIMONIO']);


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


/*
 * Item conciliado exige confirmação explícita.
 *
 * Excluir um item conciliado apaga o vínculo com a nota fiscal — não é um
 * apagar qualquer. A tela já pergunta, mas confirmação só no navegador se
 * perde se a página estiver desatualizada ou se a exclusão vier por outro
 * caminho. Aqui o servidor confere no banco: se o item é conciliado e o sinal
 * de confirmação não veio, recusa e devolve um código para a tela perguntar.
 */
$confirmado = !empty($dados['confirmado_conciliado']);

$chk = $conn->prepare("SELECT conciliado FROM cadastro WHERE id=? LIMIT 1");
$chk->bind_param("i", $id);
$chk->execute();
$rowChk = $chk->get_result()->fetch_assoc();
$chk->close();

if ($rowChk === null) {
    echo json_encode(['sucesso'=>false, 'mensagem'=>'Item não encontrado']);
    exit();
}

$ehConciliado = strtoupper(trim((string)($rowChk['conciliado'] ?? ''))) === 'SIM';

if ($ehConciliado && !$confirmado) {
    echo json_encode([
        'sucesso'         => false,
        'exige_confirmar' => true,
        'mensagem'        => 'Deseja excluir um item conciliado?'
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