-- ═══════════════════════════════════════════════════════════════════════════
-- Férias do técnico passam a ser DIA/MÊS, sem ano.
--
-- Antes:  ferias_inicio / ferias_fim = DATE  ('2026-07-15')
--         → só valia para aquele ano; virava o ano e o período "expirava"
--
-- Depois: ferias_inicio / ferias_fim = CHAR(5) no formato 'MM-DD' ('07-15')
--         → recorrente, vale para qualquer ano em vigência
--
-- O formato 'MM-DD' foi escolhido em vez de 'DD/MM' porque ordena
-- corretamente por texto (01-01 < 07-15 < 12-31), o que simplifica consultas.
--
-- Rodar UMA VEZ no phpMyAdmin.
-- ═══════════════════════════════════════════════════════════════════════════

-- 1) Colunas novas
ALTER TABLE tecnico
  ADD COLUMN ferias_ini_md CHAR(5) NULL DEFAULT NULL COMMENT 'MM-DD (sem ano)' AFTER ferias_fim,
  ADD COLUMN ferias_fim_md CHAR(5) NULL DEFAULT NULL COMMENT 'MM-DD (sem ano)' AFTER ferias_ini_md;

-- 2) Converte o que já existe, descartando o ano
--    Nota: usamos YEAR() > 0 em vez de comparar com '0000-00-00'.
--    Com o strict mode do MySQL, o literal '0000-00-00' numa coluna DATE
--    dispara "#1292 Incorrect date value". YEAR() de uma data zerada dá 0.
UPDATE tecnico
SET ferias_ini_md = CASE
      WHEN ferias_inicio IS NOT NULL AND YEAR(ferias_inicio) > 0
      THEN DATE_FORMAT(ferias_inicio, '%m-%d') END,
    ferias_fim_md = CASE
      WHEN ferias_fim IS NOT NULL AND YEAR(ferias_fim) > 0
      THEN DATE_FORMAT(ferias_fim, '%m-%d') END;

-- 3) Conferência — compare o antes e o depois
SELECT id, nome,
       ferias_inicio AS antes_ini, ferias_ini_md AS depois_ini,
       ferias_fim    AS antes_fim, ferias_fim_md AS depois_fim
FROM tecnico
ORDER BY nome;


-- ═══════════════════════════════════════════════════════════════════════════
-- 4) OPCIONAL — só depois de conferir o passo 3 e testar a página.
--    Remove as colunas DATE antigas, que deixam de ser usadas.
--    Mantenha-as por alguns dias se quiser poder voltar atrás.
-- ═══════════════════════════════════════════════════════════════════════════
--
-- ALTER TABLE tecnico DROP COLUMN ferias_inicio, DROP COLUMN ferias_fim;
