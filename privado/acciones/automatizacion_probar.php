<?php
/**
 * Dispara a mano una automatización para probarla (con CSRF, desde Configuración).
 *   resumen  → resumen mensual forzado (no marca el mes como enviado)
 *   avisos   → avisos de vencimiento (respeta "no repetir": solo envía lo que no se avisó)
 */
$que = post('que');
if ($que === 'resumen') {
    $r = ejecutar_resumen_mensual(true);
    flash('ok', "Resumen mensual (prueba):\n" . implode("\n", $r['lineas']));
} elseif ($que === 'avisos') {
    $r = ejecutar_avisos_vencimientos();
    flash('ok', "Avisos de vencimiento:\n" . implode("\n", $r['lineas']));
} else {
    flash('error', 'Automatización desconocida.');
}
redirigir(url('configuracion'));
