<?php
ob_start();
mysqli_report(MYSQLI_REPORT_OFF);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'conexao.php';

// ─── Controle de Acesso ───────────────────────────────────────────────────────
if (!isset($_SESSION['usuario_logado']) || $_SESSION['classe_usuario'] !== 'DEV') {
    header('Location: index.html');
    exit;
}

$dev_usuario = $_SESSION['usuario_logado'];
$dev_hora    = date('d/m/Y H:i:s');

session_write_close();

// ─── Ações AJAX ───────────────────────────────────────────────────────────────
if (isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    $action = $_POST['action'];

    try {

    // Listar usuários
    if ($action === 'listar_usuarios') {
        $r = $conn->query("SELECT id, usuario, senha, permicao, classe_usuario, status FROM usuarios ORDER BY id ASC");
        $rows = [];
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // Editar usuário
    if ($action === 'editar_usuario') {
        $id             = intval($_POST['id']);
        $usuario        = $conn->real_escape_string($_POST['usuario']);
        $permicao       = $conn->real_escape_string($_POST['permicao']);
        $classe_usuario = $conn->real_escape_string($_POST['classe_usuario']);
        $nova_senha     = trim($_POST['senha']);
        if ($nova_senha !== '') {
            $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $sql  = "UPDATE usuarios SET usuario='$usuario', senha='$hash', permicao='$permicao', classe_usuario='$classe_usuario' WHERE id=$id";
        } else {
            $sql = "UPDATE usuarios SET usuario='$usuario', permicao='$permicao', classe_usuario='$classe_usuario' WHERE id=$id";
        }
        $conn->query($sql);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Excluir usuário
    if ($action === 'excluir_usuario') {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM usuarios WHERE id=$id");
        echo json_encode(['ok' => true]);
        exit;
    }

    // Criar usuário
    if ($action === 'criar_usuario') {
        $usuario_novo = trim($_POST['usuario'] ?? '');
        $senha_nova   = (string)($_POST['senha'] ?? '');
        $permicao     = strtoupper(trim($_POST['permicao'] ?? 'C'));
        $classe       = strtoupper(trim($_POST['classe_usuario'] ?? ''));

        // Validações — esta é a única porta de entrada de usuários no sistema
        // desde que o cadastro público saiu da tela de login.
        if ($usuario_novo === '' || $senha_nova === '') {
            echo json_encode(['ok' => false, 'msg' => 'Usuário e senha são obrigatórios.']); exit;
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $usuario_novo)) {
            echo json_encode(['ok' => false, 'msg' => 'Usuário: 3 a 30 caracteres, apenas letras, números, ponto, hífen e sublinhado.']); exit;
        }
        if (strlen($senha_nova) < 8) {
            echo json_encode(['ok' => false, 'msg' => 'A senha precisa ter pelo menos 8 caracteres.']); exit;
        }
        if (!preg_match('/[A-Za-z]/', $senha_nova) || !preg_match('/[0-9]/', $senha_nova)) {
            echo json_encode(['ok' => false, 'msg' => 'A senha precisa misturar letras e números.']); exit;
        }
        if (!in_array($permicao, ['A','B','C'], true)) {
            echo json_encode(['ok' => false, 'msg' => 'Permissão inválida.']); exit;
        }
        if (!in_array($classe, ['DEV','PATRIMONIO','ENGENHARIA CLINICA'], true)) {
            echo json_encode(['ok' => false, 'msg' => 'Classe inválida.']); exit;
        }

        $stDup = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ? LIMIT 1");
        if ($stDup) {
            $stDup->bind_param('s', $usuario_novo);
            $stDup->execute();
            $rDup = $stDup->get_result();
            $existe = ($rDup && $rDup->fetch_assoc());
            $stDup->close();
            if ($existe) { echo json_encode(['ok' => false, 'msg' => 'Já existe um usuário com esse nome.']); exit; }
        }

        $hash = password_hash($senha_nova, PASSWORD_DEFAULT);
        $stIns = $conn->prepare("INSERT INTO usuarios (usuario, senha, permicao, classe_usuario, status)
                                 VALUES (?,?,?,?,'ATIVO')");
        if (!$stIns) { echo json_encode(['ok' => false, 'msg' => 'Erro no servidor.']); exit; }
        $stIns->bind_param('ssss', $usuario_novo, $hash, $permicao, $classe);
        $ok_ins = $stIns->execute();
        $stIns->close();

        echo json_encode($ok_ins
            ? ['ok' => true, 'msg' => 'Usuário criado.']
            : ['ok' => false, 'msg' => 'Não foi possível criar o usuário.']);
        exit;
    }

    // ── Bloqueios de login (força bruta) ────────────────────────────────────
    if ($action === 'listar_bloqueios') {
        $rows = [];
        $chk = $conn->query("SHOW TABLES LIKE 'login_tentativas'");
        if ($chk && $chk->num_rows > 0) {
            $r = $conn->query("
                SELECT id, tipo, valor, tentativas, bloqueios, bloqueado_ate, ultima_falha,
                       GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), bloqueado_ate)) AS restam
                FROM login_tentativas
                ORDER BY (bloqueado_ate > NOW()) DESC, ultima_falha DESC
                LIMIT 200");
            if ($r) while ($x = $r->fetch_assoc()) $rows[] = $x;
        }
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    if ($action === 'liberar_bloqueio') {
        $id = intval($_POST['id'] ?? 0);
        $st = $conn->prepare("DELETE FROM login_tentativas WHERE id = ?");
        if ($st) { $st->bind_param('i', $id); $st->execute(); $st->close(); }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'liberar_todos_bloqueios') {
        $conn->query("DELETE FROM login_tentativas");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  TABELAS DO BANCO (ver dev_tabelas.php)
    // ══════════════════════════════════════════════════════════════════════
    if (str_starts_with($action, 'tab_')) {
        require_once __DIR__ . '/dev_tabelas.php';
        $_dt_ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']
                              ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);
        if (dt_tratar($conn, $action, (string)($_SESSION['usuario_logado'] ?? ''), $_dt_ip)) exit;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  BACKUPS
    // ══════════════════════════════════════════════════════════════════════
    if ($action === 'backup_status') {
        require_once __DIR__ . '/backup_config.php';
        require_once __DIR__ . '/backup_drive_oauth.php';

        // Histórico gravado pelo backup_run.php
        $execucoes = [];
        $tem = $conn->query("SHOW TABLES LIKE 'dev_backups'");
        if ($tem && $tem->num_rows) {
            $r = $conn->query("SELECT id, origem, situacao, tabelas, linhas, tamanho, arquivo,
                                      local_ok, drive_ok, duracao, detalhe,
                                      DATE_FORMAT(iniciado_em,'%d/%m/%Y') AS data,
                                      DATE_FORMAT(iniciado_em,'%H:%i:%s') AS hora,
                                      TIMESTAMPDIFF(HOUR, iniciado_em, NOW()) AS horas_atras
                               FROM dev_backups ORDER BY id DESC LIMIT 60");
            if ($r) while ($x = $r->fetch_assoc()) $execucoes[] = $x;
        }

        // Arquivos que existem de fato na pasta local
        $locais = [];
        if (defined('BACKUP_LOCAL_DIR') && is_dir(BACKUP_LOCAL_DIR)) {
            foreach (glob(BACKUP_LOCAL_DIR . '/backup_patasset_*') ?: [] as $f) {
                $locais[] = [
                    'nome'    => basename($f),
                    'tamanho' => filesize($f),
                    'data'    => date('d/m/Y H:i', filemtime($f)),
                    'ts'      => filemtime($f),
                ];
            }
            usort($locais, fn($a, $b) => $b['ts'] <=> $a['ts']);
            $locais = array_slice($locais, 0, 40);
        }

        // Últimas linhas do log em arquivo
        $log = '';
        if (defined('BACKUP_LOG_FILE') && file_exists(BACKUP_LOG_FILE)) {
            $todas = @file(BACKUP_LOG_FILE, FILE_IGNORE_NEW_LINES) ?: [];
            $log   = implode("\n", array_slice($todas, -40));
        }

        // Agendamento
        $cron = '';
        if (function_exists('shell_exec')
            && !in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
            $saida = (string)@shell_exec('crontab -l 2>/dev/null');
            foreach (preg_split('/\R/', $saida) as $l) {
                if (stripos($l, 'backup_run') !== false) { $cron = trim($l); break; }
            }
        }

        echo json_encode([
            'ok'        => true,
            'execucoes' => $execucoes,
            'locais'    => $locais,
            'log'       => $log,
            'cron'      => $cron,
            'pasta'     => defined('BACKUP_LOCAL_DIR') ? BACKUP_LOCAL_DIR : '',
            'drive'     => [
                'ativo'      => oauth_configurado(),
                'autorizado' => oauth_carregar()['autorizado_em'] ?? '',
                'pasta'      => oauth_carregar()['pasta_nome'] ?? '',
            ],
            'manter'    => defined('BACKUP_MANTER') ? BACKUP_MANTER : 0,
        ]);
        exit;
    }

    // Dispara um backup completo em segundo plano
    if ($action === 'backup_agora') {
        $php = PHP_BINARY;
        if (!$php || !is_file($php) || stripos(basename($php), 'apache') !== false) {
            foreach (['/usr/local/bin/php', '/usr/bin/php'] as $cand) {
                if (is_file($cand)) { $php = $cand; break; }
            }
        }
        $desativadas = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (!function_exists('shell_exec') || in_array('shell_exec', $desativadas, true)) {
            echo json_encode(['ok' => false, 'msg' => 'shell_exec desativado neste servidor.']);
            exit;
        }
        // Em segundo plano: o backup completo pode passar do tempo limite da
        // requisição, e a tela ficaria travada esperando.
        $cmd = escapeshellcmd($php) . ' ' . escapeshellarg(__DIR__ . '/backup_run.php')
             . ' > /dev/null 2>&1 &';
        @shell_exec($cmd);
        echo json_encode(['ok' => true, 'msg' => 'Backup iniciado em segundo plano. Atualize em alguns instantes.']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  ERROS (PHP + JavaScript)
    // ══════════════════════════════════════════════════════════════════════
    if ($action === 'erros_listar') {
        $tem = $conn->query("SHOW TABLES LIKE 'dev_log_erros'");
        if (!$tem || !$tem->num_rows) { echo json_encode(['ok'=>true,'data'=>[],'resumo'=>[]]); exit; }

        // Compatível com a tabela antiga, que não tinha essas colunas
        $cols = [];
        $rc = $conn->query("SHOW COLUMNS FROM dev_log_erros");
        if ($rc) while ($c = $rc->fetch_assoc()) $cols[] = $c['Field'];
        $tem_novo = in_array('origem', $cols, true);
        if (!$tem_novo) {
            echo json_encode(['ok'=>false,'msg'=>'Rode o dev_seguranca.sql (passos 2 e 3) antes de usar esta tela.']);
            exit;
        }

        $limit  = max(1, min(300, intval($_POST['limit'] ?? 100)));
        $origem = strtoupper(trim($_POST['origem'] ?? ''));
        $nivel  = strtoupper(trim($_POST['nivel']  ?? ''));
        $busca  = trim($_POST['busca'] ?? '');
        $ver_resolvidos = ($_POST['resolvidos'] ?? '0') === '1';

        $w = ['1=1'];
        if (in_array($origem, ['PHP','JS'], true))                       $w[] = "origem = '$origem'";
        if (in_array($nivel, ['INFO','WARNING','ERROR','CRITICAL'], true)) $w[] = "nivel = '$nivel'";
        if ($busca !== '') {
            $b = $conn->real_escape_string($busca);
            $w[] = "(mensagem LIKE '%$b%' OR arquivo LIKE '%$b%' OR usuario LIKE '%$b%' OR url LIKE '%$b%')";
        }
        if (!$ver_resolvidos) $w[] = "resolvido = 0";
        $where = 'WHERE ' . implode(' AND ', $w);

        $r = $conn->query("
            SELECT id, origem, nivel, arquivo, url, linha, mensagem, stack, usuario, ip,
                   user_agent, ocorrencias, resolvido,
                   DATE_FORMAT(criado_em,'%d/%m/%Y %H:%i')     AS primeira,
                   DATE_FORMAT(COALESCE(atualizado_em,criado_em),'%d/%m/%Y %H:%i') AS ultima
            FROM dev_log_erros $where
            ORDER BY COALESCE(atualizado_em, criado_em) DESC
            LIMIT $limit");
        $rows = []; if ($r) while ($x = $r->fetch_assoc()) $rows[] = $x;

        $res = $conn->query("
            SELECT
              SUM(origem='PHP' AND resolvido=0) AS php,
              SUM(origem='JS'  AND resolvido=0) AS js,
              SUM(nivel='CRITICAL' AND resolvido=0) AS criticos,
              SUM(resolvido=0) AS abertos,
              SUM(COALESCE(atualizado_em,criado_em) >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS hoje
            FROM dev_log_erros");
        $resumo = $res ? $res->fetch_assoc() : [];

        echo json_encode(['ok'=>true,'data'=>$rows,'resumo'=>$resumo]);
        exit;
    }

    if ($action === 'erro_resolver') {
        $id = intval($_POST['id'] ?? 0);
        $v  = ($_POST['valor'] ?? '1') === '1' ? 1 : 0;
        $conn->query("UPDATE dev_log_erros SET resolvido=$v WHERE id=$id");
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'erros_limpar') {
        $modo = $_POST['modo'] ?? 'resolvidos';
        if ($modo === 'tudo')          $conn->query("TRUNCATE TABLE dev_log_erros");
        elseif ($modo === 'antigos')   $conn->query("DELETE FROM dev_log_erros WHERE COALESCE(atualizado_em,criado_em) < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        else                           $conn->query("DELETE FROM dev_log_erros WHERE resolvido = 1");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  AMEAÇAS
    // ══════════════════════════════════════════════════════════════════════
    if ($action === 'ameacas_listar') {
        $tem = $conn->query("SHOW TABLES LIKE 'dev_ameacas'");
        if (!$tem || !$tem->num_rows) { echo json_encode(['ok'=>true,'data'=>[],'resumo'=>[]]); exit; }

        $limit = max(1, min(300, intval($_POST['limit'] ?? 100)));
        $sev   = strtoupper(trim($_POST['severidade'] ?? ''));
        $tipo  = strtoupper(trim($_POST['tipo'] ?? ''));
        $ver_revisadas = ($_POST['revisadas'] ?? '0') === '1';

        $w = ['1=1'];
        if (in_array($sev, ['BAIXA','MEDIA','ALTA','CRITICA'], true)) $w[] = "severidade = '$sev'";
        if ($tipo !== '') $w[] = "tipo = '" . $conn->real_escape_string($tipo) . "'";
        if (!$ver_revisadas) $w[] = "revisado = 0";
        $where = 'WHERE ' . implode(' AND ', $w);

        $r = $conn->query("
            SELECT id, tipo, severidade, usuario_alvo, ip_rede, ip_local, navegador,
                   sistema, dispositivo, pagina, detalhe, ocorrencias, revisado, revisado_por,
                   DATE_FORMAT(primeira_em,'%d/%m/%Y %H:%i') AS primeira,
                   DATE_FORMAT(ultima_em,'%d/%m/%Y %H:%i')   AS ultima
            FROM dev_ameacas $where
            ORDER BY FIELD(severidade,'CRITICA','ALTA','MEDIA','BAIXA'), ultima_em DESC
            LIMIT $limit");
        $rows = []; if ($r) while ($x = $r->fetch_assoc()) $rows[] = $x;

        $res = $conn->query("
            SELECT
              SUM(revisado=0) AS abertas,
              SUM(severidade IN ('ALTA','CRITICA') AND revisado=0) AS graves,
              SUM(ultima_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS h24,
              COUNT(DISTINCT ip_rede) AS ips
            FROM dev_ameacas");
        $resumo = $res ? $res->fetch_assoc() : [];

        echo json_encode(['ok'=>true,'data'=>$rows,'resumo'=>$resumo]);
        exit;
    }

    if ($action === 'ameaca_revisar') {
        $id = intval($_POST['id'] ?? 0);
        $me = $conn->real_escape_string($_SESSION['usuario_logado'] ?? '');
        $conn->query("UPDATE dev_ameacas SET revisado=1, revisado_por='$me', revisado_em=NOW() WHERE id=$id");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  AUDITORIA DE SEGURANÇA — verificações que valem olhar de tempos em tempos
    // ══════════════════════════════════════════════════════════════════════
    if ($action === 'auditoria') {
        $itens = [];
        // $acao = [nome_da_acao, rótulo do botão] quando o problema tem
        // conserto de um clique. Apontar o problema sem oferecer a solução
        // faz o painel virar lista de reclamações.
        $add = function ($nome, $ok, $detalhe, $risco = 'MEDIO', $acao = null) use (&$itens) {
            $itens[] = compact('nome', 'ok', 'detalhe', 'risco', 'acao');
        };

        // 1) Senhas guardadas em texto puro. Hash bcrypt sempre começa com $2y$.
        $r = $conn->query("SELECT COUNT(*) c FROM usuarios WHERE senha NOT LIKE '\$2y\$%' AND senha NOT LIKE '\$2a\$%'");
        $n = $r ? (int)$r->fetch_assoc()['c'] : 0;
        $add('Senhas com hash', $n === 0,
            $n === 0 ? 'Todas as senhas estão cifradas.'
                     : "$n usuário(s) com senha legível no banco. Qualquer cópia do banco — "
                     . "inclusive o backup que vai para o Drive — expõe essas senhas.",
            'ALTO', $n > 0 ? ['corrigir_senhas', 'Cifrar agora'] : null);

        // 2) Contas com poder total.
        // UPPER/TRIM porque a coluna tem variações de caixa e espaço — a
        // comparação exata retornava 0 mesmo com contas DEV existindo.
        $r = $conn->query("SELECT COUNT(*) c FROM usuarios
                           WHERE UPPER(TRIM(classe_usuario))='DEV'
                             AND UPPER(TRIM(COALESCE(status,'ATIVO')))='ATIVO'");
        $n = $r ? (int)$r->fetch_assoc()['c'] : 0;
        $add('Contas DEV ativas', $n >= 1 && $n <= 2,
            $n === 0
                ? 'Nenhuma conta DEV ativa encontrada. Se você está usando este painel, '
                . 'a coluna classe_usuario provavelmente está gravada de forma diferente.'
                : "$n conta(s) com acesso total ao sistema."
                  . ($n > 2 ? ' Acesso total deveria ser exceção.' : ''),
            'ALTO');

        // 3) Contas que nunca acessaram ou sumiram
        $r = @$conn->query("SELECT COUNT(*) c FROM usuarios u WHERE u.status='ATIVO'
                            AND NOT EXISTS (SELECT 1 FROM historico_acessos h
                                            WHERE h.usuario=u.usuario
                                              AND h.login_em >= DATE_SUB(NOW(), INTERVAL 90 DAY))");
        $n = $r ? (int)$r->fetch_assoc()['c'] : 0;
        $add('Contas inativas', $n === 0,
            $n === 0 ? 'Todos os usuários ativos acessaram nos últimos 90 dias.'
                     : "$n conta(s) ativa(s) sem acesso há mais de 90 dias. Conta esquecida é porta aberta.",
            'MEDIO');

        // 4) Bloqueios de login em vigor
        $r = @$conn->query("SELECT COUNT(*) c FROM login_tentativas WHERE bloqueado_ate > NOW()");
        $n = $r ? (int)$r->fetch_assoc()['c'] : 0;
        $add('Bloqueios ativos', true, $n === 0 ? 'Nenhum bloqueio no momento.' : "$n bloqueio(s) em vigor.", 'BAIXO');

        // 5) Backup recente
        $r = @$conn->query("SELECT DATE_FORMAT(MAX(iniciado_em),'%d/%m/%Y %H:%i') d,
                                   TIMESTAMPDIFF(DAY, MAX(iniciado_em), NOW()) dias
                            FROM dev_backups WHERE situacao <> 'FALHA'");
        $b = $r ? $r->fetch_assoc() : null;
        $dias = $b && $b['dias'] !== null ? (int)$b['dias'] : null;
        $add('Backup recente', $dias !== null && $dias <= 8,
            $dias === null ? 'Nenhum backup registrado ainda.'
                           : "Último backup bem-sucedido em {$b['d']} ({$dias} dia(s) atrás).",
            'ALTO');

        // 6) Erros críticos em aberto
        $r = @$conn->query("SELECT COUNT(*) c FROM dev_log_erros WHERE nivel='CRITICAL' AND resolvido=0");
        $n = $r ? (int)$r->fetch_assoc()['c'] : 0;
        $add('Erros críticos', $n === 0, $n === 0 ? 'Nenhum erro crítico em aberto.' : "$n erro(s) crítico(s) sem tratamento.", 'ALTO');

        // 7) Sessões antigas ainda marcadas como online
        $r = @$conn->query("SELECT COUNT(*) c FROM usuarios_online
                            WHERE revogada=0 AND ultimo_acesso < DATE_SUB(NOW(), INTERVAL 12 HOUR)");
        $n = $r ? (int)$r->fetch_assoc()['c'] : 0;
        $add('Sessões esquecidas', $n === 0,
            $n === 0 ? 'Nenhuma sessão parada há mais de 12 horas.'
                     : "$n registro(s) de sessão sem atividade há mais de 12h ainda marcados "
                     . "como ativos. São de quem fechou o navegador sem clicar em Sair — a linha "
                     . "fica no banco para sempre. Não aparecem em \"Online agora\", que só "
                     . "considera os últimos 30 minutos.",
            'MEDIO', $n > 0 ? ['limpar_sessoes', 'Limpar sessões antigas'] : null);

        // 8) Conexão cifrada
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $add('Acesso por HTTPS', $https,
            $https ? 'O painel está sendo acessado por conexão cifrada.'
                   : 'Acesso sem HTTPS: senhas trafegam legíveis pela rede.',
            'ALTO');

        // 9) Captura de erros instalada
        $add('Captura de erros ativa', defined('DEV_CAPTURA_ATIVA'),
            defined('DEV_CAPTURA_ATIVA')
                ? 'Erros de PHP estão sendo registrados automaticamente.'
                : 'A diretiva auto_prepend_file não está ativa — só erros de JavaScript serão capturados.',
            'MEDIO');

        echo json_encode(['ok' => true, 'itens' => $itens]);
        exit;
    }

    /**
     * Converte senhas em texto puro para hash bcrypt.
     * Ninguém perde o acesso: o login já aceita as duas formas, então a senha
     * que a pessoa digita continua valendo — só deixa de estar legível no banco.
     */
    if ($action === 'corrigir_senhas') {
        $r = $conn->query("SELECT id, usuario, senha FROM usuarios
                           WHERE senha NOT LIKE '\$2y\$%' AND senha NOT LIKE '\$2a\$%'");
        $n = 0; $nomes = [];
        if ($r) {
            $st = $conn->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
            while ($u = $r->fetch_assoc()) {
                if (trim((string)$u['senha']) === '') continue;
                $hash = password_hash($u['senha'], PASSWORD_DEFAULT);
                if ($st) {
                    $st->bind_param('si', $hash, $u['id']);
                    if ($st->execute()) { $n++; $nomes[] = $u['usuario']; }
                }
            }
            if ($st) $st->close();
        }
        echo json_encode(['ok' => true,
            'msg' => $n . ' senha(s) cifrada(s)' . ($nomes ? ': ' . implode(', ', $nomes) : '.')]);
        exit;
    }

    /** Remove registros de sessão parados há mais de 12 horas */
    if ($action === 'limpar_sessoes') {
        $conn->query("DELETE FROM usuarios_online
                      WHERE ultimo_acesso < DATE_SUB(NOW(), INTERVAL 12 HOUR)");
        $n = $conn->affected_rows;
        echo json_encode(['ok' => true, 'msg' => "$n registro(s) de sessão removido(s)."]);
        exit;
    }

    // Toggle status (ATIVO <-> BLOQUEADO)
    if ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        // Não permite bloquear a própria conta DEV
        $me = $conn->real_escape_string($_SESSION['usuario_logado'] ?? '');
        $check = $conn->query("SELECT usuario, status FROM usuarios WHERE id=$id")->fetch_assoc();
        if (!$check) {
            echo json_encode(['ok' => false, 'msg' => 'Usuário não encontrado']);
            exit;
        }
        if ($check['usuario'] === $me) {
            echo json_encode(['ok' => false, 'msg' => 'Não é possível bloquear sua própria conta']);
            exit;
        }
        $novo = $check['status'] === 'BLOQUEADO' ? 'ATIVO' : 'BLOQUEADO';
        $conn->query("UPDATE usuarios SET status='$novo' WHERE id=$id");

        // Se bloqueando, também revoga sessões ativas desse usuário
        if ($novo === 'BLOQUEADO') {
            $u = $conn->real_escape_string($check['usuario']);
            $conn->query("UPDATE usuarios_online SET revogada = 1 WHERE usuario = '$u' AND revogada = 0");
        }

        echo json_encode(['ok' => true, 'novo_status' => $novo, 'usuario' => $check['usuario']]);
        exit;
    }

    // Listar autorização
    if ($action === 'listar_autorizacao') {
        $r = $conn->query("SELECT id, senha FROM autorizacao ORDER BY id ASC");
        $rows = [];
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // Editar autorização
    if ($action === 'editar_autorizacao') {
        $id    = intval($_POST['id']);
        $senha = $conn->real_escape_string($_POST['senha']);
        $conn->query("UPDATE autorizacao SET senha='$senha' WHERE id=$id");
        echo json_encode(['ok' => true]);
        exit;
    }

    // Métricas do banco
    if ($action === 'metricas') {
        $db = 'sistema_db';

        // ── Tamanho do banco ──────────────────────────────────────────────────
        $size_q  = $conn->query("SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) AS mb FROM information_schema.tables WHERE table_schema='$db'");
        $size_mb = $size_q ? ($size_q->fetch_assoc()['mb'] ?? 0) : 0;

        // ── Uptime MySQL ──────────────────────────────────────────────────────
        $uptime_q = $conn->query("SHOW STATUS LIKE 'Uptime'");
        $uptime_s = $uptime_q ? intval($uptime_q->fetch_assoc()['Value']) : 0;
        $uptime   = sprintf('%dd %02dh %02dm', floor($uptime_s/86400), floor(($uptime_s%86400)/3600), floor(($uptime_s%3600)/60));

        // ── Threads ───────────────────────────────────────────────────────────
        $conn_q  = $conn->query("SHOW STATUS LIKE 'Threads_connected'");
        $threads = $conn_q ? $conn_q->fetch_assoc()['Value'] : '?';

        // ── Dados relevantes do PatAsset ──────────────────────────────────────
        $total_cadastro   = $conn->query("SELECT COUNT(*) AS c FROM cadastro")->fetch_assoc()['c'] ?? 0;
        $total_ativos     = $conn->query("SELECT COUNT(*) AS c FROM cadastro WHERE status='ATIVO'")->fetch_assoc()['c'] ?? 0;
        $total_baixa      = $conn->query("SELECT COUNT(*) AS c FROM baixa_definitiva")->fetch_assoc()['c'] ?? 0;
        $total_mov        = $conn->query("SELECT COUNT(*) AS c FROM historico")->fetch_assoc()['c'] ?? 0;
        $total_usuarios   = $conn->query("SELECT COUNT(*) AS c FROM usuarios")->fetch_assoc()['c'] ?? 0;
        $total_bloqueados = $conn->query("SELECT COUNT(*) AS c FROM usuarios WHERE status='BLOQUEADO'")->fetch_assoc()['c'] ?? 0;
        $total_online     = $conn->query("SELECT COUNT(*) AS c FROM usuarios_online WHERE revogada=0 AND ultimo_acesso >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)")->fetch_assoc()['c'] ?? 0;

        // ── Último cadastro e última movimentação ─────────────────────────────
        $ult_cad_q = $conn->query("SELECT usuario_cadastro, DATE_FORMAT(periodo,'%d/%m/%Y %H:%i') AS dt FROM cadastro ORDER BY id DESC LIMIT 1");
        $ult_cad   = $ult_cad_q ? $ult_cad_q->fetch_assoc() : null;

        $ult_mov_q = $conn->query("SELECT usuario_mov, DATE_FORMAT(data,'%d/%m/%Y %H:%i') AS dt FROM historico ORDER BY id DESC LIMIT 1");
        $ult_mov   = $ult_mov_q ? $ult_mov_q->fetch_assoc() : null;

        // ── Tabela maior (mais pesada) ────────────────────────────────────────
        $maior_q = $conn->query("SELECT table_name, ROUND((data_length+index_length)/1024/1024,2) AS mb FROM information_schema.tables WHERE table_schema='$db' ORDER BY (data_length+index_length) DESC LIMIT 1");
        $maior   = $maior_q ? $maior_q->fetch_assoc() : null;

        // ── Acessos hoje ──────────────────────────────────────────────────────
        $acessos_hoje = 0;
        $ah_q = $conn->query("SHOW TABLES LIKE 'historico_acessos'");
        if ($ah_q && $ah_q->num_rows > 0) {
            $ah2 = $conn->query("SELECT COUNT(*) AS c FROM historico_acessos WHERE DATE(login_em) = CURDATE()");
            $acessos_hoje = $ah2 ? ($ah2->fetch_assoc()['c'] ?? 0) : 0;
        }

        echo json_encode([
            'ok'               => true,
            // Banco
            'size_mb'          => $size_mb,
            'uptime'           => $uptime,
            'threads'          => $threads,
            'php_version'      => phpversion(),
            'server_time'      => date('d/m/Y H:i:s'),
            'memory_usage'     => round(memory_get_usage(true)/1024/1024, 2) . ' MB',
            // PatAsset
            'total_cadastro'   => number_format($total_cadastro,  0, ',', '.'),
            'total_ativos'     => number_format($total_ativos,     0, ',', '.'),
            'total_baixa'      => number_format($total_baixa,      0, ',', '.'),
            'total_mov'        => number_format($total_mov,        0, ',', '.'),
            'total_usuarios'   => $total_usuarios,
            'total_bloqueados' => $total_bloqueados,
            'total_online'     => $total_online,
            'acessos_hoje'     => $acessos_hoje,
            'ult_cad'          => $ult_cad,
            'ult_mov'          => $ult_mov,
            'maior_tabela'     => $maior,
        ]);
        exit;
    }
    // Tabelas do banco
    if ($action === 'log_tabelas') {
        $db  = 'sistema_db';
        $r   = $conn->query("SELECT table_name, table_rows, ROUND((data_length+index_length)/1024,1) AS kb, update_time FROM information_schema.tables WHERE table_schema='$db' ORDER BY update_time DESC");
        $rows = [];
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // Usuários online
    if ($action === 'usuarios_online') {
        $my_sid = session_id();
        $r = $conn->query("
            SELECT session_id, usuario, classe_usuario, ip, ultimo_acesso, login_em
            FROM usuarios_online
            WHERE revogada = 0
              AND ultimo_acesso >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ORDER BY usuario, ultimo_acesso DESC
        ");
        if (!$r) {
            echo json_encode(['ok' => false, 'msg' => 'Erro na query: ' . $conn->error]);
            exit;
        }
        $por_usuario = [];
        while ($row = $r->fetch_assoc()) {
            $u = $row['usuario'];
            if (!isset($por_usuario[$u])) $por_usuario[$u] = $row;
        }
        $online = [];
        foreach ($por_usuario as $row) {
            $online[] = [
                'usuario'        => $row['usuario'],
                'classe_usuario' => $row['classe_usuario'],
                'ip'             => $row['ip'],
                'session'        => $row['session_id'],
                'ultimo_acesso'  => date('H:i:s', strtotime($row['ultimo_acesso'])),
                'login_em'       => date('H:i \dia d/m', strtotime($row['login_em'])),
                'eu'             => ($row['session_id'] === $my_sid),
            ];
        }
        usort($online, fn($a, $b) => strcmp($b['ultimo_acesso'], $a['ultimo_acesso']));
        echo json_encode(['ok' => true, 'data' => $online]);
        exit;
    }

    // Deslogar usuário individual
    if ($action === 'deslogar_usuario') {
        $sid     = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['session_id'] ?? '');
        $my_sid  = session_id();
        if (!$sid) { echo json_encode(['ok' => false, 'msg' => 'Session ID inválido']); exit; }
        if ($sid === $my_sid) { echo json_encode(['ok' => false, 'msg' => 'Não é possível deslogar sua própria sessão aqui.']); exit; }
        $stmt = $conn->prepare("UPDATE usuarios_online SET revogada = 1 WHERE session_id = ?");
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        echo json_encode(['ok' => true, 'revogado' => $affected > 0]);
        exit;
    }

    // Backup do banco
    if ($action === 'backup') {
        @set_time_limit(0);
        // O banco tem tabelas com anexos em BLOB; 256M não bastava nem com
        // leitura em fluxo. O consumo real é baixo, mas um único anexo grande
        // precisa caber na memória inteiro no momento da conversão para hexa.
        @ini_set('memory_limit', '512M');

        // Desliga output buffering para streaming direto
        while (ob_get_level()) ob_end_clean();

        require_once __DIR__ . '/backup_dump.php';

        $escopo  = trim($_POST['escopo'] ?? 'geral');
        $tabelas = backup_tabelas_do_escopo($conn, $escopo);

        if (!$tabelas) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Escopo inválido ou tabela inexistente.']);
            exit;
        }

        // Nome do arquivo indica o conteúdo: na hora de restaurar, seis meses
        // depois, "backup_sistema_db" não diz se tem tudo ou só uma parte.
        $rotulo = match (strtolower($escopo)) {
            'geral'    => 'completo',
            'patasset' => 'patasset',
            'lifetech' => 'lifetech',
            default    => 'tabela-' . preg_replace('/[^a-z0-9_]/i', '', $escopo),
        };
        $filename = 'backup_' . $rotulo . '_' . date('Ymd_His') . '.sql';

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');
        header('Pragma: no-cache');

        // Mesmo gerador do backup automático — ver backup_dump.php.
        backup_gerar_dump($conn, function (string $texto) {
            echo $texto;
            flush();
        }, $tabelas);
        exit;
    }

    // Lista de tabelas para o seletor de download avulso
    if ($action === 'backup_tabelas') {
        require_once __DIR__ . '/backup_dump.php';
        $saida = [];
        foreach (backup_todas_tabelas($conn) as $t) {
            $c = $conn->query("SELECT COUNT(*) n FROM `$t`");
            $saida[] = [
                'tabela' => $t,
                'grupo'  => backup_grupo_da_tabela($t),
                'linhas' => $c ? (int)$c->fetch_assoc()['n'] : 0,
            ];
        }
        echo json_encode(['ok' => true, 'tabelas' => $saida]);
        exit;
    }


    // Histórico de acessos
    if ($action === 'listar_acessos') {
        $limit  = max(1, min(500, intval($_POST['limit']  ?? 100)));
        $offset = max(0, intval($_POST['offset'] ?? 0));
        $filtro_usuario   = $conn->real_escape_string($_POST['filtro_usuario']   ?? '');
        $filtro_resultado = $conn->real_escape_string($_POST['filtro_resultado'] ?? '');

        // Detecta colunas existentes na tabela para montar SELECT seguro
        $cols_q   = $conn->query("SHOW COLUMNS FROM historico_acessos");
        $cols_ex  = [];
        if ($cols_q) while ($col = $cols_q->fetch_assoc()) $cols_ex[] = $col['Field'];

        $has_resultado = in_array('resultado', $cols_ex);
        $has_ip_local  = in_array('ip_local',  $cols_ex);
        $has_ip_rede   = in_array('ip_rede',   $cols_ex);
        $has_ua        = in_array('user_agent', $cols_ex);

        $where = "WHERE 1=1";
        if ($filtro_usuario)              $where .= " AND usuario LIKE '%$filtro_usuario%'";
        if ($filtro_resultado && $has_resultado) $where .= " AND resultado = '$filtro_resultado'";

        $total_q = $conn->query("SELECT COUNT(*) AS c FROM historico_acessos $where");
        $total   = $total_q ? (int)$total_q->fetch_assoc()['c'] : 0;

        $sel_resultado = $has_resultado ? "resultado"               : "'OK' AS resultado";
        $sel_ip_local  = $has_ip_local  ? "ip_local"                : "NULL AS ip_local";
        $sel_ip_rede   = $has_ip_rede   ? "ip_rede"                 : "NULL AS ip_rede";
        $sel_ua        = $has_ua        ? "user_agent"               : "NULL AS user_agent";

        $r = $conn->query("
            SELECT id, usuario,
                   COALESCE(permicao,'N/A')       AS permicao,
                   COALESCE(classe_usuario,'N/A') AS classe_usuario,
                   $sel_ip_rede,
                   $sel_ip_local,
                   $sel_ua,
                   $sel_resultado,
                   DATE_FORMAT(login_em, '%d/%m/%Y') AS data,
                   DATE_FORMAT(login_em, '%H:%i:%s')  AS hora
            FROM historico_acessos
            $where
            ORDER BY id DESC
            LIMIT $limit OFFSET $offset
        ");
        if (!$r) {
            echo json_encode(['ok' => false, 'msg' => 'Erro na query: ' . $conn->error]);
            exit;
        }
        $rows = [];
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        echo json_encode(['ok' => true, 'data' => $rows, 'total' => $total]);
        exit;
    }

    // Limpar histórico de acessos
    if ($action === 'limpar_acessos') {
        $conn->query("TRUNCATE TABLE historico_acessos");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── TIMELINE (múltiplas tabelas existentes) ────────────────────────────────
    if ($action === 'timeline_acoes') {
        $filtro_u = $conn->real_escape_string($_POST['filtro_usuario'] ?? '');
        $parts = [];

        // ── CADASTRO ──────────────────────────────────────────────────────────
        // Detecta colunas disponíveis
        $cols_cad = [];
        $rc = $conn->query("SHOW COLUMNS FROM cadastro");
        if ($rc) while ($cc = $rc->fetch_assoc()) $cols_cad[] = $cc['Field'];

        if (!empty($cols_cad)) {
            $has_uc  = in_array('usuario_cadastro', $cols_cad);
            $has_desc= in_array('descricao', $cols_cad);
            $has_tag = in_array('tag_antiga', $cols_cad);
            $has_per = in_array('periodo', $cols_cad);
            $has_tag2= in_array('tag', $cols_cad);

            $sel_uc   = $has_uc   ? 'usuario_cadastro' : "'' ";
            $sel_desc = $has_desc ? 'COALESCE(descricao,\'item\')' : "'item'";
            $sel_tag  = $has_tag  ? 'COALESCE(tag_antiga,\'\')' : ($has_tag2 ? 'COALESCE(tag,\'\')' : "''");
            $sel_ts   = $has_per  ? 'periodo' : (in_array('criado_em',$cols_cad) ? 'criado_em' : 'NOW()');
            $where_uc = ($has_uc && $filtro_u) ? "AND usuario_cadastro LIKE '%$filtro_u%'" : '';

            $r1 = $conn->query("SELECT 'CADASTRO' AS acao, 'PATRIMÔNIO' AS modulo, $sel_uc AS usuario, CONCAT('Cadastrou: ',$sel_desc,' [',$sel_tag,']') AS descricao, $sel_ts AS ts FROM cadastro WHERE 1=1 $where_uc ORDER BY id DESC LIMIT 100");
            if ($r1) while ($row = $r1->fetch_assoc()) $parts[] = $row;
        }

        // ── HISTORICO (movimentações) ─────────────────────────────────────────
        $cols_his = [];
        $rh = $conn->query("SHOW COLUMNS FROM historico");
        if ($rh) while ($ch = $rh->fetch_assoc()) $cols_his[] = $ch['Field'];

        if (!empty($cols_his)) {
            $has_um   = in_array('usuario_mov',      $cols_his);
            $has_htag = in_array('tag',              $cols_his);
            $has_ud   = in_array('unidade_destino',  $cols_his);
            $has_sd   = in_array('setor_destino',    $cols_his);
            $has_data = in_array('data',             $cols_his);

            $sel_um  = $has_um   ? 'usuario_mov'                           : "''";
            $sel_htag= $has_htag ? "COALESCE(tag,'')"                      : "''";
            $sel_ud  = $has_ud   ? "COALESCE(unidade_destino,'')"          : "''";
            $sel_sd  = $has_sd   ? "COALESCE(setor_destino,'')"            : "''";
            $sel_dts = $has_data ? 'data'                                  : (in_array('criado_em',$cols_his)?'criado_em':'NOW()');
            $where_um = ($has_um && $filtro_u) ? "AND usuario_mov LIKE '%$filtro_u%'" : '';

            $r2 = $conn->query("SELECT 'MOVIMENTACAO' AS acao, 'PATRIMÔNIO' AS modulo, $sel_um AS usuario, CONCAT('Movimentou: ',$sel_htag,' → ',$sel_ud,' / ',$sel_sd) AS descricao, $sel_dts AS ts FROM historico WHERE 1=1 $where_um ORDER BY id DESC LIMIT 100");
            if ($r2) while ($row = $r2->fetch_assoc()) $parts[] = $row;
        }

        // ── BAIXA DEFINITIVA ──────────────────────────────────────────────────
        $colB = $conn->query("SHOW COLUMNS FROM baixa_definitiva LIKE 'usuario_baixa'");
        if ($colB && $colB->num_rows > 0) {
            $wbu = $filtro_u ? "AND COALESCE(usuario_baixa,responsavel_baixa,'') LIKE '%$filtro_u%'" : '';
            $r3 = $conn->query("SELECT 'BAIXA' AS acao, 'PATRIMÔNIO' AS modulo, COALESCE(usuario_baixa,responsavel_baixa,'sistema') AS usuario, CONCAT('Deu baixa: ',COALESCE(descricao_item,'item')) AS descricao, data_baixa AS ts FROM baixa_definitiva WHERE 1=1 $wbu ORDER BY id DESC LIMIT 50");
            if ($r3) while ($row = $r3->fetch_assoc()) $parts[] = $row;
        }

        // ── HISTORICO_ACESSOS (logins) ────────────────────────────────────────
        $chk_ha = $conn->query("SHOW TABLES LIKE 'historico_acessos'");
        if ($chk_ha && $chk_ha->num_rows > 0) {
            $has_resultado = ($conn->query("SHOW COLUMNS FROM historico_acessos LIKE 'resultado'")->num_rows ?? 0) > 0;
            $has_ip_rede   = ($conn->query("SHOW COLUMNS FROM historico_acessos LIKE 'ip_rede'")->num_rows ?? 0) > 0;
            $where_res = $has_resultado ? "WHERE resultado='OK'" : "WHERE 1=1";
            $sel_ip    = $has_ip_rede   ? "COALESCE(ip_rede,'?')" : "'?'";
            if ($filtro_u) $where_res .= " AND usuario LIKE '%$filtro_u%'";
            $r4 = $conn->query("SELECT 'LOGIN' AS acao, 'SISTEMA' AS modulo, usuario, CONCAT('Login — IP: ',$sel_ip) AS descricao, login_em AS ts FROM historico_acessos $where_res ORDER BY id DESC LIMIT 100");
            if ($r4) while ($row = $r4->fetch_assoc()) $parts[] = $row;
        }

        usort($parts, fn($a, $b) => strcmp($b['ts'] ?? '', $a['ts'] ?? ''));
        $parts = array_slice($parts, 0, 200);
        foreach ($parts as &$row) {
            $row['data'] = $row['ts'] ? date('d/m/Y', strtotime($row['ts'])) : '—';
            $row['hora'] = $row['ts'] ? date('H:i:s',  strtotime($row['ts'])) : '—';
        }
        echo json_encode(['ok' => true, 'data' => $parts]);
        exit;
    }


    // ── LOG DE ERROS ───────────────────────────────────────────────────────────
    if ($action === 'listar_log_erros') {
        $limit  = max(1, min(500, intval($_POST['limit']  ?? 200)));
        $offset = max(0, intval($_POST['offset'] ?? 0));
        $has = ($conn->query("SHOW TABLES LIKE 'dev_log_erros'")->num_rows ?? 0) > 0;
        if (!$has) { echo json_encode(['ok'=>true,'data'=>[],'total'=>0]); exit; }
        $filtro_n = $conn->real_escape_string($_POST['filtro_nivel'] ?? '');
        $w = "WHERE 1=1"; if ($filtro_n) $w .= " AND nivel='$filtro_n'";
        $total = (int)($conn->query("SELECT COUNT(*) AS c FROM dev_log_erros $w")->fetch_assoc()['c'] ?? 0);
        $r = $conn->query("SELECT id,nivel,arquivo,mensagem,usuario,ip,DATE_FORMAT(criado_em,'%d/%m/%Y') AS data,DATE_FORMAT(criado_em,'%H:%i:%s') AS hora FROM dev_log_erros $w ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $rows=[]; if ($r) while ($row=$r->fetch_assoc()) $rows[]=$row;
        echo json_encode(['ok'=>true,'data'=>$rows,'total'=>$total]); exit;
    }
    if ($action === 'limpar_log_erros') {
        if (($conn->query("SHOW TABLES LIKE 'dev_log_erros'")->num_rows ?? 0) > 0) $conn->query("TRUNCATE TABLE dev_log_erros");
        echo json_encode(['ok'=>true]); exit;
    }

    // ── INVASÕES ───────────────────────────────────────────────────────────────
    if ($action === 'listar_invasoes') {
        $limit  = max(1, min(500, intval($_POST['limit']  ?? 200)));
        $offset = max(0, intval($_POST['offset'] ?? 0));
        $has = ($conn->query("SHOW TABLES LIKE 'dev_invasoes'")->num_rows ?? 0) > 0;
        if (!$has) { echo json_encode(['ok'=>true,'data'=>[],'total'=>0]); exit; }
        $filtro_t = $conn->real_escape_string($_POST['filtro_tipo'] ?? '');
        $w = "WHERE 1=1"; if ($filtro_t) $w .= " AND tipo='$filtro_t'";
        $total = (int)($conn->query("SELECT COUNT(*) AS c FROM dev_invasoes $w")->fetch_assoc()['c'] ?? 0);
        $r = $conn->query("SELECT id,arquivo,usuario_tentativa,ip,user_agent,tipo,DATE_FORMAT(criado_em,'%d/%m/%Y') AS data,DATE_FORMAT(criado_em,'%H:%i:%s') AS hora FROM dev_invasoes $w ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $rows=[]; if ($r) while ($row=$r->fetch_assoc()) $rows[]=$row;
        echo json_encode(['ok'=>true,'data'=>$rows,'total'=>$total]); exit;
    }
    if ($action === 'limpar_invasoes') {
        if (($conn->query("SHOW TABLES LIKE 'dev_invasoes'")->num_rows ?? 0) > 0) $conn->query("TRUNCATE TABLE dev_invasoes");
        echo json_encode(['ok'=>true]); exit;
    }

    // ── ESTATÍSTICAS POR USUÁRIO ───────────────────────────────────────────────
    if ($action === 'estatisticas_usuarios') {
        // Acumula por chave em LOWER para unificar "Eduardo" == "EDUARDO" == "eduardo"
        $logins=$cadastros=$movs=$baixas=[];

        $chk_ha2 = $conn->query("SHOW TABLES LIKE 'historico_acessos'");
        if ($chk_ha2 && $chk_ha2->num_rows>0) {
            $has_res2 = ($conn->query("SHOW COLUMNS FROM historico_acessos LIKE 'resultado'")->num_rows ?? 0) > 0;
            $where_ha = $has_res2 ? "WHERE resultado='OK'" : "WHERE 1=1";
            $r=$conn->query("SELECT LOWER(usuario) AS u, COUNT(*) AS c FROM historico_acessos $where_ha GROUP BY LOWER(usuario)");
            if ($r) while ($row=$r->fetch_assoc()) $logins[$row['u']] = ($logins[$row['u']] ?? 0) + (int)$row['c'];
        }
        $r=$conn->query("SELECT LOWER(usuario_cadastro) AS u, COUNT(*) AS c FROM cadastro GROUP BY LOWER(usuario_cadastro)");
        if ($r) while ($row=$r->fetch_assoc()) $cadastros[$row['u']] = ($cadastros[$row['u']] ?? 0) + (int)$row['c'];

        $r=$conn->query("SELECT LOWER(usuario_mov) AS u, COUNT(*) AS c FROM historico GROUP BY LOWER(usuario_mov)");
        if ($r) while ($row=$r->fetch_assoc()) $movs[$row['u']] = ($movs[$row['u']] ?? 0) + (int)$row['c'];

        $colB=$conn->query("SHOW COLUMNS FROM baixa_definitiva LIKE 'usuario_baixa'");
        if ($colB&&$colB->num_rows>0) {
            $r=$conn->query("SELECT LOWER(usuario_baixa) AS u, COUNT(*) AS c FROM baixa_definitiva GROUP BY LOWER(usuario_baixa)");
            if ($r) while ($row=$r->fetch_assoc()) $baixas[$row['u']] = ($baixas[$row['u']] ?? 0) + (int)$row['c'];
        }

        // Monta lista unificada com nome em minúsculo como chave canônica
        $all = array_unique(array_merge(array_keys($logins), array_keys($cadastros), array_keys($movs), array_keys($baixas)));
        $result=[];
        foreach ($all as $u) {
            if (!$u) continue;
            $total = ($logins[$u]??0)+($cadastros[$u]??0)+($movs[$u]??0)+($baixas[$u]??0);
            $result[]=['usuario'=>$u,'logins'=>$logins[$u]??0,'cadastros'=>$cadastros[$u]??0,'movs'=>$movs[$u]??0,'baixas'=>$baixas[$u]??0,'total'=>$total];
        }
        usort($result,fn($a,$b)=>$b['total']<=>$a['total']);
        echo json_encode(['ok'=>true,'data'=>$result]); exit;
    }

    // ── MANUTENÇÃO ─────────────────────────────────────────────────────────────
    if ($action === 'status_manutencao') {
        $flag=__DIR__.'/manutencao.flag'; $ativa=file_exists($flag);
        echo json_encode(['ok'=>true,'ativa'=>$ativa,'msg'=>$ativa?trim(file_get_contents($flag)):'']); exit;
    }
    if ($action === 'toggle_manutencao') {
        $flag=__DIR__.'/manutencao.flag';
        $msg=trim($_POST['msg_manutencao']??'O sistema estará de volta em breve.');
        if (file_exists($flag)) { unlink($flag); echo json_encode(['ok'=>true,'ativa'=>false]); }
        else { file_put_contents($flag,$msg?:'O sistema estará de volta em breve.'); echo json_encode(['ok'=>true,'ativa'=>true]); }
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Ação inválida']);
    exit;

    } catch (Throwable $e) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'Erro interno: ' . $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Painel DEV — PatAsset</title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="shortcut icon" href="logo_1.png">
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then(regs => regs.forEach(r => r.unregister()));
}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Geist+Mono:wght@300;400;500&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══════════════════════════════════════════
   TOKENS
═══════════════════════════════════════════ */
:root {
  --bg-root:    #0a0a0a;
  --bg-nav:     #111111;
  --bg-card:    #161616;
  --bg-card2:   #1c1c1c;
  --bg-input:   #1a1a1a;
  --bg-hover:   #202020;
  --border:     #2a2a2a;
  --border-md:  #333333;
  --border-hi:  #444444;

  --txt-1:      #f0f0f0;
  --txt-2:      #a0a0a0;
  --txt-3:      #606060;
  --txt-4:      #404040;

  --blue:       #3b82f6;
  --blue-dim:   rgba(59,130,246,.12);
  --blue-hi:    #60a5fa;
  --green:      #22c55e;
  --green-dim:  rgba(34,197,94,.12);
  --red:        #ef4444;
  --red-dim:    rgba(239,68,68,.1);
  --amber:      #f59e0b;
  --amber-dim:  rgba(245,158,11,.1);
  --purple:     #a855f7;
  --purple-dim: rgba(168,85,247,.1);
  --orange:     #f97316;
  --orange-dim: rgba(249,115,22,.1);

  --radius-sm:  6px;
  --radius:     10px;
  --radius-lg:  14px;
  --shadow:     0 1px 3px rgba(0,0,0,.5), 0 1px 2px rgba(0,0,0,.4);
  --shadow-lg:  0 8px 32px rgba(0,0,0,.6);

  --font-ui:    'Outfit', sans-serif;
  --font-mono:  'Geist Mono', monospace;
  --nav-w:      220px;
  --header-h:   56px;
  --transition: .18s ease;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: var(--font-ui);
  background: var(--bg-root);
  color: var(--txt-1);
  min-height: 100vh;
  display: flex;
  font-size: 14px;
  line-height: 1.5;
}

/* ═══════════════════════════════════════════
   SIDEBAR NAV
═══════════════════════════════════════════ */
.sidenav {
  width: var(--nav-w);
  min-height: 100vh;
  background: var(--bg-nav);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0; left: 0;
  z-index: 200;
  flex-shrink: 0;
}
.sidenav-brand {
  padding: 20px 18px 16px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 10px;
}
.brand-icon {
  width: 32px; height: 32px;
  background: var(--blue);
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
  color: #fff;
  flex-shrink: 0;
}
.brand-name  { font-size: 13px; font-weight: 600; color: var(--txt-1); letter-spacing: .01em; }
.brand-sub   { font-size: 10px; color: var(--txt-3); letter-spacing: .04em; text-transform: uppercase; margin-top: 1px; font-family: var(--font-mono); }
.sidenav-section { font-size: 10px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--txt-4); padding: 18px 18px 6px; }
.nav-link {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 18px;
  color: var(--txt-2);
  text-decoration: none;
  font-size: 13.5px;
  font-weight: 400;
  cursor: pointer;
  border: none;
  background: none;
  width: 100%;
  text-align: left;
  position: relative;
  transition: background var(--transition), color var(--transition);
}
.nav-link i { width: 16px; text-align: center; font-size: 13px; flex-shrink: 0; }
.nav-link:hover { background: var(--bg-hover); color: var(--txt-1); }
.nav-link.active { color: var(--blue-hi); background: var(--blue-dim); }
.nav-link.active::before {
  content: '';
  position: absolute;
  left: 0; top: 0; bottom: 0;
  width: 2px;
  background: var(--blue);
  border-radius: 0 2px 2px 0;
}
.sidenav-footer { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
.user-pill {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  background: var(--bg-card2);
  border: 1px solid var(--border);
}
.user-avatar {
  width: 28px; height: 28px;
  border-radius: 50%;
  background: var(--blue-dim);
  border: 1px solid var(--blue);
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; color: var(--blue-hi);
  font-family: var(--font-mono); font-weight: 500;
  flex-shrink: 0;
}
.user-info  { flex: 1; min-width: 0; }
.user-name  { font-size: 12px; font-weight: 500; color: var(--txt-1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role  { font-size: 10px; color: var(--txt-3); font-family: var(--font-mono); }

/* ═══════════════════════════════════════════
   MAIN
═══════════════════════════════════════════ */
.main { margin-left: var(--nav-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }
.topbar {
  height: var(--header-h);
  border-bottom: 1px solid var(--border);
  background: var(--bg-nav);
  display: flex; align-items: center;
  padding: 0 28px; gap: 12px;
  position: sticky; top: 0; z-index: 100;
}
.topbar-title { font-size: 15px; font-weight: 600; color: var(--txt-1); flex: 1; }
.topbar-meta  { font-family: var(--font-mono); font-size: 11px; color: var(--txt-3); display: flex; align-items: center; gap: 6px; }
.dot-live     { width: 6px; height: 6px; border-radius: 50%; background: var(--green); box-shadow: 0 0 6px var(--green); animation: pulse-dot 2.5s infinite; }
@keyframes pulse-dot { 0%,100%{opacity:1;box-shadow:0 0 6px var(--green)} 50%{opacity:.5;box-shadow:0 0 3px var(--green)} }
.topbar-clock { font-family: var(--font-mono); font-size: 12px; color: var(--txt-2); }
.btn-logout   { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: var(--red-dim); border: 1px solid rgba(239,68,68,.25); border-radius: var(--radius-sm); color: var(--red); font-size: 12px; font-family: var(--font-ui); font-weight: 500; cursor: pointer; text-decoration: none; transition: background var(--transition), border-color var(--transition); }
.btn-logout:hover { background: rgba(239,68,68,.2); border-color: rgba(239,68,68,.4); }
.content { flex: 1; padding: 28px; }
.page { display: none; }
.page.active { display: block; animation: fadeup .2s ease; }
@keyframes fadeup { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }

/* ═══════════════════════════════════════════
   STAT CARDS
═══════════════════════════════════════════ */
.stats-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px; }
.stat-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; transition: border-color var(--transition); }
.stat-card:hover { border-color: var(--border-md); }
.stat-label { font-size: 11px; font-weight: 500; color: var(--txt-3); letter-spacing: .04em; text-transform: uppercase; margin-bottom: 10px; display: flex; align-items: center; gap: 7px; }
.stat-label i { font-size: 11px; }
.stat-value { font-size: 30px; font-weight: 700; color: var(--txt-1); line-height: 1; letter-spacing: -.02em; font-family: var(--font-mono); }
.stat-sub   { font-size: 11px; color: var(--txt-3); margin-top: 6px; }
.stat-insight { font-size: 11px; margin-top: 8px; padding: 5px 8px; border-radius: 5px; line-height: 1.4; min-height: 14px; transition: all .3s ease; }
.insight-ok     { background: rgba(34,197,94,.1);  color: var(--green); }
.insight-warn   { background: rgba(245,158,11,.1); color: var(--amber); }
.insight-danger { background: rgba(239,68,68,.1);  color: var(--red); }
.insight-info   { background: rgba(59,130,246,.1); color: var(--blue); }
.insight-purple { background: rgba(168,85,247,.1); color: var(--purple); }

/* ═══════════════════════════════════════════
   CARD
═══════════════════════════════════════════ */
.card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); margin-bottom: 20px; overflow: hidden; }
.card-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--border); gap: 12px; }
.card-title  { font-size: 13px; font-weight: 600; color: var(--txt-1); display: flex; align-items: center; gap: 8px; }
.card-title i { color: var(--txt-3); font-size: 13px; }
.card-actions { display: flex; gap: 8px; align-items: center; }
.card-body    { padding: 20px; }

/* ═══════════════════════════════════════════
   BUTTONS
═══════════════════════════════════════════ */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 500; font-family: var(--font-ui); cursor: pointer; border: 1px solid transparent; transition: all var(--transition); text-decoration: none; white-space: nowrap; }
.btn i { font-size: 11px; }
.btn-primary { background: var(--blue); color: #fff; border-color: var(--blue); }
.btn-primary:hover { background: var(--blue-hi); border-color: var(--blue-hi); }
.btn-ghost   { background: var(--bg-card2); color: var(--txt-2); border-color: var(--border); }
.btn-ghost:hover { background: var(--bg-hover); color: var(--txt-1); border-color: var(--border-md); }
.btn-green   { background: var(--green-dim); color: var(--green); border-color: rgba(34,197,94,.25); }
.btn-green:hover { background: rgba(34,197,94,.2); }
.btn-red     { background: var(--red-dim); color: var(--red); border-color: rgba(239,68,68,.25); }
.btn-red:hover { background: rgba(239,68,68,.2); }
.btn-amber   { background: var(--amber-dim); color: var(--amber); border-color: rgba(245,158,11,.25); }
.btn-amber:hover { background: rgba(245,158,11,.2); }
.btn-orange  { background: var(--orange-dim); color: var(--orange); border-color: rgba(249,115,22,.25); }
.btn-orange:hover { background: rgba(249,115,22,.2); }
.btn-sm { padding: 5px 10px; font-size: 11px; }
.btn-xs { padding: 3px 8px; font-size: 10px; border-radius: 4px; }

/* ═══════════════════════════════════════════
   TABLE
═══════════════════════════════════════════ */
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th { padding: 10px 14px; text-align: left; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--txt-3); border-bottom: 1px solid var(--border); background: var(--bg-card2); white-space: nowrap; }
tbody td { padding: 11px 14px; border-bottom: 1px solid var(--border); color: var(--txt-2); font-size: 13px; vertical-align: middle; }
tbody tr:last-child td { border-bottom: none; }
tbody tr { transition: background var(--transition); }
tbody tr:hover td { background: var(--bg-hover); }
tbody tr.row-bloqueado td { opacity: .65; }
tbody tr.row-bloqueado { background: rgba(239,68,68,.03); }

.mono { font-family: var(--font-mono); font-size: 12px; }

/* Badge */
.badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; font-family: var(--font-mono); letter-spacing: .02em; }
.badge-blue   { background: var(--blue-dim); color: var(--blue-hi); }
.badge-green  { background: var(--green-dim); color: var(--green); }
.badge-amber  { background: var(--amber-dim); color: var(--amber); }
.badge-red    { background: var(--red-dim); color: var(--red); }
.badge-purple { background: var(--purple-dim); color: var(--purple); }
.badge-grey   { background: rgba(255,255,255,.06); color: var(--txt-3); }
.badge-orange { background: var(--orange-dim); color: var(--orange); }

/* Status badge especial com pulsação */
.badge-status-ativo { background: var(--green-dim); color: var(--green); display: inline-flex; align-items: center; gap: 5px; }
.badge-status-bloqueado { background: var(--red-dim); color: var(--red); display: inline-flex; align-items: center; gap: 5px; }
.status-dot { width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
.status-dot-green { background: var(--green); box-shadow: 0 0 4px var(--green); animation: pulse-dot 2.5s infinite; }
.status-dot-red   { background: var(--red); }

/* Senha toggle */
.senha-cell { display: flex; align-items: center; gap: 8px; }
.senha-val { font-family: var(--font-mono); font-size: 11px; color: var(--txt-3); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.senha-val.visible { color: var(--amber); }
.btn-eye { background: none; border: none; color: var(--txt-4); cursor: pointer; padding: 3px 5px; border-radius: 4px; font-size: 12px; transition: color var(--transition), background var(--transition); flex-shrink: 0; }
.btn-eye:hover { color: var(--txt-2); background: var(--bg-hover); }
.btn-eye.active { color: var(--amber); }
.toggle-all-senha { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; color: var(--txt-3); cursor: pointer; padding: 3px 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg-card2); transition: all var(--transition); }
.toggle-all-senha:hover { color: var(--txt-2); border-color: var(--border-md); }

/* Search bar */
.search-wrap { position: relative; flex: 1; }
.search-wrap i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--txt-4); font-size: 12px; pointer-events: none; }
.search-input { width: 100%; padding: 7px 12px 7px 32px; background: var(--bg-input); border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--txt-1); font-size: 13px; font-family: var(--font-ui); outline: none; transition: border-color var(--transition); }
.search-input:focus { border-color: var(--border-hi); }
.search-input::placeholder { color: var(--txt-4); }

/* ═══════════════════════════════════════════
   TOGGLE STATUS SWITCH
═══════════════════════════════════════════ */
.status-toggle-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 10px; font-weight: 700;
  font-family: var(--font-mono);
  cursor: pointer;
  border: 1px solid transparent;
  transition: all var(--transition);
  letter-spacing: .04em;
  user-select: none;
}
.status-toggle-btn.ativo {
  background: rgba(34,197,94,.15);
  color: var(--green);
  border-color: rgba(34,197,94,.3);
}
.status-toggle-btn.ativo:hover {
  background: rgba(239,68,68,.15);
  color: var(--red);
  border-color: rgba(239,68,68,.3);
}
.status-toggle-btn.bloqueado {
  background: rgba(239,68,68,.15);
  color: var(--red);
  border-color: rgba(239,68,68,.3);
}
.status-toggle-btn.bloqueado:hover {
  background: rgba(34,197,94,.15);
  color: var(--green);
  border-color: rgba(34,197,94,.3);
}
.status-toggle-btn:disabled { opacity: .5; cursor: default; }

/* ═══════════════════════════════════════════
   ONLINE CARDS
═══════════════════════════════════════════ */
.online-grid { display: flex; flex-wrap: wrap; gap: 12px; }
.online-card { display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: var(--bg-card2); border: 1px solid var(--border); border-radius: var(--radius); min-width: 220px; transition: border-color var(--transition); animation: fadeup .25s ease; }
.online-card:hover { border-color: var(--border-md); }
.online-card.me   { border-color: rgba(59,130,246,.3); background: var(--blue-dim); }
.online-avatar    { width: 34px; height: 34px; border-radius: 50%; background: var(--bg-card); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; color: var(--txt-2); flex-shrink: 0; border: 1px solid var(--border-md); font-family: var(--font-mono); text-transform: uppercase; }
.online-info      { flex: 1; min-width: 0; }
.online-name      { font-size: 13px; font-weight: 500; color: var(--txt-1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.online-meta      { font-size: 11px; color: var(--txt-3); font-family: var(--font-mono); display: flex; align-items: center; gap: 5px; margin-top: 2px; }
.dot-online       { width: 6px; height: 6px; border-radius: 50%; background: var(--green); box-shadow: 0 0 5px var(--green); flex-shrink: 0; animation: pulse-dot 2.5s infinite; }
.btn-deslogar     { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; background: var(--red-dim); border: 1px solid rgba(239,68,68,.2); border-radius: var(--radius-sm); color: var(--red); font-size: 11px; font-weight: 500; font-family: var(--font-ui); cursor: pointer; transition: all var(--transition); flex-shrink: 0; }
.btn-deslogar:hover { background: rgba(239,68,68,.22); border-color: rgba(239,68,68,.4); }
.btn-deslogar:disabled { opacity: .4; cursor: default; }
.online-empty     { padding: 32px; text-align: center; color: var(--txt-4); font-size: 13px; width: 100%; }

/* ═══════════════════════════════════════════
   MODAL
═══════════════════════════════════════════ */
.overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.75); backdrop-filter: blur(6px); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.overlay.open { display: flex; }
.modal { background: var(--bg-card); border: 1px solid var(--border-md); border-radius: var(--radius-lg); width: 100%; max-width: 480px; box-shadow: var(--shadow-lg); overflow: hidden; animation: modal-in .2s ease; }
@keyframes modal-in { from{opacity:0;transform:scale(.97) translateY(6px)} to{opacity:1;transform:none} }
.modal-header  { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
.modal-title   { font-size: 14px; font-weight: 600; color: var(--txt-1); display: flex; align-items: center; gap: 8px; }
.modal-close   { width: 28px; height: 28px; background: var(--bg-card2); border: 1px solid var(--border); border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--txt-3); font-size: 14px; transition: all var(--transition); }
.modal-close:hover { color: var(--red); border-color: rgba(239,68,68,.3); background: var(--red-dim); }
.modal-body    { padding: 20px; }
.modal-footer  { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; }

/* Alerta de bloqueio no modal */
.bloqueado-alert {
  display: none;
  align-items: center; gap: 10px;
  padding: 10px 14px;
  background: rgba(239,68,68,.08);
  border: 1px solid rgba(239,68,68,.2);
  border-radius: var(--radius-sm);
  margin-bottom: 16px;
  font-size: 12px; color: var(--red);
}
.bloqueado-alert.show { display: flex; }
.bloqueado-alert i { flex-shrink: 0; }

/* Form fields */
.field { margin-bottom: 14px; }
.field:last-child { margin-bottom: 0; }
.field label { display: block; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--txt-3); margin-bottom: 6px; }
.field input, .field select { width: 100%; padding: 8px 12px; background: var(--bg-input); border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--txt-1); font-size: 13px; font-family: var(--font-ui); outline: none; transition: border-color var(--transition); }
.field input:focus, .field select:focus { border-color: var(--blue); }
.field select option { background: var(--bg-input); }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

/* Password strength */
.pw-strength { height: 3px; border-radius: 2px; margin-top: 6px; transition: all .3s ease; background: var(--border); overflow: hidden; }
.pw-strength-bar { height: 100%; border-radius: 2px; transition: all .3s ease; width: 0; }
.pw-hint { font-size: 10px; color: var(--txt-4); margin-top: 4px; }

/* ═══════════════════════════════════════════
   INFO ROWS
═══════════════════════════════════════════ */
.info-two  { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.info-list { display: flex; flex-direction: column; }
.info-row  { display: flex; align-items: center; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.info-row:last-child { border-bottom: none; }
.info-row-k { color: var(--txt-3); }
.info-row-v { color: var(--txt-1); font-family: var(--font-mono); font-size: 12px; }

/* ═══════════════════════════════════════════
   TOAST
═══════════════════════════════════════════ */
#toast { position: fixed; bottom: 24px; right: 24px; z-index: 9000; padding: 11px 18px; background: var(--bg-card2); border: 1px solid var(--border-md); border-radius: var(--radius); font-size: 13px; color: var(--txt-1); box-shadow: var(--shadow-lg); display: flex; align-items: center; gap: 10px; opacity: 0; transform: translateY(10px); transition: all .25s ease; pointer-events: none; max-width: 320px; }
#toast.show  { opacity: 1; transform: translateY(0); }
#toast .toast-icon { font-size: 14px; flex-shrink: 0; }
#toast.toast-ok   { border-left: 3px solid var(--green); }
#toast.toast-err  { border-left: 3px solid var(--red); }
#toast.toast-info { border-left: 3px solid var(--blue); }
#toast.toast-warn { border-left: 3px solid var(--amber); }

/* Scrollbar */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-md); border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: var(--border-hi); }

/* ═══════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════ */
@media (max-width: 900px) {
  :root { --nav-w: 0px; }
  .sidenav { transform: translateX(-220px); transition: transform .25s; width: 220px; }
  .sidenav.open { transform: translateX(0); }
  .main { margin-left: 0; }
  .mob-toggle { display: flex !important; }
  .info-two  { grid-template-columns: 1fr; }
  .field-row { grid-template-columns: 1fr; }
}
.mob-toggle { display: none; width: 32px; height: 32px; border-radius: var(--radius-sm); background: var(--bg-card2); border: 1px solid var(--border); align-items: center; justify-content: center; color: var(--txt-2); cursor: pointer; font-size: 15px; }

/* ═══════════════════════════════════════════
   LOG TABS
═══════════════════════════════════════════ */
.log-tab { padding:8px 16px;background:none;border:none;border-bottom:2px solid transparent;color:var(--txt-3);font-size:13px;font-family:var(--font-ui);cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all var(--transition);margin-bottom:-1px; }
.log-tab:hover { color:var(--txt-1); }
.log-tab.active { color:var(--blue-hi);border-bottom-color:var(--blue); }
.log-tab i { font-size:12px; }
.prod-bar-wrap { display:flex;align-items:center;gap:8px; }
.prod-bar      { height:5px;border-radius:3px;background:var(--border);flex:1;overflow:hidden;min-width:60px; }
.prod-bar-fill { height:100%;border-radius:3px;transition:width .4s ease; }

/* ═══════════════════════════════════════════
   ERROS / AMEAÇAS / BACKUPS / AUDITORIA
═══════════════════════════════════════════ */
.nav-badge{margin-left:auto;background:var(--red);color:#fff;font-size:10px;font-weight:700;
           padding:1px 7px;border-radius:10px;font-family:var(--font-mono)}

.filtros-linha{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.filtros-linha select,.filtros-linha input[type=text]{padding:7px 11px;background:var(--bg-input);
  border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--txt-2);
  font-size:12px;font-family:var(--font-ui);outline:none}
.filtros-linha label{font-size:12px;color:var(--txt-3);display:flex;align-items:center;gap:6px;cursor:pointer}

/* Cartão de erro / ameaça */
.ev{border:1px solid var(--border);border-left-width:3px;border-radius:var(--radius);
    padding:13px 16px;margin-bottom:9px;background:var(--bg-card2)}
.ev-CRITICAL,.ev-CRITICA{border-left-color:var(--red)}
.ev-ERROR,.ev-ALTA{border-left-color:#f97316}
.ev-WARNING,.ev-MEDIA{border-left-color:var(--amber)}
.ev-INFO,.ev-BAIXA{border-left-color:var(--blue)}
.ev-resolvido{opacity:.5}
.ev-topo{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:7px}
.ev-msg{font-size:13px;color:var(--txt-1);font-family:var(--font-mono);word-break:break-word;
        line-height:1.55;margin-bottom:7px}
.ev-meta{display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:var(--txt-3)}
.ev-meta span{display:inline-flex;align-items:center;gap:5px}
.ev-meta i{font-size:10px;opacity:.7}
.ev-acoes{margin-left:auto;display:flex;gap:6px}
.tag{font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;
     font-family:var(--font-mono);text-transform:uppercase;white-space:nowrap}
.tag-CRITICAL,.tag-CRITICA{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.35)}
.tag-ERROR,.tag-ALTA{background:rgba(249,115,22,.15);color:#fb923c;border:1px solid rgba(249,115,22,.35)}
.tag-WARNING,.tag-MEDIA{background:rgba(245,158,11,.13);color:#fbbf24;border:1px solid rgba(245,158,11,.32)}
.tag-INFO,.tag-BAIXA{background:rgba(59,130,246,.13);color:#60a5fa;border:1px solid rgba(59,130,246,.32)}
.tag-PHP{background:rgba(139,92,246,.14);color:#a78bfa;border:1px solid rgba(139,92,246,.3)}
.tag-JS{background:rgba(234,179,8,.14);color:#facc15;border:1px solid rgba(234,179,8,.3)}
.tag-n{background:var(--bg-hover);color:var(--txt-2);border:1px solid var(--border)}
.ev-stack{margin-top:9px;font-size:11px;font-family:var(--font-mono);color:var(--txt-3);
          background:var(--bg-input);border-radius:6px;padding:9px 11px;white-space:pre-wrap;
          max-height:170px;overflow:auto}
.ev-detalhe{font-size:12.5px;color:var(--txt-2);line-height:1.6;margin-bottom:8px}

/* Auditoria */
.aud{display:flex;gap:13px;align-items:flex-start;padding:13px 16px;border:1px solid var(--border);
     border-radius:var(--radius);margin-bottom:8px;background:var(--bg-card2)}
.aud-ic{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;
        flex-shrink:0;font-size:12px}
.aud-ok{background:rgba(34,197,94,.15);color:#4ade80}
.aud-no{background:rgba(239,68,68,.15);color:#f87171}
.aud-t{font-size:13px;font-weight:600;color:var(--txt-1);margin-bottom:3px}
.aud-d{font-size:12px;color:var(--txt-3);line-height:1.55}

/* Backups */
.bk{display:flex;gap:14px;align-items:center;padding:12px 16px;border:1px solid var(--border);
    border-left-width:3px;border-radius:var(--radius);margin-bottom:8px;background:var(--bg-card2);flex-wrap:wrap}
.bk-EXITO{border-left-color:var(--green)}
.bk-PARCIAL{border-left-color:var(--amber)}
.bk-FALHA{border-left-color:var(--red)}
.bk-data{min-width:120px;font-family:var(--font-mono);font-size:12px;color:var(--txt-1)}
.bk-info{flex:1;min-width:200px;font-size:12px;color:var(--txt-3)}
.log-cru{background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius);
         padding:13px 15px;font-family:var(--font-mono);font-size:11.5px;color:var(--txt-3);
         white-space:pre-wrap;max-height:320px;overflow:auto;line-height:1.6}
.vazio-msg{padding:40px 20px;text-align:center;color:var(--txt-4);font-size:13px}
.vazio-msg i{display:block;font-size:26px;margin-bottom:10px;opacity:.3}
</style>
</head>
<body>

<!-- ═══════ SIDEBAR ═══════ -->
<nav class="sidenav" id="sidenav">
  <div class="sidenav-brand">
    <div class="brand-icon"><i class="fas fa-code"></i></div>
    <div>
      <div class="brand-name">PatAsset</div>
      <div class="brand-sub">DEV Panel</div>
    </div>
  </div>

  <div class="sidenav-section">Gestão</div>
  <button class="nav-link active" onclick="goPage('usuarios', this)"><i class="fas fa-users"></i> Usuários</button>
  <button class="nav-link" onclick="goPage('online', this)"><i class="fas fa-circle" style="color:var(--green);font-size:8px"></i> Online agora</button>
  <button class="nav-link" onclick="goPage('autorizacao', this)"><i class="fas fa-key"></i> Autorização</button>

  <div class="sidenav-section">Sistema</div>
  <button class="nav-link" onclick="goPage('desempenho', this)"><i class="fas fa-gauge-high"></i> Desempenho</button>
  <button class="nav-link" onclick="goPage('controle', this)"><i class="fas fa-sliders"></i> Controle</button>
  <button class="nav-link" onclick="goPage('acessos', this)"><i class="fas fa-clock-rotate-left"></i> Acessos</button>
  <button class="nav-link" onclick="goPage('estatisticas', this)"><i class="fas fa-chart-bar"></i> Estatísticas</button>

  <div class="sidenav-section">Segurança</div>
  <button class="nav-link" onclick="goPage('logs', this)"><i class="fas fa-scroll"></i> Logs de Ações</button>
  <button class="nav-link" onclick="goPage('invasoes', this)"><i class="fas fa-shield-halved"></i> Invasões</button>
  <button class="nav-link" onclick="goPage('erros', this)"><i class="fas fa-bug"></i> Erros
    <span id="nav-badge-erros" class="nav-badge" style="display:none"></span></button>
  <button class="nav-link" onclick="goPage('ameacas', this)"><i class="fas fa-user-secret"></i> Ameaças
    <span id="nav-badge-ameacas" class="nav-badge" style="display:none"></span></button>
  <button class="nav-link" onclick="goPage('tabelas', this)"><i class="fas fa-table-list"></i> Tabelas</button>
  <button class="nav-link" onclick="goPage('backups', this)"><i class="fas fa-database"></i> Backups</button>
  <button class="nav-link" onclick="goPage('auditoria', this)"><i class="fas fa-clipboard-check"></i> Auditoria</button>

  <div class="sidenav-section">Módulos</div>
  <a class="nav-link" href="inicial.php"><i class="fas fa-building"></i> Patrimônio</a>
  <a class="nav-link" href="engenharia_clinica_inicial.php"><i class="fas fa-stethoscope"></i> Eng. Clínica</a>

  <div class="sidenav-footer">
    <div class="user-pill">
      <div class="user-avatar"><?= strtoupper(substr($dev_usuario, 0, 2)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($dev_usuario) ?></div>
        <div class="user-role">DEV · <?= date('H:i') ?></div>
      </div>
    </div>
  </div>
</nav>

<!-- ═══════ MAIN ═══════ -->
<div class="main">
  <header class="topbar">
    <button class="mob-toggle" onclick="document.getElementById('sidenav').classList.toggle('open')">
      <i class="fas fa-bars"></i>
    </button>
    <div class="topbar-title" id="topbar-title">Usuários</div>
    <div class="topbar-meta">
      <span class="dot-live"></span>
      Sistema online
    </div>
    <div class="topbar-clock" id="clock"></div>
    <a href="index.html" class="btn-logout"><i class="fas fa-arrow-right-from-bracket"></i> Sair</a>
  </header>

  <div class="content">

    <!-- ══════════ PÁGINA: USUÁRIOS ══════════ -->
    <div id="page-usuarios" class="page active">

      <div class="stats-row" id="stats-usuarios">
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-users"></i> Total</div>
          <div class="stat-value" id="su-total">—</div>
          <div class="stat-sub">usuários cadastrados</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-code" style="color:var(--blue)"></i> DEV</div>
          <div class="stat-value" id="su-dev" style="color:var(--blue)">—</div>
          <div class="stat-sub">acesso total</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-shield-halved" style="color:var(--green)"></i> Nível A</div>
          <div class="stat-value" id="su-a" style="color:var(--green)">—</div>
          <div class="stat-sub">administradores</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-user" style="color:var(--amber)"></i> B / C</div>
          <div class="stat-value" id="su-bc" style="color:var(--amber)">—</div>
          <div class="stat-sub">operadores</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-ban" style="color:var(--red)"></i> Bloqueados</div>
          <div class="stat-value" id="su-bloqueados" style="color:var(--red)">—</div>
          <div class="stat-sub">sem acesso</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-table"></i> Lista de Usuários</div>
          <div class="card-actions">
            <div class="search-wrap">
              <i class="fas fa-magnifying-glass"></i>
              <input class="search-input" id="search-usr" placeholder="Buscar..." oninput="filtrarUsuarios()" autocomplete="off" readonly onfocus="this.removeAttribute('readonly')" style="width:200px">
            </div>
            <!-- Filtro de status -->
            <select id="filtro-status" onchange="filtrarUsuarios()" style="padding:6px 10px;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--txt-2);font-size:12px;font-family:var(--font-ui);outline:none;cursor:pointer;">
              <option value="">Todos</option>
              <option value="ATIVO">Ativos</option>
              <option value="BLOQUEADO">Bloqueados</option>
            </select>
            <button class="toggle-all-senha" id="btn-toggle-all" onclick="toggleTodasSenhas()">
              <i class="fas fa-eye" id="icon-toggle-all"></i> Senhas
            </button>
            <button class="btn btn-ghost btn-sm" onclick="carregarUsuarios()"><i class="fas fa-rotate-right"></i></button>
            <button class="btn btn-primary btn-sm" onclick="abrirModalCriar()"><i class="fas fa-plus"></i> Novo</button>
          </div>
        </div>
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Usuário</th>
                <th>Senha (hash)</th>
                <th>Permissão</th>
                <th>Classe</th>
                <th>Status</th>
                <th style="text-align:right">Ações</th>
              </tr>
            </thead>
            <tbody id="tbody-usuarios">
              <tr><td colspan="7" style="text-align:center;padding:36px;color:var(--txt-4)">Carregando...</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ── Bloqueios de login (proteção contra força bruta) ── -->
      <div class="card" style="margin-top:16px">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-lock" style="color:var(--red)"></i> Bloqueios de Login</div>
          <div class="card-actions">
            <button class="btn btn-ghost btn-sm" onclick="carregarBloqueios()"><i class="fas fa-rotate-right"></i></button>
            <button class="btn btn-ghost btn-sm" onclick="liberarTodosBloqueios()"><i class="fas fa-unlock"></i> Liberar todos</button>
          </div>
        </div>
        <div style="padding:10px 18px 0;font-size:12px;color:var(--txt-4);line-height:1.6">
          5 senhas erradas no mesmo usuário bloqueiam por 15 minutos (30 e 60 min em reincidência).
          Por IP o limite é 20 — mais alto de propósito, porque todo o hospital sai pelo mesmo IP.
        </div>
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>Tipo</th><th>Usuário / IP</th><th>Falhas</th>
                <th>Bloqueios</th><th>Situação</th><th>Última falha</th>
                <th style="text-align:right">Ação</th>
              </tr>
            </thead>
            <tbody id="tbody-bloqueios">
              <tr><td colspan="7" style="text-align:center;padding:28px;color:var(--txt-4)">Carregando...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════ PÁGINA: ONLINE ══════════ -->
    <div id="page-online" class="page">
      <div class="card">
        <div class="card-header">
          <div class="card-title">
            <i class="fas fa-signal" style="color:var(--green)"></i>
            Usuários Online
            <span id="online-count-badge" style="background:var(--green-dim);color:var(--green);border-radius:10px;font-size:11px;font-weight:600;padding:1px 9px;font-family:var(--font-mono)"></span>
          </div>
          <div class="card-actions">
            <span id="online-refresh-label" style="font-size:11px;color:var(--txt-4);font-family:var(--font-mono)"></span>
            <button class="btn btn-ghost btn-sm" onclick="clearTimeout(onlinePollingTimer); atualizarOnline()"><i class="fas fa-rotate-right"></i> Atualizar</button>
          </div>
        </div>
        <div class="card-body">
          <p style="font-size:12px;color:var(--txt-3);margin-bottom:16px">
            Sessões PHP ativas no servidor. Botão <strong style="color:var(--red)">Deslogar</strong> encerra apenas aquela sessão individualmente.
          </p>
          <div class="online-grid" id="online-grid">
            <div class="online-empty"><i class="fas fa-spinner fa-spin"></i> Carregando sessões...</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════ PÁGINA: AUTORIZAÇÃO ══════════ -->
    <div id="page-autorizacao" class="page">
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-key"></i> Senhas de Autorização</div>
          <div class="card-actions">
            <button class="btn btn-ghost btn-sm" onclick="carregarAutorizacao()"><i class="fas fa-rotate-right"></i></button>
          </div>
        </div>
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr><th>ID</th><th>Senha de Autorização</th><th style="text-align:right">Ação</th></tr>
            </thead>
            <tbody id="tbody-autorizacao">
              <tr><td colspan="3" style="text-align:center;padding:36px;color:var(--txt-4)">Carregando...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════ PÁGINA: DESEMPENHO ══════════ -->
    <div id="page-desempenho" class="page">

      <!-- ── Linha 1: PatAsset ── -->
      <div style="font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--txt-4);margin-bottom:10px">PatAsset</div>
      <div class="stats-row" style="margin-bottom:20px">
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-box-archive" style="color:var(--blue)"></i> Patrimônios</div>
          <div class="stat-value" id="pd-cadastro" style="color:var(--blue)">—</div>
          <div class="stat-sub">itens cadastrados</div>
          <div class="stat-insight" id="ins-cadastro"></div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-circle-check" style="color:var(--green)"></i> Ativos</div>
          <div class="stat-value" id="pd-ativos" style="color:var(--green)">—</div>
          <div class="stat-sub">em operação</div>
          <div class="stat-insight" id="ins-ativos"></div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-trash-can" style="color:var(--red)"></i> Descartados</div>
          <div class="stat-value" id="pd-baixa" style="color:var(--red)">—</div>
          <div class="stat-sub">baixas definitivas</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-right-left" style="color:var(--amber)"></i> Movimentações</div>
          <div class="stat-value" id="pd-mov" style="color:var(--amber)">—</div>
          <div class="stat-sub">no histórico</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-arrow-right-to-bracket" style="color:var(--purple)"></i> Acessos hoje</div>
          <div class="stat-value" id="pd-acessos" style="color:var(--purple)">—</div>
          <div class="stat-sub">logins no dia</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-circle" style="color:var(--green);font-size:8px"></i> Online agora</div>
          <div class="stat-value" id="pd-online" style="color:var(--green)">—</div>
          <div class="stat-sub">usuários ativos</div>
        </div>
      </div>

      <!-- ── Última atividade ── -->
      <div class="card" style="margin-bottom:20px">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-clock-rotate-left"></i> Última Atividade</div>
          <div class="card-actions">
            <button class="btn btn-ghost btn-sm" onclick="carregarMetricas()"><i class="fas fa-rotate-right"></i></button>
          </div>
        </div>
        <div class="card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div>
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--txt-4);margin-bottom:8px">Último Cadastro</div>
            <div id="pd-ult-cad" style="font-size:13px;color:var(--txt-2);font-family:var(--font-mono)">—</div>
          </div>
          <div>
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--txt-4);margin-bottom:8px">Última Movimentação</div>
            <div id="pd-ult-mov" style="font-size:13px;color:var(--txt-2);font-family:var(--font-mono)">—</div>
          </div>
        </div>
      </div>

      <!-- ── Linha 2: Servidor ── -->
      <div style="font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--txt-4);margin-bottom:10px">Servidor</div>
      <div class="stats-row" style="margin-bottom:20px">
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-database" style="color:var(--green)"></i> Banco</div>
          <div class="stat-value" id="pd-size" style="color:var(--green);font-size:22px">—</div>
          <div class="stat-sub">sistema_db</div>
          <div class="stat-insight" id="ins-size"></div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-clock" style="color:var(--blue)"></i> Uptime MySQL</div>
          <div class="stat-value" id="pd-uptime" style="color:var(--blue);font-size:18px">—</div>
          <div class="stat-sub">servidor ativo</div>
          <div class="stat-insight" id="ins-uptime"></div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-plug" style="color:var(--purple)"></i> Conexões</div>
          <div class="stat-value" id="pd-threads" style="color:var(--purple)">—</div>
          <div class="stat-sub">threads ativas agora</div>
          <div class="stat-insight" id="ins-threads"></div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-microchip"></i> Memória PHP</div>
          <div class="stat-value" id="pd-mem" style="font-size:18px">—</div>
          <div class="stat-sub">uso desta requisição</div>
          <div class="stat-insight" id="ins-mem"></div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-code-branch"></i> PHP</div>
          <div class="stat-value" id="pd-php" style="font-size:18px">—</div>
          <div class="stat-sub">versão do servidor</div>
          <div class="stat-insight" id="ins-php"></div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-table-list" style="color:var(--amber)"></i> Maior Tabela</div>
          <div class="stat-value" id="pd-maior-mb" style="color:var(--amber);font-size:18px">—</div>
          <div class="stat-sub" id="pd-maior-nome">—</div>
          <div class="stat-insight" id="ins-maior"></div>
        </div>
      </div>

      <!-- ── Tabelas ── -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-table-list"></i> Tabelas do Banco</div>
          <div class="card-actions">
            <button class="btn btn-ghost btn-sm" onclick="carregarMetricas()"><i class="fas fa-rotate-right"></i></button>
          </div>
        </div>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>Tabela</th><th>Registros</th><th>Tamanho</th><th>Última atualização</th></tr></thead>
            <tbody id="tbody-tabelas">
              <tr><td colspan="4" style="text-align:center;padding:36px;color:var(--txt-4)">Carregando...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════ PÁGINA: CONTROLE ══════════ -->
    <div id="page-controle" class="page">
      <div class="info-two" style="margin-bottom:20px">
        <div class="card" style="margin-bottom:0">
          <div class="card-header"><div class="card-title"><i class="fas fa-server"></i> Servidor</div></div>
          <div class="card-body">
            <div class="info-list">
              <div class="info-row"><span class="info-row-k">Hora do servidor</span><span class="info-row-v" id="cc-time">—</span></div>
              <div class="info-row"><span class="info-row-k">Versão PHP</span><span class="info-row-v" id="cc-php">—</span></div>
              <div class="info-row"><span class="info-row-k">Banco de dados</span><span class="info-row-v">sistema_db</span></div>
              <div class="info-row"><span class="info-row-k">Host DB</span><span class="info-row-v">localhost</span></div>
              <div class="info-row"><span class="info-row-k">Usuário DB</span><span class="info-row-v">usuario_banco</span></div>
            </div>
          </div>
        </div>
        <div class="card" style="margin-bottom:0">
          <div class="card-header"><div class="card-title"><i class="fas fa-user-shield"></i> Sessão atual</div></div>
          <div class="card-body">
            <div class="info-list">
              <div class="info-row"><span class="info-row-k">Operador</span><span class="info-row-v"><?= htmlspecialchars($dev_usuario) ?></span></div>
              <div class="info-row"><span class="info-row-k">Classe</span><span class="info-row-v"><span class="badge badge-blue">DEV</span></span></div>
              <div class="info-row"><span class="info-row-k">Session ID</span><span class="info-row-v" style="font-size:10px;word-break:break-all;max-width:180px;text-align:right"><?= session_id() ?></span></div>
              <div class="info-row"><span class="info-row-k">Início</span><span class="info-row-v"><?= $dev_hora ?></span></div>
            </div>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-wrench"></i> Operações</div></div>
        <div class="card-body">
          <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px">
            <button class="btn btn-green" onclick="goPage('backups', null); carregarBackups(); carregarTabelasBackup()"><i class="fas fa-floppy-disk"></i> Backup do banco</button>
            <button class="btn btn-ghost" onclick="goPage('desempenho', null); carregarMetricas()"><i class="fas fa-gauge-high"></i> Ver desempenho</button>
            <button class="btn btn-ghost" onclick="goPage('usuarios', null); carregarUsuarios()"><i class="fas fa-users"></i> Ver usuários</button>
            <button class="btn btn-ghost" onclick="goPage('online', null); carregarOnline()"><i class="fas fa-signal"></i> Ver online</button>
          </div>
          <div style="border-top:1px solid var(--border);padding-top:20px">
            <div style="font-size:12px;font-weight:600;color:var(--txt-2);margin-bottom:12px;display:flex;align-items:center;gap:8px">
              <i class="fas fa-power-off" style="color:var(--amber)"></i> Modo Manutenção
              <span id="manut-badge" style="display:none;font-size:10px;padding:2px 8px;border-radius:10px;background:var(--amber-dim);color:var(--amber);font-family:var(--font-mono)">ATIVO</span>
            </div>
            <div class="field" style="max-width:420px;margin-bottom:12px">
              <label>Mensagem para os usuários</label>
              <input type="text" id="manut-msg" placeholder="O sistema estará de volta em breve."
                style="width:100%;padding:8px 12px;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--txt-1);font-size:13px;font-family:var(--font-ui);outline:none;transition:border-color var(--transition)">
            </div>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
              <button class="btn" id="btn-manutencao" onclick="toggleManutencao()"
                style="background:var(--amber-dim);color:var(--amber);border:1px solid rgba(245,158,11,.3)">
                <i class="fas fa-power-off"></i> <span id="manut-label">Ligar manutenção</span>
              </button>
              <span style="font-size:12px;color:var(--txt-4)">Ao ativar, usuários veem tela de manutenção. DEV tem acesso normal.</span>
            </div>
            <div id="manut-status" style="margin-top:10px;font-size:12px;color:var(--txt-3);font-family:var(--font-mono)">Verificando...</div>
          </div>
        </div>
      </div>
    </div>


    <!-- ══════════ PÁGINA: ACESSOS ══════════ -->
    <div id="page-acessos" class="page">

      <div class="stats-row" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-right-to-bracket" style="color:var(--green)"></i> Logins OK</div>
          <div class="stat-value" id="ac-ok" style="color:var(--green)">—</div>
          <div class="stat-sub">acessos bem-sucedidos</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-triangle-exclamation" style="color:var(--red)"></i> Falhas</div>
          <div class="stat-value" id="ac-falha" style="color:var(--red)">—</div>
          <div class="stat-sub">senha incorreta</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-ban" style="color:var(--orange)"></i> Bloqueados</div>
          <div class="stat-value" id="ac-bloqueado" style="color:var(--orange)">—</div>
          <div class="stat-sub">usuário bloqueado</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-list" style="color:var(--blue)"></i> Total</div>
          <div class="stat-value" id="ac-total" style="color:var(--blue)">—</div>
          <div class="stat-sub">registros</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-clock-rotate-left"></i> Histórico de Acessos</div>
          <div class="card-actions">
            <!-- Filtro usuário -->
            <div class="search-wrap">
              <i class="fas fa-magnifying-glass"></i>
              <input class="search-input" id="ac-filtro-usuario" placeholder="Filtrar usuário..."
                     oninput="debounceAcessos()" autocomplete="off" style="width:180px">
            </div>
            <!-- Filtro resultado -->
            <select id="ac-filtro-resultado" onchange="carregarAcessos()"
                    style="padding:6px 10px;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--txt-2);font-size:12px;font-family:var(--font-ui);outline:none;cursor:pointer;">
              <option value="">Todos</option>
              <option value="OK">OK</option>
              <option value="FALHA">FALHA</option>
              <option value="BLOQUEADO">BLOQUEADO</option>
            </select>
            <button class="btn btn-ghost btn-sm" onclick="carregarAcessos()"><i class="fas fa-rotate-right"></i></button>
            <button class="btn btn-green btn-sm" onclick="exportarAcessosCSV()"><i class="fas fa-file-csv"></i> CSV</button>
            <button class="btn btn-red btn-sm" onclick="limparAcessos()"><i class="fas fa-trash"></i> Limpar</button>
          </div>
        </div>

        <!-- Paginação topo -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 20px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:8px">
          <span id="ac-contador" style="font-size:12px;color:var(--txt-3);font-family:var(--font-mono)">—</span>
          <div style="display:flex;gap:6px;align-items:center">
            <button class="btn btn-ghost btn-sm" id="ac-btn-prev" onclick="acessosPagAnterior()"><i class="fas fa-chevron-left"></i></button>
            <span id="ac-pag-label" style="font-size:12px;color:var(--txt-2);font-family:var(--font-mono);min-width:70px;text-align:center">pág 1</span>
            <button class="btn btn-ghost btn-sm" id="ac-btn-next" onclick="acessosPagProxima()"><i class="fas fa-chevron-right"></i></button>
            <select id="ac-por-pagina" onchange="carregarAcessos()"
                    style="padding:5px 8px;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--txt-2);font-size:11px;font-family:var(--font-mono);outline:none;">
              <option value="50">50/pág</option>
              <option value="100" selected>100/pág</option>
              <option value="200">200/pág</option>
            </select>
          </div>
        </div>

        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Data</th>
                <th>Hora</th>
                <th>Usuário</th>
                <th>Permissão</th>
                <th>Classe</th>
                <th>Resultado</th>
                <th>IP Rede</th>
                <th>IP Dispositivo</th>
                <th>Navegador / SO</th>
              </tr>
            </thead>
            <tbody id="tbody-acessos">
              <tr><td colspan="10" style="text-align:center;padding:36px;color:var(--txt-4)">Carregando...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════ PÁGINA: ESTATÍSTICAS ══════════ -->
    <div id="page-estatisticas" class="page">
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-chart-bar" style="color:var(--blue)"></i> Produção por Usuário</div>
          <div class="card-actions">
            <div class="search-wrap">
              <i class="fas fa-magnifying-glass"></i>
              <input class="search-input" id="est-filtro" placeholder="Filtrar usuário..." oninput="filtrarEstatisticas()" style="width:180px">
            </div>
            <button class="btn btn-ghost btn-sm" onclick="carregarEstatisticas()"><i class="fas fa-rotate-right"></i> Atualizar</button>
          </div>
        </div>
        <div class="tbl-wrap">
          <table>
            <thead><tr>
              <th>#</th><th>Usuário</th>
              <th style="color:var(--purple)"><i class="fas fa-right-to-bracket"></i> Logins</th>
              <th style="color:var(--blue)"><i class="fas fa-plus-circle"></i> Cadastros</th>
              <th style="color:var(--amber)"><i class="fas fa-right-left"></i> Movimentações</th>
              <th style="color:var(--red)"><i class="fas fa-trash"></i> Baixas</th>
              <th style="color:var(--green)">Total</th>
            </tr></thead>
            <tbody id="tbody-estatisticas">
              <tr><td colspan="7" style="text-align:center;padding:36px;color:var(--txt-4)">Carregando...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════ PÁGINA: LOGS ══════════ -->
    <div id="page-logs" class="page">
      <div style="display:flex;gap:0;margin-bottom:20px;border-bottom:1px solid var(--border)">
        <button id="log-tab-acoes" class="log-tab active" onclick="mudarLogTab('acoes')"><i class="fas fa-list-check"></i> Timeline de Ações</button>
        <button id="log-tab-erros" class="log-tab" onclick="mudarLogTab('erros')"><i class="fas fa-circle-xmark"></i> Log de Erros</button>
      </div>
      <div id="log-acoes">
        <div class="card">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-timeline" style="color:var(--blue)"></i> Timeline de Ações do Sistema</div>
            <div class="card-actions">
              <div class="search-wrap">
                <i class="fas fa-magnifying-glass"></i>
                <input class="search-input" id="la-filtro-u" placeholder="Filtrar usuário..." oninput="carregarTimeline()" style="width:170px">
              </div>
              <button class="btn btn-ghost btn-sm" onclick="carregarTimeline()"><i class="fas fa-rotate-right"></i></button>
            </div>
          </div>
          <div class="tbl-wrap"><table>
            <thead><tr><th>Data</th><th>Hora</th><th>Usuário</th><th>Ação</th><th>Módulo</th><th>Descrição</th></tr></thead>
            <tbody id="tbody-timeline"><tr><td colspan="6" style="text-align:center;padding:36px;color:var(--txt-4)">Carregando...</td></tr></tbody>
          </table></div>
        </div>
      </div>
      <div id="log-erros" style="display:none">
        <div class="card">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-bug" style="color:var(--red)"></i> Log de Erros</div>
            <div class="card-actions">
              <select id="le-filtro-nivel" onchange="carregarLogErros()" style="padding:6px 10px;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--txt-2);font-size:12px;font-family:var(--font-ui);outline:none;cursor:pointer;">
                <option value="">Todos</option><option value="INFO">INFO</option><option value="WARNING">WARNING</option><option value="ERROR">ERROR</option><option value="CRITICAL">CRITICAL</option>
              </select>
              <button class="btn btn-ghost btn-sm" onclick="carregarLogErros()"><i class="fas fa-rotate-right"></i></button>
              <button class="btn btn-red btn-sm" onclick="limparLogErros()"><i class="fas fa-trash"></i> Limpar</button>
            </div>
          </div>
          <div class="tbl-wrap"><table>
            <thead><tr><th>Data</th><th>Hora</th><th>Nível</th><th>Arquivo</th><th>Mensagem</th><th>Usuário</th><th>IP</th></tr></thead>
            <tbody id="tbody-log-erros"><tr><td colspan="7" style="text-align:center;padding:36px;color:var(--txt-4)">Carregando...</td></tr></tbody>
          </table></div>
        </div>
      </div>
    </div>

    <!-- ══════════ PÁGINA: INVASÕES ══════════ -->
    <div id="page-invasoes" class="page">
      <div class="stats-row" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
        <div class="stat-card"><div class="stat-label"><i class="fas fa-user-slash" style="color:var(--red)"></i> Sem Login</div><div class="stat-value" id="inv-sem-login" style="color:var(--red)">—</div><div class="stat-sub">não autenticados</div></div>
        <div class="stat-card"><div class="stat-label"><i class="fas fa-ban" style="color:var(--orange)"></i> Permissão</div><div class="stat-value" id="inv-permissao" style="color:var(--orange)">—</div><div class="stat-sub">nível insuficiente</div></div>
        <div class="stat-card"><div class="stat-label"><i class="fas fa-hourglass-end" style="color:var(--amber)"></i> Sessão Expirada</div><div class="stat-value" id="inv-expirada" style="color:var(--amber)">—</div><div class="stat-sub">pós-logout</div></div>
        <div class="stat-card"><div class="stat-label"><i class="fas fa-shield-halved" style="color:var(--blue)"></i> Total</div><div class="stat-value" id="inv-total" style="color:var(--blue)">—</div><div class="stat-sub">registros</div></div>
      </div>
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-shield-halved" style="color:var(--red)"></i> Tentativas de Acesso Não Autorizado</div>
          <div class="card-actions">
            <select id="inv-filtro-tipo" onchange="carregarInvasoes()" style="padding:6px 10px;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--txt-2);font-size:12px;font-family:var(--font-ui);outline:none;cursor:pointer;">
              <option value="">Todos</option><option value="SEM_LOGIN">Sem Login</option><option value="PERMISSAO_INSUFICIENTE">Permissão</option><option value="SESSAO_EXPIRADA">Sessão Expirada</option>
            </select>
            <button class="btn btn-ghost btn-sm" onclick="carregarInvasoes()"><i class="fas fa-rotate-right"></i></button>
            <button class="btn btn-red btn-sm" onclick="limparInvasoes()"><i class="fas fa-trash"></i> Limpar</button>
          </div>
        </div>
        <div class="tbl-wrap"><table>
          <thead><tr><th>Data</th><th>Hora</th><th>Tipo</th><th>Arquivo</th><th>Usuário tentativa</th><th>IP</th><th>Navegador</th></tr></thead>
          <tbody id="tbody-invasoes"><tr><td colspan="7" style="text-align:center;padding:36px;color:var(--txt-4)">Carregando...</td></tr></tbody>
        </table></div>
      </div>
      <div class="card" style="border-color:rgba(245,158,11,.2);background:rgba(245,158,11,.03)">
        <div class="card-body" style="padding:16px 20px">
          <div style="font-size:12px;font-weight:600;color:var(--amber);margin-bottom:8px"><i class="fas fa-circle-info"></i> Como registrar invasões</div>
          <div style="font-size:12px;color:var(--txt-3);line-height:1.7">Esta tela é do sistema antigo. O registro atual fica em <b>Ameaças</b>, alimentado por <code style="font-family:var(--font-mono);background:var(--bg-card2);padding:1px 5px;border-radius:3px">dev_seguranca.php</code>. Para marcar um acesso negado numa página restrita, chame <code style="font-family:var(--font-mono);background:var(--bg-card2);padding:1px 5px;border-radius:3px">dev_acesso_negado()</code> antes do redirecionamento.</div>
        </div>
      </div>
    </div>

    <!-- ══════════ PÁGINA: ERROS ══════════ -->
    <div id="page-erros" class="page">
      <div class="stats-row">
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-bug"></i> Em aberto</div>
          <div class="stat-value" id="er-abertos">—</div>
          <div class="stat-sub">erros não resolvidos</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-fire" style="color:var(--red)"></i> Críticos</div>
          <div class="stat-value" id="er-criticos" style="color:var(--red)">—</div>
          <div class="stat-sub">exigem atenção</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fab fa-php" style="color:#a78bfa"></i> PHP</div>
          <div class="stat-value" id="er-php" style="color:#a78bfa">—</div>
          <div class="stat-sub">no servidor</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fab fa-js" style="color:var(--amber)"></i> JavaScript</div>
          <div class="stat-value" id="er-js" style="color:var(--amber)">—</div>
          <div class="stat-sub">no navegador</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-clock"></i> Últimas 24h</div>
          <div class="stat-value" id="er-hoje">—</div>
          <div class="stat-sub">ocorrências</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-list"></i> Registro de Erros</div>
          <div class="card-actions filtros-linha">
            <select id="er-origem" onchange="carregarErros()">
              <option value="">PHP e JavaScript</option>
              <option value="PHP">Só PHP (servidor)</option>
              <option value="JS">Só JavaScript (navegador)</option>
            </select>
            <select id="er-nivel" onchange="carregarErros()">
              <option value="">Todos os níveis</option>
              <option value="CRITICAL">Crítico</option>
              <option value="ERROR">Erro</option>
              <option value="WARNING">Aviso</option>
              <option value="INFO">Informativo</option>
            </select>
            <input type="text" id="er-busca" placeholder="Buscar mensagem, arquivo, usuário..."
                   style="width:220px" onkeydown="if(event.key==='Enter')carregarErros()">
            <label><input type="checkbox" id="er-resolvidos" onchange="carregarErros()"> Mostrar resolvidos</label>
            <button class="btn btn-ghost btn-sm" onclick="carregarErros()"><i class="fas fa-rotate-right"></i></button>
            <button class="btn btn-ghost btn-sm" onclick="limparErros()"><i class="fas fa-broom"></i> Limpar</button>
          </div>
        </div>
        <div class="card-body" id="er-lista">
          <div class="vazio-msg">Carregando...</div>
        </div>
      </div>
    </div>

    <!-- ══════════ PÁGINA: AMEAÇAS ══════════ -->
    <div id="page-ameacas" class="page">
      <div class="stats-row">
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-user-secret"></i> Em aberto</div>
          <div class="stat-value" id="am-abertas">—</div>
          <div class="stat-sub">não revisadas</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-triangle-exclamation" style="color:var(--red)"></i> Graves</div>
          <div class="stat-value" id="am-graves" style="color:var(--red)">—</div>
          <div class="stat-sub">alta ou crítica</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-clock"></i> Últimas 24h</div>
          <div class="stat-value" id="am-24h">—</div>
          <div class="stat-sub">eventos</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-network-wired"></i> Origens</div>
          <div class="stat-value" id="am-ips">—</div>
          <div class="stat-sub">endereços distintos</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-shield-halved"></i> Possíveis Ameaças</div>
          <div class="card-actions filtros-linha">
            <select id="am-sev" onchange="carregarAmeacas()">
              <option value="">Todas as severidades</option>
              <option value="CRITICA">Crítica</option>
              <option value="ALTA">Alta</option>
              <option value="MEDIA">Média</option>
              <option value="BAIXA">Baixa</option>
            </select>
            <select id="am-tipo" onchange="carregarAmeacas()">
              <option value="">Todos os tipos</option>
              <option value="FORCA_BRUTA">Força bruta (login)</option>
              <option value="ACESSO_NEGADO">Acesso negado</option>
              <option value="SEM_LOGIN">Sem sessão</option>
              <option value="ERRO_CRITICO">Erro crítico</option>
              <option value="CSRF_SUSPEITO">Ação sem token (CSRF)</option>
              <option value="UPLOAD_SUSPEITO">Envio de arquivo recusado</option>
            </select>
            <label><input type="checkbox" id="am-revisadas" onchange="carregarAmeacas()"> Mostrar revisadas</label>
            <button class="btn btn-ghost btn-sm" onclick="carregarAmeacas()"><i class="fas fa-rotate-right"></i></button>
          </div>
        </div>
        <div class="card-body" id="am-lista">
          <div class="vazio-msg">Carregando...</div>
        </div>
      </div>
    </div>

    <!-- ══════════ PÁGINA: TABELAS ══════════ -->
    <div id="page-tabelas" class="page">
      <div class="card">
        <div class="card-header" style="flex-wrap:wrap;gap:10px">
          <div class="card-title"><i class="fas fa-table-list"></i> Tabelas do Banco</div>
          <div class="card-actions filtros-linha">
            <select id="tb-sel" onchange="abrirTabela()" style="min-width:330px">
              <option value="">Selecione uma tabela...</option>
            </select>
            <button class="btn btn-ghost btn-sm" onclick="carregarCatalogoTabelas()">
              <i class="fas fa-rotate-right"></i>
            </button>
          </div>
        </div>
        <div class="card-body" id="tb-vazio">
          <div class="vazio-msg">
            <i class="fas fa-table-list"></i>
            Escolha uma tabela na lista acima.<br>
            <span style="font-size:12px">Só aparecem as tabelas que pessoas preenchem.
            As alimentadas pelo sistema — registros de acesso, trilha de eventos, logs de
            erro — ficam de fora de propósito: editá-las não conserta nada e apaga a
            evidência do que aconteceu.</span>
          </div>
        </div>
      </div>

      <div id="tb-area" style="display:none">
        <div class="card">
          <div class="card-header" style="flex-wrap:wrap;gap:10px">
            <div>
              <div class="card-title" id="tb-titulo" style="margin-bottom:3px">—</div>
              <div style="font-size:12px;color:var(--txt-3)" id="tb-desc">—</div>
            </div>
            <div class="card-actions filtros-linha">
              <input type="text" id="tb-busca" placeholder="Buscar em todas as colunas..."
                     style="width:240px" onkeydown="if(event.key==='Enter'){tbPagina=1;carregarDadosTabela()}">
              <select id="tb-limite" onchange="tbPagina=1;carregarDadosTabela()">
                <option value="25">25 linhas</option>
                <option value="50" selected>50 linhas</option>
                <option value="100">100 linhas</option>
                <option value="200">200 linhas</option>
              </select>
              <label id="tb-lbl-edicao">
                <input type="checkbox" id="tb-edicao" onchange="alternarEdicao()"> Modo edição
              </label>
              <button class="btn btn-ghost btn-sm" onclick="carregarDadosTabela()">
                <i class="fas fa-rotate-right"></i>
              </button>
              <button class="btn btn-ghost btn-sm" onclick="verAuditoria()">
                <i class="fas fa-clock-rotate-left"></i> Alterações
              </button>
            </div>
          </div>

          <div id="tb-aviso-edicao" style="display:none;margin:0 18px 12px;padding:11px 15px;
               border-radius:8px;background:rgba(245,158,11,.09);border:1px solid rgba(245,158,11,.3);
               color:#fbbf24;font-size:12.5px;line-height:1.6">
            <i class="fas fa-triangle-exclamation"></i>
            <b>Modo edição ativo.</b> Alterações aqui não passam pelas validações das telas
            do sistema — nem combobox, nem normalização de datas, nem os vínculos entre
            registros. Tudo o que você fizer fica registrado em "Alterações".
          </div>

          <div class="tbl-wrap" style="max-height:62vh;overflow:auto">
            <table id="tb-tabela"><thead id="tb-cab"></thead><tbody id="tb-corpo"></tbody></table>
          </div>

          <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;
                      padding:13px 18px;border-top:1px solid var(--border);flex-wrap:wrap">
            <div style="font-size:12px;color:var(--txt-3)" id="tb-info">—</div>
            <div style="display:flex;gap:7px;align-items:center">
              <button class="btn btn-ghost btn-sm" onclick="tbIrPagina(-1)"><i class="fas fa-chevron-left"></i></button>
              <span style="font-size:12px;color:var(--txt-2);font-family:var(--font-mono)" id="tb-pag">1 / 1</span>
              <button class="btn btn-ghost btn-sm" onclick="tbIrPagina(1)"><i class="fas fa-chevron-right"></i></button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════ PÁGINA: BACKUPS ══════════ -->
    <div id="page-backups" class="page">
      <div class="stats-row">
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-calendar-check"></i> Último backup</div>
          <div class="stat-value" id="bk-ultimo" style="font-size:17px">—</div>
          <div class="stat-sub" id="bk-ultimo-sub">—</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-hard-drive"></i> Cópias locais</div>
          <div class="stat-value" id="bk-locais">—</div>
          <div class="stat-sub" id="bk-locais-sub">no servidor</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fab fa-google-drive"></i> Google Drive</div>
          <div class="stat-value" id="bk-drive" style="font-size:17px">—</div>
          <div class="stat-sub" id="bk-drive-sub">—</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-clock"></i> Agendamento</div>
          <div class="stat-value" id="bk-cron" style="font-size:15px">—</div>
          <div class="stat-sub">tarefa automática</div>
        </div>
      </div>

      <!-- ── Download avulso ── -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-download"></i> Baixar cópia agora</div>
          <div class="card-actions">
            <button class="btn btn-green btn-sm" onclick="rodarBackupAgora()">
              <i class="fas fa-play"></i> Executar backup automático
            </button>
          </div>
        </div>
        <div class="card-body">
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
            <button class="btn btn-primary" onclick="fazerBackup('geral')">
              <i class="fas fa-database"></i> Banco completo
            </button>
            <button class="btn btn-ghost" onclick="fazerBackup('patasset')">
              <i class="fas fa-building"></i> Somente PatAsset
            </button>
            <button class="btn btn-ghost" onclick="fazerBackup('lifetech')">
              <i class="fas fa-stethoscope"></i> Somente LifeTech
            </button>
          </div>

          <div style="border-top:1px solid var(--border);padding-top:16px">
            <div style="font-size:12px;font-weight:600;color:var(--txt-2);margin-bottom:10px">
              <i class="fas fa-table"></i> Uma tabela específica
            </div>
            <div class="filtros-linha">
              <select id="bk-tabela" style="min-width:320px">
                <option value="">Carregando tabelas...</option>
              </select>
              <button class="btn btn-ghost btn-sm" onclick="baixarTabela()">
                <i class="fas fa-download"></i> Baixar
              </button>
            </div>
          </div>

          <div style="font-size:12px;color:var(--txt-3);margin-top:16px;line-height:1.7">
            Os arquivos parciais incluem também as tabelas comuns aos dois sistemas
            — usuários, acessos e registros do painel. Sem elas, restaurar só o LifeTech
            devolveria um sistema em que ninguém consegue entrar.
            <br>O nome do arquivo indica o conteúdo, para não restar dúvida meses depois.
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-clock-rotate-left"></i> Execuções</div>
          <div class="card-actions">
            <button class="btn btn-ghost btn-sm" onclick="carregarBackups()"><i class="fas fa-rotate-right"></i></button>
          </div>
        </div>
        <div class="card-body" id="bk-execucoes"><div class="vazio-msg">Carregando...</div></div>
      </div>

      <div class="info-two">
        <div class="card">
          <div class="card-header"><div class="card-title"><i class="fas fa-folder"></i> Arquivos no servidor</div></div>
          <div class="card-body" id="bk-arquivos"><div class="vazio-msg">—</div></div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title"><i class="fas fa-file-lines"></i> Log da última execução</div></div>
          <div class="card-body"><div class="log-cru" id="bk-log">—</div></div>
        </div>
      </div>
    </div>

    <!-- ══════════ PÁGINA: AUDITORIA ══════════ -->
    <div id="page-auditoria" class="page">
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-clipboard-check"></i> Verificações de Segurança</div>
          <div class="card-actions">
            <button class="btn btn-ghost btn-sm" onclick="carregarAuditoria()"><i class="fas fa-rotate-right"></i> Verificar novamente</button>
          </div>
        </div>
        <div class="card-body">
          <div style="font-size:12px;color:var(--txt-3);margin-bottom:16px;line-height:1.7">
            Cada item é uma pergunta que vale repetir de tempos em tempos. Um item marcado
            em vermelho não significa que houve invasão — significa que existe uma porta que
            seria melhor fechar antes que alguém tente.
          </div>
          <div id="aud-lista"><div class="vazio-msg">Carregando...</div></div>
        </div>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<!-- Toast -->
<div id="toast"><span class="toast-icon" id="toast-icon"></span><span id="toast-msg"></span></div>

<!-- ══ Modal: editar registro ══ -->
<div class="overlay" id="modal-registro">
  <div class="modal" style="max-width:720px">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-pen" style="color:var(--blue);font-size:13px"></i>
        <span id="mr-titulo">Registro</span></div>
      <div class="modal-close" onclick="fecharModal('modal-registro')"><i class="fas fa-xmark"></i></div>
    </div>
    <div class="modal-body" style="max-height:65vh;overflow:auto" id="mr-campos"></div>
    <div class="modal-footer" id="mr-rodape"></div>
  </div>
</div>

<!-- ══ Modal: trilha de alterações ══ -->
<div class="overlay" id="modal-auditoria">
  <div class="modal" style="max-width:900px">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-clock-rotate-left" style="color:var(--amber);font-size:13px"></i>
        Alterações feitas por esta tela</div>
      <div class="modal-close" onclick="fecharModal('modal-auditoria')"><i class="fas fa-xmark"></i></div>
    </div>
    <div class="modal-body" style="max-height:70vh;overflow:auto" id="ma-corpo"></div>
  </div>
</div>

<!-- ══ Modal Editar ══ -->
<div class="overlay" id="modal-editar">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-pen" style="color:var(--blue);font-size:13px"></i> Editar Usuário</div>
      <div class="modal-close" onclick="fecharModal('modal-editar')"><i class="fas fa-xmark"></i></div>
    </div>
    <div class="modal-body">
      <div class="bloqueado-alert" id="edit-bloqueado-alert">
        <i class="fas fa-triangle-exclamation"></i>
        <span>Este usuário está <strong>BLOQUEADO</strong> — sem acesso ao sistema.</span>
      </div>
      <input type="hidden" id="edit-id">
      <div class="field"><label>Usuário</label><input type="text" id="edit-usuario"></div>
      <div class="field">
        <label>Nova senha <span style="color:var(--txt-4);text-transform:none;font-weight:400">(deixe vazio para manter)</span></label>
        <div style="position:relative">
          <input type="password" id="edit-senha" placeholder="••••••••" oninput="avaliarSenha(this,'edit-pw-bar','edit-pw-hint')" style="padding-right:36px">
          <button type="button" onclick="togglePwVis('edit-senha',this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--txt-4);cursor:pointer;font-size:13px;padding:2px 4px;transition:color .15s"><i class="fas fa-eye"></i></button>
        </div>
        <div class="pw-strength"><div class="pw-strength-bar" id="edit-pw-bar"></div></div>
        <div class="pw-hint" id="edit-pw-hint"></div>
      </div>
      <div class="field-row">
        <div class="field">
          <label>Permissão</label>
          <select id="edit-permicao">
            <option value="A">A — Administrador</option>
            <option value="B">B — Operador</option>
            <option value="C">C — Visualizador</option>
            <option value="DEV">DEV — Desenvolvedor</option>
            <option value="S">S — Super</option>
          </select>
        </div>
        <div class="field">
          <label>Classe / Setor</label>
          <select id="edit-classe">
            <option value="PATRIMONIO">PATRIMONIO</option>
            <option value="ENGENHARIA CLINICA">ENGENHARIA CLINICA</option>
            <option value="DEV">DEV</option>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="fecharModal('modal-editar')">Cancelar</button>
      <button class="btn btn-primary" onclick="salvarEdicao()"><i class="fas fa-check"></i> Salvar</button>
    </div>
  </div>
</div>

<!-- ══ Modal Criar ══ -->
<div class="overlay" id="modal-criar">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-user-plus" style="color:var(--green);font-size:13px"></i> Novo Usuário</div>
      <div class="modal-close" onclick="fecharModal('modal-criar')"><i class="fas fa-xmark"></i></div>
    </div>
    <div class="modal-body">
      <div class="field"><label>Usuário</label><input type="text" id="novo-usuario"></div>
      <div class="field">
        <label>Senha</label>
        <div style="position:relative">
          <input type="password" id="novo-senha" placeholder="••••••••" oninput="avaliarSenha(this,'novo-pw-bar','novo-pw-hint')" style="padding-right:36px">
          <button type="button" onclick="togglePwVis('novo-senha',this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--txt-4);cursor:pointer;font-size:13px;padding:2px 4px;transition:color .15s"><i class="fas fa-eye"></i></button>
        </div>
        <div class="pw-strength"><div class="pw-strength-bar" id="novo-pw-bar"></div></div>
        <div class="pw-hint" id="novo-pw-hint"></div>
      </div>
      <div class="field-row">
        <div class="field">
          <label>Permissão</label>
          <select id="novo-permicao">
            <option value="A">A — Administrador</option>
            <option value="B">B — Operador</option>
            <option value="C">C — Visualizador</option>
            <option value="DEV">DEV — Desenvolvedor</option>
            <option value="S">S — Super</option>
          </select>
        </div>
        <div class="field">
          <label>Classe / Setor</label>
          <select id="novo-classe">
            <option value="PATRIMONIO">PATRIMONIO</option>
            <option value="ENGENHARIA CLINICA">ENGENHARIA CLINICA</option>
            <option value="DEV">DEV</option>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="fecharModal('modal-criar')">Cancelar</button>
      <button class="btn btn-primary" onclick="criarUsuario()"><i class="fas fa-plus"></i> Criar</button>
    </div>
  </div>
</div>

<script>
// ── Estado ─────────────────────────────────────────────────────────────────────
let todosUsuarios  = [];
let senhasVisiveis = false;

// ── Clock ──────────────────────────────────────────────────────────────────────
(function clock() {
  document.getElementById('clock').textContent = new Date().toLocaleString('pt-BR');
  setTimeout(clock, 1000);
})();

// ── Navegação ─────────────────────────────────────────────────────────────────
const pageNames = { usuarios:'Usuários', online:'Online agora', autorizacao:'Autorização', desempenho:'Desempenho', controle:'Controle', acessos:'Histórico de Acessos', estatisticas:'Estatísticas de Usuários', logs:'Logs de Ações', invasoes:'Invasões / Segurança', erros:'Erros da Aplicação', ameacas:'Possíveis Ameaças', backups:'Backups', auditoria:'Auditoria de Segurança', tabelas:'Tabelas do Banco' };

function goPage(name, btn) {
  if (onlineAtivo) pararPollingOnline();
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('page-' + name).classList.add('active');
  document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
  if (btn) btn.classList.add('active');
  document.getElementById('topbar-title').textContent = pageNames[name] || name;
  if (name === 'online')      iniciarPollingOnline();
  if (name === 'autorizacao') carregarAutorizacao();
  if (name === 'desempenho')  carregarMetricas();
  if (name === 'controle')     { carregarMetricas(); verificarManutencao(); }
  if (name === 'usuarios') {
    if (todosUsuarios.length === 0) carregarUsuarios();
    carregarBloqueios();
  }
  if (name === 'acessos')      carregarAcessos();
  if (name === 'estatisticas') carregarEstatisticas();
  if (name === 'logs')         carregarTimeline();
  if (name === 'invasoes')     carregarInvasoes();
  if (name === 'erros')        carregarErros();
  if (name === 'ameacas')      carregarAmeacas();
  if (name === 'tabelas' && document.getElementById('tb-sel').options.length <= 1) carregarCatalogoTabelas();
  if (name === 'backups')      { carregarBackups(); carregarTabelasBackup(); }
  if (name === 'auditoria')    carregarAuditoria();
}

// ── Modal ──────────────────────────────────────────────────────────────────────
function abrirModal(id) { document.getElementById(id).classList.add('open'); }
function fecharModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.overlay').forEach(o =>
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); })
);

// ── Toast ──────────────────────────────────────────────────────────────────────
function toast(msg, tipo = 'ok') {
  const t = document.getElementById('toast');
  const map = { ok:['✓','toast-ok'], err:['✕','toast-err'], info:['ℹ','toast-info'], warn:['⚠','toast-warn'] };
  const [icon, cls] = map[tipo] || map.ok;
  t.className = `show ${cls}`;
  document.getElementById('toast-icon').textContent = icon;
  document.getElementById('toast-msg').textContent  = msg;
  clearTimeout(t._t);
  t._t = setTimeout(() => t.className = '', 3500);
}

// ── POST helper ───────────────────────────────────────────────────────────────
/**
 * Token CSRF lido do cookie.
 * Não depende do script global, que é carregado no fim do documento — e este
 * painel dispara requisições já no carregamento, antes daquele script existir.
 */
function csrfToken() {
  if (window.__segCsrf) return window.__segCsrf;
  const m = /(?:^|;\s*)pat_csrf=([^;]+)/.exec(document.cookie || '');
  return m ? decodeURIComponent(m[1]) : '';
}

async function post(data) {
  const fd = new FormData();
  for (const k in data) fd.append(k, data[k]);

  const t = csrfToken();
  if (t) fd.append('_csrf', t);

  const r = await fetch('dev_painel.php?_=' + Date.now(), {
    method: 'POST',
    body: fd,
    cache: 'no-store',
    headers: t ? { 'X-CSRF-Token': t } : {},
  });
  return r.json();
}

// ── Escape ─────────────────────────────────────────────────────────────────────
function esc(str) {
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Password utils ─────────────────────────────────────────────────────────────
function togglePwVis(inputId, btn) {
  const inp = document.getElementById(inputId);
  const isPass = inp.type === 'password';
  inp.type = isPass ? 'text' : 'password';
  btn.querySelector('i').className = isPass ? 'fas fa-eye-slash' : 'fas fa-eye';
  btn.style.color = isPass ? 'var(--amber)' : 'var(--txt-4)';
}

function avaliarSenha(input, barId, hintId) {
  const v = input.value;
  const bar  = document.getElementById(barId);
  const hint = document.getElementById(hintId);
  if (!v) { bar.style.width='0'; bar.style.background=''; hint.textContent=''; return; }
  let score = 0;
  if (v.length >= 6)  score++;
  if (v.length >= 10) score++;
  if (/[A-Z]/.test(v)) score++;
  if (/[0-9]/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  const levels = [
    { w:'20%',  bg:'var(--red)',    txt:'Muito fraca' },
    { w:'40%',  bg:'var(--orange)', txt:'Fraca' },
    { w:'60%',  bg:'var(--amber)',  txt:'Razoável' },
    { w:'80%',  bg:'var(--blue)',   txt:'Boa' },
    { w:'100%', bg:'var(--green)',  txt:'Forte' },
  ];
  const l = levels[Math.min(score - 1, 4)] || levels[0];
  bar.style.width  = l.w;
  bar.style.background = l.bg;
  hint.textContent = l.txt;
  hint.style.color = l.bg;
}

// ═══════════════════════════════════════════
// USUÁRIOS
// ═══════════════════════════════════════════
const usuarioMap = new Map();

async function carregarUsuarios() {
  const res = await post({ action: 'listar_usuarios' });
  if (!res.ok) return toast('Erro ao carregar usuários', 'err');
  todosUsuarios = res.data.map(u => ({ ...u, id: parseInt(u.id) }));
  usuarioMap.clear();
  todosUsuarios.forEach(u => usuarioMap.set(u.id, u));
  renderizarUsuarios(todosUsuarios);

  document.getElementById('su-total').textContent     = res.data.length;
  document.getElementById('su-dev').textContent       = res.data.filter(u => u.classe_usuario === 'DEV').length;
  document.getElementById('su-a').textContent         = res.data.filter(u => u.permicao === 'A').length;
  document.getElementById('su-bc').textContent        = res.data.filter(u => ['B','C'].includes(u.permicao)).length;
  document.getElementById('su-bloqueados').textContent = res.data.filter(u => u.status === 'BLOQUEADO').length;
}

function renderizarUsuarios(data) {
  const tbody = document.getElementById('tbody-usuarios');
  if (!data.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:36px;color:var(--txt-4)">Nenhum resultado.</td></tr>';
    return;
  }
  tbody.innerHTML = data.map(u => {
    const bloqueado  = (u.status || 'ATIVO') === 'BLOQUEADO';
    const statusHtml = bloqueado
      ? `<span class="badge badge-status-bloqueado badge"><span class="status-dot status-dot-red"></span>BLOQUEADO</span>`
      : `<span class="badge badge-status-ativo badge"><span class="status-dot status-dot-green"></span>ATIVO</span>`;

    const toggleTitle = bloqueado ? 'Clique para ATIVAR' : 'Clique para BLOQUEAR';
    const toggleClass = bloqueado ? 'bloqueado' : 'ativo';
    const toggleIcon  = bloqueado ? 'fa-lock-open' : 'fa-lock';
    const toggleLabel = bloqueado ? 'Ativar' : 'Bloquear';

    return `
      <tr class="${bloqueado ? 'row-bloqueado' : ''}">
        <td class="mono" style="color:var(--txt-4)">#${u.id}</td>
        <td style="font-weight:500;color:${bloqueado ? 'var(--txt-3)' : 'var(--txt-1)'}">${esc(u.usuario)}</td>
        <td>
          <div class="senha-cell">
            <span class="senha-val${senhasVisiveis ? ' visible' : ''}" id="sv-${u.id}">${senhasVisiveis ? esc(u.senha) : '••••••••••••'}</span>
            <button class="btn-eye${senhasVisiveis ? ' active' : ''}" id="eye-${u.id}" onclick="toggleSenha(${u.id})" title="${senhasVisiveis ? 'Ocultar' : 'Mostrar'}">
              <i class="fas ${senhasVisiveis ? 'fa-eye-slash' : 'fa-eye'}"></i>
            </button>
          </div>
        </td>
        <td>${badgePerm(u.permicao)}</td>
        <td>${badgeClasse(u.classe_usuario)}</td>
        <td>${statusHtml}</td>
        <td style="text-align:right">
          <button class="status-toggle-btn ${toggleClass}" id="stbtn-${u.id}" onclick="toggleStatus(${u.id})" title="${toggleTitle}">
            <i class="fas ${toggleIcon}"></i> ${toggleLabel}
          </button>
          <button class="btn btn-ghost btn-xs" onclick="abrirEditar(${u.id})" style="margin-left:4px">
            <i class="fas fa-pen"></i> Editar
          </button>
          <button class="btn btn-red btn-xs" onclick="excluirUsuario(${u.id})" style="margin-left:4px">
            <i class="fas fa-trash"></i>
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

// Toggle status individual
async function toggleStatus(id) {
  id = parseInt(id);
  const u = usuarioMap.get(id);
  if (!u) return;
  const bloqueado = (u.status || 'ATIVO') === 'BLOQUEADO';
  const acao = bloqueado ? 'ativar' : 'bloquear';
  const aviso = bloqueado
    ? `Ativar o acesso de "${u.usuario}"?`
    : `Bloquear "${u.usuario}"?\n\nO usuário perderá o acesso imediatamente e sua sessão ativa será encerrada.`;

  if (!confirm(aviso)) return;

  const btn = document.getElementById('stbtn-' + id);
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }

  const res = await post({ action: 'toggle_status', id });
  if (res.ok) {
    // Atualiza localmente sem recarregar tudo
    u.status = res.novo_status;
    usuarioMap.set(id, u);
    const icone = res.novo_status === 'BLOQUEADO' ? '🔒' : '✅';
    toast(`${icone} ${res.usuario} está agora ${res.novo_status}`, res.novo_status === 'BLOQUEADO' ? 'warn' : 'ok');
    carregarUsuarios(); // recarrega para atualizar contadores e estilos
  } else {
    toast(res.msg || 'Erro ao alterar status', 'err');
    if (btn) { btn.disabled = false; }
    carregarUsuarios();
  }
}

// Toggle senha individual
function toggleSenha(id) {
  id = parseInt(id);
  const u = usuarioMap.get(id);
  if (!u) return;
  const sv  = document.getElementById('sv-' + id);
  const eye = document.getElementById('eye-' + id);
  if (!sv || !eye) return;
  const isVisible = sv.classList.contains('visible');
  if (isVisible) {
    sv.textContent = '••••••••••••';
    sv.classList.remove('visible');
    eye.classList.remove('active');
    eye.innerHTML = '<i class="fas fa-eye"></i>';
  } else {
    sv.textContent = u.senha;
    sv.classList.add('visible');
    eye.classList.add('active');
    eye.innerHTML = '<i class="fas fa-eye-slash"></i>';
    setTimeout(() => { if (sv?.classList.contains('visible')) toggleSenha(id); }, 8000);
  }
}

// Toggle todas as senhas
function toggleTodasSenhas() {
  senhasVisiveis = !senhasVisiveis;
  document.getElementById('icon-toggle-all').className = senhasVisiveis ? 'fas fa-eye-slash' : 'fas fa-eye';
  renderizarUsuarios(usuariosFiltrados());
}

function usuariosFiltrados() {
  const q  = document.getElementById('search-usr').value.toLowerCase().trim();
  const st = document.getElementById('filtro-status').value;
  return todosUsuarios.filter(u => {
    const matchQ  = !q  || u.usuario.toLowerCase().includes(q) || (u.permicao||'').toLowerCase().includes(q) || (u.classe_usuario||'').toLowerCase().includes(q);
    const matchSt = !st || (u.status || 'ATIVO') === st;
    return matchQ && matchSt;
  });
}

function filtrarUsuarios() { renderizarUsuarios(usuariosFiltrados()); }

function badgePerm(p) {
  const map = { A:'badge-green', B:'badge-blue', C:'badge-amber', DEV:'badge-purple', S:'badge-red' };
  return `<span class="badge ${map[p]||'badge-grey'}">${p||'—'}</span>`;
}
function badgeClasse(c) {
  if (!c) return '<span style="color:var(--txt-4)">—</span>';
  const map = { 'PATRIMONIO':'badge-blue', 'ENGENHARIA CLINICA':'badge-green', 'DEV':'badge-purple' };
  return `<span class="badge ${map[c]||'badge-grey'}">${esc(c)}</span>`;
}

function abrirModalCriar() { abrirModal('modal-criar'); }

function abrirEditar(id) {
  id = parseInt(id);
  const u = usuarioMap.get(id);
  if (!u) return;
  document.getElementById('edit-id').value      = u.id;
  document.getElementById('edit-usuario').value  = u.usuario;
  document.getElementById('edit-senha').value    = '';
  document.getElementById('edit-permicao').value = u.permicao;
  const sel = document.getElementById('edit-classe');
  for (let o of sel.options) o.selected = (o.value === (u.classe_usuario||''));
  // Alerta de bloqueio
  const alerta = document.getElementById('edit-bloqueado-alert');
  alerta.classList.toggle('show', (u.status || 'ATIVO') === 'BLOQUEADO');
  // Reseta força de senha
  document.getElementById('edit-pw-bar').style.width = '0';
  document.getElementById('edit-pw-hint').textContent = '';
  abrirModal('modal-editar');
}

async function salvarEdicao() {
  const res = await post({
    action: 'editar_usuario',
    id:             document.getElementById('edit-id').value,
    usuario:        document.getElementById('edit-usuario').value,
    senha:          document.getElementById('edit-senha').value,
    permicao:       document.getElementById('edit-permicao').value,
    classe_usuario: document.getElementById('edit-classe').value,
  });
  if (res.ok) { fecharModal('modal-editar'); toast('Usuário atualizado'); carregarUsuarios(); }
  else toast('Erro ao salvar', 'err');
}

async function criarUsuario() {
  const usuario = document.getElementById('novo-usuario').value.trim();
  const senha   = document.getElementById('novo-senha').value.trim();
  if (!usuario || !senha) return toast('Preencha usuário e senha', 'err');
  const res = await post({
    action: 'criar_usuario', usuario, senha,
    permicao:       document.getElementById('novo-permicao').value,
    classe_usuario: document.getElementById('novo-classe').value,
  });
  if (res.ok) {
    fecharModal('modal-criar');
    toast(res.msg || 'Usuário criado com sucesso');
    carregarUsuarios();
    ['novo-usuario','novo-senha'].forEach(id => { document.getElementById(id).value=''; });
    document.getElementById('novo-pw-bar').style.width = '0';
    document.getElementById('novo-pw-hint').textContent = '';
  } else toast(res.msg || 'Erro ao criar', 'err');
}

// ═══════════════════════════════════════════
// TABELAS DO BANCO
// ═══════════════════════════════════════════
let tbAtual = null;    // metadados da tabela aberta
let tbPagina = 1;

async function carregarCatalogoTabelas() {
  const sel = document.getElementById('tb-sel');
  const res = await post({ action: 'tab_catalogo' }).catch(() => null);
  if (!res || !res.ok) { toast('Erro ao carregar tabelas', 'err'); return; }

  const grupos = {};
  res.tabelas.forEach(t => { (grupos[t.grupo] = grupos[t.grupo] || []).push(t); });

  let html = '<option value="">Selecione uma tabela...</option>';
  Object.keys(grupos).forEach(g => {
    html += `<optgroup label="${esc(g)}">`;
    grupos[g].forEach(t => {
      const n = Number(t.linhas).toLocaleString('pt-BR');
      html += `<option value="${esc(t.tabela)}">${esc(t.rotulo)} — ${esc(t.desc)} (${n})</option>`;
    });
    html += '</optgroup>';
  });
  sel.innerHTML = html;
}

function abrirTabela() {
  const t = document.getElementById('tb-sel').value;
  if (!t) {
    document.getElementById('tb-area').style.display = 'none';
    document.getElementById('tb-vazio').style.display = '';
    return;
  }
  tbPagina = 1;
  document.getElementById('tb-busca').value = '';
  document.getElementById('tb-edicao').checked = false;
  document.getElementById('tb-aviso-edicao').style.display = 'none';
  carregarDadosTabela();
}

function alternarEdicao() {
  const on = document.getElementById('tb-edicao').checked;
  document.getElementById('tb-aviso-edicao').style.display = on ? '' : 'none';
  carregarDadosTabela();
}

function tbIrPagina(d) {
  const nova = tbPagina + d;
  if (!tbAtual || nova < 1 || nova > tbAtual.paginas) return;
  tbPagina = nova;
  carregarDadosTabela();
}

/* Valor binário chega marcado para não trafegar o arquivo inteiro */
function tbFormatar(v) {
  if (v === null) return '<span style="color:var(--txt-4)">NULL</span>';
  if (typeof v === 'string' && v.startsWith('@@BIN:')) {
    const kb = Math.round(parseInt(v.slice(6), 10) / 1024);
    return `<span style="color:var(--txt-4)"><i class="fas fa-file"></i> binário · ${kb} KB</span>`;
  }
  if (v === '') return '<span style="color:var(--txt-4)">—</span>';
  const s = String(v);
  return esc(s.length > 90 ? s.slice(0, 90) + '…' : s);
}

async function carregarDadosTabela() {
  const t = document.getElementById('tb-sel').value;
  if (!t) return;

  const res = await post({
    action: 'tab_dados', tabela: t, pagina: tbPagina,
    limite: document.getElementById('tb-limite').value,
    busca:  document.getElementById('tb-busca').value,
  }).catch(() => null);

  if (!res || !res.ok) { toast(res?.msg || 'Erro ao carregar', 'err'); return; }
  tbAtual = res;

  document.getElementById('tb-vazio').style.display = 'none';
  document.getElementById('tb-area').style.display = '';
  document.getElementById('tb-titulo').textContent = res.rotulo + '  ·  ' + res.tabela;
  document.getElementById('tb-desc').textContent = res.desc;

  const editavel = !res.leitura && document.getElementById('tb-edicao').checked && res.chave;
  document.getElementById('tb-lbl-edicao').style.display = res.leitura ? 'none' : '';

  // Cabeçalho
  let cab = '<tr>';
  if (res.chave) cab += '<th style="width:80px">Ações</th>';
  res.colunas.forEach(c => {
    const marca = c.chave ? ' <i class="fas fa-key" style="font-size:9px;color:var(--amber)"></i>' : '';
    cab += `<th title="${esc(c.tipo)}">${esc(c.nome)}${marca}</th>`;
  });
  document.getElementById('tb-cab').innerHTML = cab + '</tr>';

  // Linhas
  if (!res.linhas.length) {
    document.getElementById('tb-corpo').innerHTML =
      `<tr><td colspan="${res.colunas.length + 1}" style="text-align:center;padding:34px;color:var(--txt-4)">
        Nenhum registro encontrado.</td></tr>`;
  } else {
    document.getElementById('tb-corpo').innerHTML = res.linhas.map((l, i) => {
      const id = res.chave ? l[res.chave] : null;
      let tr = '<tr>';
      if (res.chave) {
        tr += `<td style="white-space:nowrap">
          <button class="btn btn-ghost btn-sm" title="${editavel ? 'Editar' : 'Ver'}"
                  onclick="abrirRegistro(${i})">
            <i class="fas fa-${editavel ? 'pen' : 'eye'}"></i></button>
          ${editavel ? `<button class="btn btn-ghost btn-sm" title="Excluir"
              onclick="excluirRegistro('${esc(String(id))}')"
              style="color:var(--red)"><i class="fas fa-trash"></i></button>` : ''}
        </td>`;
      }
      res.colunas.forEach(c => { tr += `<td>${tbFormatar(l[c.nome])}</td>`; });
      return tr + '</tr>';
    }).join('');
  }

  const ini = (res.pagina - 1) * res.limite + 1;
  const fim = Math.min(res.pagina * res.limite, res.total);
  document.getElementById('tb-info').textContent =
    res.total ? `${ini}–${fim} de ${Number(res.total).toLocaleString('pt-BR')} registros`
              : 'Nenhum registro';
  document.getElementById('tb-pag').textContent = `${res.pagina} / ${res.paginas}`;
}

/* ── Ver / editar um registro ── */
function abrirRegistro(indice) {
  const res = tbAtual;
  const l = res.linhas[indice];
  const editavel = !res.leitura && document.getElementById('tb-edicao').checked && res.chave;
  const id = res.chave ? l[res.chave] : '';
  const bloq = (res.bloq || []).map(x => x.toLowerCase());

  document.getElementById('mr-titulo').textContent =
    (editavel ? 'Editar' : 'Ver') + ' registro' + (res.chave ? ` · ${res.chave} = ${id}` : '');

  document.getElementById('mr-campos').innerHTML = res.colunas.map(c => {
    const v = l[c.nome];
    const bin = typeof v === 'string' && v.startsWith('@@BIN:');
    const travado = !editavel || c.binaria || bin || c.chave || bloq.includes(c.nome.toLowerCase());

    let motivo = '';
    if (c.chave)                          motivo = 'chave primária — identifica a linha';
    else if (c.binaria || bin)            motivo = 'conteúdo binário — não editável por aqui';
    else if (bloq.includes(c.nome.toLowerCase())) motivo = 'precisa passar pela tela própria do sistema';

    const valor = bin ? '[arquivo binário]' : (v === null ? '' : String(v));
    const campo = c.texto
      ? `<textarea class="input" data-col="${esc(c.nome)}" ${travado ? 'readonly' : ''}
           style="min-height:80px;resize:vertical">${esc(valor)}</textarea>`
      : `<input class="input" type="text" data-col="${esc(c.nome)}" ${travado ? 'readonly' : ''}
           value="${esc(valor)}">`;

    return `<div class="field" style="margin-bottom:13px">
      <label style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span>${esc(c.nome)}</span>
        <span style="font-size:10px;color:var(--txt-4);font-family:var(--font-mono)">${esc(c.tipo)}</span>
        ${motivo ? `<span style="font-size:10px;color:var(--amber)">· ${esc(motivo)}</span>` : ''}
      </label>
      ${campo}
    </div>`;
  }).join('');

  document.getElementById('mr-rodape').innerHTML = editavel
    ? `<button class="btn btn-ghost" onclick="fecharModal('modal-registro')">Cancelar</button>
       <button class="btn btn-primary" onclick="salvarRegistro('${esc(String(id))}')">
         <i class="fas fa-floppy-disk"></i> Salvar</button>`
    : `<button class="btn btn-ghost" onclick="fecharModal('modal-registro')">Fechar</button>`;

  abrirModal('modal-registro');
}

async function salvarRegistro(id) {
  const campos = {};
  document.querySelectorAll('#mr-campos [data-col]').forEach(el => {
    if (el.readOnly) return;
    campos[el.dataset.col] = el.value;
  });

  const res = await post({
    action: 'tab_salvar', tabela: tbAtual.tabela, id, campos: JSON.stringify(campos),
  }).catch(() => null);

  if (res && res.ok) {
    fecharModal('modal-registro');
    toast(res.msg || 'Salvo');
    carregarDadosTabela();
  } else {
    toast(res?.msg || 'Erro ao salvar', 'err');
  }
}

async function excluirRegistro(id) {
  // Primeiro descobre o que ficaria órfão
  const rel = await post({ action: 'tab_relacionados', tabela: tbAtual.tabela, id })
                .catch(() => ({ relacionados: [] }));

  let aviso = `Excluir o registro ${tbAtual.chave} = ${id} da tabela "${tbAtual.rotulo}"?`;
  if (rel && rel.relacionados && rel.relacionados.length) {
    aviso += `\n\nATENÇÃO — este registro tem vínculos:\n· ${rel.relacionados.join('\n· ')}`;
    aviso += `\n\nEsses registros NÃO serão apagados e ficarão apontando para algo que deixou de existir.`;
  }
  aviso += `\n\nA exclusão não tem volta. O conteúdo da linha fica guardado em "Alterações".`;

  if (!confirm(aviso)) return;

  const res = await post({ action: 'tab_excluir', tabela: tbAtual.tabela, id }).catch(() => null);
  if (res && res.ok) { toast(res.msg || 'Excluído'); carregarDadosTabela(); }
  else toast(res?.msg || 'Erro ao excluir', 'err');
}

async function verAuditoria() {
  const res = await post({ action: 'tab_auditoria', tabela: tbAtual ? tbAtual.tabela : '' })
                .catch(() => null);
  const box = document.getElementById('ma-corpo');
  if (!res || !res.ok) { box.innerHTML = '<div class="vazio-msg">Erro ao carregar.</div>'; }
  else if (!res.data.length) {
    box.innerHTML = '<div class="vazio-msg"><i class="fas fa-clock-rotate-left"></i>' +
                    'Nenhuma alteração registrada para esta tabela.</div>';
  } else {
    box.innerHTML = `<table><thead><tr>
        <th>Quando</th><th>Operação</th><th>Registro</th><th>Coluna</th>
        <th>Antes</th><th>Depois</th><th>Quem</th></tr></thead><tbody>` +
      res.data.map(a => `<tr>
        <td style="white-space:nowrap">${esc(a.quando)}</td>
        <td><span class="tag tag-${a.operacao === 'EXCLUSAO' ? 'CRITICA' : 'MEDIA'}">${esc(a.operacao)}</span></td>
        <td>${esc(a.registro_id)}</td>
        <td>${esc(a.coluna || '—')}</td>
        <td style="max-width:220px;word-break:break-word;color:var(--txt-3)">${esc(a.valor_antes || '—')}</td>
        <td style="max-width:220px;word-break:break-word">${esc(a.valor_depois || '—')}</td>
        <td style="white-space:nowrap">${esc(a.usuario)}<br>
            <span style="font-size:10px;color:var(--txt-4)">${esc(a.ip || '')}</span></td>
      </tr>`).join('') + '</tbody></table>';
  }
  abrirModal('modal-auditoria');
}

// ═══════════════════════════════════════════
// ERROS (PHP + JavaScript)
// ═══════════════════════════════════════════
function nivelPT(n) {
  return { CRITICAL:'Crítico', ERROR:'Erro', WARNING:'Aviso', INFO:'Info' }[n] || n;
}

async function carregarErros() {
  const box = document.getElementById('er-lista');
  const res = await post({
    action:     'erros_listar',
    origem:     document.getElementById('er-origem').value,
    nivel:      document.getElementById('er-nivel').value,
    busca:      document.getElementById('er-busca').value,
    resolvidos: document.getElementById('er-resolvidos').checked ? '1' : '0',
  }).catch(() => null);

  if (!res) { box.innerHTML = '<div class="vazio-msg">Erro de comunicação.</div>'; return; }
  if (!res.ok) {
    box.innerHTML = `<div class="vazio-msg"><i class="fas fa-database"></i>${esc(res.msg || 'Erro')}</div>`;
    return;
  }

  const r = res.resumo || {};
  document.getElementById('er-abertos').textContent  = r.abertos  ?? 0;
  document.getElementById('er-criticos').textContent = r.criticos ?? 0;
  document.getElementById('er-php').textContent      = r.php      ?? 0;
  document.getElementById('er-js').textContent       = r.js       ?? 0;
  document.getElementById('er-hoje').textContent     = r.hoje     ?? 0;

  const badge = document.getElementById('nav-badge-erros');
  const abertos = parseInt(r.abertos || 0, 10);
  badge.style.display = abertos > 0 ? '' : 'none';
  badge.textContent = abertos > 99 ? '99+' : abertos;

  if (!res.data.length) {
    box.innerHTML = '<div class="vazio-msg"><i class="fas fa-circle-check" style="color:var(--green)"></i>' +
                    'Nenhum erro registrado com esses filtros.</div>';
    return;
  }

  box.innerHTML = res.data.map(e => {
    const local = e.arquivo ? esc(e.arquivo) + (parseInt(e.linha,10) ? ':' + e.linha : '') : '—';
    return `<div class="ev ev-${esc(e.nivel)} ${e.resolvido == 1 ? 'ev-resolvido' : ''}">
      <div class="ev-topo">
        <span class="tag tag-${esc(e.origem)}">${esc(e.origem)}</span>
        <span class="tag tag-${esc(e.nivel)}">${esc(nivelPT(e.nivel))}</span>
        ${e.ocorrencias > 1 ? `<span class="tag tag-n">${esc(e.ocorrencias)}x</span>` : ''}
        ${e.resolvido == 1 ? '<span class="tag tag-n">resolvido</span>' : ''}
        <div class="ev-acoes">
          <button class="btn btn-ghost btn-sm" onclick="resolverErro(${e.id}, ${e.resolvido == 1 ? 0 : 1})">
            <i class="fas fa-${e.resolvido == 1 ? 'rotate-left' : 'check'}"></i>
            ${e.resolvido == 1 ? 'Reabrir' : 'Resolver'}
          </button>
        </div>
      </div>
      <div class="ev-msg">${esc(e.mensagem)}</div>
      <div class="ev-meta">
        <span><i class="fas fa-file-code"></i>${local}</span>
        ${e.url ? `<span><i class="fas fa-link"></i>${esc(e.url).slice(0,70)}</span>` : ''}
        <span><i class="fas fa-user"></i>${esc(e.usuario || 'anônimo')}</span>
        <span><i class="fas fa-network-wired"></i>${esc(e.ip || '—')}</span>
        <span><i class="fas fa-clock"></i>${esc(e.ultima)}</span>
        ${e.ocorrencias > 1 ? `<span><i class="fas fa-hourglass-start"></i>desde ${esc(e.primeira)}</span>` : ''}
      </div>
      ${e.stack ? `<div class="ev-stack">${esc(e.stack)}</div>` : ''}
    </div>`;
  }).join('');
}

async function resolverErro(id, valor) {
  const res = await post({ action:'erro_resolver', id, valor });
  if (res.ok) { toast(valor ? 'Erro marcado como resolvido' : 'Erro reaberto'); carregarErros(); }
}

async function limparErros() {
  const op = prompt('O que remover?\n\n1 = apenas os resolvidos\n2 = registros com mais de 30 dias\n3 = TUDO\n\nDigite 1, 2 ou 3:', '1');
  if (!op) return;
  const modo = op.trim() === '3' ? 'tudo' : (op.trim() === '2' ? 'antigos' : 'resolvidos');
  if (modo === 'tudo' && !confirm('Apagar TODO o histórico de erros?')) return;
  const res = await post({ action:'erros_limpar', modo });
  if (res.ok) { toast('Registros removidos'); carregarErros(); }
}

// ═══════════════════════════════════════════
// AMEAÇAS
// ═══════════════════════════════════════════
const TIPO_AMEACA = {
  FORCA_BRUTA:     'Tentativas de senha em excesso',
  ACESSO_NEGADO:   'Acesso a página sem permissão',
  SEM_LOGIN:       'Página restrita sem sessão',
  SESSAO_INVALIDA: 'Sessão expirada em uso',
  ERRO_CRITICO:    'Erro crítico na aplicação',
  UPLOAD_SUSPEITO: 'Envio de arquivo recusado',
  CSRF_SUSPEITO:   'Ação sem token de confirmação',
  OUTRO:           'Outro',
};

async function carregarAmeacas() {
  const box = document.getElementById('am-lista');
  const res = await post({
    action:     'ameacas_listar',
    severidade: document.getElementById('am-sev').value,
    tipo:       document.getElementById('am-tipo').value,
    revisadas:  document.getElementById('am-revisadas').checked ? '1' : '0',
  }).catch(() => null);

  if (!res || !res.ok) { box.innerHTML = '<div class="vazio-msg">Erro ao carregar.</div>'; return; }

  const r = res.resumo || {};
  document.getElementById('am-abertas').textContent = r.abertas ?? 0;
  document.getElementById('am-graves').textContent  = r.graves  ?? 0;
  document.getElementById('am-24h').textContent     = r.h24     ?? 0;
  document.getElementById('am-ips').textContent     = r.ips     ?? 0;

  const badge = document.getElementById('nav-badge-ameacas');
  const graves = parseInt(r.graves || 0, 10);
  badge.style.display = graves > 0 ? '' : 'none';
  badge.textContent = graves > 99 ? '99+' : graves;

  if (!res.data.length) {
    box.innerHTML = '<div class="vazio-msg"><i class="fas fa-shield-halved" style="color:var(--green)"></i>' +
                    'Nenhuma ameaça registrada. Isso é bom.</div>';
    return;
  }

  box.innerHTML = res.data.map(a => `
    <div class="ev ev-${esc(a.severidade)} ${a.revisado == 1 ? 'ev-resolvido' : ''}">
      <div class="ev-topo">
        <span class="tag tag-${esc(a.severidade)}">${esc(a.severidade)}</span>
        <span class="tag tag-n">${esc(TIPO_AMEACA[a.tipo] || a.tipo)}</span>
        ${a.ocorrencias > 1 ? `<span class="tag tag-n">${esc(a.ocorrencias)}x</span>` : ''}
        ${a.revisado == 1 ? `<span class="tag tag-n">revisada por ${esc(a.revisado_por || '')}</span>` : ''}
        <div class="ev-acoes">
          ${a.revisado == 1 ? '' :
            `<button class="btn btn-ghost btn-sm" onclick="revisarAmeaca(${a.id})">
               <i class="fas fa-check"></i> Marcar como revisada</button>`}
        </div>
      </div>
      <div class="ev-detalhe">${esc(a.detalhe || '')}</div>
      <div class="ev-meta">
        ${a.usuario_alvo ? `<span><i class="fas fa-user-lock"></i>alvo: <b>${esc(a.usuario_alvo)}</b></span>` : ''}
        <span><i class="fas fa-network-wired"></i>IP: ${esc(a.ip_rede || '—')}</span>
        ${a.ip_local ? `<span><i class="fas fa-house-laptop"></i>rede interna: ${esc(a.ip_local)}</span>` : ''}
        <span><i class="fas fa-desktop"></i>${esc(a.dispositivo || '—')} · ${esc(a.sistema || '—')} · ${esc(a.navegador || '—')}</span>
        ${a.pagina ? `<span><i class="fas fa-file"></i>${esc(a.pagina)}</span>` : ''}
        <span><i class="fas fa-clock"></i>${esc(a.ultima)}</span>
        ${a.ocorrencias > 1 ? `<span><i class="fas fa-hourglass-start"></i>desde ${esc(a.primeira)}</span>` : ''}
      </div>
    </div>`).join('');
}

async function revisarAmeaca(id) {
  const res = await post({ action:'ameaca_revisar', id });
  if (res.ok) { toast('Ameaça marcada como revisada'); carregarAmeacas(); }
}

// ═══════════════════════════════════════════
// BACKUPS
// ═══════════════════════════════════════════
function tamanhoLegivel(b) {
  b = parseInt(b || 0, 10);
  if (b <= 0) return '—';
  if (b < 1024) return b + ' B';
  if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
  return (b / 1048576).toFixed(2) + ' MB';
}

async function carregarBackups() {
  const res = await post({ action: 'backup_status' }).catch(() => null);
  if (!res || !res.ok) { toast('Erro ao carregar backups', 'err'); return; }

  // ── Resumo ──
  const ult = res.execucoes[0];
  if (ult) {
    document.getElementById('bk-ultimo').textContent = ult.data + ' ' + ult.hora;
    const horas = parseInt(ult.horas_atras || 0, 10);
    const quando = horas < 24 ? `há ${horas}h` : `há ${Math.floor(horas/24)} dia(s)`;
    document.getElementById('bk-ultimo-sub').textContent = ult.situacao + ' · ' + quando;
    document.getElementById('bk-ultimo').style.color =
      ult.situacao === 'EXITO' ? 'var(--green)' : (ult.situacao === 'FALHA' ? 'var(--red)' : 'var(--amber)');
  } else {
    document.getElementById('bk-ultimo').textContent = 'Nenhum';
    document.getElementById('bk-ultimo-sub').textContent = 'sem registro no banco';
  }

  const totalLocal = res.locais.reduce((s, f) => s + f.tamanho, 0);
  document.getElementById('bk-locais').textContent = res.locais.length;
  document.getElementById('bk-locais-sub').textContent =
    tamanhoLegivel(totalLocal) + ' · mantém ' + res.manter;

  document.getElementById('bk-drive').textContent = res.drive.ativo ? 'Conectado' : 'Não configurado';
  document.getElementById('bk-drive').style.color = res.drive.ativo ? 'var(--green)' : 'var(--amber)';
  document.getElementById('bk-drive-sub').textContent = res.drive.ativo
    ? (res.drive.pasta || '') : 'configure em backup_oauth.php';

  const cronEl = document.getElementById('bk-cron');
  if (res.cron) {
    const p = res.cron.trim().split(/\s+/);
    const dias = ['domingo','segunda','terça','quarta','quinta','sexta','sábado'];
    const dia = p[4] === '*' ? 'todo dia' : (dias[parseInt(p[4],10)] || p[4]);
    cronEl.textContent = `${dia} ${String(p[1]).padStart(2,'0')}:${String(p[0]).padStart(2,'0')}`;
    cronEl.style.color = 'var(--green)';
  } else {
    cronEl.textContent = 'Não agendado';
    cronEl.style.color = 'var(--red)';
  }

  // ── Execuções ──
  const box = document.getElementById('bk-execucoes');
  if (!res.execucoes.length) {
    box.innerHTML = '<div class="vazio-msg"><i class="fas fa-database"></i>' +
      'Nenhuma execução registrada ainda. O registro começa no próximo backup.</div>';
  } else {
    box.innerHTML = res.execucoes.map(b => `
      <div class="bk bk-${esc(b.situacao)}">
        <div class="bk-data">${esc(b.data)}<br><span style="color:var(--txt-4)">${esc(b.hora)}</span></div>
        <div class="bk-info">
          <span class="tag tag-${b.situacao === 'EXITO' ? 'BAIXA' : (b.situacao === 'FALHA' ? 'CRITICA' : 'MEDIA')}">
            ${esc(b.situacao)}</span>
          <span class="tag tag-n">${esc(b.origem)}</span>
          <div style="margin-top:6px">
            ${esc(b.tabelas)} tabelas · ${Number(b.linhas).toLocaleString('pt-BR')} linhas ·
            ${tamanhoLegivel(b.tamanho)} · ${esc(b.duracao)}s
          </div>
          <div style="margin-top:4px;color:var(--txt-4)">
            <i class="fas fa-${b.local_ok == 1 ? 'check' : 'xmark'}"
               style="color:${b.local_ok == 1 ? 'var(--green)' : 'var(--red)'}"></i> Servidor &nbsp;
            <i class="fas fa-${b.drive_ok == 1 ? 'check' : 'xmark'}"
               style="color:${b.drive_ok == 1 ? 'var(--green)' : 'var(--red)'}"></i> Drive
            ${b.arquivo ? ' &nbsp;·&nbsp; ' + esc(b.arquivo) : ''}
          </div>
        </div>
      </div>`).join('');
  }

  // ── Arquivos ──
  const fbox = document.getElementById('bk-arquivos');
  fbox.innerHTML = res.locais.length
    ? `<div style="font-size:11px;color:var(--txt-4);margin-bottom:10px;font-family:var(--font-mono)">${esc(res.pasta)}</div>` +
      res.locais.map(f => `
        <div style="display:flex;justify-content:space-between;gap:10px;padding:7px 0;
                    border-bottom:1px solid var(--border);font-size:12px">
          <span style="font-family:var(--font-mono);color:var(--txt-2);word-break:break-all">${esc(f.nome)}</span>
          <span style="color:var(--txt-4);white-space:nowrap">${esc(f.data)} · ${tamanhoLegivel(f.tamanho)}</span>
        </div>`).join('')
    : '<div class="vazio-msg">Nenhum arquivo na pasta de backup.</div>';

  document.getElementById('bk-log').textContent = res.log || 'Sem log registrado.';
}

async function rodarBackupAgora() {
  if (!confirm('Executar um backup completo agora?\n\nRoda em segundo plano e leva alguns minutos.')) return;
  const res = await post({ action: 'backup_agora' });
  toast(res.msg || (res.ok ? 'Iniciado' : 'Falhou'), res.ok ? 'ok' : 'err');
  if (res.ok) setTimeout(carregarBackups, 15000);
}

// ═══════════════════════════════════════════
// AUDITORIA
// ═══════════════════════════════════════════
async function carregarAuditoria() {
  const box = document.getElementById('aud-lista');
  box.innerHTML = '<div class="vazio-msg"><i class="fas fa-spinner fa-spin"></i>Verificando...</div>';
  const res = await post({ action: 'auditoria' }).catch(() => null);
  if (!res || !res.ok) { box.innerHTML = '<div class="vazio-msg">Erro ao verificar.</div>'; return; }

  box.innerHTML = res.itens.map(i => `
    <div class="aud">
      <div class="aud-ic ${i.ok ? 'aud-ok' : 'aud-no'}">
        <i class="fas fa-${i.ok ? 'check' : 'exclamation'}"></i>
      </div>
      <div style="flex:1">
        <div class="aud-t">${esc(i.nome)}
          ${i.ok ? '' : `<span class="tag tag-${i.risco === 'ALTO' ? 'CRITICA' : (i.risco === 'MEDIO' ? 'MEDIA' : 'BAIXA')}"
                          style="margin-left:8px">risco ${esc(i.risco.toLowerCase())}</span>`}
        </div>
        <div class="aud-d">${esc(i.detalhe)}</div>
      </div>
      ${i.acao ? `<button class="btn btn-primary btn-sm" style="flex-shrink:0"
                    onclick="acaoAuditoria('${esc(i.acao[0])}','${esc(i.nome)}')">
                    <i class="fas fa-wrench"></i> ${esc(i.acao[1])}</button>` : ''}
    </div>`).join('');
}

async function acaoAuditoria(acao, nome) {
  const avisos = {
    corrigir_senhas: 'Cifrar as senhas que estão em texto puro?\n\n' +
                     'Ninguém perde acesso: as senhas continuam as mesmas para os usuários, ' +
                     'só deixam de ficar legíveis no banco. A ação não tem volta.',
    limpar_sessoes:  'Remover registros de sessão parados há mais de 12 horas?\n\n' +
                     'Não desconecta ninguém que esteja usando o sistema agora.',
  };
  if (!confirm(avisos[acao] || ('Executar: ' + nome + '?'))) return;

  const res = await post({ action: acao }).catch(() => null);
  if (res && res.ok) { toast(res.msg || 'Concluído'); carregarAuditoria(); }
  else toast('Não foi possível executar', 'err');
}

// ═══════════════════════════════════════════
// BLOQUEIOS DE LOGIN
// ═══════════════════════════════════════════
function tempoRestante(seg) {
  seg = parseInt(seg || 0, 10);
  if (seg <= 0) return '';
  const m = Math.floor(seg / 60), s = seg % 60;
  return m > 0 ? `${m}min ${s}s` : `${s}s`;
}

async function carregarBloqueios() {
  const tb = document.getElementById('tbody-bloqueios');
  if (!tb) return;
  const res = await post({ action: 'listar_bloqueios' }).catch(() => null);
  if (!res || !res.ok) {
    tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:28px;color:var(--red)">Erro ao carregar</td></tr>';
    return;
  }
  if (!res.data.length) {
    tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:28px;color:var(--txt-4)">Nenhuma tentativa registrada.</td></tr>';
    return;
  }
  tb.innerHTML = res.data.map(b => {
    const restam = parseInt(b.restam || 0, 10);
    const sit = restam > 0
      ? `<span style="color:var(--red);font-weight:600">Bloqueado — ${tempoRestante(restam)}</span>`
      : `<span style="color:var(--txt-4)">Liberado</span>`;
    return `<tr>
      <td>${esc(b.tipo)}</td>
      <td style="font-weight:500">${esc(b.valor)}</td>
      <td>${esc(b.tentativas)}</td>
      <td>${esc(b.bloqueios)}</td>
      <td>${sit}</td>
      <td style="color:var(--txt-4)">${esc(b.ultima_falha || '—')}</td>
      <td style="text-align:right">
        <button class="btn btn-ghost btn-sm" onclick="liberarBloqueio(${parseInt(b.id,10)})">
          <i class="fas fa-unlock"></i> Liberar
        </button>
      </td>
    </tr>`;
  }).join('');
}

async function liberarBloqueio(id) {
  const res = await post({ action: 'liberar_bloqueio', id });
  if (res.ok) { toast('Bloqueio liberado'); carregarBloqueios(); }
  else toast('Erro ao liberar', 'err');
}

async function liberarTodosBloqueios() {
  if (!confirm('Liberar todos os bloqueios de login?\n\nTodos os contadores de tentativa serão zerados.')) return;
  const res = await post({ action: 'liberar_todos_bloqueios' });
  if (res.ok) { toast('Todos os bloqueios foram liberados'); carregarBloqueios(); }
  else toast('Erro ao liberar', 'err');
}

async function excluirUsuario(id) {
  id = parseInt(id);
  const u = usuarioMap.get(id);
  const nome = u ? u.usuario : '#' + id;
  if (!confirm(`Excluir "${nome}"?\n\nEsta ação não pode ser desfeita.\nDica: prefira bloquear em vez de excluir.`)) return;
  const res = await post({ action: 'excluir_usuario', id });
  if (res.ok) { toast(`Usuário "${nome}" excluído`); carregarUsuarios(); }
  else toast('Erro ao excluir', 'err');
}

// ═══════════════════════════════════════════
// ONLINE (tempo real)
// ═══════════════════════════════════════════
let onlinePollingTimer = null;
let onlineAtivo        = false;
let onlineErros        = 0;

function iniciarPollingOnline() { onlineAtivo = true; onlineErros = 0; atualizarOnline(); }
function pararPollingOnline()  { onlineAtivo = false; onlineErros = 0; clearTimeout(onlinePollingTimer); }

async function atualizarOnline(silencioso = false) {
  if (!onlineAtivo) return;
  const grid = document.getElementById('online-grid');
  if (!silencioso && grid.children.length === 0)
    grid.innerHTML = '<div class="online-empty"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';

  const res = await post({ action: 'usuarios_online' }).catch(() => null);
  if (!onlineAtivo) return;

  if (!res || !res.ok) {
    onlineErros++;
    if (onlineErros >= 3)
      grid.innerHTML = '<div class="online-empty" style="color:var(--red)"><i class="fas fa-triangle-exclamation"></i>&nbsp; Erro ao carregar sessões.</div>';
  } else {
    onlineErros = 0;
    if (!res.data.length) {
      grid.innerHTML = '<div class="online-empty"><i class="fas fa-circle-xmark" style="color:var(--txt-4)"></i>&nbsp; Nenhum usuário online no momento.</div>';
    } else {
      renderOnlineCards(res.data);
      atualizarContadorOnline(res.data.length);
    }
  }
  onlinePollingTimer = setTimeout(() => atualizarOnline(true), 15000);
}

function renderOnlineCards(data) {
  const grid = document.getElementById('online-grid');
  const existentes = new Set([...grid.querySelectorAll('.online-card')].map(c => c.dataset.sid));
  const novos      = new Set(data.map(u => u.session));
  existentes.forEach(sid => {
    if (!novos.has(sid)) {
      const card = document.getElementById('card-' + sid);
      if (card) { card.style.opacity='0'; card.style.transform='scale(.95)'; setTimeout(()=>card.remove(),250); }
    }
  });
  data.forEach((u, i) => {
    const existing = document.getElementById('card-' + u.session);
    if (existing) { const m = existing.querySelector('.meta-ativo'); if(m) m.textContent='ativo '+u.ultimo_acesso; return; }
    const card = document.createElement('div');
    card.className   = 'online-card' + (u.eu ? ' me' : '');
    card.id          = 'card-' + u.session;
    card.dataset.sid = u.session;
    card.style.cssText = 'opacity:0;transform:translateY(8px);transition:opacity .25s ease,transform .25s ease';
    card.innerHTML = `
      <div class="online-avatar">${esc(u.usuario.substring(0,2).toUpperCase())}</div>
      <div class="online-info">
        <div class="online-name">
          ${esc(u.usuario)}
          ${u.eu ? '<span style="font-size:10px;color:var(--blue-hi);font-family:var(--font-mono);margin-left:4px">(você)</span>' : ''}
        </div>
        <div class="online-meta">
          <span class="dot-online"></span>
          <span>${esc(u.classe_usuario)}</span>
          <span>·</span>
          <span class="meta-ativo">ativo ${esc(u.ultimo_acesso)}</span>
        </div>
        <div style="font-size:10px;color:var(--txt-4);font-family:var(--font-mono);margin-top:2px">
          Login: ${esc(u.login_em)} · IP: ${esc(u.ip || '—')}
        </div>
      </div>
      ${u.eu
        ? '<span style="font-size:11px;color:var(--txt-4);padding:5px 10px;white-space:nowrap">Sessão atual</span>'
        : `<button class="btn-deslogar" data-sid="${u.session}" data-nome="${esc(u.usuario)}" onclick="deslogarUsuario(this)">
             <i class="fas fa-right-from-bracket"></i> Deslogar
           </button>`}
    `;
    const empty = grid.querySelector('.online-empty');
    if (empty) empty.remove();
    grid.appendChild(card);
    setTimeout(() => { card.style.opacity='1'; card.style.transform='none'; }, i * 40);
  });
}

function atualizarContadorOnline(count) {
  const h = document.getElementById('online-count-badge');
  if (h) h.textContent = count + ' online';
  let badge = document.getElementById('badge-online');
  if (!badge) {
    const nl = [...document.querySelectorAll('.nav-link')].find(l => l.textContent.includes('Online'));
    if (nl) {
      badge = document.createElement('span');
      badge.id = 'badge-online';
      badge.style.cssText = 'margin-left:auto;background:var(--green);color:#000;border-radius:10px;font-size:10px;font-weight:700;padding:1px 7px;font-family:var(--font-mono)';
      nl.appendChild(badge);
    }
  }
  if (badge) badge.textContent = count;
  iniciarCountdown(15);
}

let countdownTimer = null;
function iniciarCountdown(s) {
  clearInterval(countdownTimer);
  const label = document.getElementById('online-refresh-label');
  if (!label) return;
  let t = s;
  label.textContent = `atualiza em ${t}s`;
  countdownTimer = setInterval(() => {
    t--;
    if (t <= 0) { clearInterval(countdownTimer); label.textContent='atualizando...'; }
    else label.textContent = `atualiza em ${t}s`;
  }, 1000);
}

async function deslogarUsuario(btn) {
  const sid  = btn.dataset.sid;
  const nome = btn.dataset.nome;
  if (!confirm(`Deslogar "${nome}"?\n\nO usuário será redirecionado ao login na próxima ação.`)) return;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  const res = await post({ action: 'deslogar_usuario', session_id: sid });
  if (res.ok) {
    const card = document.getElementById('card-' + sid);
    if (card) { card.style.opacity='0'; card.style.transform='scale(.95)'; card.style.transition='all .25s ease'; setTimeout(()=>card.remove(),260); }
    toast(`${nome} foi deslogado`);
  } else {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-right-from-bracket"></i> Deslogar';
    toast('Sessão não encontrada (já expirou?)', 'info');
  }
}

// ═══════════════════════════════════════════
// AUTORIZAÇÃO
// ═══════════════════════════════════════════
async function carregarAutorizacao() {
  const res = await post({ action: 'listar_autorizacao' });
  if (!res.ok) return toast('Erro ao carregar', 'err');
  document.getElementById('tbody-autorizacao').innerHTML = res.data.map(a => `
    <tr>
      <td class="mono" style="color:var(--txt-4)">#${a.id}</td>
      <td>
        <input type="text" value="${esc(a.senha)}" id="auth-${a.id}"
          style="background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--amber);font-family:var(--font-mono);font-size:13px;padding:6px 10px;outline:none;width:100%;transition:border-color var(--transition);"
          onfocus="this.style.borderColor='var(--blue)'"
          onblur="this.style.borderColor='var(--border)'">
      </td>
      <td style="text-align:right">
        <button class="btn btn-green btn-sm" onclick="salvarAutorizacao(${a.id})"><i class="fas fa-floppy-disk"></i> Salvar</button>
      </td>
    </tr>
  `).join('');
}

async function salvarAutorizacao(id) {
  const senha = document.getElementById('auth-' + id).value;
  const res   = await post({ action: 'editar_autorizacao', id, senha });
  if (res.ok) toast('Senha de autorização atualizada');
  else toast('Erro ao salvar', 'err');
}

// ═══════════════════════════════════════════
// INSIGHTS
// ═══════════════════════════════════════════
function insightBanco(mb) {
  mb = parseFloat(mb)||0;
  if (mb<10)   return['ok',    '🟢 Banco enxuto — praticamente vazio.'];
  if (mb<50)   return['ok',    '🟢 Tamanho saudável. Muita margem.'];
  if (mb<150)  return['ok',    '🟡 Banco em crescimento normal.'];
  if (mb<300)  return['warn',  '🟡 Crescendo. Monitore tabelas grandes.'];
  if (mb<500)  return['warn',  '🟠 Atenção: acima de 300 MB.'];
  if (mb<1000) return['danger','🔴 Banco pesado. Revise dados antigos.'];
  return['danger','🔴 Banco crítico. Migre imagens para externo.'];
}
function insightUptime(s) {
  const d=parseInt(s.match(/(\d+)d/)?.[1]||0), h=parseInt(s.match(/(\d+)h/)?.[1]||0), t=d*24+h;
  if (t<1)   return['warn',  '🟡 MySQL reiniciado há menos de 1h.'];
  if (t<6)   return['warn',  '🟡 MySQL subiu há poucas horas.'];
  if (t<24)  return['ok',    '🟢 MySQL estável hoje.'];
  if (t<72)  return['ok',    `🟢 Estável há ${d} dia(s).`];
  if (t<720) return['ok',    `🟢 ${d} dias sem interrupções.`];
  return['purple',`🏆 Uptime excepcional — ${d} dias sem restart.`];
}
function insightQueries(s) {
  const q=parseInt((s||'0').replace(/\./g,'').replace(/,/g,'')), M=1e6;
  if (q<10*M)    return['ok',    '🟢 Servidor recém-iniciado ou uso mínimo.'];
  if (q<500*M)   return['ok',    '🟢 Volume normal acumulado.'];
  if (q<2000*M)  return['ok',    '🟢 Volume alto — normal em servidor compartilhado.'];
  if (q<5000*M)  return['ok',    '🟡 Servidor muito ativo. Contador global, não só PatAsset.'];
  if (q<10000*M) return['info',  'ℹ️ Contador global elevado. Normal após muitos dias sem restart.'];
  return['info','ℹ️ Uptime longo — contador acumula de todos os sistemas do servidor.'];
}
function insightThreads(t) {
  t=parseInt(t)||0;
  if (t<=2)  return['ok',   '🟢 Servidor ocioso.'];
  if (t<=5)  return['ok',   '🟢 Carga leve.'];
  if (t<=10) return['ok',   '🟢 Uso normal.'];
  if (t<=20) return['ok',   '🟡 Uso moderado.'];
  if (t<=40) return['warn', '🟠 Uso elevado. Monitore.'];
  if (t<=80) return['danger','🔴 Muitas conexões ativas.'];
  return['danger','🔴 Conexões críticas.'];
}
function insightMemoria(s) {
  const mb=parseFloat((s||'0').replace(' MB',''))||0;
  if (mb<8)   return['ok',   '🟢 Memória mínima.'];
  if (mb<16)  return['ok',   '🟢 Uso normal.'];
  if (mb<32)  return['ok',   '🟡 Uso moderado.'];
  if (mb<64)  return['warn', '🟠 Memória acima do usual.'];
  if (mb<128) return['danger','🔴 Alto consumo de memória.'];
  return['danger','🔴 Memória crítica.'];
}
function insightPHP(v) {
  if (!v||v==='—') return['info','ℹ️ Versão não identificada.'];
  const [major,minor]=[parseInt(v),parseInt(v.split('.')[1])];
  if (major<7)          return['danger','🔴 PHP desatualizado. Vulnerabilidades.'];
  if (major===7&&minor<4) return['danger','🔴 PHP 7.x sem suporte. Atualize urgente.'];
  if (major===7&&minor===4) return['warn','🟠 PHP 7.4 — fim do suporte.'];
  if (major===8&&minor===0) return['warn','🟡 PHP 8.0 — suporte encerrado.'];
  if (major===8&&minor===1) return['warn','🟡 PHP 8.1 — considere atualizar.'];
  if (major===8&&minor===2) return['ok',  '🟢 PHP 8.2 — estável e suportado.'];
  if (major===8&&minor===3) return['ok',  '✅ PHP 8.3 — versão mais recente.'];
  return['ok','✅ PHP atualizado.'];
}
function renderInsight(id, tipo, msg) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg;
  el.className = 'stat-insight insight-' + tipo;
}

// ═══════════════════════════════════════════
// MÉTRICAS
// ═══════════════════════════════════════════
async function carregarMetricas() {
  const res = await post({ action: 'metricas' });
  if (!res.ok) return;

  // ── PatAsset ─────────────────────────────────────────────────────────────
  document.getElementById('pd-cadastro').textContent = res.total_cadastro || '—';
  document.getElementById('pd-ativos').textContent   = res.total_ativos   || '—';
  document.getElementById('pd-baixa').textContent    = res.total_baixa    || '—';
  document.getElementById('pd-mov').textContent      = res.total_mov      || '—';
  document.getElementById('pd-acessos').textContent  = res.acessos_hoje   ?? '—';
  document.getElementById('pd-online').textContent   = res.total_online   ?? '—';

  // Insight patrimônios ativos vs total
  const tot = parseInt((res.total_cadastro || '0').replace(/\./g,''));
  const atv = parseInt((res.total_ativos   || '0').replace(/\./g,''));
  const pct = tot > 0 ? Math.round(atv/tot*100) : 0;
  const elCad = document.getElementById('ins-cadastro');
  const elAtv = document.getElementById('ins-ativos');
  if (elCad) { elCad.textContent = `${pct}% ativos do total`; elCad.className = 'stat-insight ' + (pct >= 80 ? 'insight-ok' : pct >= 50 ? 'insight-warn' : 'insight-danger'); }
  if (elAtv) { elAtv.textContent = `${100-pct}% inativos/baixa`; elAtv.className = 'stat-insight insight-info'; }

  // Última atividade
  const ultCad = res.ult_cad;
  const ultMov = res.ult_mov;
  const elUC = document.getElementById('pd-ult-cad');
  const elUM = document.getElementById('pd-ult-mov');
  if (elUC) elUC.innerHTML = ultCad
    ? `<span style="color:var(--txt-1)">${esc(ultCad.dt)}</span><br><span style="color:var(--txt-3);font-size:11px">por ${esc(ultCad.usuario_cadastro || '—')}</span>`
    : '<span style="color:var(--txt-4)">nenhum registro</span>';
  if (elUM) elUM.innerHTML = ultMov
    ? `<span style="color:var(--txt-1)">${esc(ultMov.dt)}</span><br><span style="color:var(--txt-3);font-size:11px">por ${esc(ultMov.usuario_mov || '—')}</span>`
    : '<span style="color:var(--txt-4)">nenhum registro</span>';

  // ── Servidor ─────────────────────────────────────────────────────────────
  document.getElementById('pd-size').textContent   = (res.size_mb || '0') + ' MB';
  document.getElementById('pd-uptime').textContent = res.uptime;
  document.getElementById('pd-threads').textContent= res.threads;
  document.getElementById('pd-php').textContent    = res.php_version;
  document.getElementById('pd-mem').textContent    = res.memory_usage;

  // Maior tabela
  if (res.maior_tabela) {
    document.getElementById('pd-maior-mb').textContent   = res.maior_tabela.mb + ' MB';
    document.getElementById('pd-maior-nome').textContent = res.maior_tabela.table_name;
    const elM = document.getElementById('ins-maior');
    if (elM) {
      const mb = parseFloat(res.maior_tabela.mb) || 0;
      elM.textContent  = mb > 50 ? '🟠 Considere otimizar esta tabela.' : '🟢 Tamanho saudável.';
      elM.className    = 'stat-insight ' + (mb > 50 ? 'insight-warn' : 'insight-ok');
    }
  }

  // Insights servidor
  renderInsight('ins-size',   ...insightBanco(res.size_mb));
  renderInsight('ins-uptime', ...insightUptime(res.uptime));
  renderInsight('ins-threads',...insightThreads(res.threads));
  renderInsight('ins-mem',    ...insightMemoria(res.memory_usage));
  renderInsight('ins-php',    ...insightPHP(res.php_version));

  // Atualiza Controle
  const ct = document.getElementById('cc-time');
  const cp = document.getElementById('cc-php');
  if (ct) ct.textContent = res.server_time;
  if (cp) cp.textContent = res.php_version;

  // Tabelas
  const r2 = await post({ action: 'log_tabelas' });
  if (r2.ok) {
    document.getElementById('tbody-tabelas').innerHTML = r2.data.map(t => `
      <tr>
        <td class="mono" style="color:var(--txt-1)">${esc(t.table_name)}</td>
        <td class="mono" style="color:var(--txt-2)">${Number(t.table_rows||0).toLocaleString('pt-BR')}</td>
        <td class="mono" style="color:var(--txt-2)">${t.kb||0} KB</td>
        <td class="mono" style="color:var(--txt-3);font-size:11px">${t.update_time||'N/A'}</td>
      </tr>
    `).join('');
  }
}

// ═══════════════════════════════════════════
// BACKUP
// ═══════════════════════════════════════════
/**
 * Baixa uma cópia. escopo: 'geral' | 'patasset' | 'lifetech' | nome da tabela.
 * O nome do arquivo vem do cabeçalho Content-Disposition enviado pelo servidor,
 * que já sabe o que colocou dentro — montar o nome aqui daria margem a um
 * arquivo chamado "completo" contendo só uma tabela.
 */
async function fazerBackup(escopo = 'geral') {
  const rotulos = { geral:'banco completo', patasset:'PatAsset', lifetech:'LifeTech' };
  const nome = rotulos[escopo] || ('tabela ' + escopo);

  const btns = document.querySelectorAll('#page-backups .btn');
  btns.forEach(b => b.disabled = true);
  toast('Gerando cópia — ' + nome + '. Pode levar alguns minutos...', 'info');

  try {
    const fd = new FormData();
    fd.append('action', 'backup');
    fd.append('escopo', escopo);
    const r = await fetch('dev_painel.php', { method: 'POST', body: fd, cache: 'no-store' });
    if (!r.ok) throw new Error('HTTP ' + r.status);

    const ct = r.headers.get('content-type') || '';
    if (ct.includes('json')) {
      const j = await r.json();
      throw new Error(j.msg || 'Erro no servidor');
    }

    // Nome vindo do servidor
    let arquivo = 'backup.sql';
    const cd = r.headers.get('content-disposition') || '';
    const m  = /filename="?([^"]+)"?/.exec(cd);
    if (m) arquivo = m[1];

    const blob = await r.blob();
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = arquivo;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);

    const mb = (blob.size / 1048576).toFixed(1);
    toast('Cópia baixada: ' + arquivo + ' (' + mb + ' MB)', 'ok');
  } catch(e) {
    toast('Erro no backup: ' + e.message, 'err');
  } finally {
    btns.forEach(b => b.disabled = false);
  }
}

function baixarTabela() {
  const t = document.getElementById('bk-tabela').value;
  if (!t) { toast('Escolha uma tabela', 'err'); return; }
  fazerBackup(t);
}

async function carregarTabelasBackup() {
  const sel = document.getElementById('bk-tabela');
  if (!sel) return;
  const res = await post({ action: 'backup_tabelas' }).catch(() => null);
  if (!res || !res.ok) { sel.innerHTML = '<option value="">Erro ao carregar</option>'; return; }

  const nomes = { PATASSET:'PatAsset', LIFETECH:'LifeTech', COMUM:'Comuns aos dois sistemas' };
  const grupos = {};
  res.tabelas.forEach(t => { (grupos[t.grupo] = grupos[t.grupo] || []).push(t); });

  let html = '<option value="">Selecione uma tabela...</option>';
  ['PATASSET','LIFETECH','COMUM'].forEach(g => {
    if (!grupos[g]) return;
    html += `<optgroup label="${esc(nomes[g] || g)}">`;
    grupos[g].forEach(t => {
      html += `<option value="${esc(t.tabela)}">${esc(t.tabela)} — ${Number(t.linhas).toLocaleString('pt-BR')} linhas</option>`;
    });
    html += '</optgroup>';
  });
  sel.innerHTML = html;
}

// ═══════════════════════════════════════════
// HISTÓRICO DE ACESSOS
// ═══════════════════════════════════════════
let acessosPagAtual = 1;
let acessosTotalReg = 0;
let acessosDebTimer = null;
let todosAcessosCache = [];

function debounceAcessos() {
  clearTimeout(acessosDebTimer);
  acessosDebTimer = setTimeout(() => { acessosPagAtual = 1; carregarAcessos(); }, 350);
}

async function carregarAcessos() {
  const porPag   = parseInt(document.getElementById('ac-por-pagina').value);
  const offset   = (acessosPagAtual - 1) * porPag;
  const filtroU  = document.getElementById('ac-filtro-usuario').value.trim();
  const filtroR  = document.getElementById('ac-filtro-resultado').value;

  document.getElementById('tbody-acessos').innerHTML =
    '<tr><td colspan="10" style="text-align:center;padding:24px;color:var(--txt-4)"><i class="fas fa-spinner fa-spin"></i></td></tr>';

  const res = await post({
    action: 'listar_acessos',
    limit:  porPag,
    offset: offset,
    filtro_usuario:   filtroU,
    filtro_resultado: filtroR,
  });

  if (!res.ok) { toast('Erro ao carregar acessos', 'err'); return; }

  acessosTotalReg    = res.total;
  todosAcessosCache  = res.data;

  const ok  = res.data.filter(r => r.resultado === 'OK').length;
  const fl  = res.data.filter(r => r.resultado === 'FALHA').length;
  const bl  = res.data.filter(r => r.resultado === 'BLOQUEADO').length;
  document.getElementById('ac-ok').textContent       = ok;
  document.getElementById('ac-falha').textContent     = fl;
  document.getElementById('ac-bloqueado').textContent = bl;
  document.getElementById('ac-total').textContent     = res.total;

  const totalPags = Math.max(1, Math.ceil(res.total / porPag));
  document.getElementById('ac-pag-label').textContent = `pág ${acessosPagAtual} / ${totalPags}`;
  document.getElementById('ac-btn-prev').disabled = acessosPagAtual <= 1;
  document.getElementById('ac-btn-next').disabled = acessosPagAtual >= totalPags;
  document.getElementById('ac-contador').textContent =
    res.total === 0 ? '0 registros'
    : `${offset + 1}–${Math.min(offset + porPag, res.total)} de ${res.total} registros`;

  renderAcessos(res.data);
}

function renderAcessos(data) {
  const tbody = document.getElementById('tbody-acessos');
  if (!data.length) {
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:36px;color:var(--txt-4)">Nenhum registro encontrado.</td></tr>';
    return;
  }

  const badgeRes = r => {
    const map = { 'OK':'badge-green', 'FALHA':'badge-red', 'BLOQUEADO':'badge-orange' };
    return `<span class="badge ${map[r] || 'badge-grey'}">${r}</span>`;
  };

  const parseUA = ua => {
    if (!ua) return '—';
    let os = '', br = '';
    if (/Windows NT 10/i.test(ua))      os = 'Windows 10';
    else if (/Windows NT 11/i.test(ua)) os = 'Windows 11';
    else if (/Windows/i.test(ua))       os = 'Windows';
    else if (/Android/i.test(ua))       os = 'Android';
    else if (/iPhone|iPad/i.test(ua))   os = 'iOS';
    else if (/Mac OS/i.test(ua))        os = 'macOS';
    else if (/Linux/i.test(ua))         os = 'Linux';
    else                                os = '?';
    const chromeM  = ua.match(/Chrome\/([\d]+)/);
    const firefoxM = ua.match(/Firefox\/([\d]+)/);
    const safariM  = ua.match(/Version\/([\d]+).*Safari/);
    const edgeM    = ua.match(/Edg\/([\d]+)/);
    if      (edgeM)    br = `Edge ${edgeM[1]}`;
    else if (chromeM)  br = `Chrome ${chromeM[1]}`;
    else if (firefoxM) br = `Firefox ${firefoxM[1]}`;
    else if (safariM)  br = `Safari ${safariM[1]}`;
    else               br = 'Outro';
    return `${br} · ${os}`;
  };

  tbody.innerHTML = data.map(r => `
    <tr>
      <td class="mono" style="color:var(--txt-4)">${r.id}</td>
      <td class="mono">${esc(r.data)}</td>
      <td class="mono">${esc(r.hora)}</td>
      <td style="font-weight:500;color:var(--txt-1)">${esc(r.usuario)}</td>
      <td>${r.permicao !== 'N/A' ? badgePerm(r.permicao) : '<span style="color:var(--txt-4)">—</span>'}</td>
      <td>${r.classe_usuario !== 'N/A' ? badgeClasse(r.classe_usuario) : '<span style="color:var(--txt-4)">—</span>'}</td>
      <td>${badgeRes(r.resultado)}</td>
      <td class="mono" style="font-size:11px;color:var(--txt-2)">${esc(r.ip_rede || '—')}</td>
      <td class="mono" style="font-size:11px;color:${r.ip_local ? 'var(--blue-hi)' : 'var(--txt-4)'}">${esc(r.ip_local || '—')}</td>
      <td style="font-size:11px;color:var(--txt-3);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
          title="${esc(r.user_agent || '')}">${esc(parseUA(r.user_agent))}</td>
    </tr>
  `).join('');
}

function acessosPagAnterior() {
  if (acessosPagAtual > 1) { acessosPagAtual--; carregarAcessos(); }
}

function acessosPagProxima() {
  const porPag    = parseInt(document.getElementById('ac-por-pagina').value);
  const totalPags = Math.ceil(acessosTotalReg / porPag);
  if (acessosPagAtual < totalPags) { acessosPagAtual++; carregarAcessos(); }
}

function exportarAcessosCSV() {
  if (!todosAcessosCache.length) { toast('Nenhum dado para exportar', 'info'); return; }
  const header = ['ID','Data','Hora','Usuário','Permissão','Classe','Resultado','IP Rede','IP Dispositivo','Navegador/SO'];
  const parseUA = ua => {
    if (!ua) return '';
    let os = '', br = '';
    if (/Windows NT 10/i.test(ua)) os='Windows 10'; else if (/Android/i.test(ua)) os='Android';
    else if (/iPhone|iPad/i.test(ua)) os='iOS'; else if (/Mac OS/i.test(ua)) os='macOS';
    else if (/Linux/i.test(ua)) os='Linux'; else os='?';
    const cm=ua.match(/Chrome\/(\d+)/), fm=ua.match(/Firefox\/(\d+)/), em=ua.match(/Edg\/(\d+)/);
    if (em) br=`Edge ${em[1]}`; else if (cm) br=`Chrome ${cm[1]}`; else if (fm) br=`Firefox ${fm[1]}`; else br='Outro';
    return `${br} · ${os}`;
  };
  const rows = todosAcessosCache.map(r => [
    r.id, r.data, r.hora, r.usuario, r.permicao, r.classe_usuario,
    r.resultado, r.ip_rede||'', r.ip_local||'', parseUA(r.user_agent)
  ].map(v => `"${String(v).replace(/"/g,'""')}"`).join(','));
  const csv  = [header.join(','), ...rows].join('\n');
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `historico_acessos_${new Date().toISOString().slice(0,10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
  toast('CSV exportado', 'ok');
}

async function limparAcessos() {
  if (!confirm('Apagar TODO o histórico de acessos?\n\nEsta ação não pode ser desfeita.')) return;
  const res = await post({ action: 'limpar_acessos' });
  if (res.ok) { toast('Histórico apagado', 'ok'); carregarAcessos(); }
  else toast('Erro ao limpar', 'err');
}

// ═══════════════════════════════════════════
// ESTATÍSTICAS
// ═══════════════════════════════════════════
let todosEst = [];

async function carregarEstatisticas() {
  document.getElementById('tbody-estatisticas').innerHTML =
    '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--txt-4)"><i class="fas fa-spinner fa-spin"></i></td></tr>';
  const res = await post({ action: 'estatisticas_usuarios' });
  if (!res.ok) return toast('Erro ao carregar estatísticas', 'err');
  todosEst = res.data;
  filtrarEstatisticas();
}

function filtrarEstatisticas() {
  const q = (document.getElementById('est-filtro')?.value || '').toLowerCase().trim();
  const data = q ? todosEst.filter(u => u.usuario.toLowerCase().includes(q)) : todosEst;
  const maxTotal = data.reduce((m, u) => Math.max(m, u.total), 1);
  const tbody = document.getElementById('tbody-estatisticas');
  if (!data.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:36px;color:var(--txt-4)">Nenhum dado.</td></tr>';
    return;
  }
  tbody.innerHTML = data.map((u, i) => {
    const pct = Math.round(u.total / maxTotal * 100);
    return `<tr>
      <td class="mono" style="color:var(--txt-4)">${i+1}</td>
      <td style="font-weight:500;color:var(--txt-1)">${esc(u.usuario)}</td>
      <td class="mono" style="color:var(--purple)">${u.logins}</td>
      <td class="mono" style="color:var(--blue)">${u.cadastros}</td>
      <td class="mono" style="color:var(--amber)">${u.movs}</td>
      <td class="mono" style="color:var(--red)">${u.baixas}</td>
      <td><div class="prod-bar-wrap"><span class="mono" style="color:var(--green);min-width:32px">${u.total}</span>
        <div class="prod-bar"><div class="prod-bar-fill" style="width:${pct}%;background:var(--green)"></div></div></div></td>
    </tr>`;
  }).join('');
}

// ═══════════════════════════════════════════
// LOGS — TIMELINE
// ═══════════════════════════════════════════
let logTabAtual = 'acoes';
function mudarLogTab(tab) {
  logTabAtual = tab;
  document.getElementById('log-tab-acoes').className = 'log-tab' + (tab==='acoes'?' active':'');
  document.getElementById('log-tab-erros').className = 'log-tab' + (tab==='erros'?' active':'');
  document.getElementById('log-acoes').style.display = tab==='acoes' ? '' : 'none';
  document.getElementById('log-erros').style.display = tab==='erros' ? '' : 'none';
  if (tab==='acoes') carregarTimeline();
  if (tab==='erros') carregarLogErros();
}

const acaoBadge = a => {
  const map = { CADASTRO:['badge-blue','CADASTRO'], MOVIMENTACAO:['badge-amber','MOVIMENTAÇÃO'], BAIXA:['badge-red','BAIXA'], LOGIN:['badge-green','LOGIN'], LOGOUT:['badge-grey','LOGOUT'], EDICAO:['badge-purple','EDIÇÃO'] };
  const [cls,label] = map[a] || ['badge-grey', a||'—'];
  return `<span class="badge ${cls}">${label}</span>`;
};

async function carregarTimeline() {
  const filtroU = document.getElementById('la-filtro-u')?.value.trim() || '';
  const tbody = document.getElementById('tbody-timeline');
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--txt-4)"><i class="fas fa-spinner fa-spin"></i></td></tr>';
  const res = await post({ action: 'timeline_acoes', filtro_usuario: filtroU });
  if (!res.ok) { toast('Erro ao carregar timeline', 'err'); return; }
  if (!res.data.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:36px;color:var(--txt-4)">Nenhuma ação registrada.</td></tr>';
    return;
  }
  tbody.innerHTML = res.data.map(r => `<tr>
    <td class="mono" style="font-size:11px">${esc(r.data)}</td>
    <td class="mono" style="font-size:11px;color:var(--txt-3)">${esc(r.hora)}</td>
    <td style="font-weight:500;color:var(--txt-1)">${esc(r.usuario||'—')}</td>
    <td>${acaoBadge(r.acao)}</td>
    <td><span class="badge badge-grey" style="font-size:10px">${esc(r.modulo||'—')}</span></td>
    <td style="font-size:12px;color:var(--txt-2);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.descricao||'')}">${esc(r.descricao||'—')}</td>
  </tr>`).join('');
}

// ═══════════════════════════════════════════
// LOG DE ERROS
// ═══════════════════════════════════════════
async function carregarLogErros() {
  const nivel = document.getElementById('le-filtro-nivel')?.value || '';
  const tbody = document.getElementById('tbody-log-erros');
  tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--txt-4)"><i class="fas fa-spinner fa-spin"></i></td></tr>';
  const res = await post({ action: 'listar_log_erros', limit:200, offset:0, filtro_nivel:nivel });
  if (!res.ok) { toast('Erro ao carregar log', 'err'); return; }
  const nivelBadge = n => {
    const map={INFO:'badge-blue',WARNING:'badge-amber',ERROR:'badge-red',CRITICAL:'badge-red'};
    return `<span class="badge ${map[n]||'badge-grey'}">${n}</span>`;
  };
  if (!res.data.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:36px;color:var(--txt-4)"><i class="fas fa-check-circle" style="color:var(--green)"></i> Nenhum erro registrado.</td></tr>';
    return;
  }
  tbody.innerHTML = res.data.map(r => `<tr>
    <td class="mono" style="font-size:11px">${esc(r.data)}</td>
    <td class="mono" style="font-size:11px;color:var(--txt-3)">${esc(r.hora)}</td>
    <td>${nivelBadge(r.nivel)}</td>
    <td class="mono" style="font-size:11px;color:var(--blue-hi)">${esc(r.arquivo||'—')}</td>
    <td style="font-size:12px;color:var(--txt-2);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.mensagem||'')}">${esc(r.mensagem||'—')}</td>
    <td style="font-size:12px">${esc(r.usuario||'—')}</td>
    <td class="mono" style="font-size:11px;color:var(--txt-3)">${esc(r.ip||'—')}</td>
  </tr>`).join('');
}
async function limparLogErros() {
  if (!confirm('Limpar todo o log de erros?')) return;
  const res = await post({ action:'limpar_log_erros' });
  if (res.ok) { toast('Log de erros limpo'); carregarLogErros(); }
  else toast('Erro ao limpar','err');
}

// ═══════════════════════════════════════════
// INVASÕES
// ═══════════════════════════════════════════
async function carregarInvasoes() {
  const tipo = document.getElementById('inv-filtro-tipo')?.value || '';
  const tbody = document.getElementById('tbody-invasoes');
  tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--txt-4)"><i class="fas fa-spinner fa-spin"></i></td></tr>';
  const res = await post({ action:'listar_invasoes', limit:200, offset:0, filtro_tipo:tipo });
  if (!res.ok) { toast('Erro ao carregar','err'); return; }
  const sl=res.data.filter(r=>r.tipo==='SEM_LOGIN').length;
  const pm=res.data.filter(r=>r.tipo==='PERMISSAO_INSUFICIENTE').length;
  const ex=res.data.filter(r=>r.tipo==='SESSAO_EXPIRADA').length;
  ['inv-sem-login','inv-permissao','inv-expirada','inv-total'].forEach((id,i)=>{
    const el=document.getElementById(id); if(el) el.textContent=[sl,pm,ex,res.total][i];
  });
  const tipoBadge = t => {
    const map={SEM_LOGIN:['badge-red','Sem Login'],PERMISSAO_INSUFICIENTE:['badge-orange','Permissão'],SESSAO_EXPIRADA:['badge-amber','Sessão Expirada']};
    const [cls,lab]=map[t]||['badge-grey',t]; return `<span class="badge ${cls}">${lab}</span>`;
  };
  const parseUA = ua => {
    if (!ua) return '—';
    const cm=ua.match(/Chrome\/(\d+)/),em=ua.match(/Edg\/(\d+)/),fm=ua.match(/Firefox\/(\d+)/);
    const br=em?`Edge ${em[1]}`:cm?`Chrome ${cm[1]}`:fm?`Firefox ${fm[1]}`:'Outro';
    const os=/Windows/i.test(ua)?'Win':/Android/i.test(ua)?'Android':/iPhone/i.test(ua)?'iOS':/Mac/i.test(ua)?'Mac':'Linux';
    return `${br} · ${os}`;
  };
  if (!res.data.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:36px;color:var(--txt-4)"><i class="fas fa-shield-check" style="color:var(--green)"></i> Nenhuma tentativa registrada.</td></tr>';
    return;
  }
  tbody.innerHTML = res.data.map(r=>`<tr>
    <td class="mono" style="font-size:11px">${esc(r.data)}</td>
    <td class="mono" style="font-size:11px;color:var(--txt-3)">${esc(r.hora)}</td>
    <td>${tipoBadge(r.tipo)}</td>
    <td class="mono" style="font-size:11px;color:var(--blue-hi)">${esc(r.arquivo||'—')}</td>
    <td style="font-size:12px">${esc(r.usuario_tentativa||'—')}</td>
    <td class="mono" style="font-size:11px;color:var(--red)">${esc(r.ip||'—')}</td>
    <td style="font-size:11px;color:var(--txt-3)">${esc(parseUA(r.user_agent))}</td>
  </tr>`).join('');
}
async function limparInvasoes() {
  if (!confirm('Limpar todo o registro de invasões?')) return;
  const res = await post({ action:'limpar_invasoes' });
  if (res.ok) { toast('Registro limpo'); carregarInvasoes(); }
  else toast('Erro ao limpar','err');
}

// ═══════════════════════════════════════════
// MANUTENÇÃO
// ═══════════════════════════════════════════
async function verificarManutencao() {
  const res = await post({ action:'status_manutencao' });
  if (!res.ok) return;
  const badge=document.getElementById('manut-badge');
  const label=document.getElementById('manut-label');
  const status=document.getElementById('manut-status');
  const btn=document.getElementById('btn-manutencao');
  if (!btn) return;
  if (res.ativa) {
    if (badge) badge.style.display='inline-block';
    if (label) label.textContent='Desligar manutenção';
    btn.style.cssText='background:var(--red-dim);color:var(--red);border:1px solid rgba(239,68,68,.3)';
    if (status) status.textContent='🔴 Sistema em manutenção — usuários não conseguem acessar.';
  } else {
    if (badge) badge.style.display='none';
    if (label) label.textContent='Ligar manutenção';
    btn.style.cssText='background:var(--amber-dim);color:var(--amber);border:1px solid rgba(245,158,11,.3)';
    if (status) status.textContent='🟢 Sistema online — todos os usuários têm acesso normal.';
  }
}

async function toggleManutencao() {
  const msg = document.getElementById('manut-msg')?.value.trim() || '';
  const ativando = document.getElementById('manut-label')?.textContent.includes('Ligar');
  const confirmMsg = ativando
    ? 'Colocar o sistema em manutenção?\n\nUsuários verão a tela de manutenção.\nVocê (DEV) continua com acesso normal.'
    : 'Reativar o sistema? Usuários poderão acessar normalmente.';
  if (!confirm(confirmMsg)) return;
  const btn = document.getElementById('btn-manutencao');
  if (btn) btn.disabled = true;
  const res = await post({ action:'toggle_manutencao', msg_manutencao:msg });
  if (btn) btn.disabled = false;
  if (res.ok) { toast(res.ativa ? '🔴 Sistema em manutenção' : '🟢 Sistema reativado', res.ativa?'warn':'ok'); verificarManutencao(); }
  else toast('Erro ao alternar manutenção','err');
}

// ═══════════════════════════════════════════
// ═══════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════
document.getElementById('search-usr').value = '';
carregarUsuarios();
carregarBloqueios();

// Carrega os contadores das abas de erro e ameaça já na abertura, para os
// selos aparecerem no menu sem o DEV precisar visitar cada página.
carregarErros();
carregarAmeacas();

(function heartbeat() {
  fetch('heartbeat.php?_=' + Date.now(), {
    method: 'POST', credentials: 'same-origin', cache: 'no-store'
  }).catch(function(){});
  setTimeout(heartbeat, 30000);
})();
</script>
</body>
</html>
