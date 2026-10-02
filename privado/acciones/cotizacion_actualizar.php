<?php
/** Botón "actualizar ahora": como mucho una vez cada 5 minutos por usuario (cada clic consulta una web externa). */
$ultimo = (int) cfg('cotizacion_ultimo_intento', '0');
if (time() - $ultimo < 300) {
    flash('error', 'Ya actualizaste hace un momento. Esperá unos minutos antes de volver a intentar.');
    redirigir(url('cotizacion'));
}
cfg_set('cotizacion_ultimo_intento', (string) time());
$r = actualizar_cotizacion();
flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? $r['mensaje'] : 'No se pudo actualizar: ' . $r['mensaje'] . ' Se sigue usando la última cotización guardada.');
redirigir(url('cotizacion'));
