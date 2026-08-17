<?php
session_start();
require_once "conexao.php";
require_once 'check_session.php';

$usuario_logado = $_SESSION['usuario_logado'] ?? '';
if (!$usuario_logado) { header("Location: login.php"); exit(); }

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

if(!isset($_SESSION['msg'])) $_SESSION['msg'] = '';

function formatarCelular($cel){
    $cel = preg_replace('/\D/', '', $cel);
    if(strlen($cel) === 11)
        return "(".substr($cel,0,2).") ".substr($cel,2,5)."-".substr($cel,7,4);
    return $cel;
}

/* EXCLUIR */
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['btnExcluir'])){
    if(!empty($_POST['id_selecionado'])){
        $id = intval($_POST['id_selecionado']);
        $stmt = $conn->prepare("DELETE FROM cadastro_destinatarios WHERE id=?");
        if($stmt){ $stmt->bind_param("i",$id); $stmt->execute() ? $_SESSION['msg']="🗑️ Registro excluído com sucesso!" : $_SESSION['msg']="❌ Erro ao excluir!"; $stmt->close(); }
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit();
}

/* SALVAR CADASTRO */
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['btnSalvarCadastro'])){
    $nome    = strtoupper(trim($_POST['nome']    ?? ""));
    $unidade = strtoupper(trim($_POST['unidade'] ?? ""));
    $setor   = strtoupper(trim($_POST['setor']   ?? ""));
    $email   = strtoupper(trim($_POST['email']   ?? ""));
    $ramal   = strtoupper(trim($_POST['ramal']   ?? ""));
    $celular = formatarCelular($_POST['celular'] ?? "");
    $usuario = $_SESSION['usuario_logado'];
    $periodo = date('Y-m-d H:i:s');
    if($nome && $unidade && $setor){
        $sql  = "INSERT INTO cadastro_destinatarios (periodo,nome,unidade,setor,email,ramal,celular,usuario_cadastro) VALUES (?,?,?,?,?,?,?,?)";
        $stmt = $conn->prepare($sql);
        if($stmt){ $stmt->bind_param("ssssssss",$periodo,$nome,$unidade,$setor,$email,$ramal,$celular,$usuario); $stmt->execute() ? $_SESSION['msg']="✅ Cadastro realizado com sucesso!" : $_SESSION['msg']="❌ Erro ao cadastrar!"; $stmt->close(); }
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit();
}

/* ATUALIZAR */
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['btnSalvarTabela'])){
    if(!empty($_POST['reg'])){
        foreach($_POST['reg'] as $id => $d){
            $sql  = "UPDATE cadastro_destinatarios SET nome=?,unidade=?,setor=?,email=?,ramal=?,celular=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            if($stmt){ $cel=$d['celular'] ?? ''; $stmt->bind_param("ssssssi",$d['nome'],$d['unidade'],$d['setor'],$d['email'],$d['ramal'],$cel,$id); $stmt->execute() ? $_SESSION['msg']="✅ Alterações salvas!" : $_SESSION['msg']="❌ Erro ao salvar!"; $stmt->close(); }
        }
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit();
}

/* BUSCAR */
$lista = [];
$res = $conn->query("SELECT * FROM cadastro_destinatarios ORDER BY id DESC");
while($r = $res->fetch_assoc()) $lista[] = $r;
$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadastro de Destinatários</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;background:linear-gradient(135deg,#001435,#60a5fa);min-height:100vh;padding:16px;display:flex;justify-content:center;align-items:flex-start}

.form-card{background:#fff;padding:24px 20px;border-radius:14px;width:100%;max-width:1400px}

h1{text-align:center;font-size:20px;color:#1e3a8a;margin-bottom:18px}

/* MSG */
.msg{background:#ecfeff;border:1px solid #67e8f9;padding:10px;margin-bottom:16px;border-radius:8px;text-align:center;font-weight:600;font-size:14px}

/* FORM GRID */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:600px){.form-grid{grid-template-columns:1fr}}

.field label{font-weight:700;font-size:12px;color:#334155;display:block;margin-bottom:4px}
.field input{width:100%;padding:9px 10px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:14px;text-transform:uppercase;outline:none;transition:.15s}
.field input:focus{border-color:#3b82f6}

/* ACTIONS */
.actions{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.btn{padding:10px 14px;border:none;border-radius:8px;background:#3b82f6;color:#fff;cursor:pointer;font-weight:700;font-size:13px;flex:1;min-width:90px;transition:.15s}
.btn:hover{background:#2563eb}
.btn-secondary{background:#e5e7eb;color:#111}
.btn-secondary:hover{background:#d1d5db}
.btn-danger{background:#ef4444;color:#fff}
.btn-danger:hover{background:#dc2626}

/* DIVISOR */
.divider{border:none;border-top:2px solid #e2e8f0;margin:22px 0}

/* CARDS MOBILE */
.cards-list{display:none;flex-direction:column;gap:12px;margin-top:4px}
.reg-card{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:14px;cursor:pointer;transition:.15s;position:relative}
.reg-card.selecionado{background:#dbeafe;border-color:#3b82f6}
.reg-card .card-nome{font-weight:800;font-size:14px;color:#1e3a8a;margin-bottom:6px}
.reg-card .card-info{font-size:12px;color:#475569;margin-bottom:3px}
.reg-card .card-info span{font-weight:600;color:#0f172a}
.reg-card .card-inputs{display:none;flex-direction:column;gap:8px;margin-top:12px}
.reg-card.editando .card-inputs{display:flex}
.reg-card.editando .card-view{display:none}
.card-edit-btn{position:absolute;top:12px;right:12px;background:#3b82f6;color:#fff;border:none;border-radius:6px;padding:5px 10px;font-size:11px;font-weight:700;cursor:pointer}

/* TABELA DESKTOP */
.table-wrap{overflow-x:auto;max-height:420px;margin-top:4px}
table{border-collapse:collapse;width:100%;min-width:900px}
th,td{border:1px solid #e2e8f0;padding:7px 10px;text-align:center;font-size:13px}
th{background:#1e40af;color:#fff;position:sticky;top:0;font-size:12px}
tr.selecionada{background:#dbeafe}
td input{width:100%;border:none;text-align:center;background:transparent;font-size:13px;padding:2px}
td input:focus{outline:1px solid #3b82f6;border-radius:4px}

/* Responsive switch */
@media(max-width:700px){
    .table-wrap{display:none}
    .cards-list{display:flex}
}
@media(min-width:701px){
    .cards-list{display:none}
    .table-wrap{display:block}
}
</style>
</head>
<body>
<main class="form-card">

<h1>Cadastro de Responsáveis</h1>

<?php if(!empty($_SESSION['msg'])): ?>
<div class="msg"><?= $_SESSION['msg'] ?></div>
<?php unset($_SESSION['msg']); endif; ?>

<!-- FORMULÁRIO DE CADASTRO -->
<form method="POST" autocomplete="off">
    <div class="form-grid">
        <div class="field"><label>Nome *</label><input name="nome" placeholder="Nome completo" required></div>
        <div class="field"><label>Unidade *</label><input name="unidade" placeholder="Unidade" required></div>
        <div class="field"><label>Setor *</label><input name="setor" placeholder="Setor" required></div>
        <div class="field"><label>E-mail</label><input name="email" type="email" placeholder="email@exemplo.com" style="text-transform:none"></div>
        <div class="field"><label>Ramal</label><input name="ramal" placeholder="Ramal"></div>
        <div class="field"><label>Celular</label><input name="celular" id="celular" maxlength="15" placeholder="(00) 00000-0000"></div>
    </div>
    <div class="actions">
        <button type="button" class="btn btn-secondary" onclick="location.href='inicial.php'">Voltar</button>
        <button type="reset" class="btn btn-secondary">Limpar</button>
        <button type="submit" name="btnSalvarCadastro" class="btn">Salvar</button>
    </div>
</form>

<hr class="divider">

<!-- FORMULÁRIO DE EDIÇÃO/EXCLUSÃO -->
<form method="POST" id="formTabela">
<input type="hidden" name="id_selecionado" id="id_selecionado">

<!-- CARDS (mobile) -->
<div class="cards-list">
<?php foreach($lista as $l): ?>
<div class="reg-card" data-id="<?=$l['id']?>" onclick="selecionarCard(this)">
    <button type="button" class="card-edit-btn" onclick="editarCard(event,this)">✏️ Editar</button>
    <div class="card-view">
        <div class="card-nome"><?=htmlspecialchars($l['nome'])?></div>
        <div class="card-info">Unidade: <span><?=htmlspecialchars($l['unidade'])?></span></div>
        <div class="card-info">Setor: <span><?=htmlspecialchars($l['setor'])?></span></div>
        <div class="card-info">E-mail: <span><?=htmlspecialchars($l['email'])?></span></div>
        <div class="card-info">Ramal: <span><?=htmlspecialchars($l['ramal'])?></span> &nbsp;|&nbsp; Celular: <span><?=htmlspecialchars($l['celular'])?></span></div>
    </div>
    <div class="card-inputs">
        <div class="field"><label>Nome</label><input name="reg[<?=$l['id']?>][nome]" value="<?=htmlspecialchars($l['nome'])?>"></div>
        <div class="field"><label>Unidade</label><input name="reg[<?=$l['id']?>][unidade]" value="<?=htmlspecialchars($l['unidade'])?>"></div>
        <div class="field"><label>Setor</label><input name="reg[<?=$l['id']?>][setor]" value="<?=htmlspecialchars($l['setor'])?>"></div>
        <div class="field"><label>E-mail</label><input name="reg[<?=$l['id']?>][email]" value="<?=htmlspecialchars($l['email'])?>" style="text-transform:none"></div>
        <div class="field"><label>Ramal</label><input name="reg[<?=$l['id']?>][ramal]" value="<?=htmlspecialchars($l['ramal'])?>"></div>
        <div class="field"><label>Celular</label><input name="reg[<?=$l['id']?>][celular]" value="<?=htmlspecialchars($l['celular'])?>" class="celular" maxlength="15"></div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- TABELA (desktop) -->
<div class="table-wrap">
<table>
<thead>
<tr><th>Nome</th><th>Unidade</th><th>Setor</th><th>E-mail</th><th>Ramal</th><th>Celular</th></tr>
</thead>
<tbody>
<?php foreach($lista as $l): ?>
<tr data-id="<?=$l['id']?>">
    <td><input name="reg[<?=$l['id']?>][nome]"     value="<?=htmlspecialchars($l['nome'])?>"></td>
    <td><input name="reg[<?=$l['id']?>][unidade]"  value="<?=htmlspecialchars($l['unidade'])?>"></td>
    <td><input name="reg[<?=$l['id']?>][setor]"    value="<?=htmlspecialchars($l['setor'])?>"></td>
    <td><input name="reg[<?=$l['id']?>][email]"    value="<?=htmlspecialchars($l['email'])?>"   style="text-transform:none"></td>
    <td><input name="reg[<?=$l['id']?>][ramal]"    value="<?=htmlspecialchars($l['ramal'])?>"></td>
    <td><input name="reg[<?=$l['id']?>][celular]"  value="<?=htmlspecialchars($l['celular'])?>" class="celular" maxlength="15"></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="actions" style="margin-top:14px">
    <button type="button" class="btn btn-secondary" onclick="location.href='inicial.php'">Voltar</button>
    <button type="submit" name="btnExcluir" class="btn btn-danger"
        onclick="return confirm('Deseja excluir o registro selecionado?')">Excluir</button>
    <button type="submit" name="btnSalvarTabela" class="btn">Salvar Alterações</button>
</div>

</form>
</main>

<script>
function maskCel(v){
    v=v.replace(/\D/g,'');
    v=v.replace(/^(\d{2})(\d)/g,'($1) $2');
    v=v.replace(/(\d{5})(\d)/,'$1-$2');
    return v;
}
document.getElementById('celular').addEventListener('input',function(){ this.value=maskCel(this.value); });
document.querySelectorAll('.celular').forEach(c=>c.addEventListener('input',function(){ this.value=maskCel(this.value); }));

document.querySelectorAll('tbody tr').forEach(tr=>{
    tr.addEventListener('click',function(){
        document.querySelectorAll('tbody tr').forEach(r=>r.classList.remove('selecionada'));
        this.classList.add('selecionada');
        document.getElementById('id_selecionado').value=this.dataset.id;
    });
    tr.querySelectorAll('input').forEach(inp=>{
        inp.setAttribute('readonly',true);
        inp.addEventListener('dblclick',function(){ this.removeAttribute('readonly'); this.focus(); });
        inp.addEventListener('blur',function(){ this.setAttribute('readonly',true); });
    });
});

function selecionarCard(card){
    document.querySelectorAll('.reg-card').forEach(c=>c.classList.remove('selecionado'));
    card.classList.add('selecionado');
    document.getElementById('id_selecionado').value=card.dataset.id;
}

function editarCard(e, btn){
    e.stopPropagation();
    const card=btn.closest('.reg-card');
    selecionarCard(card);
    const editando=card.classList.toggle('editando');
    btn.textContent=editando?'✔ Concluir':'✏️ Editar';
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
    setTimeout(hb, 30000);
})();
</script>
</body>
</html>