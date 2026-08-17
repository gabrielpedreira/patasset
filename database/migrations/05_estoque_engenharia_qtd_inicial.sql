-- ═══════════════════════════════════════════════════════════════════════════
-- Separa a QUANTIDADE DE ENTRADA (histórico fixo) do SALDO ATUAL (dinâmico)
-- na tabela estoque_engenharia.
--
-- Antes:  `quantidade` era usada como registro da nota E como saldo vivo
--         (o FIFO de despachos/transferências debitava essa coluna, alterando
--          o histórico de entradas).
--
-- Depois: `quantidade_inicial` = quanto entrou (NUNCA muda com o uso)
--         `quantidade`         = saldo atual do lote (debitado/creditado)
--
-- Rodar UMA VEZ no phpMyAdmin.
-- ═══════════════════════════════════════════════════════════════════════════

-- 1) Cria a coluna
ALTER TABLE estoque_engenharia
  ADD COLUMN quantidade_inicial DECIMAL(10,3) NOT NULL DEFAULT 0 AFTER quantidade;

-- 2) Backfill: lotes existentes recebem o saldo atual como quantidade inicial.
--    (não é possível recuperar o valor original de lotes já debitados —
--     a partir de agora o histórico fica preservado)
UPDATE estoque_engenharia SET quantidade_inicial = quantidade;

-- 3) Verificação
SELECT id, codigo, codigo_peca, unidade, numero_nota,
       quantidade_inicial AS entrou,
       quantidade         AS saldo
FROM estoque_engenharia
WHERE ativo = 1
ORDER BY unidade, codigo_peca, id;
