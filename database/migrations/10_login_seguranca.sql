-- ═══════════════════════════════════════════════════════════════════════════
-- PatAsset — Controle de tentativas de login (proteção contra força bruta)
-- Rodar uma única vez no phpMyAdmin.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `login_tentativas` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `chave`          VARCHAR(190) NOT NULL,
  `tipo`           ENUM('USUARIO','IP') NOT NULL,
  `valor`          VARCHAR(190) NOT NULL,
  `tentativas`     INT NOT NULL DEFAULT 0,
  `bloqueios`      INT NOT NULL DEFAULT 0,
  `bloqueado_ate`  DATETIME NULL DEFAULT NULL,
  `primeira_falha` DATETIME NULL DEFAULT NULL,
  `ultima_falha`   DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `uk_chave` (`chave`),
  KEY `idx_bloqueado` (`bloqueado_ate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
