<?php
/**
 * dev_captura_js.php
 * Injeta, no fim de toda página HTML, o script que captura erros de
 * JavaScript e os envia ao servidor.
 *
 * Carregado pela diretiva auto_append_file (ver .user.ini). Erro de JS não
 * aparece em log nenhum do servidor: ele acontece no navegador do usuário e
 * morre lá. Sem isso, "a tela travou" nunca vira informação acionável.
 */

// Nunca em CLI, nem se a captura estiver desligada
if (PHP_SAPI === 'cli') return;

// Só em HTML. Injetar <script> num JSON, num CSV ou num PDF corromperia
// a resposta — e vários endpoints do sistema devolvem JSON.
$ct_ok = true;
foreach (headers_list() as $h) {
    if (stripos($h, 'content-type:') === 0) {
        $ct_ok = (stripos($h, 'text/html') !== false);
    }
    // Download de arquivo: nunca injetar
    if (stripos($h, 'content-disposition:') === 0 && stripos($h, 'attachment') !== false) {
        $ct_ok = false;
    }
}
if (!$ct_ok) return;

// Requisição AJAX não renderiza HTML na tela
if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') return;

// Token CSRF da sessão, se houver alguém logado
require_once __DIR__ . '/seguranca_sessao.php';
$_seg_token = function_exists('seg_token') ? seg_token() : '';
?>
<script>
/* ═══════════════════════════════════════════════════════════════════════════
   Proteção CSRF e bloqueio por inatividade — dev_captura_js.php

   O token é anexado automaticamente a todo formulário e a toda requisição
   AJAX. Fazer isso aqui, num arquivo que o PHP acrescenta ao fim de cada
   página, evita editar 86 telas uma por uma — e, mais importante, evita que a
   próxima tela criada nasça sem proteção porque alguém esqueceu.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  // Reserva pelo cookie: se a sessão foi encerrada cedo pela página
  // (session_write_close), o valor gerado pelo PHP aqui pode vir vazio.
  function doCookie() {
    var m = /(?:^|;\s*)pat_csrf=([^;]+)/.exec(document.cookie || '');
    return m ? decodeURIComponent(m[1]) : '';
  }

  var TOKEN = <?= json_encode($_seg_token) ?> || doCookie();
  if (!TOKEN) return;                       // ninguém logado, nada a proteger
  if (window.__segCsrf) return;
  window.__segCsrf = TOKEN;

  /* ── 1. Formulários ── */
  function marcarFormulario(f) {
    if (!f || (f.method || 'get').toLowerCase() !== 'post') return;
    if (f.querySelector('input[name="_csrf"]')) return;
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = '_csrf'; i.value = TOKEN;
    f.appendChild(i);
  }
  document.addEventListener('submit', function (e) { marcarFormulario(e.target); }, true);
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form').forEach(marcarFormulario);
  });

  /* ── 2. fetch ── */
  var fetchOriginal = window.fetch;
  if (fetchOriginal) {
    window.fetch = function (recurso, opcoes) {
      opcoes = opcoes || {};
      var metodo = (opcoes.method || 'GET').toUpperCase();

      // Só requisições ao próprio site: não faz sentido (nem é seguro) mandar
      // o token para domínio de terceiro.
      var url = (typeof recurso === 'string') ? recurso : (recurso && recurso.url) || '';
      var externo = /^https?:\/\//i.test(url) && url.indexOf(location.origin) !== 0;

      if (metodo !== 'GET' && metodo !== 'HEAD' && !externo) {
        var h = new Headers(opcoes.headers || {});
        if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', TOKEN);
        opcoes.headers = h;

        // FormData também recebe o campo, para o caso de o servidor ler do POST
        if (opcoes.body instanceof FormData && !opcoes.body.has('_csrf')) {
          opcoes.body.append('_csrf', TOKEN);
        }
      }
      return fetchOriginal.call(this, recurso, opcoes);
    };
  }

  /* ── 3. XMLHttpRequest ── */
  var abrirOriginal = XMLHttpRequest.prototype.open;
  var enviarOriginal = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (metodo, url) {
    this.__segMetodo = (metodo || 'GET').toUpperCase();
    this.__segUrl = url || '';
    return abrirOriginal.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function (corpo) {
    try {
      var externo = /^https?:\/\//i.test(this.__segUrl)
                 && this.__segUrl.indexOf(location.origin) !== 0;
      if (this.__segMetodo && this.__segMetodo !== 'GET'
          && this.__segMetodo !== 'HEAD' && !externo) {
        this.setRequestHeader('X-CSRF-Token', TOKEN);
        if (corpo instanceof FormData && !corpo.has('_csrf')) corpo.append('_csrf', TOKEN);
      }
    } catch (e) {}
    return enviarOriginal.call(this, corpo);
  };

  /* ── 4. Bloqueio por inatividade ──────────────────────────────────────────
     Tem de ser aqui, no navegador. O heartbeat pinga o servidor a cada 30
     segundos enquanto a aba está aberta, então pelo servidor a sessão parece
     ativa para sempre — mesmo com o computador sozinho num posto de
     enfermagem. Quem sabe se há alguém ali é o teclado e o mouse. */
  var LIMITE_MIN = <?= (int)SEG_INATIVIDADE_MIN ?>;
  var ultimoUso = Date.now();
  var avisado = false;

  ['mousedown','mousemove','keydown','touchstart','scroll','click','wheel'].forEach(function (ev) {
    document.addEventListener(ev, function () {
      ultimoUso = Date.now();
      if (avisado) { var a = document.getElementById('segAviso'); if (a) a.remove(); avisado = false; }
    }, { passive: true, capture: true });
  });

  function avisar(min) {
    if (avisado) return;
    avisado = true;
    var d = document.createElement('div');
    d.id = 'segAviso';
    d.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);' +
      'background:#78350f;color:#fde68a;border:1px solid #f59e0b;border-radius:10px;' +
      'padding:13px 20px;font-family:Arial,sans-serif;font-size:13.5px;z-index:99999;' +
      'box-shadow:0 8px 28px rgba(0,0,0,.4);max-width:90vw;text-align:center';
    d.textContent = 'Sem atividade há um tempo. A sessão será encerrada em ' + min +
                    ' minuto(s) por segurança. Mexa o mouse para continuar.';
    document.body.appendChild(d);
  }

  setInterval(function () {
    var paradoMin = (Date.now() - ultimoUso) / 60000;
    if (paradoMin >= LIMITE_MIN) {
      location.href = 'logout.php?motivo=inatividade';
    } else if (paradoMin >= LIMITE_MIN - 2) {
      avisar(Math.max(1, Math.ceil(LIMITE_MIN - paradoMin)));
    }
  }, 20000);
})();
</script>

<script>
/* Captura de erros de JavaScript — dev_captura_js.php */
(function () {
  if (window.__devCapturaJS) return;
  window.__devCapturaJS = true;

  var enviados = {};      // evita repetir o mesmo erro na mesma tela
  var total    = 0;       // teto por página, para não virar enxurrada

  function enviar(dados) {
    if (total >= 10) return;
    var assinatura = (dados.mensagem || '') + '|' + (dados.arquivo || '') + '|' + (dados.linha || 0);
    if (enviados[assinatura]) return;
    enviados[assinatura] = true;
    total++;

    try {
      var corpo = JSON.stringify(dados);
      // sendBeacon sobrevive ao fechamento da aba; fetch é o plano B
      if (navigator.sendBeacon) {
        navigator.sendBeacon('dev_log_js.php', new Blob([corpo], { type: 'application/json' }));
      } else {
        fetch('dev_log_js.php', {
          method: 'POST', body: corpo, credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' }, keepalive: true
        }).catch(function () {});
      }
    } catch (e) { /* silêncio: o log não pode atrapalhar a página */ }
  }

  // Erros de sintaxe e exceções soltas
  window.addEventListener('error', function (ev) {
    // Falha ao carregar imagem/script/css não tem ev.message
    if (ev.target && ev.target !== window && ev.target.tagName) {
      var alvo = ev.target;
      var src  = alvo.src || alvo.href || '';
      if (!src) return;
      enviar({
        nivel: 'WARNING',
        mensagem: 'Recurso não carregou: <' + alvo.tagName.toLowerCase() + '> ' + src,
        arquivo: location.pathname.split('/').pop(),
        linha: 0, url: location.href
      });
      return;
    }
    enviar({
      nivel: 'ERROR',
      mensagem: ev.message || 'Erro sem mensagem',
      arquivo: (ev.filename || '').split('/').pop(),
      linha: ev.lineno || 0,
      coluna: ev.colno || 0,
      stack: (ev.error && ev.error.stack) ? String(ev.error.stack).slice(0, 3000) : '',
      url: location.href
    });
  }, true);

  // Promessas rejeitadas sem catch — causa comum de "não salvou e não avisou"
  window.addEventListener('unhandledrejection', function (ev) {
    var m = ev.reason;
    enviar({
      nivel: 'ERROR',
      mensagem: 'Promise sem tratamento: ' + (m && m.message ? m.message : String(m)).slice(0, 500),
      arquivo: location.pathname.split('/').pop(),
      linha: 0,
      stack: (m && m.stack) ? String(m.stack).slice(0, 3000) : '',
      url: location.href
    });
  });

  // console.error continua funcionando normalmente, só que também registra
  var erroOriginal = console.error;
  console.error = function () {
    try {
      var partes = Array.prototype.map.call(arguments, function (a) {
        if (a instanceof Error) return a.message;
        if (typeof a === 'object') { try { return JSON.stringify(a).slice(0, 300); } catch (e) { return '[objeto]'; } }
        return String(a);
      }).join(' ');
      if (partes.trim()) {
        enviar({
          nivel: 'WARNING',
          mensagem: 'console.error: ' + partes.slice(0, 500),
          arquivo: location.pathname.split('/').pop(),
          linha: 0, url: location.href
        });
      }
    } catch (e) {}
    return erroOriginal.apply(console, arguments);
  };
})();
</script>
