<?php
session_start();
if(!isset($_SESSION['usuario_logado'])){
    header("Location: index.html");
    exit();
}

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

if (!in_array($permicao, ['A', 'B']) || !in_array($classe_usuario, ['DEV','PATRIMONIO'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}

// Processa inserção
$mensagem     = '';
$tipoMensagem = '';
if (isset($_SESSION['msg_contabil'])) {
    $mensagem     = $_SESSION['msg_contabil'];
    $tipoMensagem = $_SESSION['tipo_msg_contabil'];
    unset($_SESSION['msg_contabil'], $_SESSION['tipo_msg_contabil']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'inserir') {
    $descricao = strtoupper(trim($_POST['descricao'] ?? ''));
    $grupo     = strtoupper(trim($_POST['grupo']     ?? ''));
    $classe    = strtoupper(trim($_POST['classe']    ?? ''));
    $subgrupo  = strtoupper(trim($_POST['subgrupo']  ?? ''));

    if ($descricao && $grupo && $classe && $subgrupo) {
        $stmt = $conn->prepare("INSERT INTO relacao (descricao, grupo, classe, subgrupo) VALUES (?,?,?,?)");
        $stmt->bind_param("ssss", $descricao, $grupo, $classe, $subgrupo);
        if ($stmt->execute()) {
            $_SESSION['msg_contabil']      = "Registro cadastrado com sucesso!";
            $_SESSION['tipo_msg_contabil'] = "sucesso";
        } else {
            $_SESSION['msg_contabil']      = "Erro ao salvar: " . $stmt->error;
            $_SESSION['tipo_msg_contabil'] = "erro";
        }
        $stmt->close();
    } else {
        $_SESSION['msg_contabil']      = "Preencha todos os campos!";
        $_SESSION['tipo_msg_contabil'] = "erro";
    }
    header("Location: contabil.php");
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadastro de Classificação</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;background:linear-gradient(135deg,#001435,#60a5fa);display:flex;justify-content:center;padding:30px;min-height:100vh;}

.card{background:#fff;padding:28px 30px;border-radius:12px;box-shadow:0 8px 20px rgba(0,0,0,0.25);width:95%;max-width:1100px;}

h1{text-align:center;margin-bottom:20px;color:#111827;font-size:1.4rem;}

/* ── MENSAGEM ── */
.msg{padding:10px 16px;border-radius:8px;margin-bottom:16px;font-weight:700;font-size:13px;text-align:center;}
.sucesso{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
.erro   {background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}

/* ── FORM CADASTRO ── */
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr 1fr 1fr;
    gap:12px;
    margin-bottom:14px;
}
.form-grid label{
    display:block;font-size:12px;font-weight:700;
    color:#374151;margin-bottom:4px;
}
.form-grid input{
    width:100%;padding:9px 10px;
    border:1px solid #cbd5e1;border-radius:8px;
    font-size:13px;text-transform:uppercase;
    transition:.2s;
}
.form-grid input:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.15);
    outline:none;
}

.btn-row{
    display:flex;gap:10px;flex-wrap:wrap;
    margin-bottom:20px;
}
.btn{
    padding:9px 20px;border:none;border-radius:8px;
    font-size:13px;font-weight:700;cursor:pointer;
    transition:all .18s;
}
.btn-primary{background:#2563eb;color:#fff;flex:1;}
.btn-primary:hover{background:#1e40af;}
.btn-secondary{background:#e5e7eb;color:#111;}
.btn-secondary:hover{background:#d1d5db;}
.btn-success{background:#16a34a;color:#fff;}
.btn-success:hover{background:#15803d;}
.btn-warning{background:#f59e0b;color:#fff;}
.btn-warning:hover{background:#d97706;}
.btn:disabled{background:#9ca3af;cursor:not-allowed;}

/* ── BARRA PESQUISA + ATUALIZAR ── */
.toolbar{
    display:flex;gap:10px;align-items:center;
    margin-bottom:10px;flex-wrap:wrap;
}
.toolbar input{
    flex:1;min-width:200px;
    padding:8px 12px;border:1px solid #cbd5e1;
    border-radius:8px;font-size:13px;outline:none;
}
.toolbar input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15);}
.contador{
    font-size:13px;background:#eef2ff;
    border:1px solid #c7d2fe;border-radius:8px;
    padding:6px 12px;color:#1e40af;font-weight:700;white-space:nowrap;
}

/* ── TABELA ── */
.table-wrap{overflow-x:auto;border:1px solid #d1d5db;border-radius:8px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:600px;}
thead td{
    background:#2563eb;color:#fff;font-weight:700;
    padding:9px 10px;white-space:nowrap;
    position:sticky;top:0;z-index:2;
}
tbody tr{border-bottom:1px solid #e5e7eb;cursor:pointer;transition:background .1s;}
tbody tr:hover{background:#f0f7ff;}
tbody tr.selecionada{background:#dbeafe !important;outline:2px solid #2563eb;outline-offset:-2px;}
tbody tr.modificada td:first-child{border-left:3px solid #f59e0b;}
tbody tr.salva{background:#dcfce7 !important;}
tbody td{padding:7px 10px;vertical-align:middle;color:#0f172a;}

/* inputs inline na tabela */
tbody td input.cel{
    width:100%;border:none;background:transparent;
    padding:3px 2px;font-size:13px;color:#0f172a;
    cursor:default;pointer-events:none;outline:none;
    text-transform:uppercase;
}
tbody td input.cel.editando{
    background:#eef2ff;border:1px solid #6366f1;
    border-radius:5px;padding:3px 6px;
    cursor:text;pointer-events:all;
}

/* ── PAGINAÇÃO ── */
.paginacao{
    display:flex;gap:6px;justify-content:center;
    align-items:center;flex-wrap:wrap;margin-bottom:14px;
}
.paginacao button{
    padding:6px 12px;border:none;border-radius:6px;
    background:#2563eb;color:#fff;font-size:13px;
    font-weight:700;cursor:pointer;transition:.15s;
}
.paginacao button:hover{background:#1e40af;}
.paginacao button.atual{opacity:.55;cursor:default;}
.paginacao button:disabled{background:#9ca3af;cursor:not-allowed;}

/* ── BOTTOM BAR ── */
.bottom-bar{
    display:flex;gap:10px;justify-content:flex-end;
    flex-wrap:wrap;margin-top:4px;
}

/* ── TOAST ── */
#toast{
    position:fixed;bottom:28px;left:50%;
    transform:translateX(-50%) translateY(80px);
    background:#166534;color:#fff;padding:11px 26px;
    border-radius:12px;font-size:14px;font-weight:700;
    box-shadow:0 8px 28px rgba(0,0,0,.3);
    opacity:0;transition:opacity .35s,transform .35s;
    z-index:9999;pointer-events:none;white-space:nowrap;
}
#toast.visivel{opacity:1;transform:translateX(-50%) translateY(0);}
#toast.erro-t{background:#991b1b;}

/* ── RESPONSIVO ── */
@media(max-width:800px){
    .form-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:480px){
    .form-grid{grid-template-columns:1fr;}
    .btn-row{flex-direction:column;}
}
</style>
</head>
<body>
<div id="toast"></div>

<div class="card">
    <h1>Cadastro de Classificação</h1>

    <?php if ($mensagem): ?>
    <div class="msg <?= htmlspecialchars($tipoMensagem) ?>"><?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>

    <!-- ── FORMULÁRIO DE INSERÇÃO ── -->
    <form method="POST" action="contabil.php" id="formInserir">
        <input type="hidden" name="acao" value="inserir">
        <div class="form-grid">
            <div>
                <label for="descricao">Descrição</label>
                <input type="text" name="descricao" id="descricao" placeholder="DESCRIÇÃO" required>
            </div>
            <div>
                <label for="classe">Classe</label>
                <input type="text" name="classe" id="classe" placeholder="CLASSE" required>
            </div>
            <div>
                <label for="grupo">Grupo</label>
                <input type="text" name="grupo" id="grupo" placeholder="GRUPO" required>
            </div>
            <div>
                <label for="subgrupo">Subgrupo</label>
                <input type="text" name="subgrupo" id="subgrupo" placeholder="SUBGRUPO" required>
            </div>
        </div>
        <div class="btn-row">
            <button type="button" class="btn btn-secondary" onclick="location.href='inicial.php'">Voltar</button>
            <button type="reset"  class="btn btn-secondary">Limpar</button>
            <button type="submit" class="btn btn-primary">Salvar</button>
        </div>
    </form>

    <!-- ── TOOLBAR ── -->
    <div class="toolbar">
        <input type="text" id="campoPesquisa" placeholder="Pesquisar em qualquer campo...">
        <span class="contador" id="contador">Carregando...</span>
        <button class="btn btn-warning" onclick="carregarDados()">↺ Atualizar</button>
    </div>

    <!-- ── TABELA ── -->
    <div class="table-wrap" style="max-height:420px;overflow-y:auto;">
        <table id="tabela">
            <thead>
                <tr>
                    <td style="width:35px">#</td>
                    <td>DESCRIÇÃO</td>
                    <td>CLASSE</td>
                    <td>GRUPO</td>
                    <td>SUBGRUPO</td>
                </tr>
            </thead>
            <tbody id="tbody"></tbody>
        </table>
    </div>

    <!-- ── PAGINAÇÃO ── -->
    <div class="paginacao" id="paginacao"></div>

    <!-- ── BOTTOM BAR ── -->
    <div class="bottom-bar">
        <button class="btn btn-success" id="btnSalvarMod" onclick="salvarModificacoes()">💾 Salvar Modificações</button>
    </div>
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

const POR_PAGINA   = 100;
let paginaAtual    = 1;
let termoAtivo     = '';
let linhasAlteradas = new Set();
let linhaSelecionada = null;

/* ── TOAST ── */
function toast(msg, erro = false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.remove('visivel', 'erro-t');
    if (erro) t.classList.add('erro-t');
    requestAnimationFrame(() => t.classList.add('visivel'));
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('visivel'), 4000);
}

/* ── CARREGAR DADOS VIA AJAX ── */
function carregarDados() {
    const qs = new URLSearchParams({
        pagina:    paginaAtual,
        porPagina: POR_PAGINA,
        termo:     termoAtivo,
    });

    fetch('contabil_dados.php?' + qs, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(r => {
            if (r.erro) { toast('Servidor: ' + r.erro, true); return; }
            renderTabela(r.linhas || []);
            renderPaginacao(r.total || 0);
            const ini = (paginaAtual - 1) * POR_PAGINA + 1;
            const fim = Math.min(ini + (r.linhas||[]).length - 1, r.total || 0);
            document.getElementById('contador').textContent =
                (r.total||0) > 0 ? `${ini}–${fim} de ${esc(r.total)}` : '0 registros';
        })
        .catch(err => { console.error('contabil_dados erro:', err); toast('Erro ao carregar dados.', true); });
}

/* ── RENDER TABELA ── */
function renderTabela(linhas) {
    const tb = document.getElementById('tbody');
    tb.innerHTML = '';
    linhaSelecionada = null;

    linhas.forEach((linha, idx) => {
        const tr = document.createElement('tr');
        tr.dataset.id    = linha.id;
        tr.dataset.dirty = '0';

        // # (número sequencial)
        const tdN = document.createElement('td');
        tdN.style.cssText = 'color:#94a3b8;font-size:11px;text-align:center';
        tdN.textContent = (paginaAtual - 1) * POR_PAGINA + idx + 1;
        tr.appendChild(tdN);

        // colunas editáveis
        ['descricao','classe','grupo','subgrupo'].forEach(col => {
            const td  = document.createElement('td');
            const inp = document.createElement('input');
            inp.type      = 'text';
            inp.className = 'cel';
            inp.value     = linha[col] ?? '';
            inp.dataset.col = col;

            inp.addEventListener('input', () => {
                tr.dataset.dirty = '1';
                tr.classList.add('modificada');
                linhasAlteradas.add(tr);
            });

            td.appendChild(inp);
            tr.appendChild(td);
        });

        // clique simples → seleciona
        tr.addEventListener('click', e => {
            if (e.target.classList.contains('cel') && e.target.classList.contains('editando')) return;
            if (linhaSelecionada && linhaSelecionada !== tr) {
                linhaSelecionada.classList.remove('selecionada');
                linhaSelecionada.querySelectorAll('.cel.editando').forEach(i => i.classList.remove('editando'));
            }
            linhaSelecionada = tr;
            tr.classList.toggle('selecionada');
        });

        // duplo clique → edita célula
        tr.querySelectorAll('td').forEach(td => {
            td.addEventListener('dblclick', e => {
                const inp = td.querySelector('.cel');
                if (!inp) return;
                if (linhaSelecionada !== tr) {
                    if (linhaSelecionada) linhaSelecionada.classList.remove('selecionada');
                    linhaSelecionada = tr;
                    tr.classList.add('selecionada');
                }
                inp.classList.add('editando');
                inp.focus();
                e.stopPropagation();
            });
        });

        tb.appendChild(tr);
    });
}

/* clique fora da tabela: desmarca */
document.addEventListener('click', e => {
    if (!e.target.closest('#tabela') && linhaSelecionada) {
        linhaSelecionada.classList.remove('selecionada');
        linhaSelecionada.querySelectorAll('.cel.editando').forEach(i => i.classList.remove('editando'));
        linhaSelecionada = null;
    }
});

/* ── PAGINAÇÃO ── */
function renderPaginacao(total) {
    const pag   = document.getElementById('paginacao');
    pag.innerHTML = '';
    const pages = Math.ceil(total / POR_PAGINA);
    if (pages <= 1) return;

    const prev = document.createElement('button');
    prev.textContent = '<'; prev.disabled = (paginaAtual === 1);
    prev.onclick = () => { paginaAtual--; carregarDados(); };
    pag.appendChild(prev);

    const ini = Math.max(1, paginaAtual - 2);
    const fim = Math.min(pages, ini + 4);
    for (let i = ini; i <= fim; i++) {
        const b = document.createElement('button');
        b.textContent = i;
        if (i === paginaAtual) b.classList.add('atual');
        b.onclick = () => { paginaAtual = i; carregarDados(); };
        pag.appendChild(b);
    }

    const next = document.createElement('button');
    next.textContent = '>'; next.disabled = (paginaAtual === pages);
    next.onclick = () => { paginaAtual++; carregarDados(); };
    pag.appendChild(next);
}

/* ── PESQUISA ── */
document.getElementById('campoPesquisa').addEventListener('input', function () {
    termoAtivo  = this.value.trim();
    paginaAtual = 1;
    carregarDados();
});

/* ── SALVAR MODIFICAÇÕES ── */
async function salvarModificacoes() {
    const sujas = Array.from(document.querySelectorAll('#tbody tr[data-dirty="1"]'));
    if (sujas.length === 0) { toast('Nenhuma alteração para salvar.'); return; }

    const btn = document.getElementById('btnSalvarMod');
    btn.disabled = true; btn.textContent = 'Salvando...';

    const linhas = sujas.map(tr => ({
        id:        tr.dataset.id,
        descricao: tr.querySelector('input[data-col="descricao"]')?.value ?? '',
        grupo:     tr.querySelector('input[data-col="grupo"]')?.value     ?? '',
        classe:    tr.querySelector('input[data-col="classe"]')?.value    ?? '',
        subgrupo:  tr.querySelector('input[data-col="subgrupo"]')?.value  ?? '',
    }));

    try {
        const fd = new FormData();
        fd.append('acao',   'salvar_linhas');
        fd.append('linhas', JSON.stringify(linhas));

        const resp  = await fetch('contabil_dados.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const texto = await resp.text();
        let json;
        try { json = JSON.parse(texto); }
        catch (e) { toast('Erro do servidor.', true); return; }

        if (json.ok) {
            sujas.forEach(tr => {
                tr.dataset.dirty = '0';
                tr.classList.remove('modificada', 'selecionada');
                tr.classList.add('salva');
                setTimeout(() => tr.classList.remove('salva'), 2500);
            });
            linhasAlteradas.clear();
            toast(json.msg);
        } else {
            toast(json.msg || 'Erro ao salvar.', true);
        }
    } catch (err) {
        toast('Falha na comunicação com o servidor.', true);
    } finally {
        btn.disabled = false; btn.textContent = '💾 Salvar Modificações';
    }
}

/* ── UPPERCASE nos inputs do form ── */
document.querySelectorAll('#formInserir input[type="text"]').forEach(inp => {
    inp.addEventListener('input', () => { inp.value = inp.value.toUpperCase(); });
});

/* ── INIT ── */
carregarDados();
</script>

<script>
(function hb(){
    fetch('heartbeat.php?_=' + Date.now(), { method:'POST', credentials:'same-origin', cache:'no-store' })
    .then(r => r.json())
    .then(d => { if (d.revogada) location.href = 'index.html?error=Sua+sessao+foi+encerrada'; })
    .catch(() => {});
    setTimeout(hb, 30000);
})();
</script>
</body>
</html>