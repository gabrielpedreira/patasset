<?php
session_start();
require_once "conexao.php";
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

// Alteração 1: nível A + classe_usuario DEV ou PATRIMONIO
if (!in_array($permicao, ['A']) || !in_array($classe_usuario, ['DEV','PATRIMONIO'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}


/* ===============================
   DADOS DO HISTÓRICO
================================ */
$sql = "
SELECT
    id,
    tarefa,
    unidade,
    responsavel,
    inicio,
    dia,
    stts,
    obs
FROM registro_atividades
ORDER BY id DESC
";

$result = $conn->query($sql);
$linhas = [];
while($row = $result->fetch_assoc()){
    $linhas[] = $row;
}
$conn->close();

/* ===============================
   COLUNAS
================================ */
$colunas = [
    'ID' => 'id',
    'TAREFA' => 'tarefa',
    'UNIDADE' => 'unidade',
    'RESPONSÁVEL' => 'responsavel',
    'INÍCIO' => 'inicio',
    'PRAZO' => 'dia',
    'STATUS' => 'stts',
    'OBSERVAÇÕES' => 'obs'
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Registro de Atividades</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:linear-gradient(135deg,#001435,#60a5fa);
    display:flex;
    justify-content:center;
    padding:30px;
}
.card{
    background:#fff;
    padding:30px;
    border-radius:12px;
    width:95%;
    max-width:1600px;
}
.top-bar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
    margin-bottom:10px;
}
.contador{
    font-size:14px;
    background:#eef2ff;
    border:1px solid #c7d2fe;
    border-radius:8px;
    padding:6px 10px;
    color:#111827;
}
input[type="text"]{
    width: 74%;
    padding:6px 10px;
    border-radius:6px;
    border:1px solid #ccc;
}
.table-container{
    margin-top:10px;
    overflow:auto;
    max-height:550px;
}
table{
    border-collapse:collapse;
    width:100%;
    min-width:1500px;
}
th,td{
    border:1px solid #ddd;
    padding:8px;
    white-space:nowrap;
    font-size:13px;
}
th{
    background:#3b82f6;
    color:#fff;
    position:sticky;
    top:0;
    z-index:2;
}
.filtro{
    width:100%;
    margin-top:6px;
    padding:4px;
    font-size:12px;
}
.btn{
    padding:8px 14px;
    border:none;
    border-radius:6px;
    background:#3b82f6;
    color:white;
    cursor:pointer;
}
.btn:hover{background:#2563eb;}
.pagination{
    margin-top:12px;
    display:flex;
    gap:6px;
    justify-content:center;
    flex-wrap:wrap;
}
</style>
</head>

<body>
<div class="card">

<h2>Registro de Atividades</h2>

<div class="top-bar">
    <span id="contador" class="contador">Exibindo 0 de 0</span>
    <input type="text" id="pesquisa" placeholder="Pesquisar em registros...">
    <button class="btn btn-secondary" onclick="location.href='inicial.php'">Voltar</button>
    <button class="btn" onclick="location.reload()">Atualizar</button>
</div>

<div class="table-container">
<table id="tabela">
<thead>
<tr>
<?php foreach($colunas as $titulo=>$campo): ?>
<th>
    <div><?= $titulo ?></div>
    <select class="filtro" data-col="<?= $campo ?>">
        <option value="">Todos</option>
        <?php
        $vals = array_unique(array_column($linhas, $campo));
        sort($vals);
        foreach($vals as $v){
            if($v !== "" && $v !== null){
                echo "<option>".htmlspecialchars($v)."</option>";
            }
        }
        ?>
    </select>
</th>
<?php endforeach; ?>
</tr>
</thead>
<tbody></tbody>
</table>
</div>

<div class="pagination" id="paginacao"></div>

</div>

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

const dados = <?= json_encode($linhas, JSON_UNESCAPED_UNICODE) ?>;
const colunas = <?= json_encode(array_values($colunas)) ?>;

const tbody = document.querySelector("#tabela tbody");
const paginacao = document.getElementById("paginacao");
const contador = document.getElementById("contador");
const filtros = document.querySelectorAll(".filtro");

let paginaAtual = 1;
const porPagina = 100;

function renderTabela(lista){
    tbody.innerHTML = "";
    lista.forEach(linha=>{
        const tr = document.createElement("tr");
        colunas.forEach(c=>{
            const td = document.createElement("td");
            td.textContent = linha[c] ?? "";
            tr.appendChild(td);
        });
        tbody.appendChild(tr);
    });
}

function renderPaginacao(total){
    paginacao.innerHTML = "";
    const paginas = Math.ceil(total / porPagina);
    for(let i=1;i<=paginas;i++){
        const b = document.createElement("button");
        b.textContent = i;
        b.className = "btn";
        if(i === paginaAtual) b.style.opacity = "0.6";
        b.onclick = ()=>{paginaAtual=i; atualizar()};
        paginacao.appendChild(b);
    }
}

function aplicarFiltros(){
    return dados.filter(linha=>{
        return [...filtros].every(f=>{
            if(!f.value) return true;
            return (linha[f.dataset.col] ?? "") == f.value;
        });
    });
}

function atualizar(){
    const termo = document.getElementById("pesquisa").value.toLowerCase();
    let filtrados = aplicarFiltros();

    if(termo){
        filtrados = filtrados.filter(l =>
            Object.values(l).join(" ").toLowerCase().includes(termo)
        );
    }

    const inicio = (paginaAtual-1)*porPagina;
    const fim = Math.min(inicio + porPagina, filtrados.length);

    renderTabela(filtrados.slice(inicio, fim));
    contador.textContent = `Exibindo ${inicio+1}–${fim} de ${esc(filtrados.length)}`;
    renderPaginacao(filtrados.length);
}

document.getElementById("pesquisa")
    .addEventListener("keyup",()=>{paginaAtual=1;atualizar();});
filtros.forEach(f=>
    f.addEventListener("change",()=>{paginaAtual=1;atualizar();})
);

function voltar(){
    location.href = "inicial.php";
}

atualizar();
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