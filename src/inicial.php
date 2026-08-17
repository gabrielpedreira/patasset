<?php
session_start();
$mostrarLoading = false;
include 'conexao.php';

if (!isset($_SESSION['usuario_logado'])) { header("Location: index.html"); exit(); }

// ── Verificação de sessão revogada + atualização de ultimo_acesso ─────────────
try {
    $_cs_sid  = session_id();
    $_cs_stmt = $conn->prepare("SELECT revogada FROM usuarios_online WHERE session_id = ? LIMIT 1");
    if ($_cs_stmt) {
        $_cs_stmt->bind_param('s', $_cs_sid);
        $_cs_stmt->execute();
        $_cs_result = $_cs_stmt->get_result();
        $_cs_row    = $_cs_result ? $_cs_result->fetch_assoc() : null;
        $_cs_stmt->close();
        // Só redireciona se explicitamente revogada = 1
        if ($_cs_row && (int)$_cs_row['revogada'] === 1) {
            session_unset();
            session_destroy();
            header('Location: index.html?error=Sua+sessao+foi+encerrada');
            exit;
        }
        // Atualiza ultimo_acesso
        $_cs_upd = $conn->prepare("UPDATE usuarios_online SET ultimo_acesso = NOW() WHERE session_id = ?");
        if ($_cs_upd) { $_cs_upd->bind_param('s', $_cs_sid); $_cs_upd->execute(); $_cs_upd->close(); }
    }
    unset($_cs_sid, $_cs_stmt, $_cs_result, $_cs_row, $_cs_upd);
} catch (Exception $e) {
    // Não quebra a página se a tabela usuarios_online tiver algum problema
}
// ─────────────────────────────────────────────────────────────────────────────

$usuario = $_SESSION['usuario_logado'];

$stmt = $conn->prepare("SELECT permicao, classe_usuario, status FROM usuarios WHERE usuario=?");
$stmt->bind_param("s", $usuario);
$stmt->execute();
$res = $stmt->get_result();
$nivel = "C";
$classe_usuario = '';
$status         = 'ATIVO';
if ($r = $res->fetch_assoc()) {
    $nivel          = $r['permicao'];
    $classe_usuario = $r['classe_usuario'];
    $status         = $r['status'] ?? 'ATIVO';
}
$stmt->close();

// Usuário bloqueado pelo DEV — encerra sessão e redireciona ao login
if ($status !== 'ATIVO') {
    session_destroy();
    header("Location: index.html?erro=bloqueado");
    exit();
}

// Bloqueia acesso total: apenas DEV ou PATRIMONIO podem abrir a página
if (!in_array($classe_usuario, ['DEV', 'PATRIMONIO'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}

$verAtividades = ($nivel !== 'C');


$cronograma=[];
$res=$conn->query("SELECT * FROM cronograma ORDER BY STR_TO_DATE(inicio, '%Y-%m-%d') ASC");
while($row=$res->fetch_assoc()) $cronograma[]=$row;


date_default_timezone_set('America/Sao_Paulo');
$data=date('d/m/Y'); $hora=date('H:i:s');

$rConc=$conn->query("SELECT COUNT(*) AS total FROM cadastro WHERE conciliado='NAO'");
$totalNaoConciliado=0;
if($rConc&&$rowConc=$rConc->fetch_assoc()) $totalNaoConciliado=(int)$rowConc['total'];
$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Página Inicial</title>
<link rel="icon" type="image/png" href="/logo_1.png">
<style>
*{box-sizing:border-box;margin:0;padding:0}

body{font-family:Arial,sans-serif;background:#eaf1fb;min-height:100vh;display:flex;flex-direction:column}

/* ── HAMBURGUER ── */
.menu-toggle{
    display:none;position:fixed;top:10px;left:10px;z-index:1200;
    background:#1e3a8a;color:#fff;border:none;border-radius:8px;
    padding:8px 12px;font-size:20px;cursor:pointer;
    box-shadow:0 4px 12px rgba(0,0,0,.3);line-height:1;
}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000}
.sidebar-overlay.open{display:block}

/* ── SIDEBAR ── */
.container-principal{flex:1;display:flex}
.sidebar{
    width:220px;background:#1e3a8a;color:#fff;
    padding:20px;display:flex;flex-direction:column;align-items:center;
    flex-shrink:0;transition:transform .25s;
}
.logo{width:120px;margin-bottom:10px}
.logo img{width:100%}
.sidebar button{
    width:100%;margin:5px 0;padding:12px;border:none;
    border-radius:6px;background:#2563eb;color:#fff;cursor:pointer;
    transition:transform .3s ease;font-size:14px;text-align:left;
}
.sidebar button:hover{background:#1d4ed8;transform:translateX(8px)}
.sidebar .btn-logout{background:#022472}
.sidebar .btn-logout:hover{background:#000b3d}

/* ── MAIN ── */
.main{flex:1;padding:20px;position:relative;min-width:0}
.main::before{
    content:"";position:absolute;inset:0;
    background:url('logo_rede_triangulo.png') center 60%/50% no-repeat;
    opacity:.86;pointer-events:none;z-index:0;
}
.main>*{position:relative;z-index:1}

/* ── HEADER TOPO ── */
.header-topo{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px;flex-wrap:wrap}
.boas-vindas h2{margin:0;font-size:1.2rem}
.boas-vindas p{margin:4px 0 0;color:#334155;font-size:13px}
.logo-topo img{max-height:56px;width:auto}

/* ── BLOCO TAREFAS ── */
.area-central{display:flex;gap:24px;align-items:flex-start}
.bloco-tarefas{
    display:flex;flex-direction:column;width:95%;
    background:rgba(248,250,252,.82);border-radius:12px;padding:15px;
}
.bloco-tarefas::before{display:none!important}

/* ── FORM GRID ── */
.form-user-inline{
    display:grid;grid-template-columns:repeat(4,1fr);
    column-gap:18px;row-gap:16px;margin-bottom:16px;
}
.form-user-inline .campo,.form-user-inline .pesquisa{display:flex;flex-direction:column}
.form-user-inline label{font-size:13px;font-weight:600;margin-bottom:3px}
.form-user-inline input,.form-user-inline select{
    padding:9px;border-radius:8px;border:1px solid #cbd5e1;font-size:13px;width:100%;
}

/* ── BOTOES USER ── */
.botoes-user{
    grid-column:1/-1;display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;
}
.botoes-user .pesquisa{display:flex;flex-direction:column;flex:1;min-width:160px}
.botoes-user .pesquisa input{width:100%}
.botoes-user .btn{
    padding:10px 14px;border:none;border-radius:6px;background:#2563eb;
    color:#fff;cursor:pointer;font-size:13px;transition:transform .3s ease;white-space:nowrap;
}
.botoes-user .btn:hover{background:#1d4ed8;transform:scale(1.08)}

/* ── TABELA ── */
.tabela-tarefas{margin-top:12px;overflow-x:auto;max-height:320px;border:1px solid #cbd5e1;border-radius:8px;-webkit-overflow-scrolling:touch}
.tabela-tarefas table{border-collapse:collapse;width:100%;min-width:800px;font-size:13px}
.tabela-tarefas th,.tabela-tarefas td{border:1px solid #ddd;padding:8px;text-align:center}
.tabela-tarefas th{background:#3b82f6;color:#fff;position:sticky;top:0}
tr.selecionado{background:#dbeafe!important}
tr.prazo-vencido{background:#fca5a5!important}
tr.prazo-alerta{background:#fef08a!important}
tr.prazo-vencido.selecionado,tr.prazo-alerta.selecionado{filter:brightness(.92)}

/* ── BADGE CONCILIAÇÃO ── */
.btn-wrapper{position:relative;width:100%}
.btn-wrapper button{width:100%}
.badge-conc{
    position:absolute;top:-6px;right:-6px;background:#ef4444;color:#fff;
    font-size:11px;font-weight:700;min-width:20px;height:20px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;padding:0 4px;
    box-shadow:0 0 0 2px #1e3a8a;pointer-events:none;
    animation:pulseBadge 1.4s ease-in-out infinite;
}
@keyframes pulseBadge{
    0%{transform:scale(1);box-shadow:0 0 0 2px #1e3a8a,0 0 0 0 rgba(239,68,68,.6)}
    50%{transform:scale(1.15);box-shadow:0 0 0 2px #1e3a8a,0 0 0 6px rgba(239,68,68,0)}
    100%{transform:scale(1);box-shadow:0 0 0 2px #1e3a8a,0 0 0 0 rgba(239,68,68,.6)}
}


.btn-gmail{display:block;width:48px;transition:transform .2s ease,opacity .2s ease;cursor:pointer}
.btn-gmail:hover{transform:scale(1.15);opacity:.85}

/* ── BLOCO INVENTÁRIO ── */
.bloco-inventario{
    background:rgba(248,250,252,.88);border-radius:12px;padding:16px;margin-bottom:20px;
}
.bloco-inventario h2{font-size:1rem;margin-bottom:12px}

.inv-blocos{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
    align-items:stretch;
}
.inv-bloco{
    display:flex;flex-direction:column;gap:8px;
    background:#f1f5f9;border:1px solid #e2e8f0;border-radius:10px;
    padding:12px;
}
.inv-bloco-titulo{
    font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;
    letter-spacing:.04em;margin-bottom:2px;
}
.inv-bloco .campo{display:flex;flex-direction:column;gap:3px}
.inv-bloco label{font-size:13px;font-weight:600}
.inv-bloco select,
.inv-bloco input[type="text"]{
    padding:9px;border-radius:8px;border:1px solid #cbd5e1;font-size:13px;width:100%;
    background:#fff;
}
.inv-bloco .btn-inv{
    padding:9px 18px;border:none;border-radius:6px;background:#2563eb;
    color:#fff;cursor:pointer;font-size:13px;white-space:nowrap;
    transition:transform .3s ease;margin-top:auto;
}
.inv-bloco .btn-inv:hover{background:#1d4ed8;transform:scale(1.06)}

.inv-resultado{margin-top:14px;overflow-x:auto}
.inv-resultado table{border-collapse:collapse;width:100%;font-size:13px;min-width:300px}
.inv-resultado th,
.inv-resultado td{border:1px solid #ddd;padding:8px 12px;text-align:left}
.inv-resultado th{background:#3b82f6;color:#fff}
.inv-resultado tbody tr:nth-child(even){background:#f0f7ff}
.inv-aviso{
    margin-top:12px;padding:10px 14px;border-radius:8px;
    background:#fef9c3;border:1px solid #fbbf24;color:#92400e;font-size:13px;
}

/* ── AÇÕES DO COMENTÁRIO ── */
.fala-acoes{
    display:flex;gap:6px;justify-content:flex-end;margin-top:6px;
}
.fala-acoes button{
    font-size:11px;padding:2px 8px;border:none;border-radius:6px;cursor:pointer;
    transition:opacity .2s;
}
.btn-editar-fala{background:#e0edff;color:#1e40af;}
.btn-editar-fala:hover{opacity:.75}
.btn-excluir-fala{background:#fee2e2;color:#b91c1c;}
.btn-excluir-fala:hover{opacity:.75}
.fala-edit-area{width:100%;margin-top:6px;padding:6px 8px;border-radius:8px;border:1px solid #93c5fd;font-size:13px;resize:none;font-family:inherit}
.btn-salvar-edicao{font-size:11px;padding:2px 8px;border:none;border-radius:6px;cursor:pointer;background:#bbf7d0;color:#166534;margin-top:4px}
.btn-salvar-edicao:hover{opacity:.75}

/* ── FOOTER ── */
.footer{background:#011636;color:#fff;padding:12px 20px;display:flex;justify-content:space-between;flex-wrap:wrap;font-size:13px;gap:6px}

/* ════════════════════════════════
   TABLET  (≤ 900px)
════════════════════════════════ */
@media(max-width:900px){
    .form-user-inline{grid-template-columns:repeat(2,1fr)}
    .main::before{background-size:70%}
}

/* ════════════════════════════════
   MOBILE  (≤ 640px)
════════════════════════════════ */
@media(max-width:640px){
    .sidebar{
        position:fixed;top:0;left:0;height:100vh;z-index:1100;
        transform:translateX(-100%);overflow-y:auto;
    }
    .sidebar.open{transform:translateX(0)}
    .menu-toggle{display:block}

    .main{padding:14px 10px;padding-top:54px}
    .main::before{background-size:95%;background-position:center 70%}

    .header-topo{flex-direction:row;align-items:center}
    .boas-vindas h2{font-size:1rem}
    .logo-topo img{max-height:44px}

    .form-user-inline{grid-template-columns:1fr;gap:10px}

    .botoes-user{flex-direction:column;gap:8px}
    .botoes-user .pesquisa{width:100%}
    .botoes-user .btn{width:100%;padding:11px}



    .tabela-tarefas{max-height:260px}

    .inv-blocos{grid-template-columns:1fr}
    .inv-bloco .btn-inv{width:100%}
}

@media print{
    body{background:#fff!important}
    .sidebar,.menu-toggle,.sidebar-overlay,.footer{display:none!important}
    .main{padding:0}
    .main::before{display:none}
}
</style>
</head>
<body>

<!-- HAMBURGUER -->
<button class="menu-toggle" id="menuToggle" aria-label="Menu">☰</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="fecharSidebar()"></div>

<div class="container-principal">

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <div class="logo"><img src="logo_2.png" alt="Logo"></div>

    <?php if($nivel==="A"||$nivel==="B"): ?>
    <button onclick="ir('rotina.php')">Rotina</button>
    <button onclick="ir('nao_localizados.php')">Auditoria de Não Localizados</button>
    <button onclick="ir('cadastro.php')">Cadastro</button>
    <button onclick="ir('movimentar.php')">Movimentar</button>
    <button onclick="ir('historico.php')">Histórico de Movimentação</button>
    <button onclick="ir('cadastro_destinatario.php')">Cadastrar Responsáveis</button>
    <button onclick="ir('contabil.php')">Cadastro de Classificação</button>
    <button onclick="ir('baixa.php')">Baixa</button>
    <button onclick="ir('baixa_historico.php?origem=inicial')">Histórico de Baixa</button>
   
    <div class="btn-wrapper">
         <button onclick="ir('documentoss.php')">Documentos e Conciliação</button>
        <?php if($totalNaoConciliado>0): ?>
        <span class="badge-conc"><?=$totalNaoConciliado?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if(in_array($nivel,["A","B","C"])): ?>
    <button onclick="ir('planilha.php')">Planilha de Controle</button>
    <?php endif; ?>

    <?php if($nivel==="A"): ?>
    
    <button onclick="ir('registro_atividades.php')">Registro de Atividades</button>
    <!-- Gestão de usuários migrou para o Painel do Desenvolvedor (dev_painel.php).
         Manter duas telas de cadastro significava duas regras de validação
         diferentes para a mesma tabela. -->
    <button onclick="ir('relatorio.php')">Relatórios</button>
    <button onclick="ir('dash.php')">Dashboard</button>
    <?php endif; ?>

    <button class="btn-logout" onclick="ir('logout.php')">Sair</button>
</div>

<!-- MAIN -->
<div class="main">

    <div class="header-topo">
        <div class="boas-vindas">
            <h2>Bem-vindo, <?=htmlspecialchars($usuario)?>!</h2>
            <p>Use o menu lateral para navegar no sistema.</p>
        </div>
        <div class="logo-topo"><img src="logo_rede.png" alt="Logo Rede Hospitalar"></div>
    </div>

    <div class="area-central">
    <div style="display:flex;flex-direction:column;width:95%;gap:0">

        <!-- ── BLOCO INVENTÁRIO ── -->
        <div class="bloco-inventario">
            <h2>Consulta de Inventário</h2>
            <div class="inv-blocos">

                <div class="inv-bloco">
                    <div class="inv-bloco-titulo">Por unidade e descrição</div>
                    <div class="campo">
                        <label for="invUnidade">Unidade</label>
                        <select id="invUnidade">
                            <option value="">Carregando...</option>
                        </select>
                    </div>
                    <div class="campo">
                        <label for="invDescricao">Descrição do item</label>
                        <input type="text" id="invDescricao" placeholder="Ex: SUPORTE DE SORO">
                    </div>
                    <button class="btn-inv" onclick="buscarPorDescricao()">Consultar</button>
                </div>

                <div class="inv-bloco">
                    <div class="inv-bloco-titulo">Por tag do patrimônio</div>
                    <div class="campo">
                        <label for="invTag">Tag (antiga ou trocada)</label>
                        <input type="text" id="invTag" placeholder="Ex: 000123">
                    </div>
                    <button class="btn-inv" onclick="buscarPorTag()">Buscar</button>
                </div>

            </div>
            <div id="invResultado" class="inv-resultado"></div>
        </div>

        <!-- ── ATIVIDADES DA EQUIPE ── -->
        <?php if($verAtividades): ?>
        <div class="bloco-tarefas">

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
                <h2 style="margin:0;font-size:1rem">Atividades da Equipe</h2>
                <a href="https://accounts.google.com/AccountChooser?Email=sistema@seudominio.com.br&continue=https://mail.google.com/mail/u/0/"
                   target="_blank" title="Abrir Gmail">
                    <img src="gmail.png" alt="Gmail" class="btn-gmail">
                </a>
            </div>

            <form id="formNovoUsuario" onsubmit="return false;" class="form-user-inline">
                <div class="campo"><label>Tarefa</label><input type="text" id="tarefa"></div>
                <div class="campo"><label>Unidade</label><input type="text" id="unidade"></div>
                <div class="campo">
                    <label>Prioridade</label>
                    <select id="prioridade">
                        <option value="BAIXA">BAIXA</option>
                        <option value="MÉDIA">MÉDIA</option>
                        <option value="ALTA">ALTA</option>
                    </select>
                </div>
                <div class="campo"><label>Responsável</label><input type="text" id="responsavel"></div>
                <div class="campo"><label>Início</label><input id="inicio" maxlength="10" placeholder="DD/MM/AAAA"></div>
                <div class="campo"><label>Prazo</label><input type="text" id="dia" maxlength="10" placeholder="DD/MM/AAAA"></div>
                <div class="campo"><label>Status</label><input type="text" id="stts"></div>
                <div class="campo"><label>Observações</label><input type="text" id="observacoes"></div>

                <div class="botoes-user">
                    <div class="pesquisa">
                        <label>Pesquisa</label>
                        <input type="text" id="barrapesquisa" placeholder="Digite aqui sua consulta...">
                    </div>
                    <button type="button" class="btn" onclick="limparCampos()">Limpar</button>
                    <button type="button" class="btn" onclick="excluir()">Excluir</button>
                    <button type="button" class="btn" onclick="concluido()">Concluir</button>
                    <button type="button" class="btn" onclick="salvarUsuario()">Salvar</button>
                </div>
            </form>

            <div class="tabela-tarefas">
                <table>
                    <thead>
                        <tr>
                            <th>Tarefa</th><th>Unidade</th><th>Prioridade</th><th>Responsável</th>
                            <th>Início</th><th>Prazo</th><th>Status</th><th>Obs.</th>
                        </tr>
                    </thead>
                    <tbody id="listaTarefas">
                        <?php foreach($cronograma as $c): ?>
                        <tr data-id="<?=$c['id']?>">
                            <td><?=htmlspecialchars($c['tarefa'])?></td>
                            <td><?=htmlspecialchars($c['unidade'])?></td>
                            <td><?=htmlspecialchars($c['prioridade'])?></td>
                            <td><?=htmlspecialchars($c['responsavel'])?></td>
                            <td><?=date('d/m/Y',strtotime($c['inicio']))?></td>
                            <td><?=htmlspecialchars($c['dia'])?></td>
                            <td><?=htmlspecialchars($c['stts'])?></td>
                            <td><?=htmlspecialchars($c['obs'])?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
    </div>
</div>
</div>


<div class="footer">
    <div>Usuário: <?=$usuario?></div>
    <div>Data: <?=$data?> | Hora: <span id="hora"><?=$hora?></span></div>
    <div>&copy; GK Soluções</div>
</div>

<script>
/* ── SIDEBAR DRAWER ── */
function ir(url){ fecharSidebar(); location.href=url; }
document.getElementById('menuToggle').onclick=()=>{
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
};
function fecharSidebar(){
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
}

/* ── CLOCK ── */
setInterval(()=>{ document.getElementById('hora').innerText=new Date().toLocaleTimeString(); },1000);

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
    .catch(() => {}); // falha silenciosa — tenta de novo no próximo ciclo
    setTimeout(hb, 15000); // verifica a cada 15 segundos
})();

/* ── INVENTÁRIO ── */
(function(){
    const sel = document.getElementById('invUnidade');

    fetch('buscar_inventario.php?acao=unidades')
        .then(r => r.json())
        .then(lista => {
            sel.innerHTML = '<option value="">Selecione a unidade...</option>';
            lista.forEach(u => {
                const opt = document.createElement('option');
                opt.value = u; opt.textContent = u;
                sel.appendChild(opt);
            });
        })
        .catch(() => { sel.innerHTML = '<option value="">Erro ao carregar</option>'; });

    document.getElementById('invDescricao').addEventListener('keydown', function(e){
        if (e.key === 'Enter') buscarPorDescricao();
    });
    document.getElementById('invTag').addEventListener('keydown', function(e){
        if (e.key === 'Enter') buscarPorTag();
    });
})();

function buscarPorDescricao(){
    const unidade   = document.getElementById('invUnidade').value.trim();
    const descricao = document.getElementById('invDescricao').value.trim();
    const box       = document.getElementById('invResultado');

    if (!unidade) {
        box.innerHTML = '<div class="inv-aviso">Selecione uma unidade antes de consultar.</div>';
        return;
    }
    if (!descricao) {
        box.innerHTML = '<div class="inv-aviso">Digite o nome do item que deseja consultar.</div>';
        return;
    }

    box.innerHTML = '<div style="padding:10px;color:#555;font-size:13px">Consultando...</div>';

    fetch('buscar_inventario.php?acao=buscar&unidade=' + encodeURIComponent(unidade) + '&descricao=' + encodeURIComponent(descricao))
        .then(r => r.json())
        .then(dados => {
            if (dados && dados.erro) { box.innerHTML = '<div class="inv-aviso">Erro: ' + dados.erro + '</div>'; return; }
            if (!Array.isArray(dados) || dados.length === 0) {
                box.innerHTML = '<div class="inv-aviso">Nenhum dado encontrado para essa descrição em <strong>' + unidade + '</strong>. Tente com outro nome.</div>';
                return;
            }
            const total = dados.reduce((s, r) => s + parseInt(r.total), 0);
            let html = '<table><thead><tr><th>Setor</th><th style="text-align:center">Quantidade</th></tr></thead><tbody>';
            dados.forEach(row => {
                html += '<tr><td>' + (row.setor ? row.setor : '<em style="color:#aaa">Sem setor</em>') + '</td>'
                      + '<td style="text-align:center;font-weight:700">' + row.total + '</td></tr>';
            });
            html += '</tbody><tfoot><tr style="background:#dbeafe;font-weight:700">'
                  + '<td>TOTAL</td><td style="text-align:center">' + total + '</td>'
                  + '</tr></tfoot></table>';
            box.innerHTML = html;
        })
        .catch(e => { box.innerHTML = '<div class="inv-aviso">Erro de rede: ' + e.message + '</div>'; });
}

function buscarPorTag(){
    const tag = document.getElementById('invTag').value.trim();
    const box = document.getElementById('invResultado');

    if (!tag) {
        box.innerHTML = '<div class="inv-aviso">Digite a tag do patrimônio para buscar.</div>';
        return;
    }

    box.innerHTML = '<div style="padding:10px;color:#555;font-size:13px">Buscando...</div>';

    fetch('buscar_inventario.php?acao=buscar_tag&tag=' + encodeURIComponent(tag))
        .then(r => r.json())
        .then(dados => {
            if (dados && dados.erro) {
                box.innerHTML = '<div class="inv-aviso">Erro: ' + dados.erro + '</div>';
                return;
            }
            if (!Array.isArray(dados) || dados.length === 0) {
                box.innerHTML = '<div class="inv-aviso">Nenhum registro encontrado para a tag <strong>' + tag + '</strong>.</div>';
                return;
            }
            const vazio = '<em style="color:#aaa">—</em>';
            let html = '<table><thead><tr>'
                     + '<th>Nome do item</th>'
                     + '<th>Unidade</th>'
                     + '<th>Setor</th>'
                     + '<th>Pavimento</th>'
                     + '<th>Localização</th>'
                     + '</tr></thead><tbody>';
            dados.forEach(row => {
                html += '<tr>'
                      + '<td>' + (row.descricao || vazio) + '</td>'
                      + '<td>' + (row.unidade   || vazio) + '</td>'
                      + '<td>' + (row.setor     || vazio) + '</td>'
                      + '<td>' + (row.pavimento || vazio) + '</td>'
                      + '<td>' + (row.area      || vazio) + '</td>'
                      + '</tr>';
            });
            html += '</tbody></table>';
            box.innerHTML = html;
        })
        .catch(e => { box.innerHTML = '<div class="inv-aviso">Erro de rede: ' + e.message + '</div>'; });
}

<?php if($verAtividades): ?>

/* ── PRAZO CORES ── */
function colorirLinhasPrazo(){
    const hoje=new Date(); hoje.setHours(0,0,0,0);
    document.querySelectorAll("#listaTarefas tr").forEach(linha=>{
        const tp=linha.children[5]?.innerText?.trim();
        if(!tp||!/^\d{2}\/\d{2}\/\d{4}$/.test(tp)) return;
        const [d,m,a]=tp.split("/");
        const prazo=new Date(a,m-1,d); prazo.setHours(0,0,0,0);
        const diff=Math.ceil((prazo-hoje)/(86400000));
        linha.classList.remove("prazo-vencido","prazo-alerta");
        if(diff<0) linha.classList.add("prazo-vencido");
        else if(diff<=3) linha.classList.add("prazo-alerta");
    });
}
colorirLinhasPrazo();

let idSelecionado=null;

document.querySelectorAll("#listaTarefas tr").forEach(linha=>{
    linha.addEventListener("click",function(e){
        if(e.detail>1) return;
        document.querySelectorAll("#listaTarefas tr").forEach(tr=>tr.classList.remove("selecionado"));
        this.classList.add("selecionado"); idSelecionado=this.dataset.id;
        let col=this.children;
        tarefa.value=col[0].innerText; unidade.value=col[1].innerText;
        prioridade.value=col[2].innerText; responsavel.value=col[3].innerText;
        inicio.value=col[4].innerText; dia.value=col[5].innerText;
        stts.value=col[6].innerText; observacoes.value=col[7].innerText;
    });
    linha.querySelectorAll("td").forEach(td=>{
        td.addEventListener("dblclick",function(e){
            e.stopPropagation();
            let linha=this.parentElement;
            document.querySelectorAll("#listaTarefas tr").forEach(tr=>tr.classList.remove("selecionado"));
            linha.classList.add("selecionado"); idSelecionado=linha.dataset.id;
            if(this.querySelector("input")) return;
            let coluna=this.cellIndex,valor=this.innerText;
            let input=document.createElement("input");
            input.type="text"; input.value=valor;
            input.style.cssText="width:100%;border:none;outline:none;background:#eff6ff";
            this.innerHTML=""; this.appendChild(input); input.focus();
            function salvarEdicao(){ td.innerText=input.value; atualizarFormulario(coluna,input.value); }
            input.addEventListener("blur",salvarEdicao);
            input.addEventListener("keydown",function(e){
                if(e.key==="Enter"){ e.preventDefault(); this.blur(); }
                if(e.key==="Escape") td.innerText=valor;
            });
        });
    });
});

function atualizarFormulario(coluna,valor){
    [tarefa,unidade,prioridade,responsavel,inicio,dia,stts,observacoes][coluna].value=valor;
}

function limparCampos(){
    document.querySelectorAll(".form-user-inline input").forEach(i=>i.value="");
    prioridade.value="BAIXA"; idSelecionado=null;
    document.querySelectorAll("#listaTarefas tr").forEach(tr=>tr.classList.remove("selecionado"));
}

function salvarUsuario(){
    fetch("cronograma_salvar.php",{method:"POST",headers:{"Content-Type":"application/json"},
        body:JSON.stringify({id:idSelecionado,tarefa:tarefa.value,unidade:unidade.value,
        prioridade:prioridade.value,responsavel:responsavel.value,inicio:inicio.value,
        dia:dia.value,stts:stts.value,obs:observacoes.value})})
    .then(r=>r.json()).then(res=>{ if(res.ok) location.reload(); else alert("Erro ao salvar"); });
}

function excluir(){
    if(!idSelecionado){ alert("Selecione uma linha"); return; }
    if(!confirm("Deseja excluir este registro?")) return;
    fetch("cronograma_excluir.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({id:idSelecionado})})
    .then(r=>r.json()).then(res=>{ if(res.ok) location.reload(); });
}

function concluido(){
    if(!idSelecionado){ alert("Selecione uma linha primeiro."); return; }
    if(!confirm("Deseja concluir esta tarefa?")) return;
    fetch("cronograma_concluir.php",{method:"POST",headers:{"Content-Type":"application/json"},
        body:JSON.stringify({id:idSelecionado,tarefa:tarefa.value,unidade:unidade.value,
        responsavel:responsavel.value,inicio:inicio.value,dia:dia.value,stts:stts.value,obs:observacoes.value})})
    .then(r=>r.json()).then(res=>{ if(res.ok){ alert("Tarefa concluída!"); location.reload(); } else alert(res.erro); });
}

document.getElementById("barrapesquisa").addEventListener("keyup",function(){
    let t=this.value.toLowerCase();
    document.querySelectorAll("#listaTarefas tr").forEach(l=>{ l.style.display=l.innerText.toLowerCase().includes(t)?"":"none"; });
});

["inicio","dia"].forEach(id=>{
    document.getElementById(id).addEventListener("input",function(){
        let v=this.value.replace(/\D/g,"").slice(0,8);
        if(v.length>=5) this.value=v.replace(/(\d{2})(\d{2})(\d+)/,"$1/$2/$3");
        else if(v.length>=3) this.value=v.replace(/(\d{2})(\d+)/,"$1/$2");
        else this.value=v;
    });
});


<?php endif; ?>
</script>
</body>
</html>