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

$origem     = $_GET['origem'] ?? '';
$url_voltar = ($origem === 'inicial') ? 'inicial.php' : 'baixa_definitiva.php';
$is_dev     = ($classe_usuario === 'DEV');

$result = $conn->query("
    SELECT id, tag, descricao, marca, modelo, serie,
           unidade, setor, obs, foto,
           dt_descarte, nao_conformidade, protocolo,
           ass_patrimonio, ass_acompanhante, resp_tecnico
    FROM baixa_definitiva
    ORDER BY id DESC
");

$itens = [];
while ($row = $result->fetch_assoc()) {
    $itens[] = [
        'id'               => $row['id'],
        'tag'              => $row['tag'],
        'descricao'        => $row['descricao'],
        'marca'            => $row['marca'],
        'modelo'           => $row['modelo'],
        'serie'            => $row['serie'],
        'unidade'          => $row['unidade'],
        'setor'            => $row['setor'],
        'obs'              => $row['obs'],
        'dt_descarte'      => $row['dt_descarte'] ? date('d/m/Y', strtotime($row['dt_descarte'])) : '-',
        'nao_conformidade' => $row['nao_conformidade'],
        'protocolo'        => $row['protocolo']
            ? str_pad(ltrim($row['protocolo'], '0') ?: '0', 6, '0', STR_PAD_LEFT) : '-',
        'ass_patrimonio'   => $row['ass_patrimonio'],
        'ass_acompanhante' => $row['ass_acompanhante'],
        'resp_tecnico'     => $row['resp_tecnico'],
        'foto_base64'      => $row['foto'] ? base64_encode($row['foto']) : null,
    ];
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Histórico de Descarte</title>
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

.busca-wrap{
    display:flex; gap:10px; align-items:center; flex-wrap:wrap;
}
.busca-wrap input{
    flex:1; min-width:0;
    padding:10px 14px; border:1px solid #ccc;
    border-radius:10px; font-size:14px; outline:none;
}
.busca-wrap input:focus{ border-color:#3b82f6; }
.contador-label{ font-size:13px; color:#6b7280; white-space:nowrap; }

.scroll-box{
    max-height:620px; overflow-y:auto;
    border:1px solid #e5e7eb; border-radius:12px;
    padding:14px; background:#f9fafb;
}

.cards-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:14px;
}

.item-card{
    border-radius:12px; overflow:hidden;
    box-shadow:0 4px 12px rgba(0,0,0,.12);
    background:#fff; border:2px solid #e5e7eb;
    cursor:pointer; transition:border-color .15s, box-shadow .15s;
}
.item-card.selecionado{
    border-color:#3b82f6;
    box-shadow:0 0 0 3px rgba(59,130,246,.3);
}
.btn-danger{ background:#dc2626; color:#fff; }
.btn-danger:hover{ background:#b91c1c; }
.btn-danger:disabled{ background:#9ca3af; cursor:not-allowed; }
.card-foto{
    width:100%; aspect-ratio:3/4;
    object-fit:cover; display:block; background:#d1d5db;
}
.card-foto-placeholder{
    width:100%; aspect-ratio:3/4; background:#d1d5db;
    display:flex; align-items:center; justify-content:center;
    color:#6b7280; font-size:13px;
}
.card-descricao{
    background:#374151; color:#fff; text-align:center;
    padding:7px 8px; font-size:13px; font-weight:700;
}
.card-row{ display:flex; }
.card-cell{
    flex:1; padding:5px 7px; font-size:11px; font-weight:600;
    text-align:center; border-top:1px solid #e5e7eb; word-break:break-word;
}
.card-cell:nth-child(odd){ background:#111827; color:#fff; }
.card-cell:nth-child(even){ background:#6b7280; color:#fff; }
.card-full{
    background:#374151; color:#fff; text-align:center;
    padding:5px 8px; font-size:11px; border-top:1px solid #4b5563;
    word-break:break-word;
}
.card-full.dark{ background:#111827; }
.card-full.mid { background:#6b7280; }
.card-full.blue{ background:#1d4ed8; }

.sem-resultado{
    text-align:center; color:#9ca3af;
    padding:40px 0; font-size:14px; grid-column:1/-1;
}

.actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:4px; }
.btn{
    flex:1; padding:11px; border:none; border-radius:12px;
    font-size:14px; font-weight:600; background:#3b82f6;
    color:#fff; cursor:pointer; min-width:120px; transition:.15s;
}
.btn:hover{ background:#2563eb; }
.btn-secondary{ background:#e5e7eb; color:#111827; }
.btn-secondary:hover{ background:#d1d5db; }

@media(max-width:700px){
    .cards-grid{ grid-template-columns:repeat(2,1fr); gap:10px; }
}

@media(max-width:480px){
    body{ padding:8px; }
    .form-card{ padding:14px 12px; border-radius:12px; }
    h1{ font-size:1.2rem; margin-bottom:14px; }
    .caixa{ padding:12px 10px; border-radius:10px; margin-bottom:14px; }
    .busca-wrap input{ font-size:13px; }
    .scroll-box{ padding:10px; max-height:none; overflow-y:visible; }

    .cards-grid{ grid-template-columns:1fr; gap:10px; }

    .item-card{
        display:grid;
        grid-template-columns:90px 1fr;
    }
    .card-foto,
    .card-foto-placeholder{
        width:90px;
        aspect-ratio:unset;
        height:100%;
        min-height:110px;
        grid-row:1 / span 99;
    }
    .card-info{ display:flex; flex-direction:column; }
    .card-descricao{ font-size:12px; padding:6px 8px; text-align:left; }
    .card-cell{ font-size:10px; padding:4px 5px; }
    .card-full{ font-size:10px; padding:4px 7px; text-align:left; }

    .actions{ gap:8px; }
    .btn{ font-size:13px; padding:11px 8px; min-width:80px; }
}
</style>
</head>

<body>
<div class="form-card">
<h1>Histórico de Descarte</h1>

<div class="caixa">
    <h2>Filtro</h2>
    <div class="busca-wrap">
        <input type="text" id="inputFiltro"
               placeholder="Buscar por descrição, tag, protocolo, marca...">
        <span class="contador-label" id="contadorLabel">
            <?= count($itens) ?> registro(s)
        </span>
    </div>
</div>

<div class="caixa">
    <h2>Itens Descartados</h2>
    <div class="scroll-box">
        <div class="cards-grid" id="cardsGrid"></div>
    </div>
</div>

<div class="actions">
    <button class="btn btn-secondary"
            onclick="location.href='<?= htmlspecialchars($url_voltar) ?>'">Voltar</button>
    <?php if ($is_dev): ?>
    <button class="btn btn-danger" id="btnExcluir" disabled
            onclick="excluirSelecionado()">Excluir Selecionado</button>
    <?php endif; ?>
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

const todosItens = <?= json_encode($itens, JSON_UNESCAPED_UNICODE) ?>;
const isDev      = <?= $is_dev ? 'true' : 'false' ?>;
let cardSelecionado = null; // { id, elemento }

function setSelecionado(card, id) {
    if (cardSelecionado?.elemento) cardSelecionado.elemento.classList.remove('selecionado');
    if (cardSelecionado?.id === id) {
        // clicou no mesmo → deseleciona
        cardSelecionado = null;
        if (isDev) document.getElementById('btnExcluir').disabled = true;
        return;
    }
    card.classList.add('selecionado');
    cardSelecionado = { id, elemento: card };
    if (isDev) document.getElementById('btnExcluir').disabled = false;
}

function renderCards(itens){
    const grid = document.getElementById('cardsGrid');
    document.getElementById('contadorLabel').textContent = itens.length + ' registro(s)';
    grid.innerHTML = '';
    cardSelecionado = null;
    if (isDev) document.getElementById('btnExcluir').disabled = true;

    if(itens.length === 0){
        grid.innerHTML = '<div class="sem-resultado">Nenhum registro encontrado.</div>';
        return;
    }

    itens.forEach(item => {
        const fotoSrc = item.foto_base64
            ? 'data:image/jpeg;base64,' + item.foto_base64 : null;
        const patrimonio = (item.nao_conformidade === 'SIM' || item.nao_conformidade === 'SEM TAG')
            ? 'SEM PATRIMÔNIO' : (item.tag || '-');

        const card = document.createElement('div');
        card.className = 'item-card';
        card.dataset.id = item.id;
        card.innerHTML = `
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
                <div class="card-full">${esc(item.obs || '-')}</div>
                <div class="card-row">
                    <div class="card-cell">${esc(item.unidade || '-')}</div>
                    <div class="card-cell">${esc(item.setor || '-')}</div>
                </div>
                <div class="card-full dark">Protocolo: ${esc(item.protocolo || '-')}</div>
                <div class="card-full mid">Patrimônio: ${esc(item.ass_patrimonio || '-')}</div>
                <div class="card-full">Acompanhante: ${esc(item.ass_acompanhante || '-')}</div>
                <div class="card-full blue">Resp. Técnico: ${esc(item.resp_tecnico || '-')}</div>
            </div>`;

        card.addEventListener('click', () => setSelecionado(card, item.id));
        grid.appendChild(card);
    });
}

function excluirSelecionado() {
    if (!cardSelecionado) return;
    const { id, elemento } = cardSelecionado;
    const descr = elemento.querySelector('.card-descricao')?.textContent?.trim() || 'este registro';
    if (!confirm(`Excluir permanentemente "${descr}" (ID ${id}) do histórico de baixa?\n\nEsta ação também reverterá o status no cadastro.`)) return;

    const btn = document.getElementById('btnExcluir');
    btn.disabled = true;
    btn.textContent = 'Excluindo...';

    const fd = new FormData();
    fd.append('id', id);
    fetch('baixa_historico_excluir.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => {
            if (d.sucesso) {
                elemento.remove();
                cardSelecionado = null;
                // atualiza array e contador
                const idx = todosItens.findIndex(i => i.id === id);
                if (idx !== -1) todosItens.splice(idx, 1);
                document.getElementById('contadorLabel').textContent = todosItens.length + ' registro(s)';
                btn.textContent = 'Excluir Selecionado';
                // verifica se grid ficou vazio
                if (!document.querySelector('.item-card')) {
                    document.getElementById('cardsGrid').innerHTML = '<div class="sem-resultado">Nenhum registro encontrado.</div>';
                }
            } else {
                alert('Erro: ' + (d.erro || 'desconhecido'));
                btn.disabled = false;
                btn.textContent = 'Excluir Selecionado';
            }
        })
        .catch(e => {
            alert('Erro de comunicação: ' + e.message);
            btn.disabled = false;
            btn.textContent = 'Excluir Selecionado';
        });
}

document.getElementById('inputFiltro').addEventListener('input', function(){
    const termo = this.value.trim().toLowerCase();
    if(!termo){ renderCards(todosItens); return; }
    const filtrados = todosItens.filter(item =>
        [item.tag, item.descricao, item.marca, item.modelo,
         item.serie, item.unidade, item.setor, item.obs,
         item.dt_descarte, item.nao_conformidade, item.protocolo,
         item.ass_patrimonio, item.ass_acompanhante, item.resp_tecnico]
        .some(val => val && val.toString().toLowerCase().includes(termo))
    );
    renderCards(filtrados);
});

renderCards(todosItens);
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