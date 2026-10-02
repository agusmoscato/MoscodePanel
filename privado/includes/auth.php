<?php
/**
 * auth.php — Login, sesión, roles y bloqueo temporal por intentos fallidos.
 *
 * - Roles: "admin" (administra cuentas, no ve datos de clientes ajenos) y "usuario".
 * - Cada usuario tiene una sesion_version: al cambiar su contraseña (o si el admin se la resetea) se
 *   incrementa y las demás sesiones abiertas dejan de ser válidas.
 * - Con debe_cambiar_clave = 1 (contraseña temporal), el panel solo deja usar la pantalla de cambio.
 * - Al autenticarse, el id del usuario queda "en contexto" (fijar_usuario): ahí se apoya el aislamiento de datos.
 */
declare(strict_types=1);

const LOGIN_MAX_INTENTOS = 5;      // fallos permitidos...
const LOGIN_VENTANA_MIN  = 15;     // ...dentro de estos minutos
const SESION_INACTIVIDAD = 28800;  // 8 horas sin actividad cierran la sesión
const CLAVE_MIN_LARGO    = 10;     // contraseñas definitivas
const CLAVE_TEMP_MIN     = 8;      // contraseñas temporales (las asigna el admin)
const CLAVE_MAX_BYTES    = 72;     // bcrypt ignora lo que pase de 72 bytes: se rechaza en vez de truncar en silencio
// Hash de una clave que nadie conoce: se verifica contra él cuando el usuario no existe (tiempo de respuesta igual)
const HASH_FALSO = '$2y$10$BdtK6uGKNesGBrOIRhZ8ieJ7cmGDMohL4zlJ1Erxq0IGeZj5vBCP2';

/**
 * IP del visitante. Por defecto solo REMOTE_ADDR: la cabecera X-Forwarded-For la puede escribir cualquiera.
 * Si el sitio está detrás de un proxy o CDN y REMOTE_ADDR es siempre la IP del proxy (se nota en "Mi cuenta":
 * todos los dispositivos aparecen con la misma IP), se lista esa IP en config.php → app.proxies_confiables y recién
 * entonces se lee X-Forwarded-For, de derecha a izquierda, salteando los proxies conocidos.
 */
function ip_cliente(): string
{
    $remota = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $confiables = conf('app.proxies_confiables', []);
    if (is_array($confiables) && $confiables && in_array($remota, $confiables, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $cadena = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
        foreach ($cadena as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false && !in_array($ip, $confiables, true)) {
                return substr($ip, 0, 45);
            }
        }
    }
    return $remota;
}

/**
 * Usuario logueado (o null). Valida actividad, cuenta activa y versión de sesión, y fija el contexto.
 * Si no hay sesión de PHP (o venció por inactividad) y el dispositivo tiene la cookie de "recordarme"
 * válida, crea una sesión nueva sin pasar por el login (ver recordar.php).
 */
function usuario_actual(): ?array
{
    static $cache = null;
    static $cacheUid = 0;
    if (defined('SIN_SESION')) {
        return null;
    }
    if (!empty($_SESSION['uid']) && time() - (int) ($_SESSION['ultimo'] ?? 0) > SESION_INACTIVIDAD) {
        // Venció por inactividad: con "recordarme" se recrea; sin él, afuera
        if (recordar_cookie_leer()) {
            unset($_SESSION['uid'], $_SESSION['sv'], $_SESSION['rid']);
        } else {
            cerrar_sesion();
            $cache = null;
            return null;
        }
    }
    if (empty($_SESSION['uid'])) {
        $r = recordar_cookie_leer() ? recordar_intentar() : null;
        if ($r === null) {
            return null;
        }
        $cache = null;     // sesión recién creada desde la cookie: se valida abajo como cualquier otra
    }
    $_SESSION['ultimo'] = time();
    if ($cache !== null && $cacheUid === (int) $_SESSION['uid']) {
        return $cache;
    }
    $u = fila(
        'SELECT id, usuario, nombre, email, rol, activo, debe_cambiar_clave, sesion_version FROM usuarios WHERE id = ?',
        [$_SESSION['uid']]
    );
    // Cuenta desactivada o sesión de una versión anterior (se cambió la contraseña en otro lado): afuera
    if (!$u || !(int) $u['activo'] || (int) $u['sesion_version'] !== (int) ($_SESSION['sv'] ?? 0)) {
        cerrar_sesion();
        return null;
    }
    fijar_usuario((int) $u['id']);
    // Dispositivo cerrado desde "Mi cuenta": la sesión que ya estaba abierta cae también
    if (!recordar_sesion_viva()) {
        cerrar_sesion();
        return null;
    }
    $cache = $u;
    $cacheUid = (int) $u['id'];
    return $u;
}

/**
 * Exige sesión iniciada; si no, manda al login. Con contraseña temporal, manda a la pantalla de cambio
 * (salvo que $permitirCambioClave sea true: lo usan esa pantalla y su acción).
 */
function require_login(bool $permitirCambioClave = false): array
{
    $u = usuario_actual();
    if (!$u) {
        redirigir(url_base('/login'));
    }
    if ((int) $u['debe_cambiar_clave'] === 1 && !$permitirCambioClave) {
        redirigir(url('cambiar_clave'));
    }
    return $u;
}

function es_admin(?array $u = null): bool
{
    $u ??= usuario_actual();
    return $u !== null && $u['rol'] === 'admin';
}

/** Corta con 403 si no es admin (acciones y pantallas de administración). */
function exigir_admin(): array
{
    $u = usuario_actual();
    if (!$u || $u['rol'] !== 'admin') {
        http_response_code(403);
        exit('Solo el administrador puede hacer esto.');
    }
    return $u;
}

/**
 * ¿Hay que frenar este intento de login? Límites (ventana de LOGIN_VENTANA_MIN minutos):
 *  - por PAR usuario+IP: LOGIN_MAX_INTENTOS fallos → bloqueo. Así nadie puede dejar afuera a otra persona
 *    escribiendo su usuario desde otra IP (el bloqueo es de esa IP para ese usuario);
 *  - por IP (cualquier usuario): 20 fallos → bloqueo;
 *  - por usuario desde muchas IP: 30 fallos → NO se bloquea, solo se demora la respuesta (frena la fuerza bruta
 *    distribuida sin permitir el bloqueo deliberado de una cuenta).
 * Los intentos del portal y de "clave actual" se cuentan aparte.
 */
function login_bloqueado(string $usuario): bool
{
    $desde = date('Y-m-d H:i:s', time() - LOGIN_VENTANA_MIN * 60);
    $f = fila(
        "SELECT COALESCE(SUM(ip = ? AND usuario NOT IN ('portal', 'portal_pagar', 'cron') AND usuario NOT LIKE 'clave:%'), 0) AS por_ip,
                COALESCE(SUM(ip = ? AND usuario = ?), 0) AS par,
                COALESCE(SUM(usuario = ?), 0) AS por_usuario
         FROM login_intentos WHERE exitoso = 0 AND creado_en >= ?",
        [ip_cliente(), ip_cliente(), $usuario, $usuario, $desde]
    );
    if ((int) ($f['por_usuario'] ?? 0) >= 30) {
        usleep(1500000);
    }
    return (int) ($f['par'] ?? 0) >= LOGIN_MAX_INTENTOS || (int) ($f['por_ip'] ?? 0) >= 20;
}

/**
 * Intenta iniciar sesión. Devuelve [ok, mensaje]; con mensaje '2fa' la clave estuvo bien pero falta el segundo paso.
 */
function intentar_login(string $usuario, string $clave, bool $recordar = false): array
{
    $usuario = mb_substr($usuario, 0, 60);
    if (login_bloqueado($usuario)) {
        return [false, 'Demasiados intentos fallidos. Esperá ' . LOGIN_VENTANA_MIN . ' minutos e intentá de nuevo.'];
    }

    $u = fila('SELECT id, password_hash, activo, sesion_version, totp_activo FROM usuarios WHERE usuario = ?', [$usuario]);
    // Una sola verificación bcrypt en los dos casos (con el hash falso si el usuario no existe): mismo tiempo de respuesta.
    $hash = $u['password_hash'] ?? HASH_FALSO;
    $ok = password_verify($clave, $hash) && $u !== null && (int) $u['activo'] === 1;

    q('INSERT INTO login_intentos (ip, usuario, exitoso, creado_en) VALUES (?, ?, ?, NOW())', [ip_cliente(), $usuario, $ok ? 1 : 0]);
    if (random_int(1, 50) === 1) {
        q('DELETE FROM login_intentos WHERE creado_en < DATE_SUB(NOW(), INTERVAL 7 DAY)');
    }

    if (!$ok) {
        if ($u !== null) {
            registrar_actividad('login_fallo', '', (int) $u['id']);
        }
        return [false, 'Usuario o contraseña incorrectos.'];
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
    }
    if ((int) $u['totp_activo'] === 1) {
        // Segundo paso: todavía NO hay sesión iniciada, solo un pase de 5 minutos para ingresar el código
        session_regenerate_id(true);
        $_SESSION['pre2fa'] = ['uid' => (int) $u['id'], 'usuario' => $usuario, 'recordar' => $recordar, 'hasta' => time() + 300, 'intentos' => 0];
        return [true, '2fa'];
    }
    completar_login($u, $recordar);
    return [true, ''];
}

/** Cierra la sesión de este dispositivo (y borra su token de "recordarme"). */
function cerrar_sesion(): void
{
    recordar_olvidar_actual();
    fijar_usuario(null);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], true);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Cambia la contraseña del usuario logueado. Quita la marca de "temporal" y cierra las demás sesiones
 * (sube sesion_version); la sesión actual sigue abierta. Devuelve null si salió bien o el mensaje de error.
 */
function cambiar_clave_propia(int $uid, string $actual, string $nueva, string $nueva2): ?string
{
    if ($err = verificar_clave_propia($uid, $actual)) {
        return $err;
    }
    $u = fila('SELECT id, usuario, sesion_version FROM usuarios WHERE id = ?', [$uid]);
    if (!$u) {
        return 'La cuenta no existe.';
    }
    if ($err = validar_clave_nueva($nueva, (string) $u['usuario'])) {
        return $err;
    }
    if ($nueva !== $nueva2) {
        return 'Las contraseñas nuevas no coinciden.';
    }
    if (hash_equals($actual, $nueva)) {
        return 'La contraseña nueva tiene que ser distinta de la actual.';
    }
    // sesion_version se suma en la base (no se lee y se reescribe): dos cambios simultáneos no se pisan
    q(
        'UPDATE usuarios SET password_hash = ?, debe_cambiar_clave = 0, sesion_version = sesion_version + 1 WHERE id = ?',
        [password_hash($nueva, PASSWORD_DEFAULT), $uid]
    );
    $version = (int) valor('SELECT sesion_version FROM usuarios WHERE id = ?', [$uid]);
    $teniaToken = (int) ($_SESSION['rid'] ?? 0) > 0;
    recordar_revocar_usuario($uid);      // los demás dispositivos tienen que volver a iniciar sesión
    session_regenerate_id(true);
    $_SESSION['sv'] = $version;       // esta sesión sigue; las otras quedan inválidas
    unset($_SESSION['rid'], $_SESSION['rid_toque']);
    if ($teniaToken) {
        recordar_crear($uid, $version);   // este dispositivo sigue recordado, con un token nuevo
    }
    registrar_actividad('clave_cambiada', '', $uid);
    return null;
}

/** Inicia la sesión de un usuario ya verificado (clave y, si corresponde, segundo paso). */
function completar_login(array $u, bool $recordar, string $via = 'clave'): void
{
    session_regenerate_id(true);   // evita fijación de sesión
    unset($_SESSION['csrf'], $_SESSION['pre2fa']);   // el token CSRF de la sesión sin login no pasa a la sesión autenticada
    $_SESSION['uid'] = (int) $u['id'];
    $_SESSION['sv'] = (int) $u['sesion_version'];
    $_SESSION['ultimo'] = time();
    q('UPDATE usuarios SET ultimo_login_en = NOW() WHERE id = ?', [$u['id']]);
    unset($_SESSION['rid'], $_SESSION['rid_toque']);
    recordar_olvidar_actual(!$recordar);            // si quedaba un token de otra cuenta en este dispositivo (la cookie nueva lo reemplaza)
    if ($recordar) {
        recordar_crear((int) $u['id'], (int) $u['sesion_version']);
    }
    registrar_actividad('login_ok', $via === 'clave' ? '' : $via, (int) $u['id']);
}

/**
 * Segundo paso del login: código TOTP o código de recuperación. Devuelve [ok, mensaje, reiniciar]:
 * reiniciar = true cuando hay que volver a poner usuario y contraseña (venció el pase o se agotaron los intentos).
 */
function completar_login_2fa(string $codigo): array
{
    $p = $_SESSION['pre2fa'] ?? null;
    if (!is_array($p) || (int) $p['hasta'] < time()) {
        unset($_SESSION['pre2fa']);
        return [false, 'La verificación venció. Volvé a iniciar sesión.', true];
    }
    $uid = (int) $p['uid'];
    $clave2fa = '2fa:' . $p['usuario'];
    if (login_bloqueado($clave2fa)) {
        return [false, 'Demasiados intentos. Esperá ' . LOGIN_VENTANA_MIN . ' minutos.', true];
    }
    $u = fila('SELECT id, activo, sesion_version, totp_activo FROM usuarios WHERE id = ?', [$uid]);
    if (!$u || !(int) $u['activo'] || !(int) $u['totp_activo']) {
        unset($_SESSION['pre2fa']);
        return [false, 'No se pudo verificar. Volvé a iniciar sesión.', true];
    }
    $codigo = trim($codigo);
    $esTotp = (bool) preg_match('/^\s*\d{3}\s?\d{3}\s*$/', $codigo);
    $valido = $esTotp ? totp_validar_usuario($uid, $codigo) : con_usuario($uid, fn() => totp_usar_recuperacion($codigo));
    q('INSERT INTO login_intentos (ip, usuario, exitoso, creado_en) VALUES (?, ?, ?, NOW())', [ip_cliente(), $clave2fa, $valido ? 1 : 0]);
    if (!$valido) {
        registrar_actividad('login_2fa_fallo', '', $uid);
        $_SESSION['pre2fa']['intentos'] = (int) $p['intentos'] + 1;
        if ($_SESSION['pre2fa']['intentos'] >= 5) {
            unset($_SESSION['pre2fa']);
            return [false, 'Demasiados códigos incorrectos. Volvé a iniciar sesión.', true];
        }
        return [false, 'El código no es correcto.', false];
    }
    completar_login($u, (bool) $p['recordar'], $esTotp ? 'clave + verificación en dos pasos' : 'clave + código de recuperación');
    if (!$esTotp) {
        registrar_actividad('codigo_recuperacion', 'Quedan ' . con_usuario($uid, fn() => totp_codigos_restantes()) . ' códigos', $uid);
    }
    return [true, '', false];
}

/**
 * Verifica la contraseña actual del usuario (para operaciones sensibles) con límite de intentos propio.
 * Devuelve null si es correcta o el mensaje de error.
 */
function verificar_clave_propia(int $uid, string $actual): ?string
{
    $desde = date('Y-m-d H:i:s', time() - LOGIN_VENTANA_MIN * 60);
    $n = (int) valor("SELECT COUNT(*) FROM login_intentos WHERE exitoso = 0 AND usuario = ? AND creado_en >= ?", ['clave:' . $uid, $desde]);
    if ($n >= LOGIN_MAX_INTENTOS) {
        return 'Demasiados intentos con la contraseña actual. Esperá ' . LOGIN_VENTANA_MIN . ' minutos.';
    }
    $hash = (string) valor('SELECT password_hash FROM usuarios WHERE id = ?', [$uid]);
    if ($hash === '' || !password_verify($actual, $hash)) {
        q('INSERT INTO login_intentos (ip, usuario, exitoso, creado_en) VALUES (?, ?, 0, NOW())', [ip_cliente(), 'clave:' . $uid]);
        registrar_actividad('clave_fallo', '', $uid);
        return 'La contraseña actual no es correcta.';
    }
    return null;
}

/** Política de contraseñas definitivas: largo, sin claves obvias y sin pasar el límite de bcrypt. Devuelve el error o null. */
function validar_clave_nueva(string $clave, string $usuario = ''): ?string
{
    if (mb_strlen($clave) < CLAVE_MIN_LARGO) {
        return 'La contraseña nueva debe tener al menos ' . CLAVE_MIN_LARGO . ' caracteres.';
    }
    if (strlen($clave) > CLAVE_MAX_BYTES) {
        return 'La contraseña es demasiado larga (máximo ' . CLAVE_MAX_BYTES . ' bytes, unos 70 caracteres).';
    }
    $minus = mb_strtolower($clave);
    if ($usuario !== '' && str_contains($minus, mb_strtolower($usuario))) {
        return 'La contraseña no puede contener tu nombre de usuario.';
    }
    $comunes = ['1234567890', '0123456789', 'contraseña', 'contrasena', 'password12', 'password123', 'qwertyuiop', '1q2w3e4r5t', 'abcdefghij',
        'abcd123456', 'iloveyou12', 'administrador', 'moscode123', 'argentina10', 'boca juniors', 'riverplate1', '1234567891', '12345678910'];
    foreach ($comunes as $c) {
        if (str_contains($minus, $c)) {
            return 'Esa contraseña es demasiado común. Elegí otra.';
        }
    }
    if (preg_match('/^(.)\1+$/u', $clave) || count(array_unique(mb_str_split($minus))) < 5) {
        return 'La contraseña es demasiado simple (repite muy pocos caracteres).';
    }
    return null;
}

/**
 * Verifica el código de la app (TOTP) o uno de recuperación del usuario logueado, con el mismo límite de intentos
 * que la contraseña actual. Devuelve null si es correcto o el mensaje de error.
 */
function verificar_codigo_2fa_propio(int $uid, string $codigo): ?string
{
    $desde = date('Y-m-d H:i:s', time() - LOGIN_VENTANA_MIN * 60);
    $n = (int) valor("SELECT COUNT(*) FROM login_intentos WHERE exitoso = 0 AND usuario = ? AND creado_en >= ?", ['clave:' . $uid, $desde]);
    if ($n >= LOGIN_MAX_INTENTOS) {
        return 'Demasiados intentos. Esperá ' . LOGIN_VENTANA_MIN . ' minutos.';
    }
    $esTotp = (bool) preg_match('/^\s*\d{3}\s?\d{3}\s*$/', $codigo);
    $ok = $esTotp ? totp_validar_usuario($uid, $codigo) : con_usuario($uid, fn() => totp_usar_recuperacion($codigo));
    if (!$ok) {
        q('INSERT INTO login_intentos (ip, usuario, exitoso, creado_en) VALUES (?, ?, 0, NOW())', [ip_cliente(), 'clave:' . $uid]);
        registrar_actividad('login_2fa_fallo', 'Código incorrecto en una operación de la cuenta', $uid);
        return 'El código no es correcto.';
    }
    return null;
}

/**
 * Cierra todas las demás sesiones del usuario (otros dispositivos, con o sin "recordarme") y deja abierta esta.
 * Sube sesion_version en la base (suma, no reescribe) y renueva la de esta sesión y la de su token.
 */
function cerrar_otras_sesiones(int $uid): void
{
    q('UPDATE usuarios SET sesion_version = sesion_version + 1 WHERE id = ?', [$uid]);
    $version = (int) valor('SELECT sesion_version FROM usuarios WHERE id = ?', [$uid]);
    $rid = (int) ($_SESSION['rid'] ?? 0);
    recordar_revocar_usuario($uid, $rid > 0 ? $rid : null);
    $_SESSION['sv'] = $version;
    if ($rid > 0) {
        sin_filtro('recordarme: este dispositivo sigue con la versión nueva', fn() => q('UPDATE sesiones_recordar SET sesion_version = ? WHERE id = ? AND usuario_id = ?', [$version, $rid, $uid]));
    }
}