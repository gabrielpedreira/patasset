<?php
/**
 * segredos.exemplo.php
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  MODELO DE CONFIGURAÇÃO                                                  │
 * │                                                                          │
 * │  1. Copie este arquivo para FORA da pasta pública do site:               │
 * │       /home/usuario/config_sistema/segredos.php                          │
 * │  2. Preencha com os valores reais.                                       │
 * │  3. Ajuste a permissão para 600 (somente o dono lê).                     │
 * │                                                                          │
 * │  NUNCA versione o arquivo preenchido. Só este modelo entra no Git.       │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * POR QUE FORA DA PASTA PÚBLICA
 * Enquanto o servidor processa PHP, o conteúdo destes arquivos é invisível.
 * Mas se o processamento falhar — migração de servidor, handler mal
 * configurado, diretiva quebrada — o Apache passa a entregar o código-fonte
 * como texto puro, e a senha do banco vira pública. Fora da pasta do site não
 * existe URL que alcance este arquivo, aconteça o que acontecer com o PHP.
 */

if (defined('PATASSET_SEGREDOS')) return;
define('PATASSET_SEGREDOS', true);

// ─── Banco de dados ─────────────────────────────────────────────────────────
define('PAT_DB_HOST', 'localhost');
define('PAT_DB_NAME', 'sistema_db');
define('PAT_DB_USER', 'usuario_banco');
define('PAT_DB_PASS', 'SUA_SENHA_DO_BANCO');

// ─── E-mail (SMTP) ──────────────────────────────────────────────────────────
// Com Gmail, use uma "senha de app" — não a senha da conta.
// Gerada em: Conta Google → Segurança → Senhas de app (exige 2 etapas ativas).
define('PAT_SMTP_HOST', 'smtp.gmail.com');
define('PAT_SMTP_PORT', 587);
define('PAT_SMTP_USER', 'sistema@seudominio.com.br');
define('PAT_SMTP_PASS', 'SUA_SENHA_DE_APP');
define('PAT_SMTP_NOME', 'Sistema de Patrimônio');

// ─── Destinatários ──────────────────────────────────────────────────────────
// São dois públicos diferentes e não devem compartilhar a mesma constante:
// falha de backup é problema técnico; movimentação de item é operacional.
define('PAT_EMAIL_EQUIPE', 'patrimonio@seudominio.com.br');   // notificações
define('PAT_EMAIL_NOME',   'Equipe de Patrimônio');
define('PAT_EMAIL_DEV',    'desenvolvedor@seudominio.com.br'); // alertas técnicos

// Compatibilidade com código legado
define('PAT_EMAIL_ALERTA', PAT_EMAIL_EQUIPE);
