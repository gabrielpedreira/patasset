<?php
session_start();
require 'conexao.php';
require_once 'check_session.php';

$usuario_logado = $_SESSION['usuario_logado'] ?? '';
if (!$usuario_logado) {
    header("Location: login.php");
    exit();
}

$stmt = $conn->prepare("SELECT permicao, status FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $usuario_logado);
$stmt->execute();
$res = $stmt->get_result();
$permicao = '';
$status   = 'ATIVO';
if ($row = $res->fetch_assoc()) {
    $permicao = $row['permicao'];
    $status   = $row['status'] ?? 'ATIVO';
}
$stmt->close();

// Usuário bloqueado pelo DEV — encerra sessão e redireciona ao login
if ($status !== 'ATIVO') {
    session_destroy();
    header("Location: index.html?erro=bloqueado");
    exit();
}

if (!in_array($permicao, ['A','B'])) {
    header("Location: acesso_bloqueado.html");
    exit();
}
// ─── RECEBE DADOS DA SESSÃO (gravados pelo executar_descarte_action) ──────────
$itens        = $_SESSION['relatorio_itens']        ?? [];
$protocolo    = $_SESSION['relatorio_protocolo']    ?? '';
$assistente   = $_SESSION['relatorio_assistente']   ?? '';
$acompanhante = $_SESSION['relatorio_acompanhante'] ?? '';
$unidade      = $_SESSION['relatorio_unidade']      ?? '';
$data_impressao = date('d/m/Y');

if (empty($itens)) {
    echo "<p style='font-family:Arial;padding:20px'>Nenhum dado para exibir. <a href='baixa_definitiva.php'>Voltar</a></p>";
    exit();
}

// Agrupa em páginas de 9 itens (3 colunas × 3 linhas = A4 compacto)
$paginas = array_chunk($itens, 9);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Relatório de Descarte — Protocolo <?= htmlspecialchars($protocolo) ?></title>
<style>
/* ── RESET ── */
*{ box-sizing:border-box; margin:0; padding:0; }

body{
    font-family: Arial, sans-serif;
    background: linear-gradient(135deg, #001435, #60a5fa);
    padding: 20px;
}

/* ── BOTÃO IMPRIMIR (some na impressão) ── */
.btn-imprimir{
    display: block;
    margin: 0 auto 24px;
    padding: 12px 40px;
    background: #3b82f6;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 16px;
    cursor: pointer;
}
.btn-imprimir:hover{ background:#2563eb; }

.btn-voltar{
    display: block;
    margin: 0 auto 24px;
    padding: 12px 40px;
    background: #3b82f6;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 16px;
    cursor: pointer;
}
.btn-voltar:hover{ background:#2563eb; }

.topo-btns{
    display: flex;
    justify-content: center;
    gap: 16px;
    margin-bottom: 24px;
}

/* ── PÁGINA A4 ── */
.pagina{
    width: 210mm;
    min-height: 297mm;
    background: #fff;
    margin: 0 auto 20px;
    padding: 14mm 12mm 14mm;
    box-shadow: 0 4px 16px rgba(0,0,0,.18);
    page-break-after: always;
    display: flex;
    flex-direction: column;
}

.pagina:last-child{
    page-break-after: avoid;
}

/* ── CABEÇALHO ── */
.cabecalho{
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10mm;
    border-bottom: 2px solid #111;
    padding-bottom: 6mm;
}

.cab-titulo{
    flex: 1;
    text-align: center;
}

.cab-titulo h1{
    font-size: 18pt;
    color: #111;
    margin-bottom: 2mm;
}

.cab-logo img{
    height: 50px;
    width: auto;
}

/* ── GRID DE CAMPOS ── */
.campos-grid{
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 4mm 6mm;
    margin-bottom: 6mm;
}

.campo-linha-2{
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4mm 6mm;
    margin-bottom: 6mm;
}

.campo-item{
    border-bottom: 1px solid #aaa;
    padding-bottom: 2mm;
}

.campo-item label{
    font-size: 7.5pt;
    color: #555;
    display: block;
    margin-bottom: 1mm;
}

.campo-item span{
    font-size: 9.5pt;
    font-weight: 700;
    color: #111;
}

/* ── PROTOCOLO DESTAQUE ── */
.protocolo-destaque{
    background: #1e3a8a;
    color: #fff;
    text-align: center;
    padding: 5mm;
    border-radius: 6px;
    font-size: 16pt;
    font-weight: 700;
    margin-bottom: 6mm;
    letter-spacing: 2px;
}

/* ── GRID DE CARDS ── */
.cards-area{
    flex: 1;
    border: 1px solid #ccc;
    border-radius: 8px;
    padding: 3mm;
    background: #f3f4f6;
}

.cards-grid{
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 3mm;
}

/* ── CARD INDIVIDUAL ── */
.item-card{
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
    border: 1px solid #d1d5db;
    box-shadow: 0 2px 6px rgba(0,0,0,.08);
}

.card-foto{
    width: 100%;
    aspect-ratio: 3/3.2;
    object-fit: cover;
    display: block;
    background: #d1d5db;
}

.card-foto-placeholder{
    width: 100%;
    aspect-ratio: 3/3.2;
    background: #d1d5db;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    font-size: 7pt;
}

.card-descricao{
    background: #374151;
    color: #fff;
    text-align: center;
    padding: 1mm 1.5mm;
    font-size: 6.5pt;
    font-weight: 700;
}

.card-row{ display: flex; }

.card-cell{
    flex: 1;
    padding: 1mm 1.5mm;
    font-size: 6pt;
    font-weight: 600;
    text-align: center;
    border-top: 1px solid #e5e7eb;
    word-break: break-word;
    line-height: 1.2;
}

.card-cell:nth-child(odd) { background:#111827; color:#fff; }
.card-cell:nth-child(even){ background:#6b7280; color:#fff; }

.card-full{
    background: #374151;
    color: #fff;
    text-align: center;
    padding: 1mm 1.5mm;
    font-size: 6pt;
    border-top: 1px solid #4b5563;
    word-break: break-word;
    line-height: 1.2;
}

/* ── ASSINATURAS ── */
.assinaturas{
    display: flex;
    justify-content: space-around;
    margin-top: 12mm;
    gap: 20mm;
    padding-top: 0;
}

.ass-bloco{
    flex: 1;
    text-align: center;
}

.ass-linha{
    border-top: 1px solid #111;
    margin-bottom: 3mm;
}

.ass-bloco span{
    font-size: 8.5pt;
    color: #333;
}

/* ── RODAPÉ DA PÁGINA ── */
.rodape-pagina{
    text-align: right;
    font-size: 7.5pt;
    color: #888;
    margin-top: 4mm;
}

/* ── IMPRESSÃO ── */
@media print{
    body{
        background: #fff;
        padding: 0;
    }

    .topo-btns{ display: none !important; }

    .pagina{
        box-shadow: none;
        margin: 0;
        padding: 14mm 12mm;
        width: 100%;
        min-height: 297mm;
    }

    @page{
        size: A4 portrait;
        margin: 0;
    }
}
</style>
</head>
<body>

<!-- BOTÕES (some na impressão) -->
<div class="topo-btns">
    <button class="btn-voltar" onclick="history.back()">Voltar</button>
    <button class="btn-imprimir" onclick="window.print()">🖨️ Imprimir</button>
</div>

<?php foreach ($paginas as $numPag => $itensPag): ?>
<div class="pagina">

    <!-- CABEÇALHO -->
    <div class="cabecalho">
        <div class="cab-titulo">
            <h1>Relatório de Descarte</h1>
            <p style="font-size:9pt;color:#555">Documento de registro de baixa patrimonial</p>
        </div>
        <div class="cab-logo">
            <img src="logo_rede.png" alt="Logo">
        </div>
    </div>

    <?php if ($numPag === 0): // Campos apenas na primeira página ?>
    <!-- CAMPOS -->
    <div class="campos-grid">
        <div class="campo-item">
            <label>Unidade</label>
            <span><?= htmlspecialchars($unidade) ?: '—' ?></span>
        </div>
        <div class="campo-item">
            <label>Data</label>
            <span><?= $data_impressao ?></span>
        </div>
        <div class="campo-item">
            <label>Total de Itens</label>
            <span><?= count($itens) ?> item(s)</span>
        </div>
    </div>

    <div class="campo-linha-2">
        <div class="campo-item">
            <label>Responsável Patrimônio</label>
            <span><?= htmlspecialchars($assistente) ?: '—' ?></span>
        </div>
        <div class="campo-item">
            <label>Responsável Acompanhante</label>
            <span><?= htmlspecialchars($acompanhante) ?: '—' ?></span>
        </div>
    </div>

    <!-- PROTOCOLO -->
    <div class="protocolo-destaque">
        PROTOCOLO: <?= htmlspecialchars($protocolo) ?>
    </div>
    <?php else: ?>
    <!-- Protocolo discreto nas páginas seguintes -->
    <div style="text-align:right;font-size:8.5pt;color:#555;margin-bottom:4mm">
        Protocolo: <strong><?= htmlspecialchars($protocolo) ?></strong>
        &nbsp;|&nbsp; Continuação — Página <?= $numPag + 1 ?>
    </div>
    <?php endif; ?>

    <!-- CARDS -->
    <div class="cards-area">
        <div class="cards-grid">
        <?php foreach ($itensPag as $item):
            $fotoSrc    = !empty($item['foto_base64'])
                ? 'data:image/jpeg;base64,' . $item['foto_base64']
                : null;
            $patrimonio = ($item['nao_conformidade'] === 'SIM' || $item['nao_conformidade'] === 'SEM TAG')
                ? 'SEM PATRIMÔNIO'
                : ($item['tag'] ?: '—');
        ?>
        <div class="item-card">
            <?php if ($fotoSrc): ?>
                <img class="card-foto" src="<?= $fotoSrc ?>" alt="Foto">
            <?php else: ?>
                <div class="card-foto-placeholder">Sem foto</div>
            <?php endif; ?>
            <div class="card-descricao"><?= htmlspecialchars($item['descricao'] ?? '—') ?></div>
            <div class="card-row">
                <div class="card-cell"><?= htmlspecialchars($item['marca']  ?? '—') ?></div>
                <div class="card-cell"><?= htmlspecialchars($item['modelo'] ?? '—') ?></div>
            </div>
            <div class="card-row">
                <div class="card-cell"><?= htmlspecialchars($item['serie'] ?? '—') ?></div>
                <div class="card-cell"><?= htmlspecialchars($patrimonio) ?></div>
            </div>
            <div class="card-full"><?= htmlspecialchars($item['obs'] ?? '—') ?></div>
            <div class="card-row">
                <div class="card-cell"><?= htmlspecialchars($item['unidade_origem'] ?? $item['unidade'] ?? '—') ?></div>
                <div class="card-cell"><?= htmlspecialchars($item['setor_origem']   ?? $item['setor']   ?? '—') ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- ASSINATURAS (apenas última página) -->
    <?php if ($numPag === count($paginas) - 1): ?>
    <div class="assinaturas">
        <div class="ass-bloco">
            <div class="ass-linha"></div>
            <span>Responsável Patrimônio</span>
        </div>
        <div class="ass-bloco">
            <div class="ass-linha"></div>
            <span>Responsável Acompanhante</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- RODAPÉ -->
    <div class="rodape-pagina">
        Página <?= $numPag + 1 ?> de <?= count($paginas) ?>
    </div>

</div>
<?php endforeach; ?>


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