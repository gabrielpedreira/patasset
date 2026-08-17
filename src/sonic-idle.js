/**
 * sonic-idle.js
 * Efeito de inatividade — Sonic atravessa a tela após 2 min sem atividade.
 * Ativo apenas para usuários da lista SONIC_USERS.
 *
 * CONFIGURAÇÃO:
 *   Antes de incluir este script, defina no HTML (ou no seu JS de login):
 *     window.SONIC_USER = "nome_do_usuario_logado";
 *   OU salve em localStorage:
 *     localStorage.setItem('sonic_user', nome);
 *
 *   Ajuste SONIC_GIF_URL abaixo para o caminho/URL do GIF no seu servidor.
 */
(() => {
  // ── Configurações ─────────────────────────────────────────────────────────
  const SONIC_USERS   = ['usuario_exemplo'];                  // usuários que ativam o efeito (case-insensitive)
  const SONIC_GIF_URL = '/SONIC.gif';   // Hostgator: /home/usuario/public_html/SONIC.gif → URL pública: /SONIC.gif
  const IDLE_TIMEOUT  = 20 * 1000;                  // tempo de inatividade: 20 segundos (ms)
  const DURATION_MS   = 1800;                       // duração da travessia: 1.8 segundos
  // ─────────────────────────────────────────────────────────────────────────
  // Lê usuário: prioriza variável global, depois localStorage
  const currentUser = (
    (typeof window.SONIC_USER !== 'undefined' ? window.SONIC_USER : '') ||
    localStorage.getItem('sonic_user') ||
    ''
  ).trim();
  // Sai silenciosamente se o usuário não estiver na lista
  if (!SONIC_USERS.includes(currentUser.toLowerCase())) return;
  let idleTimer = null;
  let running   = false;
  let loopTimer = null;
  function createSonic() {
    const img = document.createElement('img');
    img.src = SONIC_GIF_URL;
    img.id  = '__sonic_runner__';
    Object.assign(img.style, {
      position:      'fixed',
      top:           '50%',
      transform:     'translateY(-50%)',
      right:         '-300px',
      height:        '120px',
      width:         'auto',
      zIndex:        '999999',
      pointerEvents: 'none',
      transition:    `right ${DURATION_MS}ms linear`,
    });
    return img;
  }
  function runOnce(onDone) {
    const old = document.getElementById('__sonic_runner__');
    if (old) old.remove();
    const img = createSonic();
    document.body.appendChild(img);
    void img.offsetWidth; // força reflow para a transição funcionar
    img.style.right = `calc(100% + 300px)`;
    setTimeout(() => {
      img.remove();
      onDone();
    }, DURATION_MS + 100);
  }
  function startLoop() {
    if (running) return;
    running = true;
    function next() {
      if (!running) return;
      loopTimer = setTimeout(() => {
        runOnce(() => { if (running) next(); });
      }, 400);
    }
    next();
  }
  function stopLoop() {
    running = false;
    clearTimeout(loopTimer);
    const img = document.getElementById('__sonic_runner__');
    if (img) img.remove();
  }
  function resetIdleTimer() {
    clearTimeout(idleTimer);
    if (running) stopLoop();
    idleTimer = setTimeout(startLoop, IDLE_TIMEOUT);
  }
  ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click']
    .forEach(evt => window.addEventListener(evt, resetIdleTimer, { passive: true }));
  resetIdleTimer();
})();
