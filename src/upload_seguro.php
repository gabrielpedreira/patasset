<?php
/**
 * upload_seguro.php
 * Validação de arquivos enviados por usuários.
 *
 * ─── O PROBLEMA ────────────────────────────────────────────────────────────
 * $_FILES['x']['type'] NÃO é o tipo do arquivo. É um cabeçalho que o navegador
 * declara e que quem envia controla por completo — basta montar a requisição à
 * mão. O mesmo vale para a extensão do nome: é texto escolhido por quem envia.
 *
 * O sistema guardava esse valor declarado na coluna `nota.mime_type` e o
 * devolvia depois em `header('Content-Type: ' . $mime_type)`. A cadeia
 * completa era:
 *
 *   1. enviar um arquivo com conteúdo "<script>...</script>"
 *      declarando Content-Type: text/html
 *   2. o valor ia para o banco sem conferência
 *   3. visualizar_pdf.php devolvia o conteúdo COMO HTML
 *   4. o navegador executava o script na origem do sistema, com a sessão de
 *      quem abriu o documento
 *
 * Isso é XSS armazenado: um usuário comum consegue rodar script na sessão de
 * um DEV que abra o anexo. `X-Content-Type-Options: nosniff` não protege aqui
 * — ele impede o navegador de adivinhar um tipo diferente do declarado, e o
 * problema era justamente o tipo declarado.
 *
 * ─── A CORREÇÃO ────────────────────────────────────────────────────────────
 * Duas pontas, porque uma só não fecha:
 *
 *   up_mime_real()    lê os bytes do arquivo e diz o que ele é de fato,
 *                     ignorando o que foi declarado. Usado na ENTRADA.
 *   up_mime_saida()   devolve apenas tipos de uma lista fixa. Usado na SAÍDA,
 *                     para os anexos que já estão no banco desde antes desta
 *                     verificação existir — não há como confiar neles.
 */

if (defined('UPLOAD_SEGURO_CARREGADO')) return;
define('UPLOAD_SEGURO_CARREGADO', true);

/** Tipos aceitos para nota fiscal e documentos digitalizados. */
const UP_MIME_DOCUMENTO = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/bmp',
    'image/tiff',
    'image/heic',
    'image/heif',
    'image/avif',
];

/** Tipos aceitos para foto de pessoa. Sem PDF: foto de técnico é imagem. */
const UP_MIME_IMAGEM = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/bmp',
    'image/tiff',
    'image/heic',
    'image/heif',
    'image/avif',
];

/**
 * Tipo real do arquivo, lido do conteúdo.
 *
 * Devolve o MIME quando ele está na lista permitida, ou false quando o arquivo
 * é de outro tipo — inclusive quando o conteúdo não corresponde ao que foi
 * declarado, que é exatamente o caso interessante.
 *
 * @param array $arquivo Item de $_FILES
 * @param array $permitidos UP_MIME_DOCUMENTO ou UP_MIME_IMAGEM
 * @param int   $max_bytes Tamanho máximo
 * @return string|false MIME real, ou false se recusado
 */
function up_mime_real(array $arquivo, array $permitidos, int $max_bytes = 20971520) {

    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return false;
    if (empty($arquivo['tmp_name']))                                 return false;
    if (($arquivo['size'] ?? 0) <= 0)                                return false;
    if (($arquivo['size'] ?? 0) > $max_bytes)                        return false;

    // Garante que o arquivo veio de um upload HTTP e não é um caminho local
    // apontado por um parâmetro manipulado.
    if (!is_uploaded_file($arquivo['tmp_name'])) return false;

    if (!function_exists('finfo_open')) {
        // Sem a extensão fileinfo, cai para os bytes de assinatura. Cobre
        // menos formatos, mas continua lendo o conteúdo — nunca o declarado.
        $mime = up_mime_assinatura(file_get_contents($arquivo['tmp_name'], false, null, 0, 32));
        return in_array($mime, $permitidos, true) ? $mime : false;
    }

    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi === false) return false;
    $mime = finfo_file($fi, $arquivo['tmp_name']);
    finfo_close($fi);

    if (!is_string($mime) || $mime === '') return false;

    // Normaliza variações que o fileinfo pode devolver para o mesmo formato.
    $equivalentes = [
        'image/x-ms-bmp' => 'image/bmp',
        'image/pjpeg'    => 'image/jpeg',
        'image/x-png'    => 'image/png',
    ];
    $mime = $equivalentes[$mime] ?? $mime;

    return in_array($mime, $permitidos, true) ? $mime : false;
}

/** Tipo pelos bytes de assinatura. Reserva para servidor sem fileinfo. */
function up_mime_assinatura(string $inicio) {
    if (substr($inicio, 0, 4) === '%PDF')                 return 'application/pdf';
    if (substr($inicio, 0, 3) === "\xFF\xD8\xFF")         return 'image/jpeg';
    if (substr($inicio, 0, 8) === "\x89PNG\r\n\x1a\n")    return 'image/png';
    if (substr($inicio, 0, 6) === 'GIF87a')               return 'image/gif';
    if (substr($inicio, 0, 6) === 'GIF89a')               return 'image/gif';
    if (substr($inicio, 0, 2) === 'BM')                   return 'image/bmp';
    if (substr($inicio, 0, 4) === "II\x2A\x00")           return 'image/tiff';
    if (substr($inicio, 0, 4) === "MM\x00\x2A")           return 'image/tiff';
    if (substr($inicio, 0, 4) === 'RIFF' && substr($inicio, 8, 4) === 'WEBP') return 'image/webp';
    if (substr($inicio, 4, 8) === 'ftypheic')             return 'image/heic';
    if (substr($inicio, 4, 8) === 'ftypheif')             return 'image/heif';
    if (substr($inicio, 4, 8) === 'ftypavif')             return 'image/avif';
    return false;
}

/**
 * Content-Type seguro para devolver um anexo guardado no banco.
 *
 * Nunca repassa a string do banco para o cabeçalho. Os anexos gravados antes
 * desta verificação carregam o tipo que foi declarado no envio, e não há como
 * saber quais são confiáveis — então o valor do banco é ignorado por completo
 * e o tipo sai dos bytes do próprio arquivo.
 *
 * @return array{0:string,1:string,2:bool} [mime, extensão, pode_exibir_inline]
 */
function up_mime_saida(?string $mime_banco, string $blob): array {

    $seguros = [
        'application/pdf' => ['pdf',  true],
        'image/jpeg'      => ['jpg',  true],
        'image/png'       => ['png',  true],
        'image/gif'       => ['gif',  true],
        'image/webp'      => ['webp', true],
        'image/bmp'       => ['bmp',  true],
        'image/tiff'      => ['tif',  false],  // navegador não exibe: baixa
        'image/heic'      => ['heic', false],
        'image/heif'      => ['heif', false],
        'image/avif'      => ['avif', true],
    ];

    /*
     * Só o conteúdo decide. O valor do banco não entra nesta escolha nem como
     * reserva — e isso foi uma correção durante o teste desta função.
     *
     * A primeira versão consultava o banco quando os bytes não identificavam
     * nada, e um arquivo HTML gravado como "application/pdf" saía com esse
     * cabeçalho. O `nosniff` evitava a execução, então não havia XSS — mas a
     * decisão passava a depender de um dado que quem enviou controlava, o que
     * é exatamente o defeito que esta função existe para eliminar.
     *
     * Não se perde nada: a lista de assinaturas cobre todos os dez formatos
     * aceitos. Se os bytes não batem com nenhum, o arquivo não é um deles,
     * independente do que esteja escrito na coluna.
     *
     * O parâmetro $mime_banco continua na assinatura de propósito — deixa
     * explícito, em quem lê a chamada, que o valor foi buscado e descartado.
     */
    $real = up_mime_assinatura(substr($blob, 0, 32));

    if ($real !== false && isset($seguros[$real])) {
        return [$real, $seguros[$real][0], $seguros[$real][1]];
    }

    // Não identificado: entrega como binário para download. Nunca renderizado.
    return ['application/octet-stream', 'bin', false];
}
