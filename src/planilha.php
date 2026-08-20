<?php
ob_start();
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header("Location: index.html");
    exit();
}

require_once "conexao.php";
require_once 'check_session.php';

$usuario = $_SESSION['usuario_logado'];
$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $usuario);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$permicao             = $row['permicao']       ?? '';
$classe_usuario       = $row['classe_usuario'] ?? '';
$status               = $row['status']         ?? 'ATIVO';
$stmt->close();
$conn->close();

if ($status !== 'ATIVO') {
    session_destroy();
    header("Location: index.html?erro=bloqueado");
    exit();
}

if (!in_array($classe_usuario, ['DEV', 'PATRIMONIO'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}

$podeEditar                = in_array($permicao, ['A', 'B']);
$podeExcluir               = in_array($permicao, ['A', 'B']);
$podeMovimentar            = ($permicao !== 'C');
$podeEditarCamposRestritos = ($classe_usuario === 'DEV');


$colunas = [
    'ID'                                          => 'id',
    'DESCRIÇÃO'                                   => 'descricao',
    'DESCRIÇÃO DETALHADA'                         => 'descricao_detalhada',
    'MARCA'                                       => 'marca',
    'MODELO'                                      => 'modelo',
    'SÉRIE'                                       => 'serie',
    'PROPRIEDADE'                                 => 'propriedade',
    'TAG PATRIMONIO'                              => 'tag_antiga',
    'TAG NOVA COMPRA'                             => 'tag_trocada',
    'EMPRESA'                                     => 'empresa',
    'TAG ALUGADO'                                 => 'tag_alugado',
    'OBSERVAÇÃO'                                  => 'observacao',
    'UNIDADE'                                     => 'unidade',
    'SETOR'                                       => 'setor',
    'PAVIMENTO'                                   => 'pavimento',
    'ÁREA'                                        => 'area',
    'USUÁRIO CADASTRO'                            => 'usuario_cadastro',
    'CLASSE'                                      => 'classe',
    'GRUPO'                                       => 'grupo',
    'SUBGRUPO'                                    => 'subgrupo',
    'RESPONSÁVEL'                                 => 'responsavel',
    'PERÍODO'                                     => 'periodo',
    'STATUS'                                      => 'status',
    'MOVIMENTADO DEFINITIVO'                      => 'movimentado_definitivo',
    'MOVIMENTADO'                                 => 'movimentado',
    'DATA DE MOVIMENTAÇÃO'                        => 'data_movimentacao',
    'FOLHA'                                       => 'folha',
    'UNIDADE DESTINO'                             => 'unidade_destino',
    'SETOR DESTINO'                               => 'setor_destino',
    'ÁREA DESTINO'                                => 'area_destino',
    'OBS MOVIMENTAÇÃO'                            => 'obs_movimentacao',
    'USUARIO QUE MOVIMENTOU'                      => 'usuario_movimentacao',
    'DATA INSPEÇÃO'                               => 'data_inspecao',
    'USUÁRIO INSPEÇÃO'                            => 'usuario_inspecao',
    'LOCALIZADO'                                  => 'encontrado',
    'ESTADO'                                      => 'estado',
    'OBSERVAÇÃO ROTINA'                           => 'obs3',
    'NÃO CONFORMIDADE'                            => 'n_conformidade',
    'STATUS DE ROTINA'                            => 'status2',
    'EXISTE ORDEM DE SERVIÇO OU REGISTRO DE MANUTENÇÃO' => 'o_servico',
    'DATA BAIXA'                                  => 'data_baixa',
    'CC UNIDADE'                                  => 'centro_custo_unidade',
    'CC SETOR'                                    => 'centro_custo_setor',
    'UNIDADE ATRIBUÍDA'                           => 'unidade_atribuida',
    'SETOR ATRIBUÍDO'                             => 'setor_atribuido',
    'CONCILIADO'                                  => 'conciliado',
    'USUÁRIO CONCILIAÇÃO'                         => 'usuario_conciliacao',
    'NOTA FISCAL'                                 => 'nota_fiscal',
    'FORNECEDOR'                                  => 'fornecedor_nome',
    'CNPJ'                                        => 'fornecedor_cnpj',
    'DATA AQUISIÇÃO'                              => 'data_aquisicao',
    'VALOR NOTA'                                  => 'valor_nota',
    'VALOR ITEM'                                  => 'valor_item',
    'INÍCIO DEPRECIAÇÃO'                          => 'data_inicio_depreciacao',
    'DEP. ACUMULADA'                              => 'depreciacao_acumulada',
    'SALDO'                                       => 'saldo_remanecente',
    'ARRENDAMENTO'                                => 'contrato_arrendamento',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Planilha</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
body{margin:0;font-family:Arial,sans-serif;background:linear-gradient(135deg,#001435,#60a5fa);display:flex;justify-content:center;padding:30px;}
.login-card{background:#fff;padding:30px;border-radius:12px;width:95%;max-width:95%;}
.top-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px;}
.contador{font-size:14px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:6px 10px;color:#111827;}
.table-container{margin-top:10px;overflow:auto;max-height:500px;}
table{border-collapse:collapse;width:100%;min-width:1400px;}
th,td{border:1px solid #ddd;padding:8px;white-space:nowrap;}
th{background:#3b82f6;color:#fff;position:sticky;top:0;z-index:2;}
/* colunas fixas: z-index maior para ficar sobre as outras ao rolar */
th.col-fixa{z-index:4 !important;background:#1d4ed8;}
td.col-fixa{z-index:3;background:#fff;}
tr.linha-inativa   td.col-fixa{background:#ff2626;}
tr.linha-movimentada td.col-fixa{background:#f7e13c;}
tr.linha-rotina    td.col-fixa{background:#f78317;}
tr.selecionada     td.col-fixa{background:#7eaff0 !important;}
.btn{padding:8px 14px;border:none;border-radius:6px;background:#3b82f6;color:white;cursor:pointer;}
.btn:hover{background:#2563eb;}
.btn:disabled{background:#9ca3af;cursor:not-allowed;}
tr.selecionada{background:#7eaff0 !important;}
tr.linha-inativa{background:#ff2626 !important;}
tr.linha-inativa.selecionada{background:#7eaff0 !important;}
tr.linha-movimentada{background:#f7e13c !important;}
tr.linha-movimentada.selecionada{background:#7eaff0 !important;}
tr.linha-rotina{background:#f78317 !important;}
tr.linha-rotina.selecionada{background:#7eaff0 !important;}
.pagination{margin-top:12px;display:flex;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap;}
.busca-row{display:flex;gap:8px;align-items:center;}
.busca-row input{padding:7px;border-radius:8px;border:1px solid #cbd5e1;font-size:14px;width:300px;box-sizing:border-box;}
.busca-row .btn-buscar{padding:8px 16px;border:none;border-radius:6px;background:#3b82f6;color:white;cursor:pointer;font-size:14px;}
.busca-row .btn-buscar:hover{background:#2563eb;}
#bannerFiltros{display:none;font-size:12px;color:#6b7280;padding:4px 10px;background:#f3f4f6;border-radius:6px;border:1px solid #e5e7eb;}
#loading{position:fixed;width:100%;height:100%;background:linear-gradient(135deg,#001435,#60a5fa);display:flex;flex-direction:column;justify-content:center;align-items:center;z-index:9999;color:white;font-family:Arial,sans-serif;}
.loader{border:6px solid #f3f3f3;border-top:6px solid #2563eb;border-radius:50%;width:60px;height:60px;animation:spin 1s linear infinite;margin-bottom:20px;}
@keyframes spin{0%{transform:rotate(0deg);}100%{transform:rotate(360deg);}}
.logo{width:320px;margin-bottom:10px;}
.logo img{width:100%;}
td .cell-text{display:inline;outline:none;}
td.fill-source{outline:2px solid #2563eb !important;outline-offset:-2px;background:#eff6ff !important;}
td.fill-source .cell-text{display:inline-block;min-width:4px;vertical-align:middle;}
td{position:relative;}

/* ── Lista livre (combobox que aceita digitação) ──────────────────────────
   Construída à mão porque o <datalist> nativo não abre sozinho: no Chrome as
   sugestões só aparecem depois de o usuário começar a digitar, o que faz o
   campo parecer uma caixa de texto comum. */
.ll-wrap{position:relative;width:100%;}
.ll-input{width:100%;font-size:13px;border:1px solid #6366f1;border-radius:4px;
          background:#eef2ff;padding:2px 22px 2px 4px;font-family:inherit;outline:none;}
.ll-seta{position:absolute;right:5px;top:50%;transform:translateY(-50%);
         font-size:9px;color:#6366f1;pointer-events:none;}
.ll-lista{position:absolute;left:0;top:100%;z-index:9999;min-width:100%;
          max-height:190px;overflow-y:auto;background:#fff;border:1px solid #6366f1;
          border-radius:0 0 6px 6px;box-shadow:0 6px 18px rgba(0,0,0,.18);
          white-space:nowrap;}
.ll-item{padding:6px 10px;font-size:13px;cursor:pointer;color:#111827;}
.ll-item:hover,.ll-item.ll-ativo{background:#dbeafe;}
.ll-vazio{padding:6px 10px;font-size:12px;color:#9ca3af;font-style:italic;}
td .fill-handle{display:none;position:absolute;right:0;bottom:0;width:8px;height:8px;background:#2563eb;cursor:crosshair;z-index:10;user-select:none;pointer-events:none;}
td[contenteditable="false"] .fill-handle,td.fill-source .fill-handle{pointer-events:auto;}
td[contenteditable="true"] .fill-handle,td.fill-source .fill-handle{display:block;}
td.fill-preview{outline:2px dashed #2563eb;background:#dbeafe !important;}
.btn-desfazer{background:#f59e0b;}.btn-desfazer:hover{background:#d97706;}.btn-desfazer:disabled{background:#9ca3af;}
.btn-limpar-filtros{background:#ef4444;}.btn-limpar-filtros:hover{background:#dc2626;}.btn-limpar-filtros:disabled{background:#9ca3af;cursor:not-allowed;}
.btn-duplicados{background:#3b82f6;}.btn-duplicados:hover{background:#2563eb;}.btn-duplicados.ativo{background:#f59e0b;}.btn-duplicados.ativo:hover{background:#d97706;}
.dup-badge-wrap{display:inline-flex;align-items:stretch;gap:0;}
.btn-duplicados{border-radius:6px 0 0 6px !important;}
.dup-badge{display:inline-flex;align-items:center;background:#1d4ed8;color:#fff;font-size:12px;font-weight:700;padding:0 10px;border-radius:0 6px 6px 0;white-space:nowrap;cursor:default;border-left:1px solid rgba(255,255,255,0.25);min-width:36px;justify-content:center;}
.dup-badge.tem-dup{background:#ef4444;}

/* ── CABEÇALHO ── */
.th-inner{display:flex;align-items:center;justify-content:space-between;gap:4px;min-width:80px;}
.th-label{flex:1;}
.th-actions{display:flex;gap:3px;align-items:center;flex-shrink:0;}

/* ── BOTÃO FILTRO ── */
.filtro-btn{background:rgba(255,255,255,0.25);border:1px solid rgba(255,255,255,0.5);border-radius:3px;color:#fff;cursor:pointer;font-size:10px;line-height:1;padding:2px 4px;transition:background .15s;user-select:none;}
.filtro-btn:hover{background:rgba(255,255,255,0.45);}
.filtro-btn.ativo{background:#f59e0b;border-color:#d97706;color:#fff;}

/* ── BOTÃO FIXAR ── */
.pin-btn{background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.4);border-radius:3px;color:#fff;cursor:pointer;font-size:10px;line-height:1;padding:2px 5px;transition:background .15s;user-select:none;opacity:.7;}
.pin-btn:hover{background:rgba(255,255,255,0.4);opacity:1;}
.pin-btn.fixada{background:#fbbf24;border-color:#f59e0b;color:#1e3a5f;opacity:1;font-weight:700;}

/* ── DROPDOWN FILTRO ── */
.filtro-dropdown{display:none;position:fixed;z-index:9000;background:#fff;border:1px solid #cbd5e1;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.18);min-width:220px;max-width:300px;font-size:13px;color:#111827;padding:8px 0 6px;box-sizing:border-box;}
.filtro-dropdown.aberto{display:block;}
.filtro-search{display:block;width:calc(100% - 16px);margin:0 8px 6px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;outline:none;box-sizing:border-box;}
.filtro-search:focus{border-color:#3b82f6;}
.filtro-actions{display:flex;gap:4px;padding:0 8px 6px;border-bottom:1px solid #e5e7eb;margin-bottom:4px;}
.filtro-actions button{flex:1;font-size:11px;padding:3px 0;border:1px solid #cbd5e1;border-radius:4px;background:#f9fafb;cursor:pointer;color:#374151;}
.filtro-actions button:hover{background:#e5e7eb;}
.filtro-lista{max-height:200px;overflow-y:auto;padding:0 4px;}
.filtro-item{display:flex;align-items:center;gap:6px;padding:3px 6px;border-radius:3px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.filtro-item:hover{background:#eff6ff;}
.filtro-item input[type="checkbox"]{cursor:pointer;margin:0;flex-shrink:0;accent-color:#3b82f6;}
.filtro-item label{cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;font-size:12px;}
.filtro-item.item-todos label{font-weight:600;}
.filtro-footer{display:flex;gap:6px;padding:6px 8px 0;border-top:1px solid #e5e7eb;margin-top:4px;}
.filtro-footer button{flex:1;padding:5px 0;border:none;border-radius:4px;font-size:12px;cursor:pointer;}
.btn-ok-filtro{background:#3b82f6;color:#fff;}.btn-ok-filtro:hover{background:#2563eb;}
.btn-cancel-filtro{background:#f3f4f6;color:#374151;border:1px solid #d1d5db !important;}.btn-cancel-filtro:hover{background:#e5e7eb;}
.filtro-loading{text-align:center;padding:10px;color:#6b7280;font-size:12px;}
/* ── ORDENAÇÃO NO DROPDOWN ── */
.sort-section{padding:4px 8px 6px;border-bottom:1px solid #e5e7eb;margin-bottom:4px;display:flex;gap:4px;}
.sort-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:5px;padding:5px 4px;border:1px solid #cbd5e1;border-radius:4px;background:#f9fafb;cursor:pointer;color:#374151;font-size:11px;font-weight:500;transition:background .15s,border-color .15s;}
.sort-btn:hover{background:#eff6ff;border-color:#93c5fd;}
.sort-btn.ativo{background:#3b82f6;border-color:#2563eb;color:#fff;}
.sort-btn .sort-icon{font-size:13px;line-height:1;}

/* ── FILTRO POR COR ── */
.filtros-cor{display:flex;gap:6px;align-items:center;}
.btn-cor{padding:7px 13px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;color:#fff;display:flex;align-items:center;gap:5px;transition:opacity .15s,box-shadow .15s;box-shadow:0 1px 3px rgba(0,0,0,.2);}
.btn-cor:hover{opacity:.85;}
.btn-cor.ativo{box-shadow:0 0 0 3px rgba(0,0,0,.35),0 1px 3px rgba(0,0,0,.2);filter:brightness(1.08);}
.btn-cor-vermelho{background:#ff2626;}
.btn-cor-amarelo{background:#f7e13c;color:#333;}
.btn-cor-laranja{background:#f78317;}
</style>
</head>
<body>

<div id="loading">
    <div class="logo"><img src="logo_2.png" alt="Logo"></div>
    <div class="loader"></div>
    <p>Carregando planilha...</p>
</div>

<div class="login-card">
<h2>Planilha de Dados</h2>

<div class="top-bar">
    <span id="contador" class="contador">Carregando...</span>
    <div class="busca-row">
        <input type="text" id="pesquisa" placeholder="Pesquisar...">
        <button class="btn-buscar" id="btnBuscar">Buscar</button>
    </div>
    <span id="bannerFiltros" style="display:none"></span>
    <button class="btn" onclick="voltar()">Voltar</button>
    <button class="btn" onclick="location.reload()">Atualizar</button>
    <button class="btn" onclick="salvar()" <?= !$podeEditar ? 'disabled' : '' ?>>Salvar</button>
    <button class="btn btn-desfazer" id="btnDesfazer" onclick="desfazer()" disabled title="Desfazer última alteração">↩ Desfazer</button>
    <button class="btn" onclick="exportarXLSX()">Exportar XLSX</button>
    <button class="btn" onclick="movimentar()" <?= !$podeMovimentar ? 'disabled' : '' ?>>Movimentar</button>
    <button class="btn" onclick="excluirLinha()" <?= !$podeExcluir ? 'disabled' : '' ?>>Excluir</button>
    <button class="btn btn-limpar-filtros" id="btnLimparFiltros" onclick="limparTodosFiltros()" disabled title="Limpar todos os filtros ativos">✕ Limpar Filtros</button>
    <span class="dup-badge-wrap">
        <button class="btn btn-duplicados" id="btnDuplicados" onclick="toggleFiltroDuplicados()" title="Exibir apenas registros duplicados">⊕ Filtro de Duplicados</button>
        <span class="dup-badge" id="dupBadge" title="Itens em excesso (duplicatas a resolver)">...</span>
    </span>
    <div class="filtros-cor">
        <button class="btn-cor btn-cor-vermelho" id="btnCorVermelho" onclick="toggleFiltroCor('vermelho')" title="Filtrar: Baixa / Inativo">● Baixa</button>
        <button class="btn-cor btn-cor-amarelo" id="btnCorAmarelo" onclick="toggleFiltroCor('amarelo')" title="Filtrar: Movimentado">● Movimentado</button>
        <button class="btn-cor btn-cor-laranja" id="btnCorLaranja" onclick="toggleFiltroCor('laranja')" title="Filtrar: Não Localizado">● Não Localizado</button>
    </div>
</div>


<div class="table-container" id="tableContainer">
<table id="planilha">
<thead>
<tr>
<?php foreach ($colunas as $titulo => $campo): ?>
<th data-col="<?= $campo ?>">
    <div class="th-inner">
        <span class="th-label"><?= $titulo ?></span>
        <div class="th-actions">
            <button class="pin-btn" data-col="<?= $campo ?>" title="Fixar coluna">📌</button>
            <button class="filtro-btn" data-col="<?= $campo ?>" title="Filtrar">▼</button>
        </div>
    </div>
</th>
<?php endforeach; ?>
</tr>
</thead>
<tbody></tbody>
</table>
</div>

<div class="filtro-dropdown" id="filtroDropdown">
    <div class="sort-section">
        <button class="sort-btn" id="sortAZ" title="Ordenar A → Z / 0 → 9">
            <span class="sort-icon"></span> A → Z
        </button>
        <button class="sort-btn" id="sortZA" title="Ordenar Z → A / 9 → 0">
            <span class="sort-icon"></span> Z → A
        </button>
    </div>
    <input type="text" class="filtro-search" id="filtroSearch" placeholder="Pesquisar...">
    <div class="filtro-actions">
        <button id="btnSelecionarTodos">Selecionar tudo</button>
        <button id="btnLimparFiltro">Limpar filtro</button>
    </div>
    <div class="filtro-lista" id="filtroLista">
        <div class="filtro-loading">Carregando...</div>
    </div>
    <div class="filtro-footer">
        <button class="btn-ok-filtro"     id="btnOkFiltro">OK</button>
        <button class="btn-cancel-filtro" id="btnCancelFiltro">Cancelar</button>
    </div>
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

/* ═══════════════════════════════
   CONFIG
═══════════════════════════════ */
const COLUNAS             = <?= json_encode(array_values($colunas), JSON_UNESCAPED_UNICODE) ?>;
const podeEditar          = <?= $podeEditar ? 'true' : 'false' ?>;
const podeEditarRestritos = <?= $podeEditarCamposRestritos ? 'true' : 'false' ?>;
const POR_PAGINA          = 100;

/* colunas de moeda — exibem R$ e máscara BR */
const COLUNAS_MOEDA = new Set(['valor_nota', 'valor_item']);
const COLUNAS_DATA  = new Set(['data_movimentacao','data_inspecao','data_baixa','data_aquisicao','data_inicio_depreciacao']);

/* colunas com combobox — duplo clique abre select em vez de edição livre */
/* Colunas de LISTA LIVRE: abrem as opções sugeridas, mas aceitam qualquer
   texto digitado. Usa <input list> + <datalist>, que dá as duas coisas de
   forma nativa — sem precisar montar um menu à mão nem tratar teclado.
   Diferente de COLUNAS_COMBOBOX, que é lista fechada. */
const COLUNAS_LISTA_LIVRE = {
    // Em caixa alta para acompanhar o padrão da base: os registros existentes
    // estão todos em maiúsculas, e valor em caixa mista destoaria na coluna.
    'grupo': [
        'EQUIPAMENTOS E MÁQUINAS DE APOIO',
        'EQUIPAMENTO MEDICO HOSPITALAR',
        'INFORMÁTICA E COMUNICAÇÃO',
        'MÓVEIS E UTENSÍLIOS',
    ],
};

const COLUNAS_COMBOBOX = {
    'conciliado': ['', 'SIM', 'NAO', 'PENDENTE', 'SEM NOTA FISCAL'],
    // Setor responsável pela manutenção do item. Lista fechada: é ela que
    // define o que cada sistema enxerga (o LifeTech filtra por
    // 'ENGENHARIA CLINICA'), então uma digitação divergente esconde o item.
    'responsavel': [
        '',
        'ENGENHARIA CLINICA',
        'HOTELARIA',
        'NUTRIÇÃO',
        'MANUTENÇÃO',
        'MANUTENÇÃO ELETRONICA',
        'MANUTENÇÃO REFRIGERAÇÃO',
        'INFORMÁTICA',
        'SEGURANÇA',
    ],
};

const colunasRestritas = [
    'usuario_movimentacao','usuario_cadastro',
    'usuario_inspecao','usuario_conciliacao'
];

/* ═══════════════════════════════
   ESTADO
═══════════════════════════════ */
let paginaAtual      = 1;
let termoAtivo       = '';
let filtrosAtivos    = {};
let filtrosLike      = {};
let colExcluirVazio  = new Set(); // colunas onde (Vazio) foi desmarcado
let colFiltrarVazio  = new Set(); // colunas onde APENAS (Vazio) foi selecionado
let sortCol          = null;   // coluna de ordenação ativa
let sortDir          = null;   // 'asc' | 'desc'
let filtroDuplicados = false;
let filtroCor        = '';   // 'vermelho' | 'amarelo' | 'laranja' | ''
let linhasAlteradas  = new Set();
let linhaSelecionada = null;
let carregando       = false;
let historicoAlteracoes = [];
let fillSource   = null;
let fillDragging = false;
let fillPreviews = [];

/* colunas fixas: Set de nomes de campo */
let colunasFix = new Set();

/* ═══════════════════════════════
   ELEMENTOS
═══════════════════════════════ */
const tbody       = document.querySelector('#planilha tbody');
const paginacao   = document.getElementById('paginacao');
const contador    = document.getElementById('contador');
const pesquisa    = document.getElementById('pesquisa');
const btnBuscar   = document.getElementById('btnBuscar');
const btnDesfazer = document.getElementById('btnDesfazer');

/* ═══════════════════════════════
   HELPERS CÉLULA
═══════════════════════════════ */
function getCellValue(td) {
    // Se a célula está com a lista aberta, o valor corrente está no editor,
    // não no texto — que só é atualizado ao fechar. Sem isto, arrastar com a
    // lista aberta copiaria o valor anterior.
    const editor = td.querySelector('select, input[list]');
    if (editor) return (editor.value || '').trim();

    const span = td.querySelector('.cell-text');
    return span ? span.textContent.trim() : '';
}
function setCellValue(td, valor) {
    const span = td.querySelector('.cell-text');
    if (span) span.textContent = valor;
}

/* ═══════════════════════════════
   MOEDA — máscara BR
   Banco armazena: 1000,00
   Exibição: R$ 1.000,00
═══════════════════════════════ */

/** Converte qualquer formato salvo no banco → float */
function moedaParaFloat(str) {
    if (!str || str.trim() === '') return 0;
    str = str.replace(/^R\$\s*/, '').trim();
    // formato BR: 1.000,00
    if (str.includes(',')) {
        return parseFloat(str.replace(/\./g, '').replace(',', '.')) || 0;
    }
    // formato EN fallback: 1000.00
    return parseFloat(str) || 0;
}

/** float → string armazenada no banco: 1000,00 (sem ponto de milhar) */
function floatParaBanco(num) {
    return num.toFixed(2).replace('.', ',');
}

/** string do banco → string exibida na célula: R$ 1.000,00 */
function exibirMoeda(str) {
    if (!str || str.trim() === '') return '';
    const num = moedaParaFloat(str);
    return 'R$ ' + num.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Aplica máscara BR em tempo real num input contenteditable (span).
 * Interpreta os dígitos como centavos: digitar "1" → 0,01; "100" → 1,00.
 */
function aplicarMascaraMoeda(span) {
    const raw    = span.textContent.replace(/\D/g, '');
    const num    = parseInt(raw || '0', 10);
    const reais  = Math.floor(num / 100);
    const cents  = num % 100;
    const inteiraFmt = reais.toLocaleString('pt-BR');
    span.textContent = 'R$ ' + inteiraFmt + ',' + String(cents).padStart(2, '0');
    // posiciona cursor no final
    const range = document.createRange();
    range.selectNodeContents(span);
    range.collapse(false);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
}

/** Extrai valor para salvar no banco a partir do span moeda */
function moedaSpanParaBanco(span) {
    const num = moedaParaFloat(span.textContent);
    return floatParaBanco(num);
}

/* ═══════════════════════════════
   DATA
═══════════════════════════════ */
function formatarData(data) {
    if (!data) return '';
    if (!/^\d{4}-\d{2}-\d{2}/.test(data)) return data;
    const [dt, hr] = data.split(' ');
    const [ano, mes, dia] = dt.split('-');
    return hr ? `${dia}-${mes}-${ano} ${hr}` : `${dia}-${mes}-${ano}`;
}
/* Converte dd-mm-aaaa para aaaa-mm-dd antes de ENVIAR AO SERVIDOR.
   Não passa por esc(): escapar HTML aqui gravaria "&amp;" no banco em vez de
   "&". O escape é para inserção no DOM; dado destinado ao banco vai cru, e a
   proteção contra injeção é o prepared statement do lado do servidor. */
function normalizarData(valor) {
    if (!valor) return '';
    const m = valor.match(/^(\d{2})-(\d{2})-(\d{4})(.*)$/);
    if (!m) return valor;
    return `${m[3]}-${m[2]}-${m[1]}${m[4] || ''}`;
}

/* ═══════════════════════════════
   COLUNAS FIXAS — recalcula sticky
═══════════════════════════════ */
function recalcularColunasFixas() {
    const ths = document.querySelectorAll('#planilha thead th');
    let acumulado = 0;

    ths.forEach((th, idx) => {
        const col = th.dataset.col;
        const fixa = colunasFix.has(col);

        /* th */
        if (fixa) {
            th.classList.add('col-fixa');
            th.style.position = 'sticky';
            th.style.left     = acumulado + 'px';
            th.style.zIndex   = '4';
        } else {
            th.classList.remove('col-fixa');
            th.style.position = '';
            th.style.left     = '';
            th.style.zIndex   = '';
        }

        /* todas as tds dessa coluna */
        document.querySelectorAll(`#planilha tbody td:nth-child(${idx + 1})`).forEach(td => {
            if (fixa) {
                td.classList.add('col-fixa');
                td.style.position = 'sticky';
                td.style.left     = acumulado + 'px';
                td.style.zIndex   = '3';
            } else {
                td.classList.remove('col-fixa');
                td.style.position = '';
                td.style.left     = '';
                td.style.zIndex   = '';
            }
        });

        /* atualiza botão pin */
        const pinBtn = th.querySelector('.pin-btn');
        if (pinBtn) pinBtn.classList.toggle('fixada', fixa);

        if (fixa) acumulado += th.offsetWidth;
    });
}

function togglePin(col) {
    if (colunasFix.has(col)) colunasFix.delete(col);
    else colunasFix.add(col);
    recalcularColunasFixas();
}

/* Liga eventos dos botões pin */
document.querySelectorAll('.pin-btn').forEach(btn => {
    btn.addEventListener('click', e => {
        e.stopPropagation();
        togglePin(btn.dataset.col);
    });
});

/* ═══════════════════════════════
   QUERYSTRING
═══════════════════════════════ */
const VAZIO_SENTINEL = '__vazio__';

function buildQS(extras = {}) {
    const p = new URLSearchParams();
    p.set('pagina',    paginaAtual);
    p.set('porPagina', POR_PAGINA);
    if (termoAtivo !== '') p.set('termo', termoAtivo);
    if (filtroDuplicados)  p.set('duplicados', '1');
    if (filtroCor !== '')  p.set('filtro_cor', filtroCor);
    Object.entries(filtrosAtivos).forEach(([col, set]) => {
        set.forEach(val => { if (val !== '') p.append(`filtros[${col}][]`, val); });
    });
    colExcluirVazio.forEach(col => p.set(`excluir_vazio[${col}]`, '1'));
    colFiltrarVazio.forEach(col => p.set(`filtrar_vazio[${col}]`, '1'));
    Object.entries(filtrosLike).forEach(([col, t]) => p.set(`like[${col}]`, t));
    if (sortCol) { p.set('sort_col', sortCol); p.set('sort_dir', sortDir); }
    Object.entries(extras).forEach(([k, v]) => p.set(k, v));
    return p.toString();
}

/* ── QS para opções do dropdown — inclui flag duplicados ── */
function buildQSparaOpcoes(col) {
    const p = new URLSearchParams();
    p.set('modo',      'opcoes');
    p.set('coluna',    col);
    p.set('pagina',    1);
    p.set('porPagina', POR_PAGINA);
    if (termoAtivo !== '') p.set('termo', termoAtivo);
    if (filtroDuplicados)  p.set('duplicados', '1');
    if (filtroCor !== '')  p.set('filtro_cor', filtroCor);   // ← respeita filtro de botão
    if (sortCol)           { p.set('sort_col', sortCol); p.set('sort_dir', sortDir); }
    Object.entries(filtrosAtivos).forEach(([c, set]) => {
        if (c === col) return;
        set.forEach(val => { if (val !== '') p.append(`filtros[${c}][]`, val); });
    });
    Object.entries(filtrosLike).forEach(([c, t]) => {
        if (c === col) return;
        p.set(`like[${c}]`, t);
    });
    colExcluirVazio.forEach(c => { if (c !== col) p.set(`excluir_vazio[${c}]`, '1'); });
    colFiltrarVazio.forEach(c => { if (c !== col) p.set(`filtrar_vazio[${c}]`, '1'); });
    return p.toString();
}

/* ═══════════════════════════════
   BUSCAR DADOS
═══════════════════════════════ */
function buscarDados() {
    if (carregando) return;
    carregando = true;
    fetch('planilha_dados.php?' + buildQS())
        .then(r => r.json())
        .then(d => {
            renderTabela(d.linhas);
            renderPaginacao(d.total);
            totalFiltradoAtual = d.total ?? 0;
            const inicio = (paginaAtual - 1) * POR_PAGINA + 1;
            const fim    = Math.min(inicio + d.linhas.length - 1, d.total);
            contador.textContent = d.total > 0
                ? `Exibindo ${inicio}–${fim} de ${esc(d.total)}`
                : 'Exibindo 0 de 0';
            carregando = false;
            document.getElementById('loading').style.display = 'none';
            recalcularColunasFixas();
        })
        .catch(() => { carregando = false; });
}

/* ═══════════════════════════════
   RENDER TABELA
═══════════════════════════════ */
function renderTabela(linhas) {
    tbody.innerHTML  = '';
    linhaSelecionada = null;

    linhas.forEach(linha => {
        const tr = document.createElement('tr');
        tr.dataset.id = linha.id;

        const status = (linha.status ?? '').toUpperCase().trim();
        const movDef = (linha.movimentado_definitivo ?? '').toUpperCase().trim();
        const mov    = (linha.movimentado ?? '').toUpperCase().trim();
        const movimentado = (movDef === 'SIM' || mov === 'SIM');
        const unidade     = (linha.unidade ?? '').trim().toUpperCase();
        const setor       = (linha.setor   ?? '').trim().toUpperCase();
        const unidadeDest = (linha.unidade_destino ?? '').trim().toUpperCase();
        const setorDest   = (linha.setor_destino   ?? '').trim().toUpperCase();
        const destinoIgual = unidadeDest !== '' && setorDest !== '' && unidade === unidadeDest && setor === setorDest;
        const naoloc = (linha.encontrado ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toUpperCase().trim();
        const naoLocalizado = (naoloc === 'NAO');

        if (status === 'BAIXADO')              tr.classList.add('linha-inativa');
        else if (movimentado && !destinoIgual) tr.classList.add('linha-movimentada');
        else if (naoLocalizado)                tr.classList.add('linha-rotina');

        COLUNAS.forEach((c, idx) => {
            const td = document.createElement('td');
            td.dataset.coluna  = c;
            td.contentEditable = false;

            const ehMoeda = COLUNAS_MOEDA.has(c);

            const span = document.createElement('span');
            span.className = 'cell-text';

            let valorRaw = linha[c] ?? '';
            let valorExib;

            if (ehMoeda) {
                // guarda valor original no formato do banco (sem R$)
                valorExib = exibirMoeda(valorRaw);
                td.dataset.original = valorRaw; // banco: "1000,00"
                td.dataset.ehMoeda  = '1';
            } else {
                valorRaw  = formatarData(valorRaw);
                valorExib = valorRaw ? valorRaw.toString().toUpperCase() : '';
                td.dataset.original = valorExib;
            }

            span.textContent = valorExib;
            td.appendChild(span);

            const handle = document.createElement('div');
            handle.className = 'fill-handle';
            td.appendChild(handle);

            td.addEventListener('click', () => selecionarLinha(tr));

            td.addEventListener('dblclick', () => {
                const restrita = colunasRestritas.includes(c);
                if (!podeEditar || (restrita && !podeEditarRestritos)) return;

                /* ── LISTA LIVRE: abre as opções e aceita texto qualquer ──
                   Lista construída à mão, e não com <datalist>: o nativo só
                   mostra as sugestões depois de o usuário começar a digitar,
                   então o campo parecia uma caixa de texto comum. Aqui a lista
                   abre junto com o campo. */
                if (COLUNAS_LISTA_LIVRE[c] !== undefined) {
                    if (td.querySelector('.ll-wrap')) return;   // evita abrir dois

                    const opcoes     = COLUNAS_LISTA_LIVRE[c];
                    const valorAtual = span.textContent.trim();

                    const wrap = document.createElement('div');
                    wrap.className = 'll-wrap';

                    const inp = document.createElement('input');
                    inp.type      = 'text';
                    inp.className = 'll-input';
                    inp.value     = valorAtual;
                    inp.autocomplete = 'off';
                    // Limite igual ao da coluna no banco; sem isso o texto
                    // seria cortado na gravação, sem aviso na tela.
                    if (COL_MAX[c]) inp.maxLength = COL_MAX[c];

                    const seta = document.createElement('i');
                    seta.className = 'fas fa-chevron-down ll-seta';

                    const lista = document.createElement('div');
                    lista.className = 'll-lista';

                    wrap.appendChild(inp);
                    wrap.appendChild(seta);
                    wrap.appendChild(lista);

                    // Mantém o punho de arraste ativo, para copiar para baixo
                    td.classList.add('fill-source');
                    fillSource = td;

                    span.style.display = 'none';
                    td.appendChild(wrap);

                    let jaFechou = false;
                    let indice   = -1;      // item destacado pelas setas
                    let digitou  = false;   // o usuário já alterou o texto?

                    const fechar = (salvar) => {
                        if (jaFechou) return;
                        jaFechou = true;

                        const novoValor = salvar ? inp.value.trim() : valorAtual;
                        if (wrap.parentNode) wrap.remove();
                        span.style.display = '';
                        span.textContent   = novoValor;

                        const anterior = td.dataset.original ?? '';
                        if (novoValor !== anterior) {
                            historicoAlteracoes.push({ td, valorAnterior: anterior });
                            td.dataset.original = novoValor;
                            tr.classList.add('editada');
                            linhasAlteradas.add(tr);
                            atualizarBtnDesfazer();
                        }
                    };

                    /* Monta a lista, filtrando pelo que já foi digitado.
                       Comparação sem acento e sem caixa: digitar "movei" acha
                       "Móveis e Utensílios". */
                    const semAcento = (s) => s.normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '').toLowerCase();

                    const desenhar = () => {
                        // Ao abrir, mostra TODAS as opções — mesmo que a célula
                        // já tenha um valor fora da lista, que é o caso da
                        // maioria dos registros existentes. Filtrar pelo valor
                        // atual fazia a lista abrir vazia e parecer travada.
                        const termo = digitou ? semAcento(inp.value.trim()) : '';
                        const vis = termo === ''
                            ? opcoes
                            : opcoes.filter(o => semAcento(o).includes(termo));

                        lista.innerHTML = '';
                        indice = -1;

                        if (!vis.length) {
                            const v = document.createElement('div');
                            v.className   = 'll-vazio';
                            v.textContent = 'Nenhuma sugestão — pode digitar livremente';
                            lista.appendChild(v);
                            return;
                        }

                        vis.forEach(op => {
                            const it = document.createElement('div');
                            it.className   = 'll-item';
                            it.textContent = op;
                            // mousedown, não click: o blur do campo dispara antes
                            // do click e fecharia a lista antes da escolha.
                            it.addEventListener('mousedown', e => {
                                e.preventDefault();
                                inp.value = op;
                                fechar(true);
                            });
                            lista.appendChild(it);
                        });
                    };

                    const destacar = (passo) => {
                        const itens = lista.querySelectorAll('.ll-item');
                        if (!itens.length) return;
                        itens.forEach(i => i.classList.remove('ll-ativo'));
                        indice += passo;
                        if (indice < 0) indice = itens.length - 1;
                        if (indice >= itens.length) indice = 0;
                        itens[indice].classList.add('ll-ativo');
                        itens[indice].scrollIntoView({ block: 'nearest' });
                    };

                    desenhar();
                    inp.focus();
                    inp.select();

                    inp.addEventListener('input', () => { digitou = true; desenhar(); });
                    inp.addEventListener('blur',  () => fechar(true));

                    inp.addEventListener('keydown', e => {
                        if (e.key === 'ArrowDown') { e.preventDefault(); destacar(1);  return; }
                        if (e.key === 'ArrowUp')   { e.preventDefault(); destacar(-1); return; }
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            const ativo = lista.querySelector('.ll-item.ll-ativo');
                            if (ativo) inp.value = ativo.textContent;   // usa o destacado
                            fechar(true);                              // ou o que foi digitado
                            return;
                        }
                        if (e.key === 'Escape') { e.preventDefault(); fechar(false); }
                    });

                    return;
                }

                /* ── COMBOBOX para colunas especiais ── */
                if (COLUNAS_COMBOBOX[c] !== undefined) {
                    // evita abrir dois selects
                    if (td.querySelector('select')) return;

                    const opcoes   = COLUNAS_COMBOBOX[c];
                    const valorAtual = span.textContent.trim();

                    // Habilita o punho de arraste também nas colunas de lista
                    // fechada. Antes elas saíam da função antes de receber a
                    // classe 'fill-source', e o punho nunca aparecia — o que
                    // obrigava a abrir a lista célula por célula.
                    td.classList.add('fill-source');
                    fillSource = td;

                    const sel = document.createElement('select');
                    sel.style.cssText = 'width:100%;font-size:13px;border:1px solid #6366f1;border-radius:4px;background:#eef2ff;padding:2px 4px;cursor:pointer;';

                    opcoes.forEach(op => {
                        const opt = document.createElement('option');
                        opt.value       = op;
                        opt.textContent = op === '' ? '(em branco)' : op;
                        if (op === valorAtual) opt.selected = true;
                        sel.appendChild(opt);
                    });

                    span.style.display = 'none';
                    td.appendChild(sel);
                    sel.focus();

                    // Guarda de execução única. A função está ligada a 'change',
                    // 'blur' e Enter — escolher com o mouse dispara 'change' e
                    // depois 'blur', e a segunda passagem tentava remover um
                    // elemento que já havia saído do DOM, lançando NotFoundError.
                    // O valor já era gravado antes disso, então o erro não
                    // perdia dado; apenas poluía o console.
                    let jaConfirmou = false;

                    const confirmar = () => {
                        if (jaConfirmou) return;
                        jaConfirmou = true;

                        const novoValor = sel.value;
                        if (sel.parentNode) sel.remove();
                        span.style.display = '';
                        span.textContent   = novoValor;
                        td.dataset.original !== novoValor && (() => {
                            const anterior = td.dataset.original ?? '';
                            if (novoValor !== anterior) {
                                historicoAlteracoes.push({ td, valorAnterior: anterior });
                                td.dataset.original = novoValor;
                                tr.classList.add('editada');
                                linhasAlteradas.add(tr);
                                atualizarBtnDesfazer();
                            }
                        })();
                    };

                    sel.addEventListener('change', confirmar);
                    sel.addEventListener('blur',   confirmar);
                    sel.addEventListener('keydown', e => {
                        if (e.key === 'Enter')  { e.preventDefault(); confirmar(); }
                        if (e.key === 'Escape') {
                            // Escape descarta: marca como concluído para o 'blur'
                            // seguinte não gravar o valor que o usuário abandonou.
                            jaConfirmou = true;
                            if (sel.parentNode) sel.remove();
                            span.style.display = '';
                        }
                    });
                    return;
                }

                /* ── EDIÇÃO LIVRE para demais colunas ── */
                span.contentEditable = true;
                td.classList.add('fill-source');
                fillSource = td;

                if (ehMoeda) {
                    const numAtual = moedaParaFloat(span.textContent);
                    span.textContent = exibirMoeda(floatParaBanco(numAtual));
                }

                /* aplica bloqueio de tipo na primeira edição */
                if (!span.dataset.bloqueioAplicado) {
                    span.dataset.bloqueioAplicado = '1';
                    if (!ehMoeda) aplicarBloqueioTipo(span, c);
                }

                span.focus();
                const range = document.createRange();
                range.selectNodeContents(span);
                range.collapse(false);
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(range);
            });

            if (ehMoeda) {
                span.addEventListener('keydown', e => {
                    // permite apenas dígitos, backspace, delete, setas
                    if (!['Backspace','Delete','ArrowLeft','ArrowRight','Tab'].includes(e.key) && !/^\d$/.test(e.key)) {
                        e.preventDefault();
                    }
                    // aplica máscara no próximo tick
                    setTimeout(() => {
                        if (span.contentEditable === 'true') aplicarMascaraMoeda(span);
                    }, 0);
                });
            }

            span.addEventListener('blur', () => {
                setTimeout(() => {
                    if (fillDragging) return;
                    span.contentEditable = false;
                    td.classList.remove('fill-source');

                    if (ehMoeda) {
                        // normaliza para exibição final e extrai valor para banco
                        const numFinal = moedaParaFloat(span.textContent);
                        const bancoval = floatParaBanco(numFinal);
                        span.textContent = exibirMoeda(bancoval);

                        const original = td.dataset.original ?? '';
                        if (bancoval !== original) {
                            historicoAlteracoes.push({ td, valorAnterior: original, ehMoeda: true, exibAnterior: exibirMoeda(original) });
                            td.dataset.original = bancoval;
                            tr.classList.add('editada');
                            linhasAlteradas.add(tr);
                            atualizarBtnDesfazer();
                        }
                    } else {
                        registrarAlteracao(td, tr);
                    }
                }, 80);
            });

            handle.addEventListener('mousedown', e => {
                e.preventDefault(); e.stopPropagation();
                fillSource = td; fillDragging = true;
            });

            tr.appendChild(td);
        });

        tbody.appendChild(tr);
    });

    recalcularColunasFixas();
}

/* ── Fill-down ── */
document.addEventListener('mousemove', e => {
    if (!fillDragging || !fillSource) return;
    fillPreviews.forEach(el => el.classList.remove('fill-preview'));
    fillPreviews = [];
    const alvo = document.elementFromPoint(e.clientX, e.clientY);
    if (!alvo) return;
    const tdAlvo = alvo.closest('td');
    if (!tdAlvo) return;
    const colIdx   = Array.from(fillSource.parentElement.children).indexOf(fillSource);
    const trInicio = fillSource.parentElement;
    const trFim    = tdAlvo.closest('tr');
    if (!trFim) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const iIni = rows.indexOf(trInicio);
    const iFim = rows.indexOf(trFim);
    if (iIni === -1 || iFim === -1 || iFim <= iIni) return;
    for (let i = iIni + 1; i <= iFim; i++) {
        const td2 = rows[i].children[colIdx];
        if (td2) { td2.classList.add('fill-preview'); fillPreviews.push(td2); }
    }
});

document.addEventListener('mouseup', () => {
    if (!fillDragging || !fillSource) { fillDragging = false; return; }
    const valor   = getCellValue(fillSource);
    const ehMoeda = fillSource.dataset.ehMoeda === '1';

    /* valor de origem já convertido para o formato do banco, quando for moeda */
    const bancovalFonte = ehMoeda ? floatParaBanco(moedaParaFloat(valor)) : null;

    fillPreviews.forEach(td => {
        td.classList.remove('fill-preview');
        const tr = td.closest('tr');

        /* guarda o valor "de banco" anterior (não o texto exibido) para permitir desfazer corretamente */
        const anteriorOriginal = td.dataset.original ?? getCellValue(td);

        setCellValue(td, valor);

        if (ehMoeda) {
            td.dataset.ehMoeda  = '1';
            td.dataset.original = bancovalFonte; // ← ESSENCIAL: sem isso, salvar() envia o valor antigo
            historicoAlteracoes.push({
                td,
                valorAnterior: anteriorOriginal,
                ehMoeda: true,
                exibAnterior: exibirMoeda(anteriorOriginal)
            });
        } else {
            td.dataset.original = getCellValue(td); // ← mantém consistência para demais colunas também
            historicoAlteracoes.push({ td, valorAnterior: anteriorOriginal });
        }

        tr.classList.add('editada');
        linhasAlteradas.add(tr);
    });
    fillPreviews  = [];
    fillDragging  = false;
    fillSource.contentEditable = false;
    fillSource.classList.remove('fill-source');
    fillSource = null;
    atualizarBtnDesfazer();
});

/* ═══════════════════════════════
   ALTERAÇÃO / HISTÓRICO
═══════════════════════════════ */
function registrarAlteracao(td, tr) {
    const atual    = getCellValue(td);
    const original = td.dataset.original ?? '';
    if (atual !== original) {
        historicoAlteracoes.push({ td, valorAnterior: original });
        td.dataset.original = atual;
        tr.classList.add('editada');
        linhasAlteradas.add(tr);
        atualizarBtnDesfazer();
    }
}
function atualizarBtnDesfazer() { btnDesfazer.disabled = historicoAlteracoes.length === 0; }
function desfazer() {
    if (historicoAlteracoes.length === 0) return;
    const { td, valorAnterior, ehMoeda, exibAnterior } = historicoAlteracoes.pop();
    const tr = td.closest('tr');
    if (ehMoeda) {
        setCellValue(td, exibAnterior || exibirMoeda(valorAnterior));
        td.dataset.original = valorAnterior;
    } else {
        setCellValue(td, valorAnterior);
        td.dataset.original = valorAnterior;
    }
    const todasOriginais = Array.from(tr.querySelectorAll('td'))
        .every(cell => {
            const cur = getCellValue(cell);
            const orig = cell.dataset.original ?? '';
            if (cell.dataset.ehMoeda === '1') return moedaParaFloat(cur) === moedaParaFloat(orig);
            return cur === orig;
        });
    if (todasOriginais) { tr.classList.remove('editada'); linhasAlteradas.delete(tr); }
    atualizarBtnDesfazer();
}

/* ═══════════════════════════════
   SELECIONAR LINHA
═══════════════════════════════ */
function selecionarLinha(tr) {
    tbody.querySelectorAll('tr').forEach(l => l.classList.remove('selecionada'));
    tr.classList.add('selecionada');
    linhaSelecionada = tr;
}

/* ═══════════════════════════════
   PAGINAÇÃO
═══════════════════════════════ */
function renderPaginacao(total) {
    paginacao.innerHTML = '';
    const paginas = Math.ceil(total / POR_PAGINA);
    if (paginas <= 1) return;
    const prev = document.createElement('button');
    prev.textContent = '<'; prev.className = 'btn'; prev.disabled = (paginaAtual === 1);
    prev.onclick = () => { paginaAtual--; buscarDados(); };
    paginacao.appendChild(prev);
    let inicio = Math.max(1, paginaAtual - 2);
    let fim    = Math.min(paginas, inicio + 4);
    for (let i = inicio; i <= fim; i++) {
        const b = document.createElement('button');
        b.textContent = i; b.className = 'btn';
        if (i === paginaAtual) b.style.opacity = '0.6';
        b.onclick = () => { paginaAtual = i; buscarDados(); };
        paginacao.appendChild(b);
    }
    const next = document.createElement('button');
    next.textContent = '>'; next.className = 'btn'; next.disabled = (paginaAtual === paginas);
    next.onclick = () => { paginaAtual++; buscarDados(); };
    paginacao.appendChild(next);
}

/* ═══════════════════════════════
   BUSCA GLOBAL
═══════════════════════════════ */
function disparaBusca() { termoAtivo = pesquisa.value.trim(); paginaAtual = 1; atualizarBotoesFiltro(); buscarDados(); }
function limparBusca()  { pesquisa.value = ''; termoAtivo = ''; filtrosAtivos = {}; paginaAtual = 1; atualizarBotoesFiltro(); buscarDados(); }
btnBuscar.addEventListener('click', disparaBusca);
pesquisa.addEventListener('keydown', e => { if (e.key === 'Enter') disparaBusca(); });

/* ═══════════════════════════════════════════════════
   SISTEMA DE BLOQUEIO POR TIPO DE COLUNA
   Define o comportamento de cada campo na edição
═══════════════════════════════════════════════════ */

/* Máximo de caracteres por coluna (banco real) */
const COL_MAX = {
    'status':45,'movimentado_definitivo':45,'movimentado':45,'folha':45,
    'unidade_destino':45,'setor_destino':45,'area_destino':45,
    'usuario_movimentacao':45,'unidade':45,'setor':45,'pavimento':45,'area':45,
    'tag_antiga':45,'tag_trocada':45,'propriedade':45,'empresa':45,'tag_alugado':45,
    'descricao':45,'marca':45,'modelo':45,'serie':45,
    'usuario_cadastro':45,'usuario_inspecao':45,'encontrado':45,'estado':45,
    'n_conformidade':45,'status2':45,'o_servico':45,
    'centro_custo_unidade':45,'centro_custo_setor':45,
    'unidade_atribuida':45,'setor_atribuido':45,
    'conciliado':45,'usuario_conciliacao':45,'nota_fiscal':45,
    'fornecedor_cnpj':45,'valor_nota':45,'valor_item':45,
    'depreciacao_acumulada':45,'contrato_arrendamento':45,
    'obs_movimentacao':100,'descricao_detalhada':100,'fornecedor_nome':100,
    'observacao':110,'grupo':110,'classe':110,'subgrupo':110,'obs3':150,
};

/* Colunas de data */
const COL_DATE = new Set([
    'periodo','data_movimentacao','data_inspecao',
    'data_baixa','data_aquisicao','data_inicio_depreciacao'
]);

/* Colunas numéricas inteiras (decimal no banco) */
const COL_DECIMAL = new Set(['saldo_remanecente']);

/* ── Tooltip flutuante de erro ── */
let _tooltipEl = null;
function mostrarTooltipErro(span, msg) {
    removerTooltipErro();
    _tooltipEl = document.createElement('div');
    _tooltipEl.style.cssText = [
        'position:fixed','z-index:99999','background:#991b1b','color:#fff',
        'font-size:12px','font-weight:700','padding:5px 10px','border-radius:6px',
        'box-shadow:0 4px 12px rgba(0,0,0,.4)','pointer-events:none','white-space:nowrap'
    ].join(';');
    _tooltipEl.textContent = '⚠ ' + msg;
    document.body.appendChild(_tooltipEl);
    const r = span.getBoundingClientRect();
    _tooltipEl.style.left = r.left + 'px';
    _tooltipEl.style.top  = (r.bottom + 4) + 'px';
}
function removerTooltipErro() {
    if (_tooltipEl) { _tooltipEl.remove(); _tooltipEl = null; }
}

/* ── Aplica comportamento de bloqueio num span editável ── */
function aplicarBloqueioTipo(span, col) {
    const ehDate    = COL_DATE.has(col);
    const ehDecimal = COL_DECIMAL.has(col);
    const maxLen    = COL_MAX[col] || 0;

    if (ehDate) {
        /* ─ DATA: máscara DD-MM-AAAA, só dígitos e hífens ─ */
        span.addEventListener('keydown', e => {
            const permitidas = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End'];
            if (permitidas.includes(e.key)) return;
            /* só dígito ou hífen */
            if (!/^\d$/.test(e.key) && e.key !== '-') {
                e.preventDefault();
                mostrarTooltipErro(span, 'Data: use apenas dígitos e hífens (DD-MM-AAAA)');
                setTimeout(removerTooltipErro, 2000);
                return;
            }
            /* limite de 10 chars: DD-MM-AAAA */
            if (span.textContent.replace(/\s/g,'').length >= 10 && window.getSelection().toString() === '') {
                e.preventDefault();
                mostrarTooltipErro(span, 'Data máxima: DD-MM-AAAA (10 caracteres)');
                setTimeout(removerTooltipErro, 2000);
            }
        });
        /* Máscara automática: insere hífens nas posições 2 e 5
           Só aplica se o usuário realmente digitou (valor diferente do original) */
        let _dataMascaraAtiva = false;
        span.addEventListener('keydown', e => {
            if (!['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End'].includes(e.key)) {
                _dataMascaraAtiva = true; // usuário digitou uma tecla de conteúdo
            }
        });
        span.addEventListener('input', () => {
            if (!_dataMascaraAtiva) return; // não aplica máscara se não houve digitação
            let digits = span.textContent.replace(/\D/g,'');
            if (digits.length > 8) digits = digits.slice(0,8);
            let masked = digits;
            if (digits.length > 2) masked = digits.slice(0,2) + '-' + digits.slice(2);
            if (digits.length > 4) masked = digits.slice(0,2) + '-' + digits.slice(2,4) + '-' + digits.slice(4);
            if (span.textContent !== masked) {
                span.textContent = masked;
                const range = document.createRange();
                range.selectNodeContents(span);
                range.collapse(false);
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(range);
            }
        });
        /* Validação ao sair — só se o usuário alterou o valor */
        let _dataValorAoEntrar = '';
        span.addEventListener('focus', () => {
            _dataValorAoEntrar = span.textContent.trim();
            span.style.background = '';
            span.style.outline    = '';
            removerTooltipErro();
        });
        span.addEventListener('blur', () => {
            const val = span.textContent.trim();
            if (val === '' || val === _dataValorAoEntrar) return; // não alterado — não valida
            const ok = /^\d{2}-\d{2}-\d{4}$/.test(val);
            if (!ok) {
                span.style.background = '#fee2e2';
                span.style.outline    = '2px solid #ef4444';
                mostrarTooltipErro(span, 'Data inválida! Use DD-MM-AAAA');
            } else {
                span.style.background = '';
                span.style.outline    = '';
                removerTooltipErro();
            }
        });

    } else if (ehDecimal) {
        /* ─ DECIMAL: só dígitos, vírgula e sinal ─ */
        span.addEventListener('keydown', e => {
            const permitidas = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End'];
            if (permitidas.includes(e.key)) return;
            if (e.key === '-' && span.textContent === '') return; // sinal no início
            if (e.key === ',' && !span.textContent.includes(',')) return; // uma vírgula
            if (!/^\d$/.test(e.key)) {
                e.preventDefault();
                mostrarTooltipErro(span, 'Somente números (ex: 1234 ou 1234,56)');
                setTimeout(removerTooltipErro, 2000);
            }
        });
        let _decValorAoEntrar = '';
        span.addEventListener('focus', () => {
            _decValorAoEntrar = span.textContent.trim();
            span.style.background = ''; span.style.outline = ''; removerTooltipErro();
        });
        span.addEventListener('blur', () => {
            const val = span.textContent.trim();
            if (val === '' || val === _decValorAoEntrar) return; // não alterado — não valida
            const ok = /^-?\d+([,]\d+)?$/.test(val);
            if (!ok) {
                span.style.background = '#fee2e2';
                span.style.outline    = '2px solid #ef4444';
            } else {
                span.style.background = '';
                span.style.outline    = '';
            }
        });

    } else if (maxLen > 0) {
        /* ─ VARCHAR: bloqueia quando atinge o limite ─ */
        span.addEventListener('keydown', e => {
            const permitidas = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End'];
            if (permitidas.includes(e.key)) return;
            if (e.ctrlKey || e.metaKey) return; // permite colar, copiar, etc.
            const sel = window.getSelection().toString();
            if (span.textContent.length >= maxLen && sel === '') {
                e.preventDefault();
                mostrarTooltipErro(span, `Limite de ${maxLen} caracteres atingido`);
                setTimeout(removerTooltipErro, 2000);
            }
        });
        /* Trunca se colar texto maior */
        span.addEventListener('input', () => {
            if (span.textContent.length > maxLen) {
                span.textContent = span.textContent.substring(0, maxLen);
                const range = document.createRange();
                range.selectNodeContents(span);
                range.collapse(false);
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(range);
                mostrarTooltipErro(span, `Limite de ${maxLen} caracteres — texto truncado`);
                setTimeout(removerTooltipErro, 2500);
            }
        });
    }
}

/* ═══════════════════════════════
   SALVAR
   Para moeda: envia valor do banco (td.dataset.original após edição)
═══════════════════════════════ */
function salvar() {
    if (linhasAlteradas.size === 0) {
        alert('Nenhuma alteração para salvar.');
        return;
    }
    salvarLinhasNormais(false);
}

/*
 * Quantas linhas seguem por requisição.
 *
 * Cada linha carrega 59 colunas. Com o preenchimento por arraste, é fácil
 * marcar centenas de linhas de uma vez, e o envio único virava um POST de
 * megabytes: numa queda de Wi-Fi no meio do caminho, a requisição morre sem
 * resposta ("Failed to fetch") e TODO o trabalho volta para a estaca zero.
 * Em lotes, uma falha custa no máximo o último lote.
 */
const LOTE_SALVAR = 100;

/* Envia um lote. Tenta de novo uma vez se a conexão falhar (não se o
   servidor recusar — recusa é resposta, e repetir não mudaria nada). */
function enviarLote(linhas, jaRepetiu) {
    return fetch('salvar_planilha.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ dados: linhas })
    })
    .then(r => r.text())
    .then(txt => {
        try {
            return JSON.parse(txt);
        } catch (e) {
            console.error('planilha: resposta não-JSON ao salvar (' +
                          linhas.length + ' linhas): ' + txt.slice(0, 300));
            return { sucesso: false, mensagem: 'O servidor devolveu uma resposta inesperada.' };
        }
    })
    .catch(err => {
        if (!jaRepetiu) {
            return new Promise(r => setTimeout(r, 1500))
                   .then(() => enviarLote(linhas, true));
        }
        console.error('planilha: conexão perdida ao salvar ' + linhas.length +
                      ' linhas (navegador ' + (navigator.onLine ? 'online' : 'offline') +
                      '): ' + err.message);
        return { sucesso: false, semConexao: true };
    });
}

/* Save por linha (fluxo original). Retorna Promise. */
function salvarLinhasNormais(silencioso) {
    if (linhasAlteradas.size === 0) return Promise.resolve();

    /* Guarda o <tr> junto do payload: sem isso não dá para saber quais linhas
       foram efetivamente salvas quando um lote falha no meio. */
    const pendentes = [];
    linhasAlteradas.forEach(tr => {
        const row = [tr.dataset.id];
        const tds = tr.querySelectorAll('td');
        for (let i = 1; i < tds.length; i++) {
            const td = tds[i];
            if (td.dataset.ehMoeda === '1') {
                row.push(td.dataset.original ?? '');
            } else {
                row.push(normalizarData(getCellValue(td)));
            }
        }
        pendentes.push({ tr: tr, row: row });
    });

    const lotes = [];
    for (let i = 0; i < pendentes.length; i += LOTE_SALVAR) {
        lotes.push(pendentes.slice(i, i + LOTE_SALVAR));
    }

    let salvas = 0;

    /* Sequencial, não em paralelo: são UPDATEs na mesma tabela e a hospedagem
       é compartilhada — disparar tudo de uma vez atrapalharia os outros. */
    const percorrer = (indice) => {
        if (indice >= lotes.length) return Promise.resolve(null);

        return enviarLote(lotes[indice].map(p => p.row), false).then(j => {
            if (!j.sucesso) return j;
            lotes[indice].forEach(p => linhasAlteradas.delete(p.tr));
            salvas += lotes[indice].length;
            return percorrer(indice + 1);
        });
    };

    return percorrer(0).then(falha => {
        historicoAlteracoes = [];
        atualizarBtnDesfazer();

        if (!falha) {
            if (!silencioso) {
                alert(salvas === 1 ? '1 item salvo com sucesso.'
                                   : salvas + ' itens salvos com sucesso.');
            }
            return;
        }

        /* Houve falha. As linhas não salvas continuam marcadas: basta clicar
           em Salvar de novo, nada foi perdido. Isso precisa estar na mensagem
           — o usuário não tem como adivinhar. */
        const restantes = linhasAlteradas.size;
        let msg;

        if (falha.semConexao) {
            msg = 'A conexão caiu durante o salvamento.\n\n';
        } else {
            msg = (falha.mensagem || falha.erro_fatal || 'O salvamento foi interrompido.') + '\n\n';
        }

        if (salvas > 0) msg += salvas + ' ' + (salvas === 1 ? 'item já foi salvo' : 'itens já foram salvos') + '.\n';
        msg += restantes + ' ' + (restantes === 1 ? 'item continua' : 'itens continuam') +
               ' com alterações pendentes.\n\n' +
               'Suas edições NÃO foram perdidas. Clique em Salvar novamente.';

        alert(msg);
    });
}

/* ═══════════════════════════════
   FILTRO POR COR
═══════════════════════════════ */
function toggleFiltroCor(cor) {
    filtroCor = filtroCor === cor ? '' : cor;
    document.getElementById('btnCorVermelho').classList.toggle('ativo', filtroCor === 'vermelho');
    document.getElementById('btnCorAmarelo').classList.toggle('ativo',  filtroCor === 'amarelo');
    document.getElementById('btnCorLaranja').classList.toggle('ativo',  filtroCor === 'laranja');
    atualizarBotoesFiltro();
    paginaAtual = 1;
    buscarDados();
}

/* ═══════════════════════════════
   EXCLUIR
═══════════════════════════════ */
function excluirLinha() {
    if (!linhaSelecionada) { alert('Selecione uma linha primeiro'); return; }

    /* verifica se a linha está conciliada */
    const idxConciliado = COLUNAS.indexOf('conciliado');
    let estaConciliado  = false;
    if (idxConciliado !== -1) {
        const tdConc = linhaSelecionada.querySelectorAll('td')[idxConciliado];
        if (tdConc) {
            const valConc = (tdConc.querySelector('.cell-text')?.textContent ?? '').trim().toUpperCase();
            estaConciliado = valConc === 'SIM';
        }
    }

    /* confirmação padrão ou reforçada se conciliado */
    if (estaConciliado) {
        if (!confirm('⚠️ Este item está conciliado. Deseja excluir mesmo assim?')) return;
    } else {
        if (!confirm('Deseja excluir este dado do banco?')) return;
    }

    const id = linhaSelecionada.dataset.id;
    fetch('excluir_linha.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
    })
    .then(r => r.json())
    .then(j => {
        if (j.sucesso) { alert('Registro excluído com sucesso'); linhaSelecionada.remove(); linhaSelecionada = null; }
        else alert('Erro: ' + j.mensagem);
    });
}

/* ═══════════════════════════════
   MOVIMENTAR
═══════════════════════════════ */
function movimentar() {
    if (!linhaSelecionada) { alert('Selecione um item primeiro!'); return; }
    const tds = linhaSelecionada.querySelectorAll('td');
    let id = '';
    COLUNAS.forEach((c, i) => { if (c === 'id') id = tds[i].innerText.trim(); });
    if (!id) { alert('ID não encontrado!'); return; }
    location.href = 'movimentar.php?id=' + encodeURIComponent(id);
}

/* ═══════════════════════════════
   OUTROS
═══════════════════════════════ */
function exportarXLSX() { location.href = 'exportar_planilha.php'; }
function voltar()        { location.href = 'inicial.php'; }

/* ═══════════════════════════════
   FILTRO ESTILO EXCEL
═══════════════════════════════ */
const dropdown       = document.getElementById('filtroDropdown');
const filtroSearch   = document.getElementById('filtroSearch');
const filtroLista    = document.getElementById('filtroLista');
const btnSelTodos    = document.getElementById('btnSelecionarTodos');
const btnLimpar      = document.getElementById('btnLimparFiltro');
const btnOk          = document.getElementById('btnOkFiltro');
const btnCancel      = document.getElementById('btnCancelFiltro');
const colFiltroAtivo = new Set();
let dropdownColAtual    = null;
let dropdownOpcoes      = [];
let dropdownSelecionado = new Set();
let dropdownSalvoSnap   = null;

function posicionarDropdown(btn) {
    const rect = btn.getBoundingClientRect();
    const ddW  = 240;
    let left = rect.left;
    let top  = rect.bottom + 2;
    if (left + ddW > window.innerWidth - 8) left = window.innerWidth - ddW - 8;
    dropdown.style.left  = left + 'px';
    dropdown.style.top   = top  + 'px';
    dropdown.style.width = ddW  + 'px';
}

function ordenarOpcoes(arr) {
    const vazios  = arr.filter(op => op === '');
    const normais = arr.filter(op => op !== '').sort((a, b) => {
        const na = parseFloat(a), nb = parseFloat(b);
        if (!isNaN(na) && !isNaN(nb)) return na - nb;
        return String(a).localeCompare(String(b), 'pt-BR', { sensitivity: 'base' });
    });
    return [...vazios, ...normais];
}

function renderLista(termoBusca) {
    const termo    = termoBusca.toLowerCase();
    const visiveis = dropdownOpcoes.filter(op => {
        const label = op === '' ? '(vazio)' : String(op).toLowerCase();
        return label.includes(termo);
    });
    filtroLista.innerHTML = '';
    if (!termo) {
        const todosMarcado = visiveis.length > 0 && visiveis.every(op => dropdownSelecionado.has(op));
        filtroLista.appendChild(criarItem('__todos__', '(Selecionar Tudo)', todosMarcado, true));
    }
    if (visiveis.length === 0) { filtroLista.innerHTML = '<div class="filtro-loading">Nenhum resultado</div>'; return; }
    visiveis.forEach(op => {
        const label  = op === '' ? '(Vazio)' : op;
        filtroLista.appendChild(criarItem(op, label, dropdownSelecionado.has(op), false));
    });
}

function criarItem(valor, label, marcado, isTodos) {
    const uid = 'fck_' + Math.random().toString(36).slice(2);
    const div = document.createElement('div');
    div.className = 'filtro-item' + (isTodos ? ' item-todos' : '');
    const cb = document.createElement('input');
    cb.type = 'checkbox'; cb.id = uid; cb.checked = marcado;
    const lbl = document.createElement('label');
    lbl.htmlFor = uid; lbl.textContent = label; lbl.title = label;
    cb.addEventListener('change', () => {
        if (isTodos) {
            const t  = filtroSearch.value.toLowerCase();
            const vs = dropdownOpcoes.filter(op => { const lb = op===''?'(vazio)':String(op).toLowerCase(); return lb.includes(t); });
            vs.forEach(op => cb.checked ? dropdownSelecionado.add(op) : dropdownSelecionado.delete(op));
            renderLista(filtroSearch.value);
        } else {
            cb.checked ? dropdownSelecionado.add(valor) : dropdownSelecionado.delete(valor);
            sincronizarCheckboxTodos();
        }
    });
    div.appendChild(cb); div.appendChild(lbl);
    return div;
}

function sincronizarCheckboxTodos() {
    const cbTodos = filtroLista.querySelector('.item-todos input');
    if (!cbTodos) return;
    const t  = filtroSearch.value.toLowerCase();
    const vs = dropdownOpcoes.filter(op => { const lb = op===''?'(vazio)':String(op).toLowerCase(); return lb.includes(t); });
    cbTodos.checked = vs.length > 0 && vs.every(op => dropdownSelecionado.has(op));
}

function abrirDropdown(btn, col) {
    if (dropdownColAtual === col && dropdown.classList.contains('aberto')) { fecharDropdown(false); return; }
    dropdownColAtual = col;
    filtroSearch.value = '';
    filtroLista.innerHTML = '<div class="filtro-loading">Carregando...</div>';
    dropdown.classList.add('aberto');
    posicionarDropdown(btn);
    // atualizar visual dos botões de sort para esta coluna
    document.getElementById('sortAZ').classList.toggle('ativo', sortCol === col && sortDir === 'asc');
    document.getElementById('sortZA').classList.toggle('ativo', sortCol === col && sortDir === 'desc');
    fetch('planilha_dados.php?' + buildQSparaOpcoes(col))
        .then(r => r.json())
        .then(opcoes => {
            dropdownOpcoes = ordenarOpcoes(opcoes);
            // Para datas, normalizar valores salvos para YYYY-MM-DD antes de comparar
            const filtroSalvoRaw = filtrosAtivos[col];
            const filtroSalvo = (filtroSalvoRaw && COLUNAS_DATA.has(col))
                ? new Set([...filtroSalvoRaw].map(v => v ? String(v).substring(0, 10) : v))
                : filtroSalvoRaw;
            if (filtroSalvo && filtroSalvo.size > 0) {
                const opSet = new Set(dropdownOpcoes);
                // mapeia sentinel de volta para '' para comparar com dropdownOpcoes
                dropdownSelecionado = new Set([...filtroSalvo].map(v => v === VAZIO_SENTINEL ? '' : v).filter(v => opSet.has(v)));
                dropdownSalvoSnap   = new Set(dropdownSelecionado);
            } else {
                dropdownSelecionado = new Set(dropdownOpcoes);
                dropdownSalvoSnap   = null;
            }
            renderLista('');
        })
        .catch(() => { filtroLista.innerHTML = '<div class="filtro-loading">Erro ao carregar</div>'; });
}

function fecharDropdown(salvar) {
    if (salvar && dropdownColAtual) {
        const col        = dropdownColAtual;
        const termoBusca = filtroSearch.value.trim();
        delete filtrosAtivos[col]; delete filtrosLike[col];
        colFiltroAtivo.delete(col); colExcluirVazio.delete(col); colFiltrarVazio.delete(col);
        if (termoBusca !== '') {
            const visiveis = dropdownOpcoes.filter(op => { const lb = op===''?'(vazio)':String(op).toLowerCase(); return lb.includes(termoBusca.toLowerCase()); });
            const todosVisivelMarcados = visiveis.length > 0 && visiveis.every(op => dropdownSelecionado.has(op));
            if (todosVisivelMarcados) {
                filtrosLike[col] = termoBusca; colFiltroAtivo.add(col);
            } else {
                const selecionadosVisiveis = visiveis.filter(op => dropdownSelecionado.has(op));
                if (selecionadosVisiveis.length > 0) {
                    const vals = COLUNAS_DATA.has(col)
                        ? selecionadosVisiveis.map(v => String(v).substring(0, 10))
                        : selecionadosVisiveis;
                    filtrosAtivos[col] = new Set(vals); colFiltroAtivo.add(col);
                }
            }
        } else {
            const temVazioNaColuna    = dropdownOpcoes.includes('');
            const vazioSelecionado    = dropdownSelecionado.has('');
            const vazioDesmarcado     = temVazioNaColuna && !vazioSelecionado;
            const normais             = dropdownOpcoes.filter(op => op !== '');
            const normaisSelecionados = normais.filter(op => dropdownSelecionado.has(op));
            const todosNormaisMarcados = normais.length === 0 || normais.every(op => dropdownSelecionado.has(op));
            const nenhumNormalSelecionado = normaisSelecionados.length === 0;

            // Caso: SOMENTE (Vazio) selecionado → buscar registros nulos/vazios
            if (vazioSelecionado && nenhumNormalSelecionado) {
                colFiltrarVazio.add(col);  colFiltroAtivo.add(col);
                colExcluirVazio.delete(col);
            } else {
                colFiltrarVazio.delete(col);
                // Caso: (Vazio) desmarcado → excluir registros nulos/vazios
                if (vazioDesmarcado) { colExcluirVazio.add(col); colFiltroAtivo.add(col); }
                else colExcluirVazio.delete(col);
                // Caso: valores específicos selecionados (subset de normais, com ou sem vazio)
                if (!todosNormaisMarcados && !nenhumNormalSelecionado) {
                    // Para datas, normalizar para YYYY-MM-DD
                    const valsBase = COLUNAS_DATA.has(col)
                        ? normaisSelecionados.map(v => String(v).substring(0, 10))
                        : [...normaisSelecionados];
                    // Se (Vazio) também está marcado, inclui o sentinel para o backend filtrar vazios junto
                    if (vazioSelecionado && temVazioNaColuna) valsBase.push(VAZIO_SENTINEL);
                    filtrosAtivos[col] = new Set(valsBase);
                    colFiltroAtivo.add(col);
                }
            }
        }
        atualizarBotoesFiltro(); paginaAtual = 1; buscarDados();
    }
    dropdown.classList.remove('aberto'); dropdownColAtual = null;
}

function aplicarSort(dir) {
    if (!dropdownColAtual) return;
    if (sortCol === dropdownColAtual && sortDir === dir) {
        // clicou no mesmo: remove ordenação (toggle)
        sortCol = null; sortDir = null;
    } else {
        sortCol = dropdownColAtual;
        sortDir = dir;
    }
    // Apenas atualiza visual — dropdown permanece aberto para o usuário
    // ajustar checkboxes também. O OK aplica sort + filtro juntos.
    const btnAZ = document.getElementById('sortAZ');
    const btnZA = document.getElementById('sortZA');
    btnAZ.classList.toggle('ativo', sortCol === dropdownColAtual && sortDir === 'asc');
    btnZA.classList.toggle('ativo', sortCol === dropdownColAtual && sortDir === 'desc');
}

function atualizarBotoesFiltro() {
    document.querySelectorAll('.filtro-btn').forEach(btn => {
        const col = btn.dataset.col;
        const temFiltro = colFiltroAtivo.has(col);
        const temSort   = sortCol === col;
        btn.classList.toggle('ativo', temFiltro || temSort);
        btn.title = temFiltro ? 'Filtro ativo — clique para alterar' : temSort ? `Ordenado (${sortDir === 'asc' ? 'A→Z' : 'Z→A'}) — clique para alterar` : 'Filtrar / Ordenar';
    });
    // atualiza botões do dropdown conforme coluna aberta
    const btnAZ = document.getElementById('sortAZ');
    const btnZA = document.getElementById('sortZA');
    if (btnAZ && btnZA) {
        btnAZ.classList.toggle('ativo', sortCol === dropdownColAtual && sortDir === 'asc');
        btnZA.classList.toggle('ativo', sortCol === dropdownColAtual && sortDir === 'desc');
    }
    /* habilitado se qualquer filtro estiver ativo: coluna, duplicados, cor, pesquisa ou ordenação */
    const temFiltro = colFiltroAtivo.size > 0 || filtroDuplicados || termoAtivo !== '' || filtroCor !== '' || sortCol !== null;
    document.getElementById('btnLimparFiltros').disabled = !temFiltro;
}

function limparTodosFiltros() {
    /* limpa todos os estados de filtro */
    filtrosAtivos    = {};
    filtrosLike      = {};
    filtroDuplicados = false;
    filtroCor        = '';
    termoAtivo       = '';
    sortCol          = null;
    sortDir          = null;
    colFiltroAtivo.clear();
    colExcluirVazio.clear();
    colFiltrarVazio.clear();

    /* limpa visualmente a barra de pesquisa */
    document.getElementById('pesquisa').value = '';

    /* remove destaque do botão duplicados */
    document.getElementById('btnDuplicados').classList.remove('ativo');

    /* remove destaque dos botões de cor */
    document.getElementById('btnCorVermelho').classList.remove('ativo');
    document.getElementById('btnCorAmarelo').classList.remove('ativo');
    document.getElementById('btnCorLaranja').classList.remove('ativo');

    /* fecha dropdown de filtro se estiver aberto */
    if (dropdown.classList.contains('aberto')) fecharDropdown(false);

    paginaAtual = 1;
    atualizarBotoesFiltro();
    buscarDados();
}

function toggleFiltroDuplicados() {
    filtroDuplicados = !filtroDuplicados;
    const btn = document.getElementById('btnDuplicados');
    btn.classList.toggle('ativo', filtroDuplicados);
    btn.title = filtroDuplicados ? 'Exibindo apenas duplicados — clique para remover filtro' : 'Exibir apenas registros duplicados';
    document.getElementById('btnLimparFiltros').disabled = colFiltroAtivo.size === 0 && !filtroDuplicados;
    paginaAtual = 1; buscarDados();
}

function carregarContadorDuplicados() {
    const badge = document.getElementById('dupBadge');
    fetch('planilha_dados.php?modo=contar_duplicados')
        .then(r => r.json())
        .then(d => {
            const excesso = d.excesso ?? 0;
            badge.textContent = excesso;
            if (excesso > 0) { badge.classList.add('tem-dup'); badge.title = `${excesso} item(s) em excesso — ${esc(d.total_dup)} registros envolvidos`; }
            else { badge.classList.remove('tem-dup'); badge.title = 'Nenhum duplicado encontrado'; }
        })
        .catch(() => { badge.textContent = '—'; badge.title = 'Erro ao carregar contador'; });
}

filtroSearch.addEventListener('input', () => renderLista(filtroSearch.value));

document.getElementById('sortAZ').addEventListener('click', () => aplicarSort('asc'));
document.getElementById('sortZA').addEventListener('click', () => aplicarSort('desc'));

btnSelTodos.addEventListener('click', () => {
    const t        = filtroSearch.value.toLowerCase();
    const visiveis = dropdownOpcoes.filter(op => { const lb = op===''?'(vazio)':String(op).toLowerCase(); return lb.includes(t); });
    visiveis.forEach(op => dropdownSelecionado.add(op));
    renderLista(filtroSearch.value);
});
btnLimpar.addEventListener('click', () => { dropdownSelecionado.clear(); renderLista(filtroSearch.value); });
btnOk.addEventListener('click', () => fecharDropdown(true));
btnCancel.addEventListener('click', () => {
    dropdownSelecionado = dropdownSalvoSnap === null ? new Set(dropdownOpcoes) : new Set(dropdownSalvoSnap);
    fecharDropdown(false);
});

document.addEventListener('mousedown', e => {
    if (!dropdown.contains(e.target) && !e.target.classList.contains('filtro-btn') && !e.target.classList.contains('pin-btn') && !e.target.closest('.sort-btn')) {
        if (dropdown.classList.contains('aberto')) fecharDropdown(false);
    }
});
document.getElementById('tableContainer').addEventListener('scroll', () => {
    if (dropdown.classList.contains('aberto')) fecharDropdown(false);
});
document.querySelectorAll('.filtro-btn').forEach(btn => {
    btn.addEventListener('click', e => { e.stopPropagation(); abrirDropdown(btn, btn.dataset.col); });
});
window.addEventListener('resize', () => {
    if (dropdown.classList.contains('aberto')) {
        const btn = document.querySelector(`.filtro-btn[data-col="${dropdownColAtual}"]`);
        if (btn) posicionarDropdown(btn);
    }
    recalcularColunasFixas();
});

/* ═══════════════════════════════
   INIT
═══════════════════════════════ */
buscarDados();
setTimeout(carregarContadorDuplicados, 1500);
</script>

<script>
(function hb(){
    fetch('heartbeat.php?_=' + Date.now(), { method:'POST', credentials:'same-origin', cache:'no-store' })
    .then(r => r.json())
    .then(data => { if (data.revogada) window.location.href = 'index.html?error=Sua+sessao+foi+encerrada'; })
    .catch(() => {});
    setTimeout(hb, 15000);
})();
</script>
</body>
y>
</html>