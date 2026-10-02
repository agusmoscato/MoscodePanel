<?php
/**
 * usuarios.php — Alta, baja lógica y reseteo de contraseña de usuarios (lo usan la pantalla Usuarios
 * del admin y el script de consola privado/scripts/crear_usuario.php).
 *
 * Una cuenta nueva (o con la contraseña reseteada) queda con la contraseña TEMPORAL: el usuario está
 * obligado a cambiarla al iniciar sesión y no puede usar el panel hasta que lo haga.
 */
declare(strict_types=1);

const ROLES = ['admin' => 'Administrador', 'usuario' => 'Usuario'];

/** Contraseña aleatoria (sin caracteres ambiguos como 0/O o l/1), con mayúscula, minúscula y número. */
function generar_clave_aleatoria(int $largo = 12): string
{
    $grupos = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789'];
    $todo = implode('', $grupos);
    do {
        $c = '';
        for ($i = 0; $i < $largo; $i++) {
            $c .= $todo[random_int(0, strlen($todo) - 1)];
        }
        $ok = true;
        foreach ($grupos as $g) {
            $ok = $ok && strpbrk($c, $g) !== false;
        }
    } while (!$ok);
    return $c;
}

/**
 * Crea un usuario con contraseña temporal.
 * $d: usuario, nombre, email, rol, clave (opcional: si falta se genera una de 12 caracteres).
 * Devuelve ['ok' => bool, 'error' => ?string, 'id' => ?int, 'clave' => ?string].
 */
function crear_usuario(array $d): array
{
    $usuario = trim((string) ($d['usuario'] ?? ''));
    $nombre = trim((string) ($d['nombre'] ?? '')) ?: $usuario;
    $email = trim((string) ($d['email'] ?? ''));
    $rol = (string) ($d['rol'] ?? 'usuario');
    $clave = (string) ($d['clave'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $usuario)) {
        return ['ok' => false, 'error' => 'El usuario debe tener entre 3 y 60 caracteres (letras, números, punto, guion o guion bajo).'];
    }
    if (!isset(ROLES[$rol])) {
        return ['ok' => false, 'error' => 'El rol debe ser "admin" o "usuario".'];
    }
    if ($email !== '' && !email_valido($email)) {
        return ['ok' => false, 'error' => 'El email no es válido.'];
    }
    if ($clave !== '' && (mb_strlen($clave) < CLAVE_TEMP_MIN || strlen($clave) > CLAVE_MAX_BYTES)) {
        return ['ok' => false, 'error' => 'La contraseña temporal debe tener entre ' . CLAVE_TEMP_MIN . ' caracteres y ' . CLAVE_MAX_BYTES . ' bytes.'];
    }
    if (fila('SELECT id FROM usuarios WHERE usuario = ?', [$usuario])) {
        return ['ok' => false, 'error' => "El usuario \"$usuario\" ya existe."];
    }
    $generada = $clave === '';
    if ($generada) {
        $clave = generar_clave_aleatoria(12);
    }
    $id = insertar('usuarios', [
        'usuario' => $usuario,
        'password_hash' => password_hash($clave, PASSWORD_DEFAULT),
        'nombre' => mb_substr($nombre, 0, 120),
        'email' => mb_substr($email, 0, 160),
        'rol' => $rol,
        'activo' => 1,
        'debe_cambiar_clave' => 1,           // contraseña temporal: la tiene que cambiar al entrar
        'sesion_version' => 1,
        'webhook_token' => bin2hex(random_bytes(16)),
        'creado_en' => date('Y-m-d H:i:s'),
    ]);
    return ['ok' => true, 'error' => null, 'id' => $id, 'clave' => $clave, 'generada' => $generada];
}

/** Asigna una contraseña temporal (la genera si no se pasa), obliga a cambiarla y cierra sus sesiones. */
function resetear_clave(int $id, ?string $clave = null): array
{
    if (!fila('SELECT id FROM usuarios WHERE id = ?', [$id])) {
        return ['ok' => false, 'error' => 'El usuario no existe.'];
    }
    if ($clave !== null && $clave !== '' && mb_strlen($clave) < CLAVE_TEMP_MIN) {
        return ['ok' => false, 'error' => 'La contraseña temporal debe tener al menos ' . CLAVE_TEMP_MIN . ' caracteres.'];
    }
    $clave = ($clave === null || $clave === '') ? generar_clave_aleatoria(12) : $clave;
    q(
        'UPDATE usuarios SET password_hash = ?, debe_cambiar_clave = 1, sesion_version = sesion_version + 1 WHERE id = ?',
        [password_hash($clave, PASSWORD_DEFAULT), $id]
    );
    recordar_revocar_usuario($id);
    return ['ok' => true, 'error' => null, 'clave' => $clave];
}

function admins_activos(): int
{
    return (int) valor("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1");
}

/** Activa o desactiva una cuenta (desactivar cierra sus sesiones). No deja sin administradores. */
function cambiar_estado_usuario(int $id, bool $activo, int $quienLoHace): array
{
    $u = fila('SELECT id, rol, activo FROM usuarios WHERE id = ?', [$id]);
    if (!$u) {
        return ['ok' => false, 'error' => 'El usuario no existe.'];
    }
    if (!$activo) {
        if ($id === $quienLoHace) {
            return ['ok' => false, 'error' => 'No podés desactivar tu propia cuenta.'];
        }
        if ($u['rol'] === 'admin' && (int) $u['activo'] === 1 && admins_activos() <= 1) {
            return ['ok' => false, 'error' => 'No se puede desactivar al último administrador.'];
        }
    }
    q('UPDATE usuarios SET activo = ?, sesion_version = sesion_version + 1 WHERE id = ?', [$activo ? 1 : 0, $id]);
    recordar_revocar_usuario($id);
    return ['ok' => true, 'error' => null];
}

/** Usuarios activos (para los crons). Es una consulta global a propósito: recorre a todos. */
function usuarios_activos(): array
{
    return sin_filtro('cron: recorrer los usuarios activos', fn() => filas('SELECT id, usuario, nombre FROM usuarios WHERE activo = 1 ORDER BY id'));
}

/** Token de webhook del usuario (lo genera si todavía no tiene). */
function asegurar_webhook_token(int $uid): string
{
    $t = valor('SELECT webhook_token FROM usuarios WHERE id = ?', [$uid]);
    if (!$t) {
        $t = bin2hex(random_bytes(16));
        q('UPDATE usuarios SET webhook_token = ? WHERE id = ?', [$t, $uid]);
    }
    return (string) $t;
}

/**
 * Dueño de un webhook de Mercado Pago según el token de la URL. También acepta el token viejo de
 * config.php (mercadopago.webhook_token) y lo asigna al primer administrador, para no tener que
 * tocar la configuración que ya está en Mercado Pago.
 */
function usuario_por_webhook_token(string $token): ?array
{
    if (strlen($token) < 16) {
        return null;
    }
    $u = fila('SELECT id, usuario, activo FROM usuarios WHERE webhook_token = ?', [$token]);
    if ($u) {
        return $u;
    }
    $legado = (string) conf('mercadopago.webhook_token', '');
    if (token_configurado($legado) && hash_equals($legado, $token)) {
        return fila("SELECT id, usuario, activo FROM usuarios WHERE rol = 'admin' ORDER BY id LIMIT 1");
    }
    return null;
}
