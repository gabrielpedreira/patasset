const CACHE_NAME = 'patAsset-v2';

const ASSETS_TO_CACHE = [
    '/index.html',
    '/logo_1.png',
    '/logo_2.png',
    '/logo_rede.png',
    '/logo_rede_triangulo.png',
    '/gmail.png',
    '/manifest.json'
];

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS_TO_CACHE))
    );
    self.skipWaiting();
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    e.waitUntil(clients.claim());
});

self.addEventListener('fetch', e => {
    const req = e.request;
    const url = new URL(req.url);

    // Nunca intercepta: POST, arquivos .php, ou outros domínios
    if (req.method !== 'GET') return;
    if (!req.url.startsWith(self.location.origin)) return;
    if (url.pathname.endsWith('.php')) return;
    if (url.pathname.includes('heartbeat')) return;

    // Arquivos cacheáveis (apenas estáticos)
    const ext = url.pathname.split('.').pop().toLowerCase();
    const cacheable = ['png','jpg','jpeg','gif','svg','ico','webp','css','js','woff','woff2'];
    if (!cacheable.includes(ext)) return; // não intercepta o resto

    e.respondWith(
        fetch(req)
            .then(res => {
                const resClone = res.clone();
                caches.open(CACHE_NAME).then(cache => cache.put(req, resClone));
                return res;
            })
            .catch(() => caches.match(req).then(cached => cached || fetch(req)))
    );
});