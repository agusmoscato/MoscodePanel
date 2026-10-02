<?php
/**
 * index.php — Controlador frontal: TODO pedido que no sea un archivo existente (assets, íconos, offline.html)
 * llega acá (ver .htaccess) y se resuelve con las rutas de privado/includes/rutas.php.
 *
 * Ningún otro .php es accesible por web: las páginas (login, portal, webhook, cron…) viven en privado/paginas/
 * y las vistas y acciones del panel en privado/vistas/ y privado/acciones/.
 */
declare(strict_types=1);

$raiz = is_dir(__DIR__ . '/../privado') ? __DIR__ . '/../privado' : __DIR__ . '/privado';
define('RAIZ_PRIVADA', realpath($raiz) ?: $raiz);
require RAIZ_PRIVADA . '/includes/rutas.php';
iniciar_manejo_errores();
cabeceras_seguridad();

$RUTA = resolver_ruta($_SERVER['REQUEST_METHOD'] ?? 'GET', ruta_pedida(), $_GET);

if ($RUTA !== null && $RUTA['tipo'] === 'redireccion') {
    header('Location: ' . $RUTA['destino'], true, $RUTA['codigo']);
    exit;
}
// Los parámetros de la ruta se ven como si vinieran en la query (la vista usa get('id'))
if ($RUTA !== null && isset($RUTA['params'])) {
    foreach ($RUTA['params'] as $k => $v) {
        $_GET[$k] = $v;
    }
}

// Qué necesita cada destino antes de arrancar: sesión de PHP o no, y si carga la base
$ligero = $RUTA !== null && $RUTA['tipo'] === 'pagina' && $RUTA['sin_sesion'] === null;   // manifest y service worker
if ($ligero) {
    require RAIZ_PRIVADA . '/includes/version.php';
    require RAIZ_PRIVADA . '/paginas/' . $RUTA['nombre'] . '.php';
    exit;
}
if ($RUTA !== null && $RUTA['tipo'] === 'pagina' && $RUTA['sin_sesion'] === true) {
    define('SIN_SESION', true);
}
// Un 404 de alguien sin cookies (un bot recorriendo rutas) no abre sesión: no se acumulan archivos de sesión vacíos
if (($RUTA === null || $RUTA['tipo'] === 'metodo') && !isset($_COOKIE['panel_sid']) && !isset($_COOKIE['panel_recordar'])) {
    define('SIN_SESION', true);
}
require RAIZ_PRIVADA . '/includes/bootstrap.php';

if ($RUTA === null || $RUTA['tipo'] === 'metodo') {
    pagina_no_encontrada($RUTA === null ? 404 : 405);
}

if ($RUTA['tipo'] === 'pagina') {
    $archivo = RAIZ_PRIVADA . '/paginas/' . $RUTA['nombre'] . '.php';
    is_file($archivo) ? require $archivo : pagina_no_encontrada();
    exit;
}

// Vistas y acciones del panel (exigen sesión)
require RAIZ_PRIVADA . '/paginas/panel.php';
