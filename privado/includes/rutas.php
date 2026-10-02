<?php
/**
 * rutas.php — Todas las rutas del panel en UN solo lugar, y las funciones para armar y resolver URLs.
 *
 * Es independiente (no necesita la base ni la sesión): lo carga public_html/index.php antes que nada para
 * decidir qué hacer con cada pedido, y después lo usa todo el panel para generar links:
 *
 *   url('cliente', ['id' => 12])               → /clientes/12
 *   url('servicio_form', ['cliente_id' => 12]) → /clientes/12/servicios/nuevo
 *   url('cargos', ['estado' => 'pagado'])      → /cobros?estado=pagado     (lo que no es parte de la ruta va como ?query)
 *   url_accion('cliente_guardar')              → /acciones/cliente_guardar (solo POST con CSRF)
 *   url_exportar('clientes', ['q' => 'x'])     → /exportar/clientes.csv?q=x
 *
 * Los nombres de las vistas son los de privado/vistas/<nombre>.php. Una vista puede tener varias rutas: al
 * generar el link se usa la que aprovecha más parámetros (cliente_form con id → /clientes/12/editar; sin id → /clientes/nuevo).
 * Los {parámetros} de la ruta se vuelven $_GET[...] para la vista, como si vinieran en la query.
 */
declare(strict_types=1);

/** Vistas (siempre GET): nombre => rutas posibles. */
const RUTAS_VISTAS = [
    'dashboard'      => ['/'],
    'clientes'       => ['/clientes'],
    'cliente_form'   => ['/clientes/nuevo', '/clientes/{id}/editar'],
    'cliente'        => ['/clientes/{id}'],
    'resumen_cuenta' => ['/clientes/{id}/resumen'],
    'servicio_form'  => ['/clientes/{cliente_id}/servicios/nuevo', '/servicios/{id}/editar'],
    'dominio_form'   => ['/clientes/{cliente_id}/dominios/nuevo', '/dominios/{id}/editar'],
    'pago_form'      => ['/pagos/nuevo'],
    'elegir_cliente' => ['/elegir-cliente'],
    'cargos'         => ['/cobros'],
    'vencimientos'   => ['/vencimientos'],
    'precios'        => ['/precios'],
    'reportes'       => ['/reportes'],
    'cotizacion'     => ['/dolar'],
    'planes'         => ['/cuotas'],
    'plan_form'      => ['/clientes/{cliente_id}/cuotas/nueva', '/cuotas/nueva'],
    'plan'           => ['/cuotas/{id}'],
    'plan_editar'    => ['/cuotas/{id}/editar'],
    'configuracion'  => ['/configuracion'],
    'mi_cuenta'      => ['/mi-cuenta'],
    'cambiar_clave'  => ['/cambiar-clave'],
    'usuarios'       => ['/usuarios'],
    'usuario_form'   => ['/usuarios/nuevo'],
    'feriados'       => ['/feriados'],
    'notificaciones' => ['/notificaciones'],
    'pago_anular'    => ['/pagos/{id}/anular'],
    'dos_pasos'      => ['/mi-cuenta/dos-pasos'],
    'actividad'      => ['/actividad'],
    'backups'        => ['/backups'],
];

/**
 * Páginas especiales: ruta => [métodos, página (privado/paginas/<página>.php), "sin sesión" de PHP].
 * Las que terminan en .php son las URLs VIEJAS que ya pueden estar configuradas o enviadas (webhook de
 * Mercado Pago, links del portal, crons): siguen funcionando sin redirección.
 */
const RUTAS_ESPECIALES = [
    '/login'                       => ['GET|POST', 'login', false],
    '/login/2fa'                   => ['GET|POST', 'login2fa', false],
    '/portal/{t}'                  => ['GET', 'portal', true],
    '/portal/{t}/pagar/{pagar}'    => ['GET', 'portal', true],
    '/webhook/mp/{token}'          => ['GET|POST', 'webhook', true],
    '/cron/{tarea}'                => ['GET', 'cron', true],
    '/install'                     => ['GET|POST', 'install', true],
    '/manifest.webmanifest'        => ['GET', 'manifest', null],
    '/sw.js'                       => ['GET', 'sw', null],
    '/exportar/{tipo}.csv'         => ['GET', 'descargar', false],
    // URLs viejas que se mantienen sin redirigir
    '/portal.php'                  => ['GET', 'portal', true],
    '/webhook_mp.php'              => ['GET|POST', 'webhook', true],
    '/cron.php'                    => ['GET', 'cron', true],
    '/install.php'                 => ['GET|POST', 'install', true],
    '/manifest.php'                => ['GET', 'manifest', null],
    '/sw.php'                      => ['GET', 'sw', null],
];

/** Parámetros de ruta: qué texto acepta cada uno (el resto es numérico). */
const RUTAS_PARAMS = [
    't' => '[A-Za-z0-9_-]{8,128}', 'token' => '[A-Za-z0-9_-]{8,128}',
    'tarea' => '[a-z_]{3,40}', 'tipo' => '[a-z_]{3,40}', 'accion' => '[a-z0-9_]{2,60}',
];

/** Carpeta del sitio dentro del dominio ('' si está en la raíz). */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        $base = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    }
    return $base;
}

/** Ruta pedida, sin la carpeta del sitio ni la query, normalizada ("/clientes/12"). */
function ruta_pedida(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = rawurldecode($path);
    $base = base_path();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim(preg_replace('~/+~', '/', $path), '/');
    return $path;
}

/** Patrón de ruta → expresión regular con grupos con nombre ({id} = número). */
function ruta_a_regex(string $patron): string
{
    $partes = preg_split('/(\{[a-z_]+\})/', $patron, -1, PREG_SPLIT_DELIM_CAPTURE);
    $re = '';
    foreach ($partes as $p) {
        if (preg_match('/^\{([a-z_]+)\}$/', $p, $m)) {
            $re .= '(?P<' . $m[1] . '>' . (RUTAS_PARAMS[$m[1]] ?? '\d+') . ')';
        } else {
            $re .= preg_quote($p, '~');
        }
    }
    return '~^' . $re . '$~';
}

/** Parámetros de una ruta con captura (solo los nombrados). */
function ruta_capturas(array $m): array
{
    return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
}

/**
 * Resuelve un pedido. Devuelve:
 *   ['tipo' => 'vista', 'nombre' => ..., 'params' => [...]]
 *   ['tipo' => 'accion', 'nombre' => ..., 'params' => []]            (POST)
 *   ['tipo' => 'pagina', 'nombre' => ..., 'params' => [...], 'sin_sesion' => bool|null]
 *   ['tipo' => 'redireccion', 'destino' => url, 'codigo' => 301]
 *   ['tipo' => 'metodo']  (la ruta existe pero no con ese método)
 *   null                  (404)
 */
function resolver_ruta(string $metodo, string $ruta, array $query): ?array
{
    $metodo = strtoupper($metodo);
    if ($metodo === 'HEAD') {
        $metodo = 'GET';
    }

    // --- URLs viejas que se redirigen (301) ---
    if ($ruta === '/index.php') {
        if (isset($query['a']) && $metodo === 'POST') {          // formulario viejo: se atiende igual (un POST no se puede redirigir bien)
            return ['tipo' => 'accion', 'nombre' => (string) $query['a'], 'params' => []];
        }
        $p = (string) ($query['p'] ?? '');
        unset($query['p'], $query['a']);
        if ($p === '') {
            return ['tipo' => 'redireccion', 'destino' => url('dashboard', $query), 'codigo' => 301];
        }
        if (!isset(RUTAS_VISTAS[$p])) {
            return null;
        }
        try {
            return ['tipo' => 'redireccion', 'destino' => url($p, $query), 'codigo' => 301];
        } catch (InvalidArgumentException $ex) {
            return null;               // faltan parámetros que la ruta nueva necesita
        }
    }
    if ($ruta === '/login.php') {
        if ($metodo === 'POST') {                                 // formulario viejo de login: se atiende igual
            return ['tipo' => 'pagina', 'nombre' => 'login', 'params' => [], 'sin_sesion' => false];
        }
        return ['tipo' => 'redireccion', 'destino' => url_base('/login'), 'codigo' => 301];
    }
    if ($ruta === '/descargar.php') {
        $tipo = (string) ($query['tipo'] ?? '');
        unset($query['tipo']);
        return preg_match('/^[a-z_]{3,40}$/', $tipo) ? ['tipo' => 'redireccion', 'destino' => url_exportar($tipo, $query), 'codigo' => 301] : null;
    }

    // --- Acciones: POST /acciones/{accion} y POST /salir ---
    if ($ruta === '/salir') {
        return $metodo === 'POST' ? ['tipo' => 'accion', 'nombre' => 'logout', 'params' => []] : ['tipo' => 'redireccion', 'destino' => url('dashboard'), 'codigo' => 302];
    }
    if (preg_match('~^/acciones/([a-z0-9_]{2,60})$~', $ruta, $m)) {
        return $metodo === 'POST' ? ['tipo' => 'accion', 'nombre' => $m[1], 'params' => []] : ['tipo' => 'metodo'];
    }

    // --- Vistas ---
    foreach (RUTAS_VISTAS as $nombre => $patrones) {
        foreach ($patrones as $patron) {
            if (preg_match(ruta_a_regex($patron), $ruta, $m)) {
                return $metodo === 'GET' ? ['tipo' => 'vista', 'nombre' => $nombre, 'params' => ruta_capturas($m)] : ['tipo' => 'metodo'];
            }
        }
    }

    // --- Páginas especiales ---
    foreach (RUTAS_ESPECIALES as $patron => [$metodos, $pagina, $sinSesion]) {
        if (preg_match(ruta_a_regex($patron), $ruta, $m)) {
            if (!in_array($metodo, explode('|', $metodos), true)) {
                return ['tipo' => 'metodo'];
            }
            return ['tipo' => 'pagina', 'nombre' => $pagina, 'params' => ruta_capturas($m), 'sin_sesion' => $sinSesion];
        }
    }
    return null;
}

// ---------------------------------------------------------------------------------------------------------
// Generación de URLs
// ---------------------------------------------------------------------------------------------------------

/** Ruta absoluta dentro del sitio: url_base('/login') → "/login" (o "/carpeta/login"). */
function url_base(string $ruta): string
{
    return base_path() . ($ruta === '' ? '/' : $ruta);
}

/** Agrega ?query con lo que no sea null ni ''. */
function url_con_query(string $ruta, array $params): string
{
    $q = array_filter($params, fn($v) => $v !== null && $v !== '');
    return url_base($ruta) . ($q ? '?' . http_build_query($q) : '');
}

/**
 * URL de una vista: url('cliente', ['id' => 3]) → /clientes/3.
 * Elige la ruta que usa más parámetros; los que sobran van en la query.
 */
function url(string $vista, array $params = []): string
{
    $patrones = RUTAS_VISTAS[$vista] ?? null;
    if ($patrones === null) {
        throw new InvalidArgumentException("Vista sin ruta: $vista");
    }
    $mejor = null;
    $mejorN = -1;
    foreach ($patrones as $patron) {
        preg_match_all('/\{([a-z_]+)\}/', $patron, $m);
        $faltan = array_filter($m[1], fn($n) => !isset($params[$n]) || $params[$n] === '' || $params[$n] === 0 || $params[$n] === '0');
        if (!$faltan && count($m[1]) > $mejorN) {
            $mejor = [$patron, $m[1]];
            $mejorN = count($m[1]);
        }
    }
    if ($mejor === null) {
        throw new InvalidArgumentException("Faltan parámetros para la ruta de $vista");
    }
    [$patron, $nombres] = $mejor;
    foreach ($nombres as $n) {
        $patron = str_replace('{' . $n . '}', rawurlencode((string) $params[$n]), $patron);
        unset($params[$n]);
    }
    return url_con_query($patron, $params);
}

/** URL de una acción (siempre POST con token CSRF). */
function url_accion(string $accion, array $params = []): string
{
    return url_con_query('/acciones/' . $accion, $params);
}

/** URL de una exportación CSV: url_exportar('clientes', ['q' => 'x']). */
function url_exportar(string $tipo, array $params = []): string
{
    return url_con_query('/exportar/' . $tipo . '.csv', $params);
}

/** URL pública del portal de un cliente (con dominio). $baseApp = app.url sin barra final. */
function url_portal(string $baseApp, string $token): string
{
    return $baseApp . '/portal/' . rawurlencode($token);
}

/** URL pública del webhook de Mercado Pago de un usuario (con dominio). */
function url_webhook_mp(string $baseApp, string $token): string
{
    return $baseApp . '/webhook/mp/' . rawurlencode($token);
}

// ---------------------------------------------------------------------------------------------------------
// Cabeceras de seguridad y manejo de errores (se usan en TODAS las respuestas, desde index.php)
// ---------------------------------------------------------------------------------------------------------

/** ¿El pedido llegó por HTTPS? (también detrás del proxy del hosting, que informa X-Forwarded-Proto). */
function es_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/**
 * Cabeceras de seguridad. CSP sin 'unsafe-inline' (todos los estilos son clases del CSS), HSTS solo con HTTPS,
 * y sin caché en lo que lleva datos ($dinamica). Los archivos estáticos reciben las suyas desde .htaccess.
 */
function cabeceras_seguridad(bool $dinamica = true): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    $csp = "default-src 'none'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self'; connect-src 'self'; "
        . "manifest-src 'self'; worker-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'";
    if (es_https()) {
        $csp .= '; upgrade-insecure-requests';
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header('Content-Security-Policy: ' . $csp);
    if ($dinamica) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}

/** Página de error genérica (sin rutas, versiones ni detalles). El detalle va al log. Termina la ejecución. */
function pagina_error_500(string $mensaje = 'Ocurrió un error. Probá de nuevo en unos minutos.'): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error: $mensaje (ver privado/logs/php-error.log)\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $m = htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>Error — Moscode</title><link rel="stylesheet" href="' . htmlspecialchars(base_path(), ENT_QUOTES) . '/assets/css/offline.css"></head>'
        . '<body><main><h1>Algo salió mal</h1><p>' . $m . '</p></main></body></html>';
    exit(1);
}

/** Apaga display_errors, manda los errores a privado/logs y muestra una página genérica ante cualquier fallo no capturado. */
function iniciar_manejo_errores(): void
{
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    if (defined('RAIZ_PRIVADA')) {
        $dir = RAIZ_PRIVADA . '/logs';
        if (is_dir($dir) || @mkdir($dir, 0750)) {
            $log = $dir . '/php-error.log';
            if (is_file($log) && @filesize($log) > 5 * 1024 * 1024) {      // rotación simple: queda una copia vieja
                @rename($log, $log . '.1');
            }
            ini_set('error_log', $log);
        }
    }
    error_reporting(E_ALL);
    set_exception_handler(function (Throwable $e) {
        error_log('Excepción no capturada: ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine());
        pagina_error_500();
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && PHP_SAPI !== 'cli') {
            error_log('Error fatal: ' . $e['message'] . ' en ' . basename((string) $e['file']) . ':' . $e['line']);
            if (!headers_sent()) {
                pagina_error_500();
            }
        }
    });
}