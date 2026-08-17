<?php
/**
 * dev_tabelas.php
 * Consulta e edição direta das tabelas do banco — painel DEV.
 *
 * Só entram tabelas que PESSOAS preenchem e corrigem. As alimentadas pelo
 * próprio sistema (logs de acesso, trilha de eventos, registros de erro)
 * ficam de fora: editá-las não conserta nada e destrói a única evidência do
 * que aconteceu.
 *
 * Salvaguardas, todas deliberadas:
 *  · Lista branca de tabelas — nome vindo do navegador nunca entra em SQL.
 *  · Sempre por chave primária, uma linha por vez. Sem operação em massa.
 *  · Colunas binárias (anexos) nunca entram em UPDATE: seriam corrompidas.
 *  · usuarios.senha bloqueada — precisa passar pelo hash da tela própria.
 *  · Toda alteração é registrada em dev_alteracoes, com valor anterior.
 *
 * Sobre a última: editar direto no banco não deixa rastro nenhum. Sem o
 * registro, "esse campo mudou e ninguém sabe por quê" vira insolúvel seis
 * meses depois.
 */

if (defined('DEV_TABELAS_CARREGADO')) return;
define('DEV_TABELAS_CARREGADO', true);

/**
 * Catálogo. Chave = nome real da tabela.
 *   rotulo  → como aparece na lista
 *   desc    → o que a tabela faz no sistema
 *   grupo   → PatAsset | LifeTech
 *   leitura → true = não permite editar nem excluir
 *   bloq    → colunas que não podem ser alteradas por aqui
 */
function dt_catalogo(): array {
    return [
        // ── PatAsset ────────────────────────────────────────────────────────
        'cadastro' => ['rotulo'=>'Cadastro de Itens', 'grupo'=>'PatAsset',
            'desc'=>'Registro de todos os itens do patrimônio'],
        'nota' => ['rotulo'=>'Notas Fiscais', 'grupo'=>'PatAsset',
            'desc'=>'Notas fiscais vinculadas aos itens'],
        'historico' => ['rotulo'=>'Histórico de Movimentações', 'grupo'=>'PatAsset',
            'desc'=>'Registro de cada movimentação realizada'],
        'baixa_definitiva' => ['rotulo'=>'Baixas Definitivas', 'grupo'=>'PatAsset',
            'desc'=>'Itens baixados em definitivo'],
        'pre_descarte' => ['rotulo'=>'Pré-Descarte', 'grupo'=>'PatAsset',
            'desc'=>'Itens marcados para descarte, aguardando execução'],
        'relacao' => ['rotulo'=>'Relação Contábil', 'grupo'=>'PatAsset',
            'desc'=>'Relação usada na conciliação contábil'],
        'cronograma' => ['rotulo'=>'Cronograma', 'grupo'=>'PatAsset',
            'desc'=>'Cronograma de inventário por unidade'],
        'descricoes' => ['rotulo'=>'Descrições Padronizadas', 'grupo'=>'PatAsset',
            'desc'=>'Lista de descrições usadas no cadastro'],
        'cadastro_destinatarios' => ['rotulo'=>'Destinatários de E-mail', 'grupo'=>'PatAsset',
            'desc'=>'Quem recebe as notificações automáticas'],
        'termos_responsabilidade' => ['rotulo'=>'Termos de Responsabilidade', 'grupo'=>'PatAsset',
            'desc'=>'Termos gerados e enviados por setor'],
        'usuarios' => ['rotulo'=>'Usuários', 'grupo'=>'PatAsset',
            'desc'=>'Contas de acesso ao sistema',
            'bloq'=>['senha']],

        // ── LifeTech ────────────────────────────────────────────────────────
        'chamado_engclin' => ['rotulo'=>'Chamados', 'grupo'=>'LifeTech',
            'desc'=>'Chamados abertos pelos setores'],
        'ordemservico_engclin' => ['rotulo'=>'Ordens de Serviço', 'grupo'=>'LifeTech',
            'desc'=>'OS geradas a partir dos chamados'],
        'maodeobra_engclin' => ['rotulo'=>'Mão de Obra', 'grupo'=>'LifeTech',
            'desc'=>'Intervenções dos técnicos em cada OS'],
        'itens_os_engclin' => ['rotulo'=>'Materiais das OS', 'grupo'=>'LifeTech',
            'desc'=>'Peças e materiais aplicados nas ordens de serviço'],
        'manutencao_externa_engclin' => ['rotulo'=>'Manutenção Externa', 'grupo'=>'LifeTech',
            'desc'=>'Envios de equipamento para empresa externa'],
        'preventiva_engclin' => ['rotulo'=>'Agenda de Preventivas', 'grupo'=>'LifeTech',
            'desc'=>'Manutenções preventivas programadas'],
        'estoque_engenharia' => ['rotulo'=>'Estoque de Peças', 'grupo'=>'LifeTech',
            'desc'=>'Saldo e entradas de peças por unidade'],
        'engclin_cadastro_pecas' => ['rotulo'=>'Catálogo de Peças', 'grupo'=>'LifeTech',
            'desc'=>'Peças cadastradas por tipo de equipamento'],
        'engclin_despachos' => ['rotulo'=>'Despachos de Estoque', 'grupo'=>'LifeTech',
            'desc'=>'Transferências de peças entre unidades'],
        'tecnico' => ['rotulo'=>'Técnicos', 'grupo'=>'LifeTech',
            'desc'=>'Equipe técnica, férias e aniversários'],
        'fornecedores' => ['rotulo'=>'Fornecedores', 'grupo'=>'LifeTech',
            'desc'=>'Empresas prestadoras de serviço'],
        'criticidade_item_engclin' => ['rotulo'=>'Criticidade', 'grupo'=>'LifeTech',
            'desc'=>'Grau de criticidade por tipo de equipamento'],
        'retiradadepecas_catalogo' => ['rotulo'=>'Retirada de Peças', 'grupo'=>'LifeTech',
            'desc'=>'Peças retiradas de equipamentos baixados'],
        'retiradadepecas_status' => ['rotulo'=>'Situação das Peças', 'grupo'=>'LifeTech',
            'desc'=>'Estado de cada peça retirada'],
        'retiradadepecas_equipamento_tipo' => ['rotulo'=>'Tipos de Equipamento', 'grupo'=>'LifeTech',
            'desc'=>'Tipos usados no catálogo de peças'],
        'documentos_engclin' => ['rotulo'=>'Documentos', 'grupo'=>'LifeTech',
            'desc'=>'Contratos e documentos com valor'],
        'anexos_engclin' => ['rotulo'=>'Anexos das OS', 'grupo'=>'LifeTech',
            'desc'=>'Arquivos enviados nas ordens de serviço (somente leitura)',
            'leitura'=>true],
    ];
}

/** Impede que qualquer nome vindo do navegador chegue ao SQL */
function dt_permitida(string $t): bool {
    return array_key_exists($t, dt_catalogo());
}

/**
 * Registros que ficariam órfãos ao excluir uma linha.
 * Mapeado só onde a ligação é certa. Cada consulta confere antes se a coluna
 * existe, para não quebrar se a estrutura mudar.
 */
function dt_relacoes(): array {
    return [
        'cadastro' => [
            ['tabela'=>'chamado_engclin',      'col'=>'item_id', 'rotulo'=>'chamado(s)'],
            ['tabela'=>'ordemservico_engclin', 'col'=>'item_id', 'rotulo'=>'ordem(ns) de serviço'],
            ['tabela'=>'preventiva_engclin',   'col'=>'item_id', 'rotulo'=>'preventiva(s) agendada(s)'],
        ],
        'chamado_engclin' => [
            ['tabela'=>'ordemservico_engclin',       'col'=>'numero_chamado', 'rotulo'=>'OS'],
            ['tabela'=>'maodeobra_engclin',          'col'=>'numero_chamado', 'rotulo'=>'intervenção(ões)'],
            ['tabela'=>'itens_os_engclin',           'col'=>'numero_chamado', 'rotulo'=>'material(is)'],
            ['tabela'=>'anexos_engclin',             'col'=>'numero_chamado', 'rotulo'=>'anexo(s)'],
            ['tabela'=>'manutencao_externa_engclin', 'col'=>'numero_chamado', 'rotulo'=>'manutenção(ões) externa(s)'],
        ],
        'ordemservico_engclin' => [
            ['tabela'=>'maodeobra_engclin', 'col'=>'numero_chamado', 'rotulo'=>'intervenção(ões)'],
            ['tabela'=>'itens_os_engclin',  'col'=>'numero_chamado', 'rotulo'=>'material(is)'],
        ],
        'usuarios' => [
            ['tabela'=>'usuarios_online', 'col'=>'usuario', 'rotulo'=>'sessão(ões) ativa(s)'],
        ],
    ];
}

/** Colunas de uma tabela, com tipo, chave e se é binária */
function dt_colunas(mysqli $conn, string $t): array {
    $cols = [];
    $r = $conn->query("SHOW FULL COLUMNS FROM `$t`");
    if (!$r) return $cols;
    while ($c = $r->fetch_assoc()) {
        $tipo = strtolower($c['Type']);
        $cols[] = [
            'nome'     => $c['Field'],
            'tipo'     => $c['Type'],
            'binaria'  => (bool)preg_match('/blob|binary/', $tipo),
            'texto'    => (bool)preg_match('/text|json/', $tipo),
            'nulo'     => $c['Null'] === 'YES',
            'chave'    => $c['Key'] === 'PRI',
            'comentario' => $c['Comment'] ?? '',
        ];
    }
    return $cols;
}

/** Nome da coluna que é chave primária, ou null */
function dt_chave(mysqli $conn, string $t): ?string {
    $r = $conn->query("SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'");
    if (!$r) return null;
    $chaves = [];
    while ($k = $r->fetch_assoc()) $chaves[] = $k['Column_name'];
    // Chave composta não é suportada: identificar a linha exigiria montar
    // WHERE com várias colunas, e um erro aí atinge mais de um registro.
    return count($chaves) === 1 ? $chaves[0] : null;
}

/** Uma coluna existe? */
function dt_tem_coluna(mysqli $conn, string $t, string $c): bool {
    $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '" . $conn->real_escape_string($c) . "'");
    return $r && $r->num_rows > 0;
}

/** Grava a alteração na trilha de auditoria */
function dt_auditar(mysqli $conn, array $d): void {
    $st = $conn->prepare("INSERT INTO dev_alteracoes
        (tabela, registro_id, operacao, coluna, valor_antes, valor_depois, usuario, ip)
        VALUES (?,?,?,?,?,?,?,?)");
    if (!$st) return;
    $st->bind_param('ssssssss', $d['tabela'], $d['registro_id'], $d['operacao'],
        $d['coluna'], $d['antes'], $d['depois'], $d['usuario'], $d['ip']);
    @$st->execute();
    $st->close();
}

/**
 * Trata as ações da tela. Devolve true se tratou (e já imprimiu o JSON).
 */
function dt_tratar(mysqli $conn, string $action, string $usuario, string $ip): bool {

    // ── Lista de tabelas com contagem de registros ──────────────────────────
    if ($action === 'tab_catalogo') {
        $saida = [];
        foreach (dt_catalogo() as $nome => $info) {
            $existe = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($nome) . "'");
            if (!$existe || $existe->num_rows === 0) continue;   // tabela renomeada/removida
            $c = $conn->query("SELECT COUNT(*) n FROM `$nome`");
            $saida[] = [
                'tabela'  => $nome,
                'rotulo'  => $info['rotulo'],
                'desc'    => $info['desc'],
                'grupo'   => $info['grupo'],
                'leitura' => !empty($info['leitura']),
                'linhas'  => $c ? (int)$c->fetch_assoc()['n'] : 0,
            ];
        }
        echo json_encode(['ok'=>true, 'tabelas'=>$saida]);
        return true;
    }

    // ── Dados de uma tabela ─────────────────────────────────────────────────
    if ($action === 'tab_dados') {
        $t = trim($_POST['tabela'] ?? '');
        if (!dt_permitida($t)) { echo json_encode(['ok'=>false,'msg'=>'Tabela não permitida.']); return true; }

        $info   = dt_catalogo()[$t];
        $limite = max(10, min(200, (int)($_POST['limite'] ?? 50)));
        $pagina = max(1, (int)($_POST['pagina'] ?? 1));
        $offset = ($pagina - 1) * $limite;
        $busca  = trim($_POST['busca'] ?? '');

        $cols  = dt_colunas($conn, $t);
        $chave = dt_chave($conn, $t);

        // Busca em todas as colunas de texto
        $where = '';
        if ($busca !== '') {
            $b = $conn->real_escape_string($busca);
            $partes = [];
            foreach ($cols as $c) {
                if ($c['binaria']) continue;
                $partes[] = "`{$c['nome']}` LIKE '%$b%'";
            }
            if ($partes) $where = 'WHERE ' . implode(' OR ', $partes);
        }

        $tot = $conn->query("SELECT COUNT(*) n FROM `$t` $where");
        $total = $tot ? (int)$tot->fetch_assoc()['n'] : 0;

        // Binárias não são trazidas: só o tamanho, para não pesar a resposta
        $sel = [];
        foreach ($cols as $c) {
            // Marcador simples e literal: escapes tipo \u0000 são reinterpretados
            // pelo MySQL e chegariam diferentes do que o JavaScript espera.
            $sel[] = $c['binaria']
                ? "IF(`{$c['nome']}` IS NULL, NULL, CONCAT('@@BIN:', LENGTH(`{$c['nome']}`))) AS `{$c['nome']}`"
                : "`{$c['nome']}`";
        }
        $ordem = $chave ? "ORDER BY `$chave` DESC" : '';

        $r = $conn->query("SELECT " . implode(',', $sel) . " FROM `$t` $where $ordem LIMIT $limite OFFSET $offset");
        $linhas = []; if ($r) while ($x = $r->fetch_assoc()) $linhas[] = $x;

        echo json_encode([
            'ok'       => true,
            'tabela'   => $t,
            'rotulo'   => $info['rotulo'],
            'desc'     => $info['desc'],
            'leitura'  => !empty($info['leitura']),
            'bloq'     => $info['bloq'] ?? [],
            'chave'    => $chave,
            'colunas'  => $cols,
            'linhas'   => $linhas,
            'total'    => $total,
            'pagina'   => $pagina,
            'paginas'  => max(1, (int)ceil($total / $limite)),
            'limite'   => $limite,
        ]);
        return true;
    }

    // ── O que seria afetado ao excluir ──────────────────────────────────────
    if ($action === 'tab_relacionados') {
        $t  = trim($_POST['tabela'] ?? '');
        $id = trim($_POST['id'] ?? '');
        if (!dt_permitida($t)) { echo json_encode(['ok'=>false]); return true; }

        $chave = dt_chave($conn, $t);
        $achados = [];

        // Valor da chave estrangeira: nem sempre é o id (chamado usa protocolo)
        $rel = dt_relacoes()[$t] ?? [];
        if ($rel && $chave) {
            $st = $conn->prepare("SELECT * FROM `$t` WHERE `$chave` = ? LIMIT 1");
            if ($st) {
                $st->bind_param('s', $id);
                $st->execute();
                $rr = $st->get_result();
                $linha = $rr ? $rr->fetch_assoc() : null;
                $st->close();

                foreach ($rel as $x) {
                    $tx = $x['tabela'];
                    $existe = $conn->query("SHOW TABLES LIKE '$tx'");
                    if (!$existe || !$existe->num_rows) continue;
                    if (!dt_tem_coluna($conn, $tx, $x['col'])) continue;

                    // Descobre qual valor da linha alimenta a coluna de ligação
                    $valor = null;
                    if ($x['col'] === 'numero_chamado' && isset($linha['numero_chamado'])) $valor = $linha['numero_chamado'];
                    elseif ($x['col'] === 'usuario' && isset($linha['usuario']))           $valor = $linha['usuario'];
                    else                                                                   $valor = $id;
                    if ($valor === null) continue;

                    $sq = $conn->prepare("SELECT COUNT(*) n FROM `$tx` WHERE `{$x['col']}` = ?");
                    if (!$sq) continue;
                    $sq->bind_param('s', $valor);
                    $sq->execute();
                    $rq = $sq->get_result();
                    $n  = $rq ? (int)$rq->fetch_assoc()['n'] : 0;
                    $sq->close();
                    if ($n > 0) $achados[] = "$n {$x['rotulo']}";
                }
            }
        }
        echo json_encode(['ok'=>true, 'relacionados'=>$achados]);
        return true;
    }

    // ── Salvar alterações de uma linha ──────────────────────────────────────
    if ($action === 'tab_salvar') {
        $t = trim($_POST['tabela'] ?? '');
        if (!dt_permitida($t)) { echo json_encode(['ok'=>false,'msg'=>'Tabela não permitida.']); return true; }

        $info = dt_catalogo()[$t];
        if (!empty($info['leitura'])) { echo json_encode(['ok'=>false,'msg'=>'Tabela somente leitura.']); return true; }

        $chave = dt_chave($conn, $t);
        if (!$chave) { echo json_encode(['ok'=>false,'msg'=>'Tabela sem chave primária simples — edição não permitida.']); return true; }

        $id     = trim($_POST['id'] ?? '');
        $campos = json_decode($_POST['campos'] ?? '{}', true);
        if (!is_array($campos) || !$campos) { echo json_encode(['ok'=>false,'msg'=>'Nada para salvar.']); return true; }

        $cols = dt_colunas($conn, $t);
        $mapa = [];
        foreach ($cols as $c) $mapa[$c['nome']] = $c;
        $bloq = array_map('strtolower', $info['bloq'] ?? []);

        // Estado anterior, para a auditoria
        $antes = null;
        $sa = $conn->prepare("SELECT * FROM `$t` WHERE `$chave` = ? LIMIT 1");
        if ($sa) {
            $sa->bind_param('s', $id); $sa->execute();
            $ra = $sa->get_result(); $antes = $ra ? $ra->fetch_assoc() : null;
            $sa->close();
        }
        if (!$antes) { echo json_encode(['ok'=>false,'msg'=>'Registro não encontrado.']); return true; }

        $sets = []; $vals = []; $mudancas = [];
        foreach ($campos as $col => $valor) {
            if (!isset($mapa[$col]))                 continue;   // coluna inexistente
            if ($mapa[$col]['binaria'])              continue;   // binário nunca por aqui
            if ($col === $chave)                     continue;   // chave não se altera
            if (in_array(strtolower($col), $bloq, true)) continue;

            $novo    = ($valor === '' && $mapa[$col]['nulo']) ? null : $valor;
            $antigo  = $antes[$col];
            if ((string)$antigo === (string)$novo) continue;     // não mudou

            $sets[] = "`$col` = ?";
            $vals[] = $novo;
            $mudancas[] = ['coluna'=>$col, 'antes'=>$antigo, 'depois'=>$novo];
        }

        if (!$sets) { echo json_encode(['ok'=>true,'msg'=>'Nenhuma alteração.']); return true; }

        $vals[] = $id;
        $st = $conn->prepare("UPDATE `$t` SET " . implode(', ', $sets) . " WHERE `$chave` = ? LIMIT 1");
        if (!$st) { echo json_encode(['ok'=>false,'msg'=>'Erro: ' . $conn->error]); return true; }
        $st->bind_param(str_repeat('s', count($vals)), ...$vals);
        $ok = $st->execute();
        $st->close();

        if ($ok) {
            foreach ($mudancas as $m) {
                dt_auditar($conn, [
                    'tabela'=>$t, 'registro_id'=>$id, 'operacao'=>'EDICAO',
                    'coluna'=>$m['coluna'],
                    'antes'=>  $m['antes']  === null ? null : mb_substr((string)$m['antes'], 0, 500),
                    'depois'=> $m['depois'] === null ? null : mb_substr((string)$m['depois'], 0, 500),
                    'usuario'=>$usuario, 'ip'=>$ip,
                ]);
            }
            echo json_encode(['ok'=>true, 'msg'=>count($mudancas) . ' campo(s) alterado(s).']);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>'Não foi possível salvar.']);
        }
        return true;
    }

    // ── Excluir uma linha ───────────────────────────────────────────────────
    if ($action === 'tab_excluir') {
        $t = trim($_POST['tabela'] ?? '');
        if (!dt_permitida($t)) { echo json_encode(['ok'=>false,'msg'=>'Tabela não permitida.']); return true; }

        $info = dt_catalogo()[$t];
        if (!empty($info['leitura'])) { echo json_encode(['ok'=>false,'msg'=>'Tabela somente leitura.']); return true; }

        $chave = dt_chave($conn, $t);
        if (!$chave) { echo json_encode(['ok'=>false,'msg'=>'Tabela sem chave primária simples.']); return true; }

        $id = trim($_POST['id'] ?? '');

        // Guarda a linha inteira antes de apagar: é a única chance de saber
        // o que havia ali, se a exclusão tiver sido engano.
        $antes = null;
        $sa = $conn->prepare("SELECT * FROM `$t` WHERE `$chave` = ? LIMIT 1");
        if ($sa) {
            $sa->bind_param('s', $id); $sa->execute();
            $ra = $sa->get_result(); $antes = $ra ? $ra->fetch_assoc() : null;
            $sa->close();
        }
        if (!$antes) { echo json_encode(['ok'=>false,'msg'=>'Registro não encontrado.']); return true; }

        // Remove binários do retrato, senão a auditoria fica gigante
        $retrato = [];
        foreach ($antes as $k => $v) {
            $retrato[$k] = (is_string($v) && strlen($v) > 300) ? mb_substr($v, 0, 300) . '…' : $v;
        }

        $st = $conn->prepare("DELETE FROM `$t` WHERE `$chave` = ? LIMIT 1");
        if (!$st) { echo json_encode(['ok'=>false,'msg'=>'Erro: ' . $conn->error]); return true; }
        $st->bind_param('s', $id);
        $ok = $st->execute();
        $st->close();

        if ($ok) {
            dt_auditar($conn, [
                'tabela'=>$t, 'registro_id'=>$id, 'operacao'=>'EXCLUSAO', 'coluna'=>null,
                'antes'=> mb_substr(json_encode($retrato, JSON_UNESCAPED_UNICODE), 0, 4000),
                'depois'=>null, 'usuario'=>$usuario, 'ip'=>$ip,
            ]);
            echo json_encode(['ok'=>true, 'msg'=>'Registro excluído.']);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>'Não foi possível excluir.']);
        }
        return true;
    }

    // ── Trilha de alterações ────────────────────────────────────────────────
    if ($action === 'tab_auditoria') {
        $tem = $conn->query("SHOW TABLES LIKE 'dev_alteracoes'");
        if (!$tem || !$tem->num_rows) { echo json_encode(['ok'=>true,'data'=>[]]); return true; }
        $t = trim($_POST['tabela'] ?? '');
        $w = dt_permitida($t) ? "WHERE tabela = '" . $conn->real_escape_string($t) . "'" : '';
        $r = $conn->query("SELECT id, tabela, registro_id, operacao, coluna, valor_antes,
                                  valor_depois, usuario, ip,
                                  DATE_FORMAT(criado_em,'%d/%m/%Y %H:%i') AS quando
                           FROM dev_alteracoes $w ORDER BY id DESC LIMIT 200");
        $rows = []; if ($r) while ($x = $r->fetch_assoc()) $rows[] = $x;
        echo json_encode(['ok'=>true, 'data'=>$rows]);
        return true;
    }

    return false;
}
