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

$destinatarios = [];
$rd = $conn->query("SELECT id, nome, email FROM cadastro_destinatarios ORDER BY nome ASC");
while ($row = $rd->fetch_assoc()) $destinatarios[] = $row;

$protocolos = [];
$rp = $conn->query("SELECT DISTINCT protocolo FROM pre_descarte WHERE protocolo IS NOT NULL AND protocolo <> '' ORDER BY protocolo ASC");
while ($row = $rp->fetch_assoc()) $protocolos[] = $row['protocolo'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lista de Pré-Baixa</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<style>
*{box-sizing:border-box;margin:0;padding:0}

body{
    font-family:Arial,sans-serif;
    background:linear-gradient(135deg,#001435,#60a5fa);
    min-height:100vh; padding:16px;
    display:flex; justify-content:center; align-items:flex-start;
}

.form-card{
    background:#fff; width:100%; max-width:1100px;
    padding:24px 20px; border-radius:16px;
    box-shadow:0 12px 30px rgba(0,0,0,.25);
}

h1{ text-align:center; font-size:1.5rem; margin-bottom:18px; color:#111827; }

.caixa{ border:1px solid #ccc; padding:16px; border-radius:14px; margin-bottom:18px; }
.caixa h2{ margin:0 0 12px; color:#3b82f6; font-size:1rem; }

.field{ margin-bottom:14px; }
.field label{ display:block; margin-bottom:5px; font-weight:600; font-size:13px; }
.field input, .field select{
    width:100%; padding:10px 12px;
    border:1px solid #ccc; border-radius:10px;
    font-size:14px; outline:none; background:#fff;
}
.field input:disabled{ background:#e5e7eb; }
.field select{ cursor:pointer; }
.field select:focus{ border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.2); }

.row2{ display:grid; grid-template-columns:1fr 1fr; gap:14px; }

.scroll-box{
    max-height:580px; overflow-y:auto;
    border:1px solid #e5e7eb; border-radius:12px;
    padding:14px; background:#f9fafb;
}

.cards-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }

.card-wrap{ display:flex; flex-direction:column; align-items:flex-end; }
.card-checkbox{ margin-bottom:6px; display:flex; align-items:center; gap:6px; align-self:flex-end; }
.card-checkbox input[type="checkbox"]{ width:18px; height:18px; accent-color:#3b82f6; cursor:pointer; }
.card-checkbox label{ font-size:12px; font-weight:600; color:#374151; cursor:pointer; }

.item-card{
    width:100%; border-radius:12px; overflow:hidden;
    box-shadow:0 4px 12px rgba(0,0,0,.12); background:#fff;
    border:2px solid transparent; transition:border .2s;
}
.item-card.selecionado{ border-color:#3b82f6; }

.card-foto{ width:100%; aspect-ratio:3/4; object-fit:cover; display:block; background:#d1d5db; }
.card-foto-placeholder{ width:100%; aspect-ratio:3/4; background:#d1d5db; display:flex; align-items:center; justify-content:center; color:#6b7280; font-size:13px; }

.card-descricao{ background:#374151; color:#fff; text-align:center; padding:7px 8px; font-size:13px; font-weight:700; }
.card-row{ display:flex; }
.card-cell{ flex:1; padding:5px 6px; font-size:11px; font-weight:600; text-align:center; border-top:1px solid #e5e7eb; }
.card-cell:nth-child(odd){ background:#111827; color:#fff; }
.card-cell:nth-child(even){ background:#6b7280; color:#fff; }
.card-obs{ background:#374151; color:#fff; text-align:center; padding:5px 8px; font-size:11px; border-top:1px solid #4b5563; }
.card-resp{ background:#1d4ed8; color:#fff; text-align:center; padding:5px 8px; font-size:11px; border-top:1px solid #2563eb; }

.sem-resultado{ text-align:center; color:#9ca3af; padding:40px 0; font-size:14px; }

.email-info{ margin-top:6px; font-size:12px; color:#2563eb; font-weight:600; min-height:18px; }

.actions{ display:flex; gap:10px; flex-wrap:wrap; }
.btn{ flex:1; padding:11px; border:none; border-radius:12px; font-size:14px; font-weight:600; background:#3b82f6; color:#fff; cursor:pointer; min-width:120px; transition:.15s; }
.btn:hover{ background:#2563eb; }
.btn-secondary{ background:#e5e7eb; color:#111827; }
.btn-secondary:hover{ background:#d1d5db; }
.btn-danger{ background:#ef4444; color:#fff; }
.btn-danger:hover{ background:#dc2626; }
.btn-warning{ background:#f59e0b; color:#fff; }
.btn-warning:hover{ background:#d97706; }
.btn-dark{ background:#111827; color:#fff; }
.btn-dark:hover{ background:#1f2937; }

.modal-overlay{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:999; justify-content:center; align-items:center; padding:16px; }
.modal-overlay.ativo{ display:flex; }
.modal{ background:#fff; border-radius:16px; padding:28px 22px; max-width:420px; width:100%; box-shadow:0 20px 50px rgba(0,0,0,.3); text-align:center; }
.modal h3{ margin:0 0 14px; font-size:1.1rem; color:#111827; }
.modal p{ color:#374151; font-size:14px; margin-bottom:22px; line-height:1.6; }
.modal-btns{ display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
.modal-btns .btn{ flex:none; min-width:100px; }

.toast{ position:fixed; bottom:20px; right:16px; left:16px; max-width:380px; margin:0 auto; background:#111827; color:#fff; padding:13px 18px; border-radius:12px; font-size:14px; font-weight:600; z-index:9999; display:none; box-shadow:0 8px 24px rgba(0,0,0,.3); text-align:center; }
.toast.ativo{ display:block; }
.toast.sucesso{ background:#16a34a; }
.toast.erro{ background:#dc2626; }

@media print{
    body{ background:#fff !important; padding:0; }
    .form-card{ box-shadow:none; border-radius:0; padding:0; max-width:100%; }
    .no-print{ display:none !important; }
    .print-only{ display:block !important; }
    .scroll-box{ max-height:none; overflow:visible; border:none; padding:0; }
    .cards-grid{ grid-template-columns:repeat(3,1fr); gap:8px; }
    .item-card{ box-shadow:none; border:1px solid #ccc; }
    .folha-header{ display:block !important; }
    .assinaturas{ display:flex !important; }
}
.print-only{ display:none; }
.folha-header{ display:none; text-align:center; margin-bottom:20px; border-bottom:2px solid #111; padding-bottom:14px; }
.folha-header img{ height:56px; margin-bottom:8px; }
.folha-header h2{ margin:0 0 6px; font-size:1.2rem; }
.folha-header p{ margin:2px 0; font-size:13px; }
.assinaturas{ display:none; justify-content:space-around; margin-top:48px; gap:20px; flex-wrap:wrap; }
.ass-bloco{ flex:1; min-width:160px; text-align:center; border-top:1px solid #111; padding-top:8px; font-size:13px; }

@media(max-width:700px){
    .cards-grid{ grid-template-columns:repeat(2,1fr); gap:10px; }
    .row2{ grid-template-columns:1fr; gap:0; }
}
@media(max-width:480px){
    body{ padding:8px; }
    .form-card{ padding:14px 12px; border-radius:12px; }
    h1{ font-size:1.25rem; margin-bottom:14px; }
    .caixa{ padding:12px 10px; border-radius:10px; margin-bottom:14px; }
    .cards-grid{ grid-template-columns:1fr; }
    .item-card{ display:grid; grid-template-columns:100px 1fr; }
    .card-foto, .card-foto-placeholder{ width:100px; aspect-ratio:unset; height:100%; min-height:120px; }
    .card-info{ display:flex; flex-direction:column; }
    .card-descricao{ font-size:12px; padding:6px; text-align:left; }
    .card-cell{ font-size:10px; padding:4px 5px; }
    .card-obs, .card-resp{ font-size:10px; padding:4px 6px; text-align:left; }
    .actions{ gap:8px; }
    .btn{ font-size:13px; padding:11px 8px; min-width:80px; }
    .modal{ padding:22px 16px; }
    .modal p{ font-size:13px; }
}
</style>
</head>
<body>
<div class="form-card">
<h1>Lista de Pré-Baixa</h1>

<div class="folha-header print-only" id="folhaHeader">
    <img src="/logo_rede.png" alt="Logo">
    <h2>Folha de Descarte de Bens Patrimoniais</h2>
    <p><strong>Unidade:</strong> <span id="printUnidade"></span></p>
    <p><strong>Assistente Patrimonial:</strong> <span id="printAssistente"></span></p>
    <p><strong>Responsável Acompanhante:</strong> <span id="printAcompanhante"></span></p>
    <p><strong>Data:</strong> <span id="printData"></span></p>
    <p><strong>Protocolo:</strong> <span id="printProtocolo"></span></p>
</div>

<!-- BUSCA -->
<div class="caixa no-print">
    <h2>Busca por Protocolo</h2>
    <div class="row2">
        <div class="field">
            <label>Protocolo</label>
            <input id="inputProtocolo" placeholder="Ex: 000001" maxlength="10">
        </div>
        <div class="field" style="display:flex;align-items:flex-end">
            <button class="btn" style="flex:none;padding:10px 24px;width:100%" onclick="buscarProtocolo()">Buscar</button>
        </div>
    </div>
    <?php if(!empty($protocolos)): ?>
    <div class="field" style="margin-top:10px;margin-bottom:0;">
        <label>Ou selecione um protocolo disponível</label>
        <select id="selectProtocolo" onchange="selecionarProtocolo(this.value)">
            <option value="">-- Selecione --</option>
            <?php foreach($protocolos as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php else: ?>
    <p style="margin-top:10px;font-size:13px;color:#9ca3af;">Nenhum protocolo disponível no momento.</p>
    <?php endif; ?>
</div>

<!-- DADOS DA BAIXA -->
<div class="caixa no-print">
    <h2>Dados da Baixa</h2>
    <div class="row2">
        <div class="field">
            <label>Nome do Assistente Patrimonial</label>
            <input id="nomeAssistente" placeholder="Nome completo">
        </div>
        <div class="field">
            <label>Unidade</label>
            <input id="nomeUnidade" placeholder="Ex: Unidade Centro">
        </div>
    </div>
    <div class="row2">
        <div class="field">
            <label>Data</label>
            <input id="dataAtual" disabled>
        </div>
        <div class="field">
            <label>Responsável Acompanhante</label>
            <select id="selectAcompanhante" onchange="atualizarEmail()">
                <option value="" data-email="">-- Selecione --</option>
                <?php foreach($destinatarios as $d): ?>
                <option value="<?= htmlspecialchars($d['nome']) ?>"
                        data-email="<?= htmlspecialchars($d['email']) ?>">
                    <?= htmlspecialchars($d['nome']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="email-info" id="emailInfo"></div>
        </div>
    </div>
</div>

<!-- GRID ITENS -->
<div class="caixa">
    <h2 class="no-print">Itens do Protocolo</h2>
    <div class="scroll-box">
        <div class="cards-grid" id="cardsGrid">
            <div class="sem-resultado" id="semResultado" style="grid-column:1/-1">Busque um protocolo para exibir os itens.</div>
        </div>
    </div>
</div>

<!-- ASSINATURAS impressão -->
<div class="assinaturas print-only">
    <div class="ass-bloco">Responsável Patrimônio</div>
    <div class="ass-bloco">Gerente Administrativo</div>
    <div class="ass-bloco">Responsável Acompanhante</div>
</div>

<!-- AÇÕES -->
<div class="actions no-print" style="margin-bottom:10px;">
    <button class="btn btn-secondary" onclick="location.href='baixa.php'">Voltar</button>
    <button class="btn btn-dark"      onclick="location.href='baixa_historico.php?origem=baixa_definitiva'">Histórico</button>
    <button class="btn btn-warning"   id="btnRemover">Remover da Pré-Baixa</button>
    <button class="btn btn-danger"    id="btnDescartar">Descartar Itens</button>
</div>
</div>

<!-- MODAL DESCARTE -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal">
        <h3>Confirmar Descarte</h3>
        <p>Deseja dar baixa nos itens selecionados?<br>Esta ação não poderá ser desfeita.<br><br>
           Um relatório será enviado por e-mail ao responsável acompanhante.</p>
        <div class="modal-btns">
            <button class="btn btn-secondary" onclick="fecharModal()">Não</button>
            <button class="btn btn-danger"    onclick="confirmarDescarte()">Sim</button>
        </div>
    </div>
</div>

<!-- MODAL REMOVER -->
<div class="modal-overlay" id="modalRemover">
    <div class="modal">
        <h3>Remover da Pré-Baixa</h3>
        <p id="modalRemoverMsg">Deseja remover os itens selecionados da pré-baixa?<br>Os itens voltarão ao patrimônio ativo.</p>
        <div class="modal-btns">
            <button class="btn btn-secondary" onclick="fecharModalRemover()">Cancelar</button>
            <button class="btn btn-warning"   onclick="confirmarRemocao()">Remover</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

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

let itensProtocolo = [];

document.getElementById('dataAtual').value = new Date().toLocaleDateString('pt-BR');

function atualizarEmail(){
    const sel   = document.getElementById('selectAcompanhante');
    const email = sel.options[sel.selectedIndex]?.dataset.email || '';
    document.getElementById('emailInfo').textContent = email ? '📧 ' + email : '';
}

function selecionarProtocolo(prot){
    if(!prot) return;
    document.getElementById('inputProtocolo').value = prot;
    buscarProtocolo();
}

function buscarProtocolo(){
    const prot = document.getElementById('inputProtocolo').value.trim();
    if(!prot){ alert('Informe o número do protocolo.'); return; }
    fetch('buscar_protocolo.php?protocolo=' + encodeURIComponent(prot))
    .then(r => r.json())
    .then(d => {
        if(!d.encontrado || d.itens.length === 0){
            itensProtocolo = [];
            renderCards([]);
            document.getElementById('semResultado').textContent = 'Nenhum item encontrado para este protocolo.';
            document.getElementById('semResultado').style.display = 'block';
            return;
        }
        itensProtocolo = d.itens;
        renderCards(itensProtocolo);
    })
    .catch(e => alert('Erro: ' + e.message));
}

function renderCards(itens){
    const grid   = document.getElementById('cardsGrid');
    const semRes = document.getElementById('semResultado');
    grid.querySelectorAll('.card-wrap').forEach(el => el.remove());

    if(itens.length === 0){ semRes.style.display = 'block'; return; }
    semRes.style.display = 'none';

    itens.forEach((item, idx) => {
        const fotoSrc    = item.foto_base64 ? 'data:image/jpeg;base64,' + item.foto_base64 : null;
        const patrimonio = item.nao_conformidade === 'SIM' ? 'SEM PATRIMÔNIO' : (item.tag || '-');
        const respTecnico = item.resp_tecnico || '-';

        const wrap = document.createElement('div');
        wrap.className = 'card-wrap';
        wrap.innerHTML = `
            <div class="card-checkbox">
                <input type="checkbox" id="chk_${idx}" onchange="toggleCard(${idx}, this)">
                <label for="chk_${idx}">Selecionar</label>
            </div>
            <div class="item-card" id="card_${idx}">
                ${fotoSrc
                    ? `<img class="card-foto" src="${fotoSrc}" alt="Foto">`
                    : `<div class="card-foto-placeholder">Sem foto</div>`}
                <div class="card-info">
                    <div class="card-descricao">${esc(item.descricao || '-')}</div>
                    <div class="card-row">
                        <div class="card-cell">${esc(item.marca || '-')}</div>
                        <div class="card-cell">${esc(item.modelo || '-')}</div>
                    </div>
                    <div class="card-row">
                        <div class="card-cell">${esc(item.serie || '-')}</div>
                        <div class="card-cell">${esc(patrimonio)}</div>
                    </div>
                    <div class="card-obs">${esc(item.obs || '-')}</div>
                    <div class="card-resp">Resp. Técnico: ${esc(respTecnico)}</div>
                </div>
            </div>`;
        grid.appendChild(wrap);
    });
}

function toggleCard(idx, chk){
    const card = document.getElementById('card_' + idx);
    if(card) card.classList.toggle('selecionado', chk.checked);
}

function getSelecionados(){
    return itensProtocolo.filter((_, idx) => {
        const chk = document.getElementById('chk_' + idx);
        return chk && chk.checked;
    });
}

function mostrarToast(msg, tipo='sucesso'){
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast ativo ' + tipo;
    setTimeout(() => t.className = 'toast', 4500);
}

document.getElementById('btnDescartar').onclick = () => {
    if(getSelecionados().length === 0){ alert('Selecione ao menos um item para descartar.'); return; }
    document.getElementById('modalOverlay').classList.add('ativo');
};

function fecharModal(){ document.getElementById('modalOverlay').classList.remove('ativo'); }

function confirmarDescarte(){
    fecharModal();
    const sel          = document.getElementById('selectAcompanhante');
    const acompanhante = sel.value.trim();
    const emailDest    = sel.options[sel.selectedIndex]?.dataset.email || '';
    const assistente   = document.getElementById('nomeAssistente').value.trim();
    const unidade      = document.getElementById('nomeUnidade').value.trim();
    const protocolo    = document.getElementById('inputProtocolo').value.trim();
    const selecionados = getSelecionados();

    if(!assistente || !unidade || !acompanhante){
        alert('Preencha o nome do assistente, unidade e responsável acompanhante antes de descartar.');
        return;
    }
    if(!emailDest){ alert('O responsável selecionado não possui e-mail cadastrado.'); return; }

    const payload = {
        itens: selecionados.map(item => ({...item, resp_tecnico: item.resp_tecnico || ''})),
        assistente, unidade, acompanhante, protocolo,
        email_destinatario: emailDest
    };

    fetch('executar_descarte_action.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) })
    .then(r => r.json())
    .then(r => {
        if(!r.sucesso){ mostrarToast('❌ Erro no descarte: ' + r.erro, 'erro'); return; }
        return fetch('enviar_email_descarte.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) })
        .then(re => re.json())
        .then(re => {
            if(re.status === 'sucesso') mostrarToast('✅ Descarte realizado e e-mail enviado!', 'sucesso');
            else mostrarToast('⚠️ Descarte salvo, mas falha no e-mail: ' + re.msg, 'erro');
            setTimeout(() => window.location.href = 'relatorio_descarte.php', 2200);
        });
    })
    .catch(e => mostrarToast('Erro JS: ' + e.message, 'erro'));
}

document.getElementById('btnRemover').onclick = () => {
    const sel = getSelecionados();
    if(sel.length === 0){ alert('Selecione ao menos um item para remover.'); return; }
    document.getElementById('modalRemoverMsg').innerHTML =
        `Deseja remover <strong>${esc(sel.length)}</strong> item(s) selecionado(s) da pré-baixa?<br>Os itens voltarão ao patrimônio ativo.`;
    document.getElementById('modalRemover').classList.add('ativo');
};

function fecharModalRemover(){ document.getElementById('modalRemover').classList.remove('ativo'); }

function confirmarRemocao(){
    fecharModalRemover();
    const selecionados = getSelecionados();
    const ids = selecionados.map(item => item.id).filter(id => id > 0);

    if(ids.length === 0){ mostrarToast('Nenhum ID válido para remover.', 'erro'); return; }

    fetch('remover_pre_descarte.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ ids })
    })
    .then(r => r.json())
    .then(r => {
        if(!r.sucesso){ mostrarToast('❌ Erro ao remover: ' + r.erro, 'erro'); return; }
        mostrarToast(`✅ ${esc(r.removidos)} item(s) removido(s) da pré-baixa!`, 'sucesso');
        const idsRemovidos = new Set(ids);
        itensProtocolo = itensProtocolo.filter(item => !idsRemovidos.has(item.id));
        renderCards(itensProtocolo);
    })
    .catch(e => mostrarToast('Erro JS: ' + e.message, 'erro'));
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