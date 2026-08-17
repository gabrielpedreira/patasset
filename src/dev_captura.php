<?php
/**
 * dev_captura.php
 * Captura automática de erros PHP em TODAS as páginas.
 *
 * Não precisa ser incluído arquivo por arquivo. É carregado pelo próprio PHP
 * antes de qualquer página, através da diretiva auto_prepend_file no
 * arquivo .user.ini (ver dev_seguranca_INSTALACAO.txt).
 *
 * Editar 100 arquivos para adicionar um include seria trabalho perdido: o
 * primeiro arquivo novo criado depois disso já ficaria de fora, e ninguém
 * lembraria. A diretiva pega tudo, inclusive o que ainda não existe.
 *
 * Regra de ouro: este arquivo NUNCA pode quebrar uma página. Toda a lógica
 * está protegida e falha em silêncio.
 */

if (defined('DEV_CAPTURA_ATIVA')) return;
define('DEV_CAPTURA_ATIVA', true);

// Não interfere no cron nem na linha de comando
if (PHP_SAPI === 'cli') return;

require_once __DIR__ . '/dev_seguranca.php';

// Configura o cookie de sessão (HttpOnly, SameSite, Secure).
// Precisa acontecer ANTES de qualquer session_start, e este arquivo é o único
// ponto do sistema garantidamente carregado antes de toda página.
require_once __DIR__ . '/seguranca_sessao.php';
seg_configurar_cookie();

/** Erros do PHP (avisos, notices, warnings) */
set_error_handler(function ($tipo, $mensagem, $arquivo, $linha) {
    // @ na frente da chamada continua silenciando
    if (!(error_reporting() & $tipo)) return false;

    $niveis = [
        E_ERROR             => 'CRITICAL',
        E_PARSE             => 'CRITICAL',
        E_CORE_ERROR        => 'CRITICAL',
        E_COMPILE_ERROR     => 'CRITICAL',
        E_USER_ERROR        => 'ERROR',
        E_RECOVERABLE_ERROR => 'ERROR',
        E_WARNING           => 'WARNING',
        E_USER_WARNING      => 'WARNING',
        E_NOTICE            => 'INFO',
        E_USER_NOTICE       => 'INFO',
        E_DEPRECATED        => 'INFO',
        E_USER_DEPRECATED   => 'INFO',
    ];

    try {
        dev_registrar_erro([
            'origem'   => 'PHP',
            'nivel'    => $niveis[$tipo] ?? 'ERROR',
            'arquivo'  => basename((string)$arquivo),
            'linha'    => (int)$linha,
            'mensagem' => (string)$mensagem,
        ]);
    } catch (Throwable $e) { /* log não derruba página */ }

    return false; // deixa o PHP seguir o comportamento normal
}, E_ALL);

/** Exceções não capturadas */
set_exception_handler(function ($ex) {
    try {
        dev_registrar_erro([
            'origem'   => 'PHP',
            'nivel'    => 'CRITICAL',
            'arquivo'  => basename($ex->getFile()),
            'linha'    => $ex->getLine(),
            'mensagem' => get_class($ex) . ': ' . $ex->getMessage(),
            'stack'    => $ex->getTraceAsString(),
        ]);
    } catch (Throwable $e) {}
});

/** Erros fatais — só dá para pegar no encerramento */
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e) return;
    if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

    try {
        dev_registrar_erro([
            'origem'   => 'PHP',
            'nivel'    => 'CRITICAL',
            'arquivo'  => basename((string)$e['file']),
            'linha'    => (int)$e['line'],
            'mensagem' => (string)$e['message'],
        ]);
    } catch (Throwable $x) {}
});
