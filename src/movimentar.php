<?php
session_start();
include 'conexao.php';
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

$id = $_GET['id'] ?? '';
$from_rotina = ($_GET['from'] ?? '') === 'rotina';
$veio_planilha = !empty($id);
$dados_item = null;

if ($id) {
    $sql = "SELECT * FROM cadastro WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $dados_item = $res->fetch_assoc();
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Movimentação</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:linear-gradient(135deg,#001435,#60a5fa);
    display:flex;
    justify-content:center;
    align-items:flex-start;
    min-height:100vh;
    padding:20px;
}

.form-card{
    background:#fff;
    padding:30px 20px;
    border-radius:16px;
    box-shadow:0 12px 30px rgba(0,0,0,.25);
    width:100%;
    max-width:700px;
}

.form-card h1{
    text-align:center;
    font-size:2rem;
    margin-bottom:10px;
    color:#111827;
}

.alert-sucesso{
    background:#dcfce7;
    border:1px solid #22c55e;
    color:#166534;
    padding:12px 15px;
    border-radius:10px;
    margin-bottom:15px;
    font-weight:600;
    text-align:center;
    animation:fadeIn .4s ease;
}

@keyframes fadeIn{
    from{opacity:0;transform:translateY(-5px);}
    to{opacity:1;}
}

.caixa{
    border:1px solid #ccc;
    padding:15px;
    border-radius:12px;
    margin-bottom:20px;
}

.caixa h2{
    margin-top:0;
    color:#3b82f6;
}

.field{
    margin-bottom:16px;
    width:100%;
}

.field label{
    display:block;
    margin-bottom:4px;
    font-weight:600;
    color:#111827;
    font-size:14px;
}

.field input{
    width:100%;
    padding:8px 10px;
    border:1px solid #ccc;
    border-radius:10px;
    font-size:14px;
    outline:none;
    box-sizing:border-box;
}

.field input.uppercase{ text-transform:uppercase; }
.field input:disabled{ background:#e5e7eb; }

.busca-row{
    display:flex;
    gap:8px;
    align-items:flex-end;
    margin-bottom:16px;
}
.busca-row .field{
    flex:1;
    margin-bottom:0;
}
.busca-row .btn-buscar{
    flex:0 0 auto;
    padding:9px 18px;
    border:none;
    border-radius:10px;
    background:#3b82f6;
    color:#fff;
    cursor:pointer;
    font-size:14px;
    white-space:nowrap;
}
.busca-row .btn-buscar:hover{ background:#2563eb; }

.badge-id{
    display:none;
    background:#dbeafe;
    color:#1e40af;
    border:1px solid #93c5fd;
    border-radius:8px;
    padding:5px 12px;
    font-size:13px;
    font-weight:700;
    white-space:nowrap;
    align-self:flex-end;
    margin-bottom:16px;
}

.tabela-itens{
    width:100%;
    border-collapse:collapse;
    font-size:13px;
    margin-top:10px;
}
.tabela-itens th{
    background:#2563eb;
    color:#fff;
    padding:7px 8px;
    text-align:left;
}
.tabela-itens td{
    padding:6px 8px;
    border-bottom:1px solid #e5e7eb;
}
.tabela-itens tr:nth-child(even){ background:#f9fafb; }

.btn-remover{
    background:none;
    border:none;
    color:#ef4444;
    cursor:pointer;
    font-weight:700;
    font-size:15px;
    padding:0 4px;
}

.actions{
    display:flex;
    gap:10px;
    margin-top:10px;
}

.btn{
    flex:1;
    padding:10px;
    border:none;
    border-radius:10px;
    font-size:14px;
    background:#3b82f6;
    color:#fff;
    cursor:pointer;
}
.btn:hover{ background:#2563eb; }
.btn-secondary{ background:#e5e7eb; color:#111827; }
.btn-secondary:hover{ background:#d1d5db; }
.btn-verde{ background:#16a34a; }
.btn-verde:hover{ background:#15803d; }

.checkline{
    display:flex;
    align-items:center;
    gap:8px;
    margin-top:8px;
    font-weight:600;
}

/* ── Combobox pesquisável ── */
.ss-wrap{position:relative;width:100%;}
.ss-input{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:10px;font-size:14px;outline:none;box-sizing:border-box;text-transform:uppercase;background:#fff;}
.ss-input:focus{border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,.15);}
.ss-input:disabled{background:#e5e7eb;color:#9ca3af;cursor:not-allowed;}
.ss-list{display:none;position:absolute;top:calc(100% + 3px);left:0;right:0;background:#fff;border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 6px 18px rgba(0,0,0,.12);z-index:200;max-height:220px;overflow-y:auto;}
.ss-item{padding:9px 12px;cursor:pointer;font-size:14px;text-transform:uppercase;transition:background .1s;}
.ss-item:hover,.ss-item.ss-hover{background:#eff6ff;color:#1d4ed8;}
.ss-vazio{padding:9px 12px;font-size:13px;color:#9ca3af;font-style:italic;}

@media(max-width:600px){
    body{ padding:10px; }
    .form-card{ padding:20px 15px; border-radius:12px; }
    .form-card h1{ font-size:1.6rem; }
    .caixa h2{ font-size:1.1rem; }
    .field label{ font-size:13px; }
    .field input{ font-size:13px; padding:10px; }
    .actions{ flex-direction:column; }
    .btn{ width:100%; font-size:15px; padding:12px; }
    .checkline{ font-size:13px; }
    .busca-row{ flex-direction:column; }
    .busca-row .btn-buscar{ width:100%; }
}
</style>
</head>

<body>
<div class="form-card">
<h1>Movimentação</h1>

<?php if(isset($_GET['msg']) && $_GET['msg'] == 'sucesso'): ?>
    <div class="alert-sucesso">✅ Movimentação realizada com sucesso!</div>
<?php endif; ?>

<input type="hidden" id="id_cadastro" value="<?= intval($dados_item['id'] ?? 0) ?>">

<!-- ====== BUSCA ====== -->
<div class="caixa">
<h2>Buscar Item</h2>

<div class="busca-row">
    <div class="field">
        <label>Tag / Nº Série</label>
        <input type="text" id="tag" class="uppercase" placeholder="Digite a tag ou número de série"
               value="<?= htmlspecialchars($dados_item['tag_trocada'] ?? $dados_item['tag_antiga'] ?? '') ?>">
    </div>
    <button type="button" class="btn-buscar" id="btnBuscarTag">Buscar</button>
    <span class="badge-id" id="badgeId"></span>
</div>

<div class="field"><label>Descrição</label>
<input id="descricao" class="uppercase" disabled value="<?= htmlspecialchars($dados_item['descricao'] ?? '') ?>">
</div>

<div class="field"><label>Marca</label>
<input id="marca" class="uppercase" disabled value="<?= htmlspecialchars($dados_item['marca'] ?? '') ?>">
</div>

<div class="field"><label>Modelo</label>
<input id="modelo" class="uppercase" disabled value="<?= htmlspecialchars($dados_item['modelo'] ?? '') ?>">
</div>

<div class="field"><label>N° de Série</label>
<input id="serie" class="uppercase" disabled value="<?= htmlspecialchars($dados_item['serie'] ?? '') ?>">
</div>

<div class="field"><label>Unidade de Origem (localização atual)</label>
<input id="unidade_origem" class="uppercase" disabled value="<?= htmlspecialchars(!empty($dados_item['unidade_destino']) ? $dados_item['unidade_destino'] : ($dados_item['unidade'] ?? '')) ?>">
</div>

<div class="field"><label>Setor de Origem (localização atual)</label>
<input id="setor_origem" class="uppercase" disabled value="<?= htmlspecialchars(!empty($dados_item['setor_destino']) ? $dados_item['setor_destino'] : ($dados_item['setor'] ?? '')) ?>">
</div>

<div class="field"><label>Área de Origem (localização atual)</label>
<input id="area_origem" class="uppercase" disabled value="<?= htmlspecialchars($dados_item['area_destino'] ?? '') ?>">
</div>

<div class="actions" style="margin-top:0;">
    <button class="btn btn-verde" id="btnAdicionar" type="button">Adicionar +</button>
</div>
</div>

<!-- ====== ITENS ADICIONADOS ====== -->
<div class="caixa" id="caixaItens" style="display:none;">
<h2>Itens para Movimentar</h2>
<div style="overflow-x:auto;">
<table class="tabela-itens">
    <thead>
        <tr>
            <th>#</th>
            <th>Tag / Nº Série</th>
            <th>Descrição</th>
            <th>Marca</th>
            <th>Unidade / Setor</th>
            <th></th>
        </tr>
    </thead>
    <tbody id="tbodyItens"></tbody>
</table>
</div>
</div>

<!-- ====== DESTINO ====== -->
<div class="caixa">
<h2>Local de Destino</h2>

<div class="field">
    <label>Unidade de Destino</label>
    <div class="ss-wrap" id="ss-wrap-unidade">
        <input class="ss-input" id="ss-unidade" type="text" placeholder="Digite para filtrar..." autocomplete="off">
        <div class="ss-list" id="ss-list-unidade"></div>
        <input type="hidden" id="val-unidade">
    </div>
</div>
<div class="field">
    <label>Setor de Destino</label>
    <div class="ss-wrap" id="ss-wrap-setor">
        <input class="ss-input" id="ss-setor" type="text" placeholder="Selecione a unidade primeiro..." autocomplete="off" disabled>
        <div class="ss-list" id="ss-list-setor"></div>
        <input type="hidden" id="val-setor">
    </div>
</div>
<div class="field">
    <label>Pavimento de Destino</label>
    <div class="ss-wrap" id="ss-wrap-pav">
        <input class="ss-input" id="ss-pav" type="text" placeholder="Selecione o setor primeiro..." autocomplete="off" disabled>
        <div class="ss-list" id="ss-list-pav"></div>
        <input type="hidden" id="val-pav">
    </div>
</div>
<div class="field">
    <label>Área de Destino</label>
    <div class="ss-wrap" id="ss-wrap-local">
        <input class="ss-input" id="ss-local" type="text" placeholder="Selecione o pavimento primeiro..." autocomplete="off" disabled>
        <div class="ss-list" id="ss-list-local"></div>
        <input type="hidden" id="val-local">
    </div>
</div>
<div class="field"><label>Observações</label><input id="obs_movimentacao" class="uppercase"></div>

<div class="checkline">
    <input type="checkbox" id="mov_def">
    <label for="mov_def">Movimentação Definitiva?</label>
</div>
</div>

<div class="actions">
    <button class="btn btn-secondary" id="btnLimpar" type="button">Limpar</button>
    <button class="btn" id="btnMovimentar" type="button">Movimentar</button>
</div>

<div class="actions">
    <button class="btn btn-secondary" id="btnVoltar">Voltar</button>
    <button class="btn btn-secondary" id="btnHistorico" onclick="location.href='historico.php'">Histórico</button>
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

const veioPlanilha = <?= $veio_planilha ? 'true' : 'false' ?>;
const retornoPara  = sessionStorage.getItem('movimentar_retorno') || 'inicial.php';

const elTag           = document.getElementById('tag');
const elDescricao     = document.getElementById('descricao');
const elMarca         = document.getElementById('marca');
const elModelo        = document.getElementById('modelo');
const elSerie         = document.getElementById('serie');
const elUnidadeOrig   = document.getElementById('unidade_origem');
const elSetorOrig     = document.getElementById('setor_origem');
const elAreaOrig      = document.getElementById('area_origem');
const elIdCadastro    = document.getElementById('id_cadastro');
const badgeId         = document.getElementById('badgeId');

const elObs    = document.getElementById('obs_movimentacao');
const elMovDef = document.getElementById('mov_def');

/* ═══════════════════════════════
   COMBOBOX PESQUISÁVEL
═══════════════════════════════ */
class SearchableCombobox {
    constructor(inputId, listId, hiddenId) {
        this.input  = document.getElementById(inputId);
        this.list   = document.getElementById(listId);
        this.hidden = document.getElementById(hiddenId);
        this.opcoes = [];
        this._cb    = null;

        this.input.addEventListener('input',   () => this._render());
        this.input.addEventListener('focus',   () => { if (!this.input.disabled) this._render(); });
        this.input.addEventListener('keydown', e  => this._tecla(e));
        document.addEventListener('click', e => {
            if (!this.input.contains(e.target) && !this.list.contains(e.target)) this._fechar();
        });
    }

    get value() { return this.hidden.value; }

    set onchange(fn) { this._cb = fn; }

    carregar(opcoes) {
        this.opcoes = opcoes;
        this.hidden.value = '';
        this.input.value  = '';
        this.input.disabled = false;
        this.input.placeholder = 'Digite para filtrar...';
        this._fechar();
    }

    limpar(placeholder = 'Selecione o campo anterior primeiro...') {
        this.opcoes = [];
        this.hidden.value   = '';
        this.input.value    = '';
        this.input.disabled = true;
        this.input.placeholder = placeholder;
        this._fechar();
    }

    resetValor() {
        this.hidden.value = '';
        this.input.value  = '';
    }

    _filtradas() {
        const t = this.input.value.trim().toLowerCase();
        return t ? this.opcoes.filter(o => o.toLowerCase().includes(t)) : this.opcoes;
    }

    _render() {
        const lista = this._filtradas();
        this.list.innerHTML = '';
        if (lista.length === 0) {
            const d = document.createElement('div');
            d.className = 'ss-vazio';
            d.textContent = this.input.value.trim() ? 'Nenhum resultado encontrado' : 'Nenhuma opção disponível';
            this.list.appendChild(d);
        } else {
            lista.forEach(op => {
                const d = document.createElement('div');
                d.className  = 'ss-item';
                d.textContent = op;
                d.addEventListener('mousedown', e => { e.preventDefault(); this._selecionar(op); });
                this.list.appendChild(d);
            });
        }
        this.list.style.display = 'block';
    }

    _selecionar(val) {
        this.hidden.value = val;
        this.input.value  = val;
        this._fechar();
        if (this._cb) this._cb();
    }

    _fechar() { this.list.style.display = 'none'; }

    _tecla(e) {
        if (e.key === 'Escape') { this._fechar(); return; }
        const itens = [...this.list.querySelectorAll('.ss-item')];
        if (!itens.length) return;
        const ativo = this.list.querySelector('.ss-hover');
        let idx = ativo ? itens.indexOf(ativo) : -1;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (ativo) ativo.classList.remove('ss-hover');
            itens[Math.min(idx + 1, itens.length - 1)].classList.add('ss-hover');
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (ativo) ativo.classList.remove('ss-hover');
            if (idx > 0) itens[idx - 1].classList.add('ss-hover');
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const hover = this.list.querySelector('.ss-hover');
            if (hover) this._selecionar(hover.textContent);
        }
    }
}

/* ── Instâncias ── */
const ssUnidade = new SearchableCombobox('ss-unidade', 'ss-list-unidade', 'val-unidade');
const ssSetor   = new SearchableCombobox('ss-setor',   'ss-list-setor',   'val-setor');
const ssPav     = new SearchableCombobox('ss-pav',     'ss-list-pav',     'val-pav');
const ssLocal   = new SearchableCombobox('ss-local',   'ss-list-local',   'val-local');

ssUnidade.limpar('Digite para filtrar unidades...');

/* ── Cascata ── */
function carregarDestino(nivel, params = {}) {
    const qs = new URLSearchParams({ nivel, ...params }).toString();
    return fetch('rotina_filtros.php?' + qs).then(r => r.json());
}

carregarDestino('unidade').then(d => ssUnidade.carregar(d.map(x => x.unidade)));

ssUnidade.onchange = () => {
    ssSetor.limpar();  ssPav.limpar();  ssLocal.limpar();
    if (!ssUnidade.value) return;
    carregarDestino('setor', { unidade: ssUnidade.value })
        .then(d => ssSetor.carregar(d.map(x => x.setor)));
};

ssSetor.onchange = () => {
    ssPav.limpar();  ssLocal.limpar();
    if (!ssSetor.value) return;
    carregarDestino('pavimento', { unidade: ssUnidade.value, setor: ssSetor.value })
        .then(d => ssPav.carregar(d.map(x => x.pavimento)));
};

ssPav.onchange = () => {
    ssLocal.limpar();
    if (!ssPav.value) return;
    carregarDestino('area', { unidade: ssUnidade.value, setor: ssSetor.value, pavimento: ssPav.value })
        .then(d => ssLocal.carregar(d.map(x => x.area)));
};

const btnBuscarTag    = document.getElementById('btnBuscarTag');
const btnAdicionar    = document.getElementById('btnAdicionar');
const btnMovimentar   = document.getElementById('btnMovimentar');
const btnLimpar       = document.getElementById('btnLimpar');
const btnVoltar       = document.getElementById('btnVoltar');

const caixaItens      = document.getElementById('caixaItens');
const tbodyItens      = document.getElementById('tbodyItens');

let itens = [];

function atualizarBadge() {
    const id = parseInt(elIdCadastro.value);
    if (id > 0) {
        badgeId.textContent = 'ID: ' + id;
        badgeId.style.display = 'inline-block';
    } else {
        badgeId.style.display = 'none';
    }
}

<?php if ($dados_item):
    $orig_unidade = !empty($dados_item['unidade_destino']) ? $dados_item['unidade_destino'] : ($dados_item['unidade'] ?? '');
    $orig_setor   = !empty($dados_item['setor_destino'])   ? $dados_item['setor_destino']   : ($dados_item['setor']   ?? '');
?>
itens.push({
    id:        <?= intval($dados_item['id']) ?>,
    tag:       "<?= htmlspecialchars($dados_item['tag_trocada'] ?: $dados_item['tag_antiga']) ?>",
    descricao: "<?= htmlspecialchars($dados_item['descricao'] ?? '') ?>",
    marca:     "<?= htmlspecialchars($dados_item['marca'] ?? '') ?>",
    unidade:   "<?= htmlspecialchars($orig_unidade) ?>",
    setor:     "<?= htmlspecialchars($orig_setor) ?>"
});
renderTabela();
limparBusca();
<?php else: ?>
atualizarBadge();
<?php endif; ?>

function buscarTag() {
    const v = elTag.value.trim().toUpperCase();
    if (!v) { alert("Digite uma tag ou número de série"); return; }

    fetch('buscar_tag.php?tag=' + encodeURIComponent(v))
        .then(r => r.json())
        .then(d => {
            if (d.encontrado) {
                elDescricao.value   = d.descricao   ?? '';
                elMarca.value       = d.marca        ?? '';
                elModelo.value      = d.modelo       ?? '';
                elSerie.value       = d.serie        ?? '';
                // Mostra localização atual: unidade_destino com fallback para unidade
                elUnidadeOrig.value = d.unidade_destino || d.unidade || '';
                elSetorOrig.value   = d.setor_destino   || d.setor   || '';
                elAreaOrig.value    = d.area_destino    || '';
                elIdCadastro.value  = d.id;
                atualizarBadge();
            } else {
                alert("Item não encontrado");
                elIdCadastro.value = '';
                atualizarBadge();
            }
        })
        .catch(() => alert("Erro ao buscar item"));
}

btnBuscarTag.addEventListener('click', buscarTag);
elTag.addEventListener('keydown', e => { if (e.key === 'Enter') buscarTag(); });

btnAdicionar.addEventListener('click', () => {
    const id = parseInt(elIdCadastro.value);

    if (!id || isNaN(id) || id <= 0) {
        alert("Busque um item primeiro");
        return;
    }

    if (itens.find(i => i.id === id)) {
        alert("Este item já foi adicionado");
        return;
    }

    itens.push({
        id:        id,
        tag:       elTag.value.trim().toUpperCase(),
        descricao: elDescricao.value,
        marca:     elMarca.value,
        unidade:   elUnidadeOrig.value,
        setor:     elSetorOrig.value
    });

    renderTabela();
    limparBusca();
});

function renderTabela() {
    tbodyItens.innerHTML = '';

    itens.forEach((item, idx) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${idx + 1}</td>
            <td>${esc(item.tag)}</td>
            <td>${esc(item.descricao)}</td>
            <td>${esc(item.marca)}</td>
            <td>${esc(item.unidade)} / ${esc(item.setor)}</td>
            <td><button class="btn-remover" title="Remover" data-idx="${idx}">✕</button></td>
        `;
        tbodyItens.appendChild(tr);
    });

    caixaItens.style.display = itens.length > 0 ? 'block' : 'none';

    tbodyItens.querySelectorAll('.btn-remover').forEach(btn => {
        btn.addEventListener('click', () => {
            itens.splice(parseInt(btn.dataset.idx), 1);
            renderTabela();
        });
    });
}

function limparBusca() {
    elTag.value          = '';
    elDescricao.value    = '';
    elMarca.value        = '';
    elModelo.value       = '';
    elSerie.value        = '';
    elUnidadeOrig.value  = '';
    elSetorOrig.value    = '';
    elAreaOrig.value     = '';
    elIdCadastro.value   = '';
    atualizarBadge();
}

btnMovimentar.addEventListener('click', () => {
    if (itens.length === 0)    { alert("Adicione pelo menos um item"); return; }
    if (!ssUnidade.value)      { alert("Informe a Unidade de Destino"); return; }
    if (!ssSetor.value)        { alert("Informe o Setor de Destino"); return; }

    const origemUnidade = itens[0].unidade;
    const origemSetor   = itens[0].setor;

    const fd = new FormData();

    itens.forEach(item => fd.append('itens[]', item.id));

    fd.append('unidade_destino',   ssUnidade.value.trim().toUpperCase());
    fd.append('setor_destino',     ssSetor.value.trim().toUpperCase());
    fd.append('pavimento_destino', ssPav.value.trim().toUpperCase());
    fd.append('area_destino',      ssLocal.value.trim().toUpperCase());
    fd.append('obs_movimentacao',  elObs.value.trim().toUpperCase());
    fd.append('definitiva',        elMovDef.checked ? '1' : '0');

    fetch('movimentar_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.sucesso) { alert(d.erro); return; }

            if (retornoPara !== 'inicial.php') {
                sessionStorage.removeItem('movimentar_retorno');
                window.location.href = retornoPara;
                return;
            }

            const qs = d.ids.map(i => 'ids[]=' + i).join('&')
                + '&unidade_orig=' + encodeURIComponent(origemUnidade)
                + '&setor_orig='   + encodeURIComponent(origemSetor);

            window.open("formulario_movimentacao.php?" + qs, "_blank");

            alert("Movimentação registrada com sucesso!");
            btnLimpar.click();
        })
        .catch(e => alert("Erro: " + e.message));
});

btnLimpar.addEventListener('click', () => {
    itens = [];
    renderTabela();
    limparBusca();

    ssUnidade.resetValor();
    ssSetor.limpar();
    ssPav.limpar();
    ssLocal.limpar();
    elObs.value      = '';
    elMovDef.checked = false;
});

btnVoltar.addEventListener('click', () => {
    sessionStorage.removeItem('movimentar_retorno');
    window.location.href = retornoPara;
});

if (retornoPara !== 'inicial.php') {
    const btnHistorico = document.getElementById('btnHistorico');
    if (btnHistorico) btnHistorico.style.display = 'none';
}

if (window.location.search.includes("msg=sucesso")) {
    setTimeout(() => {
        const url = new URL(window.location);
        url.searchParams.delete("msg");
        window.history.replaceState({}, document.title, url.pathname);
    }, 3000);
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