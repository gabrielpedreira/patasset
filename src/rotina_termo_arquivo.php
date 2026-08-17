<?php
/**
 * rotina_termo_arquivo.php
 * Entrega o arquivo do termo de responsabilidade, conferindo a sessão antes.
 *
 * Antes, os termos eram servidos por link direto (uploads/termos/arquivo.pdf).
 * Isso significa que qualquer pessoa com o endereço baixava o documento — sem
 * login, sem registro nenhum. Termo de responsabilidade traz nome do
 * coordenador, setor e a relação de bens sob guarda dele.
 *
 * Agora o arquivo sai por aqui: o PHP lê do disco (o disco não passa pelo
 * bloqueio HTTP da pasta) e só entrega para quem tem sessão válida.
 */
session_start();

require_once 'conexao.php';

if (empty($_SESSION['usuario_logado'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acesso negado. Faça login para abrir o documento.';
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'Documento não informado.'; exit; }

// O nome do arquivo vem do banco, nunca da URL — assim não há como pedir
// "../../conexao.php" ou qualquer outro caminho.
$st = $conn->prepare("SELECT arquivo FROM termos_responsabilidade WHERE id = ? LIMIT 1");
if (!$st) { http_response_code(500); echo 'Erro interno.'; exit; }
$st->bind_param('i', $id);
$st->execute();
$r   = $st->get_result();
$row = $r ? $r->fetch_assoc() : null;
$st->close();

if (!$row || empty($row['arquivo'])) {
    http_response_code(404);
    echo 'Documento não encontrado.';
    exit;
}

// basename() como cinto de segurança, caso algum registro antigo tenha caminho
$nome    = basename((string)$row['arquivo']);
$caminho = __DIR__ . '/uploads/termos/' . $nome;

if (!is_file($caminho)) {
    http_response_code(404);
    echo 'Arquivo não encontrado no servidor.';
    exit;
}

$ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
$tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg',
          'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$mime = $tipos[$ext] ?? 'application/octet-stream';

// inline abre no navegador; se o tipo não for conhecido, força download em
// vez de deixar o navegador adivinhar o que fazer com ele.
$disposicao = isset($tipos[$ext]) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposicao . '; filename="' . $nome . '"');
header('Content-Length: ' . filesize($caminho));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache');

readfile($caminho);
