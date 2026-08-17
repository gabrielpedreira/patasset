<?php
session_start();
require_once 'eng_clin_menu.php';
$ENG_CLIN_PAGINA = 'estoque';   // item ativo no menu lateral
mysqli_report(MYSQLI_REPORT_OFF);
include 'conexao.php';

if (!isset($_SESSION['usuario_logado'])) { header("Location: index.html"); exit(); }
$usuario = $_SESSION['usuario_logado'];

$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
$stmt->bind_param("s", $usuario); $stmt->execute();
$res = $stmt->get_result();
$nivel = 'C'; $classe_usuario = ''; $status = 'ATIVO';
if ($r = $res->fetch_assoc()) {
    $nivel          = strtoupper(trim($r['permicao']));
    $classe_usuario = strtoupper(trim($r['classe_usuario']));
    $status         = $r['status'] ?? 'ATIVO';
}
$stmt->close();
if ($status !== 'ATIVO') { session_destroy(); header("Location: index.html?erro=bloqueado"); exit(); }
if (!in_array($classe_usuario, ['DEV','ENGENHARIA CLINICA'])) { header("Location: acesso_bloqueado.html"); exit(); }
if (!in_array($nivel, ['A','B']) && $classe_usuario !== 'DEV') { header("Location: acesso_bloqueado.html"); exit(); }

date_default_timezone_set('America/Sao_Paulo');
$data_hoje = date('Y-m-d');
$hora_agora = date('H:i:s');

// ── Unidade do técnico ────────────────────────────────────────────────────────
$unidade_tecnico = '';
$stmt2 = $conn->prepare("SELECT unidade FROM tecnico WHERE usuario=? LIMIT 1");
$stmt2->bind_param("s", $usuario); $stmt2->execute();
$res2 = $stmt2->get_result();
if ($r2 = $res2->fetch_assoc()) $unidade_tecnico = strtoupper(trim($r2['unidade']));
$stmt2->close();

$todas_unidades = [
    'CASA DE PORTUGAL (ESTOQUE CENTRAL)','EVANGELICO','ILHA DO GOVERNADOR',
    'RIO LARANJEIRAS','RIO BOTAFOGO','SÃO BERNARDO','PREMIUM',
    'PRONTOCOR','OFTALMOCASA','MENSSANA','OUTROS',
];
$unidades_map = [
    'CASA DE PORTUGAL'                   => 'CASA DE PORTUGAL (ESTOQUE CENTRAL)',
    'CASA DE PORTUGAL (ESTOQUE CENTRAL)' => 'CASA DE PORTUGAL (ESTOQUE CENTRAL)',
    'EVANGELICO'        => 'EVANGELICO',   'ILHA DO GOVERNADOR' => 'ILHA DO GOVERNADOR',
    'RIO LARANJEIRAS'   => 'RIO LARANJEIRAS', 'RIO BOTAFOGO'    => 'RIO BOTAFOGO',
    'SÃO BERNARDO'      => 'SÃO BERNARDO', 'SAO BERNARDO'       => 'SÃO BERNARDO',
    'PREMIUM'           => 'PREMIUM',      'PRONTOCOR'          => 'PRONTOCOR',
    'OFTALMOCASA'       => 'OFTALMOCASA',  'MENSSANA'           => 'MENSSANA',
    'OUTROS'            => 'OUTROS',
];
$unidade_pre   = $unidades_map[$unidade_tecnico] ?? '';
$fixar_unidade = ($nivel === 'B' && $classe_usuario !== 'DEV' && $unidade_pre !== '');

$msg = ''; $msg_tipo = '';

// ── Catálogo de peças ─────────────────────────────────────────────────────────
$catalogo = [];
$resC = $conn->query("SELECT codigo, descricao FROM engclin_cadastro_pecas WHERE ativo=1 ORDER BY descricao ASC");
if ($resC) while ($rc = $resC->fetch_assoc()) $catalogo[] = $rc;

// ── Próximo código de entrada ─────────────────────────────────────────────────
function proximo_ec($conn) {
    $res = $conn->query("SELECT MAX(CAST(SUBSTRING(codigo, 4) AS UNSIGNED)) AS ultimo FROM estoque_engenharia");
    $u = 0;
    if ($r = $res->fetch_assoc()) $u = (int)($r['ultimo'] ?? 0);
    return 'EC-' . str_pad($u + 1, 6, '0', STR_PAD_LEFT);
}
$proximo_ec = proximo_ec($conn);

// ── EXCLUIR ENTRADA ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excluir') {
    $id_del = intval($_POST['id'] ?? 0);
    if ($id_del > 0) {
        $st = $conn->prepare("UPDATE estoque_engenharia SET ativo=0 WHERE id=?");
        $st->bind_param("i", $id_del);
        $st->execute() && $st->affected_rows > 0
            ? ($msg = 'Entrada removida.') && ($msg_tipo = 'ok')
            : ($msg = 'Erro ao remover.') && ($msg_tipo = 'erro');
        $st->close();
    }
}

// ── NOVA ENTRADA (um ou vários itens na mesma nota) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'entrada') {
    // Dados compartilhados: valem para todos os itens do lançamento
    $unidade_s  = $fixar_unidade ? $unidade_pre : trim($_POST['unidade'] ?? '');
    $nota_s     = trim($_POST['numero_nota'] ?? '');
    $garantia_s = in_array($_POST['tem_garantia'] ?? '', ['SIM','NAO']) ? $_POST['tem_garantia'] : 'NAO';
    $dt_gar_s   = trim($_POST['data_garantia'] ?? '') ?: null;

    // Itens: arrays paralelos vindos das linhas do formulário
    $cods    = (array)($_POST['codigo_peca'] ?? []);
    $nomes   = (array)($_POST['nome']        ?? []);
    $modelos = (array)($_POST['modelo']      ?? []);
    $marcas  = (array)($_POST['marca']       ?? []);
    $qtds    = (array)($_POST['quantidade']  ?? []);
    $valores = (array)($_POST['valor']       ?? []);

    if ($unidade_s === '') {
        $msg = 'Selecione a unidade.'; $msg_tipo = 'erro';
    } elseif (!$cods) {
        $msg = 'Adicione ao menos um item.'; $msg_tipo = 'erro';
    } else {
        $gravados = 0; $ignorados = 0; $erros = [];

        $st = $conn->prepare("INSERT INTO estoque_engenharia (codigo,codigo_peca,unidade,nome,modelo,marca,numero_nota,quantidade,quantidade_inicial,tem_garantia,data_garantia,valor,usuario_cadastro,origem) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'NOTA')");

        if (!$st) {
            $msg = 'Erro ao preparar a gravação: '.$conn->error; $msg_tipo = 'erro';
        } else {
            foreach ($cods as $i => $cod) {
                $codigo_peca = trim((string)$cod);
                $nome_s      = trim((string)($nomes[$i] ?? ''));

                // Sem código ou nome, a linha ficou em branco — ignora
                if ($codigo_peca === '' || $nome_s === '') { $ignorados++; continue; }

                $modelo_s = trim((string)($modelos[$i] ?? ''));
                $marca_s  = trim((string)($marcas[$i]  ?? ''));
                $qty_s    = max(1, intval($qtds[$i] ?? 1));
                $vraw     = str_replace(['.', ','], ['', '.'], trim((string)($valores[$i] ?? '')));
                $valor_s  = is_numeric($vraw) ? floatval($vraw) : null;

                // Cada item vira um lote com código EC próprio
                $cod_ec = proximo_ec($conn);
                $st->bind_param("sssssssiissds", $cod_ec, $codigo_peca, $unidade_s, $nome_s,
                                $modelo_s, $marca_s, $nota_s, $qty_s, $qty_s,
                                $garantia_s, $dt_gar_s, $valor_s, $usuario);
                if ($st->execute()) $gravados++;
                else $erros[] = $nome_s . ': ' . $st->error;
            }
            $st->close();

            if ($gravados > 0) {
                $msg = $gravados === 1
                     ? "Entrada registrada: 1 item."
                     : "Entrada registrada: {$gravados} itens na mesma nota.";
                if ($nota_s !== '') $msg .= " Nota {$nota_s}.";
                if ($ignorados)     $msg .= " {$ignorados} linha(s) em branco ignorada(s).";
                $msg_tipo = $erros ? 'warn' : 'ok';
                if ($erros) $msg .= ' Falhas: ' . implode(' | ', $erros);
            } else {
                $msg = $erros ? 'Erro: '.implode(' | ', $erros) : 'Nenhum item válido informado.';
                $msg_tipo = 'erro';
            }
            $proximo_ec = proximo_ec($conn);
        }
    }
}

// ── EDITAR ENTRADA ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'editar') {
    $id_edit  = intval($_POST['id_edit'] ?? 0);
    $qty_e    = max(0, intval($_POST['quantidade'] ?? 0));
    $nota_e   = trim($_POST['numero_nota'] ?? '');
    $gar_e    = in_array($_POST['tem_garantia']??'',['SIM','NAO'])?$_POST['tem_garantia']:'NAO';
    $dtg_e    = trim($_POST['data_garantia']??'') ?: null;
    $val_raw  = str_replace(['.', ','], ['', '.'], trim($_POST['valor'] ?? ''));
    $val_e    = is_numeric($val_raw) ? floatval($val_raw) : null;
    if ($id_edit > 0) {
        // Corrigir a quantidade da NOTA ajusta o saldo vivo pela diferença,
        // preservando o que já foi consumido daquele lote.
        $stAtual = $conn->prepare("SELECT quantidade, quantidade_inicial FROM estoque_engenharia WHERE id=? LIMIT 1");
        $stAtual->bind_param('i', $id_edit); $stAtual->execute();
        $resAtual = $stAtual->get_result();
        $rowAtual = $resAtual ? $resAtual->fetch_assoc() : null;
        $stAtual->close();

        if (!$rowAtual) {
            $msg = 'Entrada não encontrada.'; $msg_tipo = 'erro';
        } else {
            $ini_antiga  = (float)$rowAtual['quantidade_inicial'];
            $saldo_atual = (float)$rowAtual['quantidade'];
            $delta       = $qty_e - $ini_antiga;
            $novo_saldo  = max(0, $saldo_atual + $delta);

            $stE = $conn->prepare("UPDATE estoque_engenharia SET quantidade=?,quantidade_inicial=?,numero_nota=?,tem_garantia=?,data_garantia=?,valor=? WHERE id=?");
            $stE->bind_param("ddsssdi", $novo_saldo,$qty_e,$nota_e,$gar_e,$dtg_e,$val_e,$id_edit);
            $stE->execute()
                ? ($msg = 'Atualizado com sucesso.') && ($msg_tipo = 'ok')
                : ($msg = 'Erro: '.$stE->error) && ($msg_tipo = 'erro');
            $stE->close();
        }
    }
}

// ── DESPACHO (almoxarifado) ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'despacho') {
    $uni_est    = trim($_POST['unidade_estoque']  ?? '');
    $uni_dest   = trim($_POST['unidade_destino']  ?? '');
    $setor_dest = trim($_POST['setor_destino']    ?? '');
    $local_dest = trim($_POST['local_destino']    ?? '');
    $nome_sol   = trim($_POST['nome_solicitante'] ?? '');
    $obs_d      = trim($_POST['observacao']       ?? '');
    $itens_raw  = $_POST['itens'] ?? []; // array de {codigo_peca, quantidade}

    $itens_validos = [];
    foreach ($itens_raw as $it) {
        $cp  = trim($it['codigo_peca'] ?? '');
        $qty = max(1, intval($it['quantidade'] ?? 1));
        if ($cp) $itens_validos[] = ['codigo_peca' => $cp, 'quantidade' => $qty];
    }

    if (!$uni_est || !$uni_dest || !$nome_sol || empty($itens_validos)) {
        $msg = 'Preencha todos os campos obrigatórios e adicione ao menos 1 item.'; $msg_tipo = 'erro';
    } else {
        // Gerar número de despacho
        $rn = $conn->query("SELECT MAX(CAST(SUBSTRING(numero_despacho,5) AS UNSIGNED)) AS u FROM engclin_despachos");
        $u  = (int)(($rn ? $rn->fetch_assoc()['u'] : 0) ?? 0);
        $num_des = 'DES-' . str_pad($u + 1, 6, '0', STR_PAD_LEFT);

        // Enriquecer itens com descrição e debitar FIFO por unidade
        foreach ($itens_validos as &$it) {
            // Pegar descrição
            $stD = $conn->prepare("SELECT descricao FROM engclin_cadastro_pecas WHERE codigo=? LIMIT 1");
            $stD->bind_param('s', $it['codigo_peca']); $stD->execute();
            $rd = $stD->get_result()->fetch_assoc(); $stD->close();
            $it['descricao'] = $rd['descricao'] ?? $it['codigo_peca'];

            // FIFO: debitar da unidade selecionada
            $restante = $it['quantidade'];
            $stE2 = $conn->prepare("SELECT id, quantidade FROM estoque_engenharia WHERE codigo_peca=? AND unidade=? AND ativo=1 AND quantidade>0 ORDER BY data_cadastro ASC, id ASC");
            $stE2->bind_param('ss', $it['codigo_peca'], $uni_est); $stE2->execute();
            $res_e2 = $stE2->get_result(); $stE2->close();
            while ($re = $res_e2->fetch_assoc()) {
                if ($restante <= 0) break;
                $deb = min($restante, (int)$re['quantidade']);
                $nq  = (int)$re['quantidade'] - $deb;
                $conn->query("UPDATE estoque_engenharia SET quantidade={$nq} WHERE id=".((int)$re['id']));
                $restante -= $deb;
            }
        }
        unset($it);

        // Salvar despacho
        $itens_json = json_encode($itens_validos, JSON_UNESCAPED_UNICODE);
        $stDes = $conn->prepare("INSERT INTO engclin_despachos (numero_despacho,unidade_estoque,unidade_destino,setor_destino,local_destino,nome_solicitante,usuario_logado,itens_json,observacao,data_despacho,hora_despacho) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stDes->bind_param("sssssssssss", $num_des,$uni_est,$uni_dest,$setor_dest,$local_dest,$nome_sol,$usuario,$itens_json,$obs_d,$data_hoje,$hora_agora);
        if ($stDes->execute()) {
            $msg = "Despacho {$num_des} registrado com sucesso!"; $msg_tipo = 'ok';
            // Redirecionar para comprovante
            header("Location: eng_clin_inventario.php?comprovante={$num_des}&msg=".urlencode($msg));
            exit();
        } else {
            $msg = 'Erro ao registrar despacho: '.$stDes->error; $msg_tipo = 'erro';
        }
        $stDes->close();
    }
}

// ── TRANSFERÊNCIA DE ESTOQUE ENTRE UNIDADES ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'transferencia') {
    $uni_orig   = trim($_POST['unidade_origem']  ?? '');
    $uni_dest   = trim($_POST['unidade_destino'] ?? '');
    $obs_t      = trim($_POST['observacao']      ?? '');
    $itens_raw  = $_POST['itens'] ?? [];

    $itens_validos = [];
    foreach ($itens_raw as $it) {
        $cp  = trim($it['codigo_peca'] ?? '');
        $qty = max(1, intval($it['quantidade'] ?? 1));
        if ($cp) $itens_validos[] = ['codigo_peca' => $cp, 'quantidade' => $qty];
    }

    if (!$uni_orig || !$uni_dest || empty($itens_validos)) {
        $msg = 'Informe origem, destino e ao menos 1 item.'; $msg_tipo = 'erro';
    } elseif ($uni_orig === $uni_dest) {
        $msg = 'Origem e destino não podem ser a mesma unidade.'; $msg_tipo = 'erro';
    } else {
        // Gerar número de transferência TRF-000001
        $rnT = $conn->query("SELECT MAX(CAST(SUBSTRING(observacao,5,6) AS UNSIGNED)) AS u FROM movimentacao_estoque_engclin WHERE observacao LIKE 'TRF-%'");
        $uT  = (int)(($rnT ? $rnT->fetch_assoc()['u'] : 0) ?? 0);
        $num_trf = 'TRF-' . str_pad($uT + 1, 6, '0', STR_PAD_LEFT);
        $obs_base = $num_trf . ($obs_t ? ' — '.$obs_t : '');

        $erros = [];
        foreach ($itens_validos as &$it) {
            $cp  = $it['codigo_peca'];
            $qty = $it['quantidade'];

            // Pegar dados da peça (nome, marca, modelo) da entrada mais recente da origem
            $stInfo = $conn->prepare("SELECT nome, marca, modelo, codigo_peca FROM estoque_engenharia WHERE codigo_peca=? AND unidade=? AND ativo=1 ORDER BY data_cadastro DESC, id DESC LIMIT 1");
            $stInfo->bind_param('ss', $cp, $uni_orig); $stInfo->execute();
            $rowInfo = $stInfo->get_result()->fetch_assoc(); $stInfo->close();

            if (!$rowInfo) {
                // Tenta buscar a peça no catálogo como fallback
                $stCat = $conn->prepare("SELECT descricao FROM engclin_cadastro_pecas WHERE codigo=? LIMIT 1");
                $stCat->bind_param('s', $cp); $stCat->execute();
                $rowCat = $stCat->get_result()->fetch_assoc(); $stCat->close();
                $nome_peca = $rowCat['descricao'] ?? $cp;
                $marca_peca = ''; $modelo_peca = '';
            } else {
                $nome_peca   = $rowInfo['nome']   ?? $cp;
                $marca_peca  = $rowInfo['marca']  ?? '';
                $modelo_peca = $rowInfo['modelo'] ?? '';
            }

            // 1. DEBITAR da unidade origem (FIFO)
            $restante = $qty;
            $stOrig = $conn->prepare("SELECT id, quantidade FROM estoque_engenharia WHERE codigo_peca=? AND unidade=? AND ativo=1 AND quantidade>0 ORDER BY data_cadastro ASC, id ASC");
            $stOrig->bind_param('ss', $cp, $uni_orig); $stOrig->execute();
            $entradas_orig = $stOrig->get_result()->fetch_all(MYSQLI_ASSOC); $stOrig->close();

            $saldo_orig = array_sum(array_column($entradas_orig, 'quantidade'));
            if ($saldo_orig < $qty) {
                $erros[] = "Saldo insuficiente para {$nome_peca}: disponível {$saldo_orig}, solicitado {$qty}.";
                continue;
            }

            foreach ($entradas_orig as $e) {
                if ($restante <= 0) break;
                $deb = min($restante, (float)$e['quantidade']);
                $nq  = round((float)$e['quantidade'] - $deb, 3);
                $conn->query("UPDATE estoque_engenharia SET quantidade={$nq} WHERE id=".((int)$e['id']));
                $restante -= $deb;
            }

            // 2. CREDITAR na unidade destino
            //    a) Verifica se já existe lote desta peça na unidade destino
            $stDest = $conn->prepare("SELECT id, quantidade FROM estoque_engenharia WHERE codigo_peca=? AND unidade=? AND ativo=1 ORDER BY data_cadastro DESC, id DESC LIMIT 1");
            $stDest->bind_param('ss', $cp, $uni_dest); $stDest->execute();
            $rowDest = $stDest->get_result()->fetch_assoc(); $stDest->close();

            if ($rowDest) {
                // Incrementa o lote mais recente da destino
                $nova_qty_d = round((float)$rowDest['quantidade'] + $qty, 3);
                $conn->query("UPDATE estoque_engenharia SET quantidade={$nova_qty_d} WHERE id=".((int)$rowDest['id']));
            } else {
                // Cria novo lote na destino
                $cod_ec_novo = proximo_ec($conn);
                // Lote criado por transferência — não é entrada por nota fiscal
                $stIns = $conn->prepare("INSERT INTO estoque_engenharia (codigo,codigo_peca,unidade,nome,marca,modelo,quantidade,quantidade_inicial,usuario_cadastro,origem) VALUES (?,?,?,?,?,?,?,?,?,'TRANSFERENCIA')");
                if ($stIns) {
                    $stIns->bind_param('ssssssdds', $cod_ec_novo, $cp, $uni_dest, $nome_peca, $marca_peca, $modelo_peca, $qty, $qty, $usuario);
                    $stIns->execute(); $stIns->close();
                }
            }

            // 3. Registrar movimentação (SAIDA da origem + ENTRADA no destino)
            $obs_saida   = "{$obs_base} | Saída de {$uni_orig} → {$uni_dest}";
            $obs_entrada = "{$obs_base} | Entrada em {$uni_dest} ← {$uni_orig}";
            $stMov = $conn->prepare("INSERT INTO movimentacao_estoque_engclin (codigo_item,nome_item,tipo,quantidade,usuario,observacao) VALUES (?,?,'SAIDA',?,?,?)");
            if ($stMov) { $stMov->bind_param('ssiss', $cp, $nome_peca, $qty, $usuario, $obs_saida); $stMov->execute(); $stMov->close(); }
            $stMov2 = $conn->prepare("INSERT INTO movimentacao_estoque_engclin (codigo_item,nome_item,tipo,quantidade,usuario,observacao) VALUES (?,?,'ENTRADA',?,?,?)");
            if ($stMov2) { $stMov2->bind_param('ssiss', $cp, $nome_peca, $qty, $usuario, $obs_entrada); $stMov2->execute(); $stMov2->close(); }

            $it['nome'] = $nome_peca;
        }
        unset($it);

        if (!empty($erros)) {
            $msg = implode(' | ', $erros); $msg_tipo = 'warn';
        } else {
            $total_tipos = count($itens_validos);
            $msg = "Transferência {$num_trf} concluída! {$total_tipos} tipo(s) de item transferidos de {$uni_orig} → {$uni_dest}.";
            $msg_tipo = 'ok';
        }
    }
}

// ── Comprovante (reimpressão) ─────────────────────────────────────────────────
$comprovante = null;
$comprovante_num = trim($_GET['comprovante'] ?? '');
if ($comprovante_num) {
    $stC = $conn->prepare("SELECT * FROM engclin_despachos WHERE numero_despacho=? LIMIT 1");
    $stC->bind_param('s', $comprovante_num); $stC->execute();
    $comprovante = $stC->get_result()->fetch_assoc(); $stC->close();
    if ($comprovante) $comprovante['itens'] = json_decode($comprovante['itens_json'], true) ?? [];
}
if (isset($_GET['msg'])) { $msg = urldecode($_GET['msg']); $msg_tipo = 'ok'; }

// ── Helper: formata quantidade sem zeros decimais inúteis (1.000 → 1) ─────────
function fmt_qty($v): string {
    $n = (float)$v;
    return ($n == floor($n))
        ? number_format($n, 0, ',', '.')
        : rtrim(rtrim(number_format($n, 3, ',', '.'), '0'), ',');
}

// ── Saldos por peça e por unidade ────────────────────────────────────────────
// saldos[codigo_peca][unidade] = qty   e   saldos[codigo_peca]['_total'] = qty_total
$saldos = [];
$resSaldo = $conn->query("SELECT codigo_peca, unidade, SUM(quantidade) AS total FROM estoque_engenharia WHERE ativo=1 AND codigo_peca IS NOT NULL AND codigo_peca <> '' GROUP BY codigo_peca, unidade");
if ($resSaldo) {
    while ($rs = $resSaldo->fetch_assoc()) {
        $cp = $rs['codigo_peca']; $un = $rs['unidade']; $qt = (int)$rs['total'];
        $saldos[$cp][$un] = $qt;
        $saldos[$cp]['_total'] = ($saldos[$cp]['_total'] ?? 0) + $qt;
    }
}
// saldos_flat[codigo_peca] = total (para compatibilidade com JS)
$saldos_flat = [];
foreach ($saldos as $cp => $uns) $saldos_flat[$cp] = $uns['_total'] ?? 0;

// ── Listar entradas ───────────────────────────────────────────────────────────
// Só entradas REAIS por nota fiscal. Lotes criados por transferência interna
// ficam de fora — já têm registro próprio na aba "Transferência de Materiais".
$filtro_inv = $fixar_unidade ? $unidade_pre : '';
$inventario = [];
if ($filtro_inv !== '') {
    $st_inv = $conn->prepare("SELECT * FROM estoque_engenharia WHERE ativo=1 AND origem='NOTA' AND unidade=? ORDER BY codigo_peca ASC, codigo ASC");
    $st_inv->bind_param("s", $filtro_inv); $st_inv->execute();
    $res_inv = $st_inv->get_result(); $st_inv->close();
} else {
    $res_inv = $conn->query("SELECT * FROM estoque_engenharia WHERE ativo=1 AND origem='NOTA' ORDER BY unidade ASC, codigo_peca ASC, codigo ASC");
}
if ($res_inv) while ($r = $res_inv->fetch_assoc()) $inventario[] = $r;

// ── Despachos recentes (últimos 20 para listar) ───────────────────────────────
$despachos_rec = [];
$resDR = $conn->query("SELECT numero_despacho, unidade_estoque, unidade_destino, nome_solicitante, data_despacho, hora_despacho FROM engclin_despachos ORDER BY criado_em DESC LIMIT 20");
if ($resDR) while ($rd = $resDR->fetch_assoc()) $despachos_rec[] = $rd;

// ── Transferências — histórico COMPLETO (uma linha por TRF) ───────────────────
// Nota: observacao/usuario precisam de MIN() por causa do ONLY_FULL_GROUP_BY
// (MySQL 5.7+), senão a query falha e a lista vem vazia.
$col_data_mov = null;
$resCols = $conn->query("SHOW COLUMNS FROM movimentacao_estoque_engclin");
if ($resCols) {
    $cols_mov = [];
    while ($rc = $resCols->fetch_assoc()) $cols_mov[] = $rc['Field'];
    foreach (['data_movimentacao','data','criado_em','data_cadastro','created_at'] as $cand) {
        if (in_array($cand, $cols_mov, true)) { $col_data_mov = $cand; break; }
    }
}
$sel_data = $col_data_mov ? "MIN(`{$col_data_mov}`) AS data_mov," : "NULL AS data_mov,";

$transferencias_rec = [];
$resTR = $conn->query("
    SELECT MIN(observacao) AS observacao,
           MIN(usuario)    AS usuario,
           {$sel_data}
           SUM(quantidade) AS total_qty,
           COUNT(*)        AS qtd_itens,
           GROUP_CONCAT(CONCAT_WS('\t', codigo_item, nome_item, quantidade)
                        ORDER BY nome_item SEPARATOR '\n') AS itens_raw,
           MAX(id)         AS feito_em
    FROM movimentacao_estoque_engclin
    WHERE tipo='SAIDA' AND observacao LIKE 'TRF-%'
    GROUP BY LEFT(observacao, 10)
    ORDER BY feito_em DESC
");
if ($resTR) while ($rt = $resTR->fetch_assoc()) $transferencias_rec[] = $rt;

$conn->close();
$data = date('d/m/Y'); $hora = date('H:i:s');

// ── Helper: mapa de catálogo para JS ──────────────────────────────────────────
$catalogo_map = [];
foreach ($catalogo as $c) $catalogo_map[$c['codigo']] = $c['descricao'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $comprovante ? htmlspecialchars($comprovante['numero_despacho']).' — Comprovante' : 'Inventário — Engenharia Clínica' ?></title>
<link rel="icon" type="image/png" href="logo_1.png">
<link rel="shortcut icon" href="logo_1.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg-page:#0f0f0f;--bg-sidebar:#141414;--bg-card:#1a1a1a;--bg-card-hover:#1f1f1f;--bg-input:#222;
  --border:rgba(255,255,255,.07);--border-hover:rgba(255,255,255,.14);--accent-steel:#a0aec0;
  --text-primary:#f0f0f0;--text-secondary:#888;--text-muted:#555;
  --sidebar-w:260px;--sidebar-collapsed:68px;--header-h:56px;
  --radius:10px;--radius-lg:16px;--transition:0.22s cubic-bezier(.4,0,.2,1);
  --font-ui:'DM Sans',sans-serif;--font-display:'Syne',sans-serif;
  --status-ok:#4ade80;--status-warn:#facc15;--status-err:#f87171;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-ui);background:var(--bg-page);color:var(--text-primary);min-height:100vh;overflow-x:hidden;display:flex;flex-direction:column}
.menu-toggle{display:none;position:fixed;top:10px;left:10px;z-index:1200;background:#2a2a2a;color:#e2e8f0;border:1px solid var(--border-hover);border-radius:8px;padding:8px 12px;font-size:20px;cursor:pointer;line-height:1}
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
.nav-item-sair:hover{background:rgba(248,113,113,.08)!important}
#sidebar.collapsed .nav-item{padding:10px 0;text-align:center;font-size:0;color:transparent;overflow:hidden;transform:none!important}
#sidebar.collapsed .nav-item::before{font-family:'Font Awesome 6 Free';font-weight:900;font-size:15px;color:#888;display:block;line-height:1;transition:color var(--transition)}
#sidebar.collapsed .nav-item:hover::before{color:#e8e9eb}
#sidebar.collapsed .nav-item.active::before{color:#fff}
#sidebar.collapsed .nav-item::after{content:attr(data-tooltip);position:absolute;left:calc(var(--sidebar-collapsed)+8px);top:50%;transform:translateY(-50%);background:#2d2d2d;color:#e2e8f0;font-size:12px;padding:5px 10px;border-radius:6px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:200;border:1px solid var(--border-hover);box-shadow:0 4px 12px rgba(0,0,0,.4)}
#sidebar.collapsed .nav-item:hover::after{opacity:1}
#sidebar.collapsed .nav-item[data-tooltip="Abertura de Chamado"]::before  {content:"\f46d"}
#sidebar.collapsed .nav-item[data-tooltip="Cadastro de Equipamento"]::before{content:"\f0fe"}
#sidebar.collapsed .nav-item[data-tooltip="Planilha"]::before             {content:"\f0ce"}
#sidebar.collapsed .nav-item[data-tooltip="Ordem de Serviço"]::before     {content:"\f46d"}
#sidebar.collapsed .nav-item[data-tooltip="Estoque"]::before              {content:"\f49e"}
#sidebar.collapsed .nav-item[data-tooltip="Movimentar"]::before           {content:"\f362"}
#sidebar.collapsed .nav-item[data-tooltip="Página Inicial"]::before       {content:"\f200"}
#sidebar.collapsed .nav-item[data-tooltip="Voltar"]::before               {content:"\f060"}
#main{margin-left:var(--sidebar-w);transition:margin-left var(--transition);min-height:calc(100vh - 42px);display:flex;flex-direction:column;flex:1}
#main.sidebar-collapsed{margin-left:var(--sidebar-collapsed)}
.topbar{height:var(--header-h);background:rgba(20,20,20,.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:10px;position:sticky;top:0;z-index:50}
.topbar-breadcrumb{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted)}
.topbar-breadcrumb span:last-child{color:var(--text-primary);font-weight:500}
.topbar-breadcrumb i{font-size:10px}
.topbar-spacer{flex:1}
.topbar-logo-rede{height:32px;width:auto;object-fit:contain;opacity:.75;transition:opacity var(--transition);flex-shrink:0}
.topbar-logo-rede:hover{opacity:1}
.content{flex:1;padding:28px;width:100%}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.page-title{font-family:var(--font-display);font-size:22px;font-weight:700;letter-spacing:-.01em}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none}
.btn-primary{background:#232323;border:1px solid rgba(255,255,255,.13);color:var(--text-primary)}
.btn-primary:hover{background:#2e2e2e;border-color:rgba(255,255,255,.2)}
.btn-success{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.25);color:#4ade80}
.btn-success:hover{background:rgba(74,222,128,.18);border-color:rgba(74,222,128,.4)}
.btn-danger{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.25);color:#f87171}
.btn-danger:hover{background:rgba(248,113,113,.18)}
.btn-warn{background:rgba(250,204,21,.1);border:1px solid rgba(250,204,21,.25);color:#facc15}
.btn-warn:hover{background:rgba(250,204,21,.18)}
.alert{padding:11px 16px;border-radius:8px;font-size:13px;font-weight:500;margin-bottom:18px;display:flex;align-items:center;gap:8px}
.alert-ok  {background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.2);color:#4ade80}
.alert-erro{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.2);color:#f87171}
/* Tabs */
.tabs{display:flex;gap:4px;margin-bottom:20px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:5px}
.tab-btn{flex:1;padding:9px 10px;border-radius:7px;border:none;background:none;color:var(--text-muted);font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;transition:all var(--transition);display:flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap}
.tab-btn:hover{color:var(--text-primary);background:rgba(255,255,255,.04)}
.tab-btn.active{background:#2a2c31;color:#fff;font-weight:600}
/* Form section */
.form-section{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:20px;transition:border-color var(--transition)}
.form-section:hover{border-color:var(--border-hover)}
.fsec-header{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.02)}
.fsec-icon{width:28px;height:28px;border-radius:7px;background:rgba(255,255,255,.05);display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--accent-steel);flex-shrink:0}
.fsec-title{font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--accent-steel)}
.fsec-body{padding:18px}
/* ── Entrada com vários itens na mesma nota ── */
.bloco-nota{background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:10px;padding:15px 17px}
.bloco-tit{display:flex;align-items:center;gap:9px;font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--accent-steel);margin-bottom:13px}
.bloco-tit i{font-size:12px}
.itens-cnt{background:rgba(160,174,192,.14);border:1px solid rgba(160,174,192,.25);border-radius:20px;padding:1px 9px;font-size:10px;color:var(--accent-steel)}
.item-linha{position:relative;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:16px 17px 15px;margin-bottom:11px}
.item-linha:hover{border-color:var(--border-hover)}
.item-num{position:absolute;top:-9px;left:15px;background:var(--bg-page);border:1px solid var(--border-hover);border-radius:20px;padding:1px 11px;font-size:10px;font-weight:700;color:var(--accent-steel);letter-spacing:.04em}
.item-del{position:absolute;top:11px;right:12px;width:24px;height:24px;border-radius:6px;border:1px solid var(--border);background:none;color:var(--text-muted);cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center}
.item-del:hover{color:#f87171;border-color:rgba(248,113,113,.3);background:rgba(248,113,113,.08)}
.btn-add{background:rgba(160,174,192,.08);border:1px dashed rgba(160,174,192,.35);color:var(--accent-steel);width:100%;justify-content:center;padding:11px}
.btn-add:hover{background:rgba(160,174,192,.14);border-style:solid}
.drop-op{padding:8px 11px;cursor:pointer;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--border)}
.drop-op:last-child{border-bottom:none}
.drop-op:hover{background:rgba(255,255,255,.05);color:var(--text-primary)}
.drop-op strong{font-family:monospace;color:var(--accent-steel)}
.fg{display:grid;gap:12px}
.fg2{grid-template-columns:1fr 1fr}
.fg3{grid-template-columns:1fr 1fr 1fr}
.fg4{grid-template-columns:1fr 1fr 1fr 1fr}
.cs2{grid-column:span 2}
.cs3{grid-column:span 3}
.cs4{grid-column:span 4}
.field{display:flex;flex-direction:column;gap:5px}
.field-label{font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--text-muted)}
.field-label .req{color:var(--status-err)}
.field-label .info{font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-muted);margin-left:3px}
.fi{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:9px 12px;font-family:var(--font-ui);font-size:13px;color:var(--text-primary);outline:none;transition:border-color var(--transition),background var(--transition);width:100%;-webkit-appearance:none;appearance:none}
.fi:focus{border-color:var(--border-hover);background:#282828}
.fi::placeholder{color:var(--text-muted);font-size:12px}
.fi:read-only{opacity:.6;cursor:default}
.fi[type=number]{-moz-appearance:textfield}
select.fi{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:30px;cursor:pointer}
select.fi option{background:#1e1e1e}
select.fi:disabled{opacity:.55;cursor:not-allowed}
.badge-fixo{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:600;padding:3px 9px;border-radius:20px;background:rgba(250,204,21,.1);color:#facc15;border:1px solid rgba(250,204,21,.2);margin-top:4px}
/* Saldo badges */
.sb{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px}
.sb-ok  {background:rgba(74,222,128,.1);color:#4ade80;border:1px solid rgba(74,222,128,.2)}
.sb-low {background:rgba(250,204,21,.1);color:#facc15;border:1px solid rgba(250,204,21,.2)}
.sb-zero{background:rgba(248,113,113,.1);color:#f87171;border:1px solid rgba(248,113,113,.2)}
/* Saldo grid */
.saldo-filter-row{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.saldo-filter-row label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted)}
.saldo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px}
.sc{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:14px 16px;transition:border-color var(--transition)}
.sc:hover{border-color:var(--border-hover)}
.sc-cod{font-family:monospace;font-size:10px;font-weight:700;color:var(--accent-steel);margin-bottom:3px}
.sc-name{font-size:13px;font-weight:500;color:var(--text-primary);margin-bottom:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sc-val{font-family:var(--font-display);font-size:26px;font-weight:700;line-height:1;letter-spacing:-.02em}
.sc-un{font-size:11px;color:var(--text-muted);margin-top:4px}
/* Detalhamento por unidade dentro do card de saldo */
.sc-uni-list{margin-top:9px;padding-top:8px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:3px}
.sc-uni-row{display:flex;align-items:baseline;justify-content:space-between;gap:8px;font-size:11px}
.sc-uni-nome{color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sc-uni-qtd{font-weight:700;flex-shrink:0}
.sc-uni-vazio{font-size:11px;color:var(--text-muted);font-style:italic;margin-top:9px;padding-top:8px;border-top:1px solid var(--border)}
/* Histórico completo de transferências — rolagem interna */
.trf-scroll{max-height:420px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.trf-scroll::-webkit-scrollbar{width:6px}
.trf-scroll::-webkit-scrollbar-track{background:transparent}
.trf-scroll::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:4px}
/* Itens dentro de cada transferência */
.trf-itens{margin:5px 0 4px;display:flex;flex-direction:column;gap:2px}
.trf-item{display:flex;align-items:baseline;gap:7px;font-size:11px;min-width:0}
.trf-item-cod{font-family:monospace;font-size:10px;color:var(--accent-steel);flex-shrink:0}
.trf-item-nome{color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0}
.trf-item-qtd{color:var(--status-warn);font-weight:600;flex-shrink:0}
/* Tabela entradas */
.table-section{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:28px}
.table-section:hover{border-color:var(--border-hover)}
.table-toolbar{padding:13px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.tbl-title{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--accent-steel);flex:1;display:flex;align-items:center;gap:8px}
.tbl-cnt{background:rgba(160,174,192,.12);color:var(--accent-steel);font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px}
.tbl-filter-wrap{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.tsearch{display:flex;align-items:center;gap:8px;background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:6px 12px;transition:border-color var(--transition);min-width:180px}
.tsearch:focus-within{border-color:var(--border-hover)}
.tsearch i{font-size:12px;color:var(--text-muted);flex-shrink:0}
.tsearch input{background:none;border:none;outline:none;font-size:12px;color:var(--text-primary);font-family:var(--font-ui);width:100%}
.tsearch input::placeholder{color:var(--text-muted)}
.fsel{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:7px 26px 7px 10px;font-family:var(--font-ui);font-size:12px;color:var(--text-primary);outline:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;cursor:pointer}
.fsel:focus{border-color:var(--border-hover)}
.fsel option{background:#1e1e1e}
.fsel:disabled{opacity:.55;cursor:not-allowed}
.table-wrap{overflow-x:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
table.inv-table{width:100%;border-collapse:collapse;font-size:12px;min-width:860px}
table.inv-table thead tr{border-bottom:1px solid var(--border)}
table.inv-table th{padding:9px 11px;text-align:left;font-size:10px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--text-muted);white-space:nowrap;background:rgba(255,255,255,.02)}
table.inv-table td{padding:9px 11px;color:var(--text-secondary);border-bottom:1px solid var(--border);white-space:nowrap;max-width:160px;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}
table.inv-table tbody tr:last-child td{border-bottom:none}
table.inv-table tbody tr:hover td{background:rgba(255,255,255,.02);color:var(--text-primary)}
table.inv-table td.tdp{color:var(--text-primary);font-weight:500}
table.inv-table td.tdm{font-family:monospace;font-size:11px;color:var(--accent-steel)}
.td-edit input,.td-edit select{background:var(--bg-input);border:1px solid var(--border-hover);border-radius:5px;padding:4px 6px;font-family:var(--font-ui);font-size:12px;color:var(--text-primary);outline:none;width:100%;min-width:60px;appearance:none}
.td-edit select{padding-right:18px;cursor:pointer}
.td-edit input:focus,.td-edit select:focus{border-color:rgba(255,255,255,.28)}
.g-sim{background:rgba(74,222,128,.1);color:#4ade80;font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;border:1px solid rgba(74,222,128,.2)}
.g-nao{background:rgba(255,255,255,.05);color:var(--text-muted);font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;border:1px solid var(--border)}
.bico{width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center;border-radius:5px;border:1px solid var(--border);background:none;color:var(--text-muted);cursor:pointer;font-size:11px;transition:background var(--transition),color var(--transition),border-color var(--transition)}
.bico:hover{background:#2a2a2a;color:var(--text-primary);border-color:var(--border-hover)}
.bico.save{color:#4ade80;border-color:rgba(74,222,128,.25)}
.bico.save:hover{background:rgba(74,222,128,.1)}
.bico.del:hover{background:rgba(248,113,113,.1);color:#f87171;border-color:rgba(248,113,113,.3)}
.bico.cancel:hover{background:rgba(250,204,21,.08);color:#facc15;border-color:rgba(250,204,21,.3)}
.tbl-empty{padding:40px 20px;text-align:center;color:var(--text-muted);font-size:13px}
.tbl-empty i{font-size:28px;display:block;margin-bottom:10px;opacity:.4}
/* Almoxarifado — lista de itens do pedido */
.item-lista{display:flex;flex-direction:column;gap:8px;margin-bottom:12px}
.item-row{display:flex;align-items:center;gap:8px;background:#1e1e1e;border:1px solid var(--border);border-radius:8px;padding:10px 12px}
.item-row select,.item-row input{background:var(--bg-input);border:1px solid var(--border);border-radius:6px;padding:7px 10px;font-family:var(--font-ui);font-size:12px;color:var(--text-primary);outline:none;appearance:none;transition:border-color var(--transition)}
.item-row select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;padding-right:26px;cursor:pointer;flex:2}
.item-row select option{background:#1e1e1e}
.item-row input[type=number]{width:70px;flex:none;text-align:center}
.item-row .saldo-live{font-size:10px;min-width:75px;text-align:right;white-space:nowrap}
.item-row .rm-btn{width:24px;height:24px;border-radius:5px;border:1px solid rgba(248,113,113,.3);background:rgba(248,113,113,.08);color:#f87171;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;transition:background var(--transition)}
.item-row .rm-btn:hover{background:rgba(248,113,113,.2)}
/* Despachos lista */
.des-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border)}
.des-row:last-child{border-bottom:none}
.des-num{font-family:monospace;font-size:11px;font-weight:700;color:var(--accent-steel);min-width:90px}
.des-info{flex:1;min-width:0}
.des-title{font-size:13px;font-weight:500;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.des-sub{font-size:11px;color:var(--text-muted);margin-top:2px}
/* Toast */
#toast{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;align-items:center;gap:10px;background:#1e1e1e;border:1px solid var(--border-hover);border-radius:10px;padding:13px 18px;font-size:13px;color:var(--text-primary);box-shadow:0 8px 32px rgba(0,0,0,.5);opacity:0;transform:translateY(12px);pointer-events:none;transition:opacity .3s ease,transform .3s ease;max-width:380px}
#toast.show{opacity:1;transform:translateY(0);pointer-events:auto}
#toast.success i{color:#4ade80}
#toast.error i{color:#f87171}
.footer{background:#181818;color:#888;border-top:1px solid var(--border);padding:12px 20px;display:flex;justify-content:space-between;flex-wrap:wrap;font-size:13px;gap:6px;margin-left:var(--sidebar-w);transition:margin-left var(--transition)}
.footer div{color:#666}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeUp .35s ease both}
.delay-1{animation-delay:.05s}
.delay-2{animation-delay:.10s}
::-webkit-scrollbar{width:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.07);border-radius:4px}
::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.14)}
@media(max-width:900px){.fg3,.fg4{grid-template-columns:1fr 1fr}.cs3,.cs4{grid-column:span 2}.content{padding:16px}.footer,#main{margin-left:var(--sidebar-collapsed)}#sidebar{width:var(--sidebar-collapsed)}#sidebar.open{width:var(--sidebar-w)}}
@media(max-width:640px){#sidebar{position:fixed;top:0;left:0;height:100vh;z-index:1100;transform:translateX(-100%);transition:transform var(--transition);width:var(--sidebar-w)!important}#sidebar.open{transform:translateX(0)}.sidebar-toggle{display:none}.menu-toggle{display:block}#main{margin-left:0!important}.topbar{padding-left:54px}.fg2,.fg3,.fg4{grid-template-columns:1fr}.cs2,.cs3,.cs4{grid-column:span 1}.content{padding:12px}.footer{margin-left:0}.tbl-filter-wrap{width:100%}.fsel,.tsearch{width:100%}}
@media print{.menu-toggle,.sidebar-overlay,#sidebar,.topbar,.footer,#toast,.tabs,.no-print{display:none!important}#main{margin-left:0!important}.content{padding:0}}

/* ── COMPROVANTE (overlay em tela) ─────────────────────────────── */
.comprovante-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:2000;display:flex;align-items:center;justify-content:center;padding:20px}
.comprovante-box{background:#fff;color:#000;border-radius:12px;width:100%;max-width:700px;max-height:90vh;overflow-y:auto;font-family:'DM Sans',sans-serif}
.comp-header{padding:20px 28px 14px;border-bottom:2px solid #111;display:flex;align-items:center;gap:20px}
.comp-logos{display:flex;align-items:center;gap:14px;flex-shrink:0}
.comp-logos img{height:44px;width:auto;object-fit:contain}
.comp-title-block{flex:1;text-align:center}
.comp-titulo{font-size:16px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#000;line-height:1.2}
.comp-num{font-size:11px;color:#555;margin-top:3px;letter-spacing:.04em}
.comp-body{padding:18px 28px 20px}
.comp-section{margin-bottom:14px}
.comp-section-title{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#555;border-bottom:1px solid #ddd;padding-bottom:3px;margin-bottom:8px}
.comp-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px}
.comp-field label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;display:block;margin-bottom:1px}
.comp-field span{font-size:13px;color:#000;font-weight:600}
.comp-field.full{grid-column:1/-1}
.comp-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:6px}
.comp-table th{padding:8px 12px;text-align:left;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:#f0f0f0;color:#444;border:1px solid #ccc}
.comp-table td{padding:9px 12px;border:1px solid #ccc;color:#111;font-size:13px}
.comp-assinaturas{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-top:28px}
.assin-box{border-top:1.5px solid #000;padding-top:8px}
.assin-box p{font-size:11px;color:#555;margin-top:3px}
.assin-box p:first-child{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#333;margin-top:0}
.comp-footer-txt{font-size:9.5px;color:#888;text-align:center;margin-top:18px;padding-top:10px;border-top:1px solid #eee}
.comp-actions{padding:12px 28px;border-top:1px solid #eee;display:flex;gap:10px;justify-content:flex-end;background:#fff}

/* ── IMPRESSAO: apenas o comprovante, A4 ───────────────────────── */
@media print {
  @page { size: A4 portrait; margin: 12mm 14mm; }
  body > *:not(.comprovante-overlay) { display: none !important; }
  .comprovante-overlay {
    position: static !important;
    background: none !important;
    padding: 0 !important;
    display: block !important;
    z-index: auto !important;
  }
  .comprovante-box {
    border-radius: 0 !important;
    box-shadow: none !important;
    max-height: none !important;
    max-width: 100% !important;
    width: 100% !important;
    overflow: visible !important;
  }
  .comp-header   { padding: 0 0 12pt 0; border-bottom: 2pt solid #000; }
  .comp-logos img{ height: 52pt; }
  .comp-titulo   { font-size: 17pt; }
  .comp-num      { font-size: 10pt; }
  .comp-body     { padding: 14pt 0 0 0; }
  .comp-section  { margin-bottom: 12pt; }
  .comp-section-title { font-size: 9pt; margin-bottom: 6pt; }
  .comp-field label   { font-size: 8pt; }
  .comp-field span    { font-size: 13pt; }
  .comp-table    { font-size: 12pt; }
  .comp-table th { padding: 7pt 10pt; font-size: 9pt; }
  .comp-table td { padding: 9pt 10pt; }
  .comp-assinaturas   { margin-top: 30pt; gap: 32pt; }
  .assin-box p   { font-size: 10pt; }
  .comp-footer-txt    { font-size: 8.5pt; margin-top: 16pt; }
  .comp-actions  { display: none !important; }
  .comp-table tbody tr { page-break-inside: avoid; }
}
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
      <span>Estoque / Inventário</span>
    </div>
    <div class="topbar-spacer"></div>
    <img src="logo_rede.png" alt="Rede Hospitalar" class="topbar-logo-rede">
      <?php eng_clin_menu_botoes(); ?>
  </header>

  <div class="content">

    <div class="page-header fade-up">
      <div>
        <div class="page-title">Estoque / Inventário</div>
        <div class="page-subtitle">Engenharia Clínica &middot; <?= eng_clin_data_pt() ?>
          <?php if ($fixar_unidade): ?>&middot; <span style="color:var(--status-warn)"><?= htmlspecialchars($unidade_pre) ?></span><?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert <?= $msg_tipo === 'ok' ? 'alert-ok' : 'alert-erro' ?> fade-up">
      <i class="fas <?= $msg_tipo === 'ok' ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <!-- TABS -->
    <div class="tabs fade-up">
      <button class="tab-btn active" id="tabEntrada"   onclick="mudarTab('Entrada')"><i class="fas fa-plus-circle"></i> Nova Entrada</button>
      <button class="tab-btn"        id="tabSaldo"     onclick="mudarTab('Saldo')"><i class="fas fa-chart-bar"></i> Saldo por Item</button>
      <button class="tab-btn"        id="tabEntradas"  onclick="mudarTab('Entradas')"><i class="fas fa-list"></i> Entradas por Nota</button>
      <button class="tab-btn"        id="tabAlmox"     onclick="mudarTab('Almox')"><i class="fas fa-truck-ramp-box"></i> Almoxarifado</button>
      <button class="tab-btn"        id="tabTransf"    onclick="mudarTab('Transf')"><i class="fas fa-right-left"></i> Transferência de Materiais</button>
    </div>

    <!-- ══ ABA: NOVA ENTRADA ══════════════════════════════════════════ -->
    <div id="abaEntrada" class="fade-up delay-1">
      <div class="form-section">
        <div class="fsec-header"><div class="fsec-icon"><i class="fas fa-box-open"></i></div><span class="fsec-title">Registrar Entrada de Material</span></div>
        <div class="fsec-body">
          <form method="POST" id="formEntrada">
            <input type="hidden" name="action" value="entrada">

            <!-- ══ BLOCO 1 — DADOS DA NOTA (valem para todos os itens) ══ -->
            <div class="bloco-nota">
              <div class="bloco-tit"><i class="fas fa-file-invoice"></i> Dados da nota</div>
              <div class="fg fg4">
                <div class="field cs2">
                  <label class="field-label">Unidade <span class="req">*</span><?php if($fixar_unidade):?><span class="info">— vinculada</span><?php endif;?></label>
                  <select name="unidade" class="fi" id="selectUnidade" <?= $fixar_unidade?'disabled':'' ?> required>
                    <option value="">Selecione...</option>
                    <?php foreach($todas_unidades as $u): ?>
                    <option value="<?=htmlspecialchars($u)?>"<?=($fixar_unidade&&$u===$unidade_pre)?' selected':''?>><?=htmlspecialchars($u)?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php if($fixar_unidade): ?>
                    <input type="hidden" name="unidade" value="<?=htmlspecialchars($unidade_pre)?>">
                    <span class="badge-fixo"><i class="fas fa-lock"></i> <?=htmlspecialchars($unidade_pre)?></span>
                  <?php endif; ?>
                </div>
                <div class="field">
                  <label class="field-label">Nº Nota</label>
                  <input type="text" name="numero_nota" class="fi" placeholder="NF">
                </div>
                <div class="field">
                  <label class="field-label">Garantia?</label>
                  <select name="tem_garantia" class="fi" id="selGarantia">
                    <option value="NAO">NÃO</option><option value="SIM">SIM</option>
                  </select>
                </div>
              </div>
              <div class="fg fg4" id="wrapDataGarantia" style="display:none;margin-top:12px">
                <div class="field">
                  <label class="field-label">Validade da garantia</label>
                  <input type="date" name="data_garantia" class="fi">
                </div>
              </div>
            </div>

            <!-- ══ BLOCO 2 — ITENS DA NOTA (repetível) ══ -->
            <div class="bloco-tit" style="margin-top:20px">
              <i class="fas fa-boxes-stacked"></i> Itens da nota
              <span class="itens-cnt" id="itensCnt">1</span>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin:0 0 12px">
              Cada item entra como um lote próprio, com a mesma unidade, nota e garantia acima.
            </div>

            <div id="listaItens"></div>

            <button type="button" class="btn btn-add" onclick="addItemEntrada()">
              <i class="fas fa-plus"></i> Adicionar outro item
            </button>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
              <button type="reset" class="btn btn-primary" onclick="resetEntrada()"><i class="fas fa-rotate-left"></i> Limpar</button>
              <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Registrar Entrada</button>
            </div>
          </form>

          <!-- Modelo de linha de item, clonado pelo JS -->
          <template id="tplItem">
            <div class="item-linha">
              <div class="item-num"></div>
              <button type="button" class="item-del" title="Remover este item" onclick="delItemEntrada(this)">
                <i class="fas fa-xmark"></i>
              </button>
              <div class="fg fg4">
                <div class="field">
                  <label class="field-label">Código <span class="info">(busca)</span></label>
                  <div style="position:relative">
                    <input type="text" class="fi in-codigo"
                           style="font-family:monospace;letter-spacing:.06em;text-transform:uppercase"
                           placeholder="00001" maxlength="10" autocomplete="off">
                    <div class="drop-cod" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;background:#1e1e1e;border:1px solid var(--border-hover);border-radius:0 0 8px 8px;max-height:200px;overflow-y:auto;scrollbar-width:thin"></div>
                  </div>
                </div>
                <div class="field cs3">
                  <label class="field-label">Item do Catálogo <span class="req">*</span></label>
                  <input type="text" class="fi in-busca" style="margin-bottom:6px;text-transform:uppercase"
                         placeholder="Digite para filtrar..." autocomplete="off">
                  <select name="codigo_peca[]" class="fi in-catalogo" required>
                    <option value="">— selecione —</option>
                    <?php foreach($catalogo as $c): ?>
                    <option value="<?=htmlspecialchars($c['codigo'])?>"
                            data-nome="<?=htmlspecialchars($c['descricao'])?>">
                      <?=htmlspecialchars($c['codigo'])?> — <?=htmlspecialchars($c['descricao'])?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                  <input type="hidden" name="nome[]" class="in-nome">
                  <div class="saldo-wrap" style="display:none;margin-top:5px;font-size:11px;color:var(--text-muted)">
                    Saldo atual: <span class="sb sb-ok saldo-badge"></span>
                  </div>
                </div>
              </div>
              <div class="fg fg4" style="margin-top:12px">
                <div class="field"><label class="field-label">Marca</label>
                  <input type="text" name="marca[]" class="fi" placeholder="Fabricante"></div>
                <div class="field"><label class="field-label">Modelo</label>
                  <input type="text" name="modelo[]" class="fi" placeholder="Modelo do item"></div>
                <div class="field"><label class="field-label">Quantidade <span class="req">*</span></label>
                  <input type="number" name="quantidade[]" class="fi" value="1" min="1" required></div>
                <div class="field"><label class="field-label">Valor Unit. (R$)</label>
                  <input type="text" name="valor[]" class="fi in-valor" placeholder="0,00"></div>
              </div>
            </div>
          </template>
        </div>
      </div>
      <?php if(empty($catalogo)): ?>
      <div style="background:rgba(250,204,21,.06);border:1px solid rgba(250,204,21,.2);border-radius:10px;padding:14px 18px;font-size:13px;color:#facc15;display:flex;align-items:center;gap:10px">
        <i class="fas fa-triangle-exclamation"></i>
        Catálogo vazio. <a href="eng_clin_cadastro_pecas.php" style="color:#facc15;font-weight:600;margin-left:4px">Cadastrar peças →</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ ABA: SALDO POR ITEM ════════════════════════════════════════ -->
    <div id="abaSaldo" style="display:none" class="fade-up delay-1">
      <div class="saldo-filter-row">
        <label>Visualizar por:</label>
        <select class="fi" id="filtroSaldoUnidade" onchange="renderSaldo()" style="max-width:280px">
          <option value="">Todas as unidades (total geral)</option>
          <?php foreach($todas_unidades as $u): ?>
          <option value="<?=htmlspecialchars($u)?>"><?=htmlspecialchars($u)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="saldo-grid" id="saldoGrid"><!-- renderizado por JS --></div>
    </div>

    <!-- ══ ABA: ENTRADAS POR NOTA ═════════════════════════════════════ -->
    <div id="abaEntradas" style="display:none" class="fade-up delay-1">
      <div class="table-section">
        <div class="table-toolbar">
          <div class="tbl-title"><i class="fas fa-receipt" style="font-size:12px"></i>Entradas<span class="tbl-cnt" id="invCount"><?=count($inventario)?></span>
            <span style="font-weight:400;font-size:10px;color:var(--text-muted);margin-left:8px;text-transform:none;letter-spacing:0">
              registro fixo por nota fiscal &middot; não muda com o uso
            </span>
          </div>
          <div class="tbl-filter-wrap">
            <select class="fsel" id="filtroUnidade" <?=$fixar_unidade?'disabled':''?>>
              <?php if(!$fixar_unidade): ?>
              <option value="">Todas as unidades</option>
              <?php foreach($todas_unidades as $u): ?>
              <option value="<?=htmlspecialchars($u)?>"><?=htmlspecialchars($u)?></option>
              <?php endforeach; ?>
              <?php else: ?>
              <option value="<?=htmlspecialchars($unidade_pre)?>"><?=htmlspecialchars($unidade_pre)?></option>
              <?php endif; ?>
            </select>
            <div class="tsearch"><i class="fas fa-search"></i><input type="text" id="buscaInv" placeholder="Buscar código, item, nota..."></div>
          </div>
        </div>
        <div class="table-wrap">
          <table class="inv-table" id="tblInventario">
            <thead>
              <tr>
                <th>Código</th><th>Cód. Peça</th><th>Item</th><th>Unidade</th>
                <th>Nº Nota</th><th title="Quantidade que entrou nesta nota — registro fixo">Qtd Entrada</th>
                <th>Garantia</th><th>Val. Garantia</th><th>Valor Unit.</th>
                <th>Cadastrado</th><th style="width:66px;text-align:center">Ações</th>
              </tr>
            </thead>
            <tbody id="tblBody">
            <?php if(empty($inventario)): ?>
              <tr><td colspan="11" class="tbl-empty"><i class="fas fa-boxes-stacked"></i>Nenhuma entrada por nota ainda.</td></tr>
            <?php else: ?>
              <?php foreach($inventario as $item):
                $dtGar  = $item['data_garantia'] ? date('d/m/Y',strtotime($item['data_garantia'])) : '—';
                $dtGarV = $item['data_garantia'] ?? '';
                $dtCad  = $item['data_cadastro'] ? date('d/m/Y',strtotime($item['data_cadastro'])) : '—';
                $val    = $item['valor'] !== null ? 'R$ '.number_format($item['valor'],2,',','.') : '—';
                $cp     = $item['codigo_peca'] ?? '';
                // Registro FIXO da nota — não muda com o uso dos itens
                $qtd_ent = $item['quantidade_inicial'] ?? $item['quantidade'];
              ?>
              <tr data-id="<?=$item['id']?>" data-unidade="<?=htmlspecialchars($item['unidade'])?>">
                <td class="view-mode tdm"><?=htmlspecialchars($item['codigo'])?></td>
                <td class="view-mode tdm" style="color:#a0aec0"><?=htmlspecialchars($cp?:'—')?></td>
                <td class="view-mode tdp"><?=htmlspecialchars($item['nome'])?></td>
                <td class="view-mode" style="font-size:11px"><?=htmlspecialchars($item['unidade'])?></td>
                <td class="view-mode"><?=htmlspecialchars($item['numero_nota']?:'—')?></td>
                <td class="view-mode"><strong><?=fmt_qty($qtd_ent)?></strong> <span style="font-size:10px;color:var(--text-muted)">un.</span></td>
                <td class="view-mode"><span class="<?=$item['tem_garantia']==='SIM'?'g-sim':'g-nao'?>"><?=$item['tem_garantia']?></span></td>
                <td class="view-mode"><?=$dtGar?></td>
                <td class="view-mode"><?=$val?></td>
                <td class="view-mode" style="font-size:11px;color:var(--text-muted)"><?=$dtCad?></td>

                <!-- edit -->
                <td class="edit-mode" colspan="4" style="display:none"><span class="tdm"><?=htmlspecialchars($item['codigo'])?></span> &middot; <?=htmlspecialchars($item['nome'])?></td>
                <td class="td-edit edit-mode" style="display:none"><input class="edit-nota" type="text" value="<?=htmlspecialchars($item['numero_nota']??'')?>" style="width:80px"></td>
                <td class="td-edit edit-mode" style="display:none"><input class="edit-qty" type="number" value="<?=(int)$qtd_ent?>" min="0" style="width:56px" title="Corrigir a quantidade da nota ajusta o saldo pela diferença"></td>
                <td class="td-edit edit-mode" style="display:none"><select class="edit-garantia" style="width:60px"><option value="NAO" <?=$item['tem_garantia']==='NAO'?'selected':''?>>NÃO</option><option value="SIM" <?=$item['tem_garantia']==='SIM'?'selected':''?>>SIM</option></select></td>
                <td class="td-edit edit-mode" style="display:none"><input class="edit-dtgar" type="date" value="<?=htmlspecialchars($dtGarV)?>" style="width:118px"></td>
                <td class="td-edit edit-mode" style="display:none"><input class="edit-valor" type="text" value="<?=$item['valor']!==null?number_format($item['valor'],2,',','.'):'';?>" style="width:78px" placeholder="0,00"></td>
                <td class="edit-mode" style="display:none">—</td>

                <td style="text-align:center">
                  <span class="view-mode" style="display:inline-flex;gap:3px">
                    <button class="bico" title="Editar" onclick="modoEditar(this)"><i class="fas fa-pen"></i></button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Remover esta entrada?')">
                      <input type="hidden" name="action" value="excluir"><input type="hidden" name="id" value="<?=$item['id']?>">
                      <button type="submit" class="bico del" title="Remover"><i class="fas fa-trash"></i></button>
                    </form>
                  </span>
                  <span class="edit-mode" style="display:none;gap:3px">
                    <button class="bico save" onclick="salvarEdicao(this)"><i class="fas fa-check"></i></button>
                    <button class="bico cancel" onclick="cancelarEdicao(this)"><i class="fas fa-xmark"></i></button>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══ ABA: ALMOXARIFADO ══════════════════════════════════════════ -->
    <div id="abaAlmox" style="display:none" class="fade-up delay-1">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

        <!-- Formulário de despacho -->
        <div>
          <div class="form-section">
            <div class="fsec-header"><div class="fsec-icon" style="background:rgba(250,204,21,.1)"><i class="fas fa-truck-ramp-box" style="color:#facc15"></i></div><span class="fsec-title">Novo Despacho</span></div>
            <div class="fsec-body">
              <form method="POST" id="formDespacho">
                <input type="hidden" name="action" value="despacho">

                <div class="fg fg2" style="margin-bottom:12px">
                  <div class="field cs2">
                    <label class="field-label">Unidade de Estoque (origem) <span class="req">*</span></label>
                    <select name="unidade_estoque" class="fi" id="selUniEstoque" required onchange="atualizarSaldosAlmox()">
                      <option value="">Selecione o estoque...</option>
                      <?php foreach($todas_unidades as $u): ?>
                      <option value="<?=htmlspecialchars($u)?>"><?=htmlspecialchars($u)?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <!-- Itens solicitados -->
                <div style="margin-bottom:12px">
                  <label class="field-label" style="display:block;margin-bottom:8px">
                    <i class="fas fa-boxes-stacked" style="margin-right:4px"></i>Itens Solicitados <span class="req">*</span>
                  </label>
                  <div class="item-lista" id="itemLista">
                    <!-- linha adicionada por JS -->
                  </div>
                  <button type="button" class="btn btn-primary" style="font-size:11px" onclick="adicionarItem()">
                    <i class="fas fa-plus"></i> Adicionar Item
                  </button>
                </div>

                <div class="fg fg2" style="margin-bottom:12px">
                  <div class="field">
                    <label class="field-label">Unidade de Destino <span class="req">*</span></label>
                    <select name="unidade_destino" class="fi" required>
                      <option value="">Selecione...</option>
                      <?php foreach($todas_unidades as $u): ?>
                      <option value="<?=htmlspecialchars($u)?>"><?=htmlspecialchars($u)?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label class="field-label">Setor</label>
                    <input type="text" name="setor_destino" class="fi" placeholder="Ex: CTI, Centro Cirúrgico...">
                  </div>
                </div>

                <div class="fg fg2" style="margin-bottom:12px">
                  <div class="field">
                    <label class="field-label">Local / Área</label>
                    <input type="text" name="local_destino" class="fi" placeholder="Ex: Leito 04, Sala 2...">
                  </div>
                  <div class="field">
                    <label class="field-label">Nome do Solicitante <span class="req">*</span></label>
                    <input type="text" name="nome_solicitante" class="fi" placeholder="Nome completo" style="text-transform:uppercase" required>
                  </div>
                </div>

                <div class="field" style="margin-bottom:18px">
                  <label class="field-label">Observação</label>
                  <input type="text" name="observacao" class="fi" placeholder="Opcional...">
                </div>

                <div style="display:flex;justify-content:flex-end">
                  <button type="submit" class="btn btn-warn" onclick="return prepararItens()">
                    <i class="fas fa-paper-plane"></i> Registrar Despacho
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Despachos recentes -->
        <div>
          <div class="form-section">
            <div class="fsec-header"><div class="fsec-icon"><i class="fas fa-clock-rotate-left"></i></div><span class="fsec-title">Despachos Recentes</span></div>
            <div class="fsec-body" style="padding:0">
              <?php if(empty($despachos_rec)): ?>
              <div class="tbl-empty"><i class="fas fa-inbox"></i>Nenhum despacho registrado.</div>
              <?php else: foreach($despachos_rec as $dr): ?>
              <div class="des-row" style="padding:10px 18px">
                <div class="des-num"><?=htmlspecialchars($dr['numero_despacho'])?></div>
                <div class="des-info">
                  <div class="des-title"><?=htmlspecialchars($dr['nome_solicitante'])?> &rarr; <?=htmlspecialchars($dr['unidade_destino'])?></div>
                  <div class="des-sub"><?=htmlspecialchars($dr['unidade_estoque'])?> &middot; <?=date('d/m/Y',strtotime($dr['data_despacho']))?> <?=substr($dr['hora_despacho'],0,5)?></div>
                </div>
                <a href="?comprovante=<?=urlencode($dr['numero_despacho'])?>" class="btn btn-primary" style="font-size:11px;padding:6px 12px">
                  <i class="fas fa-print"></i> Reimprimir
                </a>
              </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ ABA: TRANSFERÊNCIA DE MATERIAIS ══════════════════════════════ -->
    <div id="abaTransf" style="display:none" class="fade-up delay-1">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

        <!-- Formulário de transferência -->
        <div>
          <div class="form-section">
            <div class="fsec-header">
              <div class="fsec-icon" style="background:rgba(74,222,128,.1)"><i class="fas fa-right-left" style="color:#4ade80"></i></div>
              <span class="fsec-title">Nova Transferência de Materiais</span>
            </div>
            <div class="fsec-body">
              <form method="POST" id="formTransf">
                <input type="hidden" name="action" value="transferencia">

                <div class="fg fg2" style="margin-bottom:12px">
                  <div class="field">
                    <label class="field-label">Unidade de Origem <span class="req">*</span></label>
                    <select name="unidade_origem" class="fi" id="selUniOrigem" required onchange="atualizarSaldosTransf()">
                      <option value="">Selecione a origem...</option>
                      <?php foreach($todas_unidades as $u): ?>
                      <option value="<?=htmlspecialchars($u)?>"><?=htmlspecialchars($u)?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label class="field-label">Unidade de Destino <span class="req">*</span></label>
                    <select name="unidade_destino" class="fi" id="selUniDestinoT" required>
                      <option value="">Selecione o destino...</option>
                      <?php foreach($todas_unidades as $u): ?>
                      <option value="<?=htmlspecialchars($u)?>"><?=htmlspecialchars($u)?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <!-- Itens a transferir -->
                <div style="margin-bottom:14px">
                  <label class="field-label" style="display:block;margin-bottom:8px">
                    <i class="fas fa-boxes-stacked" style="margin-right:4px"></i>
                    Itens a Transferir <span class="req">*</span>
                  </label>
                  <div class="item-lista" id="itemListaTransf">
                    <!-- linhas adicionadas por JS -->
                  </div>
                  <button type="button" class="btn btn-primary" style="font-size:11px" onclick="adicionarItemTransf()">
                    <i class="fas fa-plus"></i> Adicionar Item
                  </button>
                </div>

                <!-- Resumo dos saldos da origem -->
                <div id="saldoOrigemPanel" style="display:none;margin-bottom:14px;padding:12px;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:8px">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:8px">
                    <i class="fas fa-warehouse" style="margin-right:4px"></i>Saldo disponível na origem
                  </div>
                  <div id="saldoOrigemLista" style="font-size:12px;color:var(--text-secondary);line-height:1.8"></div>
                </div>

                <div class="field" style="margin-bottom:18px">
                  <label class="field-label">Observação</label>
                  <input type="text" name="observacao" class="fi" placeholder="Motivo ou referência da transferência...">
                </div>

                <div style="display:flex;justify-content:flex-end">
                  <button type="submit" class="btn" style="background:rgba(74,222,128,.12);color:#4ade80;border:1px solid rgba(74,222,128,.3);font-size:13px" onclick="return prepararItensTransf()">
                    <i class="fas fa-right-left"></i> Registrar Transferência
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Transferências recentes -->
        <div>
          <div class="form-section">
            <div class="fsec-header">
              <div class="fsec-icon"><i class="fas fa-clock-rotate-left"></i></div>
              <span class="fsec-title">Histórico de Transferências</span>
              <?php if(!empty($transferencias_rec)): ?>
              <span class="tbl-cnt" style="margin-left:auto"><?= count($transferencias_rec) ?></span>
              <?php endif; ?>
            </div>
            <?php if(count($transferencias_rec) > 1): ?>
            <div style="padding:8px 18px 0">
              <input type="text" id="buscaTrf" class="fi" style="font-size:12px;padding:7px 10px"
                     placeholder="Filtrar por TRF, unidade ou usuário...">
            </div>
            <?php endif; ?>
            <div class="fsec-body trf-scroll" style="padding:0">
              <?php if(empty($transferencias_rec)): ?>
              <div class="tbl-empty"><i class="fas fa-inbox"></i>Nenhuma transferência registrada.</div>
              <?php else: foreach($transferencias_rec as $tr): ?>
              <?php
                // Extrair número TRF e rota da observação
                preg_match('/^(TRF-\d+)/', $tr['observacao']??'', $mTrf);
                $num_trf_d = $mTrf[1] ?? '—';
                preg_match('/Saída de (.+?) → (.+?)(?:\s*\||\s*$)/', $tr['observacao']??'', $mRota);
                $orig_d = $mRota[1] ?? '—';
                $dest_d = $mRota[2] ?? '—';
                $usr_d  = $tr['usuario'] ?? '';
                $dt_d   = !empty($tr['data_mov']) ? date('d/m/Y H:i', strtotime($tr['data_mov'])) : '';
                $itens_d = (int)($tr['qtd_itens'] ?? 0);

                // Itens da transferência (código \t nome \t quantidade, um por linha)
                $itens_list = [];
                foreach (explode("\n", (string)($tr['itens_raw'] ?? '')) as $ln) {
                    if (trim($ln) === '') continue;
                    $p = explode("\t", $ln);
                    $itens_list[] = [
                        'cod'  => $p[0] ?? '',
                        'nome' => $p[1] ?? '',
                        'qtd'  => $p[2] ?? '',
                    ];
                }
                $busca_d = mb_strtolower(
                    $num_trf_d.' '.$orig_d.' '.$dest_d.' '.$usr_d.' '.
                    implode(' ', array_map(fn($i) => $i['cod'].' '.$i['nome'], $itens_list))
                );
              ?>
              <div class="des-row trf-row" style="padding:10px 18px" data-busca="<?= htmlspecialchars($busca_d) ?>">
                <div class="des-num" style="color:#4ade80;font-size:11px;font-weight:700;min-width:80px"><?= htmlspecialchars($num_trf_d) ?></div>
                <div class="des-info" style="flex:1;min-width:0">
                  <div class="des-title" style="font-size:12px"><?= htmlspecialchars($orig_d) ?> &rarr; <?= htmlspecialchars($dest_d) ?></div>
                  <?php if($itens_list): ?>
                  <div class="trf-itens">
                    <?php foreach($itens_list as $it): ?>
                    <div class="trf-item">
                      <span class="trf-item-cod"><?= htmlspecialchars($it['cod']) ?></span>
                      <span class="trf-item-nome" title="<?= htmlspecialchars($it['nome']) ?>"><?= htmlspecialchars($it['nome']) ?></span>
                      <?php if($itens_d > 1): ?>
                      <span class="trf-item-qtd"><?= fmt_qty($it['qtd']) ?> un.</span>
                      <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
                  <div class="des-sub">
                    <?= htmlspecialchars($usr_d) ?>
                    <?php if($dt_d): ?>&middot; <?= $dt_d ?><?php endif; ?>
                    <?php if($itens_d > 1): ?>&middot; <?= $itens_d ?> itens<?php endif; ?>
                  </div>
                </div>
                <div style="font-size:11px;color:var(--status-warn);font-weight:600;white-space:nowrap"><?= fmt_qty($tr['total_qty']??0) ?> un.</div>
              </div>
              <?php endforeach; ?>
              <div class="tbl-empty" id="trfVazio" style="display:none"><i class="fas fa-magnifying-glass"></i>Nenhuma transferência encontrada.</div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Card explicativo -->
          <div class="form-section" style="margin-top:16px">
            <div class="fsec-body" style="padding:14px 16px">
              <div style="display:flex;align-items:flex-start;gap:12px">
                <div style="font-size:18px;color:#4ade80;flex-shrink:0"><i class="fas fa-circle-info"></i></div>
                <div>
                  <div style="font-size:12px;font-weight:600;color:var(--text-primary);margin-bottom:6px">Como funciona</div>
                  <div style="font-size:12px;color:var(--text-secondary);line-height:1.7">
                    A transferência debita o saldo da <strong style="color:var(--text-primary)">unidade de origem</strong> e credita automaticamente na <strong style="color:var(--text-primary)">unidade de destino</strong>.<br>
                    Se a unidade destino ainda não tiver aquele item, um novo lote é criado. O saldo total do sistema não muda, apenas a distribuição entre unidades.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
    <!-- ══ fim aba Transferência ══════════════════════════════════════════ -->

  </div><!-- /content -->
</div><!-- /main -->

<div class="footer" id="pageFooter">
  <div>Usuário: <?=htmlspecialchars($usuario)?> &middot; Nível: <?=htmlspecialchars($nivel)?></div>
  <div>Data: <?=$data?> | Hora: <span id="hora"><?=$hora?></span></div>
  <div>&copy; GK Soluções</div>
</div>

<div id="toast"><i class="fas fa-circle-check" id="toastIcon"></i> <span id="toastMsg"></span></div>

<!-- ══ COMPROVANTE OVERLAY ══ -->
<?php if($comprovante): ?>
<div class="comprovante-overlay" id="comprovanteOverlay">
  <div class="comprovante-box" id="comprovanteBox">
    <div class="comp-header">
      <div class="comp-logos">
        <img src="lifetechpreto.png" alt="LifeTech">
        <img src="logo_rede_escuro.png" alt="Rede Hospitalar">
      </div>
      <div class="comp-title-block">
        <div class="comp-titulo">Comprovante de Recebimento de Componentes</div>
        <div class="comp-num"><?=htmlspecialchars($comprovante['numero_despacho'])?></div>
      </div>
    </div>
    <div class="comp-body">

      <div class="comp-section">
        <div class="comp-section-title">Origem / Centro de Custo</div>
        <div class="comp-grid">
          <div class="comp-field full">
            <label>Centro de Custo (Estoque)</label>
            <span><?=htmlspecialchars($comprovante['unidade_estoque'])?></span>
          </div>
          <div class="comp-field">
            <label>Data</label>
            <span><?=date('d/m/Y',strtotime($comprovante['data_despacho']))?></span>
          </div>
          <div class="comp-field">
            <label>Hora</label>
            <span><?=substr($comprovante['hora_despacho'],0,5)?></span>
          </div>
        </div>
      </div>

      <div class="comp-section">
        <div class="comp-section-title">Destino</div>
        <div class="comp-grid">
          <div class="comp-field"><label>Unidade</label><span><?=htmlspecialchars($comprovante['unidade_destino'])?></span></div>
          <div class="comp-field"><label>Setor</label><span><?=htmlspecialchars($comprovante['setor_destino']?:'—')?></span></div>
          <div class="comp-field"><label>Local / Área</label><span><?=htmlspecialchars($comprovante['local_destino']?:'—')?></span></div>
          <div class="comp-field"><label>Solicitante</label><span><?=htmlspecialchars($comprovante['nome_solicitante'])?></span></div>
        </div>
      </div>

      <div class="comp-section">
        <div class="comp-section-title">Itens Retirados</div>
        <table class="comp-table">
          <thead>
            <tr><th>Cód.</th><th>Descrição do Item</th><th style="text-align:right">Qtd.</th></tr>
          </thead>
          <tbody>
            <?php foreach($comprovante['itens'] as $it): ?>
            <tr>
              <td style="font-family:monospace;font-size:11px"><?=htmlspecialchars($it['codigo_peca'])?></td>
              <td><?=htmlspecialchars($it['descricao'])?></td>
              <td style="text-align:right;font-weight:700"><?=intval($it['quantidade'])?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if($comprovante['observacao']): ?>
        <div style="margin-top:8px;font-size:12px;color:#555"><strong>Obs:</strong> <?=htmlspecialchars($comprovante['observacao'])?></div>
        <?php endif; ?>
      </div>

      <div class="comp-assinaturas">
        <div class="assin-box">
          <p>Assinatura do Solicitante</p>
          <p style="margin-top:4px;font-size:11px;color:#777"><?=htmlspecialchars($comprovante['nome_solicitante'])?></p>
        </div>
        <div class="assin-box">
          <p>Assinatura do Técnico Responsável</p>
          <p style="margin-top:4px;font-size:11px;color:#777"><?=htmlspecialchars($comprovante['usuario_logado'])?></p>
        </div>
      </div>

      <div class="comp-footer-txt">
        Documento gerado pelo sistema &middot; <strong>LifeTech / GK Soluções</strong> &middot; <?=htmlspecialchars($comprovante['numero_despacho'])?>
      </div>
    </div>
    <div class="comp-actions no-print">
      <button class="btn btn-primary" onclick="document.getElementById('comprovanteOverlay').style.display='none'">
        <i class="fas fa-xmark"></i> Fechar
      </button>
      <button class="btn btn-success" onclick="window.print()">
        <i class="fas fa-print"></i> Imprimir
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
/* ── Escape de HTML ──────────────────────────────────────────────────────────
   Converte caracteres que o navegador interpretaria como marcação em entidades.
   Sem isso, um valor gravado no banco contendo uma tag é EXECUTADO ao ser
   inserido com innerHTML — e o código roda na sessão de quem abriu a tela.
   Escapar um texto comum não altera nada; o efeito só aparece no que era
   marcação disfarçada de dado. */
function esc(v) {
  if (v === null || v === undefined) return '';
  return String(v)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// ── Dados PHP → JS ────────────────────────────────────────────────────────────
const saldosJS      = <?= json_encode($saldos) ?>;       // [cp][unidade] = qty, [cp]['_total'] = sum
const catalogoMap   = <?= json_encode($catalogo_map) ?>; // [cp] = descricao
const todasUnidades = <?= json_encode($todas_unidades) ?>;

// ── Relógio ───────────────────────────────────────────────────────────────────
setInterval(()=>{ document.getElementById('hora').innerText=new Date().toLocaleTimeString('pt-BR'); },1000);

// ── Sidebar ───────────────────────────────────────────────────────────────────
const sidebar=document.getElementById('sidebar'),mainArea=document.getElementById('main'),footer=document.getElementById('pageFooter'),toggleBtn=document.getElementById('toggleBtn'),toggleIcon=document.getElementById('toggleIcon');
function syncFooter(c){if(footer)footer.style.marginLeft=c?'var(--sidebar-collapsed)':'var(--sidebar-w)';}
if(toggleBtn)toggleBtn.addEventListener('click',()=>{const c=sidebar.classList.toggle('collapsed');mainArea.classList.toggle('sidebar-collapsed',c);toggleIcon.classList.toggle('fa-chevron-left',!c);toggleIcon.classList.toggle('fa-chevron-right',c);syncFooter(c);});
document.getElementById('menuToggle').onclick=()=>{sidebar.classList.add('open');document.getElementById('sidebarOverlay').classList.add('open');};
function fecharSidebar(){sidebar.classList.remove('open');document.getElementById('sidebarOverlay').classList.remove('open');}
sidebar.querySelectorAll('.nav-item').forEach(i=>i.addEventListener('click',()=>{if(window.innerWidth<=640)fecharSidebar();}));

// ── Tabs ──────────────────────────────────────────────────────────────────────
function mudarTab(nome) {
  ['Entrada','Saldo','Entradas','Almox','Transf'].forEach(t=>{
    document.getElementById('tab'+t).classList.remove('active');
    document.getElementById('aba'+t).style.display='none';
  });
  document.getElementById('tab'+nome).classList.add('active');
  document.getElementById('aba'+nome).style.display='';
  if(nome==='Saldo') renderSaldo();
  if(nome==='Transf' && !transfInitializado) { adicionarItemTransf(); }
}

// A busca bidirecional Código ↔ Nome passou a ser por linha, em ligarLinha().
// O bloco global antigo foi removido: ele operava por ID único e quebraria
// com várias linhas de item na mesma nota.

// Garantia toggle
document.getElementById('selGarantia').addEventListener('change',function(){
  document.getElementById('wrapDataGarantia').style.display=this.value==='SIM'?'flex':'none';
});
function maskValor(el){let v=el.value.replace(/\D/g,'');if(!v){el.value='';return;}v=(parseInt(v,10)/100).toFixed(2);el.value=v.replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.');}
document.querySelectorAll('.edit-valor').forEach(el=>el.addEventListener('input',function(){maskValor(this);}));

/* ══════════════════════════════════════════════════════════════════════
   ITENS DA NOTA — uma linha por produto, todos enviados de uma vez
   ══════════════════════════════════════════════════════════════════════ */
function renumerarItens() {
  const linhas = document.querySelectorAll('#listaItens .item-linha');
  linhas.forEach((l, i) => {
    l.querySelector('.item-num').textContent = 'Item ' + (i + 1);
    // A primeira linha nunca some: sem ela não há o que lançar
    l.querySelector('.item-del').style.display = linhas.length > 1 ? '' : 'none';
  });
  const cnt = document.getElementById('itensCnt');
  if (cnt) cnt.textContent = linhas.length;
}

function addItemEntrada() {
  const tpl   = document.getElementById('tplItem');
  const lista = document.getElementById('listaItens');
  if (!tpl || !lista) return;
  lista.appendChild(tpl.content.cloneNode(true));
  ligarLinha(lista.lastElementChild);
  renumerarItens();
  lista.lastElementChild.querySelector('.in-busca')?.focus();
}

function delItemEntrada(btn) {
  if (document.querySelectorAll('#listaItens .item-linha').length <= 1) return;
  btn.closest('.item-linha').remove();
  renumerarItens();
}

/* Cada linha tem seus próprios campos — os comportamentos são ligados
   por linha, não por ID global. */
function ligarLinha(linha) {
  const inCod   = linha.querySelector('.in-codigo');
  const inBusca = linha.querySelector('.in-busca');
  const sel     = linha.querySelector('.in-catalogo');
  const hidNome = linha.querySelector('.in-nome');
  const drop    = linha.querySelector('.drop-cod');
  const wrapSld = linha.querySelector('.saldo-wrap');
  const badge   = linha.querySelector('.saldo-badge');
  const inValor = linha.querySelector('.in-valor');

  function aplicar(cod) {
    if (!cod) { hidNome.value=''; wrapSld.style.display='none'; return; }
    const op = [...sel.options].find(o => o.value === cod);
    hidNome.value = op ? (op.dataset.nome || '') : '';
    sel.value = cod;
    inCod.value = cod;
    const s = (saldosJS[cod] && saldosJS[cod]['_total']) || 0;
    badge.textContent = s + ' un.';
    badge.className = 'sb saldo-badge ' + sbCls(s);
    wrapSld.style.display = 'block';
  }

  sel.addEventListener('change', () => aplicar(sel.value));

  inCod.addEventListener('input', () => {
    const v = inCod.value.trim().toUpperCase();
    if (!v) { drop.style.display='none'; return; }
    const achados = [...sel.options].filter(o => o.value && o.value.toUpperCase().startsWith(v));
    if (!achados.length) { drop.style.display='none'; return; }
    drop.innerHTML = achados.slice(0,30).map(o =>
      '<div class="drop-op" data-cod="'+o.value+'"><strong>'+o.value+'</strong> — '+(o.dataset.nome||'')+'</div>'
    ).join('');
    drop.style.display='block';
    drop.querySelectorAll('.drop-op').forEach(d => {
      d.addEventListener('mousedown', ev => {
        ev.preventDefault(); aplicar(d.dataset.cod); drop.style.display='none';
      });
    });
  });
  inCod.addEventListener('blur', () => setTimeout(()=>drop.style.display='none',150));

  inBusca.addEventListener('input', () => {
    const t = inBusca.value.trim().toUpperCase();
    [...sel.options].forEach(o => {
      if (!o.value) return;
      o.hidden = t !== '' && !((o.value+' '+(o.dataset.nome||'')).toUpperCase().includes(t));
    });
  });

  if (inValor) inValor.addEventListener('input', function(){ maskValor(this); });
}

if (document.getElementById('listaItens')) addItemEntrada();

function resetEntrada(){
  document.getElementById('wrapDataGarantia').style.display='none';
  const lista = document.getElementById('listaItens');
  if (lista) { lista.innerHTML=''; addItemEntrada(); }
}

// ── Aba Saldo — renderiza cards por unidade ou total ─────────────────────────
function sbCls(v){ return v===0?'sb-zero':v<=2?'sb-low':'sb-ok'; }
function corVal(v){ return v===0?'#f87171':v<=2?'#facc15':'#4ade80'; }

function renderSaldo() {
  const uni = document.getElementById('filtroSaldoUnidade').value;
  const grid = document.getElementById('saldoGrid');
  grid.innerHTML = '';
  const pecas = Object.keys(catalogoMap);
  if(!pecas.length){ grid.innerHTML='<div style="color:var(--text-muted);font-size:13px">Nenhuma peça no catálogo.</div>'; return; }
  pecas.sort((a,b)=>catalogoMap[a].localeCompare(catalogoMap[b])).forEach(cp=>{
    const desc = catalogoMap[cp];
    const porUni = saldosJS[cp] || {};
    const qty = uni ? (porUni[uni]||0) : (porUni['_total']||0);

    // Quando "Todas as unidades": lista o saldo de cada unidade abaixo do total
    let detalhe = '';
    if(!uni){
      const linhas = Object.keys(porUni)
        .filter(u => u !== '_total')
        .map(u => ({ nome:u, qtd:porUni[u]||0 }))
        .filter(o => o.qtd > 0)
        .sort((a,b) => b.qtd - a.qtd || a.nome.localeCompare(b.nome));
      detalhe = linhas.length
        ? `<div class="sc-uni-list">${linhas.map(o=>
            `<div class="sc-uni-row"><span class="sc-uni-nome" title="${esc(o.nome)}">${esc(o.nome)}</span>`+
            `<span class="sc-uni-qtd" style="color:${corVal(o.qtd)}">${esc(o.qtd)}</span></div>`).join('')}</div>`
        : '<div class="sc-uni-vazio">Sem saldo em nenhuma unidade</div>';
    }

    const div=document.createElement('div');
    div.className='sc';
    div.innerHTML=`
      <div class="sc-cod">${esc(cp)}</div>
      <div class="sc-name" title="${esc(desc)}">${esc(desc)}</div>
      <div class="sc-val" style="color:${corVal(qty)}">${qty} <span style="font-size:12px;font-weight:400;color:var(--text-muted)">un.</span></div>
      ${uni?'':'<div class="sc-un">saldo geral</div>'}
      ${detalhe}
    `;
    grid.appendChild(div);
  });
}

// ── Aba Entradas — filtro/busca ───────────────────────────────────────────────
const filtroUni=document.getElementById('filtroUnidade');
const buscaInv=document.getElementById('buscaInv');
const invCount=document.getElementById('invCount');
function filtrarTabela(){
  const uni=filtroUni?filtroUni.value.toLowerCase():'';
  const q=buscaInv.value.toLowerCase().trim();
  const rows=document.querySelectorAll('#tblBody tr[data-id]');
  let v=0;
  rows.forEach(tr=>{
    const ru=(tr.dataset.unidade||'').toLowerCase();
    const rt=tr.innerText.toLowerCase();
    const ok=(!uni||ru===uni)&&(!q||rt.includes(q));
    tr.style.display=ok?'':'none';
    if(ok)v++;
  });
  if(invCount)invCount.textContent=v;
}
if(filtroUni)filtroUni.addEventListener('change',filtrarTabela);
if(buscaInv)buscaInv.addEventListener('input',filtrarTabela);

// ── Filtro do histórico de transferências ─────────────────────────────────────
const buscaTrf = document.getElementById('buscaTrf');
if (buscaTrf) {
  buscaTrf.addEventListener('input', () => {
    const q = buscaTrf.value.toLowerCase().trim();
    let vis = 0;
    document.querySelectorAll('.trf-row').forEach(row => {
      const ok = !q || (row.dataset.busca || '').includes(q);
      row.style.display = ok ? '' : 'none';
      if (ok) vis++;
    });
    const vazio = document.getElementById('trfVazio');
    if (vazio) vazio.style.display = vis === 0 ? '' : 'none';
  });
}

// ── Edição inline entradas ────────────────────────────────────────────────────
function modoEditar(btn){
  const tr=btn.closest('tr');
  tr.querySelectorAll('.view-mode').forEach(el=>el.style.display='none');
  tr.querySelectorAll('.edit-mode').forEach(el=>el.style.display='');
  tr.querySelectorAll('span.edit-mode').forEach(el=>el.style.display='inline-flex');
  tr.querySelectorAll('span.view-mode').forEach(el=>el.style.display='none');
}
function cancelarEdicao(btn){
  const tr=btn.closest('tr');
  tr.querySelectorAll('.view-mode').forEach(el=>el.style.display='');
  tr.querySelectorAll('.edit-mode').forEach(el=>el.style.display='none');
  tr.querySelectorAll('span.view-mode').forEach(el=>el.style.display='inline-flex');
  tr.querySelectorAll('span.edit-mode').forEach(el=>el.style.display='none');
}
function salvarEdicao(btn){
  const tr=btn.closest('tr');
  const id=tr.dataset.id;
  const fd=new FormData();
  fd.append('action','editar');fd.append('id_edit',id);
  fd.append('quantidade',tr.querySelector('.edit-qty').value);
  fd.append('numero_nota',tr.querySelector('.edit-nota').value.trim());
  fd.append('tem_garantia',tr.querySelector('.edit-garantia').value);
  fd.append('data_garantia',tr.querySelector('.edit-dtgar').value);
  fd.append('valor',tr.querySelector('.edit-valor').value);
  fetch('',{method:'POST',body:fd})
    .then(r=>{if(r.ok){showToast('Atualizado!','success');setTimeout(()=>location.reload(),800);}else showToast('Erro.','error');})
    .catch(()=>showToast('Falha.','error'));
}

// ── Aba Almoxarifado ──────────────────────────────────────────────────────────
let itemIdx = 0;

function saldoParaUnidade(cp, uni) {
  if(!uni) return (saldosJS[cp]&&saldosJS[cp]['_total'])||0;
  return (saldosJS[cp]&&saldosJS[cp][uni])||0;
}

function adicionarItem() {
  const uni = document.getElementById('selUniEstoque').value;
  const lista = document.getElementById('itemLista');
  const idx = itemIdx++;
  const div = document.createElement('div');
  div.className='item-row'; div.dataset.idx=idx;

  // Montar options do catálogo
  let opts='<option value="">Selecione o item...</option>';
  Object.keys(catalogoMap).sort((a,b)=>catalogoMap[a].localeCompare(catalogoMap[b])).forEach(cp=>{
    const s = saldoParaUnidade(cp, uni);
    opts+=`<option value="${cp}">${cp} — ${esc(catalogoMap[cp])} (${s} un.)</option>`;
  });

  div.innerHTML=`
    <select onchange="atualizarSaldoLinha(this,${idx})">${opts}</select>
    <span class="saldo-live" id="saldo-live-${idx}" style="color:var(--text-muted)"></span>
    <input type="number" value="1" min="1" style="width:65px;background:var(--bg-input);border:1px solid var(--border);border-radius:6px;padding:7px 8px;font-family:var(--font-ui);font-size:12px;color:var(--text-primary);outline:none;text-align:center">
    <button type="button" class="rm-btn" onclick="this.closest('.item-row').remove()"><i class="fas fa-xmark"></i></button>
  `;
  lista.appendChild(div);
}

function atualizarSaldosAlmox() {
  // Recriar selects com saldo da unidade atualizado
  const uni = document.getElementById('selUniEstoque').value;
  document.querySelectorAll('#itemLista .item-row').forEach(row=>{
    const sel=row.querySelector('select');
    const prevVal=sel.value;
    let opts='<option value="">Selecione o item...</option>';
    Object.keys(catalogoMap).sort((a,b)=>catalogoMap[a].localeCompare(catalogoMap[b])).forEach(cp=>{
      const s=saldoParaUnidade(cp,uni);
      opts+=`<option value="${cp}"${cp===prevVal?' selected':''}>${cp} — ${esc(catalogoMap[cp])} (${s} un.)</option>`;
    });
    sel.innerHTML=opts;
    if(prevVal) atualizarSaldoLinhaEl(sel, row.querySelector('.saldo-live'));
  });
}

function atualizarSaldoLinha(sel, idx) {
  const span=document.getElementById('saldo-live-'+idx);
  if(!span) return;
  atualizarSaldoLinhaEl(sel, span);
}
function atualizarSaldoLinhaEl(sel, span) {
  const cp=sel.value;
  const uni=document.getElementById('selUniEstoque').value;
  if(!cp){ span.textContent=''; return; }
  const s=saldoParaUnidade(cp,uni);
  span.textContent=s+' em estoque';
  span.style.color=s===0?'#f87171':s<=2?'#facc15':'#4ade80';
}

function prepararItens() {
  // Serializar itens para campos hidden antes de submeter
  const form=document.getElementById('formDespacho');
  // Remover hiddens antigos
  form.querySelectorAll('input[name^="itens["]').forEach(el=>el.remove());
  const rows=document.querySelectorAll('#itemLista .item-row');
  if(!rows.length){ alert('Adicione ao menos 1 item.'); return false; }
  let i=0, ok=true;
  rows.forEach(row=>{
    const cp=row.querySelector('select').value;
    const qty=row.querySelector('input[type=number]').value;
    if(!cp){ ok=false; return; }
    const hcp=document.createElement('input'); hcp.type='hidden'; hcp.name=`itens[${i}][codigo_peca]`; hcp.value=cp; form.appendChild(hcp);
    const hqt=document.createElement('input'); hqt.type='hidden'; hqt.name=`itens[${i}][quantidade]`; hqt.value=qty; form.appendChild(hqt);
    i++;
  });
  if(!ok){ alert('Selecione o item em todas as linhas.'); return false; }
  return true;
}

// Inicializar com 1 item na lista (almoxarifado)
adicionarItem();

// ── Aba Transferência de Materiais ────────────────────────────────────────────
let itemIdxT = 0;
let transfInitializado = false;

function adicionarItemTransf() {
  const lista = document.getElementById('itemListaTransf');
  const uni   = document.getElementById('selUniOrigem')?.value || '';
  const idx   = itemIdxT++;
  const div   = document.createElement('div');
  div.className = 'item-row'; div.dataset.idx = idx;

  let opts = '<option value="">Selecione o item...</option>';
  Object.keys(catalogoMap)
    .sort((a,b) => catalogoMap[a].localeCompare(catalogoMap[b]))
    .forEach(cp => {
      const s = saldoParaUnidade(cp, uni);
      opts += `<option value="${cp}">${cp} — ${esc(catalogoMap[cp])} (${s} un.)</option>`;
    });

  div.innerHTML = `
    <select onchange="atualizarSaldoLinhaTransf(this,${idx})">${opts}</select>
    <span class="saldo-live" id="saldo-live-t-${idx}" style="color:var(--text-muted)"></span>
    <input type="number" value="1" min="1"
      style="width:65px;background:var(--bg-input);border:1px solid var(--border);border-radius:6px;padding:7px 8px;font-family:var(--font-ui);font-size:12px;color:var(--text-primary);outline:none;text-align:center">
    <button type="button" class="rm-btn" onclick="this.closest('.item-row').remove()"><i class="fas fa-xmark"></i></button>
  `;
  lista.appendChild(div);
  transfInitializado = true;
}

function atualizarSaldosTransf() {
  const uni   = document.getElementById('selUniOrigem').value;
  const panel = document.getElementById('saldoOrigemPanel');
  const lista = document.getElementById('saldoOrigemLista');

  // Atualizar selects
  document.querySelectorAll('#itemListaTransf .item-row').forEach(row => {
    const sel = row.querySelector('select');
    const prevVal = sel.value;
    let opts = '<option value="">Selecione o item...</option>';
    Object.keys(catalogoMap)
      .sort((a,b) => catalogoMap[a].localeCompare(catalogoMap[b]))
      .forEach(cp => {
        const s = saldoParaUnidade(cp, uni);
        opts += `<option value="${cp}"${cp===prevVal?' selected':''}>${cp} — ${esc(catalogoMap[cp])} (${s} un.)</option>`;
      });
    sel.innerHTML = opts;
    const span = row.querySelector('.saldo-live');
    if (prevVal && span) { sel.value = prevVal; atualizarSaldoLinhaTransfEl(sel, span); }
  });

  // Mostrar painel de saldos disponíveis
  if (!uni) { panel.style.display = 'none'; return; }
  panel.style.display = 'block';
  const itens = Object.keys(catalogoMap)
    .map(cp => ({ cp, desc: catalogoMap[cp], qty: saldoParaUnidade(cp, uni) }))
    .filter(i => i.qty > 0)
    .sort((a,b) => a.desc.localeCompare(b.desc));

  if (!itens.length) {
    lista.innerHTML = '<span style="color:var(--text-muted)">Nenhum item com saldo nesta unidade.</span>';
  } else {
    lista.innerHTML = itens.map(i =>
      `<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid rgba(255,255,255,.03)">
         <span>${esc(i.desc)}</span>
         <span style="font-weight:600;color:${i.qty<=2?'#facc15':'#4ade80'}">${esc(i.qty)} un.</span>
       </div>`
    ).join('');
  }
}

function atualizarSaldoLinhaTransf(sel, idx) {
  const span = document.getElementById('saldo-live-t-' + idx);
  if (!span) return;
  atualizarSaldoLinhaTransfEl(sel, span);
}
function atualizarSaldoLinhaTransfEl(sel, span) {
  const cp  = sel.value;
  const uni = document.getElementById('selUniOrigem').value;
  if (!cp) { span.textContent = ''; return; }
  const s = saldoParaUnidade(cp, uni);
  span.textContent = s + ' em estoque';
  span.style.color = s <= 0 ? '#f87171' : s <= 2 ? '#facc15' : '#4ade80';
}

function prepararItensTransf() {
  const form    = document.getElementById('formTransf');
  const uniOrig = document.getElementById('selUniOrigem').value;
  const uniDest = document.getElementById('selUniDestinoT').value;

  if (!uniOrig || !uniDest) { alert('Selecione a unidade de origem e destino.'); return false; }
  if (uniOrig === uniDest)  { alert('Origem e destino não podem ser iguais.');  return false; }

  form.querySelectorAll('input[name^="itens["]').forEach(el => el.remove());
  const rows = document.querySelectorAll('#itemListaTransf .item-row');
  if (!rows.length) { alert('Adicione ao menos 1 item.'); return false; }

  let i = 0, ok = true;
  rows.forEach(row => {
    const cp  = row.querySelector('select').value;
    const qty = parseInt(row.querySelector('input[type=number]').value, 10) || 0;
    if (!cp || qty <= 0) { ok = false; return; }
    // Verificar saldo na origem
    const saldo = saldoParaUnidade(cp, uniOrig);
    if (qty > saldo) {
      alert(`Saldo insuficiente para "${esc(catalogoMap[cp])}": disponível ${saldo} un., solicitado ${qty} un.`);
      ok = false; return;
    }
    const hcp = document.createElement('input'); hcp.type='hidden'; hcp.name=`itens[${i}][codigo_peca]`; hcp.value=cp; form.appendChild(hcp);
    const hqt = document.createElement('input'); hqt.type='hidden'; hqt.name=`itens[${i}][quantidade]`; hqt.value=qty; form.appendChild(hqt);
    i++;
  });
  if (!ok) return false;
  return confirm(`Confirmar transferência de ${i} tipo(s) de item de "${uniOrig}" para "${uniDest}"?`);
}

// ── Toast ─────────────────────────────────────────────────────────────────────
let toastTimer;
function showToast(msg,tipo='success'){
  const t=document.getElementById('toast');
  document.getElementById('toastMsg').innerText=msg;
  document.getElementById('toastIcon').className=tipo==='success'?'fas fa-circle-check':'fas fa-circle-xmark';
  t.className='show '+tipo;
  clearTimeout(toastTimer);
  toastTimer=setTimeout(()=>{t.className='';},4000);
}
<?php if($msg): ?>
setTimeout(()=>showToast(<?=json_encode($msg)?>,<?=$msg_tipo==='ok'?'"success"':'"error"'?>),200);
<?php endif; ?>

// ── Abrir aba correta ─────────────────────────────────────────────────────────
<?php
$aba_abrir = 'Entrada';
if ($_GET['comprovante'] ?? '') $aba_abrir = 'Almox';
elseif ($msg_tipo === 'ok' && strpos($msg, 'Entrada') !== false) $aba_abrir = 'Entradas';
elseif ($msg_tipo === 'ok') $aba_abrir = 'Entradas';
?>
mudarTab('<?= $aba_abrir ?>');
</script>
<?php eng_clin_menu_painel(true); ?>
</body>
</html>