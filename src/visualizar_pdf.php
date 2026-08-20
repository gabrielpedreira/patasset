<?php
session_start();
require_once "conexao.php";
require_once 'check_session.php';

$usuario_logado = $_SESSION['usuario_logado'] ?? '';
if (!$usuario_logado) {
    header("Location: login.php");
    exit();
}

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo "ID inválido.";
    exit();
}

// Busca o blob e o mime_type
$stmt = $conn->prepare("SELECT nota_fiscal, mime_type FROM nota WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    http_response_code(404);
    echo "Documento não encontrado.";
    exit();
}

$stmt->bind_result($blob, $mime_type);
$stmt->fetch();
$stmt->close();

if (empty($blob)) {
    http_response_code(404);
    echo "Este registro não possui arquivo anexado.";
    exit();
}

/*
 * O Content-Type sai de uma lista fixa, decidida pelos bytes do arquivo.
 *
 * Antes, o valor da coluna `mime_type` ia direto para o cabeçalho. E aquele
 * valor era o Content-Type declarado por quem enviou o arquivo, guardado sem
 * conferência: bastava enviar um arquivo com script dentro declarando
 * "text/html" para esta página devolvê-lo como HTML, e o navegador executava o
 * script na origem do sistema, com a sessão de quem abriu o documento.
 *
 * `nosniff` não cobria isso — ele impede o navegador de adivinhar um tipo
 * diferente do declarado, e o tipo declarado era o problema.
 *
 * Ver upload_seguro.php.
 */
require_once __DIR__ . '/upload_seguro.php';

list($mime_type, $ext, $inline) = up_mime_saida($mime_type, $blob);

// Anexo antigo de tipo desconhecido vai como download, nunca renderizado.
$disposicao = $inline ? 'inline' : 'attachment';

header('Content-Type: ' . $mime_type);
header('Content-Disposition: ' . $disposicao . '; filename="documento_' . $id . '.' . $ext . '"');
header('Content-Length: ' . strlen($blob));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

/*
 * Sem Content-Security-Policy aqui, de propósito. `sandbox` impediria o
 * visualizador de PDF nativo do Chrome de abrir o arquivo, e conferir nota
 * fiscal é justamente o que esta tela faz.
 *
 * A lista fixa de tipos já resolve: o cabeçalho só pode assumir um de dez
 * valores conhecidos, e nem `text/html` nem `image/svg+xml` estão entre eles.
 * SVG está fora por ser um formato de imagem que carrega script — parece
 * inofensivo na lista e não é.
 */

echo $blob;
exit();