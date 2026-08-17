-- ═══════════════════════════════════════════════════════════════════════════
--  Agenda de manutenções preventivas
--
--  Antes, a data da próxima preventiva vivia numa coluna da ordem de serviço.
--  Isso gerava uma linha por OS para o mesmo equipamento e não permitia
--  registrar "realizada", "adiada" ou "removida" — a agenda precisava de vida
--  própria, com uma linha por equipamento.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `preventiva_engclin` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `item_id`             INT NOT NULL,
  `periodicidade_meses` INT NOT NULL DEFAULT 6,
  `proxima_data`        DATE NOT NULL,
  `origem`              ENUM('OS','MANUAL') NOT NULL DEFAULT 'MANUAL',
  `numero_chamado`      VARCHAR(20) NULL,
  `ultima_troca`        VARCHAR(500) NULL,
  `ativo`               TINYINT(1) NOT NULL DEFAULT 1,
  `usuario`             VARCHAR(100) NULL,
  `criado_em`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em`       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_item` (`item_id`),
  INDEX `idx_proxima` (`proxima_data`),
  INDEX `idx_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `preventiva_hist_engclin` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `id_preventiva`       INT NULL,
  `item_id`             INT NOT NULL,
  `acao`                ENUM('REALIZADA','ADIADA','REMOVIDA','AGENDADA') NOT NULL,
  `tecnico_usuario`     VARCHAR(100) NULL,
  `nome_tecnico`        VARCHAR(150) NULL,
  `data_exec`           DATE NULL,
  `hora_exec`           TIME NULL,
  `servico_troca`       VARCHAR(1000) NULL,
  `periodicidade_meses` INT NULL,
  `data_anterior`       DATE NULL,
  `proxima_data`        DATE NULL,
  `usuario`             VARCHAR(100) NULL,
  `criado_em`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_item` (`item_id`),
  INDEX `idx_criado` (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
