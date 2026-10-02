<?php
/**
 * cron/mantenimiento.php — Limpieza diaria de tablas que crecen sin parar y de lo vencido.
 *   - intentos de login / portal / cron de más de 7 días
 *   - registro de webhooks de Mercado Pago de más de 90 días y avisos enviados de más de 400 (los avisos de
 *     vencimientos se anticipan hasta 365 días: borrarlos antes los repetiría)
 *   - tokens de "mantener sesión iniciada" vencidos
 *   - cotizaciones automáticas descartadas o pendientes viejas
 *   - registro de actividad de más de 365 días
 * Programar una vez por día. Solo por consola o con el token del cron.
 */
defined('SIN_SESION') || define('SIN_SESION', true);
require_once __DIR__ . '/../includes/bootstrap.php';
cron_proteger();
cron_bloquear('mantenimiento');

/** Corre una limpieza (consulta global a propósito, declarada con sin_filtro) y cuenta lo que borró. */
function limpiar(string $nombre, callable $consulta): void
{
    try {
        echo "  $nombre: " . $consulta() . " fila(s)\n";
    } catch (Throwable $ex) {
        error_log("mantenimiento [$nombre]: " . $ex->getMessage());
        echo "  $nombre: ERROR (ver el log del servidor)\n";
    }
}

echo date('Y-m-d H:i:s') . " — mantenimiento\n";
limpiar('login_intentos (7 días)', fn() => q('DELETE FROM login_intentos WHERE creado_en < DATE_SUB(NOW(), INTERVAL 7 DAY)')->rowCount());
limpiar('cotizaciones descartadas (30 d)', fn() => q("DELETE FROM cotizaciones WHERE estado = 'descartada' AND resuelta_en < DATE_SUB(NOW(), INTERVAL 30 DAY)")->rowCount());
limpiar('cotizaciones pendientes viejas (30 d)', fn() => q("UPDATE cotizaciones SET estado = 'descartada', resuelta_en = NOW() WHERE estado = 'pendiente' AND creado_en < DATE_SUB(NOW(), INTERVAL 30 DAY)")->rowCount());
limpiar('mp_webhook_log (90 días)', fn() => sin_filtro('mantenimiento: limpieza global del log de webhooks', fn() => q('DELETE FROM mp_webhook_log WHERE creado_en < DATE_SUB(NOW(), INTERVAL 90 DAY)')->rowCount()));
limpiar('notificaciones_log (400 días)', fn() => sin_filtro('mantenimiento: limpieza global del log de avisos', fn() => q('DELETE FROM notificaciones_log WHERE enviado_en < DATE_SUB(NOW(), INTERVAL 400 DAY)')->rowCount()));
limpiar('sesiones_recordar vencidas', fn() => sin_filtro('mantenimiento: tokens de recordarme vencidos', fn() => q('DELETE FROM sesiones_recordar WHERE vence_en < NOW()')->rowCount()));
limpiar('registro_actividad (365 días)', fn() => sin_filtro('mantenimiento: registro de actividad viejo', fn() => q('DELETE FROM registro_actividad WHERE creado_en < DATE_SUB(NOW(), INTERVAL 365 DAY)')->rowCount()));
