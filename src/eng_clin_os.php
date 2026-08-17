<?php
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = 'os';   // item ativo no menu lateral
include 'conexao.php';

// PHP 8.1+ passou a lançar mysqli_sql_exception por padrão. O restante do
// sistema assume o comportamento antigo (prepare devolve false e o código
// testa com `if ($stmt)`). Sem isto, qualquer coluna ausente vira erro 500.
mysqli_report(MYSQLI_REPORT_OFF);

if (!isset($_SESSION['usuario_logado'])) { header("Location: index.html"); exit(); }

$usuario = $_SESSION['usuario_logado'];
$nivel = 'C'; $classe_usuario = ''; $status_user = 'ATIVO'; $nome_usuario = $usuario;

// A coluna `nome` pode não existir em usuarios — mesmo fallback usado em
// eng_clin_ordemdeservico.php
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status, nome FROM usuarios WHERE usuario=?");
if (!$stmt) $stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
if ($stmt) {
    $stmt->bind_param("s", $usuario);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($r = $res->fetch_assoc())) {
        $nivel          = strtoupper(trim($r['permicao']       ?? 'C'));
        $classe_usuario = strtoupper(trim($r['classe_usuario'] ?? ''));
        $status_user    = $r['status'] ?? 'ATIVO';
        $nome_usuario   = trim($r['nome'] ?? '') ?: $usuario;
    }
    $stmt->close();
}

if ($status_user !== 'ATIVO') {
    session_destroy();
    header("Location: index.html?erro=bloqueado"); exit();
}
if (!in_array($classe_usuario, ['DEV','ENGENHARIA CLINICA']) || !in_array($nivel, ['A','B','C'])) {
    header("Location: acesso_bloqueado.html"); exit();
}

date_default_timezone_set('America/Sao_Paulo');

/* ═══════════════════════════════════════════════════════════════════════════
   OCORRÊNCIAS — lista estruturada.
   As opções definitivas serão definidas depois; basta editar este array.
   ═══════════════════════════════════════════════════════════════════════════ */
$OCORRENCIAS = [
    'MANUTENCAO_CORRETIVA'  => 'Manutenção corretiva',
    'MANUTENCAO_PREVENTIVA' => 'Manutenção preventiva',
    'TREINAMENTO'           => 'Treinamento / orientação de uso',
    'INSTALACAO'            => 'Instalação / configuração',
    'AVALIACAO_TECNICA'     => 'Avaliação técnica',
    'SEM_DEFEITO'           => 'Sem defeito constatado',
];

/* Motivos = coluna `motivo` (ENUM). É o "status/observação" do fluxo. */
$MOTIVOS = [
    'PROBLEMA_SOLUCIONADO'  => 'Problema solucionado',
    'FALTA_DE_PECAS'        => 'Aguardando peças',
    'AGUARDANDO_ORCAMENTO'  => 'Aguardando orçamento',
    'MANUTENCAO_TERCEIROS'  => 'Enviado para manutenção externa',
    'AGUARDANDO_PATRIMONIO' => 'Aguardando patrimônio',
    'OBSOLESCENCIA'         => 'Obsoleto',
    'ITEM_ALUGADO'          => 'Item alugado',
    'OUTROS'                => 'Outros',
];

/* ═══════════════════════════════════════════════════════════════════════════
   UNIDADES DE ESTOQUE
   O chamado grava "CASA DE PORTUGAL", mas o estoque usa
   "CASA DE PORTUGAL (ESTOQUE CENTRAL)". Sem traduzir, a busca por estoque
   da unidade não acha nada. Mesmo mapa de eng_clin_inventario.php.
   ═══════════════════════════════════════════════════════════════════════════ */
$UNIDADES_MAP = [
    'CASA DE PORTUGAL'                   => 'CASA DE PORTUGAL (ESTOQUE CENTRAL)',
    'CASA DE PORTUGAL (ESTOQUE CENTRAL)' => 'CASA DE PORTUGAL (ESTOQUE CENTRAL)',
    'EVANGELICO'         => 'EVANGELICO',       'EVANGÉLICO'      => 'EVANGELICO',
    'ILHA DO GOVERNADOR' => 'ILHA DO GOVERNADOR',
    'RIO LARANJEIRAS'    => 'RIO LARANJEIRAS',  'RIO BOTAFOGO'    => 'RIO BOTAFOGO',
    'SÃO BERNARDO'       => 'SÃO BERNARDO',     'SAO BERNARDO'    => 'SÃO BERNARDO',
    'PREMIUM'            => 'PREMIUM',          'PRONTOCOR'       => 'PRONTOCOR',
    'OFTALMOCASA'        => 'OFTALMOCASA',      'MENSSANA'        => 'MENSSANA',
    'OUTROS'             => 'OUTROS',
];

/** Traduz a unidade do chamado para a unidade correspondente no estoque */
function ec_unidade_estoque(string $u): string {
    global $UNIDADES_MAP;
    $u = strtoupper(trim($u));
    return $UNIDADES_MAP[$u] ?? $u;
}

/* Campos de nível da OS que o autosave aceita.
   `problema` saiu: o relato vem do chamado (causa) e o que foi feito vem do
   serviço de cada intervenção. Manter no whitelist sem campo na tela só
   deixaria uma porta aberta sem uso. */
$CAMPOS_SALVAVEIS = [
    'motivo'              => ['col' => 'motivo',              'etapa' => 'status'],
    'periodicidade_meses' => ['col' => 'periodicidade_meses', 'etapa' => 'preventiva'],
];

/* Campos por INTERVENÇÃO (tabela maodeobra_engclin).
   Cada técnico tem sua própria entrada — vários podem atuar na mesma OS. */
$CAMPOS_MO = ['data_inicio','hora_inicio','data_fim','hora_fim','ocorrencia','servico','status'];

/* ═══════════════════════════════════════════════════════════════════════════
   STATUS POR PROCESSO
   Cada intervenção e cada manutenção externa tem seu próprio status.
   A OS é pendência enquanto QUALQUER processo estiver pendente.
   ═══════════════════════════════════════════════════════════════════════════ */
$ST_PROCESSO = [
    'EM_ANDAMENTO'          => 'Trabalhos em andamento',
    'PROBLEMA_SOLUCIONADO'  => 'Problema solucionado',
    'FALTA_DE_PECAS'        => 'Aguardando peças',
    'AGUARDANDO_ORCAMENTO'  => 'Aguardando orçamento',
    'AGUARDANDO_PATRIMONIO' => 'Aguardando patrimônio',
    'MANUTENCAO_TERCEIROS'  => 'Enviado para manutenção externa',
    'OBSOLESCENCIA'         => 'Obsoleto',
    'SEM_SOLUCAO'           => 'Sem solução',
    'OUTROS'                => 'Outros',
];
/* Encerram o ciclo. Todo o resto mantém a OS pendente. */
const ST_CONCLUSIVOS = ['PROBLEMA_SOLUCIONADO','OBSOLESCENCIA','SEM_SOLUCAO'];

function ec_conclusivo(string $st): bool {
    return in_array($st, ST_CONCLUSIVOS, true);
}

/** Status da manutenção externa traduzido para o vocabulário dos processos */
function ec_status_externa(string $st): string {
    return match ($st) {
        'AGUARDANDO_ORCAMENTO'  => 'AGUARDANDO_ORCAMENTO',
        'EM_MANUTENCAO_EXTERNA' => 'MANUTENCAO_TERCEIROS',
        'CONCLUIDO'             => 'PROBLEMA_SOLUCIONADO',
        default                 => 'EM_ANDAMENTO',
    };
}

/**
 * Recalcula o motivo da OS a partir dos processos.
 * O motivo vira um espelho do estado real: se houver processo pendente,
 * assume o do mais recente; se todos concluíram, assume o desfecho.
 * Devolve ['pendente' => bool, 'motivo' => string].
 */
function ec_recalcular_os(mysqli $conn, string $proto): array {
    $pendentes = []; $concluidos = [];

    $st = $conn->prepare("SELECT status FROM maodeobra_engclin WHERE numero_chamado=? ORDER BY id ASC");
    if ($st) {
        $st->bind_param('s', $proto); $st->execute();
        $r = $st->get_result();
        if ($r) while ($x = $r->fetch_assoc()) {
            ec_conclusivo($x['status']) ? $concluidos[] = $x['status'] : $pendentes[] = $x['status'];
        }
        $st->close();
    }

    $st = $conn->prepare("SELECT status FROM manutencao_externa_engclin WHERE numero_chamado=? ORDER BY id ASC");
    if ($st) {
        $st->bind_param('s', $proto); $st->execute();
        $r = $st->get_result();
        if ($r) while ($x = $r->fetch_assoc()) {
            $eq = ec_status_externa($x['status']);
            ec_conclusivo($eq) ? $concluidos[] = $eq : $pendentes[] = $eq;
        }
        $st->close();
    }

    if ($pendentes) {
        $motivo = end($pendentes);                 // o mais recente manda
        // EM_ANDAMENTO não existe no ENUM `motivo` da OS — grava NULL e a
        // tela exibe "OS em andamento".
        $mot_os = ($motivo === 'EM_ANDAMENTO' || $motivo === 'OUTROS') ? null : $motivo;
    } else {
        // Melhor desfecho disponível
        $ordem = ['PROBLEMA_SOLUCIONADO' => 3, 'OBSOLESCENCIA' => 2, 'SEM_SOLUCAO' => 1];
        usort($concluidos, fn($a, $b) => ($ordem[$b] ?? 0) <=> ($ordem[$a] ?? 0));
        $motivo = $concluidos[0] ?? 'PROBLEMA_SOLUCIONADO';
        $mot_os = ($motivo === 'SEM_SOLUCAO') ? 'OUTROS' : $motivo;
    }

    $stU = $conn->prepare("UPDATE ordemservico_engclin SET motivo=? WHERE numero_chamado=?");
    if ($stU) { $stU->bind_param('ss', $mot_os, $proto); $stU->execute(); $stU->close(); }

    return ['pendente' => (bool)$pendentes, 'motivo' => $motivo];
}

function registrar_evento_os($conn, $num_ch, $usuario, $nome, $tipo, $desc) {
    $st = $conn->prepare("INSERT INTO historico_eventos_engclin (numero_chamado,numero_os,usuario,nome_usuario,tipo_evento,descricao_evento,data_evento,hora_evento) VALUES (?,?,?,?,?,?,?,?)");
    if ($st) {
        $d = date('Y-m-d'); $h = date('H:i:s');
        $st->bind_param('ssssssss', $num_ch, $num_ch, $usuario, $nome, $tipo, $desc, $d, $h);
        $st->execute(); $st->close();
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   AJAX: SALVAMENTO POR ETAPA
   Grava um campo por vez. Sai antes de qualquer HTML.
   ═══════════════════════════════════════════════════════════════════════════ */
if (($_POST['ajax'] ?? '') === 'salvar_campo') {
    header('Content-Type: application/json; charset=utf-8');

    $protocolo = trim($_POST['protocolo'] ?? '');
    $campo     = trim($_POST['campo']     ?? '');
    $valor     = $_POST['valor'] ?? '';
    $mo_id     = (int)($_POST['mo_id'] ?? 0);   // > 0 = campo de intervenção

    $eh_mo = $mo_id > 0;
    if ($protocolo === '' || (!$eh_mo && !isset($CAMPOS_SALVAVEIS[$campo]))
                          || ($eh_mo && !in_array($campo, $CAMPOS_MO, true))) {
        echo json_encode(['ok' => false, 'msg' => 'Campo inválido.']); exit();
    }

    // A OS precisa estar aberta
    $stV = $conn->prepare("SELECT status, etapas_salvas FROM ordemservico_engclin WHERE numero_chamado=? LIMIT 1");
    if (!$stV) { echo json_encode(['ok'=>false,'msg'=>'Erro de banco.']); exit(); }
    $stV->bind_param('s', $protocolo); $stV->execute();
    $resV = $stV->get_result();
    $rowV = $resV ? $resV->fetch_assoc() : null;
    $stV->close();

    if (!$rowV) { echo json_encode(['ok'=>false,'msg'=>'OS não encontrada.']); exit(); }
    if ($rowV['status'] !== 'ABERTA') {
        echo json_encode(['ok'=>false,'msg'=>'OS já encerrada — não é possível alterar.']); exit();
    }

    $col   = $eh_mo ? $campo : $CAMPOS_SALVAVEIS[$campo]['col'];
    $etapa = $eh_mo ? 'mao_obra' : $CAMPOS_SALVAVEIS[$campo]['etapa'];

    // Validação por tipo
    $valor = trim((string)$valor);
    if ($campo === 'ocorrencia' && $valor !== '' && !isset($OCORRENCIAS[$valor])) {
        echo json_encode(['ok'=>false,'msg'=>'Ocorrência inválida.']); exit();
    }
    if ($campo === 'motivo' && $valor !== '' && !isset($MOTIVOS[$valor])) {
        echo json_encode(['ok'=>false,'msg'=>'Motivo inválido.']); exit();
    }
    if ($campo === 'status' && !isset($ST_PROCESSO[$valor])) {
        echo json_encode(['ok'=>false,'msg'=>'Status inválido.']); exit();
    }
    if ($campo === 'periodicidade_meses' && $valor !== '') {
        $m = (int)$valor;
        if ($m < 1 || $m > 120) { echo json_encode(['ok'=>false,'msg'=>'Informe de 1 a 120 meses.']); exit(); }
        $valor = (string)$m;
    }
    if (in_array($campo, ['data_inicio','data_fim'], true) && $valor !== ''
        && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        echo json_encode(['ok'=>false,'msg'=>'Data inválida.']); exit();
    }
    if (in_array($campo, ['hora_inicio','hora_fim'], true) && $valor !== '') {
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $valor)) {
            echo json_encode(['ok'=>false,'msg'=>'Hora inválida.']); exit();
        }
        if (strlen($valor) === 5) $valor .= ':00';
    }

    $valorBind = ($valor === '') ? null : $valor;

    if ($eh_mo) {
        // A intervenção precisa pertencer a este protocolo
        $stU = $conn->prepare("UPDATE maodeobra_engclin SET `$col`=? WHERE id=? AND numero_chamado=?");
        if (!$stU) { echo json_encode(['ok'=>false,'msg'=>'Campo nao gravavel.']); exit(); }
        $stU->bind_param('sis', $valorBind, $mo_id, $protocolo);
        $stU->execute(); $stU->close();
    } else {
        $stU = $conn->prepare("UPDATE ordemservico_engclin SET `$col`=? WHERE numero_chamado=?");
        if (!$stU) { echo json_encode(['ok'=>false,'msg'=>'Campo nao gravavel.']); exit(); }
        $stU->bind_param('ss', $valorBind, $protocolo);
        $stU->execute(); $stU->close();
    }

    // Marcar a etapa como preenchida
    $etapas = array_filter(array_map('trim', explode(',', (string)$rowV['etapas_salvas'])));
    if (!in_array($etapa, $etapas, true)) {
        $etapas[] = $etapa;
        $novo = implode(',', $etapas);
        $stE = $conn->prepare("UPDATE ordemservico_engclin SET etapas_salvas=? WHERE numero_chamado=?");
        if ($stE) { $stE->bind_param('ss', $novo, $protocolo); $stE->execute(); $stE->close(); }
    }

    // Mudar o status de um processo muda a situação da OS inteira
    $recalc = null;
    if ($eh_mo && $campo === 'status') {
        $recalc = ec_recalcular_os($conn, $protocolo);
        registrar_evento_os($conn, $protocolo, $usuario, $nome_usuario, 'STATUS_INTERVENCAO',
            "Intervenção #{$mo_id}: status alterado para " . ($ST_PROCESSO[$valor] ?? $valor) . ".");
    }

    echo json_encode([
        'ok'    => true,
        'etapa' => $etapa,
        'hora'  => date('H:i:s'),
        'os_pendente' => $recalc['pendente'] ?? null,
        'os_motivo'   => $recalc ? ($ST_PROCESSO[$recalc['motivo']] ?? $recalc['motivo']) : null,
    ]);
    exit();
}

$msg = ''; $msg_tipo = '';

/* ═══════════════════════════════════════════════════════════════════════════
   DOWNLOAD DE ANEXO — sai antes de qualquer HTML
   ═══════════════════════════════════════════════════════════════════════════ */
if (isset($_GET['anexo'])) {
    $id_anx = (int)$_GET['anexo'];
    $stA = $conn->prepare("SELECT nome_arquivo, mime, conteudo FROM anexos_engclin WHERE id=? LIMIT 1");
    if ($stA) {
        $stA->bind_param('i', $id_anx); $stA->execute();
        $rA = $stA->get_result();
        $anx = $rA ? $rA->fetch_assoc() : null;
        $stA->close();
        if ($anx) {
            header('Content-Type: ' . $anx['mime']);
            header('Content-Disposition: inline; filename="' . basename($anx['nome_arquivo']) . '"');
            header('Content-Length: ' . strlen($anx['conteudo']));
            echo $anx['conteudo'];
            exit();
        }
    }
    http_response_code(404); echo 'Anexo não encontrado.'; exit();
}

/* ═══════════════════════════════════════════════════════════════════════════
   POST: INTERVENÇÕES (mão de obra)
   Vários técnicos podem atuar na mesma OS. Cada um tem sua própria entrada
   com período, ocorrência e serviço — o histórico fica rastreável por pessoa.
   ═══════════════════════════════════════════════════════════════════════════ */
if (in_array(($_POST['acao'] ?? ''), ['add_intervencao','fim_intervencao','del_intervencao'], true)) {
    $acao_mo = $_POST['acao'];
    $proto   = trim($_POST['protocolo'] ?? '');
    $mo_id   = (int)($_POST['mo_id'] ?? 0);

    // Só em OS aberta
    $stSt = $conn->prepare("SELECT status FROM ordemservico_engclin WHERE numero_chamado=? LIMIT 1");
    $st_os = '';
    if ($stSt) {
        $stSt->bind_param('s', $proto); $stSt->execute();
        $rst = $stSt->get_result(); $rowSt = $rst ? $rst->fetch_assoc() : null;
        $st_os = $rowSt['status'] ?? ''; $stSt->close();
    }

    if ($st_os !== 'ABERTA') {
        $msg = 'OS encerrada — não é possível alterar as intervenções.'; $msg_tipo = 'erro';
    } else {
        if ($acao_mo === 'add_intervencao') {
            // O técnico executante é escolhido, não assumido pelo login —
            // quem registra nem sempre é quem executa.
            $tec_usu = trim($_POST['tecnico_usuario'] ?? '');
            if ($tec_usu === '') {
                $msg = 'Selecione o técnico que vai executar o atendimento.'; $msg_tipo = 'erro';
                header("Location: eng_clin_os.php?protocolo=".urlencode($proto)."&m=".urlencode($msg)."&t=erro#maoobra");
                exit();
            }

            $nome_tec = '';
            $stNt = $conn->prepare("SELECT nome FROM tecnico WHERE usuario=? LIMIT 1");
            if ($stNt) {
                $stNt->bind_param('s', $tec_usu); $stNt->execute();
                $rnt = $stNt->get_result(); $rowNt = $rnt ? $rnt->fetch_assoc() : null;
                if ($rowNt) $nome_tec = $rowNt['nome'];
                $stNt->close();
            }
            if ($nome_tec === '') {
                $msg = 'Técnico não encontrado no cadastro.'; $msg_tipo = 'erro';
                header("Location: eng_clin_os.php?protocolo=".urlencode($proto)."&m=".urlencode($msg)."&t=erro#maoobra");
                exit();
            }

            $d = date('Y-m-d'); $h = date('H:i:s');
            $stAdd = $conn->prepare("INSERT INTO maodeobra_engclin (numero_chamado,usuario,nome_tecnico,data_inicio,hora_inicio) VALUES (?,?,?,?,?)");
            if ($stAdd) {
                $stAdd->bind_param('sssss', $proto, $tec_usu, $nome_tec, $d, $h);
                $stAdd->execute(); $stAdd->close();
            }
            registrar_evento_os($conn, $proto, $usuario, $nome_usuario, 'MAO_OBRA_INICIO',
                "Intervenção de {$nome_tec} adicionada por {$nome_usuario}.");
            $msg = "Intervenção de {$nome_tec} adicionada. Preencha o serviço executado."; $msg_tipo = 'ok';

        } elseif ($acao_mo === 'fim_intervencao') {
            $d = date('Y-m-d'); $h = date('H:i:s');
            $stF = $conn->prepare("UPDATE maodeobra_engclin SET data_fim=?, hora_fim=? WHERE id=? AND numero_chamado=? AND data_fim IS NULL");
            if ($stF) { $stF->bind_param('ssis', $d, $h, $mo_id, $proto); $stF->execute(); $stF->close(); }
            registrar_evento_os($conn, $proto, $usuario, $nome_usuario, 'MAO_OBRA_FIM',
                "Intervenção #{$mo_id} finalizada por {$nome_usuario}.");
            $msg = 'Intervenção finalizada.'; $msg_tipo = 'ok';

        } else { // del_intervencao
            // Não remove se houver material lançado nela
            $temMat = 0;
            $stTm = $conn->prepare("SELECT COUNT(*) AS c FROM itens_os_engclin WHERE id_maodeobra=?");
            if ($stTm) {
                $stTm->bind_param('i', $mo_id); $stTm->execute();
                $rtm = $stTm->get_result(); $temMat = (int)(($rtm ? $rtm->fetch_assoc()['c'] : 0) ?? 0);
                $stTm->close();
            }
            if ($temMat > 0) {
                $msg = 'Esta intervenção tem material lançado e não pode ser removida.'; $msg_tipo = 'erro';
            } else {
                $stD = $conn->prepare("DELETE FROM maodeobra_engclin WHERE id=? AND numero_chamado=?");
                if ($stD) { $stD->bind_param('is', $mo_id, $proto); $stD->execute(); $stD->close(); }
                registrar_evento_os($conn, $proto, $usuario, $nome_usuario, 'MAO_OBRA_REMOVIDA',
                    "Intervenção #{$mo_id} removida por {$nome_usuario}.");
                $msg = 'Intervenção removida.'; $msg_tipo = 'ok';
            }
        }
        header("Location: eng_clin_os.php?protocolo=".urlencode($proto)."&m=".urlencode($msg)."&t=".$msg_tipo."#maoobra");
        exit();
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   POST: MANUTENÇÃO EXTERNA — dados e anexos
   ═══════════════════════════════════════════════════════════════════════════ */
if (($_POST['acao'] ?? '') === 'salvar_externa') {
    $proto     = trim($_POST['protocolo'] ?? '');
    $empresa   = trim($_POST['empresa']   ?? '');
    $orcamento = ($_POST['orcamento'] ?? 'NAO') === 'SIM' ? 'SIM' : 'NAO';
    $valor_raw = str_replace(['.', ','], ['', '.'], trim($_POST['valor_orcamento'] ?? ''));
    $valor_orc = is_numeric($valor_raw) ? (float)$valor_raw : null;
    $link_orc  = trim($_POST['link_orcamento'] ?? '');
    $problema  = trim($_POST['problema_ext']   ?? '');

    // ENVIO = equipamento sai | VISITA = técnico vem ao hospital
    $tipo_ext  = ($_POST['tipo_ext'] ?? 'ENVIO') === 'VISITA' ? 'VISITA' : 'ENVIO';
    $st_ext    = $_POST['status_ext'] ?? 'AGUARDANDO_ORCAMENTO';
    if (!in_array($st_ext, ['AGUARDANDO_ORCAMENTO','EM_MANUTENCAO_EXTERNA','CONCLUIDO'], true)) {
        $st_ext = 'AGUARDANDO_ORCAMENTO';
    }
    // Campos da visita técnica
    $v_tec  = trim($_POST['visita_tecnico'] ?? '');
    $v_data = trim($_POST['visita_data']    ?? '') ?: null;
    $v_cheg = trim($_POST['visita_chegada'] ?? '') ?: null;
    $v_said = trim($_POST['visita_saida']   ?? '') ?: null;
    $v_sol  = trim($_POST['visita_solucao'] ?? '');

    // Link só é aceito se for http/https — evita javascript: na tela
    if ($link_orc !== '' && !preg_match('~^https?://~i', $link_orc)) {
        $link_orc = 'https://' . $link_orc;
    }
    if ($link_orc !== '' && !filter_var($link_orc, FILTER_VALIDATE_URL)) $link_orc = '';

    $stO2 = $conn->prepare("SELECT status, item_id, manutencao_externa FROM ordemservico_engclin WHERE numero_chamado=? LIMIT 1");
    $osX = null;
    if ($stO2) {
        $stO2->bind_param('s', $proto); $stO2->execute();
        $rx = $stO2->get_result(); $osX = $rx ? $rx->fetch_assoc() : null; $stO2->close();
    }

    if (!$osX || $osX['status'] !== 'ABERTA') {
        $msg = 'OS não encontrada ou já encerrada.'; $msg_tipo = 'erro';
    } elseif ($empresa === '') {
        $msg = 'Selecione a empresa de manutenção externa.'; $msg_tipo = 'erro';
    } else {
        $d_now = date('Y-m-d'); $h_now = date('H:i:s');
        $primeira_vez = ($osX['manutencao_externa'] !== 'SIM');

        // Um registro de manutenção externa por protocolo
        $stEx = $conn->prepare("SELECT id FROM manutencao_externa_engclin WHERE numero_chamado=? ORDER BY id DESC LIMIT 1");
        $id_ext = 0;
        if ($stEx) {
            $stEx->bind_param('s', $proto); $stEx->execute();
            $rex = $stEx->get_result(); $rowEx = $rex ? $rex->fetch_assoc() : null; $stEx->close();
            $id_ext = (int)($rowEx['id'] ?? 0);
        }

        if ($id_ext > 0) {
            $stUx = $conn->prepare("UPDATE manutencao_externa_engclin SET empresa=?, tipo=?, status=?, problema=?, orcamento=?, valor_orcamento=?, link_orcamento=?, visita_tecnico=?, visita_data=?, visita_chegada=?, visita_saida=?, visita_solucao=? WHERE id=?");
            if ($stUx) {
                $stUx->bind_param('sssssdssssssi', $empresa, $tipo_ext, $st_ext, $problema, $orcamento, $valor_orc,
                                  $link_orc, $v_tec, $v_data, $v_cheg, $v_said, $v_sol, $id_ext);
                $stUx->execute(); $stUx->close();
            }
        } else {
            $stIx = $conn->prepare("INSERT INTO manutencao_externa_engclin (numero_chamado,numero_os,usuario_saida,problema,empresa,tipo,status,data_saida,hora_saida,orcamento,valor_orcamento,link_orcamento,visita_tecnico,visita_data,visita_chegada,visita_saida,visita_solucao) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if ($stIx) {
                // 17 tipos: 10 strings, valor_orcamento (d) na 11ª, 6 strings
                $stIx->bind_param('ssssssssssdssssss', $proto, $proto, $usuario, $problema, $empresa, $tipo_ext, $st_ext,
                                  $d_now, $h_now, $orcamento, $valor_orc, $link_orc, $v_tec, $v_data, $v_cheg, $v_said, $v_sol);
                $stIx->execute(); $id_ext = $conn->insert_id; $stIx->close();
            }
        }

        // REGRA: sem orçamento aprovado, a OS fica aguardando orçamento.
        // Sobrepõe o motivo escolhido — o item foi enviado, mas o ciclo só
        // avança quando a empresa responder.
        if ($orcamento === 'NAO') {
            $mot_forcado = 'AGUARDANDO_ORCAMENTO';
            $stMt = $conn->prepare("UPDATE ordemservico_engclin SET motivo=? WHERE numero_chamado=?");
            if ($stMt) { $stMt->bind_param('ss', $mot_forcado, $proto); $stMt->execute(); $stMt->close(); }
        }

        // Marca a OS e a etapa
        $conn->query("UPDATE ordemservico_engclin SET manutencao_externa='SIM', etapas_salvas =
            CASE WHEN etapas_salvas IS NULL OR etapas_salvas = '' THEN 'externa'
                 WHEN FIND_IN_SET('externa', etapas_salvas) > 0 THEN etapas_salvas
                 ELSE CONCAT(etapas_salvas, ',externa') END
            WHERE numero_chamado = '".mysqli_real_escape_string($conn,$proto)."'");

        /* ── Anexos (BLOB) ───────────────────────────────────────────────── */
        $LIMITE = 8 * 1024 * 1024;   // 8 MB por arquivo
        $MIMES  = [
            'image/jpeg'=>'FOTO','image/png'=>'FOTO','image/webp'=>'FOTO',
            'image/gif'=>'FOTO','image/bmp'=>'FOTO','application/pdf'=>'PDF',
        ];
        $enviados = 0; $recusados = [];

        foreach (['fotos','pdfs'] as $campo) {
            if (empty($_FILES[$campo]['name'][0])) continue;
            $n = count($_FILES[$campo]['name']);
            for ($i = 0; $i < $n; $i++) {
                if (($_FILES[$campo]['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
                $tmp  = $_FILES[$campo]['tmp_name'][$i];
                $nome = $_FILES[$campo]['name'][$i];
                $tam  = (int)$_FILES[$campo]['size'][$i];

                if ($tam > $LIMITE) { $recusados[] = "{$nome} (acima de 8 MB)"; continue; }

                // MIME real do conteúdo, não o enviado pelo navegador
                $mime = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: '') : '';
                if (!isset($MIMES[$mime])) { $recusados[] = "{$nome} (tipo não permitido)"; continue; }

                $bin = file_get_contents($tmp);
                if ($bin === false) continue;

                $tipo = $MIMES[$mime];
                $ctx  = 'MANUTENCAO_EXTERNA';
                $stAn = $conn->prepare("INSERT INTO anexos_engclin (numero_chamado,contexto,tipo,nome_arquivo,mime,tamanho,conteudo,usuario) VALUES (?,?,?,?,?,?,?,?)");
                if ($stAn) {
                    $nulo = null;
                    $stAn->bind_param('sssssibs', $proto, $ctx, $tipo, $nome, $mime, $tam, $nulo, $usuario);
                    $stAn->send_long_data(6, $bin);   // BLOB fora do bind normal
                    if ($stAn->execute()) $enviados++;
                    $stAn->close();
                }
            }
        }

        // Movimentação: o item vai para a Engenharia Clínica como qualquer OS,
        // mas a observação registra que ele seguiu para a empresa externa.
        if ($primeira_vez && !empty($osX['item_id'])) {
            require_once __DIR__ . '/eng_clin_mover_item.php';
            $dst = eng_clin_destino_manutencao();
            $dst['obs']     = "ENVIADO PARA MANUTENÇÃO EXTERNA — {$empresa} (OS {$proto})";
            $dst['usuario'] = $usuario;
            $dst['data']    = $d_now;
            $mvx = eng_clin_mover_item($conn, (int)$osX['item_id'], $dst);
            registrar_evento_os($conn, $proto, $usuario, $nome_usuario,
                $mvx['ok'] ? 'MANUT_EXTERNA_SAIDA' : 'MOV_PATRIMONIO_ERRO',
                $mvx['ok']
                  ? "Equipamento enviado para manutenção externa — {$empresa}. Movimentado de {$mvx['de']} para {$mvx['para']}."
                  : "Falha ao movimentar para manutenção externa: ".$mvx['erro']);
        } else {
            registrar_evento_os($conn, $proto, $usuario, $nome_usuario, 'MANUT_EXTERNA_ATUALIZADA',
                "Dados da manutenção externa atualizados. Empresa: {$empresa}." .
                ($orcamento === 'SIM' && $valor_orc !== null ? " Orçamento: R$ ".number_format($valor_orc,2,',','.') : ''));
        }

        // O status da externa participa da situação geral da OS
        ec_recalcular_os($conn, $proto);

        $msg = 'Manutenção externa salva.'
             . ($orcamento === 'NAO' ? ' Situação definida como "Aguardando orçamento".' : '')
             . ($enviados ? " {$enviados} anexo(s) enviado(s)." : '')
             . ($recusados ? ' Recusados: '.implode('; ', $recusados) : '');
        $msg_tipo = $recusados ? 'erro' : 'ok';
        header("Location: eng_clin_os.php?protocolo=".urlencode($proto)."&m=".urlencode($msg)."&t=".$msg_tipo."#externa");
        exit();
    }
}

/* POST: remover anexo */
if (($_POST['acao'] ?? '') === 'del_anexo') {
    $proto  = trim($_POST['protocolo'] ?? '');
    $id_anx = (int)($_POST['id_anexo'] ?? 0);
    $stD = $conn->prepare("DELETE FROM anexos_engclin WHERE id=? AND numero_chamado=?");
    if ($stD) { $stD->bind_param('is', $id_anx, $proto); $stD->execute(); $stD->close(); }
    registrar_evento_os($conn, $proto, $usuario, $nome_usuario, 'ANEXO_REMOVIDO', "Anexo #{$id_anx} removido.");
    header("Location: eng_clin_os.php?protocolo=".urlencode($proto)."#externa");
    exit();
}

/* ═══════════════════════════════════════════════════════════════════════════
   POST: LANÇAR MATERIAL
   Baixa FIFO no estoque, priorizando a unidade do chamado.
   Mesma lógica de eng_clin_ordemdeservico.php, trazida para a tela única.
   ═══════════════════════════════════════════════════════════════════════════ */
if (($_POST['acao'] ?? '') === 'add_material') {
    $proto    = trim($_POST['protocolo'] ?? '');
    $cod_peca = strtoupper(trim($_POST['codigo_item'] ?? ''));
    $qty_usar = max(1, intval($_POST['quantidade_usar'] ?? 1));
    // REAPROVEITADO: peça vinda de equipamento baixado. Não existe no estoque,
    // não tem código e não gera baixa — o técnico apenas declara o uso.
    $origem   = ($_POST['origem_material'] ?? 'ESTOQUE') === 'REAPROVEITADO' ? 'REAPROVEITADO' : 'ESTOQUE';
    $nome_reap= trim($_POST['nome_reaproveitado'] ?? '');

    if ($proto === '' || ($origem === 'ESTOQUE' && $cod_peca === '') || ($origem === 'REAPROVEITADO' && $nome_reap === '')) {
        $msg = $origem === 'REAPROVEITADO' ? 'Informe o nome da peça reaproveitada.' : 'Selecione a peça e a quantidade.';
        $msg_tipo = 'erro';
    } else {
        // OS precisa estar aberta
        $stChk = $conn->prepare("SELECT status FROM ordemservico_engclin WHERE numero_chamado=? LIMIT 1");
        $stOS = null;
        if ($stChk) {
            $stChk->bind_param('s', $proto); $stChk->execute();
            $rk = $stChk->get_result();
            $stOS = $rk ? $rk->fetch_assoc() : null;
            $stChk->close();
        }

        if (($stOS['status'] ?? '') !== 'ABERTA') {
            $msg = 'Não é possível lançar material numa OS encerrada.'; $msg_tipo = 'erro';
        } else {
            // ── Peça reaproveitada: registro manual, sem estoque ──────────
            if ($origem === 'REAPROVEITADO') {
                $id_mo = null;
                $mo_post = (int)($_POST['mo_id'] ?? 0);
                if ($mo_post > 0) {
                    $stMo = $conn->prepare("SELECT id FROM maodeobra_engclin WHERE id=? AND numero_chamado=? LIMIT 1");
                    if ($stMo) {
                        $stMo->bind_param('is', $mo_post, $proto); $stMo->execute();
                        $rmo = $stMo->get_result(); $rowMo = $rmo ? $rmo->fetch_assoc() : null;
                        if ($rowMo) $id_mo = (int)$rowMo['id'];
                        $stMo->close();
                    }
                }
                if ($id_mo === null) {
                    $msg = 'Intervenção inválida para lançar material.'; $msg_tipo = 'erro';
                    header("Location: eng_clin_os.php?protocolo=".urlencode($proto)."&m=".urlencode($msg)."&t=erro#maoobra");
                    exit();
                }

                $cod_reap = 'REAPROV';
                $nulo_est = null; $nulo_val = null;
                $stR = $conn->prepare("INSERT INTO itens_os_engclin (numero_os,numero_chamado,codigo_item,origem,id_estoque,nome_item,quantidade_usada,valor_unitario,usuario,id_maodeobra) VALUES (?,?,?,'REAPROVEITADO',?,?,?,?,?,?)");
                if ($stR) {
                    $stR->bind_param('sssisidsi', $proto, $proto, $cod_reap, $nulo_est,
                                     $nome_reap, $qty_usar, $nulo_val, $usuario, $id_mo);
                    $stR->execute(); $stR->close();
                }
                registrar_evento_os($conn, $proto, $usuario, $nome_usuario, 'USO_MATERIAL',
                    "Peça REAPROVEITADA — {$nome_reap} ({$qty_usar} un.) utilizada. Sem baixa de estoque.");

                $conn->query("UPDATE ordemservico_engclin SET etapas_salvas =
                    CASE WHEN etapas_salvas IS NULL OR etapas_salvas = '' THEN 'materiais'
                         WHEN FIND_IN_SET('materiais', etapas_salvas) > 0 THEN etapas_salvas
                         ELSE CONCAT(etapas_salvas, ',materiais') END
                    WHERE numero_chamado = '".mysqli_real_escape_string($conn,$proto)."'");

                $msg = "Peça reaproveitada registrada: {$nome_reap}."; $msg_tipo = 'ok';
                header("Location: eng_clin_os.php?protocolo=".urlencode($proto)."&m=".urlencode($msg)."&t=ok#maoobra");
                exit();
            }

            // Descrição da peça no catálogo
            $stP = $conn->prepare("SELECT descricao FROM engclin_cadastro_pecas WHERE codigo=? AND ativo=1 LIMIT 1");
            $rowP = null;
            if ($stP) {
                $stP->bind_param('s', $cod_peca); $stP->execute();
                $rp = $stP->get_result();
                $rowP = $rp ? $rp->fetch_assoc() : null;
                $stP->close();
            }

            if (!$rowP) {
                $msg = 'Peça não encontrada no catálogo.'; $msg_tipo = 'erro';
            } else {
                $nome_item = $rowP['descricao'];

                // Unidade do chamado, traduzida para a nomenclatura do estoque
                $uni_chamado = '';
                $stCO = $conn->prepare("SELECT unidade_ocorrencia FROM chamado_engclin WHERE numero_chamado=? LIMIT 1");
                if ($stCO) {
                    $stCO->bind_param('s', $proto); $stCO->execute();
                    $rco = $stCO->get_result();
                    $rowCO = $rco ? $rco->fetch_assoc() : null;
                    $uni_chamado = ec_unidade_estoque($rowCO['unidade_ocorrencia'] ?? '');
                    $stCO->close();
                }

                // Saldo DA UNIDADE do chamado — não do total geral
                $saldo_atual = 0.0;
                $stS = $conn->prepare("SELECT SUM(quantidade) AS total FROM estoque_engenharia WHERE codigo_peca=? AND unidade=? AND ativo=1");
                if ($stS) {
                    $stS->bind_param('ss', $cod_peca, $uni_chamado); $stS->execute();
                    $rs = $stS->get_result();
                    $saldo_atual = (float)(($rs ? $rs->fetch_assoc()['total'] : 0) ?? 0);
                    $stS->close();
                }

                // Baixa FIFO RESTRITA à unidade do chamado.
                // Atender um chamado da Casa de Portugal não pode consumir
                // estoque do Evangélico.
                $restante = $qty_usar; $id_estoque_reg = null; $valor_unit_reg = null;
                $entradas = [];
                $stE = $conn->prepare("SELECT id, quantidade, valor FROM estoque_engenharia WHERE codigo_peca=? AND unidade=? AND ativo=1 AND quantidade>0 ORDER BY data_cadastro ASC, id ASC");
                if ($stE) {
                    $stE->bind_param('ss', $cod_peca, $uni_chamado);
                    $stE->execute();
                    $re = $stE->get_result();
                    if ($re) $entradas = $re->fetch_all(MYSQLI_ASSOC);
                    $stE->close();
                }

                foreach ($entradas as $ent) {
                    if ($restante <= 0) break;
                    if ($id_estoque_reg === null) {
                        $id_estoque_reg = (int)$ent['id'];
                        $valor_unit_reg = $ent['valor'] ? (float)$ent['valor'] : null;
                    }
                    $debitar  = min($restante, (float)$ent['quantidade']);
                    $nova_qty = round((float)$ent['quantidade'] - $debitar, 3);
                    $id_ent   = (int)$ent['id'];
                    $conn->query("UPDATE estoque_engenharia SET quantidade={$nova_qty} WHERE id={$id_ent}");
                    $restante -= $debitar;
                }

                // A intervenção vem do formulário — cada técnico lança na sua.
                // Confere que ela pertence a este protocolo antes de vincular.
                $id_mo = null;
                $mo_post = (int)($_POST['mo_id'] ?? 0);
                if ($mo_post > 0) {
                    $stMo = $conn->prepare("SELECT id FROM maodeobra_engclin WHERE id=? AND numero_chamado=? LIMIT 1");
                    if ($stMo) {
                        $stMo->bind_param('is', $mo_post, $proto); $stMo->execute();
                        $rmo = $stMo->get_result(); $rowMo = $rmo ? $rmo->fetch_assoc() : null;
                        if ($rowMo) $id_mo = (int)$rowMo['id'];
                        $stMo->close();
                    }
                }
                if ($id_mo === null) {
                    $msg = 'Intervenção inválida para lançar material.'; $msg_tipo = 'erro';
                    header("Location: eng_clin_os.php?protocolo=".urlencode($proto)."&m=".urlencode($msg)."&t=erro#maoobra");
                    exit();
                }

                // Registrar o uso na OS
                $stR = $conn->prepare("INSERT INTO itens_os_engclin (numero_os,numero_chamado,codigo_item,origem,id_estoque,nome_item,quantidade_usada,valor_unitario,usuario,id_maodeobra) VALUES (?,?,?,'ESTOQUE',?,?,?,?,?,?)");
                if ($stR) {
                    $stR->bind_param('sssisidsi',
                        $proto, $proto, $cod_peca, $id_estoque_reg,
                        $nome_item, $qty_usar, $valor_unit_reg, $usuario, $id_mo
                    );
                    $stR->execute(); $stR->close();
                }

                // Movimentação de estoque
                $obs_mov = "Utilizado na OS {$proto}";
                $stMv = $conn->prepare("INSERT INTO movimentacao_estoque_engclin (codigo_item,nome_item,tipo,quantidade,numero_chamado,numero_os,usuario,observacao) VALUES (?,?,'SAIDA_OS',?,?,?,?,?)");
                if ($stMv) {
                    $stMv->bind_param('ssissss', $cod_peca, $nome_item, $qty_usar, $proto, $proto, $usuario, $obs_mov);
                    $stMv->execute(); $stMv->close();
                }

                registrar_evento_os($conn, $proto, $usuario, $nome_usuario, 'USO_MATERIAL',
                    "Peça {$cod_peca} — {$nome_item} ({$qty_usar} un.) utilizada na OS.");

                // Marca a etapa de materiais como preenchida
                $conn->query("UPDATE ordemservico_engclin SET etapas_salvas =
                    CASE WHEN etapas_salvas IS NULL OR etapas_salvas = '' THEN 'materiais'
                         WHEN FIND_IN_SET('materiais', etapas_salvas) > 0 THEN etapas_salvas
                         ELSE CONCAT(etapas_salvas, ',materiais') END
                    WHERE numero_chamado = '".mysqli_real_escape_string($conn,$proto)."'");

                $novo_saldo = max(0.0, $saldo_atual - $qty_usar);
                $uni_lbl = $uni_chamado ?: 'unidade não identificada';
                if ($saldo_atual < $qty_usar) {
                    $msg = "Material registrado, mas o saldo em {$uni_lbl} era insuficiente ({$saldo_atual} un.).";
                    $msg_tipo = 'erro';
                } else {
                    $msg = "Material lançado. Saldo restante em {$uni_lbl}: {$novo_saldo} un.";
                    $msg_tipo = 'ok';
                }
                // Âncora para a página voltar na seção de materiais, não no topo
                header("Location: eng_clin_os.php?protocolo=".urlencode($proto)."&m=".urlencode($msg)."&t=".$msg_tipo."#maoobra");
                exit();
            }
        }
    }
}
if (isset($_GET['m'])) { $msg = $_GET['m']; $msg_tipo = ($_GET['t'] ?? '') === 'ok' ? 'ok' : 'erro'; }

/* ═══════════════════════════════════════════════════════════════════════════
   POST: ENCERRAR OS
   Fecha o ciclo: valida, devolve (ou não) o item, grava histórico e conclui.
   ═══════════════════════════════════════════════════════════════════════════ */

if (($_POST['acao'] ?? '') === 'encerrar_os') {
    $proto    = trim($_POST['protocolo'] ?? '');
    $devolver = ($_POST['devolver'] ?? '') === 'SIM' ? 'SIM' : 'NAO';

    $stE = $conn->prepare("SELECT * FROM ordemservico_engclin WHERE numero_chamado=? LIMIT 1");
    $osE = null;
    if ($stE) {
        $stE->bind_param('s', $proto); $stE->execute();
        $rE = $stE->get_result();
        $osE = $rE ? $rE->fetch_assoc() : null;
        $stE->close();
    }

    if (!$osE) {
        $msg = 'OS não encontrada.'; $msg_tipo = 'erro';
    } elseif ($osE['status'] !== 'ABERTA') {
        $msg = 'Esta OS já foi encerrada.'; $msg_tipo = 'erro';
    } else {
        // Exige ao menos uma intervenção com serviço descrito
        $com_servico = 0;
        $stCs = $conn->prepare("SELECT COUNT(*) AS c FROM maodeobra_engclin WHERE numero_chamado=? AND servico IS NOT NULL AND TRIM(servico) <> ''");
        if ($stCs) {
            $stCs->bind_param('s', $proto); $stCs->execute();
            $rcs = $stCs->get_result(); $com_servico = (int)(($rcs ? $rcs->fetch_assoc()['c'] : 0) ?? 0);
            $stCs->close();
        }
        // Nenhum processo pode estar pendente
        $situacao = ec_recalcular_os($conn, $proto);

        $falta = [];
        if ($com_servico === 0) $falta[] = 'ao menos uma intervenção com Serviço executado';
        if ($situacao['pendente']) {
            $falta[] = 'resolver os processos pendentes (situação atual: '
                     . ($ST_PROCESSO[$situacao['motivo']] ?? $situacao['motivo']) . ')';
        }
        if ($falta) {
            $msg = 'Antes de encerrar: ' . implode(' e ', $falta) . '.';
            $msg_tipo = 'erro';
        }
    }

    if ($osE && $osE['status'] === 'ABERTA' && $msg_tipo !== 'erro') {
        $d_fim = date('Y-m-d'); $h_fim = date('H:i:s');
        $item_id_os = $osE['item_id'] ? (int)$osE['item_id'] : null;

        // ── DEVOLUÇÃO ────────────────────────────────────────────────────────
        // Usa a localização congelada no início da OS, NUNCA os valores atuais
        // de `cadastro` — aqueles já apontam para a Engenharia Clínica.
        if ($devolver === 'SIM' && $item_id_os) {
            // Mesmo helper usado na saída para manutenção: atualiza cadastro,
            // grava as 15 colunas em `historico` e notifica o PatAsset.
            require_once __DIR__ . '/eng_clin_mover_item.php';
            $destino = eng_clin_destino_retorno($osE);
            $destino['obs']     = "OS {$proto} encerrada — devolvido ao local de origem";
            $destino['usuario'] = $usuario;
            $destino['data']    = $d_fim;

            $mv = eng_clin_mover_item($conn, $item_id_os, $destino);

            if ($mv['ok']) {
                registrar_evento_os($conn, $proto, $usuario, $nome_usuario, 'MOV_DEVOLUCAO',
                    "Equipamento #{$item_id_os} ({$mv['tag']}) devolvido de {$mv['de']} para {$mv['para']}. " .
                    "Motivo: DEVOLUÇÃO AO LOCAL DE ORIGEM.");
            } else {
                registrar_evento_os($conn, $proto, $usuario, $nome_usuario, 'MOV_DEVOLUCAO_ERRO',
                    "Falha na devolução automática: ".$mv['erro']);
                $devolver = 'NAO';   // não registra como devolvido se não moveu
            }
        } elseif ($devolver === 'NAO') {
            registrar_evento_os($conn, $proto, $usuario, $nome_usuario, 'SEM_DEVOLUCAO',
                "OS encerrada sem devolução automática. O equipamento permanece na localização atual.");
        }

        // OS que passou por manutenção externa não tem devolução automática:
        // o técnico decide o destino na tela de movimentação manual.
        $foi_externa = ($osE['manutencao_externa'] ?? 'NAO') === 'SIM';

        // Preventiva: agenda a próxima a partir do encerramento
        $stPv = $conn->prepare("SELECT periodicidade_meses FROM ordemservico_engclin WHERE numero_chamado=? LIMIT 1");
        if ($stPv) {
            $stPv->bind_param('s', $proto); $stPv->execute();
            $rpv = $stPv->get_result(); $rowPv = $rpv ? $rpv->fetch_assoc() : null;
            $stPv->close();
            $meses = (int)($rowPv['periodicidade_meses'] ?? 0);
            if ($meses > 0) {
                $prox = date('Y-m-d', strtotime("+{$meses} months", strtotime($d_fim)));
                $stPx = $conn->prepare("UPDATE ordemservico_engclin SET proxima_preventiva=? WHERE numero_chamado=?");
                if ($stPx) { $stPx->bind_param('ss', $prox, $proto); $stPx->execute(); $stPx->close(); }

                // A agenda real vive em preventiva_engclin — uma linha por
                // equipamento. O campo acima fica só como registro da OS.
                $item_pv = (int)($osE['item_id'] ?? 0);
                if ($item_pv > 0) {
                    // Peças trocadas nesta OS viram o "última revisão" do item
                    $trocas = '';
                    $stTr = $conn->prepare("SELECT GROUP_CONCAT(DISTINCT nome_item ORDER BY id SEPARATOR ', ')
                                            FROM itens_os_engclin WHERE numero_chamado=?");
                    if ($stTr) {
                        $stTr->bind_param('s', $proto); $stTr->execute();
                        $rtr = $stTr->get_result();
                        if ($rtr && ($xtr = $rtr->fetch_row())) $trocas = (string)($xtr[0] ?? '');
                        $stTr->close();
                    }

                    $stUp = $conn->prepare("INSERT INTO preventiva_engclin
                        (item_id, periodicidade_meses, proxima_data, origem, numero_chamado, ultima_troca, ativo, usuario)
                        VALUES (?,?,?,'OS',?,?,1,?)
                        ON DUPLICATE KEY UPDATE
                            periodicidade_meses = VALUES(periodicidade_meses),
                            proxima_data        = VALUES(proxima_data),
                            origem              = 'OS',
                            numero_chamado      = VALUES(numero_chamado),
                            ultima_troca        = VALUES(ultima_troca),
                            ativo               = 1");
                    if ($stUp) {
                        $stUp->bind_param('iissss', $item_pv, $meses, $prox, $proto, $trocas, $usuario);
                        $stUp->execute(); $stUp->close();
                    }

                    // Histórico da agenda de preventivas
                    $idPv = 0;
                    $stId = $conn->prepare("SELECT id FROM preventiva_engclin WHERE item_id=? LIMIT 1");
                    if ($stId) {
                        $stId->bind_param('i', $item_pv); $stId->execute();
                        $rid = $stId->get_result();
                        if ($rid && ($xid = $rid->fetch_assoc())) $idPv = (int)$xid['id'];
                        $stId->close();
                    }
                    $stHp = $conn->prepare("INSERT INTO preventiva_hist_engclin
                        (id_preventiva,item_id,acao,tecnico_usuario,nome_tecnico,data_exec,hora_exec,
                         servico_troca,periodicidade_meses,data_anterior,proxima_data,usuario)
                        VALUES (?,?,'AGENDADA',?,?,?,?,?,?,NULL,?,?)");
                    if ($stHp) {
                        $obs_hp = "Agendada no encerramento da OS {$proto}."
                                . ($trocas !== '' ? " Trocado: {$trocas}." : '');
                        $stHp->bind_param('iisssssiss', $idPv, $item_pv, $usuario, $nome_usuario,
                                          $d_fim, $h_fim, $obs_hp, $meses, $prox, $usuario);
                        $stHp->execute(); $stHp->close();
                    }
                }

                registrar_evento_os($conn, $proto, $usuario, $nome_usuario, 'PREVENTIVA_AGENDADA',
                    "Próxima manutenção preventiva agendada para " . date('d/m/Y', strtotime($prox)) . " ({$meses} meses).");
            }
        }

        // Fecha intervenções que ficaram sem data de término
        $stFim = $conn->prepare("UPDATE maodeobra_engclin SET data_fim=?, hora_fim=? WHERE numero_chamado=? AND data_fim IS NULL");
        if ($stFim) { $stFim->bind_param('sss', $d_fim, $h_fim, $proto); $stFim->execute(); $stFim->close(); }

        // ── CONCLUIR A OS ────────────────────────────────────────────────────
        // status = ciclo de vida | motivo = observação. Nunca misturados.
        $stFim = $conn->prepare("UPDATE ordemservico_engclin SET status='CONCLUIDO', data_fechamento=?, hora_fechamento=?, item_devolvido=? WHERE numero_chamado=?");
        if ($stFim) {
            $stFim->bind_param('ssss', $d_fim, $h_fim, $devolver, $proto);
            $stFim->execute(); $stFim->close();
        }
        $conn->query("UPDATE chamado_engclin SET status='CONCLUIDO' WHERE numero_chamado='".mysqli_real_escape_string($conn,$proto)."'");

        // Snapshot do documento: os dados podem mudar depois, mas o que foi
        // assinado precisa continuar valendo. Gerado via output buffering
        // reaproveitando a própria página do documento.
        try {
            $_GET['protocolo'] = $proto;
            if (!defined('EC_DOC_EMBED')) define('EC_DOC_EMBED', true);
            ob_start();
            include __DIR__ . '/eng_clin_os_documento.php';
            $html_doc = ob_get_clean();
            if ($html_doc && strlen($html_doc) < 6000000) {
                $agora_doc = date('Y-m-d H:i:s');
                $stDoc = $conn->prepare("UPDATE ordemservico_engclin SET documento_html=?, documento_em=? WHERE numero_chamado=?");
                if ($stDoc) { $stDoc->bind_param('sss', $html_doc, $agora_doc, $proto); $stDoc->execute(); $stDoc->close(); }
            }
        } catch (\Throwable $eDoc) {
            if (ob_get_level()) ob_end_clean();
            error_log('[LifeTech] falha ao arquivar documento da OS: '.$eDoc->getMessage());
        }

        registrar_evento_os($conn, $proto, $usuario, $nome_usuario, 'ENCERRAMENTO_OS',
            "OS encerrada por {$nome_usuario}. Situação: " . ($MOTIVOS[$osE['motivo']] ?? $osE['motivo']) .
            ". Item devolvido ao local de origem: {$devolver}.");

        // Manutenção externa: leva direto à movimentação manual, com o item
        // já identificado — o técnico só informa o destino.
        if ($foi_externa && $item_id_os) {
            header("Location: eng_clin_movimentar.php?id=" . (int)$item_id_os . "&os_encerrada=" . urlencode($proto));
            exit();
        }

        header("Location: eng_clin_os.php?protocolo=" . urlencode($proto) . "&ok=1");
        exit();
    }
}
if (isset($_GET['ok'])) { $msg = 'OS encerrada com sucesso.'; $msg_tipo = 'ok'; }

/* ═══════════════════════════════════════════════════════════════════════════
   CARREGAR A OS
   ═══════════════════════════════════════════════════════════════════════════ */
$protocolo = trim($_GET['protocolo'] ?? '');
$os = null; $ch = null; $erro_carga = '';

if ($protocolo === '') {
    $erro_carga = 'Nenhum protocolo informado.';
} else {
    $stO = $conn->prepare("SELECT * FROM ordemservico_engclin WHERE numero_chamado=? LIMIT 1");
    if ($stO) {
        $stO->bind_param('s', $protocolo); $stO->execute();
        $resO = $stO->get_result();
        $os = $resO ? $resO->fetch_assoc() : null;
        $stO->close();
    }

    if (!$os) {
        $erro_carga = "Nenhuma OS encontrada para o protocolo {$protocolo}.";
    } else {
        $stCh = $conn->prepare("SELECT * FROM chamado_engclin WHERE numero_chamado=? LIMIT 1");
        if ($stCh) {
            $stCh->bind_param('s', $protocolo); $stCh->execute();
            $resCh = $stCh->get_result();
            $ch = $resCh ? $resCh->fetch_assoc() : null;
            $stCh->close();
        }
    }
}

/* Técnicos para o seletor de responsável */
$tecnicos = [];
$resTec = $conn->query("SELECT usuario, nome, funcao FROM tecnico WHERE usuario IS NOT NULL AND usuario <> '' ORDER BY nome ASC");
if ($resTec) while ($t = $resTec->fetch_assoc()) $tecnicos[] = $t;

/* Materiais já lançados (leitura — o lançamento continua na tela de OS) */
$materiais = [];
if ($os) {
    $stM = $conn->prepare("SELECT codigo_item, nome_item, quantidade_usada, valor_unitario, usuario, id_maodeobra, origem FROM itens_os_engclin WHERE numero_chamado=? ORDER BY id ASC");
    if ($stM) {
        $stM->bind_param('s', $protocolo); $stM->execute();
        $resM = $stM->get_result();
        if ($resM) while ($m = $resM->fetch_assoc()) $materiais[] = $m;
        $stM->close();
    }
}

/* Catálogo de peças com o saldo DA UNIDADE do chamado — é dela que sai a baixa */
$catalogo_pecas = [];
$uni_estoque    = ec_unidade_estoque($ch['unidade_ocorrencia'] ?? '');
if ($os) {
    $stCat = $conn->prepare("
        SELECT p.codigo, p.descricao,
               COALESCE((SELECT SUM(e.quantidade) FROM estoque_engenharia e
                         WHERE e.codigo_peca = p.codigo AND e.unidade = ? AND e.ativo = 1), 0) AS saldo
        FROM engclin_cadastro_pecas p
        WHERE p.ativo = 1
        ORDER BY p.descricao ASC
    ");
    if ($stCat) {
        $stCat->bind_param('s', $uni_estoque);
        $stCat->execute();
        $rCat = $stCat->get_result();
        if ($rCat) while ($c = $rCat->fetch_assoc()) $catalogo_pecas[] = $c;
        $stCat->close();
    }
}

/* Intervenções (mão de obra) — uma por técnico que atuou */
$intervencoes = [];
if ($os) {
    $stMo = $conn->prepare("SELECT * FROM maodeobra_engclin WHERE numero_chamado=? ORDER BY id ASC");
    if ($stMo) {
        $stMo->bind_param('s', $protocolo); $stMo->execute();
        $rmo = $stMo->get_result();
        if ($rmo) while ($m = $rmo->fetch_assoc()) $intervencoes[] = $m;
        $stMo->close();
    }
}

/* Manutenção externa: registro, fornecedores e anexos */
$ext = null; $fornecedores = []; $anexos = [];
if ($os) {
    $stX = $conn->prepare("SELECT * FROM manutencao_externa_engclin WHERE numero_chamado=? ORDER BY id DESC LIMIT 1");
    if ($stX) {
        $stX->bind_param('s', $protocolo); $stX->execute();
        $rx = $stX->get_result(); $ext = $rx ? $rx->fetch_assoc() : null; $stX->close();
    }
    $rf = $conn->query("SELECT nome FROM fornecedores WHERE ativo=1 ORDER BY nome ASC");
    if ($rf) while ($f = $rf->fetch_assoc()) $fornecedores[] = $f['nome'];

    // conteudo fica de fora: é BLOB e só é lido no download
    $stAn = $conn->prepare("SELECT id, tipo, nome_arquivo, mime, tamanho, criado_em FROM anexos_engclin WHERE numero_chamado=? ORDER BY id ASC");
    if ($stAn) {
        $stAn->bind_param('s', $protocolo); $stAn->execute();
        $ra = $stAn->get_result();
        if ($ra) while ($a = $ra->fetch_assoc()) $anexos[] = $a;
        $stAn->close();
    }
}

/* Histórico do protocolo */
$eventos = [];
if ($os) {
    $stH = $conn->prepare("SELECT tipo_evento, descricao_evento, nome_usuario, data_evento, hora_evento FROM historico_eventos_engclin WHERE numero_chamado=? ORDER BY id DESC");
    if ($stH) {
        $stH->bind_param('s', $protocolo); $stH->execute();
        $resH = $stH->get_result();
        if ($resH) while ($e = $resH->fetch_assoc()) $eventos[] = $e;
        $stH->close();
    }
}

$conn->close();

// Chamado pode não ser encontrado (OS órfã) — evita acesso a índice de null
if (!is_array($ch)) $ch = [];

$etapas_ok = array_filter(array_map('trim', explode(',', (string)($os['etapas_salvas'] ?? ''))));
$data = date('d/m/Y'); $hora = date('H:i:s');
$encerrada = $os && $os['status'] !== 'ABERTA';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $protocolo ? htmlspecialchars($protocolo) : 'Atendimento' ?> — Engenharia Clínica</title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg-page:#0f0f0f; --bg-sidebar:#141414; --bg-card:#1a1a1a;
  --bg-input:#222; --border:rgba(255,255,255,0.07);
  --border-hover:rgba(255,255,255,0.14); --accent-steel:#a0aec0;
  --text-primary:#f0f0f0; --text-secondary:#888; --text-muted:#555;
  --sidebar-w:260px; --sidebar-collapsed:68px; --header-h:56px;
  --radius:10px; --radius-lg:16px;
  --transition:0.22s cubic-bezier(0.4,0,0.2,1);
  --font-ui:'DM Sans',sans-serif; --font-display:'Syne',sans-serif;
  --status-ok:#4ade80; --status-warn:#facc15; --status-err:#f87171;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-ui);background:var(--bg-page);color:var(--text-primary);min-height:100vh}

/* ── SIDEBAR ── */
.menu-toggle{display:none;position:fixed;top:10px;left:10px;z-index:1200;background:#2a2a2a;color:#e2e8f0;border:1px solid var(--border-hover);border-radius:8px;padding:8px 12px;font-size:20px;cursor:pointer;line-height:1}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000}
.sidebar-overlay.open{display:block}
#sidebar{position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;background:var(--bg-sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;transition:width var(--transition);overflow:visible}
#sidebar.collapsed{width:var(--sidebar-collapsed)}
.sidebar-brand{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px 12px 16px;border-bottom:1px solid var(--border);flex-shrink:0;gap:10px}
.brand-logo-main{width:56%;max-width:140px;height:auto;object-fit:contain;display:block;transition:opacity var(--transition),width var(--transition)}
#sidebar.collapsed .brand-logo-main{width:31px;max-width:31px}
.sidebar-toggle{position:absolute;top:14px;right:-14px;width:28px;height:28px;background:var(--bg-card);border:1px solid var(--border-hover);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:200;color:var(--text-secondary);font-size:11px}
.sidebar-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:8px 10px;scrollbar-width:thin}
.nav-item{display:block;width:100%;padding:11px 14px;margin:3px 0;border-radius:6px;cursor:pointer;text-decoration:none;color:#bfc0c2;font-size:14px;transition:background var(--transition),transform var(--transition);white-space:nowrap;overflow:hidden;position:relative;background:#1e2025;text-align:left}
.nav-item:hover{background:#26282d;color:#e8e9eb;transform:translateX(4px)}
.nav-item.active{background:#2a2c31;color:#fff;font-weight:500}
#sidebar.collapsed .nav-label{opacity:0}
.nav-item-sair{color:#f87171!important}
#sidebar.collapsed .nav-item{padding:10px 0;text-align:center;font-size:0;color:transparent;overflow:hidden;transform:none!important}
#sidebar.collapsed .nav-item::before{font-family:'Font Awesome 6 Free';font-weight:900;font-size:15px;color:#888;display:block;line-height:1}
#sidebar.collapsed .nav-item::after{content:attr(data-tooltip);position:absolute;left:calc(var(--sidebar-collapsed) + 8px);top:50%;transform:translateY(-50%);background:#2d2d2d;color:#e2e8f0;font-size:12px;padding:5px 10px;border-radius:6px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:200;border:1px solid var(--border-hover)}
#sidebar.collapsed .nav-item:hover::after{opacity:1}

/* ── MAIN ── */
#main{margin-left:var(--sidebar-w);transition:margin-left var(--transition);min-height:100vh;display:flex;flex-direction:column}
#main.sidebar-collapsed{margin-left:var(--sidebar-collapsed)}
.topbar{height:var(--header-h);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;padding:0 24px;background:var(--bg-page);position:sticky;top:0;z-index:50}
.topbar-breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary)}
.topbar-breadcrumb span:last-child{color:var(--text-primary);font-weight:500}
.topbar-breadcrumb i{font-size:10px;color:var(--text-muted)}
.topbar-spacer{flex:1}
.topbar-logo-rede{height:32px;width:auto;object-fit:contain;opacity:.75;flex-shrink:0}
.content{flex:1;padding:24px 28px;max-width:1100px;width:100%}
.page-header{margin-bottom:20px}
.page-title{font-family:var(--font-display);font-size:22px;font-weight:700;letter-spacing:-.01em}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}

/* ── PROTOCOLO ── */
.proto-bar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:18px}
.proto-num{font-family:var(--font-display);font-size:22px;font-weight:700;color:#4ade80;letter-spacing:.02em}
.proto-sep{width:1px;height:34px;background:var(--border)}
.proto-item{min-width:0}
.proto-label{font-size:9.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);margin-bottom:2px}
.proto-val{font-size:13px;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;white-space:nowrap}
.badge-aberta{background:rgba(250,204,21,.12);color:#facc15;border:1px solid rgba(250,204,21,.3)}
.badge-fechada{background:rgba(74,222,128,.12);color:#4ade80;border:1px solid rgba(74,222,128,.3)}

/* ── SEÇÕES ── */
.sec{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);margin-bottom:16px;overflow:hidden}
.sec-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:11px;background:rgba(255,255,255,.02)}
.sec-icon{width:28px;height:28px;border-radius:7px;background:rgba(160,174,192,.1);display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--accent-steel);flex-shrink:0}
.sec-title{font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--accent-steel);flex:1}
.sec-body{padding:18px 20px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
.fg{display:flex;flex-direction:column;gap:6px}
.fg.full{grid-column:1/-1}
.flabel{font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted)}
.fi{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-family:var(--font-ui);font-size:13px;color:var(--text-primary);outline:none;transition:border-color var(--transition);width:100%}
.fi:focus{border-color:rgba(160,174,192,.45)}
.fi:disabled{opacity:.5;cursor:not-allowed}
textarea.fi{resize:vertical;min-height:92px;line-height:1.6}
select.fi{cursor:pointer}

/* ── INDICADOR DE SALVAMENTO ── */
.sec-status{font-size:11px;font-weight:500;display:flex;align-items:center;gap:5px;white-space:nowrap;transition:opacity .3s}
.sec-status.ok{color:#4ade80}
.sec-status.saving{color:var(--text-muted)}
.sec-status.err{color:#f87171}
.sec-status.hidden{opacity:0}
.chip-ok{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;color:#4ade80;background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.25);padding:2px 8px;border-radius:20px}

/* ── LOCALIZAÇÃO ORIGINAL ── */
.loc-box{background:rgba(96,165,250,.06);border:1px solid rgba(96,165,250,.2);border-radius:10px;padding:13px 16px;display:flex;align-items:flex-start;gap:12px}
.loc-box i{color:#60a5fa;font-size:15px;flex-shrink:0;margin-top:1px}
.loc-txt{font-size:12.5px;color:var(--text-secondary);line-height:1.65}
.loc-txt strong{color:var(--text-primary)}

/* ── TABELA MATERIAIS ── */
.tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.tbl th{text-align:left;font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--accent-steel);padding:8px 10px;border-bottom:1px solid var(--border)}
.tbl td{padding:9px 10px;border-bottom:1px solid var(--border);color:var(--text-secondary)}
.tbl tr:last-child td{border-bottom:none}
.vazio{padding:20px;text-align:center;color:var(--text-muted);font-size:12px}
.vazio i{display:block;font-size:20px;margin-bottom:7px;opacity:.35}

/* ── HISTÓRICO ── */
.ev{display:flex;gap:12px;padding:11px 0;border-bottom:1px solid var(--border)}
.ev:last-child{border-bottom:none}
.ev-dot{width:8px;height:8px;border-radius:50%;background:var(--accent-steel);flex-shrink:0;margin-top:5px}
.ev-body{flex:1;min-width:0}
.ev-tipo{font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--accent-steel);text-transform:uppercase}
.ev-desc{font-size:12.5px;color:var(--text-primary);margin-top:2px;line-height:1.5}
.ev-meta{font-size:11px;color:var(--text-muted);margin-top:2px}

/* ── AVISOS ── */
.aviso{border-radius:10px;padding:14px 18px;font-size:13px;display:flex;align-items:flex-start;gap:11px;margin-bottom:16px;line-height:1.6}
.aviso i{font-size:15px;flex-shrink:0;margin-top:1px}
.aviso-erro{background:rgba(248,113,113,.09);border:1px solid rgba(248,113,113,.28);color:#fca5a5}
.aviso-info{background:rgba(160,174,192,.07);border:1px solid rgba(160,174,192,.2);color:var(--text-secondary)}
.aviso-ok{background:rgba(74,222,128,.09);border:1px solid rgba(74,222,128,.28);color:#86efac}

.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:8px;font-family:var(--font-ui);font-size:13px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none}
.btn-ghost{background:#232323;border:1px solid rgba(255,255,255,.12);color:var(--text-primary)}
.btn-ghost:hover{background:#2e2e2e}
.btn-encerrar{background:rgba(74,222,128,.14);border:1px solid rgba(74,222,128,.4);color:#4ade80;font-weight:600}
.btn-encerrar:hover{background:rgba(74,222,128,.22)}

/* ── Lançamento de material ── */
.mat-form{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
.mat-aviso{font-size:11.5px;margin-top:8px;display:none}
.mat-aviso.vis{display:block}
.mat-aviso.alerta{color:var(--status-warn)}
.mat-aviso.info{color:var(--text-muted)}

/* ── Anexos da manutenção externa ── */
.anx-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px}
.anx{display:flex;align-items:center;gap:6px;background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:8px 10px}
.anx-link{display:flex;align-items:center;gap:9px;flex:1;min-width:0;text-decoration:none;color:var(--text-secondary);font-size:12px}
.anx-link:hover{color:var(--text-primary)}
.anx-link > i{font-size:15px;color:var(--accent-steel);flex-shrink:0}
.anx-nome{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.anx-tam{font-size:10px;color:var(--text-muted);flex-shrink:0}
.anx-del{background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:12px;padding:2px 4px;border-radius:4px}
.anx-del:hover{color:#f87171;background:rgba(248,113,113,.1)}

/* ── Pergunta recolhida da manutenção externa ── */
.pergunta-ext{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:10px;padding:14px 18px}
.pergunta-txt{display:flex;align-items:center;gap:10px;font-size:13.5px;color:var(--text-primary);font-weight:500}
.pergunta-txt i{color:var(--accent-steel);font-size:15px}

/* ── Intervenções (mão de obra) ── */
.iv{border:1px solid var(--border);border-radius:10px;margin-bottom:12px;overflow:hidden;background:rgba(255,255,255,.015)}
.iv-aberta{border-color:rgba(250,204,21,.3)}
.iv-head{display:flex;align-items:center;gap:11px;padding:11px 15px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.02);flex-wrap:wrap}
.iv-quem{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-primary);flex:1;min-width:0}
.iv-quem i{color:var(--accent-steel);font-size:12px}
.iv-tag-eu{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;background:rgba(96,165,250,.15);color:#60a5fa;border:1px solid rgba(96,165,250,.3);padding:1px 7px;border-radius:20px}
.iv-badge{font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap}
.iv-badge-ab{background:rgba(250,204,21,.12);color:#facc15;border:1px solid rgba(250,204,21,.3)}
.iv-badge-fim{background:rgba(74,222,128,.12);color:#4ade80;border:1px solid rgba(74,222,128,.3)}
.iv-acoes{display:flex;gap:6px}
.iv-btn{background:#232323;border:1px solid rgba(255,255,255,.12);color:var(--text-secondary);border-radius:6px;padding:5px 10px;font-size:11px;font-family:var(--font-ui);cursor:pointer;display:inline-flex;align-items:center;gap:5px}
.iv-btn:hover{background:#2e2e2e;color:var(--text-primary)}
.iv-btn-del:hover{color:#f87171;border-color:rgba(248,113,113,.3)}
.iv-body{padding:15px}
.iv-mat-box{margin-top:14px;padding-top:12px;border-top:1px solid var(--border)}
.iv-mat-tit{display:flex;align-items:center;gap:7px;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:10px}
.iv-mat-tit i{color:var(--accent-steel);font-size:11px}
.iv-mat-cnt{background:rgba(255,255,255,.07);border-radius:20px;padding:1px 7px;font-size:9.5px}
.iv-add{margin-top:14px;padding-top:14px;border-top:1px solid var(--border)}
/* Pílula de situação do processo */
.st-pill{font-size:9px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:2px 8px;border-radius:20px;margin-left:8px}
.st-ok{background:rgba(74,222,128,.14);color:#4ade80;border:1px solid rgba(74,222,128,.3)}
.st-pend{background:rgba(250,204,21,.13);color:#facc15;border:1px solid rgba(250,204,21,.3)}
/* Escolha entre envio e visita técnica */
.tipo-ext{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.tipo-op{display:flex;flex-direction:column;gap:3px;padding:13px 15px;border:1px solid var(--border);border-radius:10px;background:var(--bg-input);cursor:pointer;transition:border-color var(--transition),background var(--transition)}
.tipo-op:hover{border-color:var(--border-hover)}
.tipo-op:has(input:checked){border-color:rgba(74,222,128,.45);background:rgba(74,222,128,.06)}
.tipo-op input{position:absolute;opacity:0;pointer-events:none}
.tipo-op span{font-size:13px;font-weight:500;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.tipo-op span i{color:var(--accent-steel);font-size:13px}
.tipo-op:has(input:checked) span i{color:#4ade80}
.tipo-op small{font-size:11px;color:var(--text-muted);padding-left:21px}
.sec-sep{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);margin:18px 0 12px;padding-top:14px;border-top:1px solid var(--border)}
/* Dados do chamado — leitura */
.ro-tag{font-size:9.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted);background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:20px;padding:3px 10px;display:inline-flex;align-items:center;gap:5px}
.ch-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px 20px}
.ch-f{min-width:0}
.ch-l{font-size:9.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);margin-bottom:3px}
.ch-v{font-size:13px;color:var(--text-primary);word-break:break-word}
.ch-txt{margin-top:16px;padding-top:14px;border-top:1px solid var(--border)}
.ch-corpo{font-size:13px;color:var(--text-secondary);line-height:1.65;margin-top:4px;background:rgba(255,255,255,.02);border-left:2px solid var(--border-hover);padding:10px 14px;border-radius:0 8px 8px 0}
/* Aviso de campo obrigatório */
.obrig{display:none;font-size:10.5px;font-weight:600;color:var(--status-warn);margin-bottom:3px}
.obrig.vis{display:block}
.fi.faltando{border-color:rgba(250,204,21,.55)}
/* Origem do material */
.orig-mat{display:flex;gap:8px;flex-wrap:wrap}
.orig-op{display:inline-flex;align-items:center;padding:6px 13px;border:1px solid var(--border);border-radius:20px;background:var(--bg-input);cursor:pointer;transition:all var(--transition)}
.orig-op:hover{border-color:var(--border-hover)}
.orig-op:has(input:checked){border-color:rgba(74,222,128,.45);background:rgba(74,222,128,.08)}
.orig-op input{position:absolute;opacity:0;pointer-events:none}
.orig-op span{font-size:11.5px;color:var(--text-secondary);display:flex;align-items:center;gap:6px}
.orig-op:has(input:checked) span{color:#4ade80;font-weight:600}
.tag-reap{font-size:10px;font-weight:600;color:#4ade80;background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.25);border-radius:20px;padding:2px 8px;white-space:nowrap}
.prox-box{display:flex;align-items:center;gap:9px;background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:10px 13px;font-size:13px;color:var(--text-primary);min-height:41px}
.prox-box i{color:var(--accent-steel);font-size:13px}
@media(max-width:640px){.tipo-ext{grid-template-columns:1fr}}

/* ── Modal da devolução ── */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:900;align-items:center;justify-content:center;padding:20px}
.modal-bg.aberto{display:flex}
.modal-box{background:var(--bg-card);border:1px solid var(--border-hover);border-radius:var(--radius-lg);max-width:540px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.6);overflow:hidden}
.modal-head{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:11px;font-family:var(--font-display);font-size:15px;font-weight:700}
.modal-body{padding:20px 22px}
.modal-acoes{padding:16px 22px;border-top:1px solid var(--border);display:flex;gap:9px;justify-content:flex-end;flex-wrap:wrap;background:rgba(255,255,255,.02)}

.footer{margin-left:var(--sidebar-w);padding:14px 28px;border-top:1px solid var(--border);display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);transition:margin-left var(--transition);flex-wrap:wrap;gap:8px}

@media(max-width:900px){.grid2,.grid3{grid-template-columns:1fr}.content{padding:16px}.topbar-logo-rede{display:none}.footer,#main{margin-left:var(--sidebar-collapsed)}#sidebar{width:var(--sidebar-collapsed)}}
@media(max-width:640px){#sidebar{position:fixed;transform:translateX(-100%);width:var(--sidebar-w)!important}#sidebar.open{transform:translateX(0)}.sidebar-toggle{display:none}.menu-toggle{display:block}#main{margin-left:0!important}.topbar{padding-left:54px}.footer{margin-left:0}}
</style>
<?php eng_clin_menu_css(); ?>
</head>
<body>

<?php eng_clin_menu_sidebar(); ?>

<div id="main">
  <header class="topbar">
    <div class="topbar-breadcrumb">
      <span>Engenharia Clínica</span>
      <i class="fas fa-chevron-right"></i>
      <span>Atendimento</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
    <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <div class="page-header" style="display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap">
      <div>
        <div class="page-title">Atendimento</div>
        <div class="page-subtitle">Engenharia Clínica &middot; <?= eng_clin_data_pt() ?></div>
      </div>
      <a href="eng_clin_ordemdeservico.php" class="btn btn-ghost">
        <i class="fas fa-arrow-left"></i> Voltar às Ordens de Serviço
      </a>
    </div>

    <?php if ($msg): ?>
    <div class="aviso <?= $msg_tipo==='ok' ? 'aviso-ok' : 'aviso-erro' ?>">
      <i class="fas <?= $msg_tipo==='ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
      <div><?= htmlspecialchars($msg) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($erro_carga): ?>
      <div class="aviso aviso-erro">
        <i class="fas fa-circle-exclamation"></i>
        <div>
          <?= htmlspecialchars($erro_carga) ?><br>
          <span style="font-size:12px;color:var(--text-muted)">
            Abra o atendimento a partir da lista de chamados.
          </span>
        </div>
      </div>
      <a href="eng_clin_ordemdeservico.php" class="btn btn-ghost">
        <i class="fas fa-arrow-left"></i> Ir para Ordens de Serviço
      </a>

    <?php else: ?>

      <!-- ══ PROTOCOLO ══════════════════════════════════════════════ -->
      <div class="proto-bar">
        <div>
          <div class="proto-label">Protocolo</div>
          <div class="proto-num"><?= htmlspecialchars($protocolo) ?></div>
        </div>
        <div class="proto-sep"></div>
        <div class="proto-item" style="flex:1">
          <div class="proto-label">Equipamento</div>
          <div class="proto-val"><?= htmlspecialchars($ch['descricao_item'] ?? '—') ?></div>
        </div>
        <div class="proto-item">
          <div class="proto-label">Tag</div>
          <div class="proto-val"><?= htmlspecialchars(($ch['tag_patrimonio'] ?? '') ?: '—') ?></div>
        </div>
        <div class="proto-item">
          <div class="proto-label">Criticidade</div>
          <div class="proto-val"><?= htmlspecialchars($ch['criticidade'] ?? '—') ?></div>
        </div>
        <span class="badge <?= $encerrada ? 'badge-fechada' : 'badge-aberta' ?>">
          <i class="fas <?= $encerrada ? 'fa-circle-check' : 'fa-circle-dot' ?>"></i>
          <?= $encerrada ? 'Encerrada' : 'Pendência' ?>
        </span>
      </div>

      <?php if ($encerrada): ?>
      <div class="aviso aviso-ok">
        <i class="fas fa-lock"></i>
        <div>Esta OS está encerrada. Os campos ficam somente para consulta.</div>
      </div>
      <?php else: ?>
      <div class="aviso aviso-info">
        <i class="fas fa-cloud-arrow-up"></i>
        <div>
          Cada campo é salvo automaticamente ao sair dele.
          Você pode fechar a página e voltar depois — nada se perde.
        </div>
      </div>
      <?php endif; ?>

      <!-- ══ SEÇÃO: DADOS DO CHAMADO (somente leitura) ═════════════ -->
      <div class="sec">
        <div class="sec-head">
          <div class="sec-icon"><i class="fas fa-file-lines"></i></div>
          <span class="sec-title">Dados do Chamado</span>
          <span class="ro-tag"><i class="fas fa-lock"></i> somente leitura</span>
        </div>
        <div class="sec-body">
          <div class="ch-grid">
            <div class="ch-f"><div class="ch-l">Aberto por</div><div class="ch-v"><?= htmlspecialchars($ch['nome'] ?: '—') ?></div></div>
            <div class="ch-f"><div class="ch-l">Cargo / função</div><div class="ch-v"><?= htmlspecialchars($ch['cargo'] ?: '—') ?></div></div>
            <div class="ch-f"><div class="ch-l">E-mail</div><div class="ch-v"><?= htmlspecialchars($ch['email'] ?: '—') ?></div></div>
            <div class="ch-f"><div class="ch-l">Abertura</div><div class="ch-v">
              <?= !empty($ch['data_chamado']) ? date('d/m/Y', strtotime($ch['data_chamado'])) : '—' ?>
              <?= substr((string)($ch['hora_chamado'] ?? ''), 0, 5) ?>
            </div></div>

            <div class="ch-f"><div class="ch-l">Unidade</div><div class="ch-v"><?= htmlspecialchars($ch['unidade_ocorrencia'] ?: '—') ?></div></div>
            <div class="ch-f"><div class="ch-l">Setor</div><div class="ch-v"><?= htmlspecialchars($ch['setor_ocorrencia'] ?: '—') ?></div></div>
            <div class="ch-f"><div class="ch-l">Área</div><div class="ch-v"><?= htmlspecialchars($ch['area_ocorrencia'] ?: '—') ?></div></div>
            <div class="ch-f"><div class="ch-l">Criticidade</div><div class="ch-v"><?= htmlspecialchars($ch['criticidade'] ?: '—') ?></div></div>

            <div class="ch-f"><div class="ch-l">Equipamento</div><div class="ch-v"><?= htmlspecialchars($ch['descricao_item'] ?: '—') ?></div></div>
            <div class="ch-f"><div class="ch-l">Tag / Série</div><div class="ch-v">
              <?= htmlspecialchars($ch['tag_patrimonio'] ?: '—') ?>
              <?= !empty($ch['numero_serie']) ? ' &middot; ' . htmlspecialchars($ch['numero_serie']) : '' ?>
            </div></div>
            <div class="ch-f"><div class="ch-l">Tipo de ocorrência</div><div class="ch-v"><?= htmlspecialchars($ch['tipo_ocorrencia'] ?: '—') ?></div></div>
            <div class="ch-f"><div class="ch-l">Parada do equipamento</div><div class="ch-v">
              <?= !empty($ch['data_parada']) ? date('d/m/Y', strtotime($ch['data_parada'])) : '—' ?>
              <?= substr((string)($ch['hora_parada'] ?? ''), 0, 5) ?>
            </div></div>
          </div>

          <?php if (!empty($ch['causa'])): ?>
          <div class="ch-txt">
            <div class="ch-l">Problema relatado</div>
            <div class="ch-corpo"><?= nl2br(htmlspecialchars($ch['causa'])) ?></div>
          </div>
          <?php endif; ?>
          <?php if (!empty($ch['observacao'])): ?>
          <div class="ch-txt">
            <div class="ch-l">Observação do solicitante</div>
            <div class="ch-corpo"><?= nl2br(htmlspecialchars($ch['observacao'])) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ══ SEÇÃO: MÃO DE OBRA — VÁRIAS INTERVENÇÕES ══════════════ -->
      <div class="sec" id="maoobra">
        <div class="sec-head">
          <div class="sec-icon"><i class="fas fa-user-gear"></i></div>
          <span class="sec-title">Mão de Obra</span>
          <?php if ($intervencoes): ?>
          <span class="chip-ok"><i class="fas fa-users"></i> <?= count($intervencoes) ?></span>
          <?php endif; ?>
          <span class="sec-status hidden" data-status="mao_obra"></span>
        </div>

        <div class="sec-body">
          <div style="font-size:11.5px;color:var(--text-muted);margin-bottom:14px;line-height:1.6">
            <i class="fas fa-circle-info"></i>
            Cada técnico que atuar registra a própria intervenção, com período,
            ocorrência e serviço. O material lançado fica vinculado a quem lançou.
          </div>

          <?php if (!$intervencoes): ?>
          <div class="vazio"><i class="fas fa-user-gear"></i>Nenhuma intervenção registrada.</div>
          <?php else: foreach ($intervencoes as $iv):
            $ab   = empty($iv['data_fim']);
            $meu  = ($iv['usuario'] === $usuario);
            $bloq = $encerrada;
          ?>
          <div class="iv <?= $ab ? 'iv-aberta' : '' ?>">
            <div class="iv-head">
              <div class="iv-quem">
                <i class="fas fa-user"></i>
                <strong><?= htmlspecialchars($iv['nome_tecnico']) ?></strong>
                <?php if ($meu): ?><span class="iv-tag-eu">você</span><?php endif; ?>
              </div>
              <span class="iv-badge <?= $ab ? 'iv-badge-ab' : 'iv-badge-fim' ?>">
                <?= $ab ? 'Em andamento' : 'Finalizada' ?>
              </span>
              <?php if (!$bloq): ?>
              <div class="iv-acoes">
                <?php if ($ab): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="acao" value="fim_intervencao">
                  <input type="hidden" name="protocolo" value="<?= htmlspecialchars($protocolo) ?>">
                  <input type="hidden" name="mo_id" value="<?= (int)$iv['id'] ?>">
                  <button type="submit" class="iv-btn" title="Finalizar esta intervenção">
                    <i class="fas fa-flag"></i> Finalizar
                  </button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Remover esta intervenção?')">
                  <input type="hidden" name="acao" value="del_intervencao">
                  <input type="hidden" name="protocolo" value="<?= htmlspecialchars($protocolo) ?>">
                  <input type="hidden" name="mo_id" value="<?= (int)$iv['id'] ?>">
                  <button type="submit" class="iv-btn iv-btn-del" title="Remover"><i class="fas fa-trash"></i></button>
                </form>
              </div>
              <?php endif; ?>
            </div>

            <div class="iv-body">
              <div class="grid2">
                <div class="fg">
                  <label class="flabel">Início</label>
                  <div style="display:flex;gap:8px">
                    <input type="date" class="fi" data-campo="data_inicio" data-mo="<?= (int)$iv['id'] ?>"
                           value="<?= htmlspecialchars($iv['data_inicio'] ?? '') ?>" <?= $bloq?'disabled':'' ?>>
                    <input type="time" class="fi" data-campo="hora_inicio" data-mo="<?= (int)$iv['id'] ?>" step="1"
                           value="<?= htmlspecialchars($iv['hora_inicio'] ?? '') ?>" <?= $bloq?'disabled':'' ?>>
                  </div>
                </div>
                <div class="fg">
                  <label class="flabel">Término</label>
                  <div style="display:flex;gap:8px">
                    <input type="date" class="fi" data-campo="data_fim" data-mo="<?= (int)$iv['id'] ?>"
                           value="<?= htmlspecialchars($iv['data_fim'] ?? '') ?>" <?= $bloq?'disabled':'' ?>>
                    <input type="time" class="fi" data-campo="hora_fim" data-mo="<?= (int)$iv['id'] ?>" step="1"
                           value="<?= htmlspecialchars($iv['hora_fim'] ?? '') ?>" <?= $bloq?'disabled':'' ?>>
                  </div>
                </div>
                <div class="fg full">
                  <label class="flabel">Ocorrência</label>
                  <select class="fi" data-campo="ocorrencia" data-mo="<?= (int)$iv['id'] ?>" <?= $bloq?'disabled':'' ?>>
                    <option value="">Selecione...</option>
                    <?php foreach ($OCORRENCIAS as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($iv['ocorrencia'] ?? '')===$k?'selected':'' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="fg full">
                  <label class="flabel">Serviço executado</label>
                  <textarea class="fi" data-campo="servico" data-mo="<?= (int)$iv['id'] ?>"
                            placeholder="O que este técnico realizou..." <?= $bloq?'disabled':'' ?>><?= htmlspecialchars($iv['servico'] ?? '') ?></textarea>
                </div>
                <div class="fg full">
                  <label class="flabel">
                    Status desta intervenção
                    <?php $st_iv = $iv['status'] ?? 'EM_ANDAMENTO'; ?>
                    <span class="st-pill <?= ec_conclusivo($st_iv) ? 'st-ok' : 'st-pend' ?>">
                      <?= ec_conclusivo($st_iv) ? 'concluído' : 'pendência' ?>
                    </span>
                  </label>
                  <select class="fi" data-campo="status" data-mo="<?= (int)$iv['id'] ?>" <?= $bloq?'disabled':'' ?>>
                    <?php foreach ($ST_PROCESSO as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $st_iv===$k?'selected':'' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div style="font-size:10.5px;color:var(--text-muted);margin-top:4px">
                    Só <strong>Problema solucionado</strong>, <strong>Obsoleto</strong> e
                    <strong>Sem solução</strong> encerram. Os demais mantêm a OS como pendência.
                  </div>
                </div>
              </div>

              <!-- Materiais desta intervenção -->
              <?php $mat_iv = array_filter($materiais, fn($m) => (int)($m['id_maodeobra'] ?? 0) === (int)$iv['id']); ?>
              <div class="iv-mat-box">
                <div class="iv-mat-tit">
                  <i class="fas fa-boxes-stacked"></i> Materiais utilizados nesta intervenção
                  <?php if ($mat_iv): ?><span class="iv-mat-cnt"><?= count($mat_iv) ?></span><?php endif; ?>
                </div>

                <?php if ($mat_iv): ?>
                <table class="tbl" style="margin-bottom:10px">
                  <thead><tr><th>Código</th><th>Item</th><th>Origem</th><th>Qtd</th><th>Valor unit.</th></tr></thead>
                  <tbody>
                    <?php foreach ($mat_iv as $mm): ?>
                    <tr>
                      <td style="font-family:monospace;color:var(--accent-steel)"><?= htmlspecialchars($mm['codigo_item']) ?></td>
                      <td style="color:var(--text-primary)"><?= htmlspecialchars($mm['nome_item']) ?></td>
                      <td><?php if (($mm['origem'] ?? 'ESTOQUE') === 'REAPROVEITADO'): ?>
                            <span class="tag-reap"><i class="fas fa-recycle"></i> Reaproveitada</span>
                          <?php else: ?>
                            <span style="font-size:11px;color:var(--text-muted)">Estoque</span>
                          <?php endif; ?></td>
                      <td><?= (float)$mm['quantidade_usada'] == floor((float)$mm['quantidade_usada']) ? (int)$mm['quantidade_usada'] : $mm['quantidade_usada'] ?> un.</td>
                      <td><?= $mm['valor_unitario'] !== null ? 'R$ '.number_format((float)$mm['valor_unitario'],2,',','.') : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
                <?php else: ?>
                <div style="font-size:11.5px;color:var(--text-muted);margin-bottom:10px">
                  Nenhum material lançado nesta intervenção.
                </div>
                <?php endif; ?>

                <?php if (!$bloq && $catalogo_pecas): ?>
                <form method="POST" class="mat-form" onsubmit="return validarMaterial(this)">
                  <input type="hidden" name="acao" value="add_material">
                  <input type="hidden" name="protocolo" value="<?= htmlspecialchars($protocolo) ?>">
                  <input type="hidden" name="mo_id" value="<?= (int)$iv['id'] ?>">
                  <div class="fg" style="width:100%">
                    <div class="orig-mat">
                      <label class="orig-op">
                        <input type="radio" name="origem_material" value="ESTOQUE" checked
                               onchange="alternarOrigem(this)">
                        <span><i class="fas fa-warehouse"></i> Do estoque</span>
                      </label>
                      <label class="orig-op">
                        <input type="radio" name="origem_material" value="REAPROVEITADO"
                               onchange="alternarOrigem(this)">
                        <span><i class="fas fa-recycle"></i> Reaproveitada</span>
                      </label>
                    </div>
                  </div>
                  <div class="fg campo-reap" style="flex:1;min-width:210px;display:none">
                    <input type="text" name="nome_reaproveitado" class="fi"
                           placeholder="Nome da peça reaproveitada...">
                  </div>
                  <div class="fg campo-estoque" style="flex:1;min-width:210px">
                    <select name="codigo_item" class="fi sel-peca">
                      <option value="">Selecione a peça...</option>
                      <?php foreach ($catalogo_pecas as $p): ?>
                      <option value="<?= htmlspecialchars($p['codigo']) ?>" data-saldo="<?= (float)$p['saldo'] ?>">
                        <?= htmlspecialchars($p['codigo']) ?> — <?= htmlspecialchars($p['descricao']) ?>
                        (<?= (float)$p['saldo'] == floor((float)$p['saldo']) ? (int)$p['saldo'] : $p['saldo'] ?> un.)
                      </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="fg" style="width:92px">
                    <input type="number" name="quantidade_usar" class="fi" value="1" min="1" step="1" required>
                  </div>
                  <button type="submit" class="iv-btn"><i class="fas fa-plus"></i> Lançar</button>
                </form>
                <div class="mat-aviso aviso-saldo"></div>
                <?php elseif (!$bloq): ?>
                <div style="font-size:11.5px;color:var(--text-muted)">
                  Nenhuma peça ativa no catálogo.
                  <a href="eng_clin_cadastro_pecas.php" style="color:var(--accent-steel)">Cadastrar peça</a>.
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; endif; ?>

          <?php if (!$encerrada): ?>
          <div class="iv-add">
            <?php if (!$tecnicos): ?>
              <div style="font-size:12.5px;color:var(--status-warn)">
                Nenhum técnico cadastrado.
                <a href="eng_clin_cadastrodetecnico.php" style="color:var(--accent-steel)">Cadastrar técnico</a>
              </div>
            <?php else: ?>
            <form method="POST" class="mat-form" onsubmit="return validarIntervencao(this)">
              <input type="hidden" name="acao" value="add_intervencao">
              <input type="hidden" name="protocolo" value="<?= htmlspecialchars($protocolo) ?>">
              <div class="fg" style="flex:1;min-width:250px">
                <label class="flabel">Técnico que vai executar</label>
                <select name="tecnico_usuario" class="fi" id="selTecNovo">
                  <option value="">Selecione o técnico...</option>
                  <?php foreach ($tecnicos as $t): ?>
                  <option value="<?= htmlspecialchars($t['usuario']) ?>" <?= $t['usuario']===$usuario?'selected':'' ?>>
                    <?= htmlspecialchars($t['nome']) ?><?= $t['funcao'] ? ' — '.htmlspecialchars($t['funcao']) : '' ?>
                    <?= $t['usuario']===$usuario ? ' (você)' : '' ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-ghost" style="align-self:flex-end">
                <i class="fas fa-user-plus"></i> Adicionar intervenção
              </button>
            </form>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>


      <!-- A seção "Status / Observação" da OS foi removida: o status agora
           pertence a cada processo (intervenção e manutenção externa). O motivo
           da OS é derivado deles em ec_recalcular_os(). -->

      <!-- Materiais agora vivem dentro de cada intervenção, na seção Mão de
           Obra: cada técnico lança os seus e o registro fica separado. -->

      <!-- ══ SEÇÃO: PERIODICIDADE (só em preventiva) ═══════════════ -->
      <?php
        // Aparece quando qualquer intervenção estiver marcada como preventiva
        $tem_preventiva = false;
        foreach ($intervencoes as $ivx) {
            if (($ivx['ocorrencia'] ?? '') === 'MANUTENCAO_PREVENTIVA') { $tem_preventiva = true; break; }
        }
      ?>
      <div class="sec" id="secPreventiva" style="<?= $tem_preventiva ? '' : 'display:none' ?>">
        <div class="sec-head">
          <div class="sec-icon"><i class="fas fa-calendar-check"></i></div>
          <span class="sec-title">Manutenção Preventiva</span>
          <span class="sec-status hidden" data-status="preventiva"></span>
        </div>
        <div class="sec-body">
          <div class="grid2">
            <div class="fg">
              <label class="flabel">Periodicidade (meses)</label>
              <input type="number" class="fi" data-campo="periodicidade_meses" min="1" max="120" step="1"
                     placeholder="Ex: 6" value="<?= htmlspecialchars($os['periodicidade_meses'] ?? '') ?>"
                     <?= $encerrada?'disabled':'' ?>>
              <div style="font-size:11px;color:var(--text-muted);margin-top:5px;line-height:1.6">
                A contagem começa no encerramento da OS. Informando 6, o sistema
                avisa da próxima preventiva 6 meses depois.
              </div>
            </div>
            <div class="fg">
              <label class="flabel">Próxima preventiva</label>
              <div class="prox-box">
                <?php if (!empty($os['proxima_preventiva'])): ?>
                  <i class="fas fa-calendar-day" style="color:#4ade80"></i>
                  <strong><?= date('d/m/Y', strtotime($os['proxima_preventiva'])) ?></strong>
                <?php else: ?>
                  <i class="fas fa-hourglass-half"></i>
                  <span style="color:var(--text-muted)">Calculada ao encerrar a OS</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══ SEÇÃO: MANUTENÇÃO EXTERNA ══════════════════════════════ -->
      <div class="sec" id="externa">
        <div class="sec-head">
          <div class="sec-icon"><i class="fas fa-truck"></i></div>
          <span class="sec-title">Manutenção Externa</span>
          <?php if (($os['manutencao_externa'] ?? 'NAO') === 'SIM'): ?>
          <span class="chip-ok"><i class="fas fa-check"></i> Enviado</span>
          <?php endif; ?>
        </div>
        <div class="sec-body">
          <?php if ($encerrada && ($os['manutencao_externa'] ?? 'NAO') !== 'SIM'): ?>
            <div style="font-size:12.5px;color:var(--text-muted)">Esta OS não passou por manutenção externa.</div>
          <?php else: ?>

          <?php if (!$encerrada): ?>
          <?php $ja_externa = ($os['manutencao_externa'] ?? 'NAO') === 'SIM'; ?>

          <!-- Pergunta recolhida: só abre o formulário se o técnico responder Sim -->
          <div id="perguntaExterna" class="pergunta-ext" style="<?= $ja_externa ? 'display:none' : '' ?>">
            <div class="pergunta-txt">
              <i class="fas fa-circle-question"></i>
              <span>Enviar para Manutenção Externa?</span>
            </div>
            <div style="display:flex;gap:8px">
              <button type="button" class="btn btn-encerrar" onclick="abrirExterna()">Sim</button>
              <button type="button" class="btn btn-ghost" onclick="recusarExterna()">Não</button>
            </div>
          </div>
          <div id="recusaExterna" class="mat-aviso info" style="display:none">
            <i class="fas fa-circle-check"></i> Manutenção externa: <strong>Não</strong>.
            Nenhum dado adicional é necessário.
            <a href="#" onclick="abrirExterna();return false" style="color:var(--accent-steel);margin-left:6px">Mudei de ideia</a>
          </div>

          <form method="POST" enctype="multipart/form-data" onsubmit="return validarExterna(this)"
                id="formExterna" style="<?= $ja_externa ? '' : 'display:none' ?>">
            <input type="hidden" name="acao" value="salvar_externa">
            <input type="hidden" name="protocolo" value="<?= htmlspecialchars($protocolo) ?>">

            <!-- Tipo de atendimento externo -->
            <div class="fg" style="margin-bottom:16px">
              <label class="flabel">Tipo de atendimento</label>
              <div class="tipo-ext">
                <label class="tipo-op">
                  <input type="radio" name="tipo_ext" value="ENVIO" id="tipoEnvio"
                         <?= ($ext['tipo'] ?? 'ENVIO')==='ENVIO'?'checked':'' ?> onchange="alternarTipoExt()">
                  <span><i class="fas fa-truck"></i> Enviar para manutenção externa</span>
                  <small>O equipamento sai do hospital</small>
                </label>
                <label class="tipo-op">
                  <input type="radio" name="tipo_ext" value="VISITA" id="tipoVisita"
                         <?= ($ext['tipo'] ?? '')==='VISITA'?'checked':'' ?> onchange="alternarTipoExt()">
                  <span><i class="fas fa-user-clock"></i> Visita técnica</span>
                  <small>O técnico vem até o hospital</small>
                </label>
              </div>
            </div>

            <!-- Ciclo próprio da manutenção externa -->
            <div class="fg" style="margin-bottom:16px">
              <label class="flabel">
                Status da manutenção externa
                <?php $st_ex = $ext['status'] ?? 'AGUARDANDO_ORCAMENTO'; ?>
                <span class="st-pill <?= $st_ex==='CONCLUIDO' ? 'st-ok' : 'st-pend' ?>">
                  <?= $st_ex==='CONCLUIDO' ? 'concluído' : 'pendência' ?>
                </span>
              </label>
              <select name="status_ext" class="fi">
                <option value="AGUARDANDO_ORCAMENTO"  <?= $st_ex==='AGUARDANDO_ORCAMENTO'?'selected':'' ?>>1 — Aguardando orçamento</option>
                <option value="EM_MANUTENCAO_EXTERNA" <?= $st_ex==='EM_MANUTENCAO_EXTERNA'?'selected':'' ?>>2 — Em manutenção externa</option>
                <option value="CONCLUIDO"             <?= $st_ex==='CONCLUIDO'?'selected':'' ?>>3 — Concluído</option>
              </select>
            </div>

            <div class="grid2">
              <div class="fg">
                <label class="flabel">Empresa <span style="color:var(--status-err)">*</span></label>
                <?php if (!$fornecedores): ?>
                <div style="font-size:12px;color:var(--status-warn);padding:8px 0">
                  Nenhum fornecedor ativo.
                  <a href="eng_clin_cadastrodefornecedores.php" style="color:var(--accent-steel)">Cadastrar fornecedor</a>
                </div>
                <?php else: ?>
                <select name="empresa" class="fi" id="selEmpresa">
                  <option value="">Selecione a empresa...</option>
                  <?php foreach ($fornecedores as $fn): ?>
                  <option value="<?= htmlspecialchars($fn) ?>" <?= ($ext['empresa'] ?? '')===$fn?'selected':'' ?>><?= htmlspecialchars($fn) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php endif; ?>
              </div>
              <div class="fg">
                <label class="flabel">Problema relatado à empresa</label>
                <input type="text" name="problema_ext" class="fi" placeholder="Sintoma / causa"
                       value="<?= htmlspecialchars($ext['problema'] ?? '') ?>">
              </div>
              <div class="fg">
                <label class="flabel">Orçamento</label>
                <select name="orcamento" class="fi" id="selOrcamento">
                  <option value="NAO" <?= ($ext['orcamento'] ?? 'NAO')==='NAO'?'selected':'' ?>>Não</option>
                  <option value="SIM" <?= ($ext['orcamento'] ?? '')==='SIM'?'selected':'' ?>>Sim</option>
                </select>
              </div>
              <div class="fg">
                <label class="flabel">Valor do orçamento (R$)</label>
                <input type="text" name="valor_orcamento" class="fi" id="inpValor" placeholder="0,00"
                       value="<?= $ext && $ext['valor_orcamento'] !== null ? number_format((float)$ext['valor_orcamento'],2,',','.') : '' ?>">
              </div>
              <div class="fg full">
                <label class="flabel">Link do orçamento</label>
                <input type="text" name="link_orcamento" class="fi" placeholder="https://..."
                       value="<?= htmlspecialchars($ext['link_orcamento'] ?? '') ?>">
              </div>
              <div class="fg">
                <label class="flabel">Anexar fotos</label>
                <input type="file" name="fotos[]" class="fi" multiple accept="image/*">
              </div>
              <div class="fg">
                <label class="flabel">Anexar PDF</label>
                <input type="file" name="pdfs[]" class="fi" multiple accept="application/pdf">
              </div>
            </div>

            <!-- Campos exclusivos da visita técnica -->
            <div id="camposVisita" style="<?= ($ext['tipo'] ?? '')==='VISITA' ? '' : 'display:none' ?>">
              <div class="sec-sep">Dados da visita</div>
              <div class="grid2">
                <div class="fg">
                  <label class="flabel">Técnico da empresa</label>
                  <input type="text" name="visita_tecnico" class="fi" placeholder="Nome de quem veio"
                         value="<?= htmlspecialchars($ext['visita_tecnico'] ?? '') ?>">
                </div>
                <div class="fg">
                  <label class="flabel">Data da visita</label>
                  <input type="date" name="visita_data" class="fi"
                         value="<?= htmlspecialchars($ext['visita_data'] ?? '') ?>">
                </div>
                <div class="fg">
                  <label class="flabel">Hora de chegada</label>
                  <input type="time" name="visita_chegada" class="fi" step="1"
                         value="<?= htmlspecialchars($ext['visita_chegada'] ?? '') ?>">
                </div>
                <div class="fg">
                  <label class="flabel">Hora de saída</label>
                  <input type="time" name="visita_saida" class="fi" step="1"
                         value="<?= htmlspecialchars($ext['visita_saida'] ?? '') ?>">
                </div>
                <div class="fg full">
                  <label class="flabel">Solução aplicada</label>
                  <textarea name="visita_solucao" class="fi"
                            placeholder="O que o técnico da empresa fez no equipamento..."><?= htmlspecialchars($ext['visita_solucao'] ?? '') ?></textarea>
                </div>
              </div>
            </div>

            <div style="font-size:11px;color:var(--text-muted);margin-top:10px;line-height:1.6">
              <i class="fas fa-circle-info"></i>
              Máximo de 8 MB por arquivo. Ao salvar pela primeira vez, o equipamento é movimentado
              para <strong>Engenharia Clínica / Sala de Manutenção</strong> com a observação
              <strong>“ENVIADO PARA MANUTENÇÃO EXTERNA”</strong>, registrada também no histórico do PatAsset.
              <br>
              <i class="fas fa-triangle-exclamation" style="color:var(--status-warn)"></i>
              Com orçamento em <strong>“Não”</strong>, a situação da OS passa a
              <strong>“Aguardando orçamento”</strong> automaticamente.
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
              <?php if (!$ja_externa): ?>
              <button type="button" class="btn btn-ghost" onclick="recusarExterna()">Cancelar</button>
              <?php endif; ?>
              <button type="submit" class="btn btn-encerrar"><i class="fas fa-floppy-disk"></i> Salvar manutenção externa</button>
            </div>
          </form>
          <?php else: ?>
            <div class="grid3">
              <div><div class="proto-label">Empresa</div><div class="proto-val"><?= htmlspecialchars($ext['empresa'] ?? '—') ?></div></div>
              <div><div class="proto-label">Orçamento</div><div class="proto-val">
                <?= ($ext['orcamento'] ?? 'NAO')==='SIM' ? 'Sim' : 'Não' ?>
                <?= $ext && $ext['valor_orcamento'] !== null ? ' — R$ '.number_format((float)$ext['valor_orcamento'],2,',','.') : '' ?>
              </div></div>
              <div><div class="proto-label">Saída</div><div class="proto-val">
                <?= !empty($ext['data_saida']) ? date('d/m/Y', strtotime($ext['data_saida'])) : '—' ?>
              </div></div>
            </div>
            <?php if (!empty($ext['link_orcamento'])): ?>
            <div style="margin-top:12px">
              <a href="<?= htmlspecialchars($ext['link_orcamento']) ?>" target="_blank" rel="noopener"
                 style="color:var(--accent-steel);font-size:12.5px">
                <i class="fas fa-link"></i> Abrir orçamento
              </a>
            </div>
            <?php endif; ?>
          <?php endif; ?>

          <?php if (!$encerrada && !empty($ext['link_orcamento'])): ?>
          <div style="margin-top:12px">
            <a href="<?= htmlspecialchars($ext['link_orcamento']) ?>" target="_blank" rel="noopener"
               style="color:var(--accent-steel);font-size:12.5px">
              <i class="fas fa-link"></i> Abrir orçamento salvo
            </a>
          </div>
          <?php endif; ?>

          <!-- Anexos -->
          <?php if ($anexos): ?>
          <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px">
            <div class="proto-label" style="margin-bottom:9px">Anexos (<?= count($anexos) ?>)</div>
            <div class="anx-grid">
              <?php foreach ($anexos as $a): ?>
              <div class="anx">
                <a href="?protocolo=<?= urlencode($protocolo) ?>&anexo=<?= (int)$a['id'] ?>" target="_blank" rel="noopener" class="anx-link">
                  <i class="fas <?= $a['tipo']==='PDF' ? 'fa-file-pdf' : 'fa-image' ?>"></i>
                  <span class="anx-nome" title="<?= htmlspecialchars($a['nome_arquivo']) ?>"><?= htmlspecialchars($a['nome_arquivo']) ?></span>
                  <span class="anx-tam"><?= number_format($a['tamanho']/1024, 0, ',', '.') ?> KB</span>
                </a>
                <?php if (!$encerrada): ?>
                <form method="POST" onsubmit="return confirm('Remover este anexo?')" style="display:inline">
                  <input type="hidden" name="acao" value="del_anexo">
                  <input type="hidden" name="protocolo" value="<?= htmlspecialchars($protocolo) ?>">
                  <input type="hidden" name="id_anexo" value="<?= (int)$a['id'] ?>">
                  <button type="submit" class="anx-del" title="Remover"><i class="fas fa-xmark"></i></button>
                </form>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php endif; ?>
        </div>
      </div>

      <!-- ══ SEÇÃO: LOCALIZAÇÃO ═════════════════════════════════════ -->
      <div class="sec">
        <div class="sec-head">
          <div class="sec-icon"><i class="fas fa-location-dot"></i></div>
          <span class="sec-title">Localização do Item</span>
        </div>
        <div class="sec-body">
          <?php if ($encerrada): ?>
            <?php if (($os['item_devolvido'] ?? '') === 'SIM'): ?>
            <div class="loc-box" style="background:rgba(74,222,128,.06);border-color:rgba(74,222,128,.22)">
              <i class="fas fa-circle-check" style="color:#4ade80"></i>
              <div class="loc-txt">
                <strong>Item devolvido para:</strong><br>
                <span style="font-size:14px;color:var(--text-primary)">
                  <?= htmlspecialchars($os['loc_orig_unidade'] ?: '—') ?>
                  &nbsp;/&nbsp; <?= htmlspecialchars($os['loc_orig_setor'] ?: '—') ?>
                  <?= $os['loc_orig_area'] ? ' &nbsp;/&nbsp; '.htmlspecialchars($os['loc_orig_area']) : '' ?>
                </span>
              </div>
            </div>
            <?php elseif (($os['item_devolvido'] ?? '') === 'NAO'): ?>
            <div class="loc-box" style="background:rgba(250,204,21,.06);border-color:rgba(250,204,21,.25)">
              <i class="fas fa-triangle-exclamation" style="color:#facc15"></i>
              <div class="loc-txt">
                <strong>Item não devolvido ao encerrar a OS.</strong><br>
                Local de origem registrado:
                <?= htmlspecialchars($os['loc_orig_unidade'] ?: '—') ?>
                &nbsp;/&nbsp; <?= htmlspecialchars($os['loc_orig_setor'] ?: '—') ?>
                <?= $os['loc_orig_area'] ? ' &nbsp;/&nbsp; '.htmlspecialchars($os['loc_orig_area']) : '' ?>
              </div>
            </div>
            <?php else: ?>
            <div class="loc-box">
              <i class="fas fa-location-dot"></i>
              <div class="loc-txt">
                <strong>Local de origem:</strong>
                <?= htmlspecialchars($os['loc_orig_unidade'] ?: '—') ?>
                &nbsp;/&nbsp; <?= htmlspecialchars($os['loc_orig_setor'] ?: '—') ?>
                <?= $os['loc_orig_area'] ? ' &nbsp;/&nbsp; '.htmlspecialchars($os['loc_orig_area']) : '' ?>
              </div>
            </div>
            <?php endif; ?>
          <?php else: ?>
          <div class="loc-box">
            <i class="fas fa-arrow-rotate-left"></i>
            <div class="loc-txt">
              <strong>Local de devolução:</strong><br>
              <?= htmlspecialchars($os['loc_orig_unidade'] ?: '—') ?>
              &nbsp;/&nbsp; <?= htmlspecialchars($os['loc_orig_setor'] ?: '—') ?>
              <?= $os['loc_orig_area'] ? ' &nbsp;/&nbsp; '.htmlspecialchars($os['loc_orig_area']) : '' ?>
              <br><br>
              O equipamento está em <strong>Engenharia Clínica / Sala de Manutenção</strong>.
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ══ SEÇÃO: HISTÓRICO ═══════════════════════════════════════ -->
      <div class="sec">
        <div class="sec-head">
          <div class="sec-icon"><i class="fas fa-clock-rotate-left"></i></div>
          <span class="sec-title">Histórico do Protocolo</span>
          <?php if ($eventos): ?><span class="chip-ok" style="background:rgba(160,174,192,.1);border-color:rgba(160,174,192,.25);color:var(--accent-steel)"><?= count($eventos) ?></span><?php endif; ?>
        </div>
        <div class="sec-body">
          <?php if (!$eventos): ?>
          <div class="vazio"><i class="fas fa-clock-rotate-left"></i>Nenhum evento registrado.</div>
          <?php else: foreach ($eventos as $e): ?>
          <div class="ev">
            <div class="ev-dot"></div>
            <div class="ev-body">
              <div class="ev-tipo"><?= htmlspecialchars(str_replace('_',' ',$e['tipo_evento'])) ?></div>
              <div class="ev-desc"><?= htmlspecialchars($e['descricao_evento']) ?></div>
              <div class="ev-meta">
                <?= htmlspecialchars($e['nome_usuario'] ?: '—') ?>
                &middot; <?= $e['data_evento'] ? date('d/m/Y', strtotime($e['data_evento'])) : '' ?>
                <?= substr((string)$e['hora_evento'], 0, 5) ?>
              </div>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- ══ ENCERRAMENTO ═══════════════════════════════════════════ -->
      <?php if (!$encerrada): ?>
      <div class="sec" style="border-color:rgba(74,222,128,.25)">
        <div class="sec-head" style="background:rgba(74,222,128,.05)">
          <div class="sec-icon" style="background:rgba(74,222,128,.12);color:#4ade80"><i class="fas fa-flag-checkered"></i></div>
          <span class="sec-title" style="color:#4ade80">Encerrar Ordem de Serviço</span>
        </div>
        <div class="sec-body">
          <div style="font-size:12.5px;color:var(--text-secondary);line-height:1.65;margin-bottom:14px">
            Ao encerrar, a OS sai da lista de pendências e o protocolo é concluído.
            É necessário ter preenchido o <strong style="color:var(--text-primary)">Serviço executado</strong>
            e a <strong style="color:var(--text-primary)">Situação/motivo</strong>.
          </div>
          <button type="button" class="btn btn-encerrar" onclick="abrirEncerrar()">
            <i class="fas fa-flag-checkered"></i> Encerrar OS
          </button>
        </div>
      </div>

      <!-- Pergunta da devolução -->
      <div class="modal-bg" id="modalEncerrar">
        <div class="modal-box">
          <div class="modal-head">
            <i class="fas fa-dolly" style="color:#60a5fa"></i>
            <span>Devolver item ao local de origem?</span>
          </div>
          <div class="modal-body">
            <div class="loc-box" style="margin-bottom:14px">
              <i class="fas fa-location-dot"></i>
              <div class="loc-txt">
                <strong>Local de devolução:</strong><br>
                <?= htmlspecialchars($os['loc_orig_unidade'] ?: '—') ?>
                &nbsp;/&nbsp; <?= htmlspecialchars($os['loc_orig_setor'] ?: '—') ?>
                <?= $os['loc_orig_area'] ? ' &nbsp;/&nbsp; '.htmlspecialchars($os['loc_orig_area']) : '' ?>
              </div>
            </div>
            <?php if (($os['manutencao_externa'] ?? 'NAO') === 'SIM'): ?>
            <div class="aviso aviso-info" style="margin:0">
              <i class="fas fa-truck"></i>
              <div>
                Esta OS passou por <strong>manutenção externa</strong>. Ao encerrar, o sistema
                abre a tela de movimentação com o equipamento já identificado, para você
                informar o destino manualmente — a devolução automática não se aplica.
              </div>
            </div>
            <?php else: ?>
            <div style="font-size:12.5px;color:var(--text-secondary);line-height:1.65">
              <strong style="color:#4ade80">Sim</strong> — o sistema move o equipamento de volta
              automaticamente, registra no histórico do PatAsset e notifica por e-mail.<br><br>
              <strong style="color:var(--text-muted)">Não</strong> — o equipamento permanece onde está
              e o histórico registra que não houve devolução.
            </div>
            <?php endif; ?>
          </div>
          <div class="modal-acoes">
            <button type="button" class="btn btn-ghost" onclick="fecharEncerrar()">Cancelar</button>
            <form method="POST" style="display:inline">
              <input type="hidden" name="acao" value="encerrar_os">
              <input type="hidden" name="protocolo" value="<?= htmlspecialchars($protocolo) ?>">
              <input type="hidden" name="devolver" value="NAO">
              <button type="submit" class="btn btn-ghost">Não devolver</button>
            </form>
            <form method="POST" style="display:inline">
              <input type="hidden" name="acao" value="encerrar_os">
              <input type="hidden" name="protocolo" value="<?= htmlspecialchars($protocolo) ?>">
              <input type="hidden" name="devolver" value="SIM">
              <button type="submit" class="btn btn-encerrar">
                <i class="fas fa-check"></i> Sim, devolver
              </button>
            </form>
          </div>
        </div>
      </div>
      <?php else: ?>
      <div class="sec">
        <div class="sec-head">
          <div class="sec-icon" style="background:rgba(74,222,128,.12);color:#4ade80"><i class="fas fa-flag-checkered"></i></div>
          <span class="sec-title">Encerramento</span>
          <a href="eng_clin_os_documento.php?protocolo=<?= urlencode($protocolo) ?><?= !empty($os['documento_html']) ? '&arquivo=1' : '' ?>"
             target="_blank" rel="noopener" class="btn btn-encerrar" style="padding:6px 14px;font-size:11.5px">
            <i class="fas fa-file-lines"></i> Documento da OS
          </a>
        </div>
        <div class="sec-body">
          <div class="grid3">
            <div>
              <div class="proto-label">Encerrada em</div>
              <div class="proto-val">
                <?= $os['data_fechamento'] ? date('d/m/Y', strtotime($os['data_fechamento'])) : '—' ?>
                <?= substr((string)$os['hora_fechamento'], 0, 5) ?>
              </div>
            </div>
            <div>
              <div class="proto-label">Situação</div>
              <div class="proto-val"><?= htmlspecialchars($MOTIVOS[$os['motivo']] ?? ($os['motivo'] ?: '—')) ?></div>
            </div>
            <div>
              <div class="proto-label">Item devolvido</div>
              <div class="proto-val">
                <?php if ($os['item_devolvido'] === 'SIM'): ?>
                  <span style="color:#4ade80">Sim — <?= htmlspecialchars($os['loc_orig_setor'] ?: '—') ?></span>
                <?php elseif ($os['item_devolvido'] === 'NAO'): ?>
                  <span style="color:var(--status-warn)">Não devolvido</span>
                <?php else: ?>—<?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

    <?php endif; ?>

  </div>
</div>

<div class="footer" id="pageFooter">
  <div>Usuário: <?= htmlspecialchars($nome_usuario) ?></div>
  <div>Data: <?= $data ?> | Hora: <span id="hora"><?= $hora ?></span></div>
  <div>&copy; GK Soluções</div>
</div>

<?php eng_clin_menu_painel(); ?>

<script>
setInterval(() => {
  const h = document.getElementById('hora');
  if (h) h.innerText = new Date().toLocaleTimeString('pt-BR');
}, 1000);

/* ── Sidebar (recolher/expandir) ── */
const sidebar = document.getElementById('sidebar');
const mainEl  = document.getElementById('main');
const footEl  = document.getElementById('pageFooter');
const tBtn    = document.getElementById('toggleBtn');
const tIcon   = document.getElementById('toggleIcon');
if (tBtn) {
  tBtn.addEventListener('click', () => {
    const col = sidebar.classList.toggle('collapsed');
    mainEl.classList.toggle('sidebar-collapsed', col);
    if (tIcon) { tIcon.classList.toggle('fa-chevron-left', !col); tIcon.classList.toggle('fa-chevron-right', col); }
    if (footEl) footEl.style.marginLeft = col ? 'var(--sidebar-collapsed)' : 'var(--sidebar-w)';
  });
}
const mBtn = document.getElementById('menuToggle');
if (mBtn) mBtn.addEventListener('click', () => {
  sidebar.classList.add('open');
  const ov = document.getElementById('sidebarOverlay'); if (ov) ov.classList.add('open');
});
function fecharSidebar(){
  sidebar.classList.remove('open');
  const ov = document.getElementById('sidebarOverlay'); if (ov) ov.classList.remove('open');
}

/* ══════════════════════════════════════════════════════════════════════
   SALVAMENTO POR ETAPA
   Cada campo grava sozinho ao perder o foco, se o valor mudou.
   ══════════════════════════════════════════════════════════════════════ */
const PROTOCOLO = <?= json_encode($protocolo) ?>;

function statusEl(etapa) {
  return document.querySelector(`.sec-status[data-status="${etapa}"]`);
}

function mostrarStatus(etapa, tipo, texto) {
  const el = statusEl(etapa);
  if (!el) return;
  el.className = 'sec-status ' + tipo;
  el.innerHTML = (tipo === 'saving' ? '<i class="fas fa-circle-notch fa-spin"></i> '
               : tipo === 'ok'      ? '<i class="fas fa-check"></i> '
               :                      '<i class="fas fa-triangle-exclamation"></i> ') + texto;
  if (tipo === 'ok') {
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.add('hidden'), 2600);
  }
}

function marcarChip(etapa) {
  if (document.getElementById('chip-' + etapa)) return;
  const st = statusEl(etapa);
  if (!st) return;
  const chip = document.createElement('span');
  chip.className = 'chip-ok';
  chip.id = 'chip-' + etapa;
  chip.innerHTML = '<i class="fas fa-check"></i> Preenchido';
  st.parentNode.insertBefore(chip, st);
}

function salvarCampo(el) {
  const campo = el.dataset.campo;
  const valor = el.value;
  if (el._ultimo === valor) return;      // nada mudou
  el._ultimo = valor;

  const etapa = el.closest('.sec').querySelector('.sec-status')?.dataset.status || '';
  mostrarStatus(etapa, 'saving', 'Salvando...');

  const fd = new FormData();
  fd.append('ajax', 'salvar_campo');
  fd.append('protocolo', PROTOCOLO);
  fd.append('campo', campo);
  fd.append('valor', valor);
  if (el.dataset.mo) fd.append('mo_id', el.dataset.mo);   // campo de intervenção

  fetch('', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        mostrarStatus(etapa, 'ok', 'Salvo às ' + (d.hora || '').substring(0,5));
        marcarChip(d.etapa || etapa);
      } else {
        mostrarStatus(etapa, 'err', d.msg || 'Erro ao salvar');
        el._ultimo = null;               // permite nova tentativa
      }
    })
    .catch(() => {
      mostrarStatus(etapa, 'err', 'Sem conexão — não salvou');
      el._ultimo = null;
    });
}

/* A seção de periodicidade só faz sentido em manutenção preventiva */
function revisarPreventiva() {
  const sec = document.getElementById('secPreventiva');
  if (!sec) return;
  const tem = [...document.querySelectorAll('[data-campo="ocorrencia"]')]
                .some(s => s.value === 'MANUTENCAO_PREVENTIVA');
  sec.style.display = tem ? '' : 'none';
}
document.querySelectorAll('[data-campo="ocorrencia"]').forEach(s => {
  s.addEventListener('change', revisarPreventiva);
});

document.querySelectorAll('[data-campo]').forEach(el => {
  el._ultimo = el.value;                 // baseline: o que veio do banco
  if (el.tagName === 'SELECT' || el.type === 'date' || el.type === 'time') {
    el.addEventListener('change', () => salvarCampo(el));
  } else {
    el.addEventListener('blur', () => salvarCampo(el));
  }
});

/* Avisa se sair com algo digitado e ainda não salvo (textarea sem blur) */
let encerrando = false;
window.addEventListener('beforeunload', e => {
  if (encerrando) return;                // o submit do encerramento é intencional
  const sujo = [...document.querySelectorAll('[data-campo]')].some(el => el._ultimo !== el.value);
  if (sujo) { e.preventDefault(); e.returnValue = ''; }
});

/* ══════════════════════════════════════════════════════════════════════
   MATERIAL — avisa sobre saldo antes de enviar
   ══════════════════════════════════════════════════════════════════════ */
const UNI_ESTOQUE = <?= json_encode($uni_estoque ?: 'unidade não identificada') ?>;

/* Cada intervenção tem seu próprio seletor de peça e seu aviso de saldo */
function saldoDoForm(form) {
  const sel = form.querySelector('.sel-peca');
  const op  = sel?.selectedOptions?.[0];
  return (op && sel.value) ? parseFloat(op.dataset.saldo || '0') : null;
}

document.querySelectorAll('.sel-peca').forEach(sel => {
  sel.addEventListener('change', () => {
    const form = sel.closest('form');
    const av   = form?.parentNode.querySelector('.aviso-saldo');
    if (!av) return;
    const s = saldoDoForm(form);
    if (s === null) { av.className = 'mat-aviso aviso-saldo'; return; }
    if (s <= 0) {
      av.className = 'mat-aviso aviso-saldo vis alerta';
      av.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Sem saldo desta peça em ' + UNI_ESTOQUE + '.';
    } else if (s <= 2) {
      av.className = 'mat-aviso aviso-saldo vis alerta';
      av.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Saldo baixo em ' + UNI_ESTOQUE + ': ' + s + ' un.';
    } else {
      av.className = 'mat-aviso aviso-saldo vis info';
      av.innerHTML = '<i class="fas fa-circle-info"></i> Disponível em ' + UNI_ESTOQUE + ': ' + s + ' un.';
    }
  });
});

/* Alterna entre peça do estoque e peça reaproveitada */
function alternarOrigem(radio) {
  const form = radio.closest('form');
  const reap = radio.value === 'REAPROVEITADO';
  form.querySelector('.campo-reap').style.display    = reap ? '' : 'none';
  form.querySelector('.campo-estoque').style.display = reap ? 'none' : '';
  const av = form.parentNode.querySelector('.aviso-saldo');
  if (av && reap) av.className = 'mat-aviso aviso-saldo';
}

function validarMaterial(form) {
  const reap = form.querySelector('[name=origem_material]:checked')?.value === 'REAPROVEITADO';
  if (reap) {
    if (!form.nome_reaproveitado.value.trim()) {
      alert('Informe o nome da peça reaproveitada.');
      form.nome_reaproveitado.focus();
      return false;
    }
    encerrando = true;
    return true;   // sem estoque para validar
  }
  if (!form.codigo_item.value) {
    alert('Selecione a peça.');
    form.codigo_item.focus();
    return false;
  }
  const s = saldoDoForm(form);
  const q = parseFloat(form.quantidade_usar.value || '0');
  if (s !== null && q > s) {
    return confirm(
      'A quantidade informada (' + q + ') é maior que o saldo em ' + UNI_ESTOQUE + ' (' + s + ' un.).\n\n' +
      'O lançamento será registrado mesmo assim e o saldo ficará zerado.\n' +
      'Estoque de outras unidades NÃO será consumido.\n\nContinuar?'
    );
  }
  encerrando = true;   // submit intencional, não avisar sobre campos
  return true;
}

/* ══════════════════════════════════════════════════════════════════════
   MANUTENÇÃO EXTERNA
   ══════════════════════════════════════════════════════════════════════ */
function validarIntervencao(form) {
  if (!form.tecnico_usuario.value) {
    alert('Selecione o técnico que vai executar o atendimento.');
    form.tecnico_usuario.focus();
    return false;
  }
  encerrando = true;   // submit intencional
  return true;
}

/* Visita técnica revela campos próprios; envio externo os esconde */
function alternarTipoExt() {
  const visita = document.getElementById('tipoVisita');
  const campos = document.getElementById('camposVisita');
  if (campos) campos.style.display = (visita && visita.checked) ? '' : 'none';
}

function abrirExterna() {
  const p = document.getElementById('perguntaExterna');
  const r = document.getElementById('recusaExterna');
  const f = document.getElementById('formExterna');
  if (p) p.style.display = 'none';
  if (r) r.style.display = 'none';
  if (f) { f.style.display = ''; f.querySelector('select,input')?.focus(); }
}

function recusarExterna() {
  const p = document.getElementById('perguntaExterna');
  const r = document.getElementById('recusaExterna');
  const f = document.getElementById('formExterna');
  if (f) f.style.display = 'none';
  if (p) p.style.display = 'none';
  if (r) r.style.display = 'block';
}

function validarExterna(form) {
  const emp = form.empresa;
  if (emp && !emp.value) {
    alert('Selecione a empresa de manutenção externa.');
    emp.focus();
    return false;
  }
  const orc = form.orcamento, val = form.valor_orcamento;
  if (orc && orc.value === 'SIM' && val && !val.value.trim()) {
    if (!confirm('Orçamento marcado como "Sim" mas sem valor informado. Salvar assim mesmo?')) {
      val.focus();
      return false;
    }
  }
  encerrando = true;   // submit intencional
  return true;
}

/* Valor: aceita só números e vírgula */
const inpValor = document.getElementById('inpValor');
if (inpValor) {
  inpValor.addEventListener('input', () => {
    inpValor.value = inpValor.value.replace(/[^\d.,]/g, '');
  });
}

/* ══════════════════════════════════════════════════════════════════════
   ENCERRAMENTO
   ══════════════════════════════════════════════════════════════════════ */
/* Status que encerram um processo — espelha ST_CONCLUSIVOS do PHP */
const ST_CONCLUSIVOS = ['PROBLEMA_SOLUCIONADO','OBSOLESCENCIA','SEM_SOLUCAO'];

/* Marca visualmente um campo obrigatório não preenchido */
function marcarObrigatorio(el, marcar) {
  if (!el) return;
  el.classList.toggle('faltando', marcar);
  let av = el.parentNode.querySelector('.obrig');
  if (!av) {
    av = document.createElement('div');
    av.className = 'obrig';
    av.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Preenchimento obrigatório';
    const lbl = el.parentNode.querySelector('.flabel');
    lbl ? lbl.after(av) : el.parentNode.insertBefore(av, el);
  }
  av.classList.toggle('vis', marcar);
}

function limparObrigatorios() {
  document.querySelectorAll('.obrig.vis').forEach(a => a.classList.remove('vis'));
  document.querySelectorAll('.fi.faltando').forEach(e => e.classList.remove('faltando'));
}

/* Some com o aviso assim que o campo é preenchido */
document.addEventListener('input', e => {
  const el = e.target;
  if (el.classList?.contains('faltando') && String(el.value).trim()) marcarObrigatorio(el, false);
});
document.addEventListener('change', e => {
  const el = e.target;
  if (el.classList?.contains('faltando') && String(el.value).trim()) marcarObrigatorio(el, false);
});

function abrirEncerrar() {
  limparObrigatorios();

  // Valida no cliente antes de abrir, para não fazer o usuário ir e voltar
  const servicos = [...document.querySelectorAll('[data-campo="servico"]')];
  const status   = [...document.querySelectorAll('[data-campo="status"]')];
  const faltando = [];

  // Datas e horas de início são obrigatórias em toda intervenção
  const obrigMO = ['data_inicio','hora_inicio','ocorrencia'];
  let faltouMO = false;
  document.querySelectorAll('.iv').forEach(card => {
    obrigMO.forEach(c => {
      const el = card.querySelector('[data-campo="' + c + '"]');
      if (el && !String(el.value).trim()) { marcarObrigatorio(el, true); faltouMO = true; }
    });
    const sv = card.querySelector('[data-campo="servico"]');
    if (sv && !sv.value.trim()) { marcarObrigatorio(sv, true); faltouMO = true; }
  });
  if (faltouMO) faltando.push('Data, hora, ocorrência e serviço de cada intervenção');

  if (servicos.length && !servicos.some(s => s.value.trim())) {
    faltando.push('Serviço executado em ao menos uma intervenção');
  }

  const pendentes = status.filter(s => !ST_CONCLUSIVOS.includes(s.value));
  if (pendentes.length) {
    const nomes = pendentes.map(s => s.options[s.selectedIndex]?.text).filter(Boolean);
    faltando.push('Resolver ' + pendentes.length + ' processo(s) pendente(s): ' + [...new Set(nomes)].join(', '));
  }

  if (faltando.length) {
    alert('Não é possível encerrar ainda:\n\n• ' + faltando.join('\n• '));
    const alvo = document.querySelector('.fi.faltando') || pendentes[0] || servicos[0];
    alvo?.scrollIntoView({behavior:'smooth', block:'center'});
    alvo?.focus();
    return;
  }
  document.getElementById('modalEncerrar').classList.add('aberto');
}

function fecharEncerrar() {
  document.getElementById('modalEncerrar').classList.remove('aberto');
}

document.querySelectorAll('#modalEncerrar form').forEach(f => {
  f.addEventListener('submit', () => { encerrando = true; });
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') fecharEncerrar();
});

const mdl = document.getElementById('modalEncerrar');
if (mdl) mdl.addEventListener('mousedown', e => { if (e.target === mdl) fecharEncerrar(); });

/* ── Heartbeat de sessão ── */
(function hb() {
  fetch('heartbeat.php?_=' + Date.now(), { method:'POST', credentials:'same-origin', cache:'no-store' })
    .then(r => r.json()).then(d => { if (d.revogada) location.href = 'index.html?error=sessao+encerrada'; })
    .catch(() => {});
  setTimeout(hb, 30000);
})();

<?php eng_clin_menu_js(); ?>
</script>
</body>
</html>
