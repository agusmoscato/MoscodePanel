<?php
/**
 * bootstrap.php — Arranque común: configuración, errores, zona horaria, sesión y librerías.
 *
 * Los scripts de cron definen SIN_SESION antes de incluirlo para no abrir sesión.
 */
declare(strict_types=1);

if (!defined('RAIZ_PRIVADA')) {
    define('RAIZ_PRIVADA', dirname(__DIR__));
}

date_default_timezone_set('America/Argentina/Buenos_Aires');
mb_internal_encoding('UTF-8');

// Los errores se registran en un log privado, nunca se muestran al visitante.
require_once __DIR__ . '/rutas.php';
iniciar_manejo_errores();

// --- Configuración (config.php) ---
$archivoConfig = RAIZ_PRIVADA . '/config.php';
if (!is_file($archivoConfig)) {
    error_log('Falta privado/config.php (copiá config.example.php como config.php y completalo).');
    pagina_error_500('Error de configuración del servidor.');
}
$GLOBALS['CONFIG'] = require $archivoConfig;

/** Lee un valor de config.php con notación de puntos: conf('db.host'). */
function conf(string $ruta, $porDefecto = null)
{
    $nodo = $GLOBALS['CONFIG'];
    foreach (explode('.', $ruta) as $clave) {
        if (!is_array($nodo) || !array_key_exists($clave, $nodo)) {
            return $porDefecto;
        }
        $nodo = $nodo[$clave];
    }
    return $nodo;
}

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/rutas.php';
require_once __DIR__ . '/cripto.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/recordar.php';
require_once __DIR__ . '/actividad.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/usuarios.php';
require_once __DIR__ . '/cotizacion.php';
require_once __DIR__ . '/cargos.php';
require_once __DIR__ . '/planes.php';
require_once __DIR__ . '/feriados.php';
require_once __DIR__ . '/smtp.php';
require_once __DIR__ . '/notificador.php';
require_once __DIR__ . '/resumen.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/redes.php';
require_once __DIR__ . '/precios.php';
require_once __DIR__ . '/consultas.php';
require_once __DIR__ . '/reportes.php';
require_once __DIR__ . '/exportar.php';
require_once __DIR__ . '/mercadopago.php';
require_once __DIR__ . '/ui.php';

// --- Web (no CLI): cabeceras de seguridad y sesión ---
if (PHP_SAPI !== 'cli') {
    cabeceras_seguridad();

    if (!defined('SIN_SESION')) {
        $https = es_https();
        session_name('panel_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        // Sesiones en una carpeta propia: en hosting compartido el recolector de basura de PHP comparte la carpeta del
        // sistema con otros sitios y puede borrar sesiones antes de tiempo. Si algo igual las pierde, la cookie de
        // "recordarme" crea la sesión de nuevo.
        $dirSesiones = RAIZ_PRIVADA . '/sesiones';
        if (is_dir($dirSesiones) || @mkdir($dirSesiones, 0700)) {
            if (!is_file($dirSesiones . '/.htaccess')) {
                @file_put_contents($dirSesiones . '/.htaccess', "Require all denied\n");
            }
            if (is_writable($dirSesiones)) {
                session_save_path($dirSesiones);
            }
        }
        ini_set('session.gc_maxlifetime', (string) (SESION_INACTIVIDAD + 86400));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_cache_limiter('nocache');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }
}

/** ¿Es un token de verdad? (mínimo 16 caracteres y no el texto de ejemplo "CAMBIAR..."). */
function token_configurado(string $token): bool
{
    return strlen($token) >= 16 && stripos($token, 'CAMBIAR') !== 0;
}

/**
 * Protege los scripts de cron: solo CLI, o por web con el token de config.php (app.cron_token, mínimo 32 caracteres),
 * enviado en la cabecera X-Cron-Token (recomendado: no queda en los logs de acceso) o en ?token= (compatible, pero
 * el token SÍ queda en los logs: usá CLI siempre que puedas). Los intentos con token incorrecto se limitan por IP.
 * Termina la ejecución si no corresponde.
 */
function cron_proteger(): void
{
    if (PHP_SAPI === 'cli') {
        set_time_limit(0);
        return;
    }
    $ip = function_exists('ip_cliente') ? ip_cliente() : '';
    $desde = date('Y-m-d H:i:s', time() - 900);
    if ((int) valor("SELECT COUNT(*) FROM login_intentos WHERE usuario = 'cron' AND exitoso = 0 AND ip = ? AND creado_en >= ?", [$ip, $desde]) >= 10) {
        http_response_code(429);
        exit('Demasiados intentos');
    }
    $esperado = (string) conf('app.cron_token', '');
    $recibido = (string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? $_GET['token'] ?? '');
    if (strlen($esperado) < 32 || stripos($esperado, 'CAMBIAR') === 0 || !hash_equals($esperado, $recibido)) {
        q("INSERT INTO login_intentos (ip, usuario, exitoso, creado_en) VALUES (?, 'cron', 0, NOW())", [$ip]);
        http_response_code(403);
        exit('Acceso denegado');
    }
    set_time_limit(300);
    header('Content-Type: text/plain; charset=utf-8');
}

/** ¿El cron se está ejecutando por web (no por consola)? Por web no se muestran nombres de usuario ni se permite forzar. */
function cron_es_web(): bool
{
    return PHP_SAPI !== 'cli';
}

/** Cómo nombrar a un usuario en la salida de un cron: su nombre por consola, solo el número por web. */
function cron_etiqueta(array $u): string
{
    return cron_es_web() ? '#' . $u['id'] : (string) $u['usuario'];
}

/**
 * Evita que dos ejecuciones de la misma tarea corran a la vez (por consola, por web o una demorada que se pisa con la
 * siguiente): toma un lock de MySQL que se libera solo al terminar el script. Si ya hay otra corriendo, sale.
 */
function cron_bloquear(string $tarea): void
{
    if ((int) valor('SELECT GET_LOCK(?, 0)', ['moscode_cron_' . $tarea]) !== 1) {
        echo date('Y-m-d H:i:s') . " — $tarea: ya hay otra ejecución en curso, no se hace nada.\n";
        exit(0);
    }
}
