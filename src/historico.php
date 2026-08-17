<?php
session_start();
require 'conexao.php';
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

if ($status !== 'ATIVO') {
    session_destroy();
    header("Location: index.html?erro=bloqueado");
    exit();
}

if (!in_array($permicao, ['A','B']) || !in_array($classe_usuario, ['DEV','PATRIMONIO'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}

/* DEV pode editar e excluir */
$isDev = ($classe_usuario === 'DEV');

$sql = "
SELECT id, `data`, descricao, marca, modelo, serie, tag,
       unidade, setor, unidade_dest, setor_dest, local_dest,
       obs_mov, tipo_mov, usuario_mov
FROM historico
ORDER BY id DESC
";

$result = $conn->query($sql);
$linhas = [];
while ($row = $result->fetch_assoc()) $linhas[] = $row;
$conn->close();

$colunas = [
    'ID'                          => 'id',
    'DATA'                        => 'data',
    'DESCRIÇÃO'                   => 'descricao',
    'MARCA'                       => 'marca',
    'MODELO'                      => 'modelo',
    'Nº DE SÉRIE'                 => 'serie',
    'TAG DE PATRIMÔNIO'           => 'tag',
    'UNIDADE'                     => 'unidade',
    'SETOR'                       => 'setor',
    'UNIDADE DE DESTINO'          => 'unidade_dest',
    'SETOR DE DESTINO'            => 'setor_dest',
    'LOCAL DE DESTINO'            => 'local_dest',
    'OBSERVAÇÃO DE MOVIMENTAÇÃO'  => 'obs_mov',
    'TIPO DE MOVIMENTAÇÃO'        => 'tipo_mov',
    'USUÁRIO QUE MOVIMENTOU'      => 'usuario_mov',
];

/* colunas editáveis (exceto id) */
$colunasEditaveis = array_values(array_filter(array_values($colunas), fn($c) => $c !== 'id'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Histórico de Movimentações</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
body{margin:0;font-family:Arial,sans-serif;background:linear-gradient(135deg,#001435,#60a5fa);display:flex;justify-content:center;padding:30px;}
.card{background:#fff;padding:30px;border-radius:12px;width:95%;max-width:1600px;}
.top-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px;}
.contador{font-size:14px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:6px 10px;color:#111827;}
.badge-sel{font-size:14px;background:#fef9c3;border:1px solid #fbbf24;border-radius:8px;padding:6px 10px;color:#92400e;display:none;}
input[type="text"]{width:60%;padding:6px 10px;border-radius:6px;border:1px solid #ccc;}
.table-container{margin-top:10px;overflow:auto;max-height:600px;}
table{border-collapse:collapse;width:100%;min-width:2800px;}
th,td{border:1px solid #ddd;padding:8px 10px;white-space:nowrap;font-size:13px;}
/* larguras mínimas por coluna */
th:nth-child(1),td:nth-child(1){min-width:40px;width:40px;}   /* checkbox */
th:nth-child(2),td:nth-child(2){min-width:55px;}               /* id */
th:nth-child(3),td:nth-child(3){min-width:130px;}              /* data */
th:nth-child(4),td:nth-child(4){min-width:220px;}              /* descricao */
th:nth-child(5),td:nth-child(5){min-width:130px;}              /* marca */
th:nth-child(6),td:nth-child(6){min-width:160px;}              /* modelo */
th:nth-child(7),td:nth-child(7){min-width:160px;}              /* serie */
th:nth-child(8),td:nth-child(8){min-width:160px;}              /* tag */
th:nth-child(9),td:nth-child(9){min-width:180px;}              /* unidade */
th:nth-child(10),td:nth-child(10){min-width:160px;}            /* setor */
th:nth-child(11),td:nth-child(11){min-width:200px;}            /* unidade_dest */
th:nth-child(12),td:nth-child(12){min-width:180px;}            /* setor_dest */
th:nth-child(13),td:nth-child(13){min-width:180px;}            /* local_dest */
th:nth-child(14),td:nth-child(14){min-width:220px;}            /* obs_mov */
th:nth-child(15),td:nth-child(15){min-width:180px;}            /* tipo_mov */
th:nth-child(16),td:nth-child(16){min-width:160px;}            /* usuario_mov */
th{background:#3b82f6;color:#fff;position:sticky;top:0;z-index:2;}
th.col-chk,td.col-chk{width:36px;text-align:center;background:#2563eb;}
td.col-chk{background:transparent;}
tr.selecionada{background:#dbeafe!important;}
tr.editada{background:#fef9c3!important;}
tr.salva{background:#dcfce7!important;}
.filtro{width:100%;margin-top:6px;padding:4px;font-size:12px;}
.btn{padding:8px 14px;border:none;border-radius:6px;background:#3b82f6;color:white;cursor:pointer;font-size:13px;}
.btn:hover{background:#2563eb;}
.btn:disabled{background:#9ca3af;cursor:not-allowed;}
.btn-form{background:#16a34a;}.btn-form:hover{background:#15803d;}
.btn-danger{background:#dc2626;}.btn-danger:hover{background:#b91c1c;}
.btn-salvar{background:#7c3aed;}.btn-salvar:hover{background:#6d28d9;}
.pagination{margin-top:12px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;}

/* célula editável */
td input.cel{width:100%;border:none;background:transparent;padding:2px 3px;font-size:13px;cursor:default;pointer-events:none;outline:none;font-family:Arial,sans-serif;min-width:0;box-sizing:border-box;}
td input.cel.editando{background:#eef2ff;border:1px solid #6366f1;border-radius:4px;padding:2px 5px;cursor:text;pointer-events:all;}

/* modal confirmação exclusão */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;}
.modal-overlay.ativo{display:flex;}
.modal-box{background:#fff;border-radius:14px;padding:26px 28px;max-width:360px;width:90%;box-shadow:0 20px 50px rgba(0,0,0,.3);text-align:center;}
.modal-box h3{font-size:16px;color:#0f172a;margin-bottom:8px;}
.modal-box p{font-size:13px;color:#475569;margin-bottom:20px;}
.modal-acts{display:flex;gap:10px;justify-content:center;}

/* toast */
#toast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(80px);background:#166534;color:#fff;padding:11px 26px;border-radius:12px;font-size:14px;font-weight:700;box-shadow:0 8px 28px rgba(0,0,0,.3);opacity:0;transition:opacity .35s,transform .35s;z-index:9999;pointer-events:none;white-space:nowrap;}
#toast.visivel{opacity:1;transform:translateX(-50%) translateY(0);}
#toast.erro-t{background:#991b1b;}
</style>
</head>
<body>

<div id="toast"></div>

<!-- Modal exclusão -->
<div class="modal-overlay" id="modalExcluir">
    <div class="modal-box">
        <h3>Confirmar Exclusão</h3>
        <p id="modalTexto">Deseja excluir os registros selecionados permanentemente?</p>
        <div class="modal-acts">
            <button class="btn" onclick="fecharModal()" style="background:#e5e7eb;color:#111">Cancelar</button>
            <button class="btn btn-danger" onclick="confirmarExclusao()">Excluir</button>
        </div>
    </div>
</div>

<div class="card">
<h2>Histórico de Movimentações</h2>

<div class="top-bar">
    <span id="contador" class="contador">Exibindo 0 de 0</span>
    <span id="badgeSel" class="badge-sel">0 selecionado(s)</span>
    <input type="text" id="pesquisa" placeholder="Pesquisar em todo histórico...">
    <button class="btn btn-form" id="btnFormulario" onclick="abrirFormulario()" disabled>📄 Imprimir Formulário</button>
    <button class="btn" onclick="limparSelecao()">Limpar Seleção</button>
    <?php if ($isDev): ?>
    <button class="btn btn-salvar" id="btnSalvar" onclick="salvarAlteracoes()" disabled>💾 Salvar Alterações</button>
    <button class="btn btn-danger" id="btnExcluir" onclick="abrirModalExclusao()" disabled>🗑 Excluir Selecionados</button>
    <?php endif; ?>
    <button class="btn" style="background:#6b7280;" onclick="location.href='inicial.php'">Voltar</button>
    <button class="btn" onclick="location.reload()">Atualizar</button>
</div>

<div class="table-container">
<table id="tabela">
<thead>
<tr>
    <th class="col-chk">
        <input type="checkbox" id="chkTodos" title="Marcar/desmarcar página">
    </th>
<?php foreach ($colunas as $titulo => $campo): ?>
<th>
    <div><?= $titulo ?></div>
    <select class="filtro" data-col="<?= $campo ?>">
        <option value="">Todos</option>
        <?php
        $vals = array_unique(array_column($linhas, $campo));
        sort($vals);
        foreach ($vals as $v) {
            if ($v !== '' && $v !== null)
                echo '<option>' . htmlspecialchars($v) . '</option>';
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

const dados          = <?= json_encode($linhas, JSON_UNESCAPED_UNICODE) ?>;
const colunas        = <?= json_encode(array_values($colunas)) ?>;
const isDev          = <?= $isDev ? 'true' : 'false' ?>;
const colEditaveis   = <?= json_encode($colunasEditaveis) ?>;

const tbody     = document.querySelector('#tabela tbody');
const paginacao = document.getElementById('paginacao');
const contador  = document.getElementById('contador');
const badgeSel  = document.getElementById('badgeSel');
const btnForm   = document.getElementById('btnFormulario');
const btnSalvar = document.getElementById('btnSalvar');
const btnExcluir= document.getElementById('btnExcluir');
const chkTodos  = document.getElementById('chkTodos');
const filtros   = document.querySelectorAll('.filtro');

let paginaAtual = 1;
const porPagina = 100;
const selecionados  = new Set();
const linhasAlteradas = new Set();

/* ── TOAST ── */
function toast(msg, erro = false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.remove('visivel','erro-t');
    if (erro) t.classList.add('erro-t');
    requestAnimationFrame(() => t.classList.add('visivel'));
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('visivel'), 4000);
}

/* ── RENDER ── */
function renderTabela(lista) {
    tbody.innerHTML = '';
    lista.forEach(linha => {
        const tr = document.createElement('tr');
        tr.dataset.id = linha.id;
        tr.dataset.dirty = '0';
        if (selecionados.has(linha.id)) tr.classList.add('selecionada');

        /* checkbox */
        const tdChk = document.createElement('td');
        tdChk.className = 'col-chk';
        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.checked = selecionados.has(linha.id);
        chk.addEventListener('change', () => toggleSel(linha.id, tr, chk));
        tdChk.appendChild(chk);
        tr.appendChild(tdChk);

        colunas.forEach(c => {
            const td  = document.createElement('td');
            const inp = document.createElement('input');
            inp.type      = 'text';
            inp.className = 'cel';
            inp.value     = linha[c] ?? '';
            inp.dataset.campo = c;
            inp.dataset.original = inp.value;
            inp.readOnly  = true;

            /* DEV: duplo clique edita (exceto coluna id) */
            if (isDev && c !== 'id') {
                td.addEventListener('dblclick', e => {
                    inp.classList.add('editando');
                    inp.readOnly = false;
                    inp.focus();
                    e.stopPropagation();
                });
                inp.addEventListener('input', () => {
                    if (inp.value !== inp.dataset.original) {
                        tr.dataset.dirty = '1';
                        tr.classList.add('editada');
                        linhasAlteradas.add(tr);
                        if (btnSalvar) btnSalvar.disabled = false;
                    }
                });
                inp.addEventListener('blur', () => {
                    inp.classList.remove('editando');
                    inp.readOnly = true;
                });
            } else {
                /* não DEV: clique seleciona linha */
                td.addEventListener('click', () => {
                    chk.checked = !chk.checked;
                    toggleSel(linha.id, tr, chk);
                });
            }

            td.appendChild(inp);
            tr.appendChild(td);
        });

        /* DEV: clique simples seleciona a linha */
        if (isDev) {
            tr.addEventListener('click', e => {
                if (e.target.classList.contains('cel') && e.target.classList.contains('editando')) return;
                if (e.target.type === 'checkbox') return;
                chk.checked = !chk.checked;
                toggleSel(linha.id, tr, chk);
            });
        }

        tbody.appendChild(tr);
    });

    const chks = tbody.querySelectorAll('input[type=checkbox]');
    chkTodos.checked       = chks.length > 0 && [...chks].every(c => c.checked);
    chkTodos.indeterminate = !chkTodos.checked && [...chks].some(c => c.checked);
}

function renderPaginacao(total) {
    paginacao.innerHTML = '';
    const paginas = Math.ceil(total / porPagina);
    for (let i = 1; i <= paginas; i++) {
        const b = document.createElement('button');
        b.textContent = i; b.className = 'btn';
        if (i === paginaAtual) b.style.opacity = '0.6';
        b.onclick = () => { paginaAtual = i; atualizar(); };
        paginacao.appendChild(b);
    }
}

function aplicarFiltros() {
    return dados.filter(linha =>
        [...filtros].every(f => {
            if (!f.value) return true;
            return (linha[f.dataset.col] ?? '') == f.value;
        })
    );
}

function atualizar() {
    const termo = document.getElementById('pesquisa').value.toLowerCase();
    let filtrados = aplicarFiltros();
    if (termo) {
        filtrados = filtrados.filter(l =>
            Object.values(l).join(' ').toLowerCase().includes(termo)
        );
    }
    const inicio = (paginaAtual - 1) * porPagina;
    const fim    = Math.min(inicio + porPagina, filtrados.length);
    renderTabela(filtrados.slice(inicio, fim));
    const exibindo = filtrados.length === 0 ? 0 : inicio + 1;
    contador.textContent = `Exibindo ${exibindo}–${fim} de ${esc(filtrados.length)}`;
    renderPaginacao(filtrados.length);
}

/* ── SELEÇÃO ── */
function toggleSel(id, tr, chk) {
    if (chk.checked) { selecionados.add(id); tr.classList.add('selecionada'); }
    else { selecionados.delete(id); tr.classList.remove('selecionada'); }
    atualizarBadge();
    const chks = tbody.querySelectorAll('input[type=checkbox]');
    chkTodos.checked       = [...chks].every(c => c.checked);
    chkTodos.indeterminate = !chkTodos.checked && [...chks].some(c => c.checked);
}

function atualizarBadge() {
    const n = selecionados.size;
    if (n > 0) {
        badgeSel.style.display = 'inline-block';
        badgeSel.textContent   = `${n} selecionado(s)`;
        btnForm.disabled       = false;
        if (btnExcluir) btnExcluir.disabled = false;
    } else {
        badgeSel.style.display = 'none';
        btnForm.disabled       = true;
        if (btnExcluir) btnExcluir.disabled = true;
    }
}

function limparSelecao() {
    selecionados.clear(); atualizarBadge();
    tbody.querySelectorAll('input[type=checkbox]').forEach(c => c.checked = false);
    tbody.querySelectorAll('tr.selecionada').forEach(tr => tr.classList.remove('selecionada'));
    chkTodos.checked = chkTodos.indeterminate = false;
}

chkTodos.addEventListener('change', () => {
    const chks = tbody.querySelectorAll('input[type=checkbox]');
    const trs  = tbody.querySelectorAll('tr');
    chks.forEach((chk, i) => {
        chk.checked = chkTodos.checked;
        const tr = trs[i];
        const idVal = parseInt(tr.dataset.id);
        if (!isNaN(idVal)) {
            if (chkTodos.checked) { selecionados.add(idVal); tr.classList.add('selecionada'); }
            else { selecionados.delete(idVal); tr.classList.remove('selecionada'); }
        }
    });
    atualizarBadge();
});

/* ── IMPRIMIR ── */
function abrirFormulario() {
    if (selecionados.size === 0) { alert('Selecione ao menos um registro.'); return; }
    window.open('formulario_historico.php?ids=' + [...selecionados].join(','), '_blank');
}

/* ── SALVAR ALTERAÇÕES (DEV) ── */
async function salvarAlteracoes() {
    const sujas = Array.from(document.querySelectorAll('#tabela tbody tr[data-dirty="1"]'));
    if (sujas.length === 0) { toast('Nenhuma alteração para salvar.'); return; }

    btnSalvar.disabled = true; btnSalvar.textContent = 'Salvando...';

    const linhas = sujas.map(tr => {
        const obj = { id: tr.dataset.id };
        tr.querySelectorAll('input.cel[data-campo]').forEach(inp => {
            if (inp.dataset.campo !== 'id') obj[inp.dataset.campo] = inp.value;
        });
        return obj;
    });

    try {
        const fd = new FormData();
        fd.append('acao', 'salvar');
        fd.append('linhas', JSON.stringify(linhas));
        const resp = await fetch('historico_ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const json = await resp.json();
        if (json.ok) {
            sujas.forEach(tr => {
                tr.dataset.dirty = '0';
                tr.classList.remove('editada','selecionada');
                tr.classList.add('salva');
                setTimeout(() => tr.classList.remove('salva'), 2500);
                tr.querySelectorAll('input.cel').forEach(i => i.dataset.original = i.value);
            });
            linhasAlteradas.clear();
            toast(json.msg);
        } else {
            toast(json.msg || 'Erro ao salvar.', true);
        }
    } catch (e) {
        toast('Falha na comunicação.', true);
    } finally {
        btnSalvar.disabled = false; btnSalvar.textContent = '💾 Salvar Alterações';
    }
}

/* ── EXCLUIR (DEV) ── */
function abrirModalExclusao() {
    if (selecionados.size === 0) return;
    document.getElementById('modalTexto').textContent =
        `Deseja excluir permanentemente ${esc(selecionados.size)} registro(s)?`;
    document.getElementById('modalExcluir').classList.add('ativo');
}
function fecharModal() {
    document.getElementById('modalExcluir').classList.remove('ativo');
}
document.getElementById('modalExcluir').addEventListener('click', e => {
    if (e.target === document.getElementById('modalExcluir')) fecharModal();
});

async function confirmarExclusao() {
    fecharModal();
    const ids = [...selecionados];
    try {
        const fd = new FormData();
        fd.append('acao', 'excluir');
        fd.append('ids', JSON.stringify(ids));
        const resp = await fetch('historico_ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const json = await resp.json();
        if (json.ok) {
            /* remove linhas da tabela e do array dados */
            ids.forEach(id => {
                const tr = document.querySelector(`#tabela tbody tr[data-id="${id}"]`);
                if (tr) tr.remove();
                const idx = dados.findIndex(d => d.id == id);
                if (idx !== -1) dados.splice(idx, 1);
                selecionados.delete(id);
            });
            atualizarBadge();
            atualizar();
            toast(json.msg);
        } else {
            toast(json.msg || 'Erro ao excluir.', true);
        }
    } catch (e) {
        toast('Falha na comunicação.', true);
    }
}

document.getElementById('pesquisa').addEventListener('keyup', () => { paginaAtual = 1; atualizar(); });
filtros.forEach(f => f.addEventListener('change', () => { paginaAtual = 1; atualizar(); }));

atualizar();
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