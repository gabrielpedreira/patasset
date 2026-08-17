<?php
session_start();
include 'conexao.php';
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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pré Descarte</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<style>
*{box-sizing:border-box;margin:0;padding:0}

body{
    font-family:Arial,sans-serif;
    background:linear-gradient(135deg,#001435,#60a5fa);
    min-height:100vh;
    padding:16px;
    display:flex;
    justify-content:center;
    align-items:flex-start;
}

.form-card{
    background:#fff;
    width:100%;
    max-width:820px;
    padding:24px 20px;
    border-radius:16px;
    box-shadow:0 12px 30px rgba(0,0,0,.25);
}

.form-card h1{
    text-align:center;
    font-size:1.6rem;
    margin-bottom:18px;
    color:#111827;
}

.caixa{
    border:1px solid #ccc;
    padding:18px 16px;
    border-radius:14px;
    margin-bottom:20px;
}

.caixa h2{ margin:0 0 14px; color:#3b82f6; font-size:1rem; }

.field{ margin-bottom:14px; }
.field label{ display:block; margin-bottom:5px; font-weight:600; font-size:13px; }
.field input, .field select{
    width:100%; padding:10px 12px;
    border:1px solid #ccc; border-radius:10px;
    font-size:14px; outline:none; text-transform:uppercase;
}
.field input:disabled{ background:#e5e7eb; }
.field input[type="file"]{ text-transform:none; padding:7px 12px; cursor:pointer; }

.check-sem-pat{
    display:flex; align-items:center; gap:10px;
    margin-bottom:18px; padding:11px 14px;
    background:#eff6ff; border:1px solid #bfdbfe;
    border-radius:10px; cursor:pointer;
}
.check-sem-pat input[type="checkbox"]{ width:18px; height:18px; cursor:pointer; accent-color:#3b82f6; flex-shrink:0; }
.check-sem-pat span{ font-size:13px; font-weight:600; color:#1d4ed8; }

.busca-row{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }

.lista-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:8px; }
.lista-header h2{ margin:0; color:#3b82f6; font-size:1rem; }
.contador{ background:#3b82f6; color:#fff; font-size:12px; font-weight:700; padding:4px 12px; border-radius:20px; }

.lista-vazia{ text-align:center; color:#9ca3af; font-size:13px; padding:18px 0; }

.lista-tabela{ width:100%; border-collapse:collapse; font-size:13px; }
.lista-tabela thead tr{ background:#eff6ff; }
.lista-tabela th{ padding:8px 10px; text-align:left; font-weight:700; color:#1d4ed8; border-bottom:2px solid #bfdbfe; white-space:nowrap; }
.lista-tabela td{ padding:8px 10px; border-bottom:1px solid #e5e7eb; vertical-align:middle; color:#111827; }
.lista-tabela tr:last-child td{ border-bottom:none; }
.lista-tabela tr:hover td{ background:#f9fafb; }

.cards-itens{ display:none; flex-direction:column; gap:10px; }
.item-card{
    background:#f8fafc; border:1.5px solid #e2e8f0;
    border-radius:10px; padding:12px 14px; position:relative;
}
.item-card .ic-titulo{ font-weight:800; font-size:13px; color:#1e3a8a; margin-bottom:6px; padding-right:28px; }
.item-card .ic-linha{ font-size:12px; color:#475569; margin-bottom:3px; }
.item-card .ic-linha span{ font-weight:600; color:#0f172a; }
.item-card .btn-remover{ position:absolute; top:10px; right:10px; }

.tag-badge{ background:#dbeafe; color:#1d4ed8; padding:2px 8px; border-radius:6px; font-weight:700; font-size:12px; }
.sem-pat-badge{ background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:6px; font-weight:700; font-size:11px; }
.foto-badge{ background:#dcfce7; color:#166534; padding:2px 8px; border-radius:6px; font-size:11px; }

.btn-remover{ background:none; border:none; color:#ef4444; font-size:20px; cursor:pointer; padding:0 4px; line-height:1; }
.btn-remover:hover{ color:#dc2626; }

.actions{ display:flex; gap:10px; flex-wrap:wrap; }
.btn{ flex:1; padding:12px; border:none; border-radius:12px; font-size:14px; font-weight:600; background:#3b82f6; color:#fff; cursor:pointer; min-width:120px; transition:.15s; }
.btn:hover{ background:#2563eb; }
.btn-secondary{ background:#e5e7eb; color:#111827; }
.btn-secondary:hover{ background:#d1d5db; }
.btn-danger{ background:#990000; color:#fff; }
.btn-danger:hover{ background:#dc2626; }
.btn-success{ background:#2563eb; color:#fff; }
.btn-success:hover{ background:#1850c9; }
.btn-add{ background:#22c55e; color:#fff; }
.btn-add:hover{ background:#16a34a; }

#termo-overlay{
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.55); z-index:9000;
    justify-content:center; align-items:flex-start;
    padding:16px; overflow-y:auto;
}
#termo-overlay.ativo{ display:flex; }

#termo-doc{
    background:#fff; width:100%; max-width:780px;
    padding:32px 28px; border-radius:10px;
    box-shadow:0 20px 50px rgba(0,0,0,.3);
    font-family:Arial,sans-serif; color:#111;
    font-size:13px; line-height:1.5;
}

.termo-logo{ display:block; height:50px; margin:0 auto 10px; }
.termo-titulo{ text-align:center; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
.termo-subtitulo{ text-align:center; font-size:11px; color:#555; margin-bottom:18px; }

.termo-meta{ display:grid; grid-template-columns:1fr 1fr; gap:10px; border:1px solid #ccc; border-radius:6px; padding:10px 14px; margin-bottom:16px; font-size:12px; }
.termo-meta span strong{ display:block; font-size:10px; color:#555; margin-bottom:2px; }

.termo-tabela-wrap{ overflow-x:auto; margin-bottom:16px; -webkit-overflow-scrolling:touch; }
.termo-tabela{ width:100%; border-collapse:collapse; font-size:11px; min-width:600px; }
.termo-tabela th{ background:#111827; color:#fff; padding:6px 8px; text-align:left; font-weight:700; white-space:nowrap; }
.termo-tabela td{ padding:5px 8px; border-bottom:1px solid #ddd; vertical-align:top; }
.termo-tabela tr:nth-child(even) td{ background:#f9fafb; }

.termo-texto{ font-size:11px; color:#333; border:1px solid #ddd; border-radius:6px; padding:10px 14px; margin-bottom:20px; line-height:1.6; }

.termo-assinaturas{ display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:28px; }
.termo-ass-bloco{ text-align:center; }
.termo-ass-bloco .linha-ass{ border-top:1px solid #111; margin-bottom:6px; padding-top:6px; }
.termo-ass-bloco p{ margin:2px 0; font-size:11px; }

.termo-acoes{ display:flex; gap:10px; margin-top:20px; justify-content:flex-end; flex-wrap:wrap; }

@media print{
    body{ background:#fff !important; padding:0; margin:0; }
    body > *:not(#termo-overlay){ display:none !important; }
    #termo-overlay{ display:block !important; position:static !important; background:none !important; padding:0 !important; }
    #termo-doc{ box-shadow:none !important; border-radius:0 !important; padding:16px 20px !important; max-width:100% !important; }
    .termo-acoes{ display:none !important; }
    .termo-tabela{ min-width:unset; font-size:10px; }
    .termo-meta{ grid-template-columns:repeat(4,1fr); }
}

@media(max-width:600px){
    body{ padding:8px; }
    .form-card{ padding:14px 12px; border-radius:12px; }
    .form-card h1{ font-size:1.25rem; margin-bottom:14px; }
    .caixa{ padding:12px 10px; border-radius:10px; margin-bottom:14px; }
    .busca-row{ grid-template-columns:1fr; gap:0; }
    .check-sem-pat span{ font-size:12px; }
    .lista-tabela-wrap{ display:none; }
    .cards-itens{ display:flex; }
    .actions{ gap:8px; }
    .btn{ font-size:13px; padding:11px 8px; min-width:80px; }
    #termo-doc{ padding:18px 14px; }
    .termo-titulo{ font-size:12px; }
    .termo-subtitulo{ font-size:10px; }
    .termo-meta{ grid-template-columns:1fr 1fr; gap:8px; font-size:11px; padding:8px 10px; }
    .termo-assinaturas{ grid-template-columns:1fr; gap:20px; }
    .termo-acoes{ justify-content:stretch; }
    .termo-acoes .btn{ flex:1; }
}
</style>
</head>

<body>
<div class="form-card">
<h1>Pré Descarte</h1>

<!-- FORMULÁRIO DE ITEM -->
<div class="caixa">
    <h2>Dados do Item</h2>

    <label class="check-sem-pat">
        <input type="checkbox" id="chkSemPatrimonio">
        <span>SEM PATRIMÔNIO (item sem tag cadastrada)</span>
    </label>

    <div id="areaBusca">
        <div class="busca-row">
            <div class="field">
                <label>Tag</label>
                <input id="tag" placeholder="Buscar por TAG...">
            </div>
            <div class="field">
                <label>N° de Série</label>
                <input id="serie_busca" placeholder="Buscar por N° Série...">
            </div>
        </div>
    </div>

    <div class="field"><label>Descrição</label><input id="descricao" disabled></div>
    <div class="field"><label>Marca</label><input id="marca" disabled></div>
    <div class="field"><label>Modelo</label><input id="modelo" disabled></div>
    <div class="field"><label>N° de Série</label><input id="serie" disabled></div>
    <div class="field"><label>Unidade de Origem</label><input id="unidade_origem" disabled></div>
    <div class="field"><label>Setor de Origem</label><input id="setor_origem" disabled></div>
    <div class="field"><label>Observações</label><input id="observacao"></div>
    <div class="field"><label>Responsável Técnico</label><input id="resp_tecnico" placeholder="Nome do responsável técnico..."></div>
    <div class="field">
        <label>Foto do Item (PNG ou JPG)</label>
        <input type="file" id="foto" accept=".png,.jpg,.jpeg">
    </div>
</div>

<!-- LISTA TEMPORÁRIA -->
<div class="caixa">
    <div class="lista-header">
        <h2>Itens para Descarte</h2>
        <span class="contador" id="contador">0 item(s)</span>
    </div>

    <div class="field" id="campoUnidadeTermo" style="display:none;margin-top:10px;">
        <label>Unidade (para o Termo de Responsabilidade)</label>
        <input id="nomeUnidadeTermo" placeholder="Ex: UNIDADE CENTRO" style="text-transform:uppercase">
    </div>

    <div id="listaVazio"><p class="lista-vazia">Nenhum item adicionado ainda.</p></div>

    <div class="lista-tabela-wrap">
        <div style="overflow-x:auto">
            <table class="lista-tabela" id="listaTabela" style="display:none">
                <thead>
                    <tr>
                        <th>#</th><th>Tag</th><th>Descrição</th><th>Marca</th>
                        <th>Série</th><th>Unidade</th><th>Setor</th>
                        <th>Obs</th><th>Resp. Técnico</th><th>Foto</th><th></th>
                    </tr>
                </thead>
                <tbody id="listaTbody"></tbody>
            </table>
        </div>
    </div>

    <div class="cards-itens" id="cardsItens"></div>
</div>

<!-- AÇÕES -->
<div class="actions">
    <button class="btn btn-secondary" onclick="location.href='inicial.php'">Voltar</button>
    <button class="btn btn-danger"    onclick="location.href='baixa_definitiva.php'">Baixa Definitiva</button>
    <button class="btn btn-add"       id="btnAdicionar">Adicionar +</button>
    <button class="btn btn-success"   id="btnSalvar">Salvar e Gerar Protocolo</button>
</div>
</div>

<!-- TERMO DE RESPONSABILIDADE -->
<div id="termo-overlay">
<div id="termo-doc">
    <img src="/logo_rede.png" alt="Logo" class="termo-logo">
    <div class="termo-titulo">Termo de Responsabilidade Técnica - Descarte Patrimonial</div>
    <div class="termo-subtitulo">Este documento deve ser assinado pelas partes antes do encaminhamento dos itens para descarte definitivo.</div>

    <div class="termo-meta">
        <span><strong>Unidade</strong><span id="t-unidade">—</span></span>
        <span><strong>Data</strong><span id="t-data">—</span></span>
        <span><strong>Protocolo</strong><span id="t-protocolo">—</span></span>
        <span><strong>Total de Itens</strong><span id="t-total">—</span></span>
    </div>

    <div class="termo-tabela-wrap">
        <table class="termo-tabela">
            <thead>
                <tr>
                    <th>#</th><th>Tag / Patrimônio</th><th>Descrição</th>
                    <th>Marca</th><th>Modelo</th><th>N° Série</th>
                    <th>Unidade Origem</th><th>Setor</th>
                    <th>Resp. Técnico</th><th>Observações</th>
                </tr>
            </thead>
            <tbody id="t-tbody"></tbody>
        </table>
    </div>

    <div class="termo-texto">
        Declaro(amos) que os bens patrimoniais listados acima estão em condição de descarte, tendo sido avaliados pelo Responsável Técnico indicado, e que as informações constantes neste documento são verídicas. O encaminhamento para descarte definitivo somente ocorrerá após análise e aprovação pela área de Patrimônio.
    </div>

    <div class="termo-assinaturas">
        <div class="termo-ass-bloco">
            <div class="linha-ass"></div>
            <p><strong id="t-resp-pat-nome">Responsável Patrimônio</strong></p>
            <p>Responsável pelo Patrimônio</p>
        </div>
        <div class="termo-ass-bloco">
            <div class="linha-ass"></div>
            <p><strong id="t-resp-tec-nome">Responsável Técnico</strong></p>
            <p>Responsável Técnico</p>
        </div>
    </div>

    <div class="termo-acoes">
        <button class="btn btn-secondary" onclick="fecharTermo()">Fechar</button>
        <button class="btn btn-success"   onclick="window.print()">🖨️ Imprimir</button>
    </div>
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

const chkSemPat      = document.getElementById('chkSemPatrimonio');
const areaBusca      = document.getElementById('areaBusca');
const tag            = document.getElementById('tag');
const serieBusca     = document.getElementById('serie_busca');
const descricao      = document.getElementById('descricao');
const marca          = document.getElementById('marca');
const modelo         = document.getElementById('modelo');
const serie          = document.getElementById('serie');
const unidade_origem = document.getElementById('unidade_origem');
const setor_origem   = document.getElementById('setor_origem');
const observacao     = document.getElementById('observacao');
const resp_tecnico   = document.getElementById('resp_tecnico');
const foto           = document.getElementById('foto');
const btnAdicionar   = document.getElementById('btnAdicionar');
const btnSalvar      = document.getElementById('btnSalvar');
const contador       = document.getElementById('contador');
const listaVazio     = document.getElementById('listaVazio');
const listaTabela    = document.getElementById('listaTabela');
const listaTbody     = document.getElementById('listaTbody');
const cardsItens     = document.getElementById('cardsItens');
const campoUnidade   = document.getElementById('campoUnidadeTermo');
const nomeUnidade    = document.getElementById('nomeUnidadeTermo');

let itemEncontrado = false;
let listaItens     = [];

chkSemPat.addEventListener('change', () => {
    const sem = chkSemPat.checked;
    areaBusca.style.display = sem ? 'none' : 'block';
    [descricao, marca, modelo, serie, unidade_origem, setor_origem].forEach(el => el.disabled = !sem);
    limparCamposDados();
    itemEncontrado = false;
});

tag.addEventListener('input', () => {
    const v = tag.value.toUpperCase(); tag.value = v; serieBusca.value = '';
    if (!v) { limparCamposDados(); return; }
    fetch('buscar_tag.php?tag=' + encodeURIComponent(v))
    .then(r => r.json()).then(d => { if(d.encontrado){ preencherDados(d); itemEncontrado=true; } else { limparCamposDados(); itemEncontrado=false; } });
});

serieBusca.addEventListener('input', () => {
    const v = serieBusca.value.trim().toUpperCase(); serieBusca.value = v; tag.value = '';
    if (!v) { limparCamposDados(); return; }
    fetch('buscar_serie.php?serie=' + encodeURIComponent(v))
    .then(r => r.json()).then(d => { if(d.encontrado){ preencherDados(d); itemEncontrado=true; } else { limparCamposDados(); itemEncontrado=false; } });
});

btnAdicionar.onclick = () => {
    const semPat = chkSemPat.checked;
    if (!semPat && !itemEncontrado) { alert('Busque e confirme o item pela TAG ou N° de Série antes de adicionar.'); return; }
    if (!descricao.value.trim()) { alert('Informe ao menos a Descrição do item.'); return; }

    const fotoFile = foto.files[0] || null;
    const addItem = (fotoBase64, fotoNome) => {
        listaItens.push({
            sem_patrimonio: semPat ? '1' : '0',
            nao_conformidade: semPat ? 'SIM' : null,
            tag: tag.value, descricao: descricao.value,
            marca: marca.value, modelo: modelo.value, serie: serie.value,
            unidade_origem: unidade_origem.value, setor_origem: setor_origem.value,
            observacao: observacao.value, resp_tecnico: resp_tecnico.value,
            fotoBase64, fotoNome, fotoFile
        });
        renderLista();
        limparFormulario();
    };
    if (fotoFile) { const r = new FileReader(); r.onload = e => addItem(e.target.result, fotoFile.name); r.readAsDataURL(fotoFile); }
    else addItem(null, null);
};

function renderLista() {
    const total = listaItens.length;
    contador.textContent = total + ' item(s)';
    campoUnidade.style.display = total > 0 ? 'block' : 'none';
    listaVazio.style.display = total === 0 ? 'block' : 'none';
    listaTabela.style.display = total === 0 ? 'none' : 'table';

    listaTbody.innerHTML = '';
    cardsItens.innerHTML = '';

    listaItens.forEach((item, idx) => {
        const tagHtml = item.tag ? `<span class="tag-badge">${esc(item.tag)}</span>` : `<span class="sem-pat-badge">S/PAT</span>`;
        const fotoHtml = item.fotoNome ? `<span class="foto-badge">📷 ${esc(item.fotoNome)}</span>` : '-';

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${idx+1}</td><td>${tagHtml}</td><td>${esc(item.descricao)}</td>
            <td>${esc(item.marca || '-')}</td><td>${esc(item.serie || '-')}</td>
            <td>${esc(item.unidade_origem || '-')}</td><td>${esc(item.setor_origem || '-')}</td>
            <td>${esc(item.observacao || '-')}</td><td>${esc(item.resp_tecnico || '-')}</td>
            <td>${fotoHtml}</td>
            <td><button class="btn-remover" onclick="removerItem(${idx})">✕</button></td>`;
        listaTbody.appendChild(tr);

        const card = document.createElement('div');
        card.className = 'item-card';
        card.innerHTML = `
            <button class="btn-remover" onclick="removerItem(${idx})" title="Remover">✕</button>
            <div class="ic-titulo">${idx+1}. ${esc(item.descricao)} ${tagHtml}</div>
            <div class="ic-linha">Marca: <span>${esc(item.marca || '-')}</span> &nbsp;|&nbsp; Série: <span>${esc(item.serie || '-')}</span></div>
            <div class="ic-linha">Unidade: <span>${esc(item.unidade_origem || '-')}</span> &nbsp;|&nbsp; Setor: <span>${esc(item.setor_origem || '-')}</span></div>
            ${item.resp_tecnico ? `<div class="ic-linha">Resp. Técnico: <span>${esc(item.resp_tecnico)}</span></div>` : ''}
            ${item.observacao ? `<div class="ic-linha">Obs: <span>${esc(item.observacao)}</span></div>` : ''}
            ${item.fotoNome ? `<div class="ic-linha">Foto: ${fotoHtml}</div>` : ''}`;
        cardsItens.appendChild(card);
    });
}

function removerItem(idx) { listaItens.splice(idx, 1); renderLista(); }

btnSalvar.onclick = async () => {
    if (listaItens.length === 0) { alert('Adicione ao menos um item antes de salvar.'); return; }
    btnSalvar.disabled = true; btnSalvar.textContent = 'Salvando...';
    const payload = listaItens.map(item => ({
        sem_patrimonio: item.sem_patrimonio, nao_conformidade: item.nao_conformidade,
        tag: item.tag, descricao: item.descricao, marca: item.marca,
        modelo: item.modelo, serie: item.serie, unidade_origem: item.unidade_origem,
        setor_origem: item.setor_origem, observacao: item.observacao,
        resp_tecnico: item.resp_tecnico, foto: item.fotoBase64
    }));
    try {
        const resp = await fetch('salvar_pre_descarte_action.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
        const r = await resp.json();
        if (r.sucesso) { abrirTermo(r.protocolo); }
        else { alert('Erro: ' + r.erro); }
    } catch(e) { alert('Erro JS: ' + e.message); }
    btnSalvar.disabled = false; btnSalvar.textContent = 'Salvar e Gerar Protocolo';
};

function abrirTermo(protocolo) {
    const hoje = new Date().toLocaleDateString('pt-BR');
    const unid = nomeUnidade.value.trim() || '—';
    const respTec = listaItens.find(i => i.resp_tecnico)?.resp_tecnico || 'Responsável Técnico';

    document.getElementById('t-unidade').textContent  = unid;
    document.getElementById('t-data').textContent     = hoje;
    document.getElementById('t-protocolo').textContent = protocolo;
    document.getElementById('t-total').textContent    = listaItens.length + ' item(s)';
    document.getElementById('t-resp-tec-nome').textContent = respTec;

    const tbody = document.getElementById('t-tbody');
    tbody.innerHTML = '';
    listaItens.forEach((item, idx) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${idx+1}</td><td>${esc(item.tag || 'SEM PATRIMÔNIO')}</td>
            <td>${esc(item.descricao || '-')}</td><td>${esc(item.marca || '-')}</td>
            <td>${esc(item.modelo || '-')}</td><td>${esc(item.serie || '-')}</td>
            <td>${esc(item.unidade_origem || '-')}</td><td>${esc(item.setor_origem || '-')}</td>
            <td>${esc(item.resp_tecnico || '-')}</td><td>${esc(item.observacao || '-')}</td>`;
        tbody.appendChild(tr);
    });

    document.getElementById('termo-overlay').classList.add('ativo');
    listaItens = [];
    renderLista();
}

function fecharTermo() { document.getElementById('termo-overlay').classList.remove('ativo'); }

function preencherDados(d) {
    descricao.value=d.descricao||''; marca.value=d.marca||''; modelo.value=d.modelo||'';
    serie.value=d.serie||''; unidade_origem.value=d.unidade||''; setor_origem.value=d.setor||'';
}
function limparCamposDados() {
    descricao.value=marca.value=modelo.value=serie.value=unidade_origem.value=setor_origem.value='';
    itemEncontrado=false;
}
function limparFormulario() {
    tag.value=''; serieBusca.value=''; observacao.value=''; resp_tecnico.value=''; foto.value='';
    chkSemPat.checked=false; areaBusca.style.display='block';
    [descricao,marca,modelo,serie,unidade_origem,setor_origem].forEach(el=>el.disabled=true);
    limparCamposDados();
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