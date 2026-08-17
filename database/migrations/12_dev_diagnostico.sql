-- ══════════════════════════════════════════════════════
-- DIAGNÓSTICO PatAsset / LifeTech — dev_painel.php
-- Rode no phpMyAdmin ou qualquer cliente MySQL
-- ══════════════════════════════════════════════════════

-- 1. Colunas da tabela historico_acessos
--    (verifica se "resultado" e "ip_rede" existem)
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'sistema_db'
  AND TABLE_NAME   = 'historico_acessos'
ORDER BY ORDINAL_POSITION;

-- 2. Colunas da tabela baixa_definitiva
--    (verifica se "usuario_baixa" existe)
SELECT COLUMN_NAME, DATA_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'sistema_db'
  AND TABLE_NAME   = 'baixa_definitiva'
ORDER BY ORDINAL_POSITION;

-- 3. Tabelas de log do dev_painel (precisam existir)
--    Se não aparecerem, rode dev_sql_novas_tabelas.sql
SELECT TABLE_NAME, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'sistema_db'
  AND TABLE_NAME IN ('dev_log_erros','dev_log_acoes','dev_invasoes');

-- 4. Contagem geral para testar Estatísticas
SELECT 'historico_acessos' AS tabela, COUNT(*) AS total FROM historico_acessos
UNION ALL
SELECT 'cadastro',          COUNT(*) FROM cadastro
UNION ALL
SELECT 'historico',         COUNT(*) FROM historico
UNION ALL
SELECT 'baixa_definitiva',  COUNT(*) FROM baixa_definitiva
UNION ALL
SELECT 'usuarios',          COUNT(*) FROM usuarios;

-- 5. Valores distintos de "resultado" em historico_acessos
--    (confirma se a coluna existe e quais valores ela tem)
SELECT resultado, COUNT(*) AS total
FROM historico_acessos
GROUP BY resultado;
