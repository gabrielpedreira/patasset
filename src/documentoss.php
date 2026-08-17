<?php
// Erros não são exibidos ao usuário em produção: a mensagem do PHP revela
// caminho do servidor, nome de tabela e trecho de SQL. Continuam sendo
// registrados e ficam visíveis no painel DEV → Erros (ver dev_captura.php).
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();
require_once "conexao.php";
require_once 'check_session.php';

$usuario_logado = $_SESSION['usuario_logado'] ?? '';
if (!$usuario_logado) {
    header("Location: login.php");
    exit();
}

$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $usuario_logado);
$stmt->execute();
$res = $stmt->get_result();
$permicao = $classe_usuario = ''; $status = 'ATIVO';
if ($row = $res->fetch_assoc()) {
    $permicao       = $row['permicao'];
    $classe_usuario = $row['classe_usuario'];
    $status         = $row['status'] ?? 'ATIVO';
}
$stmt->close();

if ($status !== 'ATIVO') {
    session_destroy();
    header("Location: index.html?erro=bloqueado");
    exit();
}

if (!in_array($permicao, ['A','B']) || !in_array($classe_usuario, ['DEV','PATRIMONIO'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}

$usuarioLogado = $usuario_logado;

/* ── helpers ── */
function esc($conn, $v){ return $conn->real_escape_string((string)($v ?? '')); }

function normalizarMoeda(string $v): string {
    $v = trim($v);
    $v = preg_replace('/^R\$\s*/', '', $v);
    $v = trim($v);
    if ($v === '') return '';
    if (strpos($v, ',') !== false) {
        $partes = explode(',', $v);
        $inteira = str_replace('.', '', $partes[0]);
        $decimal = substr($partes[1] ?? '00', 0, 2);
        $decimal = str_pad($decimal, 2, '0');
        $inteira = ltrim($inteira, '0') ?: '0';
        $intFormatada = number_format((int)$inteira, 0, ',', '.');
        return $intFormatada . ',' . $decimal;
    }
    if (strpos($v, '.') !== false && preg_match('/\.\d{1,2}$/', $v)) {
        $num = (float)$v;
        return number_format($num, 2, ',', '.');
    }
    if (ctype_digit($v)) {
        $num = (int)$v / 100;
        return number_format($num, 2, ',', '.');
    }
    return $v;
}

function buscarNotaPorTagSerie($conn, $tagS, $serieS) {
    if ($tagS !== '' && $serieS !== '') {
        $r = $conn->prepare("SELECT id FROM nota WHERE LOWER(TRIM(tag_patrimonio))=LOWER(?) OR LOWER(TRIM(numero_serie))=LOWER(?) LIMIT 1");
        $r->bind_param("ss", $tagS, $serieS);
    } elseif ($tagS !== '') {
        $r = $conn->prepare("SELECT id FROM nota WHERE LOWER(TRIM(tag_patrimonio))=LOWER(?) LIMIT 1");
        $r->bind_param("s", $tagS);
    } elseif ($serieS !== '') {
        $r = $conn->prepare("SELECT id FROM nota WHERE LOWER(TRIM(numero_serie))=LOWER(?) LIMIT 1");
        $r->bind_param("s", $serieS);
    } else {
        return null;
    }
    $r->execute();
    $res = $r->get_result();
    $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $r->close();
    return $row;
}

$dadosConc   = null; $dadosNota = null; $jaConc = false; $idNotaExist = null;
$tagConc     = $_POST['tag_conc']   ?? '';
$serieConc   = $_POST['serie_conc'] ?? '';
$concFields  = ['centro_custo_unidade'=>'','centro_custo_setor'=>'','unidade_atribuida'=>'','setor_atribuido'=>'',
                'numero_nota'=>'','fornecedor'=>'','cnpj'=>'','data'=>'','valor_nota'=>'','valor_item'=>''];
$msgConc = ''; $tipoMsgConc = '';

if (isset($_SESSION['msg_conc'])) {
    $msgConc = $_SESSION['msg_conc']; $tipoMsgConc = $_SESSION['tipo_msg_conc'];
    unset($_SESSION['msg_conc'], $_SESSION['tipo_msg_conc']);
}

function verificarConciliado($dadosConc, $conn, $tagS, $serieS, &$jaConc, &$idNotaExist, &$dadosNota, &$concFields) {
    if (strtoupper(trim($dadosConc['conciliado'] ?? '')) === 'SIM') $jaConc = true;
    $dadosNota = null; $idNotaExist = null;
    if ($tagS !== '' && $serieS !== '') {
        $r = $conn->prepare("SELECT * FROM nota WHERE LOWER(TRIM(tag_patrimonio))=LOWER(?) OR LOWER(TRIM(numero_serie))=LOWER(?) LIMIT 1");
        $r->bind_param("ss", $tagS, $serieS); $r->execute(); $res = $r->get_result();
    } elseif ($tagS !== '') {
        $r = $conn->prepare("SELECT * FROM nota WHERE LOWER(TRIM(tag_patrimonio))=LOWER(?) LIMIT 1");
        $r->bind_param("s", $tagS); $r->execute(); $res = $r->get_result();
    } elseif ($serieS !== '') {
        $r = $conn->prepare("SELECT * FROM nota WHERE LOWER(TRIM(numero_serie))=LOWER(?) LIMIT 1");
        $r->bind_param("s", $serieS); $r->execute(); $res = $r->get_result();
    } else { $res = null; }
    if (isset($res) && $res && $res->num_rows > 0) {
        $jaConc = true; $dadosNota = $res->fetch_assoc(); $idNotaExist = $dadosNota['id'];
        if (!empty($dadosNota['numero_nota'])) $concFields['numero_nota'] = $dadosNota['numero_nota'];
    }
    if (isset($r)) $r->close();
    if ($jaConc) {
        if (!empty($dadosConc['centro_custo_unidade'])) $concFields['centro_custo_unidade'] = $dadosConc['centro_custo_unidade'];
        if (!empty($dadosConc['centro_custo_setor']))   $concFields['centro_custo_setor']   = $dadosConc['centro_custo_setor'];
        if (!empty($dadosConc['unidade_atribuida']))    $concFields['unidade_atribuida']    = $dadosConc['unidade_atribuida'];
        if (!empty($dadosConc['setor_atribuido']))      $concFields['setor_atribuido']      = $dadosConc['setor_atribuido'];
        if (!empty($dadosConc['nota_fiscal']))          $concFields['numero_nota']          = $dadosConc['nota_fiscal'];
        if (!empty($dadosConc['fornecedor_nome']))      $concFields['fornecedor']           = $dadosConc['fornecedor_nome'];
        if (!empty($dadosConc['fornecedor_cnpj']))      $concFields['cnpj']                 = $dadosConc['fornecedor_cnpj'];
        if (!empty($dadosConc['data_aquisicao']) && $dadosConc['data_aquisicao'] !== '0000-00-00')
                                                        $concFields['data']                 = $dadosConc['data_aquisicao'];
        if (!empty($dadosConc['valor_nota']))           $concFields['valor_nota']           = $dadosConc['valor_nota'];
        if (!empty($dadosConc['valor_item']))           $concFields['valor_item']           = $dadosConc['valor_item'];
        if ($dadosNota && !empty($dadosNota['numero_nota']))    $concFields['numero_nota']  = $dadosNota['numero_nota'];
        if ($dadosNota && !empty($dadosNota['dt_referencia']) && $dadosNota['dt_referencia'] !== '0000-00-00')
                                                                $concFields['data']         = $dadosNota['dt_referencia'];
    }
}

if (isset($_POST['buscar_conc'])) {
    $dadosConc = null; $dadosNota = null; $jaConc = false; $idNotaExist = null;
    $concFields = array_fill_keys(array_keys($concFields), '');
    $tagConc   = trim($_POST['tag_conc']   ?? '');
    $serieConc = trim($_POST['serie_conc'] ?? '');
    if ($tagConc === '' && $serieConc === '') {
        $msgConc = "Informe TAG ou Número de Série."; $tipoMsgConc = "erro";
    } else {
        $dadosTag = $dadosSerie = null;
        if ($tagConc !== '') {
            $r = $conn->query("SELECT * FROM cadastro WHERE tag_antiga='".esc($conn,$tagConc)."' OR tag_trocada='".esc($conn,$tagConc)."' LIMIT 1");
            if ($r && $r->num_rows > 0) $dadosTag = $r->fetch_assoc();
        }
        if ($serieConc !== '') {
            $r = $conn->query("SELECT * FROM cadastro WHERE serie='".esc($conn,$serieConc)."' LIMIT 1");
            if ($r && $r->num_rows > 0) $dadosSerie = $r->fetch_assoc();
        }
        if ($dadosTag && $dadosSerie) {
            if ($dadosTag['id'] != $dadosSerie['id']) { $msgConc = "Tag e Série não correspondem ao mesmo registro."; $tipoMsgConc = "erro"; }
            else $dadosConc = $dadosTag;
        } elseif ($dadosTag) { $dadosConc = $dadosTag; }
        elseif ($dadosSerie) { $dadosConc = $dadosSerie; }
        else { $msgConc = "Nenhum registro encontrado."; $tipoMsgConc = "erro"; }
        if ($dadosConc) {
            $tagS   = (string)($dadosConc['tag_trocada'] ?: $dadosConc['tag_antiga']);
            $serieS = (string)($dadosConc['serie'] ?? '');
            verificarConciliado($dadosConc, $conn, $tagS, $serieS, $jaConc, $idNotaExist, $dadosNota, $concFields);
        }
    }
}

$naoConciliados = [];
$r = $conn->query("SELECT id,descricao,descricao_detalhada,marca,modelo,serie,tag_antiga,tag_trocada,nota_fiscal FROM cadastro WHERE conciliado='NAO' ORDER BY id DESC");
while ($row = $r->fetch_assoc()) $naoConciliados[] = $row;

if (isset($_POST['salvar_conc'])) {
    $id = trim($_POST['id_conc'] ?? '');
    foreach (array_keys($concFields) as $k) $concFields[$k] = trim($_POST[$k] ?? '');
    $concFields['valor_nota'] = normalizarMoeda($concFields['valor_nota']);
    $concFields['valor_item'] = normalizarMoeda($concFields['valor_item']);
    $idNotaExist = trim($_POST['id_nota_conc'] ?? '') ?: null;
    if ($idNotaExist !== null) $idNotaExist = (int)$idNotaExist;
    if ($id !== '' && $dadosConc === null) {
        $r = $conn->query("SELECT * FROM cadastro WHERE id=".(int)$id." LIMIT 1");
        if ($r && $r->num_rows > 0) {
            $dadosConc = $r->fetch_assoc();
            $tagConc   = (string)($dadosConc['tag_trocada'] ?: $dadosConc['tag_antiga']);
            $serieConc = (string)($dadosConc['serie'] ?? '');
            if (strtoupper(trim($dadosConc['conciliado'] ?? '')) === 'SIM') $jaConc = true;
        }
    }
    if ($id === '') {
        $msgConc = 'Selecione um registro antes de salvar.'; $tipoMsgConc = "erro";
    } else {
        $f1=$concFields['centro_custo_unidade']; $f2=$concFields['centro_custo_setor'];
        $f3=$concFields['unidade_atribuida'];    $f4=$concFields['setor_atribuido'];
        $f5=$concFields['numero_nota'];          $f6=$concFields['fornecedor'];
        $f7=$concFields['cnpj'];                 $f8=$concFields['data'];
        $f9=$concFields['valor_nota'];           $f10=$concFields['valor_item'];
        $semDados = ($f1===''&&$f2===''&&$f3===''&&$f4===''&&$f5===''&&$f6===''&&$f7===''&&$f8===''&&$f9===''&&$f10==='') &&
                    (!isset($_FILES['pdf_conc'])||$_FILES['pdf_conc']['error']!=0);
        if ($semDados) {
            $msgConc = "Digite os dados da nota fiscal para fazer a conciliação."; $tipoMsgConc = "erro";
        } else {
            $tagS = trim($_POST['tag_exibida_conc'] ?? ''); $serieS = trim($_POST['serie_exibida_conc'] ?? '');
            $idInt = (int)$id; $dataSQL = ($f8 !== '') ? "'".esc($conn,$f8)."'" : "NULL";
            $sql = "UPDATE cadastro SET
                centro_custo_unidade='".esc($conn,$f1)."', centro_custo_setor='".esc($conn,$f2)."',
                unidade_atribuida='".esc($conn,$f3)."',    setor_atribuido='".esc($conn,$f4)."',
                nota_fiscal='".esc($conn,$f5)."',          fornecedor_nome='".esc($conn,$f6)."',
                fornecedor_cnpj='".esc($conn,$f7)."',      data_aquisicao=$dataSQL,
                valor_nota='".esc($conn,$f9)."',           valor_item='".esc($conn,$f10)."',
                usuario_conciliacao='".esc($conn,$usuarioLogado)."',
                conciliado='SIM' WHERE id=$idInt";
            if (!$conn->query($sql)) {
                $msgConc = "Erro ao salvar: ".$conn->error; $tipoMsgConc = "erro";
            } else {
                $tipo_doc_conc = 'NOTA FISCAL'; $titulo_doc_conc = 'CONCILIACAO';
                if (isset($_FILES['pdf_conc']) && $_FILES['pdf_conc']['error']==0) {
                    $arquivo = file_get_contents($_FILES['pdf_conc']['tmp_name']);
                    $mime    = preg_replace('/[^a-zA-Z0-9\/\.\-\+]/', '', $_FILES['pdf_conc']['type']);
                    if (empty($mime)) $mime = 'application/pdf';
                    $dtRefConc = ($f8 !== '') ? $f8 : null;
                    if ($idNotaExist) {
                        $upd = $conn->prepare("UPDATE nota SET tipo_doc=?, titulo_doc=?, numero_nota=?, dt_referencia=?, nota_fiscal=?, mime_type=?, tag_patrimonio=?, numero_serie=? WHERE id=?");
                        $upd->bind_param("ssssbsssi", $tipo_doc_conc, $titulo_doc_conc, $f5, $dtRefConc, $arquivo, $mime, $tagS, $serieS, $idNotaExist);
                        $upd->send_long_data(4, $arquivo); $upd->execute(); $upd->close();
                    } else {
                        $rowChk = buscarNotaPorTagSerie($conn, $tagS, $serieS);
                        if ($rowChk) {
                            $nid = $rowChk['id'];
                            $upd = $conn->prepare("UPDATE nota SET tipo_doc=?, titulo_doc=?, numero_nota=?, dt_referencia=?, nota_fiscal=?, mime_type=?, tag_patrimonio=?, numero_serie=? WHERE id=?");
                            $upd->bind_param("ssssbsssi", $tipo_doc_conc, $titulo_doc_conc, $f5, $dtRefConc, $arquivo, $mime, $tagS, $serieS, $nid);
                            $upd->send_long_data(4, $arquivo); $upd->execute(); $upd->close();
                        } else {
                            $ins = $conn->prepare("INSERT INTO nota (tipo_doc,titulo_doc,tag_patrimonio,numero_serie,numero_nota,dt_referencia,nota_fiscal,mime_type) VALUES (?,?,?,?,?,?,?,?)");
                            $ins->bind_param("ssssssbs", $tipo_doc_conc, $titulo_doc_conc, $tagS, $serieS, $f5, $dtRefConc, $arquivo, $mime);
                            $ins->send_long_data(6, $arquivo); $ins->execute(); $ins->close();
                        }
                    }
                } else {
                    $dtRefConc = ($f8 !== '') ? $f8 : null;
                    if ($idNotaExist) {
                        $upd = $conn->prepare("UPDATE nota SET tipo_doc=?, titulo_doc=?, numero_nota=?, dt_referencia=?, tag_patrimonio=?, numero_serie=? WHERE id=?");
                        $upd->bind_param("ssssssi", $tipo_doc_conc, $titulo_doc_conc, $f5, $dtRefConc, $tagS, $serieS, $idNotaExist);
                        $upd->execute(); $upd->close();
                    } else {
                        $rowChk = buscarNotaPorTagSerie($conn, $tagS, $serieS);
                        if ($rowChk) {
                            $nid = $rowChk['id'];
                            $upd = $conn->prepare("UPDATE nota SET tipo_doc=?, titulo_doc=?, numero_nota=?, dt_referencia=?, tag_patrimonio=?, numero_serie=? WHERE id=?");
                            $upd->bind_param("ssssssi", $tipo_doc_conc, $titulo_doc_conc, $f5, $dtRefConc, $tagS, $serieS, $nid);
                            $upd->execute(); $upd->close();
                        } else {
                            $ins = $conn->prepare("INSERT INTO nota (tipo_doc,titulo_doc,tag_patrimonio,numero_serie,numero_nota,dt_referencia) VALUES (?,?,?,?,?,?)");
                            $ins->bind_param("ssssss", $tipo_doc_conc, $titulo_doc_conc, $tagS, $serieS, $f5, $dtRefConc);
                            $ins->execute(); $ins->close();
                        }
                    }
                }
                $_SESSION['msg_conc'] = "Conciliação salva com sucesso!";
                $_SESSION['tipo_msg_conc'] = "sucesso";
                header("Location: documentoss.php"); exit();
            }
        }
    }
}

/* ══ DOCUMENTOS ══ */
$msgDoc = ''; $tipoMsgDoc = '';
if (isset($_SESSION['msg'])) {
    $msgDoc = $_SESSION['msg']; $tipoMsgDoc = $_SESSION['tipo_msg'];
    unset($_SESSION['msg'], $_SESSION['tipo_msg']);
}

if (isset($_POST['adicionar'])) {
    if (empty($_POST['tipo_doc']) || empty($_FILES['arquivo']['name'])) {
        $msgDoc = "Tipo de documento e arquivo são obrigatórios."; $tipoMsgDoc = "erro";
    } else {
        $tipo_doc       = $_POST['tipo_doc'];
        $titulo_doc     = $_POST['titulo_doc'];
        $dt_referencia  = !empty($_POST['dt_referencia']) ? $_POST['dt_referencia'] : null;
        $numero_nota    = $_POST['numero_nota_doc'];
        $numero_serie   = $_POST['numero_serie_doc'];
        $tag_patrimonio = $_POST['tag_patrimonio_doc'];
        $arquivo_bin    = file_get_contents($_FILES['arquivo']['tmp_name']);
        /* sanitiza mime_type: remove bytes fora do ASCII imprimível para evitar erro de charset no banco */
        $mime_type      = preg_replace('/[^a-zA-Z0-9\/\.\-\+]/', '', $_FILES['arquivo']['type']);
        if (empty($mime_type)) $mime_type = 'application/octet-stream';
        $mimes_ok = ['application/pdf','image/png','image/jpeg','image/jpg','image/gif','image/webp'];
        if (!in_array($mime_type, $mimes_ok)) {
            $msgDoc = "Tipo de arquivo não permitido. Envie PDF, PNG, JPG, GIF ou WEBP."; $tipoMsgDoc = "erro";
        } else {
            $stmt = $conn->prepare("INSERT INTO nota (tipo_doc,titulo_doc,dt_referencia,numero_nota,numero_serie,tag_patrimonio,nota_fiscal,mime_type) VALUES (?,?,?,?,?,?,?,?)");
            $null = null;
            // ordem: tipo_doc, titulo_doc, dt_referencia, numero_nota, numero_serie, tag_patrimonio, nota_fiscal(blob), mime_type
            $stmt->bind_param("ssssssbs", $tipo_doc, $titulo_doc, $dt_referencia, $numero_nota, $numero_serie, $tag_patrimonio, $null, $mime_type);
            $stmt->send_long_data(6, $arquivo_bin);
            if ($stmt->execute()) { $_SESSION['msg'] = "Documento adicionado com sucesso!"; $_SESSION['tipo_msg'] = "sucesso"; }
            else { $_SESSION['msg'] = "Erro ao inserir: ".$stmt->error; $_SESSION['tipo_msg'] = "erro"; }
            $stmt->close();
            header("Location: documentoss.php"); exit();
        }
    }
}

if (isset($_POST['excluir'])) {
    $id_excluir = intval($_POST['id_excluir'] ?? 0);
    if ($id_excluir > 0) {
        $stmt = $conn->prepare("DELETE FROM nota WHERE id=?");
        $stmt->bind_param("i", $id_excluir); $ok = $stmt->execute();
        $_SESSION['msg']      = $ok ? "Registro excluído com sucesso!" : "Erro ao excluir: ".$stmt->error;
        $_SESSION['tipo_msg'] = $ok ? "sucesso" : "erro";
        $stmt->close();
    }
    header("Location: documentoss.php"); exit();
}

$resultados = [];
$result = $conn->query("SELECT id,tipo_doc,titulo_doc,dt_referencia,numero_nota,numero_serie,tag_patrimonio,mime_type FROM nota ORDER BY id DESC");
if ($result) $resultados = $result->fetch_all(MYSQLI_ASSOC);
$total_registros = count($resultados);

function exibirMoeda(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    if (str_starts_with($v, 'R$')) return $v;
    return 'R$ ' . $v;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Documentos & Conciliação</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;background:linear-gradient(135deg,#001435,#60a5fa);padding:20px;min-height:100vh;}
.page-wrapper{max-width:1050px;width:100%;margin:auto;display:flex;flex-direction:column;gap:30px;}
.page-header{text-align:center;padding:10px 0 5px;}
.page-header h1{color:#fff;font-size:1.75rem;font-weight:700;letter-spacing:1px;text-shadow:0 2px 8px rgba(0,0,0,.35);}
.card{background:#fff;border-radius:18px;padding:28px 28px 22px;box-shadow:0 15px 35px rgba(0,0,0,.25);}
.card-title{display:flex;align-items:center;gap:10px;margin-bottom:22px;padding-bottom:12px;border-bottom:2px solid #e5e7eb;}
.card-title h2{font-size:1.15rem;color:#111827;font-weight:700;}
.counter-badge{margin-left:auto;background:#2563eb;color:#fff;font-size:12px;font-weight:700;padding:3px 12px;border-radius:999px;}
.caixa{background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:18px;}
.caixa h3{margin:0 0 14px;font-size:13px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.6px;}
.linha2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.field{margin-bottom:14px;}.field:last-child{margin-bottom:0;}
.field label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;}
input[type=text],input[type=date],input[type=file],select{width:100%;padding:10px 12px;border-radius:9px;border:1px solid #cbd5e1;font-size:13px;transition:.2s;background:#fff;}
input[type=text]:focus,input[type=date]:focus,select:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.18);outline:none;}
input:disabled,input[readonly]{background:#f1f5f9;color:#64748b;cursor:not-allowed;}
input.moeda{text-transform:none;}
.actions{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;}
button{padding:10px 18px;font-size:13px;font-weight:600;border-radius:9px;border:none;cursor:pointer;transition:all .18s;}
.btn-primary{background:#2563eb;color:#fff;flex:1;min-width:100px;}.btn-primary:hover{background:#1e40af;transform:translateY(-1px);}
.btn-secondary{background:#e5e7eb;color:#111;}.btn-secondary:hover{background:#d1d5db;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;transform:translateY(-1px);}
.btn-abrir{background:#0ea5e9;color:#fff;padding:4px 11px;font-size:12px;border-radius:7px;border:none;cursor:pointer;white-space:nowrap;}
.btn-abrir:hover{background:#0284c7;}
.msg{padding:11px 16px;border-radius:9px;margin-bottom:16px;font-weight:600;font-size:13px;}
.sucesso{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
.erro{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
#toastDoc{position:fixed;bottom:30px;left:50%;transform:translateX(-50%) translateY(80px);
    background:#166534;color:#fff;padding:12px 28px;border-radius:12px;font-size:14px;font-weight:700;
    box-shadow:0 8px 30px rgba(0,0,0,.3);opacity:0;transition:opacity .35s,transform .35s;
    z-index:9999;pointer-events:none;white-space:nowrap;}
#toastDoc.visivel{opacity:1;transform:translateX(-50%) translateY(0);}
#toastDoc.erro-toast{background:#991b1b;}
.tabela-nc-container{max-height:240px;overflow:auto;border:1px solid #cbd5e1;border-radius:9px;margin-bottom:10px;}
.tabela-nc{width:100%;border-collapse:collapse;font-size:12px;min-width:680px;}
.tabela-nc thead td{background:#2563eb;color:#fff;font-weight:700;padding:8px;white-space:nowrap;position:sticky;top:0;z-index:2;}
.tabela-nc tbody tr{border-bottom:1px solid #e5e7eb;cursor:pointer;}
.tabela-nc tbody tr:hover td{background:#eff6ff;}
.tabela-nc tbody tr.selecionada-nc td{background:#bfdbfe !important;font-weight:600;}
.tabela-nc tbody td{padding:7px 8px;}
.table-container{max-height:380px;overflow-y:auto;overflow-x:auto;border:1px solid #d1d5db;border-radius:8px;margin-bottom:8px;}
table.docs{width:100%;border-collapse:collapse;font-size:13px;min-width:820px;}
table.docs thead td{background:#2563eb;color:#fff;font-weight:700;padding:9px 8px;white-space:nowrap;position:sticky;top:0;z-index:2;}
table.docs tbody tr{border-bottom:1px solid #e5e7eb;cursor:pointer;transition:background .12s;}
table.docs tbody tr:hover{background:#f0f7ff;}
table.docs tbody tr.selecionado{background:#dbeafe !important;outline:2px solid #2563eb;outline-offset:-2px;}
table.docs tbody tr.modificado td:first-child{border-left:3px solid #f59e0b;}
table.docs tbody tr.salvo{background:#dcfce7 !important;}
table.docs tbody td{padding:7px 8px;vertical-align:middle;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;}
tbody td input.cel-input{width:100%;border:none;background:transparent;padding:4px 2px;font-size:13px;color:#0f172a;cursor:default;pointer-events:none;outline:none;}
tbody td input.cel-input.editando{background:#eef2ff;border:1px solid #6366f1;border-radius:6px;padding:4px 6px;cursor:text;pointer-events:all;}
.hint{font-size:11px;color:#94a3b8;margin-bottom:8px;}
.pesquisa-input{width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:9px;font-size:13px;outline:none;}
.pesquisa-input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.18);}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;}
.modal-overlay.ativo{display:flex;}
.modal-box{background:#fff;border-radius:16px;padding:28px 30px;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);text-align:center;}
.modal-box h3{font-size:17px;color:#0f172a;margin-bottom:8px;}
.modal-box p{font-size:13px;color:#475569;margin-bottom:20px;}
.modal-acts{display:flex;gap:10px;justify-content:center;}
@media(max-width:700px){.linha2{grid-template-columns:1fr;}.actions{flex-direction:column;}.card{padding:16px;}}
</style>
</head>
<body>

<div id="toastDoc"></div>

<div class="modal-overlay" id="modalExcluir">
    <div class="modal-box">
        <h3>Confirmar Exclusão</h3>
        <p id="modalTexto">Deseja excluir este registro permanentemente?</p>
        <div class="modal-acts">
            <button class="btn-secondary" onclick="fecharModal()">Cancelar</button>
            <form method="POST" action="documentoss.php" id="formExcluir" style="margin:0">
                <input type="hidden" name="id_excluir" id="inputIdExcluir">
                <button type="submit" name="excluir" class="btn-danger">Excluir</button>
            </form>
        </div>
    </div>
</div>

<div class="page-wrapper">
<div class="page-header"><h1>Gestão de Documentos</h1></div>

<!-- ══ CONCILIAÇÃO ══ -->
<div class="card">
    <div class="card-title"><h2>Conciliação</h2></div>
    <?php if ($msgConc !== ''): ?><div class="msg <?= $tipoMsgConc ?>"><?= htmlspecialchars($msgConc) ?></div><?php endif; ?>
    <form method="POST" enctype="multipart/form-data" action="documentoss.php" id="formConc">
        <div class="caixa">
            <h3>Pesquisar equipamento</h3>
            <div class="linha2">
                <div class="field"><label>Tag</label><input type="text" name="tag_conc" id="inputTagConc" value="<?= htmlspecialchars($tagConc) ?>" style="text-transform:uppercase"></div>
                <div class="field"><label>Número de série</label><input type="text" name="serie_conc" id="inputSerieConc" value="<?= htmlspecialchars($serieConc) ?>" style="text-transform:uppercase"></div>
            </div>
            <div class="actions" style="margin-top:4px"><button type="submit" name="buscar_conc" class="btn-primary" style="flex:0 0 auto;min-width:140px">Buscar</button></div>
        </div>
        <?php if (!empty($naoConciliados)): ?>
        <div class="caixa">
            <h3>Não conciliados com nota (<?= count($naoConciliados) ?>)</h3>
            <input type="text" id="pesquisaNC" class="pesquisa-input" placeholder="Filtrar itens..." style="margin-bottom:10px">
            <div class="tabela-nc-container">
                <table class="tabela-nc" id="tabelaNC">
                    <thead><tr><td>DESCRIÇÃO</td><td>DESC. DETALHADA</td><td>MARCA</td><td>MODELO</td><td>Nº SÉRIE</td><td>TAG PATRIMÔNIO</td><td>TAG NOVA</td><td>NOTA FISCAL</td></tr></thead>
                    <tbody>
                    <?php foreach ($naoConciliados as $nc): ?>
                    <tr data-id="<?= $nc['id'] ?>" data-descricao="<?= htmlspecialchars($nc['descricao']??'') ?>" data-marca="<?= htmlspecialchars($nc['marca']??'') ?>" data-modelo="<?= htmlspecialchars($nc['modelo']??'') ?>" data-serie="<?= htmlspecialchars($nc['serie']??'') ?>" data-tag="<?= htmlspecialchars($nc['tag_trocada']?:($nc['tag_antiga']??'')) ?>">
                        <td><?= htmlspecialchars($nc['descricao']??'') ?></td><td><?= htmlspecialchars($nc['descricao_detalhada']??'') ?></td>
                        <td><?= htmlspecialchars($nc['marca']??'') ?></td><td><?= htmlspecialchars($nc['modelo']??'') ?></td>
                        <td><?= htmlspecialchars($nc['serie']??'') ?></td><td><?= htmlspecialchars($nc['tag_antiga']??'') ?></td>
                        <td><?= htmlspecialchars($nc['tag_trocada']??'') ?></td><td><?= htmlspecialchars($nc['nota_fiscal']??'') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <div class="caixa">
            <h3>Dados do equipamento</h3>
            <input type="hidden" name="id_conc" id="hiddenIdConc" value="<?= htmlspecialchars($dadosConc['id']??'') ?>">
            <div class="linha2">
                <div class="field"><label>Descrição</label><input type="text" id="descricaoEq" value="<?= htmlspecialchars($dadosConc['descricao']??'') ?>" disabled></div>
                <div class="field"><label>Marca</label><input type="text" id="marcaEq" value="<?= htmlspecialchars($dadosConc['marca']??'') ?>" disabled></div>
            </div>
            <div class="linha2">
                <div class="field"><label>Modelo</label><input type="text" id="modeloEq" value="<?= htmlspecialchars($dadosConc['modelo']??'') ?>" disabled></div>
                <div class="field"><label>Número de série</label><input type="text" name="serie_exibida_conc" id="serieEq" value="<?= htmlspecialchars($dadosConc['serie']??'') ?>" readonly></div>
            </div>
            <div class="field" style="max-width:50%"><label>Tag</label><input type="text" name="tag_exibida_conc" id="tagEq" value="<?= htmlspecialchars($dadosConc['tag_trocada']??$dadosConc['tag_antiga']??'') ?>" readonly></div>
        </div>
        <div class="caixa">
            <h3>Dados da nota fiscal</h3>
            <?php if ($jaConc): ?>
            <div style="color:#dc2626;font-weight:700;font-size:13px;margin-bottom:14px;padding:8px 12px;background:#fee2e2;border:1px solid #fecaca;border-radius:8px;" id="avisoJaConc">&#9888; Item Já Conciliado!</div>
            <?php else: ?>
            <div style="display:none;color:#dc2626;font-weight:700;font-size:13px;margin-bottom:14px;padding:8px 12px;background:#fee2e2;border:1px solid #fecaca;border-radius:8px;" id="avisoJaConc">&#9888; Item Já Conciliado!</div>
            <?php endif; ?>
            <input type="hidden" name="id_nota_conc" id="hiddenIdNota" value="<?= htmlspecialchars((string)($idNotaExist??'')) ?>">
            <div class="linha2">
                <div class="field"><label>Centro de custo unidade</label><input type="text" name="centro_custo_unidade" id="fCCU" value="<?= htmlspecialchars($concFields['centro_custo_unidade']) ?>" style="text-transform:uppercase"></div>
                <div class="field"><label>Centro de custo setor</label><input type="text" name="centro_custo_setor" id="fCCS" value="<?= htmlspecialchars($concFields['centro_custo_setor']) ?>" style="text-transform:uppercase"></div>
            </div>
            <div class="linha2">
                <div class="field"><label>Unidade atribuída</label><input type="text" name="unidade_atribuida" id="fUA" value="<?= htmlspecialchars($concFields['unidade_atribuida']) ?>" style="text-transform:uppercase"></div>
                <div class="field"><label>Setor atribuído</label><input type="text" name="setor_atribuido" id="fSA" value="<?= htmlspecialchars($concFields['setor_atribuido']) ?>" style="text-transform:uppercase"></div>
            </div>
            <div class="linha2">
                <div class="field"><label>Número da nota</label><input type="text" name="numero_nota" id="fNN" value="<?= htmlspecialchars($concFields['numero_nota']) ?>"></div>
                <div class="field"><label>Fornecedor</label><input type="text" name="fornecedor" id="fForn" value="<?= htmlspecialchars($concFields['fornecedor']) ?>" style="text-transform:uppercase"></div>
            </div>
            <div class="linha2">
                <div class="field"><label>CNPJ fornecedor</label><input type="text" name="cnpj" id="fCNPJ" value="<?= htmlspecialchars($concFields['cnpj']) ?>"></div>
                <div class="field"><label>Data de aquisição</label><input type="date" name="data" id="fData" value="<?= htmlspecialchars($concFields['data']) ?>"></div>
            </div>
            <div class="linha2">
                <div class="field"><label>Valor da nota</label>
                    <input type="text" name="valor_nota" id="fVN" class="moeda" placeholder="R$ 0,00"
                           value="<?= htmlspecialchars(exibirMoeda($concFields['valor_nota'])) ?>"></div>
                <div class="field"><label>Valor unitário</label>
                    <input type="text" name="valor_item" id="fVI" class="moeda" placeholder="R$ 0,00"
                           value="<?= htmlspecialchars(exibirMoeda($concFields['valor_item'])) ?>"></div>
            </div>
            <div class="field"><label>PDF da nota fiscal</label><input type="file" name="pdf_conc" id="fPdf" accept="application/pdf"></div>
        </div>
        <div class="actions">
            <button type="button" onclick="location.href='inicial.php'" class="btn-secondary">Voltar</button>
            <button type="button" onclick="limparConc()" class="btn-secondary">Limpar</button>
            <button type="submit" name="salvar_conc" class="btn-primary">Salvar Conciliação</button>
        </div>
    </form>
</div>

<!-- ══ DOCUMENTOS ══ -->
<div class="card">
    <div class="card-title">
        <h2>Documentos</h2>
        <span class="counter-badge"><?= $total_registros ?> registro<?= $total_registros !== 1 ? 's' : '' ?></span>
    </div>
    <?php if ($msgDoc): ?><div class="msg <?= htmlspecialchars($tipoMsgDoc) ?>"><?= htmlspecialchars($msgDoc) ?></div><?php endif; ?>

    <form method="POST" enctype="multipart/form-data" action="documentoss.php" id="formAdicionar">
        <div class="caixa">
            <h3>Novo documento</h3>
            <div class="linha2">
                <div class="field"><label>Tipo de documento *</label><input type="text" name="tipo_doc" placeholder="Ex: Nota Fiscal"></div>
                <div class="field"><label>Título do documento</label><input type="text" name="titulo_doc" placeholder="Descrição resumida"></div>
            </div>
            <div class="linha2">
                <div class="field"><label>Data de referência</label><input type="date" name="dt_referencia"></div>
                <div class="field"><label>Número do documento</label><input type="text" name="numero_nota_doc" placeholder="N° da nota / documento"></div>
            </div>
            <div class="linha2">
                <div class="field"><label>Número de série</label><input type="text" name="numero_serie_doc"></div>
                <div class="field"><label>Tag patrimônio</label><input type="text" name="tag_patrimonio_doc"></div>
            </div>
            <div class="field"><label>Arquivo (PDF, PNG, JPG, GIF, WEBP) *</label>
                <input type="file" name="arquivo" accept="application/pdf,image/png,image/jpeg,image/gif,image/webp"></div>
            <div class="actions">
                <button type="reset" class="btn-secondary">Limpar</button>
                <button type="submit" name="adicionar" class="btn-primary">Adicionar</button>
            </div>
        </div>
    </form>

    <div class="caixa">
        <h3>Pesquisa</h3>
        <div class="field"><label>Filtrar registros</label>
            <input type="text" id="campoPesquisa" class="pesquisa-input" placeholder="Digite para filtrar por qualquer campo..."></div>
    </div>

    <div class="caixa">
        <h3>Documentos cadastrados</h3>
        <div class="hint">1 clique = selecionar &nbsp;|&nbsp; 2 cliques = editar &nbsp;|&nbsp; Borda laranja = alteração pendente</div>
        <div class="table-container">
            <table class="docs" id="tabelaDocs">
                <thead><tr>
                    <td style="width:40px">#</td>
                    <td style="width:130px">Tipo Doc</td>
                    <td style="width:170px">Título</td>
                    <td style="width:100px">Data</td>
                    <td style="width:120px">N° Doc</td>
                    <td style="width:110px">N° Série</td>
                    <td style="width:120px">Tag Patrimônio</td>
                    <td style="width:100px">Arquivo</td>
                </tr></thead>
                <tbody>
                <?php foreach ($resultados as $row): ?>
                <tr data-id="<?= $row['id'] ?>" data-dirty="0">
                    <td style="color:#94a3b8;font-size:11px;text-align:center"><?= $row['id'] ?></td>
                    <td><input class="cel-input" type="text" data-campo="tipo_doc"       value="<?= htmlspecialchars($row['tipo_doc']??'') ?>"></td>
                    <td><input class="cel-input" type="text" data-campo="titulo_doc"     value="<?= htmlspecialchars($row['titulo_doc']??'') ?>"></td>
                    <td><input class="cel-input data" type="text" data-campo="dt_referencia" maxlength="10" placeholder="SEM DATA"
                               value="<?= ($row['dt_referencia']&&$row['dt_referencia']!=='0000-00-00') ? date('d/m/Y',strtotime($row['dt_referencia'])) : '' ?>"></td>
                    <td><input class="cel-input" type="text" data-campo="numero_nota"    value="<?= htmlspecialchars($row['numero_nota']??'') ?>"></td>
                    <td><input class="cel-input" type="text" data-campo="numero_serie"   value="<?= htmlspecialchars($row['numero_serie']??'') ?>"></td>
                    <td><input class="cel-input" type="text" data-campo="tag_patrimonio" value="<?= htmlspecialchars($row['tag_patrimonio']??'') ?>"></td>
                    <td>
                        <?php $mime=$row['mime_type']??'application/pdf'; $icone=str_starts_with($mime,'image/')?'IMG':'PDF'; ?>
                        <a href="visualizar_pdf.php?id=<?= $row['id'] ?>" target="_blank">
                            <button type="button" class="btn-abrir"><?= $icone ?> Abrir</button>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="actions">
            <button type="button" onclick="location.href='inicial.php'" class="btn-secondary">Voltar</button>
            <button type="button" id="btnExcluir" class="btn-danger" onclick="confirmarExclusao()" disabled>Excluir</button>
            <button type="button" id="btnSalvar" onclick="salvarAlteracoes()" class="btn-primary">Salvar Alterações</button>
        </div>
    </div>
</div>

</div><!-- /page-wrapper -->

<script>
function aplicarMascara(el) {
    let digits = el.value.replace(/\D/g, '');
    if (digits === '') { el.value = ''; return; }
    let num = parseInt(digits, 10);
    let inteira = Math.floor(num / 100);
    let decimal = num % 100;
    let inteiraStr = inteira.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    let decimalStr = decimal.toString().padStart(2, '0');
    el.value = 'R$ ' + inteiraStr + ',' + decimalStr;
}
function valorParaBanco(el) {
    return el.value.replace(/^R\$\s*/, '').trim();
}
document.querySelectorAll('.moeda').forEach(function(el) {
    el.addEventListener('input', function() {
        aplicarMascara(el);
        el.selectionStart = el.selectionEnd = el.value.length;
    });
    el.addEventListener('blur', function() {
        if (el.value === 'R$ 0,00') el.value = '';
    });
});
document.getElementById('formConc').addEventListener('submit', function() {
    document.querySelectorAll('#formConc .moeda').forEach(function(el) {
        el.value = valorParaBanco(el);
    });
});

function mostrarToast(msg, tipo){
    const t = document.getElementById('toastDoc');
    t.textContent = msg;
    t.classList.remove('visivel','erro-toast');
    if (tipo === 'erro') t.classList.add('erro-toast');
    requestAnimationFrame(() => t.classList.add('visivel'));
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('visivel'), 4500);
}

function limparConc(){
    ['inputTagConc','inputSerieConc','hiddenIdConc','hiddenIdNota','descricaoEq','marcaEq',
     'modeloEq','serieEq','tagEq','fCCU','fCCS','fUA','fSA','fNN','fForn','fCNPJ','fData','fVN','fVI','fPdf'
    ].forEach(id => { const el = document.getElementById(id); if(el) el.value=''; });
    document.getElementById('avisoJaConc').style.display = 'none';
    document.querySelectorAll('#tabelaNC tbody tr').forEach(r => r.classList.remove('selecionada-nc'));
}

const pesquisaNC = document.getElementById('pesquisaNC');
if (pesquisaNC) {
    pesquisaNC.addEventListener('keyup', function(){
        const t = this.value.toLowerCase();
        document.querySelectorAll('#tabelaNC tbody tr').forEach(tr => {
            tr.style.display = tr.innerText.toLowerCase().includes(t) ? '' : 'none';
        });
    });
}

document.querySelectorAll('#tabelaNC tbody tr').forEach(tr => {
    tr.addEventListener('click', function(){
        document.querySelectorAll('#tabelaNC tbody tr').forEach(r => r.classList.remove('selecionada-nc'));
        this.classList.add('selecionada-nc');
        const d = this.dataset;
        document.getElementById('hiddenIdConc').value = d.id;
        document.getElementById('hiddenIdNota').value = '';
        document.getElementById('descricaoEq').value  = d.descricao;
        document.getElementById('marcaEq').value      = d.marca;
        document.getElementById('modeloEq').value     = d.modelo;
        document.getElementById('serieEq').value      = d.serie;
        document.getElementById('tagEq').value        = d.tag;
        document.getElementById('avisoJaConc').style.display = 'none';
    });
});

let linhaSelecionada = null;

document.querySelectorAll('#tabelaDocs tbody tr').forEach(function(tr){
    tr.addEventListener('click', function(e){
        if (e.target.classList.contains('cel-input') && e.target.classList.contains('editando')) return;
        if (linhaSelecionada && linhaSelecionada !== tr){
            linhaSelecionada.classList.remove('selecionado');
            linhaSelecionada.querySelectorAll('.cel-input.editando').forEach(i => i.classList.remove('editando'));
        }
        linhaSelecionada = tr;
        tr.classList.toggle('selecionado');
        document.getElementById('btnExcluir').disabled = !tr.classList.contains('selecionado');
    });
    tr.querySelectorAll('td').forEach(function(td){
        td.addEventListener('dblclick', function(e){
            const inp = td.querySelector('.cel-input');
            if (!inp) return;
            if (linhaSelecionada !== tr){
                if (linhaSelecionada) linhaSelecionada.classList.remove('selecionado');
                linhaSelecionada = tr; tr.classList.add('selecionado');
                document.getElementById('btnExcluir').disabled = false;
            }
            inp.classList.add('editando'); inp.focus(); e.stopPropagation();
        });
    });
    tr.querySelectorAll('.cel-input').forEach(inp => {
        inp.addEventListener('input', function(){
            tr.dataset.dirty = '1';
            tr.classList.add('modificado');
        });
    });
});

document.addEventListener('click', function(e){
    if (!e.target.closest('#tabelaDocs') && linhaSelecionada){
        linhaSelecionada.classList.remove('selecionado');
        linhaSelecionada.querySelectorAll('.cel-input.editando').forEach(i => i.classList.remove('editando'));
        linhaSelecionada = null;
        document.getElementById('btnExcluir').disabled = true;
    }
});

async function salvarAlteracoes(){
    const linhasSujas = Array.from(document.querySelectorAll('#tabelaDocs tbody tr[data-dirty="1"]'));
    if (linhasSujas.length === 0) { mostrarToast('Nenhuma alteração para salvar.', 'info'); return; }
    const btn = document.getElementById('btnSalvar');
    btn.disabled = true; btn.textContent = 'Salvando...';
    const linhas = linhasSujas.map(tr => {
        const g = c => (tr.querySelector(`input[data-campo="${c}"]`) || {}).value || '';
        return {
            id: tr.dataset.id, tipo_doc: g('tipo_doc'), titulo_doc: g('titulo_doc'),
            dt_referencia: g('dt_referencia'), numero_nota: g('numero_nota'),
            numero_serie: g('numero_serie'), tag_patrimonio: g('tag_patrimonio'),
        };
    });
    try {
        const fd = new FormData();
        fd.append('acao', 'salvar_linhas');
        fd.append('linhas', JSON.stringify(linhas));
        const resp = await fetch('salvar_docs_ajax.php', { method:'POST', body:fd, credentials:'same-origin' });
        const texto = await resp.text();
        let json;
        try { json = JSON.parse(texto); }
        catch(e) { console.error('Resposta não-JSON:', texto); mostrarToast('Erro do servidor.', 'erro'); return; }
        if (json.ok) {
            linhasSujas.forEach(tr => {
                tr.dataset.dirty = '0';
                tr.classList.remove('modificado','selecionado');
                tr.classList.add('salvo');
                setTimeout(() => tr.classList.remove('salvo'), 2500);
            });
            mostrarToast(json.msg, 'sucesso');
        } else {
            mostrarToast(json.msg || 'Erro ao salvar.', 'erro');
        }
    } catch(err) {
        console.error('Erro fetch:', err);
        mostrarToast('Falha na comunicação com o servidor.', 'erro');
    } finally {
        btn.disabled = false; btn.textContent = 'Salvar Alterações';
    }
}

function confirmarExclusao(){
    if (!linhaSelecionada) return;
    const id   = linhaSelecionada.dataset.id;
    const tipo = (linhaSelecionada.querySelector('input[data-campo="tipo_doc"]') || {}).value || 'ID '+id;
    document.getElementById('modalTexto').textContent = 'Excluir permanentemente o registro "'+tipo+'" (ID '+id+')?';
    document.getElementById('inputIdExcluir').value   = id;
    document.getElementById('modalExcluir').classList.add('ativo');
}
function fecharModal(){ document.getElementById('modalExcluir').classList.remove('ativo'); }
document.getElementById('modalExcluir').addEventListener('click', function(e){ if(e.target===this) fecharModal(); });

document.getElementById('campoPesquisa').addEventListener('input', function(){
    const n = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
    const t = n(this.value.trim());
    document.querySelectorAll('#tabelaDocs tbody tr').forEach(tr => {
        if (!t){ tr.style.display=''; return; }
        tr.style.display = Array.from(tr.querySelectorAll('.cel-input')).map(i=>n(i.value)).join(' ').includes(t) ? '' : 'none';
    });
});

document.querySelectorAll('.data').forEach(function(c){
    c.addEventListener('focus', () => { if(!c.value) c.placeholder=''; });
    c.addEventListener('blur',  () => { if(!c.value) c.placeholder='SEM DATA'; });
    c.addEventListener('input', function(){
        let v = c.value.replace(/\D/g,'');
        if (v.length>2) v=v.slice(0,2)+'/'+v.slice(2);
        if (v.length>5) v=v.slice(0,5)+'/'+v.slice(5);
        c.value = v.slice(0,10);
    });
});

(function hb(){
    fetch('heartbeat.php?_='+Date.now(),{method:'POST',credentials:'same-origin',cache:'no-store'})
    .then(r=>r.json()).then(d=>{ if(d.revogada) location.href='index.html?error=Sua+sessao+foi+encerrada'; }).catch(()=>{});
    setTimeout(hb,30000);
})();
</script>
</body>
</html>