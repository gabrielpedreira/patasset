<?php
/**
 * backup_dump.php
 * Geração do dump SQL — fonte única usada pelo botão do painel DEV
 * e pelo backup automático (cron).
 *
 * Antes cada um tinha o seu próprio código: o cron usava mysqldump com uma
 * lista fixa de 8 tabelas (que ficou anos desatualizada e não incluía nada
 * do LifeTech) e o painel tinha um dump em PHP que corrompia colunas binárias.
 * Os dois problemas somem com um gerador só.
 */

if (defined('BACKUP_DUMP_CARREGADO')) return;
define('BACKUP_DUMP_CARREGADO', true);

/**
 * Classificação das tabelas por sistema.
 *
 * A lista é explícita para o que existe hoje, mas há uma regra automática no
 * fim: tabela nova com "engclin" no nome cai no LifeTech, "dev_" cai em
 * sistema, o resto no PatAsset. Sem isso, uma tabela criada daqui a seis meses
 * ficaria fora dos backups parciais sem ninguém notar — foi exatamente assim
 * que o backup antigo passou anos sem salvar nada do LifeTech.
 */
function backup_grupos(): array {
    return [
        'PATASSET' => [
            'cadastro', 'nota', 'historico', 'baixa_definitiva', 'pre_descarte',
            'relacao', 'cronograma', 'descricoes', 'cadastro_destinatarios',
            'termos_responsabilidade', 'registro_atividades',
        ],
        'LIFETECH' => [
            'chamado_engclin', 'ordemservico_engclin', 'maodeobra_engclin',
            'itens_os_engclin', 'manutencao_externa_engclin', 'anexos_engclin',
            'historico_eventos_engclin', 'preventiva_engclin', 'preventiva_hist_engclin',
            'estoque_engenharia', 'movimentacao_estoque_engclin', 'engclin_cadastro_pecas',
            'engclin_despachos', 'documentos_engclin', 'criticidade_item_engclin',
            'retiradadepecas_catalogo', 'retiradadepecas_status',
            'retiradadepecas_equipamento_tipo', 'tecnico', 'fornecedores',
        ],
        // Usadas pelos dois sistemas. Entram em TODO backup parcial, para que
        // cada arquivo seja restaurável sozinho: um dump do LifeTech sem a
        // tabela de usuários restaura um sistema em que ninguém consegue entrar.
        'COMUM' => [
            'usuarios', 'usuarios_online', 'historico_acessos', 'login_tentativas',
            'autorizacao', 'dev_log_erros', 'dev_ameacas', 'dev_backups',
            'dev_invasoes', 'dev_alteracoes',
        ],
    ];
}

/** Em que grupo uma tabela se encaixa */
function backup_grupo_da_tabela(string $t): string {
    foreach (backup_grupos() as $grupo => $lista) {
        if (in_array($t, $lista, true)) return $grupo;
    }
    // Regra para tabelas criadas depois desta lista
    if (stripos($t, 'engclin') !== false || stripos($t, 'eng_clin') !== false) return 'LIFETECH';
    if (stripos($t, 'dev_') === 0)                                             return 'COMUM';
    return 'PATASSET';
}

/** Todas as tabelas do banco */
function backup_todas_tabelas(mysqli $conn): array {
    $t = [];
    $r = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    if ($r) while ($x = $r->fetch_array()) $t[] = $x[0];
    sort($t);
    return $t;
}

/**
 * Tabelas de um escopo.
 * $escopo: 'geral' | 'patasset' | 'lifetech' | nome de uma tabela
 */
function backup_tabelas_do_escopo(mysqli $conn, string $escopo): array {
    $todas = backup_todas_tabelas($conn);
    $e = strtolower(trim($escopo));

    if ($e === '' || $e === 'geral') return $todas;

    if ($e === 'patasset' || $e === 'lifetech') {
        $alvo = strtoupper($e);
        return array_values(array_filter($todas, function ($t) use ($alvo) {
            $g = backup_grupo_da_tabela($t);
            return $g === $alvo || $g === 'COMUM';
        }));
    }

    // Tabela avulsa — só se existir de verdade
    return in_array($escopo, $todas, true) ? [$escopo] : [];
}

/**
 * Escreve o dump completo do banco.
 *
 * @param mysqli   $conn
 * @param callable $out    recebe cada pedaço de texto (fwrite ou echo)
 * @param array    $only   lista de tabelas; vazio = todas
 * @return array   ['tabelas'=>int, 'linhas'=>int]
 */
function backup_gerar_dump(mysqli $conn, callable $out, array $only = []): array {
    $out("-- PatAsset / LifeTech — Backup do banco de dados\n");
    $out("-- Gerado em: " . date('d/m/Y H:i:s') . "\n");
    $out("-- Servidor MySQL: " . $conn->server_info . "\n\n");
    $out("SET NAMES utf8mb4;\n");
    $out("SET FOREIGN_KEY_CHECKS=0;\n");
    $out("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

    $tabelas = [];
    $rt = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    if ($rt) while ($x = $rt->fetch_array()) $tabelas[] = $x[0];
    if ($only) $tabelas = array_values(array_intersect($tabelas, $only));

    $total_linhas = 0;

    foreach ($tabelas as $tabela) {
        $t = str_replace('`', '', $tabela);

        $cr = $conn->query("SHOW CREATE TABLE `$t`");
        if (!$cr) continue;
        $create = $cr->fetch_assoc();

        $out("-- ─────────────────────────────────────────────\n");
        $out("-- Tabela: $t\n");
        $out("-- ─────────────────────────────────────────────\n");
        $out("DROP TABLE IF EXISTS `$t`;\n");
        $out($create['Create Table'] . ";\n\n");

        // Descobre quais colunas são binárias. BLOB escapado como texto
        // corrompe o arquivo (bytes nulos, quebras de linha); essas vão em
        // hexadecimal, que sobrevive a qualquer editor.
        $bin = [];
        $rc = $conn->query("SHOW COLUMNS FROM `$t`");
        if ($rc) while ($c = $rc->fetch_assoc()) {
            if (preg_match('/blob|binary/i', $c['Type'])) $bin[$c['Field']] = true;
        }

        // Leitura em fluxo (MYSQLI_USE_RESULT): as linhas chegam uma a uma do
        // servidor MySQL em vez de virem todas para a memória do PHP. Sem isso,
        // uma tabela de anexos com PDFs em BLOB estoura o limite de memória —
        // e bin2hex ainda dobra o tamanho de cada arquivo binário.
        $buffer      = [];
        $buffer_bytes = 0;
        $cols_sql    = null;

        // Limite por INSERT em BYTES, não em número de linhas. Uma tabela de
        // texto tem linhas de 200 bytes; uma de anexos, de 5 MB. Contar linhas
        // trata as duas igual e é justamente aí que a memória estoura.
        $limite_bytes = 2 * 1024 * 1024;

        $descarregar = function () use (&$buffer, &$buffer_bytes, $out, $t, &$cols_sql) {
            if (!$buffer) return;
            $out("INSERT INTO `$t` ($cols_sql) VALUES\n" . implode(",\n", $buffer) . ";\n");
            $buffer = [];
            $buffer_bytes = 0;
        };

        $rd = $conn->query("SELECT * FROM `$t`", MYSQLI_USE_RESULT);
        if ($rd) {
            while ($row = $rd->fetch_assoc()) {
                if ($cols_sql === null) {
                    $cols_sql = '`' . implode('`, `', array_keys($row)) . '`';
                }
                $vals = [];
                foreach ($row as $col => $v) {
                    if ($v === null)                 $vals[] = 'NULL';
                    elseif (isset($bin[$col]))       $vals[] = '0x' . bin2hex($v);
                    else                             $vals[] = "'" . $conn->real_escape_string($v) . "'";
                }
                $linha_sql = '(' . implode(',', $vals) . ')';
                unset($vals, $row);

                $buffer[]      = $linha_sql;
                $buffer_bytes += strlen($linha_sql);
                $total_linhas++;

                if ($buffer_bytes >= $limite_bytes) $descarregar();
            }
            $rd->free();
        }

        $descarregar();
        $out("\n");
    }

    $out("SET FOREIGN_KEY_CHECKS=1;\n");
    $out("-- Fim do backup — " . count($tabelas) . " tabelas, $total_linhas linhas.\n");

    return ['tabelas' => count($tabelas), 'linhas' => $total_linhas];
}
