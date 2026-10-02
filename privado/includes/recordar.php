<?php
/**
 * recordar.php — "Mantener sesión iniciada": token de recordarme con selector + validador.
 *
 * Cómo funciona:
 *  - Al iniciar sesión con la casilla marcada se crea una fila en sesiones_recordar y una cookie
 *    "selector:validador" (HttpOnly, Secure, SameSite=Lax, 90 días por defecto). En la base solo queda el
 *    HASH del validador: con la base sola no se puede armar una cookie válida.
 *  - Si la sesión de PHP no existe (se cerró el navegador, la app instalada se reinició, el hosting borró la
 *    sesión) o venció por inactividad, la cookie crea una sesión nueva sin pasar por el login. El validador se
 *    ROTA en cada uso y el vencimiento se extiende (90 días desde el último uso). Como el celular puede mandar
 *    varios pedidos juntos con la cookie vieja, el validador anterior vale 60 segundos más.
 *  - Selector válido con validador incorrecto = cookie robada (o reutilizada): se revocan TODAS las sesiones
 *    de ese usuario (se borran sus tokens y sube sesion_version, que cierra las sesiones de PHP abiertas).
 *  - Cada token guarda la sesion_version del usuario: cambiar o resetear la contraseña, o desactivar la
 *    cuenta, la sube y todos los tokens quedan inválidos.
 *  - La sesión de PHP recuerda el id de su token ($_SESSION['rid']): si desde "Mi cuenta" se cierra ese
 *    dispositivo, la sesión que ya estaba abierta también cae en el siguiente pedido.
 *
 * La duración se configura en config.php → seguridad.recordar_dias (1 a 365, por defecto 90).
 * Estas consultas son globales a propósito (el usuario todavía no está identificado): llevan el usuario
 * explícito y van dentro de sin_filtro().
 */
declare(strict_types=1);

const RECORDAR_COOKIE = 'panel_recordar';
const RECORDAR_GRACIA_SEG = 60;        // el validador anterior sigue valiendo este tiempo tras rotar
const RECORDAR_TOQUE_SEG = 600;        // cada cuánto se actualiza "último uso" mientras la sesión está activa
const RECORDAR_EXTENDER_SEG = 86400;   // cada cuánto se extiende el vencimiento mientras la sesión está activa
const RECORDAR_VIDA_MAX_DIAS = 180;    // tope absoluto desde que se creó: pasado ese tiempo hay que ingresar con la contraseña

function recordar_dias(): int
{
    return max(1, min(365, (int) conf('seguridad.recordar_dias', 90)));
}

function recordar_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function recordar_cookie_poner(string $valor, int $vence): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    setcookie(RECORDAR_COOKIE, $valor, [
        'expires' => $vence, 'path' => base_path() === '' ? '/' : base_path() . '/',
        'secure' => recordar_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    $_COOKIE[RECORDAR_COOKIE] = $valor;
}

function recordar_cookie_borrar(): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent() && isset($_COOKIE[RECORDAR_COOKIE])) {   // solo si el navegador la mandó
        setcookie(RECORDAR_COOKIE, '', [
            'expires' => time() - 3600, 'path' => base_path() === '' ? '/' : base_path() . '/',
            'secure' => recordar_https(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    unset($_COOKIE[RECORDAR_COOKIE]);
}

/** [selector, validador] de la cookie, o null si no hay o tiene mal formato. */
function recordar_cookie_leer(): ?array
{
    $c = (string) ($_COOKIE[RECORDAR_COOKIE] ?? '');
    return preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $c, $m) ? [$m[1], $m[2]] : null;
}

/** "Chrome · Android": navegador y sistema a partir del User-Agent (para la lista de dispositivos). */
function recordar_dispositivo(string $ua): string
{
    $navegador = match (true) {
        (bool) preg_match('~Edg(e|A|iOS)?/~', $ua) => 'Edge',
        (bool) preg_match('~OPR/|Opera~', $ua)     => 'Opera',
        (bool) preg_match('~SamsungBrowser~', $ua) => 'Samsung Internet',
        (bool) preg_match('~Firefox|FxiOS~', $ua)  => 'Firefox',
        (bool) preg_match('~Chrome|CriOS~', $ua)   => 'Chrome',
        (bool) preg_match('~Safari~', $ua)         => 'Safari',
        default                                     => 'Navegador',
    };
    $sistema = match (true) {
        (bool) preg_match('~Android~', $ua)        => 'Android',
        (bool) preg_match('~iPhone~', $ua)         => 'iPhone',
        (bool) preg_match('~iPad~', $ua)           => 'iPad',
        (bool) preg_match('~Windows~', $ua)        => 'Windows',
        (bool) preg_match('~Mac OS X|Macintosh~', $ua) => 'Mac',
        (bool) preg_match('~CrOS~', $ua)           => 'ChromeOS',
        (bool) preg_match('~Linux~', $ua)          => 'Linux',
        default                                     => 'dispositivo desconocido',
    };
    return $navegador . ' · ' . $sistema;
}

function recordar_ua(): string
{
    return recordar_dispositivo((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/** Crea el token de este dispositivo para el usuario y deja la cookie. Devuelve el id del token. */
function recordar_crear(int $uid, int $sesionVersion): int
{
    $selector = bin2hex(random_bytes(12));
    $validador = bin2hex(random_bytes(32));
    $vence = time() + recordar_dias() * 86400;
    $id = sin_filtro('recordarme: el token se crea para el usuario recién autenticado', function () use ($uid, $sesionVersion, $selector, $validador, $vence) {
        q(
            'INSERT INTO sesiones_recordar (usuario_id, selector, validador_hash, sesion_version, dispositivo, ip, creado_en, ultimo_uso_en, vence_en)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)',
            [$uid, $selector, hash('sha256', $validador), $sesionVersion, recordar_ua(), ip_cliente(), date('Y-m-d H:i:s', $vence)]
        );
        $nuevoId = (int) db()->lastInsertId();                                                  // antes del DELETE (lo pone en 0)
        q('DELETE FROM sesiones_recordar WHERE vence_en < NOW() AND usuario_id = ?', [$uid]);   // limpieza de vencidos
        return $nuevoId;
    });
    recordar_cookie_poner($selector . ':' . $validador, $vence);
    $_SESSION['rid'] = $id;
    $_SESSION['rid_toque'] = time();
    return $id;
}

/** Borra los tokens de un usuario (todos, o todos menos uno). */
function recordar_revocar_usuario(int $uid, ?int $excepto = null): void
{
    sin_filtro('recordarme: revocar los tokens de un usuario', function () use ($uid, $excepto) {
        if ($excepto !== null) {
            q('DELETE FROM sesiones_recordar WHERE usuario_id = ? AND id <> ?', [$uid, $excepto]);
        } else {
            q('DELETE FROM sesiones_recordar WHERE usuario_id = ?', [$uid]);
        }
    });
}

/** Cierre de sesión: se olvida ESTE dispositivo (el token de la sesión y/o el de la cookie). */
function recordar_olvidar_actual(bool $borrarCookie = true): void
{
    $rid = (int) ($_SESSION['rid'] ?? 0);
    $c = recordar_cookie_leer();
    sin_filtro('recordarme: olvidar este dispositivo', function () use ($rid, $c) {
        if ($rid > 0) {
            q('DELETE FROM sesiones_recordar WHERE id = ?', [$rid]);
        }
        if ($c) {
            q('DELETE FROM sesiones_recordar WHERE selector = ?', [$c[0]]);
        }
    });
    if ($borrarCookie) {
        recordar_cookie_borrar();
    }
}

/**
 * Intenta crear una sesión de PHP a partir de la cookie de recordarme. Devuelve la fila del usuario
 * o null (y limpia la cookie si no sirve). Rota el validador. Detecta cookies robadas.
 */
function recordar_intentar(): ?array
{
    static $intentado = false;
    if ($intentado) {
        return null;
    }
    $intentado = true;
    $c = recordar_cookie_leer();
    if (!$c) {
        if (isset($_COOKIE[RECORDAR_COOKIE])) {
            recordar_cookie_borrar();
        }
        return null;
    }
    [$selector, $validador] = $c;
    $fila = sin_filtro('recordarme: buscar el token por selector', fn() => fila('SELECT * FROM sesiones_recordar WHERE selector = ?', [$selector]));
    if (!$fila) {
        recordar_cookie_borrar();
        return null;
    }
    $uid = (int) $fila['usuario_id'];
    if (strtotime((string) $fila['vence_en']) < time() || strtotime((string) $fila['creado_en']) + RECORDAR_VIDA_MAX_DIAS * 86400 < time()) {
        recordar_revocar_token((int) $fila['id']);
        recordar_cookie_borrar();
        return null;
    }
    $hash = hash('sha256', $validador);
    $vigente = hash_equals((string) $fila['validador_hash'], $hash);
    $enGracia = !$vigente && $fila['validador_anterior_hash'] !== null && $fila['rotado_en'] !== null
        && hash_equals((string) $fila['validador_anterior_hash'], $hash)
        && time() - strtotime((string) $fila['rotado_en']) <= RECORDAR_GRACIA_SEG;
    if (!$vigente && !$enGracia) {
        // Selector real con validador equivocado: la cookie se copió o ya se usó. Se cierra todo lo de ese usuario.
        error_log("Sesión recordada: validador incorrecto para el usuario $uid desde " . ip_cliente() . ' — se revocan todas sus sesiones');
        registrar_actividad('token_robado', 'Un dispositivo presentó un validador incorrecto', $uid);
        recordar_revocar_usuario($uid);
        sin_filtro('recordarme: posible robo, se invalidan las sesiones abiertas', fn() => q('UPDATE usuarios SET sesion_version = sesion_version + 1 WHERE id = ?', [$uid]));
        recordar_cookie_borrar();
        return null;
    }
    $u = fila('SELECT id, usuario, nombre, email, rol, activo, debe_cambiar_clave, sesion_version FROM usuarios WHERE id = ?', [$uid]);
    if (!$u || !(int) $u['activo'] || (int) $u['sesion_version'] !== (int) $fila['sesion_version']) {
        recordar_revocar_token((int) $fila['id']);
        recordar_cookie_borrar();
        return null;
    }

    // Todo en orden: sesión nueva de PHP
    session_regenerate_id(true);
    unset($_SESSION['csrf'], $_SESSION['pre2fa']);
    $_SESSION['uid'] = $uid;
    $_SESSION['sv'] = (int) $u['sesion_version'];
    $_SESSION['ultimo'] = time();
    $_SESSION['rid'] = (int) $fila['id'];
    $_SESSION['rid_toque'] = time();
    $vence = time() + recordar_dias() * 86400;
    $rotada = false;
    if ($vigente) {
        $nuevo = bin2hex(random_bytes(32));
        // UPDATE condicional: si otro pedido paralelo ya rotó el validador, este no lo pisa (y no manda cookie nueva)
        $rotada = sin_filtro('recordarme: rotar el validador', fn() => q(
            'UPDATE sesiones_recordar SET validador_anterior_hash = validador_hash, validador_hash = ?, rotado_en = NOW(),
                    ultimo_uso_en = NOW(), ip = ?, dispositivo = ?, vence_en = ? WHERE id = ? AND validador_hash = ?',
            [hash('sha256', $nuevo), ip_cliente(), recordar_ua(), date('Y-m-d H:i:s', $vence), $fila['id'], $fila['validador_hash']]
        )->rowCount()) === 1;
        if ($rotada) {
            recordar_cookie_poner($selector . ':' . $nuevo, $vence);
        }
    }
    if (!$rotada) {
        sin_filtro('recordarme: pedido dentro de la gracia o rotado en paralelo', fn() => q('UPDATE sesiones_recordar SET ultimo_uso_en = NOW(), ip = ? WHERE id = ?', [ip_cliente(), $fila['id']]));
    }
    sin_filtro('recordarme: último ingreso', fn() => q('UPDATE usuarios SET ultimo_login_en = NOW() WHERE id = ?', [$uid]));
    return $u;
}

function recordar_revocar_token(int $id): void
{
    sin_filtro('recordarme: borrar un token', fn() => q('DELETE FROM sesiones_recordar WHERE id = ?', [$id]));
}

/**
 * Con la sesión activa (usuario ya en contexto): verifica que el dispositivo no haya sido cerrado desde
 * "Mi cuenta" y actualiza "último uso" y el vencimiento de vez en cuando. Devuelve false si el token ya no existe.
 */
function recordar_sesion_viva(): bool
{
    $rid = (int) ($_SESSION['rid'] ?? 0);
    if ($rid === 0) {
        return true;       // sesión sin "recordarme"
    }
    $f = fila('SELECT id, vence_en, ultimo_uso_en FROM sesiones_recordar WHERE id = ? AND usuario_id = {U}', [$rid]);
    if (!$f) {
        return false;
    }
    if (time() - (int) ($_SESSION['rid_toque'] ?? 0) >= RECORDAR_TOQUE_SEG) {
        $_SESSION['rid_toque'] = time();
        $extender = time() - strtotime((string) $f['ultimo_uso_en']) >= RECORDAR_EXTENDER_SEG;
        $vence = time() + recordar_dias() * 86400;
        if ($extender) {
            q('UPDATE sesiones_recordar SET ultimo_uso_en = NOW(), ip = ?, vence_en = ? WHERE id = ? AND usuario_id = {U}', [ip_cliente(), date('Y-m-d H:i:s', $vence), $rid]);
            if ($c = recordar_cookie_leer()) {
                recordar_cookie_poner($c[0] . ':' . $c[1], $vence);   // misma cookie, vencimiento nuevo
            }
        } else {
            q('UPDATE sesiones_recordar SET ultimo_uso_en = NOW(), ip = ? WHERE id = ? AND usuario_id = {U}', [ip_cliente(), $rid]);
        }
    }
    return true;
}

/** Dispositivos con sesión iniciada del usuario en contexto, el más reciente primero. */
function recordar_dispositivos(): array
{
    $actual = (int) ($_SESSION['rid'] ?? 0);
    $filas = filas('SELECT id, dispositivo, ip, creado_en, ultimo_uso_en, vence_en FROM sesiones_recordar WHERE usuario_id = {U} AND vence_en > NOW() ORDER BY ultimo_uso_en DESC');
    foreach ($filas as &$f) {
        $f['actual'] = (int) $f['id'] === $actual;
    }
    return $filas;
}
