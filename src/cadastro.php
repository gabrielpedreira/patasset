<?php
// Erros não são exibidos ao usuário em produção: a mensagem do PHP revela
// caminho do servidor, nome de tabela e trecho de SQL. Continuam sendo
// registrados e ficam visíveis no painel DEV → Erros (ver dev_captura.php).
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
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
$permicao       = '';
$classe_usuario = '';
$status         = 'ATIVO';
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

/* ═══════════════════════════════════════════════════════
   AJAX: buscar descrição pela descrição_detalhada
   (tabela descricoes — autopreenchimento do campo descricao)
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'buscar_descricao') {
    header('Content-Type: application/json');
    $dd = strtoupper(trim($_POST['descricao_detalhada'] ?? ''));
    $resultado = '';
    if ($dd !== '') {
        $st = $conn->prepare("SELECT descricao FROM descricoes WHERE descricao_detalhada=? LIMIT 1");
        $st->bind_param("s", $dd);
        $st->execute();
        $r = $st->get_result();
        if ($row = $r->fetch_assoc()) $resultado = $row['descricao'];
        $st->close();
    }
    echo json_encode(['descricao' => $resultado]);
    exit();
}

/* ═══════════════════════════════════════════════════════
   AJAX: verificar se descricao_detalhada está na tabela relacao
   Busca por relacao.descricao = UPPER(descricao_detalhada)
   Retorna grupo, classe, subgrupo se encontrado
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'verificar_relacao') {
    header('Content-Type: application/json');
    $dd = strtoupper(trim($_POST['descricao_detalhada'] ?? ''));
    if ($dd === '') {
        echo json_encode(['encontrado' => false]);
        exit();
    }
    $st = $conn->prepare("SELECT grupo, classe, subgrupo FROM relacao WHERE UPPER(TRIM(descricao))=? LIMIT 1");
    $st->bind_param("s", $dd);
    $st->execute();
    $r = $st->get_result();
    if ($row = $r->fetch_assoc()) {
        echo json_encode([
            'encontrado' => true,
            'grupo'      => $row['grupo'],
            'classe'     => $row['classe'],
            'subgrupo'   => $row['subgrupo'],
        ]);
    } else {
        echo json_encode(['encontrado' => false]);
    }
    $st->close();
    exit();
}

/* ═══════════════════════════════════════════════════════
   SALVAR
═══════════════════════════════════════════════════════ */
$mensagem = '';

$unidade = $setor = $pavimento = $area = '';
$propriedade = $empresa = $tagAlugado = $descricaoDetalhada = $descricao = $marca = $modelo = '';
$serie = $observacao = $notaFiscal = '';
$tagAntiga = $tagTrocada = '';

$limparPosSalvar = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar') {

    function formatarTag($valor) {
        $valor  = strtoupper(trim($valor));
        if ($valor === '') return '';
        $letras  = preg_replace('/\d/', '', $valor);
        $numeros = preg_replace('/\D/', '', $valor);
        if ($numeros !== '') {
            $numeros = str_pad(substr($numeros, -6), 6, '0', STR_PAD_LEFT);
        }
        return $letras . $numeros;
    }

    $unidade            = strtoupper(trim($_POST['unidade']            ?? ''));
    $setor              = strtoupper(trim($_POST['setor']              ?? ''));
    $pavimento          = strtoupper(trim($_POST['pavimento']          ?? ''));
    $area               = strtoupper(trim($_POST['area']               ?? ''));
    $propriedade        = strtoupper(trim($_POST['propriedade']        ?? ''));
    $empresa            = strtoupper(trim($_POST['empresa']            ?? ''));
    $tagAlugado         = formatarTag($_POST['tagAlugado']             ?? '');
    $descricaoDetalhada = strtoupper(trim($_POST['descricaoDetalhada'] ?? ''));
    $descricao          = strtoupper(trim($_POST['descricao']          ?? ''));
    $marca              = strtoupper(trim($_POST['marca']              ?? ''));
    $modelo             = strtoupper(trim($_POST['modelo']             ?? ''));
    $serie              = strtoupper(trim($_POST['numeroSerie']        ?? ''));
    $observacao         = strtoupper(trim($_POST['observacao']         ?? ''));
    $notaFiscal         = strtoupper(trim($_POST['notaFiscal']         ?? ''));
    $tagAntiga          = formatarTag($_POST['tagAntiga']              ?? '');
    $tagTrocada         = formatarTag($_POST['tagTrocada']             ?? '');

    /* campos manuais de classificação — só usados se não encontrou na relacao */
    $grupoManual    = strtoupper(trim($_POST['grupo_manual']    ?? ''));
    $classeManual   = strtoupper(trim($_POST['classe_manual']   ?? ''));
    $subgrupoManual = strtoupper(trim($_POST['subgrupo_manual'] ?? ''));
    // Setor responsável pela MANUTENÇÃO do item. É o que define a visibilidade
    // no LifeTech (antes o critério era o subgrupo).
    $responsavel    = strtoupper(trim($_POST['responsavel'] ?? '')) ?: null;

    $usuarioCad = $_SESSION['usuario_logado'];
    $grupo = $classe = $subgrupo = null;

    /* ── busca classificação na relacao ── */
    if ($descricaoDetalhada !== '') {
        $stmtRel = $conn->prepare(
            "SELECT grupo, classe, subgrupo FROM relacao WHERE UPPER(TRIM(descricao))=? LIMIT 1"
        );
        $stmtRel->bind_param("s", $descricaoDetalhada);
        $stmtRel->execute();
        $resRel = $stmtRel->get_result();

        if ($rowRel = $resRel->fetch_assoc()) {
            /* encontrou na relacao */
            $grupo    = $rowRel['grupo'];
            $classe   = $rowRel['classe'];
            $subgrupo = $rowRel['subgrupo'];
        } else {
            /* NÃO encontrou — usa campos manuais e salva na relacao */
            $grupo    = $grupoManual    ?: null;
            $classe   = $classeManual   ?: null;
            $subgrupo = $subgrupoManual ?: null;

            /* salva na relacao para futuras consultas */
            if ($descricaoDetalhada !== '' && $grupo && $classe && $subgrupo) {
                $stmtIns = $conn->prepare(
                    "INSERT INTO relacao (descricao, grupo, classe, subgrupo) VALUES (?,?,?,?)"
                );
                $stmtIns->bind_param("ssss", $descricaoDetalhada, $grupo, $classe, $subgrupo);
                $stmtIns->execute();
                $stmtIns->close();
            } else {
                $mensagem = "⚠️ Item sem classificação cadastrada — preencha Grupo, Classe e Subgrupo.";
            }
        }
        $stmtRel->close();
    }

    $status  = 'ATIVO';
    $periodo = date('Y-m-d H:i:s');

    $p1  = $status;
    $p2  = $periodo;
    $p3  = $unidade            ?: null;
    $p4  = $setor              ?: null;
    $p5  = $pavimento          ?: null;
    $p6  = $area               ?: null;
    $p7  = $tagAntiga          ?: null;
    $p8  = $tagTrocada         ?: null;
    $p9  = $tagAlugado         ?: null;
    $p10 = $propriedade        ?: null;
    $p11 = $empresa            ?: null;
    $p12 = $descricaoDetalhada ?: null;
    $p13 = $descricao          ?: null;
    $p14 = $marca              ?: null;
    $p15 = $modelo             ?: null;
    $p16 = $serie              ?: null;
    $p17 = $observacao         ?: null;
    $p18 = $notaFiscal         ?: null;
    $p19 = $usuarioCad;
    $p20 = $grupo;
    $p21 = $classe;
    $p22 = $subgrupo;
    $p24 = $responsavel;

    if ($notaFiscal !== '') {
        $p23 = 'NAO';
        $sql = "INSERT INTO cadastro (
            status, periodo,
            unidade, setor, pavimento, area,
            tag_antiga, tag_trocada, tag_alugado,
            propriedade, empresa,
            descricao_detalhada, descricao, marca, modelo,
            serie, observacao, nota_fiscal,
            usuario_cadastro,
            grupo, classe, subgrupo, responsavel, conciliado,
            encontrado
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'SIM')";
        /* encontrado='SIM': todo item recém-cadastrado nasce localizado — ele
           acabou de ser visto e registrado. Vira 'NAO' só quando uma auditoria
           não o encontra. Valor fixo, por isso literal e não bind. */

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            $mensagem = '❌ Erro prepare: ' . $conn->error;
        } else {
            $stmt->bind_param("ssssssssssssssssssssssss",
                $p1,$p2,$p3,$p4,$p5,$p6,$p7,$p8,$p9,$p10,$p11,
                $p12,$p13,$p14,$p15,$p16,$p17,$p18,$p19,$p20,$p21,$p22,$p24,$p23
            );
        }
    } else {
        $sql = "INSERT INTO cadastro (
            status, periodo,
            unidade, setor, pavimento, area,
            tag_antiga, tag_trocada, tag_alugado,
            propriedade, empresa,
            descricao_detalhada, descricao, marca, modelo,
            serie, observacao, nota_fiscal,
            usuario_cadastro,
            grupo, classe, subgrupo, responsavel,
            encontrado
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'SIM')";
        /* encontrado='SIM': item recém-cadastrado nasce localizado (ver ramo
           acima). Literal, pois é valor fixo. */

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            $mensagem = '❌ Erro prepare: ' . $conn->error;
        } else {
            $stmt->bind_param("sssssssssssssssssssssss",
                $p1,$p2,$p3,$p4,$p5,$p6,$p7,$p8,$p9,$p10,$p11,
                $p12,$p13,$p14,$p15,$p16,$p17,$p18,$p19,$p20,$p21,$p22,$p24
            );
        }
    }

    if ($stmt !== false) {
        if ($stmt->execute()) {
            $mensagem        = '✅ Cadastro realizado com sucesso!';
            $limparPosSalvar = true;
        } else {
            $mensagem = '❌ Erro execute: ' . $stmt->error;
        }
        $stmt->close();
    }

    $conn->close();

    if ($limparPosSalvar) {
        $unidade            = strtoupper(trim($_POST['unidade']            ?? ''));
        $setor              = strtoupper(trim($_POST['setor']              ?? ''));
        $pavimento          = strtoupper(trim($_POST['pavimento']          ?? ''));
        $area               = strtoupper(trim($_POST['area']               ?? ''));
        $propriedade        = strtoupper(trim($_POST['propriedade']        ?? ''));
        $empresa            = strtoupper(trim($_POST['empresa']            ?? ''));
        $tagAlugado         = formatarTag($_POST['tagAlugado']             ?? '');
        $descricaoDetalhada = strtoupper(trim($_POST['descricaoDetalhada'] ?? ''));
        $descricao          = strtoupper(trim($_POST['descricao']          ?? ''));
        $marca = $modelo = $serie = $tagAntiga = $tagTrocada = $observacao = $notaFiscal = '';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Cadastro / Inventário</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
body{margin:0;font-family:Arial;background:linear-gradient(135deg,#001435,#60a5fa);display:flex;justify-content:center;padding:20px;}
.form-card{background:#fff;padding:30px 20px;border-radius:16px;box-shadow:0 12px 30px rgba(0,0,0,.25);width:100%;max-width:700px;}
.form-card h1{text-align:center;font-size:2rem;margin-bottom:10px;color:#111827;}
.field{margin-bottom:16px;}
.field label{font-weight:600;display:block;}
.field input,.field select{width:100%;padding:8px;border:1px solid #ccc;border-radius:10px;text-transform:uppercase;box-sizing:border-box;}
#descricao.autopreenchido{background:#eff6ff;border-color:#3b82f6;color:#1e40af;}
.actions{display:flex;gap:8px;}
.btn{flex:1;padding:10px;border:none;border-radius:10px;background:#3b82f6;color:white;}
.btn:hover{background:#2563eb;cursor:pointer;}
.btn-secondary{background:#e5e7eb;color:#111;}
.btn-secondary:hover{background:#d1d5db;cursor:pointer;}
.mensagem{text-align:center;margin-bottom:15px;padding:10px;border-radius:8px;font-weight:bold;}
.sucesso{background:#d1fae5;color:#065f46;}
.erro{background:#fee2e2;color:#991b1b;}

/* ── badge classificação ── */
.badge-class{
    display:none;
    font-size:12px;font-weight:700;
    margin-top:5px;padding:5px 10px;
    border-radius:6px;
}
.badge-class.encontrado{
    display:block;
    background:#dcfce7;color:#166534;
    border:1px solid #bbf7d0;
}
.badge-class.nao-encontrado{
    display:block;
    background:#fee2e2;color:#991b1b;
    border:1px solid #fecaca;
}

/* ── campos manuais de classificação ── */
#camposClassificacao{display:none;}
#camposClassificacao .grid3{
    display:grid;
    grid-template-columns:1fr 1fr 1fr;
    gap:10px;
}
#camposClassificacao .grid3 .field{margin-bottom:0;}
@media(max-width:500px){
    #camposClassificacao .grid3{grid-template-columns:1fr;}
}
</style>
</head>
<body>
<main class="form-card">

<h1>Cadastro / Inventário</h1>

<?php if ($mensagem): ?>
<div class="mensagem <?= strpos($mensagem,'❌') !== false ? 'erro' : 'sucesso' ?>" id="msgFeedback">
    <?= $mensagem ?>
</div>
<?php endif; ?>

<form method="POST" id="formCadastro">
<input type="hidden" name="acao" value="salvar">

<div class="field">
    <label>Unidade</label>
    <input name="unidade" value="<?= htmlspecialchars($unidade) ?>">
</div>

<div class="field">
    <label>Setor</label>
    <input name="setor" value="<?= htmlspecialchars($setor) ?>">
</div>

<div class="field">
    <label>Pavimento</label>
    <input name="pavimento" value="<?= htmlspecialchars($pavimento) ?>">
</div>

<div class="field">
    <label>Área</label>
    <input name="area" value="<?= htmlspecialchars($area) ?>">
</div>

<div class="field">
    <label>Propriedade</label>
    <select name="propriedade" id="propriedade">
        <option value="">Selecione</option>
        <option <?= ($propriedade=='PATRIMONIO')  ? 'selected' : '' ?>>PATRIMONIO</option>
        <option <?= ($propriedade=='COMODATO')    ? 'selected' : '' ?>>COMODATO</option>
        <option <?= ($propriedade=='ALUGADO')     ? 'selected' : '' ?>>ALUGADO</option>
        <option <?= ($propriedade=='EMPRESTADO')  ? 'selected' : '' ?>>EMPRESTADO</option>
    </select>
</div>

<div class="field">
    <label>Empresa</label>
    <input name="empresa" value="<?= htmlspecialchars($empresa) ?>">
</div>

<div class="field">
    <label>Tag Alugado</label>
    <input name="tagAlugado" id="tagAlugado" value="<?= htmlspecialchars($tagAlugado) ?>">
</div>

<div class="field">
    <label>Descrição Detalhada</label>
    <input type="text" name="descricaoDetalhada" id="descricaoDetalhada"
           value="<?= htmlspecialchars($descricaoDetalhada) ?>">
    <!-- badge de classificação -->
    <div class="badge-class" id="badgeClass"></div>
</div>

<!-- campos manuais de classificação — aparecem só se não encontrou na relacao -->
<div id="camposClassificacao">
    <div class="grid3">
        <div class="field">
            <label>Grupo</label>
            <input type="text" name="grupo_manual" id="grupoManual" placeholder="GRUPO">
        </div>
        <div class="field">
            <label>Classe</label>
            <input type="text" name="classe_manual" id="classeManual" placeholder="CLASSE">
        </div>
        <div class="field">
            <label>Subgrupo</label>
            <input type="text" name="subgrupo_manual" id="subgrupoManual" placeholder="SUBGRUPO">
        </div>
        <div class="field">
            <label>Responsável pela manutenção</label>
            <input type="text" name="responsavel" id="responsavel" list="listaResponsaveis"
                   placeholder="EX: ENGENHARIA CLINICA" style="text-transform:uppercase">
            <datalist id="listaResponsaveis">
                <option value="ENGENHARIA CLINICA">
                <option value="HOTELARIA">
                <option value="TI">
                <option value="MANUTENCAO PREDIAL">
                <option value="PATRIMONIO">
            </datalist>
        </div>
    </div>
</div>

<div class="field">
    <label>Descrição</label>
    <input type="text" name="descricao" id="descricao"
           value="<?= htmlspecialchars($descricao) ?>">
</div>

<div class="field">
    <label>Marca</label>
    <input name="marca" value="<?= htmlspecialchars($marca) ?>">
</div>

<div class="field">
    <label>Modelo</label>
    <input name="modelo" value="<?= htmlspecialchars($modelo) ?>">
</div>

<div class="field">
    <label>Nº Série</label>
    <input name="numeroSerie" value="<?= htmlspecialchars($serie) ?>">
</div>

<div class="field">
    <label>Tag Patrimônio</label>
    <input name="tagAntiga" value="<?= htmlspecialchars($tagAntiga) ?>">
</div>

<div class="field">
    <label>Tag Nova Compra</label>
    <input name="tagTrocada" value="<?= htmlspecialchars($tagTrocada) ?>">
</div>

<div class="field">
    <label>Observação</label>
    <input name="observacao" value="<?= htmlspecialchars($observacao) ?>">
</div>

<div class="field">
    <label>Nota Fiscal</label>
    <input name="notaFiscal" id="notaFiscal" value="<?= htmlspecialchars($notaFiscal) ?>">
</div>

<div class="actions">
    <button type="button" class="btn btn-secondary" id="btnVoltar">Voltar</button>
    <button type="button" class="btn btn-secondary" id="btnLimpar">Limpar Campos</button>
    <button type="submit" class="btn">Salvar</button>
</div>

</form>
</main>

<script>
/* ── fade da mensagem de sucesso ── */
const msgEl = document.getElementById('msgFeedback');
if (msgEl && msgEl.classList.contains('sucesso')) {
    setTimeout(() => {
        msgEl.style.transition = 'opacity 0.5s ease';
        msgEl.style.opacity    = '0';
        setTimeout(() => msgEl.remove(), 500);
    }, 3000);
}

document.getElementById('btnVoltar').onclick = () => { window.location = 'inicial.php'; };

document.getElementById('btnLimpar').onclick = () => {
    document.querySelectorAll('input, select').forEach(campo => {
        const nome = campo.name;
        if (!['unidade','setor','pavimento','area','acao'].includes(nome)) {
            campo.value = '';
        }
    });
    document.getElementById('descricao').classList.remove('autopreenchido');
    ocultarBadge();
};

/* ── controle tagAlugado ── */
const propriedadeEl = document.getElementById('propriedade');
const tagAlugadoEl  = document.getElementById('tagAlugado');
function controlarTagAlugado() {
    if (propriedadeEl.value === 'PATRIMONIO') {
        tagAlugadoEl.value    = '';
        tagAlugadoEl.disabled = true;
    } else {
        tagAlugadoEl.disabled = false;
    }
}
propriedadeEl.addEventListener('change', controlarTagAlugado);
controlarTagAlugado();

/* ════════════════════════════════════════════════════
   VERIFICAÇÃO NA RELACAO — badge + campos manuais
════════════════════════════════════════════════════ */
const campoDD      = document.getElementById('descricaoDetalhada');
const campoDesc    = document.getElementById('descricao');
const badgeEl      = document.getElementById('badgeClass');
const camposCls    = document.getElementById('camposClassificacao');
const grupoEl      = document.getElementById('grupoManual');
const classeEl     = document.getElementById('classeManual');
const subgrupoEl   = document.getElementById('subgrupoManual');

let timerDD = null;

function ocultarBadge() {
    badgeEl.className  = 'badge-class';
    badgeEl.textContent = '';
    camposCls.style.display = 'none';
    grupoEl.value = classeEl.value = subgrupoEl.value = '';
    grupoEl.required = classeEl.required = subgrupoEl.required = false;
}

function verificarRelacao(dd) {
    if (!dd) { ocultarBadge(); return; }

    const fd = new FormData();
    fd.append('acao', 'verificar_relacao');
    fd.append('descricao_detalhada', dd);

    fetch('cadastro.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            if (res.encontrado) {
                /* ── encontrou: badge verde, esconde campos manuais ── */
                badgeEl.className   = 'badge-class encontrado';
                badgeEl.textContent = '✔ Item com classificação cadastrada';
                camposCls.style.display = 'none';
                grupoEl.required = classeEl.required = subgrupoEl.required = false;
            } else {
                /* ── não encontrou: badge vermelho, mostra campos manuais ── */
                badgeEl.className   = 'badge-class nao-encontrado';
                badgeEl.textContent = '✘ Item sem classificação cadastrada';
                camposCls.style.display = 'block';
                grupoEl.required = classeEl.required = subgrupoEl.required = true;
            }
        })
        .catch(() => ocultarBadge());
}

campoDD.addEventListener('input', function () {
    clearTimeout(timerDD);
    const val = this.value.trim();
    if (!val) { ocultarBadge(); return; }
    timerDD = setTimeout(() => verificarRelacao(val), 450);
    /* uppercase em tempo real */
    this.value = this.value.toUpperCase();
});

campoDD.addEventListener('blur', function () {
    clearTimeout(timerDD);
    const val = this.value.trim();
    if (val) verificarRelacao(val);
    else ocultarBadge();
});

/* ════════════════════════════════════════════════════
   BUSCA DESCRIÇÃO na tabela descricoes (autopreenchimento)
════════════════════════════════════════════════════ */
let timerDesc = null;

function buscarDescricao() {
    const val = campoDD.value.trim();
    if (val === '') {
        campoDesc.value = '';
        campoDesc.classList.remove('autopreenchido');
        return;
    }
    const dados = new URLSearchParams();
    dados.append('acao', 'buscar_descricao');
    dados.append('descricao_detalhada', val);
    fetch('cadastro.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: dados.toString()
    })
    .then(r => r.json())
    .then(res => {
        if (res.descricao && res.descricao !== '') {
            campoDesc.value = res.descricao;
            campoDesc.classList.add('autopreenchido');
        } else {
            campoDesc.value = val.toUpperCase();
            campoDesc.classList.remove('autopreenchido');
        }
    })
    .catch(() => {
        campoDesc.value = val.toUpperCase();
        campoDesc.classList.remove('autopreenchido');
    });
}

campoDD.addEventListener('input', function () {
    clearTimeout(timerDesc);
    timerDesc = setTimeout(buscarDescricao, 400);
});
campoDD.addEventListener('blur', buscarDescricao);

/* uppercase em todos os inputs de texto */
document.querySelectorAll('input[type="text"], input:not([type])').forEach(inp => {
    inp.addEventListener('input', () => { inp.value = inp.value.toUpperCase(); });
});
</script>

<script>
(function hb(){
    fetch('heartbeat.php?_=' + Date.now(), { method:'POST', credentials:'same-origin', cache:'no-store' })
    .then(r => r.json())
    .then(data => { if (data.revogada) window.location.href = 'index.html?error=Sua+sessao+foi+encerrada'; })
    .catch(() => {});
    setTimeout(hb, 30000);
})();
</script>
</body>
</html>