<?php
/**
 * backup_oauth.php — Autorização do Google Drive (acesso DEV)
 *
 * Guia passo a passo e executa o fluxo OAuth. Roda UMA vez; depois o
 * refresh token guardado renova o acesso sozinho, sem ninguém na tela.
 */
session_start();
mysqli_report(MYSQLI_REPORT_OFF);
require_once 'conexao.php';

if (!isset($_SESSION['usuario_logado'])) { header('Location: index.html'); exit; }

$classe = '';
$st = $conn->prepare("SELECT classe_usuario FROM usuarios WHERE usuario=? LIMIT 1");
if ($st) {
    $st->bind_param('s', $_SESSION['usuario_logado']);
    $st->execute();
    $r = $st->get_result();
    if ($r && ($x = $r->fetch_assoc())) $classe = strtoupper(trim($x['classe_usuario'] ?? ''));
    $st->close();
}
$conn->close();
if ($classe !== 'DEV') { header('Location: acesso_bloqueado.html'); exit; }

require_once __DIR__ . '/backup_drive_oauth.php';

/** Endereço desta própria página — é o que o Google exige cadastrar */
function url_retorno(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path  = strtok($_SERVER['REQUEST_URI'] ?? '/backup_oauth.php', '?');
    return ($https ? 'https' : 'http') . '://' . $host . $path;
}

$redirect = url_retorno();
$msg = ''; $tipo = '';
$cfg = oauth_carregar();

/* ── Salvar client_id / client_secret ─────────────────────────────────────── */
if (($_POST['acao'] ?? '') === 'salvar') {
    $cid = trim($_POST['client_id'] ?? '');
    $sec = trim($_POST['client_secret'] ?? '');
    $nome = trim($_POST['pasta_nome'] ?? '') ?: 'Backup PatAsset LifeTech';

    if ($cid === '' || $sec === '') {
        $msg = 'Preencha o ID e a chave secreta do cliente.'; $tipo = 'erro';
    } elseif (!str_ends_with($cid, '.apps.googleusercontent.com')) {
        // Barra o preenchimento automático do navegador, que insere o usuário
        // salvo do sistema no lugar do ID do cliente.
        $msg  = 'O ID do cliente informado ("' . htmlspecialchars($cid) . '") não é válido. '
              . 'Ele é longo e termina em .apps.googleusercontent.com — copie do Google Cloud Console. '
              . 'Se o campo veio preenchido sozinho, foi o navegador: limpe antes de colar.';
        $tipo = 'erro';
    } else {
        $cfg['client_id']     = $cid;
        $cfg['client_secret'] = $sec;
        $cfg['pasta_nome']    = $nome;
        if (oauth_salvar($cfg)) {
            $msg = 'Dados salvos. Agora clique em "Autorizar no Google".'; $tipo = 'ok';
        } else {
            $msg = 'Não foi possível gravar em ' . oauth_arquivo()
                 . '. Verifique se a pasta existe e tem permissão de escrita.';
            $tipo = 'erro';
        }
    }
    $cfg = oauth_carregar();
}

/* ── Iniciar autorização ──────────────────────────────────────────────────── */
if (($_GET['ir'] ?? '') === '1' && !empty($cfg['client_id'])) {
    // Guarda o endereço exato usado agora. Na troca do código o Google exige
    // o MESMO redirect_uri, caractere por caractere. Recalcular na volta é
    // arriscado: basta o usuário abrir a página sem "www" para os dois
    // divergirem e o Google responder invalid_grant.
    $cfg['redirect_uri'] = $redirect;
    oauth_salvar($cfg);

    $params = http_build_query([
        'client_id'     => $cfg['client_id'],
        'redirect_uri'  => $redirect,
        'response_type' => 'code',
        'scope'         => oauth_escopo(),
        // offline + consent: sem os dois o Google devolve só o access_token,
        // que morre em 1 hora. Precisamos do refresh_token, que é permanente.
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'include_granted_scopes' => 'true',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

/* ── Retorno do Google ────────────────────────────────────────────────────── */
if (isset($_GET['error'])) {
    $msg = 'Autorização cancelada ou negada: ' . htmlspecialchars($_GET['error']);
    $tipo = 'erro';
}

/**
 * Troca o código de autorização pelo refresh token.
 * Isolado numa função porque há dois caminhos até aqui: o retorno automático
 * do Google e a colagem manual do código (ver abaixo).
 */
function trocar_codigo(string $codigo, array $cfg, string $redirect): array {
    // Prefere o endereço registrado no momento da autorização (ver ?ir=1)
    $redirect = $cfg['redirect_uri'] ?? $redirect;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $codigo,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri'  => $redirect,
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = (string)curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $d = json_decode($resp, true);

    if ($http === 200 && !empty($d['refresh_token'])) {
        $cfg['refresh_token'] = $d['refresh_token'];
        $cfg['access_token']  = $d['access_token'] ?? '';
        $cfg['expira_em']     = time() + (int)($d['expires_in'] ?? 3600);
        $cfg['autorizado_em'] = date('Y-m-d H:i:s');
        oauth_salvar($cfg);
        return ['ok', 'Autorizado com sucesso. O backup já pode enviar ao Drive.'];
    }
    if ($http === 200) {
        // Conta que já autorizou antes: o Google não reemite o refresh token.
        return ['erro', 'O Google não devolveu o refresh token. Remova o acesso do app em '
                      . 'myaccount.google.com/permissions e autorize novamente.'];
    }
    $erro = $d['error_description'] ?? $d['error'] ?? substr($resp, 0, 300);
    if (($d['error'] ?? '') === 'invalid_grant') {
        $erro .= '.<br><br>Três causas possíveis, nesta ordem de probabilidade:'
               . '<br>1) O endereço de retorno não confere. Foi usado '
               . '<code>' . htmlspecialchars($redirect) . '</code> — ele precisa estar '
               . 'cadastrado <b>exatamente assim</b> nos URIs de redirecionamento do '
               . 'cliente OAuth (atenção ao <b>www</b> e ao <b>https</b>).'
               . '<br>2) O código já foi usado — cada um vale uma única vez.'
               . '<br>3) O código expirou (poucos minutos).'
               . '<br><br>Clique em "Autorizar no Google" novamente para gerar outro.';
    }
    return ['erro', "Falha na troca do código (HTTP $http): " . $erro];
}

/* Retorno automático do Google */
if (isset($_GET['code']) && !empty($cfg['client_id'])) {
    [$tipo, $msg] = trocar_codigo($_GET['code'], $cfg, $redirect);
    $cfg = oauth_carregar();
}

/* Colagem manual do código.
   Necessário porque o ModSecurity da hospedagem bloqueia o retorno do Google:
   o endereço traz "iss=https://accounts.google.com", e uma URL dentro da query
   string dispara a regra de inclusão remota. A autorização em si funciona — só
   a página de retorno é barrada. Como o código fica visível na barra de
   endereços, dá para concluir colando-o aqui. */
if (($_POST['acao'] ?? '') === 'codigo_manual' && !empty($cfg['client_id'])) {
    $bruto = trim($_POST['codigo'] ?? '');

    // Aceita a URL inteira ou só o código
    if (preg_match('/[?&]code=([^&\s]+)/', $bruto, $m)) {
        $codigo = urldecode($m[1]);
    } else {
        $codigo = $bruto;
    }

    if ($codigo === '') {
        $msg = 'Cole a URL da barra de endereços ou o código.'; $tipo = 'erro';
    } else {
        [$tipo, $msg] = trocar_codigo($codigo, $cfg, $redirect);
    }
    $cfg = oauth_carregar();
}

/* ── Teste de envio ───────────────────────────────────────────────────────── */
if (($_GET['testar'] ?? '') === '1') {
    $tmp = sys_get_temp_dir() . '/_teste_patasset.txt';
    file_put_contents($tmp, "Teste de envio do PatAsset em " . date('d/m/Y H:i:s'));
    $res = oauth_enviar($tmp, '_TESTE_patasset_' . date('Ymd_His') . '.txt');
    @unlink($tmp);
    if ($res['ok']) {
        $msg  = 'Envio de teste concluído. O arquivo "_TESTE_..." está na pasta do seu Drive '
              . '(pode apagar). O backup ao Drive está funcionando.';
        $tipo = 'ok';
    } else {
        $msg  = 'Falha no envio de teste: ' . $res['erro'];
        $tipo = 'erro';
    }
    $cfg = oauth_carregar();
}

/* ── Revogar ──────────────────────────────────────────────────────────────── */
if (($_POST['acao'] ?? '') === 'revogar') {
    $cfg['refresh_token'] = '';
    $cfg['access_token']  = '';
    oauth_salvar($cfg);
    $msg = 'Autorização removida deste servidor.'; $tipo = 'ok';
    $cfg = oauth_carregar();
}

$pronto = oauth_configurado();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Autorização do Google Drive — PatAsset</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f172a;color:#e2e8f0;padding:26px 16px;line-height:1.65}
.wrap{max-width:780px;margin:0 auto}
h1{font-size:21px;margin-bottom:4px}
.sub{font-size:13px;color:#94a3b8;margin-bottom:24px}
.box{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:20px 22px;margin-bottom:14px}
.box h2{font-size:15px;margin-bottom:12px;display:flex;align-items:center;gap:10px}
.num{width:24px;height:24px;border-radius:50%;background:#2563eb;color:#fff;font-size:12px;
     display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700}
.box p{font-size:13.5px;color:#cbd5e1;margin-bottom:10px}
.box ol{margin:0 0 10px 18px;font-size:13.5px;color:#cbd5e1}
.box li{margin-bottom:6px}
code{background:#0f172a;padding:3px 8px;border-radius:5px;font-size:12.5px;color:#93c5fd;
     word-break:break-all;font-family:ui-monospace,monospace}
.copiavel{display:flex;gap:8px;align-items:center;background:#0f172a;border:1px solid #334155;
          border-radius:8px;padding:10px 12px;margin:10px 0}
.copiavel input{flex:1;background:none;border:none;color:#93c5fd;font-family:ui-monospace,monospace;
                font-size:12.5px;outline:none}
label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;
      color:#94a3b8;margin:12px 0 5px}
input[type=text],input[type=password]{width:100%;background:#0f172a;border:1px solid #334155;
      border-radius:8px;padding:11px 13px;color:#e2e8f0;font-size:14px;outline:none}
input:focus{border-color:#2563eb}
.btn{display:inline-flex;align-items:center;gap:8px;background:#2563eb;color:#fff;border:none;
     padding:12px 20px;border-radius:10px;font-size:14px;cursor:pointer;text-decoration:none;margin-top:14px}
.btn:hover{background:#1d4ed8}
.btn-ghost{background:#334155}.btn-ghost:hover{background:#3f4d63}
.btn-ok{background:#16a34a}.btn-ok:hover{background:#15803d}
.btn-red{background:#7f1d1d}.btn-red:hover{background:#991b1b}
.aviso{border-radius:10px;padding:13px 16px;font-size:13.5px;margin-bottom:18px;display:flex;gap:11px}
.aviso-ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#86efac}
.aviso-erro{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:#fca5a5}
.selo{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:700;
      padding:5px 12px;border-radius:20px}
.selo-ok{background:rgba(34,197,94,.15);color:#4ade80}
.selo-no{background:rgba(148,163,184,.15);color:#94a3b8}
.nota{font-size:12.5px;color:#fbbf24;background:rgba(251,191,36,.07);
      border-left:3px solid #fbbf24;padding:11px 14px;border-radius:0 8px 8px 0;margin-top:12px}
</style>
</head>
<body>
<div class="wrap">

  <h1>Autorização do Google Drive</h1>
  <div class="sub">
    Substitui a conta de serviço, que não tem cota de armazenamento própria.
    Aqui os arquivos ficam sendo seus e ocupam a sua cota de 15 GB.
  </div>

  <?php if ($msg): ?>
  <div class="aviso <?= $tipo === 'ok' ? 'aviso-ok' : 'aviso-erro' ?>">
    <i class="fas <?= $tipo === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
    <div><?= $msg ?></div>
  </div>
  <?php endif; ?>

  <div style="margin-bottom:18px">
    <?php if ($pronto): ?>
      <span class="selo selo-ok"><i class="fas fa-circle-check"></i> Autorizado
        <?= !empty($cfg['autorizado_em']) ? 'em ' . htmlspecialchars($cfg['autorizado_em']) : '' ?></span>
    <?php else: ?>
      <span class="selo selo-no"><i class="fas fa-circle-minus"></i> Ainda não autorizado</span>
    <?php endif; ?>
  </div>

  <!-- ══ 1 ══ -->
  <div class="box">
    <h2><span class="num">1</span> Configurar a tela de permissão</h2>
    <p>No <b>Google Cloud Console</b>, projeto <code>meu-projeto-backup</code>,
       vá em <b>APIs e serviços → Tela de permissão OAuth</b>.</p>
    <ol>
      <li>Tipo de usuário: <b>Externo</b> → Criar</li>
      <li>Nome do app: <code>PatAsset Backup</code>. E-mail de suporte e de contato: o seu.</li>
      <li>Em <b>Escopos</b>, pode avançar sem adicionar nada — o escopo vai no pedido de autorização.</li>
      <li>Salve e volte ao painel da tela de permissão.</li>
      <li><b>Clique em "PUBLICAR APP"</b> e confirme.</li>
    </ol>
    <div class="nota">
      <b>O passo 5 não é opcional.</b> Enquanto o app fica em modo "Teste", o Google
      expira o refresh token a cada 7 dias — o backup pararia de enviar sozinho toda semana,
      silenciosamente. Publicado, o token vale indefinidamente.
      Como usamos um escopo não sensível, publicar não exige verificação do Google.
    </div>
  </div>

  <!-- ══ 2 ══ -->
  <div class="box">
    <h2><span class="num">2</span> Criar o ID do cliente OAuth</h2>
    <p>Em <b>Credenciais → Criar credenciais → ID do cliente OAuth</b>,
       tipo <b>Aplicativo da Web</b>, nome <code>PatAsset Backup</code>.</p>
    <p>Em <b>URIs de redirecionamento autorizados</b>, clique em "Adicionar URI" e cole
       exatamente este endereço:</p>
    <div class="copiavel">
      <input type="text" id="uri" value="<?= htmlspecialchars($redirect) ?>" readonly>
      <button class="btn" style="margin:0;padding:7px 12px;font-size:12px" onclick="copiar()">
        <i class="fas fa-copy"></i> Copiar
      </button>
    </div>
    <p>Ao criar, o Google mostra o <b>ID do cliente</b> e a <b>Chave secreta</b>. Guarde os dois.</p>
  </div>

  <!-- ══ 3 ══ -->
  <div class="box">
    <h2><span class="num">3</span> Informar as credenciais</h2>
    <!-- autocomplete="off" e type="text" na chave secreta: com um campo de
         texto seguido de um type="password", o navegador entende que é tela
         de login e preenche com o usuário e a senha salvos do sistema. -->
    <form method="POST" autocomplete="off">
      <input type="hidden" name="acao" value="salvar">
      <!-- Campos-isca: alguns navegadores ignoram autocomplete="off" e
           preenchem os dois primeiros campos que encontram. Que preencham estes. -->
      <input type="text" name="isca_usuario" style="display:none" tabindex="-1" aria-hidden="true">
      <input type="password" name="isca_senha" style="display:none" tabindex="-1" aria-hidden="true">

      <label>ID do cliente</label>
      <input type="text" id="cid" name="client_id" required
             autocomplete="off" spellcheck="false"
             value="<?= htmlspecialchars($cfg['client_id'] ?? '') ?>"
             placeholder="000000000000-xxxxxxxxxxxxxxxx.apps.googleusercontent.com">
      <div id="cid-aviso" class="nota" style="display:none;margin-top:8px">
        Isso não parece um ID de cliente. Ele é longo e termina em
        <code>.apps.googleusercontent.com</code>.
      </div>

      <label>Chave secreta do cliente</label>
      <input type="text" name="client_secret" required
             autocomplete="off" spellcheck="false"
             value="<?= htmlspecialchars($cfg['client_secret'] ?? '') ?>"
             placeholder="GOCSPX-...">

      <label>Nome da pasta que será criada no seu Drive</label>
      <input type="text" name="pasta_nome" autocomplete="off"
             value="<?= htmlspecialchars($cfg['pasta_nome'] ?? 'Backup PatAsset LifeTech') ?>">
      <button type="submit" class="btn"><i class="fas fa-floppy-disk"></i> Salvar</button>
    </form>
    <div class="nota">
      Gravado em <code><?= htmlspecialchars(oauth_arquivo()) ?></code>, fora da área pública
      do site. Esse arquivo dá acesso ao seu Drive — por isso não fica junto com o site.
      <br><br>
      A pasta é criada pelo próprio sistema, e não a que você compartilhou antes: o escopo
      usado só enxerga o que o app criou. É o que nos permite publicar sem passar pela
      verificação do Google.
    </div>
  </div>

  <!-- ══ 4 ══ -->
  <div class="box">
    <h2><span class="num">4</span> Autorizar</h2>
    <?php if (empty($cfg['client_id'])): ?>
      <p style="color:#94a3b8">Preencha o passo 3 primeiro.</p>
    <?php else: ?>
      <p>Você será levado ao Google para autorizar. Escolha a conta onde os backups
         devem ficar.</p>
      <a class="btn btn-ok" href="?ir=1"><i class="fab fa-google"></i> Autorizar no Google</a>
      <div class="nota">
        Vai aparecer uma tela dizendo que <b>"o app não foi verificado pelo Google"</b>.
        É esperado: o app é seu e roda só no seu servidor. Clique em
        <b>"Avançado"</b> → <b>"Acessar PatAsset Backup (não seguro)"</b>.
      </div>

      <hr style="border:none;border-top:1px solid #334155;margin:20px 0">

      <p style="font-size:14px;font-weight:600;color:#e2e8f0">
        Se voltar com erro <b>"Not Acceptable"</b> ou <b>Mod_Security</b>
      </p>
      <p>A autorização funcionou — quem barrou foi o firewall da hospedagem, que não
         gosta de ver um endereço <code>https://...</code> dentro da URL de retorno.
         O código está na barra de endereços da página bloqueada. Copie a
         <b>URL inteira</b> e cole abaixo:</p>
      <form method="POST" autocomplete="off">
        <input type="hidden" name="acao" value="codigo_manual">
        <input type="text" name="codigo" required autocomplete="off" spellcheck="false"
               placeholder="Cole aqui a URL inteira da página bloqueada">
        <button type="submit" class="btn"><i class="fas fa-key"></i> Concluir autorização</button>
      </form>
      <div class="nota">
        O código vale uma única vez e expira em poucos minutos. Se der
        <code>invalid_grant</code>, clique em "Autorizar no Google" de novo para
        gerar outro e cole o novo endereço.
      </div>
    <?php endif; ?>
  </div>

  <!-- ══ 5 ══ -->
  <?php if ($pronto): ?>
  <div class="box">
    <h2><span class="num">5</span> Testar</h2>
    <p>Envia um arquivo de texto de poucos bytes para a pasta, confirmando que o
       caminho inteiro funciona.</p>
    <a class="btn" href="?testar=1"><i class="fas fa-paper-plane"></i> Enviar arquivo de teste</a>
    <form method="POST" style="display:inline"
          onsubmit="return confirm('Remover a autorização deste servidor?')">
      <input type="hidden" name="acao" value="revogar">
      <button type="submit" class="btn btn-red"><i class="fas fa-unlink"></i> Revogar</button>
    </form>
  </div>
  <?php endif; ?>

  <a class="btn btn-ghost" href="dev_painel.php"><i class="fas fa-arrow-left"></i> Voltar ao painel</a>
</div>

<script>
// Limpa o que o navegador tiver preenchido sozinho e avisa se o ID não tem cara de ID
window.addEventListener('load', () => {
  const cid = document.getElementById('cid');
  if (!cid) return;
  const salvo = <?= json_encode($cfg['client_id'] ?? '') ?>;
  if (cid.value && cid.value !== salvo) cid.value = salvo;

  const checar = () => {
    const v = cid.value.trim();
    document.getElementById('cid-aviso').style.display =
      (v !== '' && !v.endsWith('.apps.googleusercontent.com')) ? 'block' : 'none';
  };
  cid.addEventListener('input', checar);
  setTimeout(checar, 400);
});

function copiar() {
  const c = document.getElementById('uri');
  c.select(); c.setSelectionRange(0, 99999);
  navigator.clipboard.writeText(c.value).then(
    () => alert('Endereço copiado.'),
    () => document.execCommand('copy')
  );
}
</script>
</body>
</html>
