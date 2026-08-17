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

// Fallback de mime_type para registros antigos sem coluna mime_type
if (empty($mime_type)) {
    // Detecta pelos primeiros bytes (magic bytes)
    $header = substr($blob, 0, 8);
    if (substr($blob, 0, 4) === '%PDF') {
        $mime_type = 'application/pdf';
    } elseif (substr($header, 0, 3) === "\xFF\xD8\xFF") {
        $mime_type = 'image/jpeg';
    } elseif (substr($header, 0, 8) === "\x89PNG\r\n\x1a\n") {
        $mime_type = 'image/png';
    } elseif (substr($header, 0, 6) === 'GIF87a' || substr($header, 0, 6) === 'GIF89a') {
        $mime_type = 'image/gif';
    } else {
        $mime_type = 'application/octet-stream';
    }
}

// Define extensão para download inline
$extensoes = [
    'application/pdf' => 'pdf',
    'image/png'       => 'png',
    'image/jpeg'      => 'jpg',
    'image/jpg'       => 'jpg',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp',
];
$ext = $extensoes[$mime_type] ?? 'bin';

header('Content-Type: ' . $mime_type);
header('Content-Disposition: inline; filename="documento_' . $id . '.' . $ext . '"');
header('Content-Length: ' . strlen($blob));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

echo $blob;
exit();