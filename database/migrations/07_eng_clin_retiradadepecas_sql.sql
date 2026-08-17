-- ============================================================
-- PatAsset / LifeTech Engenharia Clínica
-- Módulo: Retirada de Peças de Equipamentos Baixados
-- Banco: sistema_db
-- ============================================================

-- Catálogo de peças por tipo de equipamento
CREATE TABLE IF NOT EXISTS retiradadepecas_catalogo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo_equipamento VARCHAR(200) NOT NULL,
  nome_peca VARCHAR(200) NOT NULL,
  valor_estimado DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_por VARCHAR(100) DEFAULT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tipo (tipo_equipamento(100)),
  UNIQUE KEY uk_tipo_peca (tipo_equipamento(150), nome_peca(150))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vínculo entre item baixado e tipo do catálogo
CREATE TABLE IF NOT EXISTS retiradadepecas_equipamento_tipo (
  id_baixa INT NOT NULL,
  tipo_equipamento VARCHAR(200) NOT NULL,
  definido_por VARCHAR(100) DEFAULT NULL,
  definido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_baixa),
  INDEX idx_tipo (tipo_equipamento(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Status de cada peça por equipamento baixado
CREATE TABLE IF NOT EXISTS retiradadepecas_status (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_baixa INT NOT NULL,
  id_catalogo INT NOT NULL,
  nome_peca VARCHAR(200) NOT NULL,
  tipo_equipamento VARCHAR(200) NOT NULL,
  status ENUM('DISPONIVEL','REMOVIDO') NOT NULL DEFAULT 'DISPONIVEL',
  usuario VARCHAR(100) DEFAULT NULL,
  data_retirada DATETIME DEFAULT NULL,
  obs TEXT DEFAULT NULL,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_baixa_catalogo (id_baixa, id_catalogo),
  INDEX idx_baixa (id_baixa),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
