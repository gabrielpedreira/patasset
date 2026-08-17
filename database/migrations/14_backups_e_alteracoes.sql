-- ═══════════════════════════════════════════════════════════════════════════
--  Histórico de backups e trilha de alterações diretas no banco
-- ═══════════════════════════════════════════════════════════════════════════

-- Sem esta tabela, o registro de backup existiria apenas num arquivo de log,
-- que não é consultável pela tela e desaparece se a pasta for limpa.
CREATE TABLE IF NOT EXISTS `dev_backups` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `iniciado_em`  DATETIME NOT NULL,
  `terminado_em` DATETIME NULL,
  `origem`       ENUM('AUTOMATICO','MANUAL') NOT NULL DEFAULT 'AUTOMATICO',
  `situacao`     ENUM('EXITO','PARCIAL','FALHA') NOT NULL DEFAULT 'FALHA',
  `tabelas`      INT NOT NULL DEFAULT 0,
  `linhas`       INT NOT NULL DEFAULT 0,
  `tamanho`      BIGINT NOT NULL DEFAULT 0,
  `arquivo`      VARCHAR(190) NULL,
  `local_ok`     TINYINT(1) NOT NULL DEFAULT 0,
  `drive_ok`     TINYINT(1) NOT NULL DEFAULT 0,
  `duracao`      INT NOT NULL DEFAULT 0,
  `detalhe`      TEXT,
  INDEX `idx_inicio` (`iniciado_em`),
  INDEX `idx_situacao` (`situacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alteração feita por tela do sistema deixa rastro. Alteração direta no banco
-- não deixa nada. Esta tabela é o que impede "esse campo mudou e ninguém sabe
-- por quê" seis meses depois.
CREATE TABLE IF NOT EXISTS `dev_alteracoes` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tabela`       VARCHAR(64)  NOT NULL,
  `registro_id`  VARCHAR(100) NOT NULL,
  `operacao`     ENUM('EDICAO','EXCLUSAO') NOT NULL DEFAULT 'EDICAO',
  `coluna`       VARCHAR(64)  NULL,
  `valor_antes`  TEXT         NULL,
  `valor_depois` TEXT         NULL,
  `usuario`      VARCHAR(100) NULL,
  `ip`           VARCHAR(50)  NULL,
  `criado_em`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_tabela`   (`tabela`),
  INDEX `idx_registro` (`tabela`, `registro_id`),
  INDEX `idx_criado`   (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
