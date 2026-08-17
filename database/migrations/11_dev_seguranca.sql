-- ═══════════════════════════════════════════════════════════════════════════
-- PatAsset — Monitoramento de erros e ameaças (painel DEV)
-- Rodar cada passo separadamente no phpMyAdmin.
-- ═══════════════════════════════════════════════════════════════════════════


-- ─── PASSO 1 ────────────────────────────────────────────────────────────────
-- Cria a tabela de erros, caso ainda não exista.
-- Se ela já existir do jeito antigo, este comando não faz nada e o passo 2
-- cuida das colunas novas.

CREATE TABLE IF NOT EXISTS `dev_log_erros` (
  `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `nivel`     ENUM('INFO','WARNING','ERROR','CRITICAL') NOT NULL DEFAULT 'ERROR',
  `arquivo`   VARCHAR(100) DEFAULT NULL,
  `mensagem`  TEXT,
  `usuario`   VARCHAR(100) DEFAULT NULL,
  `ip`        VARCHAR(50)  DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_nivel`  (`nivel`),
  INDEX `idx_criado` (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── PASSO 2 ────────────────────────────────────────────────────────────────
-- Colunas novas: origem (PHP ou JS), endereço da página, linha, pilha de
-- chamadas, navegador e o agrupamento de erros repetidos.
--
-- Se der erro #1060 "Duplicate column", significa que a coluna já existe:
-- apague a linha correspondente e rode o restante.

ALTER TABLE `dev_log_erros`
  ADD COLUMN `origem`        ENUM('PHP','JS') NOT NULL DEFAULT 'PHP' AFTER `nivel`,
  ADD COLUMN `url`           VARCHAR(255) NULL AFTER `arquivo`,
  ADD COLUMN `linha`         INT NOT NULL DEFAULT 0 AFTER `url`,
  ADD COLUMN `stack`         TEXT NULL AFTER `mensagem`,
  ADD COLUMN `user_agent`    VARCHAR(255) NULL AFTER `ip`,
  ADD COLUMN `impressao`     CHAR(32) NULL AFTER `user_agent`,
  ADD COLUMN `ocorrencias`   INT NOT NULL DEFAULT 1 AFTER `impressao`,
  ADD COLUMN `atualizado_em` DATETIME NULL AFTER `criado_em`,
  ADD COLUMN `resolvido`     TINYINT(1) NOT NULL DEFAULT 0 AFTER `atualizado_em`;


-- ─── PASSO 3 ────────────────────────────────────────────────────────────────
-- Índice único da impressão digital: é ele que faz o mesmo erro somar
-- ocorrências em vez de criar milhares de linhas iguais.

ALTER TABLE `dev_log_erros`
  ADD UNIQUE KEY `uk_impressao` (`impressao`),
  ADD INDEX `idx_origem` (`origem`),
  ADD INDEX `idx_atualizado` (`atualizado_em`);


-- ─── PASSO 4 ────────────────────────────────────────────────────────────────
-- Ameaças de segurança.

CREATE TABLE IF NOT EXISTS `dev_ameacas` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `chave`         CHAR(32) NOT NULL,
  `tipo`          VARCHAR(30) NOT NULL DEFAULT 'OUTRO',
  `severidade`    ENUM('BAIXA','MEDIA','ALTA','CRITICA') NOT NULL DEFAULT 'MEDIA',
  `usuario_alvo`  VARCHAR(100) DEFAULT NULL,
  `ip_rede`       VARCHAR(50)  DEFAULT NULL,
  `ip_local`      VARCHAR(50)  DEFAULT NULL,
  `user_agent`    VARCHAR(255) DEFAULT NULL,
  `navegador`     VARCHAR(40)  DEFAULT NULL,
  `sistema`       VARCHAR(40)  DEFAULT NULL,
  `dispositivo`   VARCHAR(30)  DEFAULT NULL,
  `pagina`        VARCHAR(100) DEFAULT NULL,
  `detalhe`       VARCHAR(1000) DEFAULT NULL,
  `ocorrencias`   INT NOT NULL DEFAULT 1,
  `primeira_em`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultima_em`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revisado`      TINYINT(1) NOT NULL DEFAULT 0,
  `revisado_por`  VARCHAR(100) DEFAULT NULL,
  `revisado_em`   DATETIME NULL,
  UNIQUE KEY `uk_chave` (`chave`),
  INDEX `idx_tipo`  (`tipo`),
  INDEX `idx_sev`   (`severidade`),
  INDEX `idx_ultima`(`ultima_em`),
  INDEX `idx_ip`    (`ip_rede`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── PASSO 5 ────────────────────────────────────────────────────────────────
-- Histórico das execuções de backup. Hoje esse registro só existe no arquivo
-- logs/backup.log, que some se a pasta for limpa e não dá para consultar pela
-- tela.

CREATE TABLE IF NOT EXISTS `dev_backups` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `iniciado_em`  DATETIME NOT NULL,
  `terminado_em` DATETIME NULL,
  `origem`       ENUM('AUTOMATICO','MANUAL') NOT NULL DEFAULT 'AUTOMATICO',
  `situacao`     ENUM('EXITO','PARCIAL','FALHA') NOT NULL DEFAULT 'FALHA',
  `tabelas`      INT NOT NULL DEFAULT 0,
  `linhas`       INT NOT NULL DEFAULT 0,
  `tamanho`      BIGINT NOT NULL DEFAULT 0,
  `arquivo`      VARCHAR(190) DEFAULT NULL,
  `local_ok`     TINYINT(1) NOT NULL DEFAULT 0,
  `drive_ok`     TINYINT(1) NOT NULL DEFAULT 0,
  `duracao`      INT NOT NULL DEFAULT 0,
  `detalhe`      TEXT,
  INDEX `idx_inicio` (`iniciado_em`),
  INDEX `idx_situacao` (`situacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
