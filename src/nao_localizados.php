<?php
session_start();
require 'conexao.php';
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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Itens Não Localizados</title>
<link rel="icon" type="image/png" href="/logo_1.png">

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background: linear-gradient(135deg, #001435, #60a5fa);
    min-height: 100vh;
    padding: 15px;
}

.form-card {
    background: #fff;
    border-radius: 16px;
    padding: 20px;
    max-width: 1400px;
    margin: auto;
}

h1 {
    text-align: center;
    margin-bottom: 18px;
    font-size: 1.5rem;
    color: #111827;
}

/* ── FILTROS ── */
.filtros-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 16px;
}

.field label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 4px;
    color: #374151;
}

.field select {
    width: 100%;
    padding: 9px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    font-size: 14px;
    outline: none;
    background: #fff;
    cursor: pointer;
}

.field select:focus {
    border-color: #2563eb;
}

/* ── CARDS DE TOTAIS ── */
/* Alteração 2: 3 colunas */
.totais-container {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 14px;
    margin-bottom: 16px;
}

.card-total {
    background: #2564eb38;
    color: #000;
    padding: 16px 18px;
    border-radius: 14px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.2);
    display: flex;
    flex-direction: column;
    justify-content: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card-total:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(0,0,0,0.3);
}

.titulo-total {
    font-size: 14px;
    opacity: 0.85;
    margin-bottom: 6px;
}

.numero-total {
    font-size: 30px;
    font-weight: bold;
    letter-spacing: 1px;
}

/* ── ACTIONS ── */
.actions {
    display: flex;
    gap: 10px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.btn {
    flex: 1;
    min-width: 100px;
    padding: 10px 12px;
    border: none;
    border-radius: 8px;
    background: #2563eb;
    color: #fff;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: background 0.2s;
    white-space: nowrap;
    text-align: center;
}

.btn:hover { background: #1e40af; }

.btn-secondary {
    background: #e5e7eb;
    color: #111;
}

.btn-secondary:hover { background: #d1d5db; }

/* ── TABELA ── */
.table-container {
    overflow-x: auto;
    max-height: 60vh;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    -webkit-overflow-scrolling: touch;
}

table {
    width: 100%;
    border-collapse: collapse;
    table-layout: auto;
    min-width: 1200px;
    font-size: 13px;
}

th, td {
    padding: 9px 13px;
    text-align: left;
    white-space: normal;
    word-break: normal;
    overflow-wrap: break-word;
    border-bottom: 1px solid #e5e7eb;
}

thead th {
    position: sticky;
    top: 0;
    background: #2563eb;
    color: #fff;
    font-size: 12px;
    padding: 10px 13px;
    white-space: nowrap;
}

tbody tr:hover td {
    background: #eff6ff;
}

td .btn-loc {
    display: inline-block;
    padding: 7px 12px;
    border: none;
    border-radius: 7px;
    background: #2563eb;
    color: #fff;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    transition: background 0.2s;
}

td .btn-loc:hover { background: #1e40af; }

/* ── MOBILE ── */
@media (max-width: 640px) {
    body { padding: 10px; }
    .form-card { padding: 14px 12px; border-radius: 12px; }
    h1 { font-size: 1.2rem; margin-bottom: 14px; }
    .filtros-grid { grid-template-columns: 1fr; gap: 10px; }
    .totais-container { grid-template-columns: 1fr; gap: 10px; }
    .numero-total { font-size: 24px; }
    .actions { flex-direction: column; gap: 8px; }
    .btn { width: 100%; flex: none; }
    table { font-size: 12px; }
    thead th { font-size: 11px; padding: 8px 10px; }
    th, td { padding: 7px 10px; }
}

</style>
</head>

<body>
<div class="form-card">

    <h1>Itens Não Localizados</h1>

    <!-- FILTROS -->
    <div class="filtros-grid">
        <div class="field">
            <label>Unidade</label>
            <select id="filtroUnidade">
                <option value="">Selecione</option>
            </select>
        </div>
        <div class="field">
            <label>Setor</label>
            <select id="filtroSetor" disabled>
                <option value="">Selecione</option>
            </select>
        </div>
    </div>

    <!-- CARDS TOTAIS — Alteração 2: terceiro card adicionado -->
    <div class="totais-container">
        <div class="card-total">
            <span class="titulo-total">Total geral de não localizados</span>
            <span class="numero-total" id="totalGeral">0</span>
        </div>
        <div class="card-total">
            <span class="titulo-total">Total na unidade selecionada</span>
            <span class="numero-total" id="totalUnidade">0</span>
        </div>
        <div class="card-total">
            <span class="titulo-total">Total de não localizados no setor</span>
            <span class="numero-total" id="totalSetor">0</span>
        </div>
    </div>

    <!-- ACTIONS -->
    <div class="actions">
        <button class="btn btn-secondary" onclick="window.location='inicial.php'">Voltar</button>
        <button class="btn" onclick="location.reload()">Atualizar</button>
        <button class="btn" id="btnBuscar">Pesquisar</button>
    </div>

    <!-- TABELA -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>UNIDADE</th>
                    <th>SETOR</th>
                    <th>PAVIMENTO</th>
                    <th>ÁREA</th>
                    <th>TAG PATRIMÔNIO</th>
                    <th>TAG NOVA</th>
                    <th>PROPRIEDADE</th>
                    <th>EMPRESA</th>
                    <th>TAG ALUGADO</th>
                    <th>DESCRIÇÃO</th>
                    <th>MARCA</th>
                    <th>MODELO</th>
                    <th>SÉRIE</th>
                    <th>OBS</th>
                    <th>DATA INSPEÇÃO</th>
                    <th>USUÁRIO INSPEÇÃO</th>
                    <th>LOCALIZADO</th>
                </tr>
            </thead>
            <tbody id="tbody"></tbody>
        </table>
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


const unidadeSel = document.getElementById("filtroUnidade");
const setorSel   = document.getElementById("filtroSetor");

document.getElementById("btnBuscar").addEventListener("click", buscar);

/* ── CARREGAR FILTROS ── */

function carregar(nivel, params = {}) {
    const qs = new URLSearchParams({ nivel, ...params }).toString();
    return fetch("nao_localizados_filtros.php?" + qs).then(r => r.json());
}

function popular(select, dados, campo) {
    select.innerHTML = '<option value="">Selecione</option>';
    dados.forEach(d => {
        const opt = document.createElement("option");
        opt.value = d[campo];
        opt.textContent = d[campo];
        select.appendChild(opt);
    });
    select.disabled = false;
}

carregar('unidade').then(d => popular(unidadeSel, d, 'unidade'));

unidadeSel.onchange = () => {
    setorSel.disabled = true;
    setorSel.innerHTML = '<option value="">Selecione</option>';
    document.getElementById("totalSetor").textContent = '0';

    if (!unidadeSel.value) {
        carregarTotais();
        return;
    }

    carregarTotais(unidadeSel.value);
    carregar('setor', { unidade: unidadeSel.value }).then(d => popular(setorSel, d, 'setor'));
};

// Alteração 2: ao mudar o setor, atualiza o card do setor
setorSel.onchange = () => {
    carregarTotais(unidadeSel.value, setorSel.value);
};

/* ── BUSCAR DADOS ── */

function buscar() {
    if (!unidadeSel.value || !setorSel.value) {
        alert("Selecione unidade e setor");
        return;
    }

    const fd = new FormData();
    fd.append('unidade', unidadeSel.value);
    fd.append('setor', setorSel.value);

    fetch("nao_localizados_buscar.php", { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        const tb = document.getElementById("tbody");
        tb.innerHTML = '';

        if (d.length === 0) {
            alert("Nenhum item não localizado");
            return;
        }

        d.forEach(r => {
            const tr = document.createElement("tr");
            tr.id = "row_" + r.id;
            tr.innerHTML = `
                <td>${esc(r.unidade ?? '')}</td>
                <td>${esc(r.setor ?? '')}</td>
                <td>${esc(r.pavimento ?? '')}</td>
                <td>${esc(r.area ?? '')}</td>
                <td>${esc(r.tag_antiga ?? '')}</td>
                <td>${esc(r.tag_trocada ?? '')}</td>
                <td>${esc(r.propriedade ?? '')}</td>
                <td>${esc(r.empresa ?? '')}</td>
                <td>${esc(r.tag_alugado ?? '')}</td>
                <td>${esc(r.descricao ?? '')}</td>
                <td>${esc(r.marca ?? '')}</td>
                <td>${esc(r.modelo ?? '')}</td>
                <td>${esc(r.serie ?? '')}</td>
                <td>${esc(r.observacao ?? '')}</td>
                <td>${esc(r.data_inspecao ?? '')}</td>
                <td>${esc(r.usuario_inspecao ?? '')}</td>
                <td>
                    <button class="btn-loc" onclick="localizar(${esc(r.id)})">
                        ITEM LOCALIZADO
                    </button>
                </td>`;
            tb.appendChild(tr);
        });
    });
}

/* ── MARCAR COMO LOCALIZADO ── */

function localizar(id) {
    if (!confirm("Confirmar que o item foi localizado?")) return;

    fetch("nao_localizados_localizar.php", {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id })
    })
    .then(r => r.json())
    .then(j => {
        if (j.sucesso) {
            document.getElementById("row_" + id).remove();
            // Atualiza contagens após localizar um item
            carregarTotais(unidadeSel.value, setorSel.value);
        } else {
            alert("Erro: " + j.erro);
        }
    });
}

/* ── CARREGAR TOTAIS ── */

function carregarTotais(unidade = '', setor = '') {
    const qs = new URLSearchParams({ unidade, setor }).toString();
    fetch("nao_localizados_totais.php?" + qs)
    .then(r => r.json())
    .then(d => {
        document.getElementById("totalGeral").textContent   = d.total_geral   ?? 0;
        document.getElementById("totalUnidade").textContent = d.total_unidade  ?? 0;
        document.getElementById("totalSetor").textContent   = d.total_setor    ?? 0;
    });
}

document.addEventListener("DOMContentLoaded", function () {
    carregarTotais();
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