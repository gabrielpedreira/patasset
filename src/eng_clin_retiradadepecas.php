<?php
ob_start();
mysqli_report(MYSQLI_REPORT_OFF);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = 'pecas';   // item ativo no menu lateral
require_once 'conexao.php';

$usuario = $_SESSION['usuario_logado'] ?? '';
if (!$usuario) { header('Location: index.html'); exit; }

$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
$stmt->bind_param("s", $usuario); $stmt->execute();
if ($r = $stmt->get_result()->fetch_assoc()) {
    $nivel = strtoupper(trim($r['permicao'] ?? 'C'));
    $classe_usuario = strtoupper(trim($r['classe_usuario'] ?? ''));
    $status_u = $r['status'] ?? 'ATIVO';
}
$stmt->close();
$is_dev = ($classe_usuario === 'DEV');
if ($status_u !== 'ATIVO') { header("Location: acesso_bloqueado.html"); exit(); }
if (!$is_dev && !in_array($classe_usuario, ['ENGENHARIA CLINICA'])) { header("Location: acesso_bloqueado.html"); exit(); }

// ── AJAX ────────────────────────────────────────────────────────────────────
if (isset($_POST['action'])) {
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        // ── listar_equipamentos ──────────────────────────────────────────────
        if ($action === 'listar_equipamentos') {
            $busca       = $conn->real_escape_string(trim($_POST['busca'] ?? ''));
            $filtro_tipo = $conn->real_escape_string(trim($_POST['filtro_tipo'] ?? ''));
            $filtro_pct  = $conn->real_escape_string(trim($_POST['filtro_pct'] ?? ''));

            // Só patrimônio próprio pode ter peças retiradas. Alugado, comodato ou
            // emprestado pertence a terceiros — desmontar seria dano ao bem de outro.
            $where = "WHERE c.status = 'BAIXADO' AND c.responsavel = 'ENGENHARIA CLINICA'
                            AND UPPER(TRIM(COALESCE(c.propriedade,''))) = 'PATRIMONIO'";
            if ($busca !== '') {
                $where .= " AND (c.descricao LIKE '%{$busca}%' OR c.marca LIKE '%{$busca}%'
                                 OR c.modelo LIKE '%{$busca}%' OR c.serie LIKE '%{$busca}%'
                                 OR c.tag_antiga LIKE '%{$busca}%')";
            }
            if ($filtro_tipo !== '') {
                $where .= " AND c.descricao = '{$filtro_tipo}'";
            }

            $sql = "SELECT
                      c.id, c.descricao, c.marca, c.modelo, c.serie,
                      c.tag_antiga, c.tag_trocada, c.subgrupo, c.unidade, c.setor,
                      c.descricao AS tipo_equipamento,
                      (SELECT COUNT(*) FROM retiradadepecas_catalogo rc
                       WHERE rc.tipo_equipamento COLLATE utf8mb4_unicode_ci = c.descricao COLLATE utf8mb4_unicode_ci
                         AND rc.ativo = 1) AS total_pecas,
                      -- Peças reaproveitadas (entram no relatório de economia)
                      (SELECT COUNT(*) FROM retiradadepecas_status rs
                       WHERE rs.id_baixa = c.id AND rs.status = 'REMOVIDO') AS pecas_removidas,
                      -- Peças já verificadas e indisponíveis, por qualquer motivo.
                      -- É o que define quantas ainda restam para retirar.
                      (SELECT COUNT(*) FROM retiradadepecas_status rs
                       WHERE rs.id_baixa = c.id AND rs.status <> 'DISPONIVEL') AS pecas_baixadas,
                      c.propriedade
                    FROM cadastro c
                    {$where}
                    ORDER BY c.id DESC";

            $res = $conn->query($sql);
            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $total     = (int)$row['total_pecas'];
                $removidas = (int)$row['pecas_removidas'];
                // Avariada e inexistente também deixam de estar disponíveis:
                // a peça foi verificada e não pode mais ser retirada.
                $baixadas  = (int)$row['pecas_baixadas'];
                $disp      = max(0, $total - $baixadas);
                if ($total === 0) {
                    $row['pct_disp']  = -1;
                    $row['pct_label'] = 'Sem catálogo';
                    $row['pct_cor']   = 'gray';
                    $row['fracao']    = '';
                } else {
                    $pct = round($disp / $total * 100);
                    $row['pct_disp']  = $pct;
                    $row['pct_label'] = "{$pct}%";
                    $row['fracao']    = "{$disp}/{$total} peças";
                    $row['pct_cor']   = $pct >= 75 ? 'green' : ($pct >= 25 ? 'amber' : 'red');
                }

                if ($filtro_pct === 'com_pecas' && $total === 0) continue;
                if ($filtro_pct === 'sem_pecas' && $total > 0) continue;

                $rows[] = $row;
            }

            // Equipamentos que ainda têm peça para retirar.
            // Fora da contagem: sem catálogo (-1) e já esgotados (0%).
            $com_pecas = 0;
            foreach ($rows as $rw) {
                if ((int)$rw['pct_disp'] > 0) $com_pecas++;
            }

            echo json_encode([
                'ok'         => true,
                'data'       => $rows,
                'disponiveis'=> $com_pecas,
                'listados'   => count($rows),
            ]);
            exit;
        }

        // ── abrir_equipamento ────────────────────────────────────────────────
        if ($action === 'abrir_equipamento') {
            $id_baixa = (int)($_POST['id_baixa'] ?? 0);
            if (!$id_baixa) throw new Exception('ID inválido');

            $sql = "SELECT c.*
                    FROM cadastro c
                    WHERE c.id = {$id_baixa}
                      AND c.status = 'BAIXADO' AND c.responsavel = 'ENGENHARIA CLINICA'
                    LIMIT 1";
            $res_eq = $conn->query($sql);
            if (!$res_eq) throw new Exception($conn->error);
            $equip = $res_eq->fetch_assoc();
            if (!$equip) throw new Exception('Equipamento não encontrado');

            // tipo = descrição do equipamento (match automático com catálogo)
            $equip['tipo_equipamento'] = $equip['descricao'];

            $pecas = [];
            $tipo_esc = $conn->real_escape_string($equip['descricao']);
            $sqlp = "SELECT rc.id AS id_catalogo, rc.nome_peca, rc.valor_estimado, rc.ativo,
                            rs.status, rs.usuario AS usuario_retirada, rs.data_retirada, rs.obs AS obs_retirada
                     FROM retiradadepecas_catalogo rc
                     LEFT JOIN retiradadepecas_status rs ON rs.id_baixa = {$id_baixa} AND rs.id_catalogo = rc.id
                     WHERE rc.tipo_equipamento COLLATE utf8mb4_unicode_ci = '{$tipo_esc}' COLLATE utf8mb4_unicode_ci
                       AND rc.ativo = 1
                     ORDER BY rc.nome_peca";
            $resp = $conn->query($sqlp);
            while ($p = $resp->fetch_assoc()) {
                if ($p['status'] === null) $p['status'] = 'DISPONIVEL';
                $pecas[] = $p;
            }

            // valor economizado
            $valor_econ = 0;
            foreach ($pecas as $p) {
                if ($p['status'] === 'REMOVIDO') $valor_econ += (float)$p['valor_estimado'];
            }

            echo json_encode(['ok' => true, 'equip' => $equip, 'pecas' => $pecas, 'valor_economizado' => $valor_econ]);
            exit;
        }

        // ── salvar_status_peca ────────────────────────────────────────────────
        if ($action === 'salvar_status_peca') {
            $id_baixa    = (int)($_POST['id_baixa'] ?? 0);
            $id_catalogo = (int)($_POST['id_catalogo'] ?? 0);
            $status      = $conn->real_escape_string(trim($_POST['status'] ?? ''));
            $obs         = $conn->real_escape_string(trim($_POST['obs'] ?? ''));
            // REMOVIDO = peça reaproveitada (conta como economia)
            // AVARIADA / INEXISTENTE = peça não utilizável, NÃO conta como economia
            if (!$id_baixa || !$id_catalogo || !in_array($status, ['DISPONIVEL','REMOVIDO','AVARIADA','INEXISTENTE'])) throw new Exception('Dados inválidos');

            // buscar nome_peca e tipo
            $sqlc = "SELECT nome_peca, tipo_equipamento FROM retiradadepecas_catalogo WHERE id={$id_catalogo} LIMIT 1";
            $res_cat = $conn->query($sqlc);
            if (!$res_cat) throw new Exception($conn->error);
            $cat = $res_cat->fetch_assoc();
            if (!$cat) throw new Exception('Peça não encontrada no catálogo');
            $nome_peca = $conn->real_escape_string($cat['nome_peca']);
            $tipo_eq   = $conn->real_escape_string($cat['tipo_equipamento']);
            $usuario_esc = $conn->real_escape_string($usuario);

            $registrado    = ($status !== 'DISPONIVEL');
            $data_retirada = $registrado ? "NOW()" : "NULL";
            $usuario_val   = $registrado ? "'{$usuario_esc}'" : "NULL";

            $sql = "INSERT INTO retiradadepecas_status
                      (id_baixa, id_catalogo, nome_peca, tipo_equipamento, status, usuario, data_retirada, obs)
                    VALUES
                      ({$id_baixa}, {$id_catalogo}, '{$nome_peca}', '{$tipo_eq}', '{$status}', {$usuario_val}, {$data_retirada}, '{$obs}')
                    ON DUPLICATE KEY UPDATE
                      status='{$status}', usuario={$usuario_val}, data_retirada={$data_retirada}, obs='{$obs}'";
            $conn->query($sql);
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── listar_tipos_catalogo ─────────────────────────────────────────────
        if ($action === 'listar_tipos_catalogo') {
            $res = $conn->query("SELECT tipo_equipamento, COUNT(*) AS total_pecas
                                 FROM retiradadepecas_catalogo WHERE ativo=1
                                 GROUP BY tipo_equipamento ORDER BY tipo_equipamento");
            $rows = [];
            while ($r = $res->fetch_assoc()) $rows[] = $r;
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── listar_descricoes_equipamentos ────────────────────────────────────
        if ($action === 'listar_descricoes_equipamentos') {
            $res = $conn->query("SELECT DISTINCT descricao FROM cadastro
                                 WHERE status = 'BAIXADO' AND responsavel = 'ENGENHARIA CLINICA'
                                   AND UPPER(TRIM(COALESCE(propriedade,''))) = 'PATRIMONIO'
                                   AND descricao IS NOT NULL AND descricao <> ''
                                 ORDER BY descricao");
            $rows = [];
            while ($r = $res->fetch_assoc()) $rows[] = $r['descricao'];
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── listar_tipos_disponiveis ──────────────────────────────────────────
        if ($action === 'listar_tipos_disponiveis') {
            $res = $conn->query("SELECT DISTINCT tipo_equipamento FROM retiradadepecas_catalogo WHERE ativo=1 ORDER BY tipo_equipamento");
            $rows = [];
            while ($r = $res->fetch_assoc()) $rows[] = $r['tipo_equipamento'];
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── listar_pecas_tipo ─────────────────────────────────────────────────
        if ($action === 'listar_pecas_tipo') {
            $tipo = $conn->real_escape_string(trim($_POST['tipo_equipamento'] ?? ''));
            if ($tipo === '') throw new Exception('Tipo não informado');
            $res = $conn->query("SELECT id, nome_peca, valor_estimado, ativo, criado_por, criado_em
                                 FROM retiradadepecas_catalogo
                                 WHERE tipo_equipamento='{$tipo}'
                                 ORDER BY nome_peca");
            $rows = [];
            while ($r = $res->fetch_assoc()) $rows[] = $r;

            // stats
            $total = count($rows);
            $soma = array_sum(array_column(array_filter($rows, fn($p) => $p['ativo']), 'valor_estimado'));
            $media = ($total > 0) ? round($soma / max(1, count(array_filter($rows, fn($p) => $p['ativo']))), 2) : 0;

            echo json_encode(['ok' => true, 'data' => $rows, 'total' => $total, 'valor_medio' => $media]);
            exit;
        }

        // ── salvar_peca_catalogo ──────────────────────────────────────────────
        if ($action === 'salvar_peca_catalogo') {
            $tipo  = $conn->real_escape_string(trim($_POST['tipo_equipamento'] ?? ''));
            $nome  = $conn->real_escape_string(trim($_POST['nome_peca'] ?? ''));
            $valor = (float)($_POST['valor_estimado'] ?? 0);
            $usuario_esc = $conn->real_escape_string($usuario);
            if ($tipo === '' || $nome === '') throw new Exception('Tipo e nome são obrigatórios');

            $sql = "INSERT INTO retiradadepecas_catalogo (tipo_equipamento, nome_peca, valor_estimado, criado_por)
                    VALUES ('{$tipo}', '{$nome}', {$valor}, '{$usuario_esc}')";
            if (!$conn->query($sql)) throw new Exception($conn->error);
            echo json_encode(['ok' => true, 'id' => $conn->insert_id]);
            exit;
        }

        // ── toggle_ativo_peca ─────────────────────────────────────────────────
        if ($action === 'toggle_ativo_peca') {
            $id    = (int)($_POST['id'] ?? 0);
            $ativo = (int)($_POST['ativo'] ?? 0);
            if (!$id) throw new Exception('ID inválido');
            if (!in_array($ativo, [0, 1])) throw new Exception('Valor inválido');
            $conn->query("UPDATE retiradadepecas_catalogo SET ativo={$ativo} WHERE id={$id}");
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── deletar_peca_catalogo ─────────────────────────────────────────────
        if ($action === 'deletar_peca_catalogo') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('ID inválido');
            $check = $conn->query("SELECT COUNT(*) AS n FROM retiradadepecas_status WHERE id_catalogo={$id}")->fetch_assoc();
            if ((int)$check['n'] > 0) throw new Exception('Peça possui retiradas registradas e não pode ser excluída');
            $conn->query("DELETE FROM retiradadepecas_catalogo WHERE id={$id}");
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── deletar_tipo_catalogo ─────────────────────────────────────────────
        if ($action === 'deletar_tipo_catalogo') {
            $tipo = $conn->real_escape_string(trim($_POST['tipo_equipamento'] ?? ''));
            if ($tipo === '') throw new Exception('Tipo não informado');

            // Verifica se alguma peça deste tipo tem retiradas registradas
            $check = $conn->query("
                SELECT COUNT(*) AS n
                FROM retiradadepecas_status rs
                JOIN retiradadepecas_catalogo rc ON rc.id = rs.id_catalogo
                WHERE rc.tipo_equipamento = '{$tipo}'
            ")->fetch_assoc();
            if ((int)$check['n'] > 0)
                throw new Exception('Este tipo possui retiradas registradas e não pode ser excluído');

            $conn->begin_transaction();
            try {
                $r1 = $conn->query("DELETE FROM retiradadepecas_equipamento_tipo WHERE tipo_equipamento = '{$tipo}'");
                if ($r1 === false) throw new Exception($conn->error);
                $r2 = $conn->query("DELETE FROM retiradadepecas_catalogo WHERE tipo_equipamento = '{$tipo}'");
                if ($r2 === false) throw new Exception($conn->error);
                $conn->commit();
            } catch (Exception $ex) {
                $conn->rollback();
                throw $ex;
            }

            echo json_encode(['ok' => true]);
            exit;
        }

        // ── historico_geral ───────────────────────────────────────────────────
        if ($action === 'historico_geral') {
            $busca   = $conn->real_escape_string(trim($_POST['busca'] ?? ''));
            $tipo    = $conn->real_escape_string(trim($_POST['tipo'] ?? ''));
            $usu     = $conn->real_escape_string(trim($_POST['usuario_filtro'] ?? ''));
            $status  = $conn->real_escape_string(trim($_POST['status_filtro'] ?? ''));
            $dt_ini  = $conn->real_escape_string(trim($_POST['dt_ini'] ?? ''));
            $dt_fim  = $conn->real_escape_string(trim($_POST['dt_fim'] ?? ''));

            // INEXISTENTE não entra no histórico: a peça nunca esteve lá.
            // Reaproveitadas e avariadas entram, com a situação identificada.
            $where = "WHERE rs.status IN ('REMOVIDO','AVARIADA')";
            if ($busca !== '') $where .= " AND (c.descricao LIKE '%{$busca}%' OR c.tag_antiga LIKE '%{$busca}%' OR c.tag_trocada LIKE '%{$busca}%' OR c.serie LIKE '%{$busca}%')";
            if ($tipo !== '') $where .= " AND rs.tipo_equipamento = '{$tipo}'";
            if ($usu !== '') $where .= " AND rs.usuario LIKE '%{$usu}%'";
            if ($status !== '') $where .= " AND rs.status = '{$status}'";
            if ($dt_ini !== '') $where .= " AND DATE(rs.data_retirada) >= '{$dt_ini}'";
            if ($dt_fim !== '') $where .= " AND DATE(rs.data_retirada) <= '{$dt_fim}'";

            $sql = "SELECT rs.id, rs.id_baixa, rs.nome_peca, rs.tipo_equipamento, rs.status,
                           rs.usuario, rs.data_retirada, rs.obs, rs.atualizado_em,
                           c.id AS id_cadastro, c.descricao, c.tag_antiga, c.tag_trocada,
                           c.marca, c.modelo, c.serie,
                           rc.valor_estimado
                    FROM retiradadepecas_status rs
                    LEFT JOIN cadastro c ON c.id = rs.id_baixa
                    LEFT JOIN retiradadepecas_catalogo rc ON rc.id = rs.id_catalogo
                    {$where}
                    ORDER BY COALESCE(rs.data_retirada, rs.atualizado_em) DESC
                    LIMIT 500";
            $res = $conn->query($sql);
            $rows = [];
            while ($r = $res->fetch_assoc()) $rows[] = $r;
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        throw new Exception("Ação desconhecida: {$action}");
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        exit;
    }
}
// ── FIM AJAX ─────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Retirada de Peças — LifeTech Engenharia Clínica</title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="shortcut icon" href="logo_1.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --bg-page:#0f0f0f; --bg-sidebar:#141414; --bg-card:#1a1a1a;
  --bg-card-hover:#1f1f1f; --bg-input:#222;
  --border:rgba(255,255,255,0.07); --border-hover:rgba(255,255,255,0.14);
  --accent-steel:#a0aec0; --text-primary:#f0f0f0;
  --text-secondary:#888; --text-muted:#555;
  --sidebar-w:260px; --sidebar-collapsed:68px; --header-h:56px;
  --radius:10px; --radius-lg:16px;
  --transition:0.22s cubic-bezier(0.4,0,0.2,1);
  --font-ui:'DM Sans',sans-serif; --font-display:'Syne',sans-serif;
  --status-ok:#4ade80; --status-warn:#facc15; --status-err:#f87171;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-ui);background:var(--bg-page);color:var(--text-primary);min-height:100vh;overflow-x:hidden;display:flex;flex-direction:column}

.menu-toggle{display:none;position:fixed;top:10px;left:10px;z-index:1200;background:#2a2a2a;color:#e2e8f0;border:1px solid var(--border-hover);border-radius:8px;padding:8px 12px;font-size:20px;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.4);line-height:1}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000}
.sidebar-overlay.open{display:block}

#sidebar{position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;background:var(--bg-sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;transition:width var(--transition);overflow:visible}
#sidebar.collapsed{width:var(--sidebar-collapsed)}
.sidebar-brand{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px 12px 16px;border-bottom:1px solid var(--border);flex-shrink:0;gap:10px}
.brand-logo-main{width:56%;max-width:140px;height:auto;object-fit:contain;display:block;transition:opacity var(--transition),width var(--transition)}
#sidebar.collapsed .brand-logo-main{width:31px;max-width:31px}
.sidebar-toggle{position:absolute;top:14px;right:-14px;width:28px;height:28px;background:var(--bg-card);border:1px solid var(--border-hover);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:200;transition:background var(--transition);color:var(--text-secondary);font-size:11px}
.sidebar-toggle:hover{background:#2a2a2a;color:var(--text-primary)}
.sidebar-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:8px 10px;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.nav-item{display:block;width:100%;padding:11px 14px;margin:3px 0;border-radius:6px;cursor:pointer;text-decoration:none;color:#bfc0c2;font-size:14px;font-weight:400;transition:background var(--transition),color var(--transition),transform var(--transition);white-space:nowrap;overflow:hidden;position:relative;border:none;background:#1e2025;text-align:left;letter-spacing:.01em}
.nav-item:hover{background:#26282d;color:#e8e9eb;transform:translateX(4px)}
.nav-item.active{background:#2a2c31;color:#fff;font-weight:500}
.nav-label{display:inline}
#sidebar.collapsed .nav-label{opacity:0}
.nav-item-sair{color:#f87171!important}
.nav-item-sair:hover{background:rgba(248,113,113,0.08)!important}
#sidebar.collapsed .nav-item{padding:10px 0;text-align:center;font-size:0;color:transparent;overflow:hidden;transform:none!important}
#sidebar.collapsed .nav-item::before{font-family:'Font Awesome 6 Free';font-weight:900;font-size:15px;color:#888;display:block;line-height:1;transition:color var(--transition)}
#sidebar.collapsed .nav-item:hover::before{color:#e8e9eb}
#sidebar.collapsed .nav-item.active::before{color:#fff}
#sidebar.collapsed .nav-item::after{content:attr(data-tooltip);position:absolute;left:calc(var(--sidebar-collapsed) + 8px);top:50%;transform:translateY(-50%);background:#2d2d2d;color:#e2e8f0;font-size:12px;padding:5px 10px;border-radius:6px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:200;border:1px solid var(--border-hover);box-shadow:0 4px 12px rgba(0,0,0,.4)}
#sidebar.collapsed .nav-item:hover::after{opacity:1}
#sidebar.collapsed .nav-item[data-tooltip="Abertura de Chamado"]::before  { content:"\f46d"; }
#sidebar.collapsed .nav-item[data-tooltip="Cadastro de Equipamento"]::before { content:"\f0fe"; }
#sidebar.collapsed .nav-item[data-tooltip="Planilha"]::before             { content:"\f0ce"; }
#sidebar.collapsed .nav-item[data-tooltip="Ordem de Serviço"]::before     { content:"\f46d"; }
#sidebar.collapsed .nav-item[data-tooltip="Estoque"]::before              { content:"\f49e"; }
#sidebar.collapsed .nav-item[data-tooltip="Movimentar"]::before           { content:"\f362"; }
#sidebar.collapsed .nav-item[data-tooltip="Página Inicial"]::before       { content:"\f200"; }
#sidebar.collapsed .nav-item[data-tooltip="Sair"]::before                 { content:"\f2f5"; }

#main{margin-left:var(--sidebar-w);transition:margin-left var(--transition);min-height:calc(100vh - 42px);display:flex;flex-direction:column;flex:1}
#main.sidebar-collapsed{margin-left:var(--sidebar-collapsed)}
.topbar{height:var(--header-h);background:rgba(20,20,20,0.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:10px;position:sticky;top:0;z-index:50}
.topbar-breadcrumb{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted)}
.topbar-breadcrumb span:last-child{color:var(--text-primary);font-weight:500}
.topbar-breadcrumb i{font-size:10px}
.topbar-spacer{flex:1}
.topbar-logo-rede{height:32px;width:auto;object-fit:contain;opacity:.75;transition:opacity var(--transition);flex-shrink:0}
.topbar-logo-rede:hover{opacity:1}
.topbar-btn{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid var(--border);background:#1c1c1c;color:var(--text-secondary);cursor:pointer;transition:background var(--transition),color var(--transition),border-color var(--transition);font-size:13px;position:relative;flex-shrink:0}
.topbar-btn:hover{background:#2a2a2a;color:var(--text-primary);border-color:var(--border-hover)}
.topbar-btn.ativo{background:#2a2a2a;color:var(--text-primary);border-color:var(--border-hover)}

.content{flex:1;padding:28px;width:100%}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.page-title{font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--text-primary);letter-spacing:-.01em}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}

/* ── TABS HORIZONTAIS ── */
.tab-nav{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:20px}
.tab-btn{padding:10px 18px;border:none;background:none;color:var(--text-muted);font-family:var(--font-ui);font-size:13px;font-weight:500;cursor:pointer;border-bottom:2px solid transparent;transition:all var(--transition)}
.tab-btn.active{color:var(--text-primary);border-bottom-color:var(--accent-steel)}
.tab-btn:hover:not(.active){color:var(--text-secondary)}
.tab-pane{display:none}
.tab-pane.active{display:block}

/* ── FILTROS ── */
.filters-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;align-items:center}
.filters-bar input,
.filters-bar select{background:var(--bg-card);border:1px solid var(--border-hover);color:var(--text-primary);border-radius:8px;padding:8px 12px;font-family:var(--font-ui);font-size:13px;transition:border-color var(--transition)}
.filters-bar input::placeholder{color:var(--text-muted)}
.filters-bar input:focus,
.filters-bar select:focus{outline:none;border-color:var(--accent-steel)}
.filters-bar select option{background:#1a1a1a}

/* ── TABELA ── */
/* overflow-x:auto em vez de hidden — as tabelas têm 10 e 13 colunas e
   estavam sendo cortadas à direita sem possibilidade de rolar. */
.table-wrap{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow-x:auto;overflow-y:hidden}
.table-wrap::-webkit-scrollbar{height:8px}
.table-wrap::-webkit-scrollbar-track{background:transparent}
.table-wrap::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:4px}
.table-wrap::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.2)}
.table-wrap{scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.12) transparent}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:900px}
/* Resumo acima da tabela de equipamentos */
.resumo-eq{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:15px 20px;margin-bottom:14px}
.resumo-ic{width:38px;height:38px;border-radius:10px;background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.25);display:flex;align-items:center;justify-content:center;color:#4ade80;font-size:15px;flex-shrink:0}
.resumo-num{font-family:var(--font-display);font-size:24px;font-weight:700;line-height:1;color:#4ade80}
.resumo-txt{font-size:11.5px;color:var(--text-muted);margin-top:3px}
.resumo-sep{width:1px;height:34px;background:var(--border)}
.resumo-sec{font-size:12px;color:var(--text-secondary)}
.resumo-sec span{color:var(--text-primary);font-weight:600}
thead th{background:var(--bg-card-hover);padding:11px 14px;text-align:left;font-weight:600;color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border)}
tbody tr{border-bottom:1px solid var(--border);cursor:pointer;transition:background var(--transition)}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--bg-card-hover)}
tbody td{padding:11px 14px;vertical-align:middle}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap}
.badge-green{background:rgba(74,222,128,.12);color:#4ade80;border:1px solid rgba(74,222,128,.2)}
.badge-amber{background:rgba(250,204,21,.12);color:#facc15;border:1px solid rgba(250,204,21,.2)}
.badge-red{background:rgba(248,113,113,.12);color:#f87171;border:1px solid rgba(248,113,113,.2)}
.badge-gray{background:rgba(160,174,192,.1);color:var(--text-secondary);border:1px solid rgba(160,174,192,.15)}
.badge-steel{background:rgba(160,174,192,.12);color:var(--accent-steel);border:1px solid rgba(160,174,192,.2)}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none}
.btn:hover{opacity:.85}
.btn:active{filter:brightness(.9)}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn-primary{background:#232323;border:1px solid rgba(255,255,255,.13);color:var(--text-primary)}
.btn-primary:hover{background:#2e2e2e;border-color:rgba(255,255,255,.2)}
.btn-danger{background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.25);color:#f87171}
.btn-warn{background:rgba(250,204,21,.12);border:1px solid rgba(250,204,21,.28);color:#facc15}
.btn-warn:hover{background:rgba(250,204,21,.2)}
.btn-danger:hover{background:rgba(248,113,113,.2)}
.btn-success{background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.25);color:#4ade80}
.btn-success:hover{background:rgba(74,222,128,.2)}
.btn-ghost{background:var(--bg-card-hover);border:1px solid var(--border);color:var(--text-secondary)}
.btn-ghost:hover{border-color:var(--border-hover);color:var(--text-primary)}
.btn-sm{padding:5px 11px;font-size:11px}
.btn-xs{padding:3px 8px;font-size:11px}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:60px 20px;color:var(--text-muted)}
.empty-state i{font-size:2rem;margin-bottom:12px;display:block;opacity:.4}
.empty-state p{font-size:13px}

/* ── SPIN ── */
.spin{animation:spin .7s linear infinite;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── DRAWER ── */
.drawer-overlay{display:none;position:fixed;inset:0;z-index:499;background:rgba(0,0,0,.4)}
.drawer-overlay.open{display:block}
.drawer{position:fixed;top:0;right:-440px;width:420px;height:100vh;background:var(--bg-sidebar);border-left:1px solid var(--border);z-index:500;display:flex;flex-direction:column;transition:right var(--transition);box-shadow:-8px 0 32px rgba(0,0,0,.5)}
.drawer.open{right:0}
.drawer-header{padding:18px 20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-shrink:0}
.drawer-header-info h2{font-size:15px;font-weight:700;color:var(--text-primary);line-height:1.3;font-family:var(--font-display)}
.drawer-header-info p{font-size:12px;color:var(--text-secondary);margin-top:3px}
.drawer-close{background:none;border:none;color:var(--text-muted);font-size:18px;cursor:pointer;padding:2px 6px;transition:color var(--transition)}
.drawer-close:hover{color:var(--text-primary)}
.drawer-body{flex:1;overflow-y:auto;padding:18px 20px;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.drawer-footer{padding:14px 20px;border-top:1px solid var(--border);background:var(--bg-card);display:flex;align-items:center;justify-content:space-between;font-size:12px;flex-shrink:0}

/* ── TIPO SECTION (dentro do drawer) ── */
.tipo-section{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:14px;margin-bottom:16px}
.tipo-section label{display:block;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
.tipo-section .tipo-row{display:flex;align-items:center;gap:8px}
.tipo-section select{flex:1;background:var(--bg-input);border:1px solid var(--border-hover);color:var(--text-primary);border-radius:8px;padding:8px 10px;font-family:var(--font-ui);font-size:13px}
.tipo-section select:focus{outline:none;border-color:var(--accent-steel)}

/* ── PEÇA ITEM ── */
.peca-item{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:12px 14px;margin-bottom:8px;transition:border-color var(--transition)}
.peca-item.removida{border-color:rgba(248,113,113,.25)}
.peca-item-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
.peca-nome{font-size:13px;font-weight:600;color:var(--text-primary)}
.peca-valor{font-size:12px;color:var(--text-secondary);margin-top:2px}
.peca-meta{font-size:11px;color:var(--text-muted);margin-top:6px;display:flex;flex-wrap:wrap;gap:6px}
.peca-form{margin-top:10px;padding-top:10px;border-top:1px solid var(--border);display:none}
.peca-form.open{display:block}
.peca-form textarea{width:100%;background:var(--bg-input);border:1px solid var(--border-hover);color:var(--text-primary);border-radius:8px;padding:8px 10px;font-family:var(--font-ui);font-size:12px;resize:vertical;min-height:60px;margin-bottom:8px}
.peca-form textarea:focus{outline:none;border-color:var(--accent-steel)}
.peca-form textarea::placeholder{color:var(--text-muted)}

/* ── CATÁLOGO LAYOUT ── */
.catalog-layout{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.catalog-panel{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);display:flex;flex-direction:column;overflow:hidden;min-height:420px}
.catalog-panel:hover{border-color:var(--border-hover)}
.panel-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
.panel-title{font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--accent-steel);flex:1}
.panel-count{background:rgba(160,174,192,.12);color:var(--accent-steel);font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px}
.panel-body{flex:1;overflow-y:auto;padding:10px;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.panel-footer{padding:12px 16px;border-top:1px solid var(--border);background:var(--bg-card-hover);flex-shrink:0}
.panel-stats{padding:8px 16px 4px;font-size:11px;color:var(--text-muted)}

.add-tipo-form{display:flex;gap:6px;padding:10px;border-bottom:1px solid var(--border);flex-shrink:0}
.add-tipo-form input{flex:1;background:var(--bg-input);border:1px solid var(--border-hover);color:var(--text-primary);border-radius:8px;padding:7px 10px;font-family:var(--font-ui);font-size:13px}
.add-tipo-form input:focus{outline:none;border-color:var(--accent-steel)}
.add-tipo-form input::placeholder{color:var(--text-muted)}

.tipo-list-item{display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border-radius:6px;cursor:pointer;font-size:13px;transition:background var(--transition);border:1px solid transparent;margin-bottom:2px}
.tipo-list-item:hover{background:var(--bg-card-hover)}
.tipo-list-item.selected{background:rgba(160,174,192,.08);border-color:rgba(160,174,192,.2);color:var(--text-primary)}
.tipo-list-item .count{font-size:11px;color:var(--text-muted);background:var(--bg-card-hover);border-radius:10px;padding:1px 7px}
.tipo-list-item.selected .count{background:rgba(160,174,192,.15);color:var(--accent-steel)}

.add-peca-form{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-group label{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em}
.form-group input{background:var(--bg-input);border:1px solid var(--border-hover);color:var(--text-primary);border-radius:8px;padding:7px 10px;font-family:var(--font-ui);font-size:13px}
.form-group input:focus{outline:none;border-color:var(--accent-steel)}
.form-group input::placeholder{color:var(--text-muted)}

.peca-cat-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:6px;border:1px solid var(--border);margin-bottom:6px;font-size:13px;background:var(--bg-card-hover)}
.peca-cat-item .pn{flex:1;font-weight:500}
.peca-cat-item .pv{color:var(--text-secondary);font-size:12px;min-width:70px;text-align:right}
.peca-cat-item .pa{display:flex;gap:5px}

/* ── HISTÓRICO FILTROS ── */
.hist-filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;align-items:center}
.hist-filters input,
.hist-filters select{background:var(--bg-card);border:1px solid var(--border-hover);color:var(--text-primary);border-radius:8px;padding:7px 11px;font-family:var(--font-ui);font-size:13px;transition:border-color var(--transition)}
.hist-filters input:focus,.hist-filters select:focus{outline:none;border-color:var(--accent-steel)}
.hist-filters input::placeholder{color:var(--text-muted)}
.hist-filters select option{background:#1a1a1a}

/* ── TOAST ── */
#toast{position:fixed;bottom:28px;right:28px;z-index:9999;min-width:240px;max-width:340px;background:var(--bg-card);border:1px solid var(--border-hover);border-radius:var(--radius);padding:12px 16px;font-size:13px;color:var(--text-primary);box-shadow:0 8px 32px rgba(0,0,0,.5);transform:translateY(20px);opacity:0;transition:transform .25s ease,opacity .25s ease;pointer-events:none;display:flex;align-items:center;gap:10px}
#toast.show{transform:translateY(0);opacity:1}
#toast.ok{border-left:3px solid var(--status-ok)}
#toast.err{border-left:3px solid var(--status-err)}
#toast.warn{border-left:3px solid var(--status-warn)}
#toast.info{border-left:3px solid var(--accent-steel)}
#toast.ok #toast-icon{color:var(--status-ok)}
#toast.err #toast-icon{color:var(--status-err)}
#toast.warn #toast-icon{color:var(--status-warn)}
#toast.info #toast-icon{color:var(--accent-steel)}

/* ── TOOLS PANEL ── */
.tools-panel{position:fixed;top:var(--header-h);right:-280px;width:260px;height:calc(100vh - var(--header-h));background:var(--bg-sidebar);border-left:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:right var(--transition);box-shadow:-4px 0 24px rgba(0,0,0,.4)}
.tools-panel.open{right:0}
.tools-header{padding:16px 18px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.tools-title{font-family:var(--font-display);font-size:13px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--accent-steel)}
.tools-close{width:26px;height:26px;display:flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid var(--border);background:none;color:var(--text-muted);cursor:pointer;font-size:13px;transition:background var(--transition),color var(--transition)}
.tools-close:hover{background:#2a2a2a;color:var(--text-primary)}
.tools-section-label{font-size:9.5px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);padding:12px 18px 6px}
.tools-body{flex:1;overflow-y:auto;padding:8px 10px;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.tool-btn{display:flex;align-items:center;gap:12px;width:100%;padding:11px 14px;margin:3px 0;border-radius:6px;border:none;background:#1e2025;color:#bfc0c2;font-family:var(--font-ui);font-size:13px;font-weight:400;cursor:pointer;text-align:left;text-decoration:none;transition:background var(--transition),color var(--transition),transform var(--transition);letter-spacing:.01em}
.tool-btn:hover{background:#26282d;color:#e8e9eb;transform:translateX(4px)}
.tool-btn i{width:16px;text-align:center;font-size:13px;color:var(--text-muted);flex-shrink:0}
.tool-btn:hover i{color:var(--accent-steel)}

/* ── FOOTER ── */
.footer{background:#181818;color:#888;border-top:1px solid var(--border);padding:12px 20px;display:flex;justify-content:space-between;flex-wrap:wrap;font-size:13px;gap:6px;margin-left:var(--sidebar-w);transition:margin-left var(--transition)}
.footer div{color:#666}

::-webkit-scrollbar{width:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.07);border-radius:4px}

@media(max-width:900px){.catalog-layout{grid-template-columns:1fr}.content{padding:16px}.topbar-logo-rede{display:none}.footer,#main{margin-left:var(--sidebar-collapsed)}#sidebar{width:var(--sidebar-collapsed)}#sidebar.open{width:var(--sidebar-w)}}
@media(max-width:640px){#sidebar{position:fixed;top:0;left:0;height:100vh;z-index:1100;transform:translateX(-100%);transition:transform var(--transition);width:var(--sidebar-w)!important}#sidebar.open{transform:translateX(0)}.sidebar-toggle{display:none}.menu-toggle{display:block}#main{margin-left:0!important}.topbar{padding-left:54px}.content{padding:12px}.footer{margin-left:0}.drawer{width:100%;right:-100%}.drawer.open{right:0}}
@media print{.menu-toggle,.sidebar-overlay,#sidebar,.topbar,.footer{display:none!important}#main{margin-left:0!important}}
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
      <span>Retirada de Peças</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
      <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <div class="page-header">
      <div>
        <div class="page-title">Retirada de Peças</div>
        <div class="page-subtitle">Engenharia Clínica &middot; Gestão de aproveitamento de equipamentos baixados</div>
      </div>
    </div>

    <!-- ABAS HORIZONTAIS -->
    <div class="tab-nav">
      <button class="tab-btn active" data-tab="equipamentos">
        <i class="fas fa-boxes-stacked" style="margin-right:6px"></i>Equipamentos
      </button>
      <button class="tab-btn" data-tab="catalogo">
        <i class="fas fa-layer-group" style="margin-right:6px"></i>Catálogo de Peças
      </button>
      <button class="tab-btn" data-tab="historico">
        <i class="fas fa-clock-rotate-left" style="margin-right:6px"></i>Histórico
      </button>
    </div>

    <!-- ═══════════════════════════════════════════════════ -->
    <!-- ABA 1 — EQUIPAMENTOS                               -->
    <!-- ═══════════════════════════════════════════════════ -->
    <div class="tab-pane active" id="tab-equipamentos">
      <div class="filters-bar">
        <input type="text" id="eq-busca" placeholder="Buscar descrição, marca, modelo, série…" style="width:280px">
        <select id="eq-tipo" style="width:190px">
          <option value="">Todos os tipos</option>
        </select>
        <select id="eq-pct" style="width:200px">
          <option value="">Todos</option>
          <option value="com_pecas">Com peças no catálogo</option>
          <option value="sem_pecas">Sem catálogo definido</option>
        </select>
        <button class="btn btn-primary btn-sm" id="btn-buscar-eq">
          <i class="fas fa-magnifying-glass"></i> Filtrar
        </button>
      </div>

      <!-- Equipamentos que ainda têm peça a retirar (0% e sem catálogo não contam) -->
      <div class="resumo-eq" id="resumoEq">
        <div class="resumo-ic"><i class="fas fa-screwdriver-wrench"></i></div>
        <div>
          <div class="resumo-num" id="resumoNum">—</div>
          <div class="resumo-txt">equipamentos com peças disponíveis para retirada</div>
        </div>
        <div class="resumo-sep"></div>
        <div class="resumo-sec">
          <span id="resumoListados">—</span> equipamentos baixados listados
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Descrição</th>
              <th>Marca</th>
              <th>Modelo</th>
              <th>Série</th>
              <th>Tag 1</th>
              <th>Tag 2</th>
              <th>Tipo</th>
              <th>Disponibilidade</th>
              <th>Ação</th>
            </tr>
          </thead>
          <tbody id="eq-tbody">
            <tr><td colspan="9"><div class="empty-state"><i class="fas fa-spinner spin"></i><p>Carregando…</p></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════ -->
    <!-- ABA 2 — CATÁLOGO DE PEÇAS                          -->
    <!-- ═══════════════════════════════════════════════════ -->
    <div class="tab-pane" id="tab-catalogo">
      <div class="catalog-layout">

        <!-- Painel esquerdo: Tipos -->
        <div class="catalog-panel">
          <div class="panel-header">
            <span class="panel-title">Tipos de Equipamento</span>
            <span class="panel-count" id="tipo-count">—</span>
          </div>
          <div class="add-tipo-form">
            <input type="text" id="new-tipo-input" placeholder="Novo tipo ou selecione…" autocomplete="off" list="tipo-sugestoes">
            <datalist id="tipo-sugestoes"></datalist>
            <button class="btn btn-primary btn-sm" id="btn-add-tipo"><i class="fas fa-plus"></i></button>
          </div>
          <div class="panel-body" id="tipos-list">
            <div class="empty-state" style="padding:30px 10px">
              <i class="fas fa-spinner spin"></i>
            </div>
          </div>
        </div>

        <!-- Painel direito: Peças do tipo selecionado -->
        <div class="catalog-panel">
          <div class="panel-header">
            <span class="panel-title" id="cat-tipo-title" style="color:var(--text-muted)">Selecione um tipo</span>
          </div>
          <div class="panel-stats" id="cat-stats"></div>
          <div class="panel-body" id="pecas-list">
            <div class="empty-state" style="padding:40px 10px">
              <i class="fas fa-arrow-left" style="font-size:1.4rem;opacity:.3"></i>
              <p style="margin-top:8px">Selecione um tipo à esquerda</p>
            </div>
          </div>
          <div class="panel-footer" id="cat-footer" style="display:none">
            <div class="add-peca-form">
              <div class="form-group" style="flex:1;min-width:140px">
                <label>Nome da peça</label>
                <input type="text" id="new-peca-nome" placeholder="Ex: Placa principal">
              </div>
              <div class="form-group" style="width:110px">
                <label>Valor (R$)</label>
                <input type="number" id="new-peca-valor" placeholder="0.00" step="0.01" min="0">
              </div>
              <button class="btn btn-success btn-sm" id="btn-add-peca" style="align-self:flex-end">
                <i class="fas fa-plus"></i> Salvar
              </button>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════ -->
    <!-- ABA 3 — HISTÓRICO GERAL                            -->
    <!-- ═══════════════════════════════════════════════════ -->
    <div class="tab-pane" id="tab-historico">
      <div class="hist-filters">
        <input type="text" id="hist-busca" placeholder="Equipamento ou tag…" style="width:200px">
        <select id="hist-tipo" style="width:170px">
          <option value="">Todos os tipos</option>
        </select>
        <input type="text" id="hist-usuario" placeholder="Usuário…" style="width:130px">
        <select id="hist-status" style="width:150px">
          <option value="">Status: todos</option>
          <option value="REMOVIDO">Removido</option>
          <option value="DISPONIVEL">Disponível</option>
        </select>
        <input type="date" id="hist-dt-ini" title="Data início">
        <input type="date" id="hist-dt-fim" title="Data fim">
        <button class="btn btn-primary btn-sm" id="btn-hist-buscar">
          <i class="fas fa-magnifying-glass"></i> Filtrar
        </button>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Data Retirada</th>
              <th>ID</th>
              <th>Equipamento</th>
              <th>Marca / Modelo</th>
              <th>Série</th>
              <th>Tag 1</th>
              <th>Tag 2</th>
              <th>Tipo</th>
              <th>Peça</th>
              <th>Situação</th>
              <th>Valor</th>
              <th>Usuário</th>
              <th>Obs</th>
            </tr>
          </thead>
          <tbody id="hist-tbody">
            <tr><td colspan="11"><div class="empty-state" style="padding:40px"><p>Use os filtros acima e clique em Filtrar.</p></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- .content -->
</div><!-- #main -->

<div class="footer" id="pageFooter">
  <div>Usuário: <?= htmlspecialchars($usuario) ?></div>
  <div>&copy; GK Soluções</div>
</div>

<!-- DRAWER -->
<div class="drawer-overlay" id="drawerOverlay"></div>
<div class="drawer" id="drawer">
  <div class="drawer-header">
    <div class="drawer-header-info">
      <h2 id="dr-title">—</h2>
      <p id="dr-sub">—</p>
    </div>
    <button class="drawer-close" id="btn-close-drawer"><i class="fas fa-xmark"></i></button>
  </div>
  <div class="drawer-body" id="drawer-body">
    <div class="empty-state"><i class="fas fa-spinner spin"></i><p>Carregando…</p></div>
  </div>
  <div class="drawer-footer" id="drawer-footer">
    <span id="dr-pct-label" style="color:var(--text-secondary)">—</span>
    <span id="dr-valor-econ" style="color:var(--status-ok);font-weight:600">—</span>
  </div>
</div>

<!-- CAIXA DE FERRAMENTAS -->

<!-- TOAST -->
<div id="toast"><i id="toast-icon"></i><span id="toast-msg"></span></div>

<script>
// ══════════════════════════════════════════════════
// UTILITÁRIOS
// ══════════════════════════════════════════════════
let toastTimer;
function toast(msg, type) {
  type = type || 'info';
  var el = document.getElementById('toast');
  var ic = document.getElementById('toast-icon');
  var ms = document.getElementById('toast-msg');
  var icons = { ok:'fa-circle-check', err:'fa-circle-xmark', warn:'fa-triangle-exclamation', info:'fa-circle-info' };
  el.className = 'show ' + type;
  ic.className = 'fas ' + (icons[type] || icons.info);
  ms.textContent = msg;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(function(){ el.className = ''; }, 3800);
}

function fmt_brl(v) {
  return 'R$ ' + parseFloat(v || 0).toLocaleString('pt-BR', { minimumFractionDigits:2, maximumFractionDigits:2 });
}

function fmt_dt(s) {
  if (!s) return '—';
  var d = new Date(s.replace(' ','T'));
  return d.toLocaleString('pt-BR');
}

function esc(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function api(data) {
  var fd = new FormData();
  for (var k in data) fd.append(k, data[k]);
  var r = await fetch(location.href, { method:'POST', body:fd });
  return r.json();
}

function setLoading(btn, on) {
  if (!btn) return;
  if (on) { btn.dataset.orig = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner spin"></i>'; btn.disabled = true; }
  else     { btn.innerHTML = btn.dataset.orig || btn.innerHTML; btn.disabled = false; }
}

// ══════════════════════════════════════════════════
// SIDEBAR
// ══════════════════════════════════════════════════
var sidebar    = document.getElementById('sidebar');
var mainArea   = document.getElementById('main');
var pageFooter = document.getElementById('pageFooter');
var toggleBtn  = document.getElementById('toggleBtn');
var toggleIcon = document.getElementById('toggleIcon');

function syncFooter(col) {
  if (pageFooter) pageFooter.style.marginLeft = col ? 'var(--sidebar-collapsed)' : 'var(--sidebar-w)';
}

if (toggleBtn) {
  toggleBtn.addEventListener('click', function() {
    var col = sidebar.classList.toggle('collapsed');
    mainArea.classList.toggle('sidebar-collapsed', col);
    toggleIcon.classList.toggle('fa-chevron-left', !col);
    toggleIcon.classList.toggle('fa-chevron-right', col);
    syncFooter(col);
  });
}

document.getElementById('menuToggle').onclick = function() {
  sidebar.classList.add('open');
  document.getElementById('sidebarOverlay').classList.add('open');
};

function fecharSidebar() {
  sidebar.classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('open');
}

sidebar.querySelectorAll('.nav-item').forEach(function(i) {
  i.addEventListener('click', function() { if (window.innerWidth <= 640) fecharSidebar(); });
});

// ══════════════════════════════════════════════════
// TOOLS PANEL
// ══════════════════════════════════════════════════
// ══════════════════════════════════════════════════
// TABS
// ══════════════════════════════════════════════════
document.querySelectorAll('.tab-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    document.querySelectorAll('.tab-pane').forEach(function(p){ p.classList.remove('active'); });
    btn.classList.add('active');
    var tab = btn.dataset.tab;
    document.getElementById('tab-' + tab).classList.add('active');
    if (tab === 'catalogo') initCatalogo();
    if (tab === 'historico') { preencherTiposHist(); carregarHistorico(); }
  });
});

// ══════════════════════════════════════════════════
// ABA 1 — EQUIPAMENTOS
// ══════════════════════════════════════════════════
async function carregarEquipamentos() {
  var busca      = document.getElementById('eq-busca').value;
  var filtro_tipo = document.getElementById('eq-tipo').value;
  var filtro_pct  = document.getElementById('eq-pct').value;
  var tbody = document.getElementById('eq-tbody');
  tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state"><i class="fas fa-spinner spin"></i><p>Carregando…</p></div></td></tr>';

  var res = await api({ action:'listar_equipamentos', busca:busca, filtro_tipo:filtro_tipo, filtro_pct:filtro_pct });
  if (!res.ok) { toast(res.msg || 'Erro ao carregar', 'err'); return; }

  var data = res.data || [];

  // Resumo: quantos ainda têm peça a retirar
  var elNum = document.getElementById('resumoNum');
  var elLis = document.getElementById('resumoListados');
  if (elNum) elNum.textContent = (res.disponiveis != null ? res.disponiveis : 0);
  if (elLis) elLis.textContent = (res.listados    != null ? res.listados    : data.length);

  if (!data.length) {
    tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state"><i class="fas fa-box-open"></i><p>Nenhum equipamento encontrado.</p></div></td></tr>';
    return;
  }

  tbody.innerHTML = data.map(function(r) {
    var dispBadge;
    if (r.pct_disp === -1) {
      dispBadge = '<span class="badge badge-gray">Sem catálogo</span>';
    } else {
      var cls = r.pct_cor === 'green' ? 'badge-green' : r.pct_cor === 'amber' ? 'badge-amber' : 'badge-red';
      dispBadge = '<span class="badge ' + cls + '" title="' + esc(r.fracao) + '">' +
        esc(r.fracao) + ' &nbsp;<span style="opacity:.7">(' + esc(r.pct_label) + ')</span>' +
        '</span>';
    }
    var tipoBadge = (r.pct_disp !== -1 && r.pct_disp !== '-1')
      ? '<span class="badge badge-steel" style="max-width:140px;overflow:hidden;text-overflow:ellipsis" title="Catálogo encontrado"><i class="fas fa-check" style="font-size:9px"></i> No catálogo</span>'
      : '<span class="badge badge-gray">Sem catálogo</span>';
    return '<tr data-id="' + r.id + '" class="eq-row">' +
      '<td style="font-size:11px;color:var(--text-muted)">#' + r.id + '</td>' +
      '<td><strong>' + esc(r.descricao) + '</strong></td>' +
      '<td>' + (esc(r.marca) || '—') + '</td>' +
      '<td>' + (esc(r.modelo) || '—') + '</td>' +
      '<td>' + (esc(r.serie) || '—') + '</td>' +
      '<td>' + (esc(r.tag_antiga)  || '—') + '</td>' +
      '<td>' + (esc(r.tag_trocada) || '—') + '</td>' +
      '<td>' + tipoBadge + '</td>' +
      '<td>' + dispBadge + '</td>' +
      '<td><button class="btn btn-ghost btn-xs btn-abrir" data-id="' + r.id + '"><i class="fas fa-screwdriver-wrench"></i> Abrir</button></td>' +
      '</tr>';
  }).join('');

  document.querySelectorAll('.btn-abrir').forEach(function(b) {
    b.addEventListener('click', function(e) { e.stopPropagation(); abrirDrawer(parseInt(b.dataset.id)); });
  });
  document.querySelectorAll('.eq-row').forEach(function(row) {
    row.addEventListener('click', function() { abrirDrawer(parseInt(row.dataset.id)); });
  });
}

async function preencherTiposEq() {
  // Filtra por tipos que existem no catálogo (tipo_equipamento = descricao do equipamento)
  var res = await api({ action:'listar_tipos_disponiveis' });
  var sel = document.getElementById('eq-tipo');
  if (res.ok) {
    res.data.forEach(function(t) {
      var o = document.createElement('option');
      o.value = t; o.textContent = t;
      sel.appendChild(o);
    });
  }
}

document.getElementById('btn-buscar-eq').addEventListener('click', carregarEquipamentos);
document.getElementById('eq-busca').addEventListener('keydown', function(e){ if (e.key === 'Enter') carregarEquipamentos(); });

// ══════════════════════════════════════════════════
// DRAWER
// ══════════════════════════════════════════════════
var drawerIdBaixa = null;

function openDrawer() {
  document.getElementById('drawer').classList.add('open');
  document.getElementById('drawerOverlay').classList.add('open');
}

function closeDrawer() {
  document.getElementById('drawer').classList.remove('open');
  document.getElementById('drawerOverlay').classList.remove('open');
  drawerIdBaixa = null;
  drawerTipoAtual = null;
}

document.getElementById('btn-close-drawer').addEventListener('click', closeDrawer);
document.getElementById('drawerOverlay').addEventListener('click', closeDrawer);

async function abrirDrawer(id_baixa) {
  drawerIdBaixa = id_baixa;
  document.getElementById('dr-title').textContent = '…';
  document.getElementById('dr-sub').textContent = '…';
  document.getElementById('drawer-body').innerHTML = '<div class="empty-state"><i class="fas fa-spinner spin"></i><p>Carregando…</p></div>';
  document.getElementById('dr-pct-label').textContent = '—';
  document.getElementById('dr-valor-econ').textContent = '—';
  openDrawer();

  var res = await api({ action:'abrir_equipamento', id_baixa:id_baixa });
  if (!res.ok) { toast(res.msg || 'Erro', 'err'); return; }

  var eq = res.equip;
  var pecas = res.pecas || [];
  var valor_econ = parseFloat(res.valor_economizado || 0);
  document.getElementById('dr-title').textContent = eq.descricao || '—';
  document.getElementById('dr-sub').textContent = [eq.marca, eq.modelo, eq.serie].filter(Boolean).join(' · ');

  var total = pecas.length;
  var removidas = pecas.filter(function(p){ return p.status === 'REMOVIDO'; }).length;
  if (total > 0) {
    var pct = Math.round((total - removidas) / total * 100);
    var cor = pct >= 75 ? 'var(--status-ok)' : pct >= 25 ? 'var(--status-warn)' : 'var(--status-err)';
    document.getElementById('dr-pct-label').innerHTML =
      '<span style="color:' + cor + '">' + pct + '% disponível</span> ' +
      '<span style="color:var(--text-muted)">(' + (total - removidas) + '/' + total + ' peças)</span>';
  } else {
    document.getElementById('dr-pct-label').textContent = 'Sem catálogo definido';
  }
  document.getElementById('dr-valor-econ').textContent = valor_econ > 0 ? fmt_brl(valor_econ) + ' economizados' : '';

  renderDrawerBody(eq, pecas);
}

function renderDrawerBody(eq, pecas) {
  var html = '';

  // Cabeçalho informativo: catálogo vinculado automaticamente pela descrição
  if (pecas.length) {
    html += '<div class="tipo-section" style="margin-bottom:16px">' +
      '<label><i class="fas fa-link" style="margin-right:5px"></i>Catálogo vinculado automaticamente</label>' +
      '<div class="tipo-row">' +
        '<span class="badge badge-steel" style="font-size:12px;padding:5px 12px">' + esc(eq.descricao) + '</span>' +
        '<span style="font-size:11px;color:var(--text-muted)">' + pecas.length + ' peça(s)</span>' +
      '</div>' +
      '</div>';
    html += pecas.map(function(p){ return renderPecaItem(p, drawerIdBaixa); }).join('');
  } else {
    html += '<div class="empty-state" style="padding:40px">' +
      '<i class="fas fa-box-open"></i>' +
      '<p>Nenhuma peça cadastrada no catálogo para <strong>' + esc(eq.descricao) + '</strong>.</p>' +
      '<p style="margin-top:8px;font-size:12px">Acesse <em>Catálogo de Peças</em> e crie um tipo com esse nome.</p>' +
      '</div>';
  }

  document.getElementById('drawer-body').innerHTML = html;

  document.querySelectorAll('.btn-retirar').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var id_cat = btn.dataset.id_catalogo;
      var form = document.getElementById('peca-form-' + id_cat);
      document.querySelectorAll('.peca-form.open').forEach(function(f){ if (f !== form) f.classList.remove('open'); });
      form.classList.toggle('open');
      // O botão de confirmar herda o estado escolhido (removido/avariada/inexistente)
      var conf = form.querySelector('.btn-confirmar-retirada');
      if (conf) {
        conf.dataset.estado = btn.dataset.estado;
        var lbl = { 'REMOVIDO':'Confirmar retirada',
                    'AVARIADA':'Confirmar avaria',
                    'INEXISTENTE':'Confirmar inexistência' }[btn.dataset.estado] || 'Confirmar';
        conf.innerHTML = '<i class="fas fa-check"></i> ' + lbl;
        conf.className = 'btn btn-sm btn-confirmar-retirada ' +
          (btn.dataset.estado === 'REMOVIDO' ? 'btn-danger' :
           btn.dataset.estado === 'AVARIADA' ? 'btn-warn' : 'btn-ghost');
      }
    });
  });

  document.querySelectorAll('.btn-confirmar-retirada').forEach(function(btn) {
    btn.addEventListener('click', async function() {
      var id_cat = btn.dataset.id_catalogo;
      var obs = document.getElementById('obs-retirada-' + id_cat).value;
      var estado = btn.dataset.estado || 'REMOVIDO';
      setLoading(btn, true);
      var res = await api({ action:'salvar_status_peca', id_baixa:drawerIdBaixa, id_catalogo:id_cat, status:estado, obs:obs });
      setLoading(btn, false);
      if (!res.ok) { toast(res.msg || 'Erro', 'err'); return; }
      toast({ 'REMOVIDO':'Peça marcada como removida!',
              'AVARIADA':'Peça marcada como avariada.',
              'INEXISTENTE':'Peça marcada como inexistente.' }[estado] || 'Registrado.', 'ok');
      abrirDrawer(drawerIdBaixa);
      carregarEquipamentos();
    });
  });

  document.querySelectorAll('.btn-restaurar').forEach(function(btn) {
    btn.addEventListener('click', async function() {
      var id_cat = btn.dataset.id_catalogo;
      setLoading(btn, true);
      var res = await api({ action:'salvar_status_peca', id_baixa:drawerIdBaixa, id_catalogo:id_cat, status:'DISPONIVEL', obs:'' });
      setLoading(btn, false);
      if (!res.ok) { toast(res.msg || 'Erro', 'err'); return; }
      toast('Peça restaurada para Disponível!', 'ok');
      abrirDrawer(drawerIdBaixa);
      carregarEquipamentos();
    });
  });
}

/* Estados possíveis de uma peça.
   REMOVIDO conta como economia; AVARIADA e INEXISTENTE não — a peça existia
   no catálogo do modelo, mas não pôde ser aproveitada. */
var PECA_ESTADOS = {
  'REMOVIDO':    { badge:'badge-red',   icone:'fa-circle-xmark',        rotulo:'REMOVIDO' },
  'AVARIADA':    { badge:'badge-amber', icone:'fa-triangle-exclamation', rotulo:'AVARIADA' },
  'INEXISTENTE': { badge:'badge-gray',  icone:'fa-ban',                  rotulo:'INEXISTENTE' }
};

function renderPecaItem(p) {
  var est      = PECA_ESTADOS[p.status] || null;
  var removida = est !== null;               // qualquer estado != DISPONIVEL
  var badge = est
    ? '<span class="badge ' + est.badge + '"><i class="fas ' + est.icone + '"></i> ' + est.rotulo + '</span>'
    : '<span class="badge badge-green"><i class="fas fa-circle-check"></i> DISPONÍVEL</span>';

  var acoes = '';
  var metaExtra = '';

  if (!removida) {
    acoes =
      '<button class="btn btn-danger btn-xs btn-retirar" data-id_catalogo="' + p.id_catalogo + '" data-estado="REMOVIDO">' +
        '<i class="fas fa-minus"></i> Retirar</button>' +
      '<button class="btn btn-warn btn-xs btn-retirar" data-id_catalogo="' + p.id_catalogo + '" data-estado="AVARIADA">' +
        '<i class="fas fa-triangle-exclamation"></i> Avariada</button>' +
      '<button class="btn btn-ghost btn-xs btn-retirar" data-id_catalogo="' + p.id_catalogo + '" data-estado="INEXISTENTE">' +
        '<i class="fas fa-ban"></i> Inexistente</button>';
    acoes += '<div class="peca-form" id="peca-form-' + p.id_catalogo + '">' +
      '<textarea id="obs-retirada-' + p.id_catalogo + '" placeholder="Observação (opcional)…"></textarea>' +
      '<button class="btn btn-danger btn-sm btn-confirmar-retirada" data-id_catalogo="' + p.id_catalogo + '">' +
      '<i class="fas fa-check"></i> Confirmar</button>' +
      '</div>';
  } else {
    metaExtra = '<span><i class="fas fa-user"></i> ' + esc(p.usuario_retirada || '—') + '</span>';
    if (p.data_retirada) metaExtra += '<span><i class="far fa-calendar"></i> ' + fmt_dt(p.data_retirada) + '</span>';
    if (p.obs_retirada) metaExtra += '<span><i class="fas fa-note-sticky"></i> ' + esc(p.obs_retirada) + '</span>';
    acoes = '<button class="btn btn-success btn-xs btn-restaurar" data-id_catalogo="' + p.id_catalogo + '"><i class="fas fa-rotate-left"></i> Restaurar</button>';
  }

  return '<div class="peca-item' + (removida ? ' removida' : '') + '">' +
    '<div class="peca-item-top">' +
      '<div>' +
        '<div class="peca-nome">' + esc(p.nome_peca) + '</div>' +
        '<div class="peca-valor">' + fmt_brl(p.valor_estimado) + '</div>' +
      '</div>' +
      '<div style="display:flex;align-items:center;gap:8px">' + badge + '</div>' +
    '</div>' +
    (metaExtra ? '<div class="peca-meta">' + metaExtra + '</div>' : '') +
    acoes +
    '</div>';
}

// ══════════════════════════════════════════════════
// ABA 2 — CATÁLOGO
// ══════════════════════════════════════════════════
var cat_tipo_selecionado = null;

async function initCatalogo() {
  await carregarTiposCatalogo();
  popularSugestoesTipo();
}

async function popularSugestoesTipo() {
  var dl = document.getElementById('tipo-sugestoes');
  if (!dl || dl.children.length) return; // já populado
  var res = await api({ action:'listar_descricoes_equipamentos' });
  if (!res.ok) return;
  dl.innerHTML = res.data.map(function(d) {
    return '<option value="' + esc(d) + '">';
  }).join('');
}

async function carregarTiposCatalogo() {
  var res = await api({ action:'listar_tipos_catalogo' });
  if (!res.ok) { toast(res.msg || 'Erro', 'err'); return; }
  var lista = res.data || [];
  document.getElementById('tipo-count').textContent = lista.length + ' tipos';

  var el = document.getElementById('tipos-list');
  if (!lista.length) {
    el.innerHTML = '<div class="empty-state" style="padding:30px 10px"><i class="fas fa-folder-open"></i><p>Nenhum tipo cadastrado</p></div>';
    return;
  }
  el.innerHTML = lista.map(function(t) {
    return '<div class="tipo-list-item' + (cat_tipo_selecionado === t.tipo_equipamento ? ' selected' : '') + '" data-tipo="' + esc(t.tipo_equipamento) + '">' +
      '<span>' + esc(t.tipo_equipamento) + '</span>' +
      '<div style="display:flex;align-items:center;gap:6px">' +
        '<span class="count">' + t.total_pecas + '</span>' +
        '<button class="btn btn-danger btn-xs btn-del-tipo" data-tipo="' + esc(t.tipo_equipamento) + '" title="Excluir tipo" style="padding:2px 6px">' +
          '<i class="fas fa-trash"></i>' +
        '</button>' +
      '</div>' +
      '</div>';
  }).join('');

  el.querySelectorAll('.tipo-list-item').forEach(function(item) {
    item.addEventListener('click', function(e) {
      if (e.target.closest('.btn-del-tipo')) return;
      cat_tipo_selecionado = item.dataset.tipo;
      el.querySelectorAll('.tipo-list-item').forEach(function(i){ i.classList.remove('selected'); });
      item.classList.add('selected');
      carregarPecasTipo(cat_tipo_selecionado);
    });
  });

  el.querySelectorAll('.btn-del-tipo').forEach(function(btn) {
    btn.addEventListener('click', async function(e) {
      e.stopPropagation();
      var tipo = btn.dataset.tipo;
      if (!confirm('Excluir o tipo "' + tipo + '" e todas as suas peças do catálogo?\n\nEsta ação não pode ser desfeita.')) return;
      setLoading(btn, true);
      var res = await api({ action:'deletar_tipo_catalogo', tipo_equipamento:tipo });
      setLoading(btn, false);
      if (!res.ok) { toast(res.msg || 'Erro', 'err'); return; }
      toast('Tipo excluído com sucesso', 'ok');
      if (cat_tipo_selecionado === tipo) {
        cat_tipo_selecionado = null;
        document.getElementById('cat-tipo-title').textContent = 'Selecione um tipo';
        document.getElementById('cat-stats').textContent = '';
        document.getElementById('cat-footer').style.display = 'none';
        document.getElementById('pecas-list').innerHTML = '<div class="empty-state" style="padding:40px 10px"><i class="fas fa-arrow-left" style="font-size:1.4rem;opacity:.3"></i><p style="margin-top:8px">Selecione um tipo à esquerda</p></div>';
      }
      carregarTiposCatalogo();
      atualizarFiltroTipoEq();
    });
  });

  if (cat_tipo_selecionado) carregarPecasTipo(cat_tipo_selecionado);
}

async function carregarPecasTipo(tipo) {
  document.getElementById('cat-tipo-title').textContent = tipo;
  document.getElementById('cat-footer').style.display = '';
  var pecasEl = document.getElementById('pecas-list');
  pecasEl.innerHTML = '<div class="empty-state"><i class="fas fa-spinner spin"></i></div>';

  var res = await api({ action:'listar_pecas_tipo', tipo_equipamento:tipo });
  if (!res.ok) { toast(res.msg || 'Erro', 'err'); return; }

  var lista = res.data || [];
  document.getElementById('cat-stats').textContent =
    'Total: ' + lista.length + ' peças · Valor médio: ' + fmt_brl(res.valor_medio || 0);

  if (!lista.length) {
    pecasEl.innerHTML = '<div class="empty-state" style="padding:30px"><i class="fas fa-box-open"></i><p>Nenhuma peça cadastrada para este tipo.</p></div>';
    return;
  }

  pecasEl.innerHTML = lista.map(function(p) {
    return '<div class="peca-cat-item" data-id="' + p.id + '">' +
      '<span class="pn">' + esc(p.nome_peca) + '</span>' +
      '<span class="pv">' + fmt_brl(p.valor_estimado) + '</span>' +
      '<span class="badge ' + (p.ativo == 1 ? 'badge-green' : 'badge-gray') + '" style="font-size:10px">' + (p.ativo == 1 ? 'Ativo' : 'Inativo') + '</span>' +
      '<div class="pa">' +
        '<button class="btn btn-ghost btn-xs btn-toggle-ativo" data-id="' + p.id + '" data-ativo="' + p.ativo + '" title="' + (p.ativo == 1 ? 'Inativar' : 'Reativar') + '">' +
          '<i class="fas ' + (p.ativo == 1 ? 'fa-eye-slash' : 'fa-eye') + '"></i>' +
        '</button>' +
        '<button class="btn btn-danger btn-xs btn-del-peca" data-id="' + p.id + '" title="Excluir"><i class="fas fa-trash"></i></button>' +
      '</div>' +
      '</div>';
  }).join('');

  pecasEl.querySelectorAll('.btn-toggle-ativo').forEach(function(btn) {
    btn.addEventListener('click', async function() {
      var novoAtivo = btn.dataset.ativo == '1' ? 0 : 1;
      setLoading(btn, true);
      var res = await api({ action:'toggle_ativo_peca', id:btn.dataset.id, ativo:novoAtivo });
      setLoading(btn, false);
      if (!res.ok) { toast(res.msg || 'Erro', 'err'); return; }
      toast(novoAtivo ? 'Peça reativada' : 'Peça inativada', 'ok');
      carregarPecasTipo(cat_tipo_selecionado);
      carregarTiposCatalogo();
    });
  });

  pecasEl.querySelectorAll('.btn-del-peca').forEach(function(btn) {
    btn.addEventListener('click', async function() {
      if (!confirm('Excluir esta peça do catálogo?')) return;
      setLoading(btn, true);
      var res = await api({ action:'deletar_peca_catalogo', id:btn.dataset.id });
      setLoading(btn, false);
      if (!res.ok) { toast(res.msg || 'Erro', 'err'); return; }
      toast('Peça excluída', 'ok');
      carregarPecasTipo(cat_tipo_selecionado);
      carregarTiposCatalogo();
    });
  });
}

document.getElementById('btn-add-tipo').addEventListener('click', async function() {
  var input = document.getElementById('new-tipo-input');
  var tipo = input.value.trim();
  if (!tipo) { toast('Informe o nome do tipo', 'warn'); return; }
  var btn = document.getElementById('btn-add-tipo');
  setLoading(btn, true);
  var resLista = await api({ action:'listar_tipos_catalogo' });
  setLoading(btn, false);
  if (resLista.ok) {
    var existe = resLista.data.some(function(t){ return t.tipo_equipamento.toLowerCase() === tipo.toLowerCase(); });
    if (existe) { toast('Tipo já existe', 'warn'); return; }
  }
  cat_tipo_selecionado = tipo;
  document.getElementById('cat-tipo-title').textContent = tipo + ' (novo)';
  document.getElementById('cat-footer').style.display = '';
  document.getElementById('cat-stats').textContent = '0 peças cadastradas';
  document.getElementById('pecas-list').innerHTML = '<div class="empty-state" style="padding:30px"><i class="fas fa-circle-info"></i><p>Tipo novo. Adicione peças abaixo.</p></div>';
  input.value = '';
  toast('Tipo selecionado. Adicione peças para criá-lo.', 'info');
});

document.getElementById('btn-add-peca').addEventListener('click', async function() {
  if (!cat_tipo_selecionado) { toast('Selecione um tipo', 'warn'); return; }
  var nome  = document.getElementById('new-peca-nome').value.trim();
  var valor = document.getElementById('new-peca-valor').value;
  if (!nome) { toast('Informe o nome da peça', 'warn'); return; }
  var btn = document.getElementById('btn-add-peca');
  setLoading(btn, true);
  var res = await api({ action:'salvar_peca_catalogo', tipo_equipamento:cat_tipo_selecionado, nome_peca:nome, valor_estimado:valor || '0' });
  setLoading(btn, false);
  if (!res.ok) { toast(res.msg || 'Erro', 'err'); return; }
  toast('Peça salva!', 'ok');
  document.getElementById('new-peca-nome').value = '';
  document.getElementById('new-peca-valor').value = '';
  carregarPecasTipo(cat_tipo_selecionado);
  carregarTiposCatalogo();
  atualizarFiltroTipoEq();
});

async function atualizarFiltroTipoEq() {
  var sel = document.getElementById('eq-tipo');
  var cur = sel.value;
  while (sel.options.length > 1) sel.remove(1);
  var res = await api({ action:'listar_tipos_disponiveis' });
  if (res.ok) {
    res.data.forEach(function(t) {
      var o = document.createElement('option');
      o.value = t; o.textContent = t;
      if (t === cur) o.selected = true;
      sel.appendChild(o);
    });
  }
}

// ══════════════════════════════════════════════════
// ABA 3 — HISTÓRICO
// ══════════════════════════════════════════════════
async function preencherTiposHist() {
  var sel = document.getElementById('hist-tipo');
  while (sel.options.length > 1) sel.remove(1);
  var res = await api({ action:'listar_tipos_disponiveis' });
  if (res.ok) {
    res.data.forEach(function(t) {
      var o = document.createElement('option');
      o.value = t; o.textContent = t;
      sel.appendChild(o);
    });
  }
}

/* Diferencia peça aproveitada de peça que existia mas não servia.
   INEXISTENTE não chega aqui — é filtrado no backend. */
function situacaoBadge(st) {
  if (st === 'AVARIADA') return '<span class="badge badge-amber"><i class="fas fa-triangle-exclamation"></i> Avariada</span>';
  return '<span class="badge badge-green"><i class="fas fa-check"></i> Utilizada</span>';
}

async function carregarHistorico() {
  var busca          = document.getElementById('hist-busca').value;
  var tipo           = document.getElementById('hist-tipo').value;
  var usuario_filtro = document.getElementById('hist-usuario').value;
  var status_filtro  = document.getElementById('hist-status').value;
  var dt_ini         = document.getElementById('hist-dt-ini').value;
  var dt_fim         = document.getElementById('hist-dt-fim').value;

  var tbody = document.getElementById('hist-tbody');
  tbody.innerHTML = '<tr><td colspan="13"><div class="empty-state"><i class="fas fa-spinner spin"></i><p>Carregando…</p></div></td></tr>';

  var res = await api({ action:'historico_geral', busca:busca, tipo:tipo, usuario_filtro:usuario_filtro, status_filtro:status_filtro, dt_ini:dt_ini, dt_fim:dt_fim });
  if (!res.ok) { toast(res.msg || 'Erro', 'err'); return; }

  var lista = res.data || [];
  if (!lista.length) {
    tbody.innerHTML = '<tr><td colspan="13"><div class="empty-state"><i class="fas fa-clock-rotate-left"></i><p>Nenhum registro encontrado.</p></div></td></tr>';
    return;
  }

  tbody.innerHTML = lista.map(function(r) {
    var dtRetirada = r.data_retirada ? fmt_dt(r.data_retirada) : '—';
    return '<tr>' +
      '<td style="white-space:nowrap;font-size:12px">' + dtRetirada + '</td>' +
      '<td style="font-size:11px;color:var(--text-muted)">#' + r.id_cadastro + '</td>' +
      '<td><strong>' + esc(r.descricao) + '</strong></td>' +
      '<td style="font-size:12px">' + (esc(r.marca) || '—') + ' / ' + (esc(r.modelo) || '—') + '</td>' +
      '<td style="font-size:12px">' + (esc(r.serie) || '—') + '</td>' +
      '<td>' + (esc(r.tag_antiga)  || '—') + '</td>' +
      '<td>' + (esc(r.tag_trocada) || '—') + '</td>' +
      '<td><span class="badge badge-steel" style="font-size:10px">' + esc(r.tipo_equipamento) + '</span></td>' +
      '<td><strong>' + esc(r.nome_peca) + '</strong></td>' +
      '<td>' + situacaoBadge(r.status) + '</td>' +
      // Avariada não gerou economia: o valor só faz sentido no reaproveitamento
      '<td style="white-space:nowrap">' + (r.status === 'REMOVIDO' && r.valor_estimado ? fmt_brl(r.valor_estimado) : '—') + '</td>' +
      '<td>' + (esc(r.usuario) || '—') + '</td>' +
      '<td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(r.obs) + '">' + (esc(r.obs) || '—') + '</td>' +
      '</tr>';
  }).join('');
}

document.getElementById('btn-hist-buscar').addEventListener('click', carregarHistorico);
document.getElementById('hist-busca').addEventListener('keydown', function(e){ if (e.key === 'Enter') carregarHistorico(); });

// ══════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════
(async function init() {
  await preencherTiposEq();
  await carregarEquipamentos();
})();
</script>
<?php eng_clin_menu_painel(true); ?>
</body>
</html>
