<?php
/**
 * rotina_upload_termo.php
 * Recebe o PDF do termo de responsabilidade assinado.
 *
 * ┌─ Sobre a validação ────────────────────────────────────────────────────┐
 * │ A versão anterior gravava o arquivo mantendo a EXTENSÃO ENVIADA pelo    │
 * │ usuário, sem conferir o tipo. Qualquer pessoa logada podia enviar um    │
 * │ arquivo .php e depois abri-lo pelo navegador — o servidor executaria o  │
 * │ código, com acesso total ao banco e aos demais arquivos.                │
 * │                                                                         │
 * │ Agora a extensão é DERIVADA do conteúdo real do arquivo, nunca do nome. │
 * │ Nome de arquivo é dado do usuário; conteúdo é fato.                     │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_logado'])) {
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}

require_once 'conexao.php';
require_once __DIR__ . '/dev_seguranca.php';

$unidade      = trim($_POST['unidade']      ?? '');
$setor        = trim($_POST['setor']        ?? '');
$area         = trim($_POST['area']         ?? '');
$data_geracao = trim($_POST['data_geracao'] ?? '');
$coordenador  = trim($_POST['coordenador']  ?? '');
$usuario      = $_SESSION['usuario_logado'];

if ($unidade === '') {
    echo json_encode(['erro' => 'Campo unidade é obrigatório']);
    exit;
}

if (empty($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['arquivo']['error'] ?? -1;
    echo json_encode(['erro' => "Erro no upload do arquivo (código $errCode)"]);
    exit;
}

$tmp = $_FILES['arquivo']['tmp_name'];

// ─── 1. Tamanho ──────────────────────────────────────────────────────────────
const TERMO_TAM_MAX = 20 * 1024 * 1024;   // 20 MB
if (filesize($tmp) > TERMO_TAM_MAX) {
    echo json_encode(['erro' => 'Arquivo maior que 20 MB.']);
    exit;
}
if (filesize($tmp) < 100) {
    echo json_encode(['erro' => 'Arquivo vazio ou inválido.']);
    exit;
}

// ─── 2. Tipo real, lido do conteúdo ──────────────────────────────────────────
// finfo lê os primeiros bytes do arquivo. Renomear um .php para .pdf não engana:
// o conteúdo continua sendo texto com código, e o tipo detectado não bate.
$permitidos = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];

$mime = '';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) { $mime = (string)finfo_file($fi, $tmp); finfo_close($fi); }
}
if ($mime === '' && function_exists('mime_content_type')) {
    $mime = (string)mime_content_type($tmp);
}

if (!isset($permitidos[$mime])) {
    // Registra: envio de tipo não permitido raramente é engano do usuário
    try {
        dev_registrar_ameaca([
            'tipo'         => 'UPLOAD_SUSPEITO',
            'severidade'   => 'ALTA',
            'usuario_alvo' => $usuario,
            'pagina'       => 'rotina_upload_termo.php',
            'detalhe'      => 'Envio recusado. Nome: "' . substr($_FILES['arquivo']['name'], 0, 120)
                            . '" — tipo detectado: ' . ($mime ?: 'desconhecido'),
        ]);
    } catch (Throwable $e) {}

    echo json_encode(['erro' => 'Tipo de arquivo não permitido. Envie PDF, JPG ou PNG.']);
    exit;
}

// ─── 3. Nome gerado pelo sistema ─────────────────────────────────────────────
// A extensão vem da tabela acima, nunca do nome enviado.
$ext      = $permitidos[$mime];
$filename = 'termo_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;

$uploadDir = __DIR__ . '/uploads/termos/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0750, true);
}

// Garante que o bloqueio de execução exista, mesmo se a pasta for recriada
$ht = dirname($uploadDir) . '/.htaccess';
if (!file_exists($ht)) {
    @file_put_contents($ht,
        "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
        "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n" .
        "RemoveHandler .php .phtml .phar\nAddType text/plain .php .phtml .phar\n" .
        "Options -ExecCGI -Indexes\n");
}

$destPath = $uploadDir . $filename;

if (!move_uploaded_file($tmp, $destPath)) {
    echo json_encode(['erro' => 'Falha ao mover o arquivo para o destino']);
    exit;
}
@chmod($destPath, 0640);

// ─── 4. Registro ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "INSERT INTO termos_responsabilidade (unidade, setor, area, data_geracao, coordenador, usuario, arquivo)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);

if (!$stmt) {
    @unlink($destPath);
    echo json_encode(['erro' => $conn->error]);
    exit;
}

$dataVal = ($data_geracao !== '') ? $data_geracao : null;
$stmt->bind_param('sssssss', $unidade, $setor, $area, $dataVal, $coordenador, $usuario, $filename);

if (!$stmt->execute()) {
    @unlink($destPath);   // sem registro no banco o arquivo seria órfão
    echo json_encode(['erro' => $stmt->error]);
    $stmt->close();
    exit;
}

$insertId = $stmt->insert_id;
$stmt->close();

echo json_encode(['sucesso' => true, 'id' => $insertId], JSON_UNESCAPED_UNICODE);
