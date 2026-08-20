<?php
// Erros não são exibidos ao usuário em produção: a mensagem do PHP revela
// caminho do servidor, nome de tabela e trecho de SQL. Continuam sendo
// registrados e ficam visíveis no painel DEV → Erros (ver dev_captura.php).
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();
require_once "conexao.php";
require_once __DIR__ . '/upload_seguro.php';
require_once 'check_session.php';


$usuario_logado = $_SESSION['usuario_logado'] ?? '';
if (!$usuario_logado) {
    header("Location: login.php");
    exit();
}

$stmt = $conn->prepare("SELECT permicao, classe_usuario FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $usuario_logado);
$stmt->execute();
$res = $stmt->get_result();
$permicao       = '';
$classe_usuario = '';
if ($row = $res->fetch_assoc()) {
    $permicao       = $row['permicao'];
    $classe_usuario = $row['classe_usuario'];
}
$stmt->close();

// Alteração 1: nível A ou B + classe_usuario DEV ou PATRIMONIO
if (!in_array($permicao, ['A','B']) || !in_array($classe_usuario, ['DEV','PATRIMONIO'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}

$dados       = null;
$existe_pdf  = false;
$mensagem    = "";
$tipoMensagem= "";
$tag         = $_POST['tag']          ?? '';
$serie       = $_POST['numero_serie'] ?? '';
$usuarioLogado = $_SESSION['usuario_logado'] ?? '';

$concFields = [
    'centro_custo_unidade'=>'','centro_custo_setor'=>'',
    'unidade_atribuida'=>'','setor_atribuido'=>'',
    'numero_nota'=>'','fornecedor'=>'','cnpj'=>'',
    'data'=>'','valor_nota'=>'','valor_item'=>'',
];

/* ============================
   HELPERS
============================ */
function esc($conn, $v){ return $conn->real_escape_string((string)($v ?? '')); }
function nullSeVazio($v){ return ($v === '' || $v === null) ? null : $v; }

/* ============================
   BUSCA POR TAG / SÉRIE
============================ */
if(isset($_POST['buscar'])){
    $tag   = trim($_POST['tag']          ?? '');
    $serie = trim($_POST['numero_serie'] ?? '');

    if($tag === '' && $serie === ''){
        $mensagem = "Informe TAG ou Número de Série.";
        $tipoMensagem = "erro";
    } else {
        $dadosTag = $dadosSerie = null;

        if($tag !== ''){
            $r = $conn->query("SELECT * FROM cadastro WHERE tag_antiga='".esc($conn,$tag)."' OR tag_trocada='".esc($conn,$tag)."' LIMIT 1");
            if($r && $r->num_rows > 0) $dadosTag = $r->fetch_assoc();
        }
        if($serie !== ''){
            $r = $conn->query("SELECT * FROM cadastro WHERE serie='".esc($conn,$serie)."' LIMIT 1");
            if($r && $r->num_rows > 0) $dadosSerie = $r->fetch_assoc();
        }

        if($dadosTag && $dadosSerie){
            if($dadosTag['id'] != $dadosSerie['id']){ $mensagem="Tag e Série não correspondem ao mesmo registro."; $tipoMensagem="erro"; }
            else { $dados = $dadosTag; }
        } elseif($dadosTag)  { $dados = $dadosTag; }
        elseif($dadosSerie)  { $dados = $dadosSerie; }
        else { $mensagem="Nenhum registro encontrado."; $tipoMensagem="erro"; }

        if($dados){
            $tb = (string)($dados['tag_trocada'] ?: $dados['tag_antiga']);
            $sb = (string)($dados['serie'] ?? '');
            $r = $conn->query("SELECT id FROM nota WHERE tag_patrimonio='".esc($conn,$tb)."' OR numero_serie='".esc($conn,$sb)."' LIMIT 1");
            if($r && $r->num_rows > 0) $existe_pdf = true;
        }
    }
}

/* ============================
   BUSCAR LISTA NÃO CONCILIADOS
============================ */
$naoConciliados = [];
$r = $conn->query("SELECT id,descricao,descricao_detalhada,marca,modelo,serie,tag_antiga,tag_trocada,nota_fiscal FROM cadastro WHERE conciliado='NAO' ORDER BY id DESC");
while($row = $r->fetch_assoc()) $naoConciliados[] = $row;

/* ============================
   SALVAR
============================ */
if(isset($_POST['salvar'])){
    $id = trim($_POST['id'] ?? '');

    foreach(array_keys($concFields) as $k){
        $concFields[$k] = trim($_POST[$k] ?? '');
    }

    if($id !== '' && $dados === null){
        $r = $conn->query("SELECT * FROM cadastro WHERE id=".(int)$id." LIMIT 1");
        if($r && $r->num_rows > 0){
            $dados  = $r->fetch_assoc();
            $tag    = (string)($dados['tag_trocada'] ?: $dados['tag_antiga']);
            $serie  = (string)($dados['serie'] ?? '');
            $r2 = $conn->query("SELECT id FROM nota WHERE tag_patrimonio='".esc($conn,$tag)."' OR numero_serie='".esc($conn,$serie)."' LIMIT 1");
            if($r2 && $r2->num_rows > 0) $existe_pdf = true;
        }
    }

    if($id === ''){
        $mensagem = 'Selecione um registro antes de salvar.';
        $tipoMensagem = "erro";
    } else {
        $f1  = $concFields['centro_custo_unidade'];
        $f2  = $concFields['centro_custo_setor'];
        $f3  = $concFields['unidade_atribuida'];
        $f4  = $concFields['setor_atribuido'];
        $f5  = $concFields['numero_nota'];
        $f6  = $concFields['fornecedor'];
        $f7  = $concFields['cnpj'];
        $f8  = $concFields['data'];
        $f9  = $concFields['valor_nota'];
        $f10 = $concFields['valor_item'];

        $semDados = ($f1===''&&$f2===''&&$f3===''&&$f4===''&&
                     $f5===''&&$f6===''&&$f7===''&&$f8===''&&
                     $f9===''&&$f10==='') &&
                    (!isset($_FILES['pdf'])||$_FILES['pdf']['error']!=0);

        if($semDados){
            $mensagem = "Digite os dados da nota fiscal para fazer a conciliação";
            $tipoMensagem = "erro";
        } else {
            $tagS   = trim($_POST['tag_exibida']   ?? '');
            $serieS = trim($_POST['serie_exibida'] ?? '');
            $idInt  = (int)$id;

            $dataSQL = ($f8 !== '') ? "'".esc($conn,$f8)."'" : "NULL";

            $sql = "UPDATE cadastro SET
                centro_custo_unidade = '".esc($conn,$f1)."',
                centro_custo_setor   = '".esc($conn,$f2)."',
                unidade_atribuida    = '".esc($conn,$f3)."',
                setor_atribuido      = '".esc($conn,$f4)."',
                nota_fiscal          = '".esc($conn,$f5)."',
                fornecedor_nome      = '".esc($conn,$f6)."',
                fornecedor_cnpj      = '".esc($conn,$f7)."',
                data_aquisicao       = $dataSQL,
                valor_nota           = '".esc($conn,$f9)."',
                valor_item           = '".esc($conn,$f10)."',
                usuario_conciliacao  = '".esc($conn,$usuarioLogado)."',
                conciliado           = 'SIM'
                WHERE id = $idInt";

            if(!$conn->query($sql)){
                $mensagem = "Erro ao salvar: ".$conn->error;
                $tipoMensagem = "erro";
            } else {
                // Tipo lido dos bytes do arquivo, nunca do que o navegador
                // declarou — ver upload_seguro.php. Este ponto não gravava
                // mime_type nenhum, e o anexo ia para o banco sem qualquer
                // conferência de conteúdo.
                $mimePdf = ($_FILES['pdf']['error'] ?? 1) === 0
                         ? up_mime_real($_FILES['pdf'], UP_MIME_DOCUMENTO)
                         : false;

                if ($mimePdf === false && ($_FILES['pdf']['error'] ?? 1) === 0) {
                    $mensagem = "Os dados da nota foram salvos, mas o arquivo anexado "
                              . "não foi aceito. Envie PDF ou imagem, com até 20 MB.";
                    $tipoMensagem = "erro";
                }

                if($mimePdf !== false){
                    $arquivo = file_get_contents($_FILES['pdf']['tmp_name']);
                    $check = $conn->prepare("SELECT id FROM nota WHERE tag_patrimonio=? OR numero_serie=?");
                    $check->bind_param("ss",$tagS,$serieS);
                    $check->execute();
                    $resC = $check->get_result();
                    if($resC->num_rows > 0){
                        $upd = $conn->prepare("UPDATE nota SET nota_fiscal=? WHERE tag_patrimonio=? OR numero_serie=?");
                        $upd->bind_param("bss",$arquivo,$tagS,$serieS);
                        $upd->send_long_data(0,$arquivo);
                        $upd->execute(); $upd->close();
                    } else {
                        $ins = $conn->prepare("INSERT INTO nota (tag_patrimonio,numero_serie,numero_nota,nota_fiscal) VALUES (?,?,?,?)");
                        $ins->bind_param("sssb",$tagS,$serieS,$f5,$arquivo);
                        $ins->send_long_data(3,$arquivo);
                        $ins->execute(); $ins->close();
                    }
                    $check->close();
                }

                // Não sobrescreve o aviso do anexo recusado: os dados da nota
                // foram gravados, mas dizer só "salva com sucesso" faria o
                // usuário acreditar que o arquivo entrou.
                if ($tipoMensagem !== "erro") {
                    $mensagem = "Conciliação salva com sucesso!";
                    $tipoMensagem = "sucesso";
                }
                $concFields = array_fill_keys(array_keys($concFields),'');
                $dados = null;

                $naoConciliados = [];
                $r = $conn->query("SELECT id,descricao,descricao_detalhada,marca,modelo,serie,tag_antiga,tag_trocada,nota_fiscal FROM cadastro WHERE conciliado='NAO' ORDER BY id DESC");
                while($row = $r->fetch_assoc()) $naoConciliados[] = $row;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Conciliação</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*{ box-sizing:border-box; }
body{ margin:0; font-family:Arial,sans-serif; background:linear-gradient(135deg,#001435,#60a5fa); display:flex; justify-content:center; align-items:flex-start; min-height:100vh; padding:30px 15px; }
.form-card{ background:#fff; padding:35px 30px; border-radius:18px; box-shadow:0 15px 40px rgba(0,0,0,.25); width:100%; max-width:950px; }
.form-card h1{ text-align:center; font-size:2rem; margin-bottom:30px; color:#111827; }
.caixa{ border:1px solid #d1d5db; padding:20px; border-radius:14px; margin-bottom:25px; background:#f9fafb; }
.caixa h2{ margin-top:0; margin-bottom:20px; color:#2563eb; font-size:1.1rem; border-bottom:1px solid #e5e7eb; padding-bottom:8px; }
.field{ margin-bottom:22px; width:100%; }
.field label{ display:block; margin-bottom:6px; font-weight:600; color:#111827; font-size:14px; }
.field input{ width:100%; padding:11px 12px; border:1px solid #cbd5e1; border-radius:12px; font-size:14px; outline:none; text-transform:uppercase; }
.field input:focus{ border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,0.2); }
.field input:disabled{ background:#e5e7eb; cursor:not-allowed; }
.linha2{ display:flex; gap:20px; margin-bottom:5px; }
.linha2 .field{ flex:1; margin-bottom:20px; }
.actions{ display:flex; gap:15px; margin-top:15px; }
.btn{ flex:1; padding:12px; border:none; border-radius:12px; font-size:14px; font-weight:600; background:#3b82f6; color:#fff; cursor:pointer; }
.btn:hover{ background:#2563eb; }
.btn-secondary{ background:#e5e7eb; color:#111827; }
.btn-secondary:hover{ background:#d1d5db; }
.msg{ padding:15px; border-radius:12px; margin-bottom:20px; font-weight:600; font-size:14px; }
.msg.erro{ background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
.msg.sucesso{ background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.tabela-nc{ width:100%; border-collapse:collapse; font-size:13px; }
.tabela-nc th{ background:#3b82f6; color:#fff; padding:9px 8px; text-align:left; position:sticky; top:0; }
.tabela-nc td{ border-bottom:1px solid #e5e7eb; padding:8px; white-space:nowrap; }
.tabela-nc tr:hover td{ background:#eff6ff; cursor:pointer; }
.tabela-nc tr.selecionada-nc td{ background:#bfdbfe !important; font-weight:600; }
.tabela-nc-container{ max-height:280px; overflow:auto; border:1px solid #cbd5e1; border-radius:10px; }

.pesquisa-nc{
    width:100%;
    padding:9px 12px;
    border:1px solid #cbd5e1;
    border-radius:10px;
    font-size:14px;
    outline:none;
    margin-bottom:10px;
}
.pesquisa-nc:focus{ border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,0.2); }

@media(max-width:768px){ .linha2{ flex-direction:column; gap:0; } .actions{ flex-direction:column; } }
</style>
</head>
<body>
<div class="form-card">
<h1>Conciliação</h1>

<?php if($mensagem !== ''): ?>
<div class="msg <?= $tipoMensagem ?>"> <?= htmlspecialchars($mensagem) ?> </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">

<!-- BOX 1 — PESQUISA -->
<div class="caixa">
<h2>Pesquisa</h2>
<div class="field"><label>Tag</label><input type="text" name="tag" value="<?= htmlspecialchars($tag) ?>"></div>
<div class="field"><label>Número de série</label><input type="text" name="numero_serie" value="<?= htmlspecialchars($serie) ?>"></div>
<div class="actions"><button type="submit" name="buscar" class="btn">Buscar</button></div>
</div>

<!-- TABELA NÃO CONCILIADOS -->
<?php if(!empty($naoConciliados)): ?>
<div class="caixa">
<h2>Não Conciliados Com Nota (<?= count($naoConciliados) ?>)</h2>

<input type="text" id="pesquisaNC" class="pesquisa-nc" placeholder="Filtrar itens da tabela...">

<div class="tabela-nc-container">
<table class="tabela-nc" id="tabelaNC">
<thead><tr>
    <th>DESCRIÇÃO</th><th>DESC. DETALHADA</th><th>MARCA</th><th>MODELO</th>
    <th>Nº SÉRIE</th><th>TAG PATRIMÔNIO</th><th>TAG NOVA</th><th>NOTA FISCAL</th>
</tr></thead>
<tbody>
<?php foreach($naoConciliados as $nc): ?>
<tr data-id="<?= $nc['id'] ?>"
    data-descricao="<?= htmlspecialchars($nc['descricao'] ?? '') ?>"
    data-marca="<?= htmlspecialchars($nc['marca'] ?? '') ?>"
    data-modelo="<?= htmlspecialchars($nc['modelo'] ?? '') ?>"
    data-serie="<?= htmlspecialchars($nc['serie'] ?? '') ?>"
    data-tag="<?= htmlspecialchars($nc['tag_trocada'] ?: ($nc['tag_antiga'] ?? '')) ?>">
    <td><?= htmlspecialchars($nc['descricao'] ?? '') ?></td>
    <td><?= htmlspecialchars($nc['descricao_detalhada'] ?? '') ?></td>
    <td><?= htmlspecialchars($nc['marca'] ?? '') ?></td>
    <td><?= htmlspecialchars($nc['modelo'] ?? '') ?></td>
    <td><?= htmlspecialchars($nc['serie'] ?? '') ?></td>
    <td><?= htmlspecialchars($nc['tag_antiga'] ?? '') ?></td>
    <td><?= htmlspecialchars($nc['tag_trocada'] ?? '') ?></td>
    <td><?= htmlspecialchars($nc['nota_fiscal'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

<!-- BOX 2 — DADOS DO EQUIPAMENTO -->
<div class="caixa">
<h2>Dados do Equipamento</h2>
<input type="hidden" name="id" value="<?= htmlspecialchars($dados['id'] ?? '') ?>">
<div class="linha2">
<div class="field"><label>Descrição</label><input type="text" id="descricaoEq" value="<?= htmlspecialchars($dados['descricao'] ?? '') ?>" disabled></div>
<div class="field"><label>Marca</label><input type="text" id="marcaEq" value="<?= htmlspecialchars($dados['marca'] ?? '') ?>" disabled></div>
</div>
<div class="linha2">
<div class="field"><label>Modelo</label><input type="text" id="modeloEq" value="<?= htmlspecialchars($dados['modelo'] ?? '') ?>" disabled></div>
<div class="field"><label>Número de Série</label><input type="text" name="serie_exibida" id="serieEq" value="<?= htmlspecialchars($dados['serie'] ?? '') ?>" readonly></div>
</div>
<div class="linha2">
<div class="field"><label>Tag</label><input type="text" name="tag_exibida" id="tagEq" value="<?= htmlspecialchars($dados['tag_trocada'] ?? $dados['tag_antiga'] ?? '') ?>" readonly></div>
</div>
</div>

<!-- BOX 3 — DADOS DA CONCILIAÇÃO -->
<div class="caixa">
<h2>Dados da Conciliação</h2>
<div class="linha2">
<div class="field"><label>Centro de custo unidade</label><input type="text" name="centro_custo_unidade" value="<?= htmlspecialchars($concFields['centro_custo_unidade']) ?>"></div>
<div class="field"><label>Centro de custo setor</label><input type="text" name="centro_custo_setor" value="<?= htmlspecialchars($concFields['centro_custo_setor']) ?>"></div>
</div>
<div class="linha2">
<div class="field"><label>Unidade atribuida</label><input type="text" name="unidade_atribuida" value="<?= htmlspecialchars($concFields['unidade_atribuida']) ?>"></div>
<div class="field"><label>Setor atribuido</label><input type="text" name="setor_atribuido" value="<?= htmlspecialchars($concFields['setor_atribuido']) ?>"></div>
</div>
<div class="linha2">
<div class="field"><label>Número da nota</label><input type="text" name="numero_nota" value="<?= htmlspecialchars($concFields['numero_nota']) ?>"></div>
<div class="field"><label>Fornecedor</label><input type="text" name="fornecedor" value="<?= htmlspecialchars($concFields['fornecedor']) ?>"></div>
</div>
<div class="linha2">
<div class="field"><label>CNPJ fornecedor</label><input type="text" name="cnpj" value="<?= htmlspecialchars($concFields['cnpj']) ?>"></div>
<div class="field"><label>Data aquisição</label><input type="date" name="data" value="<?= htmlspecialchars($concFields['data']) ?>"></div>
</div>
<div class="linha2">
<div class="field"><label>Valor da nota</label><input type="text" name="valor_nota" class="moeda" value="<?= htmlspecialchars($concFields['valor_nota']) ?>"></div>
<div class="field"><label>Valor unitário</label><input type="text" name="valor_item" class="moeda" value="<?= htmlspecialchars($concFields['valor_item']) ?>"></div>
</div>
<div class="field">
<label>PDF da Nota Fiscal</label>
<?php if($existe_pdf): ?><div style="color:red;font-weight:600;margin-bottom:6px;">Já existe uma nota PDF cadastrada</div><?php endif; ?>
<input type="file" name="pdf" accept="application/pdf">
</div>
<div class="actions">
<button type="button" onclick="location.href='inicial.php'" class="btn btn-secondary">Voltar</button>
<button type="reset" class="btn btn-secondary">Limpar</button>
<button type="submit" name="salvar" class="btn">Salvar</button>
</div>
</div>

</form>
</div>

<script>
function formatarMoeda(el){
    let v = el.value.replace(/\D/g,'');
    v = (parseInt(v||0)/100).toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.');
    el.value = 'R$ '+v;
}
document.querySelectorAll('.moeda').forEach(c=>c.addEventListener('input',()=>formatarMoeda(c)));

document.querySelector('form').addEventListener('submit',function(){
    document.querySelectorAll('.moeda').forEach(c=>{
        c.value = c.value.replace('R$','').replace(/\./g,'').replace(',','.').trim();
    });
});

const pesquisaNC = document.getElementById('pesquisaNC');
if(pesquisaNC){
    pesquisaNC.addEventListener('keyup', function(){
        const termo = this.value.toLowerCase();
        document.querySelectorAll('#tabelaNC tbody tr').forEach(tr => {
            tr.style.display = tr.innerText.toLowerCase().includes(termo) ? '' : 'none';
        });
    });
}

document.querySelectorAll('#tabelaNC tbody tr').forEach(tr=>{
    tr.addEventListener('click',function(){
        document.querySelectorAll('#tabelaNC tbody tr').forEach(r=>r.classList.remove('selecionada-nc'));
        this.classList.add('selecionada-nc');
        const d = this.dataset;
        document.querySelector("input[name='id']").value        = d.id;
        document.getElementById('descricaoEq').value            = d.descricao;
        document.getElementById('marcaEq').value                = d.marca;
        document.getElementById('modeloEq').value               = d.modelo;
        document.getElementById('serieEq').value                = d.serie;
        document.getElementById('tagEq').value                  = d.tag;
    });
});
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
    setTimeout(hb, 30000);
})();
</script>
</body>
</html>