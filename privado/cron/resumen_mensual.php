<?php
/**
 * cron/resumen_mensual.php — Resumen del primer día hábil del mes.
 *
 * Se programa para correr TODOS LOS DÍAS temprano; el script decide si hoy toca (por usuario):
 * hoy debe ser día hábil (no sábado, domingo ni feriado) y el resumen del mes de ese usuario no
 * debe haberse enviado todavía. Ese día genera los cargos y envía la deuda por cliente, por los canales
 * de cada usuario y solo con los datos de sus clientes.
 *
 * Forzar a mano (prueba, no marca el mes como enviado):
 *   CLI:  php resumen_mensual.php --forzar
 *   (por web no se puede forzar)
 *   O el botón "Enviar resumen ahora" en Configuración (solo para el usuario logueado).
 *
 * Solo ejecutable por CLI o con token (ver cron_proteger()).
 */
defined('SIN_SESION') || define('SIN_SESION', true);
require_once __DIR__ . '/../includes/bootstrap.php';
cron_proteger();
cron_bloquear('resumen_mensual');

// Forzar solo por consola: por web un token filtrado no puede disparar el envío a todos los usuarios
$forzar = !cron_es_web() && in_array('--forzar', $argv ?? [], true);

echo date('Y-m-d H:i:s') . " — resumen_mensual\n";
// El dólar se actualiza una vez para todos (antes de armar los resúmenes), solo si hoy toca o se fuerza
if ($forzar || (es_dia_habil(date('Y-m-d')) && date('Y-m-d') >= primer_dia_habil(date('Y-m')))) {
    foreach (actualizar_cotizaciones_en_uso()['mensajes'] as $m) {
        echo "  dólar: $m\n";
    }
}
foreach (usuarios_activos() as $u) {
    echo "\n[" . cron_etiqueta($u) . "]\n";
    try {
        $r = con_usuario((int) $u['id'], fn() => ejecutar_resumen_mensual($forzar, false));
        echo implode("\n", $r['lineas']) . "\n";
        if ($forzar && $r['texto'] !== '') {
            echo "--- Texto enviado ---\n" . $r['texto'] . "\n";
        }
    } catch (Throwable $ex) {
        error_log("resumen_mensual [{$u['usuario']}]: " . $ex->getMessage());
        echo 'ERROR (ver el log del servidor)' . (cron_es_web() ? '' : ': ' . $ex->getMessage()) . "\n";
    }
}
