-- =====================================================
-- Tabela: termos_responsabilidade
-- Armazena os termos de responsabilidade patrimonial
-- (gerados + assinados digitalizados)
-- =====================================================

CREATE TABLE IF NOT EXISTS `termos_responsabilidade` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `unidade`      VARCHAR(150) NOT NULL,
  `setor`        VARCHAR(150)          DEFAULT NULL,
  `area`         VARCHAR(150)          DEFAULT NULL,
  `data_geracao` DATE         NOT NULL,
  `coordenador`  VARCHAR(200) NOT NULL,
  `usuario`      VARCHAR(100) NOT NULL,
  `arquivo`      VARCHAR(300)          DEFAULT NULL COMMENT 'Nome do arquivo PDF salvo em uploads/termos/',
  `criado_em`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_unidade`      (`unidade`),
  KEY `idx_unid_setor`   (`unidade`, `setor`),
  KEY `idx_data_geracao` (`data_geracao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Termos de responsabilidade patrimonial por local';
