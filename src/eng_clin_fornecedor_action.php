<?php
session_start();
include 'conexao.php';

header('Content-Type: application/json; charset=utf-8');

// Autenticação
if (!isset($_SESSION['usuario_logado'])) {
    echo json_encode(['ok' => false, 'msg' => 'Sessão expirada. Faça login novamente.']);
    exit();
}

$usuario = $_SESSION['usuario_logado'];
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
$stmt->bind_param("s", $usuario);
$stmt->execute();
$res = $stmt->get_result();
$nivel = 'C'; $classe_usuario = ''; $status = 'ATIVO';
if ($r = $res->fetch_assoc()) {
    $nivel          = strtoupper(trim($r['permicao']));
    $classe_usuario = strtoupper(trim($r['classe_usuario']));
    $status         = $r['status'] ?? 'ATIVO';
}
$stmt->close();

if ($status !== 'ATIVO') {
    session_destroy();
    echo json_encode(['ok' => false, 'msg' => 'Usuário bloqueado.']);
    exit();
}

if (!in_array($classe_usuario, ['DEV', 'ENGENHARIA CLINICA']) || $nivel !== 'A') {
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método inválido.']);
    exit();
}

// Função sanitização
function limpa($v) {
    return htmlspecialchars(strip_tags(trim($v ?? '')));
}

$razao_social        = limpa($_POST['razao_social']        ?? '');
$nome_fantasia       = limpa($_POST['nome_fantasia']       ?? '');
$area_atuacao        = limpa($_POST['area_atuacao']        ?? '');
$cnpj                = limpa($_POST['cnpj']                ?? '');
$banco               = limpa($_POST['banco']               ?? '');
$agencia             = limpa($_POST['agencia']             ?? '');
$conta               = limpa($_POST['conta']               ?? '');
$chave_pix           = limpa($_POST['chave_pix']           ?? '');
$cep                 = limpa($_POST['cep']                 ?? '');
$logradouro          = limpa($_POST['logradouro']          ?? '');
$numero              = limpa($_POST['numero']              ?? '');
$complemento         = limpa($_POST['complemento']         ?? '');
$bairro              = limpa($_POST['bairro']              ?? '');
$cidade              = limpa($_POST['cidade']              ?? '');
$estado              = limpa($_POST['estado']              ?? '');
$telefone_principal  = limpa($_POST['telefone_principal']  ?? '');
$telefone_secundario = limpa($_POST['telefone_secundario'] ?? '');
$whatsapp            = limpa($_POST['whatsapp']            ?? '');
$email_comercial     = limpa($_POST['email_comercial']     ?? '');
$site                = limpa($_POST['site']                ?? '');
$resp_nome           = limpa($_POST['resp_nome']           ?? '');
$resp_cargo          = limpa($_POST['resp_cargo']          ?? '');
$resp_telefone       = limpa($_POST['resp_telefone']       ?? '');
$resp_email          = limpa($_POST['resp_email']          ?? '');

// Validações obrigatórias
if (empty($razao_social)) {
    echo json_encode(['ok' => false, 'msg' => 'Razão Social é obrigatória.']);
    exit();
}
if (empty($cnpj)) {
    echo json_encode(['ok' => false, 'msg' => 'CNPJ é obrigatório.']);
    exit();
}

// Verificar CNPJ duplicado
$chk = $conn->prepare("SELECT id FROM fornecedores WHERE cnpj = ?");
$chk->bind_param("s", $cnpj);
$chk->execute();
$chk->store_result();
if ($chk->num_rows > 0) {
    $chk->close();
    echo json_encode(['ok' => false, 'msg' => 'Já existe um fornecedor cadastrado com este CNPJ.']);
    exit();
}
$chk->close();

$sql = "INSERT INTO fornecedores (
    razao_social, nome_fantasia, area_atuacao, cnpj,
    banco, agencia, conta, chave_pix,
    cep, logradouro, numero, complemento, bairro, cidade, estado,
    telefone_principal, telefone_secundario, whatsapp, email_comercial, site,
    resp_nome, resp_cargo, resp_telefone, resp_email,
    usuario_cadastro, data_cadastro
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())";

$ins = $conn->prepare($sql);
$ins->bind_param(
    "sssssssssssssssssssssssss",
    $razao_social, $nome_fantasia, $area_atuacao, $cnpj,
    $banco, $agencia, $conta, $chave_pix,
    $cep, $logradouro, $numero, $complemento, $bairro, $cidade, $estado,
    $telefone_principal, $telefone_secundario, $whatsapp, $email_comercial, $site,
    $resp_nome, $resp_cargo, $resp_telefone, $resp_email,
    $usuario
);

if ($ins->execute()) {
    $ins->close();
    $conn->close();
    echo json_encode(['ok' => true, 'msg' => 'Fornecedor cadastrado com sucesso.']);
} else {
    $err = $ins->error;
    $ins->close();
    $conn->close();
    echo json_encode(['ok' => false, 'msg' => 'Erro ao inserir no banco: ' . $err]);
}
?>