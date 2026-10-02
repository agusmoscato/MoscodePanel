<?php
/** Confirma una cotización automática que quedó pendiente por variar más del 20% (solo admin). Desde ahí la usan todos. */
$admin = exigir_admin();
$c = resolver_cotizacion_pendiente((int) post('id', '0'), true, (int) $admin['id']);
if (!$c) {
    flash('error', 'Esa cotización ya no estaba pendiente.');
} else {
    registrar_actividad('cotizacion_confirmada', ($c['tipo']) . ': ' . fmt_monto($c['valor_venta']) . ' (' . $c['variacion_pct'] . '%)');
    flash('ok', 'Cotización confirmada: desde ahora la usan todos los usuarios.');
}
redirigir(url('cotizacion'));
