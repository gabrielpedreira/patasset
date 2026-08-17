<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) { header("Location: index.html"); exit(); }
require_once "conexao.php";
require_once 'check_session.php';

$usuario_logado = $_SESSION['usuario_logado'] ?? '';
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $usuario_logado);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$permicao       = $row['permicao']       ?? '';
$classe_usuario = $row['classe_usuario'] ?? '';
$status_u       = $row['status']         ?? 'ATIVO';

$nome_usuario = $usuario_logado;

if ($status_u !== 'ATIVO') { session_destroy(); header("Location: index.html?erro=bloqueado"); exit(); }
if (!in_array($permicao, ['A','B']) || !in_array($classe_usuario, ['DEV','PATRIMONIO'])) {
    header("Location: acesso_bloqueado.html"); exit();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rotina de Inspeção</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;background:linear-gradient(135deg,#001435,#60a5fa);min-height:100vh;padding:20px;}

/* ── MAIN ── */
.main{max-width:1400px;margin:0 auto;}
.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;padding-bottom:14px;border-bottom:2px solid rgba(255,255,255,.2);}
.page-header h1{color:#fff;font-size:1.3rem;font-weight:800;}
.btn-group{display:flex;gap:8px;flex-wrap:wrap;}
.btn{padding:8px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;transition:.18s;}
.btn-primary{background:#2563eb;color:#fff;}.btn-primary:hover{background:#1e40af;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-amber{background:#d97706;color:#fff;}.btn-amber:hover{background:#b45309;}
.btn-slate{background:#475569;color:#fff;}.btn-slate:hover{background:#334155;}
.btn-red{background:#dc2626;color:#fff;}.btn-red:hover{background:#b91c1c;}

/* ── CARD ── */
.card{background:#fff;padding:20px 24px;border-radius:14px;box-shadow:0 8px 24px rgba(0,0,0,.2);margin-bottom:16px;}
.card-title{font-size:11px;font-weight:800;color:#1e3a8a;text-transform:uppercase;letter-spacing:.8px;margin-bottom:14px;padding-left:9px;border-left:3px solid #2563eb;}

/* ── FILTROS ── */
.filtros-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:12px;}
@media(max-width:900px){.filtros-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:500px){.filtros-grid{grid-template-columns:1fr;}}
.field-group{display:flex;flex-direction:column;gap:4px;}
.field-group label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;}
.field-group select,.field-group input[type=text]{padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;background:#fff;width:100%;}
.field-group select:focus,.field-group input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.field-group select:disabled{background:#f8fafc;color:#9ca3af;cursor:not-allowed;}
.tag-row{display:flex;gap:10px;align-items:flex-end;margin-top:4px;}
.tag-row .field-group{flex:1;}
.divider{display:flex;align-items:center;gap:10px;color:#9ca3af;font-size:12px;font-weight:600;margin:10px 0;}
.divider::before,.divider::after{content:'';flex:1;border-top:1px solid #e2e8f0;}

/* ── TERMOS TABLE ── */
.termos-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
#termos-body tr td{padding:7px 10px;font-size:12px;border-bottom:1px solid #f1f5f9;white-space:nowrap;}
#termos-body tr:hover{background:#f8fafc;}
.badge-ok{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;background:#dcfce7;color:#15803d;}
.badge-na{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;background:#f1f5f9;color:#64748b;}
.link-pdf{color:#2563eb;font-weight:700;text-decoration:none;font-size:11px;}
.link-pdf:hover{text-decoration:underline;}

/* ── ITEM TABLE ── */
.table-wrap{overflow:auto;max-height:52vh;border-radius:10px;border:1px solid #e2e8f0;}
table#tbl-itens{border-collapse:collapse;width:100%;min-width:2600px;font-size:12px;}
#tbl-itens thead th{position:sticky;top:0;background:#3b82f6;color:#fff;padding:8px 10px;white-space:nowrap;z-index:2;font-weight:700;border:1px solid rgba(255,255,255,.2);}
#tbl-itens tbody td{border:1px solid #ddd;padding:6px 10px;white-space:nowrap;cursor:pointer;}
#tbl-itens tbody tr:hover{background:#eff6ff;}
#tbl-itens tbody tr.selecionada td{background:#bfdbfe !important;}
#tbl-itens tbody tr.alterada{background:#fef3c7 !important;}
#tbl-itens tbody tr.linha-inativa td{background:#fecaca !important;}
#tbl-itens tbody tr.linha-movimentada td{background:#fef9c3 !important;}
#tbl-itens tbody tr.selecionada.linha-inativa td,
#tbl-itens tbody tr.selecionada.linha-movimentada td{background:#bfdbfe !important;}
#tbl-itens td[contenteditable=true]{outline:none;cursor:text;}
#tbl-itens td[contenteditable=true]:focus{background:#eff6ff !important;outline:1px solid #93c5fd;outline-offset:-1px;}
#tbl-itens td select{width:100%;border:none;background:transparent;font-size:12px;outline:none;cursor:pointer;min-width:160px;}
.badge-mov{display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;background:#fef3c7;color:#92400e;margin-left:4px;}
.empty-state{text-align:center;padding:48px;color:#94a3b8;font-size:13px;}
/* Gerar Termo desabilitado enquanto localizado não preenchido */
#btnTermo:disabled{background:#9ca3af;cursor:not-allowed;opacity:.7;}
/* ocultar colunas de localização */
#tbl-itens.ocultar-loc .col-loc{display:none;}

/* ── TOTAIS ── */
.totais-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:center;padding:10px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;font-weight:700;color:#1e3a8a;border-radius:0 0 10px 10px;}
.totais-bar span{color:#16a34a;}

/* ── MODALS ── */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;display:flex;align-items:center;justify-content:center;}
.modal{background:#fff;border-radius:14px;padding:28px 32px;max-width:520px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.35);}
.modal h3{font-size:15px;font-weight:800;color:#1e3a8a;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid #e2e8f0;}
.modal .field-group{margin-bottom:12px;}
.modal-actions{display:flex;gap:8px;margin-top:18px;justify-content:flex-end;}
.modal input[readonly]{background:#f8fafc;color:#6b7280;}

@media(max-width:768px){.main{padding:0;}}
</style>
</head>
<body>

<!-- MAIN -->
<div class="main">

    <div class="page-header">
        <h1>Rotina de Inspeção Patrimonial</h1>
    </div>

    <!-- FILTROS -->
    <div class="card">
        <div class="card-title">Filtros de Localização</div>
        <div class="filtros-grid">
            <div class="field-group">
                <label>Unidade</label>
                <select id="selUnidade"><option value="">Selecione...</option></select>
            </div>
            <div class="field-group">
                <label>Setor</label>
                <select id="selSetor" disabled><option value="">Selecione...</option></select>
            </div>
            <div class="field-group">
                <label>Pavimento</label>
                <select id="selPavimento" disabled><option value="">Selecione...</option></select>
            </div>
            <div class="field-group">
                <label>Área</label>
                <select id="selArea" disabled><option value="">Selecione...</option></select>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
            <button class="btn btn-primary" id="btnBuscar">Pesquisar</button>
            <button class="btn btn-success" id="btnSalvar">Salvar alterações</button>
            <button class="btn btn-amber" id="btnTermo" disabled title="Preencha a coluna LOCALIZADO de todos os itens antes de gerar o termo">Gerar Termo</button>
            <a href="inicial.php" class="btn btn-primary">Voltar</a>
        </div>

        <div class="divider">ou busque por Tag / Nº Série</div>
        <div class="tag-row">
            <div class="field-group">
                <label>Tag Patrimônio / Nº Série</label>
                <input type="text" id="inpTagSerie" placeholder="Digite a tag ou número de série...">
            </div>
            <button class="btn btn-primary" id="btnBuscarTag">Pesquisar</button>
        </div>
    </div>

    <!-- TERMOS ASSINADOS -->
    <div class="card" id="card-termos" style="display:none">
        <div class="termos-header">
            <div class="card-title" style="margin-bottom:0">Termos de Responsabilidade — Registros</div>
            <button class="btn btn-slate" onclick="abrirModalUpload()">+ Anexar Termo Assinado</button>
        </div>
        <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
                <tr style="background:#f8fafc">
                    <th style="padding:7px 10px;text-align:left;font-size:10px;color:#6b7280;font-weight:700;border-bottom:1px solid #e2e8f0">DATA</th>
                    <th style="padding:7px 10px;text-align:left;font-size:10px;color:#6b7280;font-weight:700;border-bottom:1px solid #e2e8f0">UNIDADE</th>
                    <th style="padding:7px 10px;text-align:left;font-size:10px;color:#6b7280;font-weight:700;border-bottom:1px solid #e2e8f0">SETOR</th>
                    <th style="padding:7px 10px;text-align:left;font-size:10px;color:#6b7280;font-weight:700;border-bottom:1px solid #e2e8f0">ÁREA</th>
                    <th style="padding:7px 10px;text-align:left;font-size:10px;color:#6b7280;font-weight:700;border-bottom:1px solid #e2e8f0">COORDENADOR</th>
                    <th style="padding:7px 10px;text-align:left;font-size:10px;color:#6b7280;font-weight:700;border-bottom:1px solid #e2e8f0">GERADO POR</th>
                    <th style="padding:7px 10px;text-align:left;font-size:10px;color:#6b7280;font-weight:700;border-bottom:1px solid #e2e8f0">ARQUIVO</th>
                </tr>
            </thead>
            <tbody id="termos-body">
                <tr><td colspan="7" style="padding:16px;text-align:center;color:#94a3b8;font-size:12px">Nenhum termo registrado para este local.</td></tr>
            </tbody>
        </table>
        </div>
    </div>

    <!-- TABELA DE ITENS -->
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px">
            <div class="card-title" style="margin-bottom:0">Itens no Local</div>
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#475569;cursor:pointer;user-select:none">
                    <input type="checkbox" id="chkOcultarLoc" style="width:15px;height:15px;cursor:pointer">
                    Ocultar colunas de localização
                </label>
                <span id="badge-total" style="font-size:12px;color:#64748b"></span>
            </div>
        </div>
        <div class="table-wrap">
            <table id="tbl-itens">
                <thead>
                    <tr>
                        <th>#</th>
                        <th class="col-loc">UNIDADE ORIGEM</th>
                        <th class="col-loc">SETOR ORIGEM</th>
                        <th class="col-loc">PAVIMENTO ORIGEM</th>
                        <th class="col-loc">ÁREA DE ORIGEM</th>
                        <th class="col-loc">UNIDADE DESTINO</th>
                        <th class="col-loc">SETOR DESTINO</th>
                        <th class="col-loc">ÁREA DESTINO</th>
                        <th>DESCRIÇÃO</th>
                        <th>MARCA</th>
                        <th>MODELO</th>
                        <th>Nº SÉRIE</th>
                        <th>TAG PATRIMÔNIO</th>
                        <th>TAG NOVA COMPRA</th>
                        <th>LOCALIZADO</th>
                        <th>ESTADO</th>
                        <th>OBSERVAÇÃO</th>
                        <th>NÃO CONFORMIDADE</th>
                        <th>STATUS</th>
                        <th>O.S. / MANUTENÇÃO</th>
                    </tr>
                </thead>
                <tbody id="tbody">
                    <tr><td colspan="20" class="empty-state">Utilize os filtros acima para carregar os itens do local.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="totais-bar" id="totais-bar" style="display:none">
            Total: <span id="tot-qtd">0</span> itens &nbsp;|&nbsp;
            Localizados: <span id="tot-loc">0</span> &nbsp;|&nbsp;
            Não localizados: <span id="tot-nloc">0</span> &nbsp;|&nbsp;
            Com não conformidade: <span id="tot-nc" style="color:#dc2626">0</span>
        </div>
    </div>

</div><!-- /main -->

<!-- MODAL: Gerar Termo -->
<div class="modal-bg" id="modal-termo" style="display:none">
    <div class="modal">
        <h3>Gerar Termo de Responsabilidade</h3>
        <div class="field-group">
            <label>Unidade</label>
            <input type="text" id="trm-unidade" readonly>
        </div>
        <div class="field-group">
            <label>Setor</label>
            <input type="text" id="trm-setor" readonly>
        </div>
        <div class="field-group">
            <label>Área</label>
            <input type="text" id="trm-area" readonly>
        </div>
        <div class="field-group">
            <label>Responsável do Setor</label>
            <input type="text" id="trm-coordenador" placeholder="Nome do responsável do setor (quem assina)">
        </div>
        <div class="field-group">
            <label>Assistente de Patrimônio</label>
            <input type="text" id="trm-assistente" value="<?= htmlspecialchars($nome_usuario) ?>" readonly>
        </div>
        <div class="field-group">
            <label>Data da Conferência</label>
            <input type="date" id="trm-data" value="<?= date('Y-m-d') ?>">
        </div>
        <p id="trm-qtd" style="font-size:12px;color:#64748b;margin-top:8px"></p>
        <div class="modal-actions">
            <button class="btn btn-slate" onclick="fecharModal('modal-termo')">Cancelar</button>
            <button class="btn btn-amber" onclick="gerarTermo()">Gerar e Imprimir</button>
        </div>
    </div>
</div>

<!-- MODAL: Anexar Termo Assinado -->
<div class="modal-bg" id="modal-upload" style="display:none">
    <form id="form-upload" enctype="multipart/form-data">
    <div class="modal">
        <h3>Anexar Termo Assinado</h3>
        <div class="field-group">
            <label>Unidade</label>
            <input type="text" name="unidade" id="upl-unidade" readonly>
        </div>
        <div class="field-group">
            <label>Setor</label>
            <input type="text" name="setor" id="upl-setor" readonly>
        </div>
        <div class="field-group">
            <label>Área</label>
            <input type="text" name="area" id="upl-area" readonly>
        </div>
        <div class="field-group">
            <label>Data do Termo</label>
            <input type="date" name="data_geracao" id="upl-data" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="field-group">
            <label>Coordenador / Responsável</label>
            <input type="text" name="coordenador" id="upl-coordenador" placeholder="Nome do responsável">
        </div>
        <div class="field-group">
            <label>Usuário</label>
            <input type="text" name="usuario" value="<?= htmlspecialchars($usuario_logado) ?>" readonly>
        </div>
        <div class="field-group">
            <label>Arquivo PDF (termo assinado)</label>
            <input type="file" name="arquivo" accept=".pdf,application/pdf" style="padding:6px;border:1px solid #e2e8f0;border-radius:8px;width:100%">
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-slate" onclick="fecharModal('modal-upload')">Cancelar</button>
            <button type="button" class="btn btn-slate" onclick="limparUpload()">Limpar</button>
            <button type="button" class="btn btn-success" onclick="salvarUpload()">Salvar</button>
        </div>
    </div>
    </form>
</div>

<script>
/* ─────────────────────────────────────
   ESTADO GLOBAL
───────────────────────────────────── */
let dadosCarregados   = [];    // array de objetos retornados por rotina_buscar.php
let linhasAlteradas   = new Set();
let linhaSelecionada  = null;
let filtroAtual       = { unidade:'', setor:'', pavimento:'', area:'' };

const OPTS_LOCALIZADO   = ['','SIM','NÃO','PARCIAL'];
const OPTS_ESTADO       = ['','BOM','REGULAR','RUIM','INATIVO'];
const OPTS_NCONFIRM     = ['','SIM','NÃO'];
const OPTS_STATUS2      = ['','EM USO NORMAL','FORA DE USO','AVARIADO','AGUARDANDO MANUTENÇÃO'];

/* ─────────────────────────────────────
   FILTROS CASCATA
───────────────────────────────────── */
function popularSel(sel, dados, campo) {
    const v = sel.value;
    sel.innerHTML = '<option value="">Selecione...</option>';
    dados.forEach(d => {
        const opt = document.createElement('option');
        opt.value = opt.textContent = d[campo];
        sel.appendChild(opt);
    });
    sel.disabled = false;
    if (dados.find(d => d[campo] === v)) sel.value = v;
}

function carregar(nivel, params = {}) {
    const qs = new URLSearchParams({ nivel, ...params }).toString();
    return fetch('rotina_filtros.php?' + qs).then(r => r.json());
}

const selU = document.getElementById('selUnidade');
const selS = document.getElementById('selSetor');
const selP = document.getElementById('selPavimento');
const selA = document.getElementById('selArea');

carregar('unidade').then(d => popularSel(selU, d, 'unidade')).catch(()=>{});

selU.onchange = () => {
    selS.disabled = selP.disabled = selA.disabled = true;
    selS.innerHTML = selP.innerHTML = selA.innerHTML = '<option value="">Selecione...</option>';
    if (!selU.value) return;
    carregar('setor', { unidade: selU.value }).then(d => popularSel(selS, d, 'setor'));
};
selS.onchange = () => {
    selP.disabled = selA.disabled = true;
    selP.innerHTML = selA.innerHTML = '<option value="">Selecione...</option>';
    if (!selS.value) return;
    // carrega pavimento E já libera área (sem depender de pavimento)
    carregar('pavimento', { unidade: selU.value, setor: selS.value }).then(d => {
        popularSel(selP, d, 'pavimento');
        // se não houver pavimentos, mantém o select visível mas vazio (sem bloquear área)
    });
    carregar('area', { unidade: selU.value, setor: selS.value }).then(d => popularSel(selA, d, 'area'));
};
selP.onchange = () => {
    // recarrega área filtrando pelo pavimento selecionado (ou sem filtro se vazio)
    carregar('area', { unidade: selU.value, setor: selS.value, pavimento: selP.value })
        .then(d => popularSel(selA, d, 'area'));
};

/* ─────────────────────────────────────
   BUSCA
───────────────────────────────────── */
document.getElementById('btnBuscar').onclick = () => {
    const u = selU.value.trim();
    const s = selS.value.trim();
    if (!u) { alert('Selecione ao menos a unidade.'); return; }
    filtroAtual = { unidade: u, setor: s, pavimento: selP.value.trim(), area: selA.value.trim() };
    buscar({ unidade: u, setor: s, pavimento: selP.value.trim(), area: selA.value.trim() });
};

document.getElementById('btnBuscarTag').onclick = () => {
    const val = document.getElementById('inpTagSerie').value.trim();
    if (!val) { alert('Digite uma tag ou número de série.'); return; }
    filtroAtual = { unidade: selU.value.trim(), setor: selS.value.trim(), pavimento: selP.value.trim(), area: selA.value.trim() };
    buscar({ tag_serie: val, unidade: filtroAtual.unidade, setor: filtroAtual.setor });
};

document.getElementById('inpTagSerie').addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('btnBuscarTag').click();
});

function buscar(params) {
    const tbody = document.getElementById('tbody');
    tbody.innerHTML = '<tr><td colspan="17" class="empty-state">Carregando...</td></tr>';
    document.getElementById('totais-bar').style.display = 'none';

    const fd = new FormData();
    Object.entries(params).forEach(([k, v]) => { if (v) fd.append(k, v); });

    fetch('rotina_buscar.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.erro) { tbody.innerHTML = `<tr><td colspan="17" class="empty-state">Erro: ${esc(data.erro)}</td></tr>`; return; }
            dadosCarregados = data;
            linhasAlteradas.clear();
            renderTabela(data);
            carregarTermos();
        })
        .catch(e => { tbody.innerHTML = `<tr><td colspan="17" class="empty-state">Erro de comunicação: ${esc(e.message)}</td></tr>`; });
}

/* ─────────────────────────────────────
   RENDERIZAR TABELA
───────────────────────────────────── */
function renderTabela(rows) {
    const tbody = document.getElementById('tbody');
    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="17" class="empty-state">Nenhum item encontrado para os filtros selecionados.</td></tr>';
        document.getElementById('badge-total').textContent = '';
        document.getElementById('totais-bar').style.display = 'none';
        return;
    }

    document.getElementById('badge-total').textContent = rows.length + ' itens encontrados';
    tbody.innerHTML = '';

    let totLoc = 0, totNLoc = 0, totNC = 0;

    rows.forEach((r, i) => {
        const tr = document.createElement('tr');
        tr.dataset.id  = r.id;
        tr.dataset.idx = i;

        const mov = (r.movimentado ?? '').toUpperCase() === 'SIM';
        const retornou = mov &&
            (r.unidade ?? '') === (r.unidade_destino ?? '') &&
            (r.setor   ?? '') === (r.setor_destino   ?? '') &&
            (r.area    ?? '') === (r.area_destino    ?? '');
        const inativo  = (r.status2 ?? '').toUpperCase().includes('INATIVO') || (r.encontrado ?? '').toUpperCase() === 'BAIXA';

        if (inativo)          tr.classList.add('linha-inativa');
        else if (mov && !retornou) tr.classList.add('linha-movimentada');

        const movBadge = (mov && !retornou)
            ? `<span class="badge-mov" title="Movimentado de ${esc(r.unidade)} / ${esc(r.setor)}">MOV</span>`
            : '';

        // localizado stats
        const loc = (r.encontrado ?? '').toUpperCase();
        if (loc === 'SIM') totLoc++;
        else if (loc === 'NÃO') totNLoc++;
        const nc = (r.n_conformidade ?? '').toUpperCase() === 'SIM';
        if (nc) totNC++;

        tr.innerHTML = `
            <td style="color:#94a3b8;font-size:11px">${i+1}</td>
            <td class="col-loc" contenteditable="true" data-campo="unidade" data-val="${esc(r.unidade)}"><span class="cel-val">${esc(r.unidade)}</span>${movBadge}</td>
            <td class="col-loc" contenteditable="true" data-campo="setor">${esc(r.setor)}</td>
            <td class="col-loc" contenteditable="true" data-campo="pavimento">${esc(r.pavimento)}</td>
            <td class="col-loc" contenteditable="true" data-campo="area">${esc(r.area)}</td>
            <td class="col-loc" contenteditable="true" data-campo="unidade_destino">${esc(r.unidade_destino)}</td>
            <td class="col-loc" contenteditable="true" data-campo="setor_destino">${esc(r.setor_destino)}</td>
            <td class="col-loc" contenteditable="true" data-campo="area_destino">${esc(r.area_destino)}</td>
            <td contenteditable="true" data-campo="descricao">${esc(r.descricao)}</td>
            <td contenteditable="true" data-campo="marca">${esc(r.marca)}</td>
            <td contenteditable="true" data-campo="modelo">${esc(r.modelo)}</td>
            <td contenteditable="true" data-campo="serie">${esc(r.serie)}</td>
            <td contenteditable="true" data-campo="tag_antiga">${esc(r.tag_antiga)}</td>
            <td contenteditable="true" data-campo="tag_trocada">${esc(r.tag_trocada)}</td>
            <td>${makeSelect(r.encontrado, OPTS_LOCALIZADO, tr, 'encontrado')}</td>
            <td>${makeSelect(r.estado,     OPTS_ESTADO,     tr, 'estado')}</td>
            <td contenteditable="true" data-campo="obs3">${esc(r.obs3)}</td>
            <td>${makeSelect(r.n_conformidade, OPTS_NCONFIRM, tr, 'n_conformidade')}</td>
            <td>${makeSelect(r.status2, OPTS_STATUS2, tr, 'status2')}</td>
            <td contenteditable="true" data-campo="o_servico">${esc(r.o_servico)}</td>
        `;

        // edição via contenteditable
        tr.querySelectorAll('td[contenteditable]').forEach(td => {
            td.addEventListener('input', () => {
                // sincroniza data-val (células com badge usam data-val para evitar capturar o texto do badge)
                if (td.dataset.val !== undefined) {
                    td.dataset.val = td.querySelector('.cel-val')
                        ? td.querySelector('.cel-val').textContent.trim()
                        : td.textContent.trim();
                }
                marcarAlterada(tr);
            });
        });

        tr.addEventListener('click', e => {
            if (e.target.tagName === 'SELECT' || e.target.hasAttribute('contenteditable')) return;
            document.querySelectorAll('#tbl-itens tbody tr').forEach(r => r.classList.remove('selecionada'));
            tr.classList.add('selecionada');
            linhaSelecionada = tr;
        });

        tbody.appendChild(tr);
    });

    document.getElementById('tot-qtd').textContent  = rows.length;
    document.getElementById('tot-loc').textContent  = totLoc;
    document.getElementById('tot-nloc').textContent = totNLoc;
    document.getElementById('tot-nc').textContent   = totNC;
    document.getElementById('totais-bar').style.display = 'flex';
    verificarTermoBtn();
}

function makeSelect(valor, opcoes, tr, campo) {
    const opts = opcoes.map(o => `<option${o === (valor??'') ? ' selected':''}>${o}</option>`).join('');
    const id = `sel_${campo}_${tr?.dataset?.id ?? Math.random()}`;
    const onchange = campo === 'encontrado'
        ? `(function(s){s.closest('tr') && marcarAlterada(s.closest('tr')); verificarTermoBtn();})(this)`
        : `(function(s){s.closest('tr') && marcarAlterada(s.closest('tr'))})(this)`;
    return `<select id="${id}" onchange="${onchange}">${opts}</select>`;
}

/* Habilita/desabilita botão Gerar Termo conforme preenchimento da coluna LOCALIZADO */
function verificarTermoBtn() {
    const sels = document.querySelectorAll('#tbody select[id*="encontrado_"]');
    if (sels.length === 0) { document.getElementById('btnTermo').disabled = true; return; }
    const todosFilled = Array.from(sels).every(s => s.value && s.value !== '');
    document.getElementById('btnTermo').disabled = !todosFilled;
}

function marcarAlterada(tr) {
    linhasAlteradas.add(tr);
    tr.classList.add('alterada');
}

function esc(v) { return (v ?? '').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* ─────────────────────────────────────
   SALVAR
───────────────────────────────────── */
document.getElementById('btnSalvar').onclick = () => salvar().then(ok => { if (ok) alert('Alterações salvas com sucesso!'); });

function salvar() {
    if (linhasAlteradas.size === 0) return Promise.resolve(true);
    const payload = [];
    linhasAlteradas.forEach(tr => {
        const id = parseInt(tr.dataset.id);
        const idx = parseInt(tr.dataset.idx);
        const orig = dadosCarregados[idx] ?? {};
        const tds = tr.querySelectorAll('td');

        const getSelVal = campo => {
            const sel = tr.querySelector(`select[id*="${campo}_"]`);
            return sel ? sel.value : (orig[campo] ?? '');
        };
        const getCEVal = campo => {
            const td = tr.querySelector(`td[data-campo="${campo}"]`);
            if (!td) return orig[campo] ?? '';
            // se tiver data-val, usa ele (evita pegar texto de badges dentro da célula)
            if (td.dataset.val !== undefined) return td.dataset.val.trim();
            return td.textContent.trim();
        };

        const encAntes = orig.encontrado ?? '';
        const encAgora = getSelVal('encontrado');
        payload.push({
            id,
            unidade:         getCEVal('unidade'),
            setor:           getCEVal('setor'),
            pavimento:       getCEVal('pavimento'),
            area:            getCEVal('area'),
            unidade_destino: getCEVal('unidade_destino'),
            setor_destino:   getCEVal('setor_destino'),
            area_destino:    getCEVal('area_destino'),
            descricao:       getCEVal('descricao'),
            marca:           getCEVal('marca'),
            modelo:          getCEVal('modelo'),
            serie:           getCEVal('serie'),
            tag_antiga:      getCEVal('tag_antiga'),
            tag_trocada:     getCEVal('tag_trocada'),
            encontrado:      encAgora,
            estado:          getSelVal('estado'),
            obs3:            getCEVal('obs3'),
            n_conformidade:  getSelVal('n_conformidade'),
            status2:         getSelVal('status2'),
            o_servico:       getCEVal('o_servico'),
            _registrar_inspecao: encAntes !== encAgora,
        });
    });

    return fetch('rotina_salvar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(d => {
        if (d.sucesso) {
            linhasAlteradas.forEach(tr => tr.classList.remove('alterada'));
            linhasAlteradas.clear();
            return true;
        } else {
            alert('Erro ao salvar: ' + (d.erro ?? 'desconhecido'));
            return false;
        }
    })
    .catch(e => { alert('Erro de comunicação: ' + e.message); return false; });
}

/* ─────────────────────────────────────
   MODAL TERMO
───────────────────────────────────── */
document.getElementById('btnTermo').onclick = () => {
    if (dadosCarregados.length === 0) { alert('Carregue os itens de um local antes de gerar o termo.'); return; }
    document.getElementById('trm-unidade').value    = filtroAtual.unidade;
    document.getElementById('trm-setor').value      = filtroAtual.setor;
    document.getElementById('trm-area').value       = filtroAtual.area;
    document.getElementById('trm-coordenador').value = '';
    document.getElementById('trm-qtd').textContent  = dadosCarregados.length + ' item(ns) incluídos no termo.';
    document.getElementById('modal-termo').style.display = 'flex';
};

async function gerarTermo() {
    const coordenador = document.getElementById('trm-coordenador').value.trim();
    const assistente  = document.getElementById('trm-assistente').value.trim();
    if (!coordenador) { alert('Informe o nome do Responsável do Setor.'); return; }

    // Salva alterações pendentes antes de gerar o termo
    if (linhasAlteradas.size > 0) {
        const ok = await salvar();
        if (!ok) return; // aborta se save falhou
    }

    // Lê dados atuais do DOM (após edições) em vez de dadosCarregados (dados originais)
    const itens = Array.from(document.querySelectorAll('#tbody tr[data-id]')).map(tr => {
        const getCE = campo => {
            const td = tr.querySelector(`td[data-campo="${campo}"]`);
            return td ? td.textContent.trim() : '';
        };
        const getSel = campo => {
            const sel = tr.querySelector(`select[id*="${campo}_"]`);
            return sel ? sel.value : '';
        };
        return {
            descricao:  getCE('descricao'),
            marca:      getCE('marca'),
            modelo:     getCE('modelo'),
            serie:      getCE('serie'),
            tag_antiga: getCE('tag_antiga'),
            encontrado: getSel('encontrado'),
        };
    });

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'rotina_termo.php';
    form.target = '_blank';
    const add = (n, v) => { const i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); };
    add('unidade',    filtroAtual.unidade);
    add('setor',      filtroAtual.setor);
    add('pavimento',  filtroAtual.pavimento);
    add('area',       filtroAtual.area);
    add('coordenador',coordenador);
    add('assistente', assistente);
    add('data',       document.getElementById('trm-data').value);
    add('itens',      JSON.stringify(itens));
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
    fecharModal('modal-termo');
}

/* ─────────────────────────────────────
   MODAL UPLOAD
───────────────────────────────────── */
function abrirModalUpload() {
    document.getElementById('upl-unidade').value    = filtroAtual.unidade;
    document.getElementById('upl-setor').value      = filtroAtual.setor;
    document.getElementById('upl-area').value       = filtroAtual.area;
    document.getElementById('upl-coordenador').value = '';
    document.getElementById('modal-upload').style.display = 'flex';
}

function limparUpload() {
    document.getElementById('upl-coordenador').value = '';
    document.getElementById('upl-data').value = new Date().toISOString().split('T')[0];
    const fileInput = document.querySelector('#form-upload input[type=file]');
    if (fileInput) fileInput.value = '';
}

function salvarUpload() {
    const form = document.getElementById('form-upload');
    const fd = new FormData(form);
    if (!fd.get('arquivo') || fd.get('arquivo').size === 0) { alert('Selecione o arquivo PDF.'); return; }
    if (!fd.get('unidade')) { alert('Unidade não identificada. Realize uma pesquisa primeiro.'); return; }
    if (!fd.get('coordenador').trim()) { alert('Informe o nome do coordenador.'); return; }

    fetch('rotina_upload_termo.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => {
            if (d.sucesso) {
                fecharModal('modal-upload');
                carregarTermos();
                alert('Termo anexado com sucesso!');
            } else {
                alert('Erro: ' + (d.erro ?? 'desconhecido'));
            }
        })
        .catch(e => alert('Erro de comunicação: ' + e.message));
}

/* ─────────────────────────────────────
   TERMOS LISTA
───────────────────────────────────── */
function carregarTermos() {
    if (!filtroAtual.unidade) return;
    document.getElementById('card-termos').style.display = 'block';

    const fd = new FormData();
    fd.append('unidade', filtroAtual.unidade);
    if (filtroAtual.setor) fd.append('setor', filtroAtual.setor);
    if (filtroAtual.area)  fd.append('area',  filtroAtual.area);

    fetch('rotina_termos_lista.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(termos => {
            const tbody = document.getElementById('termos-body');
            if (!termos || termos.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="padding:16px;text-align:center;color:#94a3b8;font-size:12px">Nenhum termo registrado para este local.</td></tr>';
                return;
            }
            tbody.innerHTML = termos.map(t => {
                const dataFmt = t.data_geracao ? t.data_geracao.split('-').reverse().join('/') : '—';
                // Sai por rotina_termo_arquivo.php, que confere a sessão antes
                // de entregar. O link direto para uploads/ deixou de existir:
                // qualquer pessoa com o endereço baixava o termo sem login.
                const arquivo = t.arquivo
                    ? `<a class="link-pdf" href="rotina_termo_arquivo.php?id=${encodeURIComponent(t.id)}" target="_blank">Abrir PDF</a>`
                    : `<span class="badge-na">Sem arquivo</span>`;
                return `<tr>
                    <td>${dataFmt}</td>
                    <td>${esc(t.unidade)}</td>
                    <td>${esc(t.setor)}</td>
                    <td>${esc(t.area)}</td>
                    <td>${esc(t.coordenador)}</td>
                    <td>${esc(t.usuario)}</td>
                    <td>${arquivo}</td>
                </tr>`;
            }).join('');
        })
        .catch(() => {});
}

function esc(v) { return (v ?? '').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* ─────────────────────────────────────
   HELPERS
───────────────────────────────────── */
function fecharModal(id) { document.getElementById(id).style.display = 'none'; }
document.querySelectorAll('.modal-bg').forEach(m => m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; }));

/* ── CHECKBOX ocultar colunas de localização ── */
document.getElementById('chkOcultarLoc').addEventListener('change', function() {
    document.getElementById('tbl-itens').classList.toggle('ocultar-loc', this.checked);
});
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
