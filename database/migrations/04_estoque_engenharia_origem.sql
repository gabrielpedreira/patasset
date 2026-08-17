-- ═══════════════════════════════════════════════════════════════════════════
-- Separa a ORIGEM do lote em estoque_engenharia.
--
-- Problema: transferências entre unidades criam lotes novos nesta tabela,
--           e esses lotes apareciam na aba "Entradas por Nota" — poluindo o
--           histórico de notas fiscais com registros sem nota.
--
-- Depois:   origem = 'NOTA'          → entrada real por nota fiscal
--           origem = 'TRANSFERENCIA' → lote criado por transferência interna
--
-- A aba "Entradas por Nota" passa a listar apenas origem='NOTA'.
-- Os SALDOS continuam somando TODOS os lotes (nota + transferência).
--
-- Rodar DEPOIS de estoque_engenharia_qtd_inicial.sql
-- ═══════════════════════════════════════════════════════════════════════════

-- 1) Cria a coluna (tudo que já existe assume 'NOTA')
ALTER TABLE estoque_engenharia
  ADD COLUMN origem ENUM('NOTA','TRANSFERENCIA') NOT NULL DEFAULT 'NOTA'
  AFTER quantidade_inicial;


-- ── 2a) EXISTE alguma transferência no sistema? ──────────────────────────────
--    Se este SELECT vier VAZIO (0 linhas), nenhum lote veio de transferência.
--    Nesse caso PULE os passos 2b e 3 — está tudo certo com o default 'NOTA'.
SELECT observacao, codigo_item, nome_item, quantidade, usuario
FROM movimentacao_estoque_engclin
WHERE tipo = 'ENTRADA' AND observacao LIKE 'TRF-%'
ORDER BY id;


-- ── 2b) Só se o 2a trouxe resultados: candidatos a lote de transferência ────
--    O INSERT de transferência não preenchia nota nem valor.
--    Confira a lista e anote os IDs que você reconhece como transferência.
SELECT e.id, e.codigo, e.codigo_peca, e.unidade, e.nome,
       e.numero_nota, e.valor, e.quantidade_inicial, e.data_cadastro
FROM estoque_engenharia e
WHERE (e.numero_nota IS NULL OR e.numero_nota = '')
  AND e.valor IS NULL
  AND EXISTS (
        SELECT 1 FROM movimentacao_estoque_engclin m
        WHERE m.tipo = 'ENTRADA'
          AND m.observacao LIKE 'TRF-%'
          AND m.codigo_item COLLATE utf8mb4_unicode_ci
              = e.codigo_peca COLLATE utf8mb4_unicode_ci
      )
ORDER BY e.data_cadastro, e.id;


-- ── 3) Marque os lotes conferidos no 2b ──────────────────────────────────────
--    Troque os números pelos IDs reais. Ex.: WHERE id IN (2, 7, 9)
--    Descomente a linha abaixo e ajuste:
--
-- UPDATE estoque_engenharia SET origem = 'TRANSFERENCIA' WHERE id IN (2);


-- ── 4) Verificação final ─────────────────────────────────────────────────────
SELECT origem, COUNT(*) AS lotes, SUM(quantidade) AS saldo_total
FROM estoque_engenharia
WHERE ativo = 1
GROUP BY origem;


-- ═══════════════════════════════════════════════════════════════════════════
-- 5) OPCIONAL — corrigir quantidade_inicial de lotes já debitados
--
-- A migração anterior copiou `quantidade` (saldo JÁ debitado) para
-- `quantidade_inicial`, porque o valor original não existia em lugar nenhum.
-- Lotes que sofreram saída ANTES da migração ficaram com a entrada subestimada.
--
-- Este SELECT estima quanto saiu de cada lote por transferência:
SELECT e.id, e.codigo, e.unidade, e.numero_nota,
       e.quantidade_inicial AS entrada_registrada,
       e.quantidade         AS saldo_atual,
       COALESCE(SUM(m.quantidade), 0) AS saiu_por_transferencia,
       e.quantidade_inicial + COALESCE(SUM(m.quantidade), 0) AS entrada_provavel
FROM estoque_engenharia e
LEFT JOIN movimentacao_estoque_engclin m
       ON m.tipo = 'SAIDA'
      AND m.observacao LIKE 'TRF-%'
      AND m.codigo_item COLLATE utf8mb4_unicode_ci
          = e.codigo_peca COLLATE utf8mb4_unicode_ci
      AND m.observacao LIKE CONCAT('%Saída de ', e.unidade, ' %')
WHERE e.ativo = 1 AND e.origem = 'NOTA'
GROUP BY e.id
HAVING saiu_por_transferencia > 0;

-- ATENÇÃO: corrija SOMENTE por SQL, mexendo apenas em quantidade_inicial.
-- NÃO use o lápis de editar na tela — lá a alteração ajusta o saldo pela
-- diferença, e o estoque ficaria inflado.
--
-- Ex.: nota 123456 era de 3 un., transferiu 2, sobrou 1:
-- UPDATE estoque_engenharia SET quantidade_inicial = 3 WHERE id = 1;
