<?php
/**
 * router_http.php — Router del servidor embebido de PHP (php -S) para las pruebas por HTTP (lo arranca
 * servidor_http() de soporte_http.php; no se usa a mano). Hace lo mismo que public_html/.htaccess: un archivo
 * existente (assets, íconos, offline.html) se sirve tal cual y todo lo demás va al controlador frontal.
 *
 * Sirve la COPIA de la app que arma soporte_http.php en tests/.entorno/http/ (con el config.php de prueba al
 * lado de privado/), nunca la carpeta real: así index.php resuelve RAIZ_PRIVADA solo, sin cambiar una línea.
 */
declare(strict_types=1);

$publico = __DIR__ . '/.entorno/http/public_html';
$ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($ruta !== '/' && !str_contains($ruta, '..') && is_file($publico . $ruta) && !str_ends_with($ruta, '.php')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publico . '/index.php';
require $publico . '/index.php';
