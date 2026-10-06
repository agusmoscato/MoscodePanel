<?php
/**
 * version.php — Versión CENTRAL de los assets y del service worker.
 *
 * Es el único lugar donde se sube la versión. En cada deploy que toque CSS, JS, íconos o fuentes,
 * cambiá el número: todos los links a assets llevan "?v=<versión>" y el service worker (/sw.js)
 * cambia de contenido, así el celular descarga lo nuevo solo (sin desinstalar la app).
 * Formato sugerido: AAAA.MM.DD-N  (N = número de deploy del día).
 */
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '2026.10.06-1');
}
