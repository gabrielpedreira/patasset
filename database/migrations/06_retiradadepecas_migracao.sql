-- ══════════════════════════════════════════════════════════════════
-- MIGRAÇÃO: retiradadepecas_* — id_baixa passa a referenciar cadastro.id
--
-- Execute ANTES de subir o novo eng_clin_retiradadepecas.php
-- Só é necessário se já houver dados registrados nas tabelas abaixo.
-- ══════════════════════════════════════════════════════════════════

-- 1. retiradadepecas_equipamento_tipo
--    Converte id_baixa (baixa_definitiva.id) → cadastro.id via tag
UPDATE retiradadepecas_equipamento_tipo et
JOIN baixa_definitiva bd ON bd.id = et.id_baixa
JOIN cadastro c ON (
    c.tag_antiga  COLLATE utf8mb4_unicode_ci = bd.tag COLLATE utf8mb4_unicode_ci
    OR
    c.tag_trocada COLLATE utf8mb4_unicode_ci = bd.tag COLLATE utf8mb4_unicode_ci
)
SET et.id_baixa = c.id;

-- 2. retiradadepecas_status
--    Mesmo processo
UPDATE retiradadepecas_status rs
JOIN baixa_definitiva bd ON bd.id = rs.id_baixa
JOIN cadastro c ON (
    c.tag_antiga  COLLATE utf8mb4_unicode_ci = bd.tag COLLATE utf8mb4_unicode_ci
    OR
    c.tag_trocada COLLATE utf8mb4_unicode_ci = bd.tag COLLATE utf8mb4_unicode_ci
)
SET rs.id_baixa = c.id;

-- ══════════════════════════════════════════════════════════════════
-- Verificação pós-migração (opcional — copie e execute no phpMyAdmin)
-- ══════════════════════════════════════════════════════════════════
-- SELECT et.id_baixa, c.descricao, c.tag_antiga
-- FROM retiradadepecas_equipamento_tipo et
-- LEFT JOIN cadastro c ON c.id = et.id_baixa;
