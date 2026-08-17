<?php
/**
 * indicadores_localizacao.php
 * Indicadores cruzando LOCALIZADO (coluna `encontrado`) com NOTA FISCAL.
 *
 * Usado pelo Relatório de Conciliados (rel_conciliacao.php) e pelo
 * Dashboard de Indicadores (indicadores_dashboard.php). Ficam num arquivo
 * só para que as duas telas nunca mostrem números diferentes para a mesma
 * pergunta — o que aconteceria se cada uma escrevesse o seu próprio WHERE.
 *
 * A coluna `encontrado` foi preenchida ao longo de anos por pessoas
 * diferentes: aparece como "sim", "SIM", "Sim", "não", "nao", "NÃO", "Nao".
 * Por isso a comparação é sempre feita em UPPER(TRIM(...)) contra a lista
 * de grafias, nunca com igualdade direta.
 */

if (defined('IND_LOCALIZACAO_CARREGADO')) return;
define('IND_LOCALIZACAO_CARREGADO', true);

/** Condição SQL para "localizado = SIM" */
function il_cond_localizado(): string {
    return "UPPER(TRIM(COALESCE(encontrado,''))) IN ('SIM','S')";
}

/** Condição SQL para "localizado = NÃO" (todas as grafias) */
function il_cond_nao_localizado(): string {
    return "UPPER(TRIM(COALESCE(encontrado,''))) IN ('NAO','NÃO','N')";
}

/** Condição SQL para "tem nota fiscal" */
function il_cond_com_nf(): string {
    return "TRIM(COALESCE(nota_fiscal,'')) <> ''";
}

/** Condição SQL para "sem nota fiscal" */
function il_cond_sem_nf(): string {
    return "TRIM(COALESCE(nota_fiscal,'')) = ''";
}

/** Expressão de soma do valor do item (texto em formato brasileiro) */
function il_expr_valor(): string {
    return "COALESCE(SUM(CAST(REPLACE(REPLACE(NULLIF(TRIM(valor_item),''),'.',''),',','.') AS DECIMAL(20,2))),0)";
}

/**
 * Calcula os seis indicadores.
 *
 * @param string $where_extra filtro adicional já escapado (ex.: unidade), ou ''
 * @return array chaves: loc_com_nf, loc_sem_nf, loc_total,
 *                       nloc_com_nf, nloc_sem_nf, nloc_total
 *               cada uma com ['qtd' => int, 'valor' => float]
 */
function il_calcular(mysqli $conn, string $where_extra = ''): array {
    $base = trim($where_extra) !== '' ? "($where_extra) AND " : '';

    $loc  = il_cond_localizado();
    $nloc = il_cond_nao_localizado();
    $cnf  = il_cond_com_nf();
    $snf  = il_cond_sem_nf();

    // Uma única varredura da tabela em vez de seis COUNT separados.
    $sql = "SELECT
        SUM(CASE WHEN $loc  AND $cnf THEN 1 ELSE 0 END) AS q_loc_com,
        SUM(CASE WHEN $loc  AND $snf THEN 1 ELSE 0 END) AS q_loc_sem,
        SUM(CASE WHEN $loc          THEN 1 ELSE 0 END) AS q_loc_tot,
        SUM(CASE WHEN $nloc AND $cnf THEN 1 ELSE 0 END) AS q_nloc_com,
        SUM(CASE WHEN $nloc AND $snf THEN 1 ELSE 0 END) AS q_nloc_sem,
        SUM(CASE WHEN $nloc         THEN 1 ELSE 0 END) AS q_nloc_tot,

        COALESCE(SUM(CASE WHEN $loc  AND $cnf THEN " . il_valor_linha() . " ELSE 0 END),0) AS v_loc_com,
        COALESCE(SUM(CASE WHEN $loc  AND $snf THEN " . il_valor_linha() . " ELSE 0 END),0) AS v_loc_sem,
        COALESCE(SUM(CASE WHEN $loc          THEN " . il_valor_linha() . " ELSE 0 END),0) AS v_loc_tot,
        COALESCE(SUM(CASE WHEN $nloc AND $cnf THEN " . il_valor_linha() . " ELSE 0 END),0) AS v_nloc_com,
        COALESCE(SUM(CASE WHEN $nloc AND $snf THEN " . il_valor_linha() . " ELSE 0 END),0) AS v_nloc_sem,
        COALESCE(SUM(CASE WHEN $nloc         THEN " . il_valor_linha() . " ELSE 0 END),0) AS v_nloc_tot
      FROM cadastro
      WHERE {$base}1=1";

    $vazio = ['qtd' => 0, 'valor' => 0.0];
    $out = [
        'loc_com_nf'  => $vazio, 'loc_sem_nf'  => $vazio, 'loc_total'  => $vazio,
        'nloc_com_nf' => $vazio, 'nloc_sem_nf' => $vazio, 'nloc_total' => $vazio,
    ];

    $r = $conn->query($sql);
    if (!$r) return $out;
    $x = $r->fetch_assoc();
    if (!$x) return $out;

    $out['loc_com_nf']  = ['qtd' => (int)$x['q_loc_com'],  'valor' => (float)$x['v_loc_com']];
    $out['loc_sem_nf']  = ['qtd' => (int)$x['q_loc_sem'],  'valor' => (float)$x['v_loc_sem']];
    $out['loc_total']   = ['qtd' => (int)$x['q_loc_tot'],  'valor' => (float)$x['v_loc_tot']];
    $out['nloc_com_nf'] = ['qtd' => (int)$x['q_nloc_com'], 'valor' => (float)$x['v_nloc_com']];
    $out['nloc_sem_nf'] = ['qtd' => (int)$x['q_nloc_sem'], 'valor' => (float)$x['v_nloc_sem']];
    $out['nloc_total']  = ['qtd' => (int)$x['q_nloc_tot'], 'valor' => (float)$x['v_nloc_tot']];

    return $out;
}

/** Valor de UMA linha, convertido do texto brasileiro para número */
function il_valor_linha(): string {
    return "CAST(REPLACE(REPLACE(NULLIF(TRIM(valor_item),''),'.',''),',','.') AS DECIMAL(20,2))";
}

/** Rótulos dos indicadores, na ordem em que devem aparecer na tela */
function il_rotulos(): array {
    return [
        'loc_com_nf'  => 'Localizados com nota fiscal',
        'loc_sem_nf'  => 'Localizados sem nota fiscal',
        'loc_total'   => 'Total localizados',
        'nloc_com_nf' => 'Não localizados com nota fiscal',
        'nloc_sem_nf' => 'Não localizados sem nota fiscal',
        'nloc_total'  => 'Total não localizados',
    ];
}

/** Formata em real brasileiro */
if (!function_exists('il_fmt_brl')) {
    function il_fmt_brl(float $v): string {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }
}
