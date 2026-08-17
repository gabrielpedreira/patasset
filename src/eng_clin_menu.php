<?php
/**
 * eng_clin_menu.php — Menu lateral + Caixa de Ferramentas do LifeTech
 * ═══════════════════════════════════════════════════════════════════════════
 * FONTE ÚNICA de navegação da Engenharia Clínica. Alterar o menu = editar
 * SÓ este arquivo. Antes isso estava duplicado em 11 páginas e já havia
 * divergido (menus incompletos e uma tag <a> quebrada em cadastrodetecnico).
 *
 * ── USO ────────────────────────────────────────────────────────────────────
 * Antes de qualquer saída HTML, defina a página atual:
 *
 *   <?php $ENG_CLIN_PAGINA = 'estoque'; ?>
 *
 * Chaves válidas: chamado, cadastro, planilha, os, estoque, movimentar,
 *                 pecas, inicial   (ou '' para nenhuma ativa)
 *
 * 1) No <head>, depois do CSS da página:
 *      <?php eng_clin_menu_css(); ?>
 *
 * 2) Logo após <body>:
 *      <?php eng_clin_menu_sidebar(); ?>
 *
 * 3) Dentro da <header class="topbar">, no fim (depois do logo):
 *      <?php eng_clin_menu_botoes(); ?>
 *
 * 4) Antes do </body> (fora do #main), e depois o <script>:
 *      <?php eng_clin_menu_painel(); ?>
 *      <script> ... <?php eng_clin_menu_js(); ?> ... </script>
 *    ou simplesmente <?php eng_clin_menu_painel(true); ?> que já emite o
 *    <script> com o JS dentro.
 * ═══════════════════════════════════════════════════════════════════════════
 */

if (!defined('ENG_CLIN_MENU_CARREGADO')) {
define('ENG_CLIN_MENU_CARREGADO', true);

/** Itens do menu lateral: chave => [rótulo, arquivo, ícone Font Awesome] */
function eng_clin_menu_itens(): array {
    return [
        // Página Inicial vem primeiro: é o ponto de retorno mais usado.
        // Na própria inicial o item é omitido (ver eng_clin_menu_sidebar).
        'inicial'    => ['Página Inicial',          'engenharia_clinica_inicial.php', "\\f015"],
        'chamado'    => ['Abertura de Chamado',     'eng_clin_aberturadechamado.php', "\\f46d"],
        'cadastro'   => ['Cadastro de Equipamento', 'eng_clin_cadastro.php',          "\\f0fe"],
        'planilha'   => ['Planilha',                'eng_clin_planilha.php',          "\\f0ce"],
        'os'         => ['Ordem de Serviço',        'eng_clin_ordemdeservico.php',    "\\f570"],
        'estoque'    => ['Estoque',                 'eng_clin_inventario.php',        "\\f49e"],
        'movimentar' => ['Movimentar',              'eng_clin_movimentar.php',        "\\f362"],
        // f7d9 = screwdriver-wrench (ferramentas). Antes era f54b (shoe-prints,
        // "pegadas"), que não tinha relação com retirada de peças.
        'pecas'      => ['Retirada de Peças',       'eng_clin_retiradadepecas.php',   "\\f7d9"],
        // f274 = calendar-check
        'preventivas'=> ['Preventivas',             'eng_clin_preventivas.php',       "\\f274"],
        'relatorios' => ['Relatórios',              'eng_clin_relatorios.php',        "\\f080"],
    ];
}

/** Página ativa (definida pela página que inclui este arquivo) */
function eng_clin_menu_atual(): string {
    return $GLOBALS['ENG_CLIN_PAGINA'] ?? '';
}

/* ═══════════════════════════════════════════════════════════════════════════
   1) CSS — ícones do menu recolhido + topbar-btn + painel de ferramentas
   ═══════════════════════════════════════════════════════════════════════════ */
function eng_clin_menu_css(): void { ?>
<style>
/* ── Ícones do menu recolhido (gerados a partir de eng_clin_menu_itens) ── */
<?php foreach (eng_clin_menu_itens() as $it): ?>
#sidebar.collapsed .nav-item[data-tooltip="<?= $it[0] ?>"]::before{content:"<?= $it[2] ?>"}
<?php endforeach; ?>
#sidebar.collapsed .nav-item[data-tooltip="Sair"]::before{content:"\f2f5"}

/* ── Botões da topbar ── */
.topbar-btn{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid var(--border);background:#1c1c1c;color:var(--text-secondary);cursor:pointer;transition:background var(--transition),color var(--transition),border-color var(--transition);font-size:13px;position:relative;flex-shrink:0}
.topbar-btn:hover{background:#2a2a2a;color:var(--text-primary);border-color:var(--border-hover)}
.topbar-btn.ativo{background:#2a2a2a;color:var(--text-primary);border-color:var(--border-hover)}
.topbar-btn .notif-dot{position:absolute;top:5px;right:5px;width:6px;height:6px;border-radius:50%;background:var(--status-warn);border:1.5px solid var(--bg-page)}

/* ── Caixa de ferramentas (painel lateral direito) ── */
.tools-panel{position:fixed;top:var(--header-h);right:-280px;width:260px;height:calc(100vh - var(--header-h));background:var(--bg-sidebar);border-left:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:right var(--transition);box-shadow:-4px 0 24px rgba(0,0,0,.4)}
.tools-panel.open{right:0}
.tools-header{padding:16px 18px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.tools-title{font-family:var(--font-display);font-size:13px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--accent-steel)}
.tools-close{width:26px;height:26px;display:flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid var(--border);background:none;color:var(--text-muted);cursor:pointer;font-size:13px;transition:background var(--transition),color var(--transition)}
.tools-close:hover{background:#2a2a2a;color:var(--text-primary)}
.tools-section-label{font-size:9.5px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);padding:12px 18px 6px}
.tools-body{flex:1;overflow-y:auto;padding:8px 10px;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.tool-btn{display:flex;align-items:center;gap:12px;width:100%;padding:11px 14px;margin:3px 0;border-radius:6px;border:none;background:#1e2025;color:#bfc0c2;font-family:var(--font-ui);font-size:13px;font-weight:400;cursor:pointer;text-align:left;text-decoration:none;transition:background var(--transition),color var(--transition),transform var(--transition);letter-spacing:.01em}
.tool-btn:hover{background:#26282d;color:#e8e9eb;transform:translateX(4px)}
.tool-btn i{width:16px;text-align:center;font-size:13px;color:var(--text-muted);flex-shrink:0}
.tool-btn:hover i{color:var(--accent-steel)}
.tool-btn.ativo{background:#2a2c31;color:#fff}
.tool-btn.em-breve{opacity:.45;cursor:not-allowed}
.tool-btn.em-breve:hover{transform:none;background:#1e2025;color:#bfc0c2}
@media(max-width:640px){.tools-panel{width:82vw;right:-84vw}}

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVIDADE — regras comuns a todas as telas do LifeTech
   ───────────────────────────────────────────────────────────────────────────
   Este bloco é emitido DEPOIS do <style> de cada página, então vence no
   empate de especificidade. Fica aqui, e não copiado em 12 arquivos, pelo
   mesmo motivo do menu: o que é duplicado diverge.
   ═══════════════════════════════════════════════════════════════════════════ */

/* Toda tabela larga rola na horizontal em vez de esticar a página inteira */
.tbl-wrap, .table-wrap, .tabela-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

@media (max-width: 900px) {
  /* Grades de duas colunas viram uma só — duas colunas de 160px não cabem */
  .grid2, .grid-2, .form-grid, .grid-main, .form-row {
    grid-template-columns: 1fr !important;
  }
  .kpi-grid, .resumo, .stats-row {
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)) !important;
  }
}

@media (max-width: 640px) {

  /* ── Menu lateral em celular ──────────────────────────────────────────
     Garantido aqui porque três páginas tinham a regra pela metade: a
     abertura de chamado não mostrava o botão do menu, e movimentar mostrava
     o botão mas não tinha o estado .open — o menu simplesmente não abria. */
  .menu-toggle { display: block !important; }
  #sidebar {
    position: fixed !important;
    width: var(--sidebar-w, 260px) !important;
    transform: translateX(-100%);
    transition: transform .22s cubic-bezier(.4,0,.2,1);
    z-index: 1100;
  }
  #sidebar.open { transform: translateX(0) !important; }
  #sidebar .sidebar-toggle { display: none !important; }
  /* Em celular o menu é gaveta, não coluna recolhida: os rótulos voltam */
  #sidebar.collapsed .nav-label { opacity: 1 !important; }
  #sidebar.collapsed .nav-item {
    padding: 11px 14px !important; text-align: left !important;
    font-size: 14px !important; color: #bfc0c2 !important;
  }
  #sidebar.collapsed .nav-item::before,
  #sidebar.collapsed .nav-item::after { content: none !important; }
  #main, .footer { margin-left: 0 !important; }

  /* ── Estrutura ── */
  .content      { padding: 14px 12px !important; }
  .topbar       { padding-left: 56px !important; padding-right: 12px !important; }
  .footer       { flex-direction: column; align-items: flex-start; gap: 4px; }

  /* Migalha de pão: só o último nível cabe na tela */
  .topbar-breadcrumb > span:not(:last-child),
  .topbar-breadcrumb > i { display: none; }
  .topbar-breadcrumb > span:last-child { font-size: 13px; }

  /* ── Cabeçalho de página ── */
  .page-header  { flex-direction: column; align-items: stretch; gap: 10px; }
  .page-title   { font-size: 18px !important; }
  .page-header .btn { width: 100%; justify-content: center; }

  /* ── Botões e formulários ── */
  .btn          { padding: 11px 14px; font-size: 13px; }
  .form-actions, .acoes-form { flex-direction: column; }
  .form-actions .btn, .acoes-form .btn { width: 100%; justify-content: center; }

  /* Fonte 16px evita o zoom automático do iOS ao focar um campo */
  .fi, .form-control, input, select, textarea { font-size: 16px !important; }

  /* ── Tudo que é grade vira coluna única ── */
  .kpi-grid, .resumo, .stats-row, .cards-grid, .grid-cards {
    grid-template-columns: 1fr !important;
  }

  /* ── Abas roláveis em vez de quebradas ── */
  .abas, .tabs {
    flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
  }
  .abas::-webkit-scrollbar, .tabs::-webkit-scrollbar { display: none; }
  .aba, .tab { flex-shrink: 0; }

  /* ── Modais ── */
  .modal, .modal-box, .modal-content {
    max-width: 100% !important; max-height: 92vh; border-radius: 14px;
  }
  .modal-bg, .modal-overlay { padding: 10px !important; }
  .modal-f, .modal-footer { flex-direction: column-reverse; }
  .modal-f .btn, .modal-footer .btn { width: 100%; justify-content: center; }

  /* ── Listas com data + corpo + ações (preventivas, OS, chamados) ── */
  .pv, .pv-item, .os-item, .lista-item { flex-wrap: wrap; }
  .pv-acoes, .item-acoes { width: 100%; }
  .pv-acoes .btn, .item-acoes .btn { flex: 1; justify-content: center; }

  /* ── Painel de ferramentas ocupa a tela ── */
  .tools-panel { width: 88vw; right: -90vw; }

  /* Tabelas: fonte menor para caber mais coluna antes de precisar rolar */
  .tbl, table { font-size: 12px; }
  .tbl th, .tbl td, table th, table td { padding: 8px 10px; }
}

@media (max-width: 420px) {
  .content { padding: 12px 10px !important; }
  .rz-v, .kpi-val, .kpi .k { font-size: 20px !important; }
  .topbar-logo-rede { display: none !important; }
}
</style>
<?php }

/* ═══════════════════════════════════════════════════════════════════════════
   2) SIDEBAR — logo após <body>
   ═══════════════════════════════════════════════════════════════════════════ */
function eng_clin_menu_sidebar(): void {
    $atual = eng_clin_menu_atual();
?>
<button class="menu-toggle" id="menuToggle" aria-label="Abrir menu">&#9776;</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="fecharSidebar()"></div>

<nav id="sidebar">
  <button class="sidebar-toggle" id="toggleBtn" title="Recolher menu">
    <i class="fas fa-chevron-left" id="toggleIcon"></i>
  </button>
  <div class="sidebar-brand">
    <img src="lifetechoriginalclaro.png" alt="LifeTech Engenharia Clínica" class="brand-logo-main">
  </div>
  <div class="sidebar-nav">
    <?php foreach (eng_clin_menu_itens() as $chave => $it): ?>
      <?php
        // A página inicial não exibe o botão "Página Inicial" — o usuário já está nela
        if ($chave === 'inicial' && $atual === 'inicial') continue;
        $cls = 'nav-item' . ($chave === $atual ? ' active' : '');
      ?>
    <a href="<?= $it[1] ?>" class="<?= $cls ?>" data-tooltip="<?= $it[0] ?>"><span class="nav-label"><?= $it[0] ?></span></a>
    <?php endforeach; ?>
    <a href="logout.php" class="nav-item nav-item-sair" data-tooltip="Sair"><span class="nav-label">Sair</span></a>
  </div>
</nav>
<?php }

/* ═══════════════════════════════════════════════════════════════════════════
   3) BOTÕES DA TOPBAR — engrenagem (e sino opcional)
      $notif = null → sem sino | int → sino com contador
   ═══════════════════════════════════════════════════════════════════════════ */
function eng_clin_menu_botoes(?int $notif = null): void { ?>
  <?php if ($notif !== null): ?>
  <button class="topbar-btn" title="Notificações">
    <i class="fas fa-bell"></i>
    <?php if ($notif > 0): ?><span class="notif-dot"></span><?php endif; ?>
  </button>
  <?php endif; ?>
  <button class="topbar-btn" id="btnOpcoes" title="Opções" onclick="toggleTools()">
    <i class="fas fa-gear"></i>
  </button>
<?php }

/* ═══════════════════════════════════════════════════════════════════════════
   4) PAINEL DE FERRAMENTAS — antes do </body>
      $com_script = true → emite também o <script> com o JS
   ═══════════════════════════════════════════════════════════════════════════ */
function eng_clin_menu_painel(bool $com_script = false): void {
    $arq_atual = basename($_SERVER['PHP_SELF'] ?? '');
    $ferramentas = [
        'Cadastros' => [
            ['eng_clin_cadastrodetecnico.php',      'fa-user-gear',     'Cadastro de Técnicos'],
            ['eng_clin_cadastrodefornecedores.php', 'fa-truck',         'Cadastro de Fornecedores'],
            ['eng_clin_cadastro_criticidade.php',   'fa-list-check',    'Criticidade de Itens'],
            ['eng_clin_cadastro_pecas.php',         'fa-wrench',        'Cadastro de Peças'],
            ['eng_clin_retiradadepecas.php',        'fa-screwdriver-wrench', 'Retirada de Peças'],
            ['eng_clin_documentos.php',             'fa-folder-open',   'Documentos'],
        ],
    ];
?>
<div id="toolsOverlay" onclick="fecharTools()" style="display:none;position:fixed;inset:0;z-index:199;background:rgba(0,0,0,.3)"></div>
<div class="tools-panel" id="toolsPanel">
  <div class="tools-header">
    <span class="tools-title"><i class="fas fa-gear" style="margin-right:7px;font-size:11px"></i>Opções</span>
    <button class="tools-close" onclick="fecharTools()">&times;</button>
  </div>
  <div class="tools-body">
    <?php foreach ($ferramentas as $secao => $links): ?>
    <div class="tools-section-label"><?= htmlspecialchars($secao) ?></div>
      <?php foreach ($links as $l): ?>
    <a href="<?= $l[0] ?>" class="tool-btn<?= $l[0] === $arq_atual ? ' ativo' : '' ?>"><i class="fas <?= $l[1] ?>"></i> <?= htmlspecialchars($l[2]) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <div class="tools-section-label">Administração</div>
    <a href="#" class="tool-btn em-breve" onclick="return false"><i class="fas fa-users-cog"></i> Controle de Usuários</a>
    <a href="#" class="tool-btn em-breve" onclick="return false"><i class="fas fa-sliders"></i> Configurações</a>
  </div>
</div>
<?php if ($com_script): ?>
<script><?php eng_clin_menu_js(); ?></script>
<?php endif;
}

/* ═══════════════════════════════════════════════════════════════════════════
   5) JS — sidebar recolher/abrir + painel de ferramentas
      Tolerante a elementos ausentes e não redeclara se a página já tiver.
   ═══════════════════════════════════════════════════════════════════════════ */
function eng_clin_menu_js(): void { ?>
/* ── eng_clin_menu.php — caixa de ferramentas ──
   NÃO mexe no recolher/abrir da sidebar: cada página já tem esse JS e
   duplicar o listener faria o menu abrir e fechar no mesmo clique. */
(function(){
  const sidebar = document.getElementById('sidebar');

  // Fallback só se a página não definiu (o overlay/onclick da sidebar precisa)
  if (typeof window.fecharSidebar !== 'function') {
    window.fecharSidebar = function(){
      if (!sidebar) return;
      sidebar.classList.remove('open');
      const ov = document.getElementById('sidebarOverlay');
      if (ov) ov.classList.remove('open');
    };
  }

  /* Abertura do menu em celular.
     Só liga o listener se a página não ligou o seu — daí a marca
     dataset.ligado. Sem isso, nas páginas que já têm o código o menu
     abriria e fecharia no mesmo toque. */
  const btnMenu = document.getElementById('menuToggle');
  if (btnMenu && !btnMenu.dataset.ligado) {
    btnMenu.dataset.ligado = '1';
    btnMenu.addEventListener('click', function(){
      if (!sidebar) return;
      sidebar.classList.add('open');
      const ov = document.getElementById('sidebarOverlay');
      if (ov) ov.classList.add('open');
    });
  }

  /* Ao tocar num item do menu em celular, fecha a gaveta */
  if (sidebar) {
    sidebar.querySelectorAll('.nav-item').forEach(function(a){
      a.addEventListener('click', function(){
        if (window.innerWidth <= 640) window.fecharSidebar();
      });
    });
  }

  /* ── Caixa de ferramentas ── */
  window.toggleTools = function(){
    const panel = document.getElementById('toolsPanel');
    if (!panel) return;
    const ov  = document.getElementById('toolsOverlay');
    const btn = document.getElementById('btnOpcoes');
    const abr = panel.classList.toggle('open');
    if (ov)  ov.style.display = abr ? 'block' : 'none';
    if (btn) btn.classList.toggle('ativo', abr);
  };
  window.fecharTools = function(){
    const panel = document.getElementById('toolsPanel');
    if (panel) panel.classList.remove('open');
    const ov  = document.getElementById('toolsOverlay');
    if (ov) ov.style.display = 'none';
    const btn = document.getElementById('btnOpcoes');
    if (btn) btn.classList.remove('ativo');
  };
  document.addEventListener('keydown', e => { if (e.key === 'Escape') window.fecharTools(); });
})();
<?php }

/* ═══════════════════════════════════════════════════════════════════════════
   Helper: data em português (date('F') ignora locale e sai em inglês)
   ═══════════════════════════════════════════════════════════════════════════ */
function eng_clin_data_pt(?int $ts = null): string {
    $ts = $ts ?? time();
    $meses = [1=>'janeiro','fevereiro','março','abril','maio','junho',
              'julho','agosto','setembro','outubro','novembro','dezembro'];
    return date('d', $ts) . ' de ' . $meses[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
}

} // ENG_CLIN_MENU_CARREGADO
