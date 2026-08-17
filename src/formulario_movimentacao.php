<?php
session_start();
require 'conexao.php';
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
$permicao       = '';
$classe_usuario = '';
$status         = 'ATIVO';
if ($row = $res->fetch_assoc()) {
    $permicao       = $row['permicao'];
    $classe_usuario = $row['classe_usuario'];
    $status         = $row['status'] ?? 'ATIVO';
}
$stmt->close();

// Usuário bloqueado pelo DEV — encerra sessão e redireciona ao login
if ($status !== 'ATIVO') {
    session_destroy();
    header("Location: index.html?erro=bloqueado");
    exit();
}

// Alteração 1: nível A ou B + classe_usuario DEV ou PATRIMONIO
if (!in_array($permicao, ['A','B']) || !in_array($classe_usuario, ['DEV','PATRIMONIO'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}

/* ===============================
   ACEITA ids[] (múltiplos) ou id (legado)
================================ */

$ids_raw = $_GET['ids'] ?? [];

if (empty($ids_raw) && !empty($_GET['id'])) {
    $ids_raw = [$_GET['id']];
}

if (empty($ids_raw)) {
    die("IDs inválidos");
}

$ids = array_map('intval', (array)$ids_raw);
$ids = array_filter($ids, fn($v) => $v > 0);
$ids = array_values($ids);

if (empty($ids)) {
    die("IDs inválidos");
}

/* ===============================
   BUSCAR TODOS OS ITENS
================================ */

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types        = str_repeat('i', count($ids));

$sql = "
SELECT
    id, tag_antiga, tag_trocada, descricao, descricao_detalhada,
    marca, modelo, serie,
    unidade, setor,
    unidade_destino, setor_destino,
    data_movimentacao, obs_movimentacao
FROM cadastro
WHERE id IN ($placeholders)
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$res   = $stmt->get_result();
$itens = [];
while ($row = $res->fetch_assoc()) {
    $itens[] = $row;
}
$stmt->close();

if (empty($itens)) {
    die("Registros não encontrados");
}

/* ===============================
   DADOS COMUNS
================================ */

$primeiro    = $itens[0];

// Origem = parâmetro passado pela movimentação (que agora é a unidade_destino anterior)
// Fallback: unidade_destino do banco → unidade de cadastro
$unidadeOrig = trim($_GET['unidade_orig'] ?? '')
    ?: (!empty($primeiro['unidade_destino']) ? $primeiro['unidade_destino'] : $primeiro['unidade']);
$setorOrig   = trim($_GET['setor_orig']   ?? '')
    ?: (!empty($primeiro['setor_destino'])   ? $primeiro['setor_destino']   : $primeiro['setor']);

$unidadeDest = $primeiro['unidade_destino'];
$setorDest   = $primeiro['setor_destino'];

$obsMov = $primeiro['obs_movimentacao'];

$data = $primeiro['data_movimentacao']
    ? date('d/m/Y', strtotime($primeiro['data_movimentacao']))
    : date('d/m/Y');

$tipo_mov = ($unidadeOrig !== $unidadeDest)
    ? "Transferência entre Unidades"
    : "Empréstimo entre Setores";

/* ===============================
   DESTINATÁRIOS
================================ */

$destinatarios = [];
$res2 = $conn->query("SELECT id, nome, setor, email FROM cadastro_destinatarios ORDER BY nome");
while ($row = $res2->fetch_assoc()) {
    $destinatarios[] = $row;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Termo de Movimentação</title>

<style>
body{
    font-family:Arial,sans-serif;
    background:#f1f5f9;
    padding:30px;
}

.form{
    background:#fff;
    max-width:950px;
    margin:auto;
    padding:40px;
    border:1px solid #000;
}

h2{text-align:center;margin-bottom:25px;}

.linha{display:flex;gap:20px;margin-bottom:15px;}
.campo{flex:1;}

label{font-weight:bold;font-size:14px;}

input,textarea,select{
    width:100%;
    border:none;
    border-bottom:1px solid #000;
    padding:4px;
    font-size:14px;
    outline:none;
    box-sizing:border-box;
}

textarea{border:1px solid #000;resize:none;height:60px;}

/* ── Campos obrigatórios ── */
.campo-obrig>label::after{content:' *';color:#dc2626;font-weight:700;}
.campo-obrig input:not([readonly]),.campo-obrig select{
    border-bottom:2px solid #fca5a5!important;background:#fff8f8;
}
.campo-obrig input:not([readonly]):focus,.campo-obrig select:focus{
    border-bottom:2px solid #dc2626!important;background:#fff;outline:none;
}
.label-obrig::after{content:' *';color:#dc2626;font-weight:700;}

.tbl-itens{
    width:100%;
    border-collapse:collapse;
    font-size:13px;
    margin:10px 0 20px;
}
.tbl-itens th{
    background:#1d4ed8;
    color:#fff;
    padding:7px 8px;
    text-align:left;
}
.tbl-itens td{
    padding:6px 8px;
    border:1px solid #cbd5e1;
}
.tbl-itens tr:nth-child(even){background:#eff6ff;}

.assinatura{
    margin-top:60px;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:40px;
}

.linha-ass{
    border-top:1px solid #000;
    text-align:center;
    padding-top:6px;
    font-size:13px;
}

.btn-print{margin-top:30px;text-align:center;}

button{
    padding:10px 25px;
    border:none;
    background:#2563eb;
    color:white;
    border-radius:6px;
    cursor:pointer;
    font-size:14px;
}
button:hover{background:#1d4ed8;}

.painel-email{
    border:1px solid #3b82f6;
    border-radius:10px;
    padding:15px;
    margin:20px 0;
    background:#eff6ff;
}
.painel-email h3{margin:0 0 12px;color:#1d4ed8;font-size:15px;}

.checkline-email{
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:12px;
    font-weight:600;
    font-size:14px;
}

.campo-manual{display:none;margin-top:10px;}
.campo-manual input{
    border:none;
    border-bottom:1px solid #000;
    width:100%;
    padding:4px;
    font-size:14px;
}

.toast{
    display:none;
    position:fixed;
    top:50%;left:50%;
    transform:translate(-50%,-50%);
    background:#fff;
    border:2px solid #16a34a;
    border-radius:16px;
    padding:36px 48px;
    text-align:center;
    z-index:9999;
    box-shadow:0 8px 32px rgba(0,0,0,.18);
    min-width:320px;
}
.toast .toast-ico{font-size:48px;margin-bottom:12px;}
.toast .toast-titulo{font-size:20px;font-weight:700;color:#15803d;margin-bottom:6px;}
.toast .toast-sub{font-size:14px;color:#374151;}
.toast .toast-barra{
    height:5px;background:#16a34a;border-radius:4px;
    margin-top:20px;width:100%;
    animation:encolher 2s linear forwards;
}
@keyframes encolher{from{width:100%;}to{width:0%}}

.overlay-bloqueio{
    display:none;
    position:fixed;inset:0;
    background:rgba(0,0,0,.35);
    z-index:9998;
}

@media print{
    body{background:white;padding:0;}
    .btn-print{display:none;}
    .painel-email{display:none;}
    .form{border:none;}
    .toast{display:none!important;}
    .overlay-bloqueio{display:none!important;}
}
</style>
</head>
<body>

<div class="overlay-bloqueio" id="overlayBloqueio"></div>
<div class="toast" id="toastSucesso">
    <div class="toast-ico">✅</div>
    <div class="toast-titulo" id="toastTitulo">Operação concluída!</div>
    <div class="toast-sub" id="toastSub">Esta janela será fechada automaticamente.</div>
    <div class="toast-barra"></div>
</div>

<div class="form" id="areaPrint">

<h2>TERMO DE MOVIMENTAÇÃO DE BENS PATRIMONIAIS</h2>

<div class="linha">
    <div class="campo">
        <label>Data da Movimentação</label>
        <input value="<?= htmlspecialchars($data) ?>" readonly>
    </div>
    <div class="campo">
        <label>Tipo de Movimentação</label>
        <input value="<?= htmlspecialchars($tipo_mov) ?>" readonly>
    </div>
</div>

<div class="linha">
    <div class="campo">
        <label>Unidade de Origem</label>
        <input value="<?= htmlspecialchars($unidadeOrig) ?>" readonly>
    </div>
    <div class="campo">
        <label>Setor de Origem</label>
        <input value="<?= htmlspecialchars($setorOrig) ?>" readonly>
    </div>
</div>

<div class="linha">
    <div class="campo">
        <label>Unidade de Destino</label>
        <input value="<?= htmlspecialchars($unidadeDest) ?>" readonly>
    </div>
    <div class="campo">
        <label>Setor de Destino</label>
        <input value="<?= htmlspecialchars($setorDest) ?>" readonly>
    </div>
</div>

<label style="display:block;margin-bottom:6px;">Itens Movimentados</label>
<table class="tbl-itens">
    <thead>
        <tr>
            <th>#</th>
            <th>Tag / Patrimônio</th>
            <th>Descrição</th>
            <th>Marca</th>
            <th>Modelo</th>
            <th>Nº Série</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($itens as $idx => $item):
        $tag = $item['tag_trocada'] ?: $item['tag_antiga'];
    ?>
        <tr>
            <td><?= $idx + 1 ?></td>
            <td><?= htmlspecialchars($tag) ?></td>
            <td><?= htmlspecialchars($item['descricao']) ?></td>
            <td><?= htmlspecialchars($item['marca']) ?></td>
            <td><?= htmlspecialchars($item['modelo']) ?></td>
            <td><?= htmlspecialchars($item['serie']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="linha">
    <div class="campo">
        <label>Quantidade de Itens</label>
        <input value="<?= count($itens) ?>" readonly>
    </div>
    <div class="campo campo-obrig">
        <label>Estado dos Itens</label>
        <input id="estado_item" placeholder="Ex: BOM, REGULAR, RUIM...">
    </div>
</div>

<div class="linha">
    <div class="campo">
        <label>Observações</label>
        <textarea readonly><?= htmlspecialchars($obsMov) ?></textarea>
    </div>
</div>

<div class="linha">
    <div class="campo campo-obrig">
        <label>Responsável que Cede</label>
        <input id="resp_cede" placeholder="Nome completo">
    </div>
</div>

<!-- ====== PAINEL EMAIL ====== -->
<div class="painel-email">
    <h3>📧 Notificação por E-mail</h3>

    <div class="checkline-email">
        <input type="checkbox" id="chkSemEmail">
        <label for="chkSemEmail">Não enviar e-mail ao destinatário (apenas cópia interna)</label>
    </div>

    <div id="blocoSelect">
        <label class="label-obrig" style="font-weight:bold;font-size:14px;">Responsável que Recebe</label>
        <select id="resp_recebe" style="width:100%;border:none;border-bottom:2px solid #fca5a5;padding:4px;font-size:14px;outline:none;background:#fff8f8;">
            <option value="">Selecione...</option>
            <?php foreach ($destinatarios as $dest):
                $texto = $dest['nome'];
                if (!empty($dest['setor'])) $texto .= ' - ' . $dest['setor'];
            ?>
                <option value="<?= $dest['id'] ?>"><?= htmlspecialchars($texto) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div id="blocoManual" class="campo-manual">
        <label class="label-obrig" style="font-weight:bold;font-size:14px;">Nome do Responsável que Recebe (apenas para registro)</label>
        <input type="text" id="resp_recebe_manual" placeholder="Digite o nome..." style="border-bottom:2px solid #fca5a5!important;background:#fff8f8;">
    </div>
</div>

<div class="assinatura">
    <div class="linha-ass">Assinatura do Responsável por Ceder</div>
    <div class="linha-ass">Assinatura da Logística (quando aplicável)</div>
    <div class="linha-ass">Assinatura do Responsável por Receber</div>
    <div class="linha-ass">Coordenadora Geral de Patrimônio</div>
</div>

<div class="btn-print">
    <button type="button" onclick="validarImpressao()">Imprimir</button>
</div>

</div><!-- /form -->

<script>
const todoItens = <?= json_encode(array_map(function($item) {
    return [
        'descricao'           => $item['descricao']           ?? '',
        'descricao_detalhada' => $item['descricao_detalhada'] ?? '',
        'tag'                 => $item['tag_trocada'] ?: $item['tag_antiga'],
        'marca'               => $item['marca']               ?? '',
        'modelo'              => $item['modelo']              ?? '',
        'serie'               => $item['serie']               ?? '',
        'unidade'             => $item['unidade']             ?? '',
        'setor'               => $item['setor']               ?? '',
        'unidade_destino'     => $item['unidade_destino']     ?? '',
        'setor_destino'       => $item['setor_destino']       ?? '',
    ];
}, $itens), JSON_UNESCAPED_UNICODE) ?>;

const dataMov     = <?= json_encode($data) ?>;
const unidadeOrig = <?= json_encode($unidadeOrig) ?>;
const setorOrig   = <?= json_encode($setorOrig) ?>;
const unidadeDest = <?= json_encode($unidadeDest) ?>;
const setorDest   = <?= json_encode($setorDest) ?>;

const chkSemEmail = document.getElementById('chkSemEmail');
const blocoSelect = document.getElementById('blocoSelect');
const blocoManual = document.getElementById('blocoManual');

chkSemEmail.addEventListener('change', () => {
    if (chkSemEmail.checked) {
        blocoSelect.style.display = 'none';
        blocoManual.style.display = 'block';
    } else {
        blocoSelect.style.display = 'block';
        blocoManual.style.display = 'none';
    }
});

function mostrarSucessoEFechar(titulo, sub) {
    document.getElementById('toastTitulo').textContent = titulo;
    document.getElementById('toastSub').textContent    = sub;

    const overlay = document.getElementById('overlayBloqueio');
    const toast   = document.getElementById('toastSucesso');

    overlay.style.display = 'block';
    toast.style.display   = 'block';

    const barra = toast.querySelector('.toast-barra');
    barra.style.animation = 'none';
    void barra.offsetWidth;
    barra.style.animation = 'encolher 2s linear forwards';

    setTimeout(() => window.close(), 2000);
}

function validarImpressao() {
    const estado   = document.getElementById('estado_item').value.trim();
    const cede     = document.getElementById('resp_cede').value.trim();
    const semEmail = chkSemEmail.checked;

    if (!estado || !cede) {
        alert("Preencha os campos obrigatórios:\n- Estado dos Itens\n- Responsável que Cede");
        return;
    }

    if (semEmail) {
        const nomeManual = document.getElementById('resp_recebe_manual').value.trim();
        if (!nomeManual) {
            alert("Digite o nome do responsável que recebe.");
            return;
        }
        window.print();
        enviarEmailInternoApenas(cede, nomeManual);
        return;
    }

    const idDest = document.getElementById('resp_recebe').value;
    if (!idDest) {
        alert("Selecione o responsável que recebe.");
        return;
    }

    const opcao = confirm(
        "Deseja imprimir e enviar e-mail?\n\n" +
        "OK = Imprimir e Enviar E-mail\n" +
        "Cancelar = Apenas Impressão"
    );

    if (opcao) {
        window.print();
        enviarEmail(idDest, cede);
    } else {
        window.print();
        mostrarSucessoEFechar("Formulário impresso!", "Esta janela será fechada automaticamente.");
    }
}

function enviarEmail(idDest, nomeCede) {
    bloquear();

    const fd = new FormData();
    fd.append('resp_recebe_id',  idDest);
    fd.append('responsavel',     nomeCede);
    fd.append('data_mov',        dataMov);
    fd.append('unidade_origem',  unidadeOrig);
    fd.append('setor_origem',    setorOrig);
    fd.append('unidade_destino', unidadeDest);
    fd.append('setor_destino',   setorDest);
    fd.append('itens',           JSON.stringify(todoItens));
    fd.append('apenas_interno',  '0');

    fetch('enviar_email_movimentacao.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(ret => {
            desbloquear();
            if (ret.status === 'sucesso') {
                mostrarSucessoEFechar("E-mail enviado com sucesso!", "Formulário impresso e notificação enviada ao destinatário.");
            } else {
                alert("Erro ao enviar e-mail:\n" + ret.msg);
            }
        })
        .catch(() => { desbloquear(); alert("Erro de conexão."); });
}

function enviarEmailInternoApenas(nomeCede, nomeRecebe) {
    bloquear();

    const fd = new FormData();
    fd.append('resp_recebe_manual', nomeRecebe);
    fd.append('responsavel',        nomeCede);
    fd.append('data_mov',           dataMov);
    fd.append('unidade_origem',     unidadeOrig);
    fd.append('setor_origem',       setorOrig);
    fd.append('unidade_destino',    unidadeDest);
    fd.append('setor_destino',      setorDest);
    fd.append('itens',              JSON.stringify(todoItens));
    fd.append('apenas_interno',     '1');

    fetch('enviar_email_movimentacao.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(ret => {
            desbloquear();
            if (ret.status === 'sucesso') {
                mostrarSucessoEFechar("Cópia interna enviada!", "Formulário impresso e registro interno registrado.");
            } else {
                alert("Erro ao enviar e-mail:\n" + ret.msg);
            }
        })
        .catch(() => { desbloquear(); alert("Erro de conexão."); });
}

function bloquear() {
    document.body.style.opacity       = '0.7';
    document.body.style.pointerEvents = 'none';
}

function desbloquear() {
    document.body.style.opacity       = '1';
    document.body.style.pointerEvents = 'auto';
}
</script>

<script>
/* ── HEARTBEAT — mantém sessão online e detecta deslogamento forçado ── */
(function hb(){
    fetch('heartbeat.php?_=' + Date.now(), {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store'
    })
    .then(r => r.json())
    .then(data => {
        if (data.revogada) {
            window.location.href = 'index.html?error=Sua+sessao+foi+encerrada';
        }
    })
    .catch(() => {});
    setTimeout(hb, 15000);
})();
</script>
</body>
</html>