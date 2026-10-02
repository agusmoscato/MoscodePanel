<?php
/**
 * sw.php — Service worker MÍNIMO de Moscode Panel, generado con la versión central (APP_VERSION).
 *
 * Solo sirve para que la app sea instalable y muestre una pantalla de "sin conexión".
 * NO cachea páginas ni respuestas: nunca se guardan datos de clientes en el dispositivo
 * (lo único que se guarda es offline.html, una página estática sin datos).
 *
 * Como el código incluye la versión, al subir la versión en privado/includes/version.php el navegador
 * detecta un service worker distinto, lo instala, borra el caché viejo y avisa a las pestañas abiertas.
 */
declare(strict_types=1);

$raiz = RAIZ_PRIVADA;

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
$version = json_encode(APP_VERSION);
$base = base_path();
echo <<<JS
/* Moscode Panel · service worker · versión {$version} */
const VERSION = $version;
const CACHE = 'moscode-offline-' + VERSION;
const OFFLINE = '$base/offline.html?v=' + encodeURIComponent(VERSION);
const OFFLINE_CSS = '$base/assets/css/offline.css';

self.addEventListener('install', (ev) => {
    ev.waitUntil(caches.open(CACHE).then((c) => c.addAll([OFFLINE, OFFLINE_CSS])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (ev) => {
    ev.waitUntil(
        caches.keys()
            .then((claves) => Promise.all(claves.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
            .then(() => self.clients.matchAll({ type: 'window' }))
            .then((ventanas) => ventanas.forEach((v) => v.postMessage({ tipo: 'version', version: VERSION })))
    );
});

// Solo las navegaciones: si no hay red, pantalla offline (y su CSS). Todo lo demás va directo a la red.
// Nunca se guarda ni se sirve desde el caché una página con datos.
self.addEventListener('fetch', (ev) => {
    if (ev.request.mode !== 'navigate') {
        if (new URL(ev.request.url).pathname === OFFLINE_CSS) {
            ev.respondWith(fetch(ev.request).catch(() => caches.match(OFFLINE_CSS)));
        }
        return;
    }
    ev.respondWith(fetch(ev.request).catch(() => caches.match(OFFLINE)));
});
JS;
