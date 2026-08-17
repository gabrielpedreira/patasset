-- ═══════════════════════════════════════════════════════════════════════════
-- LIFETECH — FASE 1: Protocolo único, novas estruturas e regra de pendência
--
-- CONTEXTO
--   Hoje o chamado nasce como CH-000042 e a OS ganha um número próprio
--   (OS-000017). O protocolo passa a ser ÚNICO: a OS herda o número do
--   chamado. `numero_os` continua existindo como coluna (para não quebrar
--   as dependências atuais), mas seu valor será igual a `numero_chamado`.
--
-- MODELO DE STATUS (decidido a partir do item 20 do escopo)
--   ordemservico_engclin.status — passa a usar SÓ dois valores do ENUM atual:
--     ABERTA    → OS iniciada e não finalizada  = PENDÊNCIA
--     CONCLUIDO → OS encerrada                  = não é pendência
--   Os demais valores do ENUM (NAO_CONCLUIDO, MANUTENCAO_EXTERNA,
--   SEM_SOLUCAO, OBSOLETO, AGUARDANDO_CORRECAO_PATRIMONIO) ficam como
--   LEGADO e deixam de ser gravados — eram motivo disfarçado de status.
--
--   O detalhe (aguardando peças, orçamento, manutenção externa, obsoleto...)
--   passa a viver em `motivo`, NUNCA em `status`. Assim
--   "CONCLUÍDO + FALTA_DE_PECAS" não volta a ser pendência.
--
--   chamado_engclin.status:
--     ABERTO         → aberto, ainda sem OS      = NÃO é pendência
--     EM_ATENDIMENTO → OS iniciada               = PENDÊNCIA
--     CONCLUIDO      → encerrado
--
-- ATENÇÃO: o passo 1 APAGA os chamados/OS existentes (são dados de teste,
--          conforme combinado). Confira antes de rodar.
--
-- Rodar UMA VEZ no phpMyAdmin, na ordem.
-- ═══════════════════════════════════════════════════════════════════════════


-- ═══════════════════════════════════════════════════════════════════════════
-- 1) LIMPEZA DOS DADOS DE TESTE
--    Confira o que existe antes de apagar:
-- ═══════════════════════════════════════════════════════════════════════════
SELECT 'chamado_engclin'            AS tabela, COUNT(*) AS registros FROM chamado_engclin
UNION ALL SELECT 'ordemservico_engclin',       COUNT(*) FROM ordemservico_engclin
UNION ALL SELECT 'itens_os_engclin',           COUNT(*) FROM itens_os_engclin
UNION ALL SELECT 'manutencao_externa_engclin', COUNT(*) FROM manutencao_externa_engclin
UNION ALL SELECT 'historico_eventos_engclin',  COUNT(*) FROM historico_eventos_engclin;

-- Descomente as 5 linhas abaixo para limpar:
--
-- DELETE FROM itens_os_engclin;
-- DELETE FROM manutencao_externa_engclin;
-- DELETE FROM historico_eventos_engclin;
-- DELETE FROM ordemservico_engclin;
-- DELETE FROM chamado_engclin;


-- ═══════════════════════════════════════════════════════════════════════════
-- 2) CHAMADO — protocolo único e índices
-- ═══════════════════════════════════════════════════════════════════════════

-- Garante que não existam dois chamados com o mesmo protocolo
ALTER TABLE chamado_engclin
  ADD UNIQUE KEY uk_chamado_protocolo (numero_chamado);

ALTER TABLE chamado_engclin
  ADD INDEX idx_ch_status    (status),
  ADD INDEX idx_ch_item      (item_id),
  ADD INDEX idx_ch_data      (data_chamado),
  ADD INDEX idx_ch_prior     (prioridade);


-- ═══════════════════════════════════════════════════════════════════════════
-- 3) ORDEM DE SERVIÇO — protocolo único + campos do novo fluxo
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE ordemservico_engclin
  -- Protocolo: numero_os passa a receber o mesmo valor de numero_chamado.
  -- UNIQUE garante uma única OS por protocolo.
  ADD UNIQUE KEY uk_os_protocolo  (numero_os),
  ADD UNIQUE KEY uk_os_chamado    (numero_chamado);

-- NOTA: `atualizado_em` JÁ EXISTE nesta tabela (datetime, ON UPDATE
--       CURRENT_TIMESTAMP). Não recriar — daria erro #1060.
ALTER TABLE ordemservico_engclin
  -- ── Mão de obra: data/hora informadas pelo técnico.
  --    Diferente de data_abertura/hora_abertura, que é quando o registro
  --    foi criado no sistema. O técnico pode ter iniciado antes.
  ADD COLUMN data_inicio  DATE NULL DEFAULT NULL COMMENT 'Início informado pelo técnico',
  ADD COLUMN hora_inicio  TIME NULL DEFAULT NULL,

  -- ── Ocorrência (lista estruturada; opções serão definidas depois)
  ADD COLUMN ocorrencia   VARCHAR(120) NULL DEFAULT NULL,

  -- ── Serviço executado
  ADD COLUMN servico TEXT NULL DEFAULT NULL COMMENT 'O que o técnico fez',

  -- ── Manutenção externa: sim/não (detalhes em manutencao_externa_engclin)
  ADD COLUMN manutencao_externa ENUM('SIM','NAO') NOT NULL DEFAULT 'NAO',

  -- ── LOCALIZAÇÃO ORIGINAL DO ITEM (congelada no início da OS)
  --    NÃO usar cadastro.* para a devolução: ao iniciar a OS aqueles
  --    valores já foram sobrescritos para ENGENHARIA CLINICA.
  ADD COLUMN loc_orig_unidade VARCHAR(120) NULL DEFAULT NULL,
  ADD COLUMN loc_orig_setor   VARCHAR(120) NULL DEFAULT NULL,
  ADD COLUMN loc_orig_area    VARCHAR(120) NULL DEFAULT NULL,
  ADD COLUMN loc_orig_pav     VARCHAR(120) NULL DEFAULT NULL,

  -- ── Devolução no encerramento
  ADD COLUMN item_devolvido ENUM('SIM','NAO') NULL DEFAULT NULL
      COMMENT 'NULL = OS ainda aberta',

  -- ── Salvamento por etapas
  ADD COLUMN etapas_salvas VARCHAR(255) NULL DEFAULT NULL
      COMMENT 'Etapas já preenchidas, separadas por vírgula';

-- NÃO foi criada a coluna `status_observacao`: a coluna `motivo` já existe
-- e cumpre esse papel. Falta apenas um valor no ENUM.
ALTER TABLE ordemservico_engclin
  MODIFY COLUMN motivo ENUM(
    'PROBLEMA_SOLUCIONADO',
    'FALTA_DE_PECAS',
    'AGUARDANDO_ORCAMENTO',   -- ← novo (item 11 do escopo)
    'ITEM_ALUGADO',
    'OBSOLESCENCIA',
    'MANUTENCAO_TERCEIROS',
    'AGUARDANDO_PATRIMONIO',
    'OUTROS'
  ) NULL DEFAULT NULL COMMENT 'Motivo/observacao da conclusao — NAO define pendencia';

ALTER TABLE ordemservico_engclin
  ADD INDEX idx_os_status  (status),
  ADD INDEX idx_os_item    (item_id),
  ADD INDEX idx_os_inicio  (data_inicio);


-- ═══════════════════════════════════════════════════════════════════════════
-- 4) MANUTENÇÃO EXTERNA — orçamento e link
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE manutencao_externa_engclin
  ADD COLUMN orcamento       ENUM('SIM','NAO') NOT NULL DEFAULT 'NAO',
  ADD COLUMN valor_orcamento DECIMAL(12,2) NULL DEFAULT NULL,
  ADD COLUMN link_orcamento  VARCHAR(500)  NULL DEFAULT NULL;

ALTER TABLE manutencao_externa_engclin
  ADD INDEX idx_me_chamado (numero_chamado),
  ADD INDEX idx_me_empresa (empresa);


-- ═══════════════════════════════════════════════════════════════════════════
-- 5) ANEXOS — fotos e PDF da manutenção externa (BLOB, conforme definido)
--    Limite de 8 MB por arquivo é aplicado no PHP. MEDIUMBLOB suporta 16 MB.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS anexos_engclin (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  numero_chamado VARCHAR(20)  NOT NULL COMMENT 'Protocolo — vincula tudo',
  contexto       VARCHAR(40)  NOT NULL DEFAULT 'MANUTENCAO_EXTERNA',
  tipo           ENUM('FOTO','PDF') NOT NULL,
  nome_arquivo   VARCHAR(255) NOT NULL,
  mime           VARCHAR(100) NOT NULL,
  tamanho        INT UNSIGNED NOT NULL DEFAULT 0,
  conteudo       MEDIUMBLOB   NOT NULL,
  usuario        VARCHAR(100) NULL DEFAULT NULL,
  criado_em      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_anx_chamado (numero_chamado),
  INDEX idx_anx_tipo    (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════════════════
-- 6) MATERIAIS E HISTÓRICO — índices de consulta
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE itens_os_engclin
  ADD INDEX idx_it_chamado (numero_chamado),
  ADD INDEX idx_it_os      (numero_os);

ALTER TABLE historico_eventos_engclin
  ADD INDEX idx_he_chamado (numero_chamado),
  ADD INDEX idx_he_tipo    (tipo_evento),
  ADD INDEX idx_he_data    (data_evento);


-- ═══════════════════════════════════════════════════════════════════════════
-- 7) CONFERÊNCIA
-- ═══════════════════════════════════════════════════════════════════════════
SHOW COLUMNS FROM ordemservico_engclin;
SHOW INDEX FROM ordemservico_engclin;
