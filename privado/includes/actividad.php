<?php
/**
 * actividad.php — Registro de actividad de seguridad.
 *
 * Guarda quién hizo qué sobre una cuenta: ingresos correctos y fallidos, cambios de contraseña, 2FA, acciones del
 * administrador, cambios de credenciales y anulaciones de pagos. Cada usuario ve el de SU cuenta; el administrador ve
 * el de las cuentas en general pero NUNCA los eventos con datos de clientes (visibilidad 'datos', ej. anular un pago).
 * Nunca se guardan contraseñas, tokens ni valores de credenciales: solo qué cambió.
 * Registrar es "a prueba de fallas": si algo sale mal se anota en el log de errores y la operación sigue.
 */
declare(strict_types=1);

const EVENTOS_ACTIVIDAD = [
    'login_ok'            => 'Ingreso correcto',
    'login_fallo'         => 'Intento de ingreso fallido',
    'login_bloqueado'     => 'Ingresos bloqueados por demasiados intentos',
    'login_2fa_fallo'     => 'Código de verificación incorrecto',
    'sesion_recordada'    => 'Sesión recreada con "mantener sesión iniciada"',
    'token_robado'        => 'Posible robo de sesión (se cerraron todas las sesiones)',
    'logout'              => 'Cierre de sesión',
    'clave_cambiada'      => 'Contraseña cambiada',
    'clave_fallo'         => 'Contraseña actual incorrecta al intentar un cambio',
    'clave_reseteada'     => 'Contraseña reseteada por un administrador',
    'email_cambiado'      => 'Email de la cuenta cambiado',
    'dos_pasos_activado'  => 'Verificación en dos pasos activada',
    'dos_pasos_desactivado' => 'Verificación en dos pasos desactivada',
    'dos_pasos_quitado'   => 'Verificación en dos pasos quitada por un administrador',
    'codigos_regenerados' => 'Códigos de recuperación regenerados',
    'codigo_recuperacion' => 'Ingreso con un código de recuperación',
    'usuario_creado'      => 'Cuenta creada',
    'usuario_activado'    => 'Cuenta activada',
    'usuario_desactivado' => 'Cuenta desactivada',
    'credenciales'        => 'Credenciales de canales modificadas',
    'sesion_cerrada'      => 'Dispositivo cerrado desde Mi cuenta',
    'sesiones_cerradas'   => 'Todas las demás sesiones cerradas',
    'cotizacion_confirmada' => 'Cotización pendiente confirmada',
    'cotizacion_descartada' => 'Cotización pendiente descartada',
    'backup_creado'       => 'Backup de la base creado',
    'pago_anulado'        => 'Pago anulado',
];

/**
 * Registra un evento. $uid = cuenta afectada (por defecto la del contexto), $actor = quién lo hizo (por defecto la
 * misma cuenta). $visibilidad: 'cuenta' (lo ve también el admin) o 'datos' (solo el dueño de la cuenta).
 */
function registrar_actividad(string $evento, string $detalle = '', ?int $uid = null, ?int $actor = null, string $visibilidad = 'cuenta'): void
{
    try {
        $uid ??= (int) ($GLOBALS['__uid'] ?? 0);
        if ($uid <= 0) {
            return;
        }
        $actor ??= $uid;
        $ip = function_exists('ip_cliente') ? ip_cliente() : '';
        $ua = PHP_SAPI === 'cli' ? 'consola' : (function_exists('recordar_ua') ? recordar_ua() : '');
        sin_filtro('actividad: el evento se registra para la cuenta afectada (explícita)', fn() => q(
            'INSERT INTO registro_actividad (usuario_id, actor_id, evento, detalle, visibilidad, ip, dispositivo, creado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$uid, $actor, mb_substr($evento, 0, 40), mb_substr($detalle, 0, 500), $visibilidad === 'datos' ? 'datos' : 'cuenta', $ip, mb_substr($ua, 0, 120)]
        ));
    } catch (Throwable $ex) {
        error_log('No se pudo registrar la actividad (' . $evento . '): ' . $ex->getMessage());
    }
}

/** Actividad de la cuenta en contexto (todo lo suyo, incluidos los eventos con datos). */
function actividad_propia(int $limite = 100): array
{
    return filas(
        'SELECT r.*, a.usuario AS actor FROM registro_actividad r LEFT JOIN usuarios a ON a.id = r.actor_id
         WHERE r.usuario_id = {U} ORDER BY r.id DESC LIMIT ' . max(1, min(500, $limite))
    );
}

/** Actividad de las cuentas para el administrador: solo eventos de seguridad (nunca datos de clientes). */
function actividad_cuentas(int $limite = 200, ?string $evento = null, ?int $uid = null): array
{
    $where = "r.visibilidad = 'cuenta'";
    $params = [];
    if ($evento !== null && isset(EVENTOS_ACTIVIDAD[$evento])) {
        $where .= ' AND r.evento = ?';
        $params[] = $evento;
    }
    if ($uid) {
        $where .= ' AND r.usuario_id = ?';
        $params[] = $uid;
    }
    return sin_filtro('actividad: vista del administrador (solo eventos de cuenta, sin datos de clientes)', fn() => filas(
        "SELECT r.*, u.usuario AS cuenta, a.usuario AS actor FROM registro_actividad r
         JOIN usuarios u ON u.id = r.usuario_id LEFT JOIN usuarios a ON a.id = r.actor_id
         WHERE $where ORDER BY r.id DESC LIMIT " . max(1, min(500, $limite)),
        $params
    ));
}
