<?php
/** Descarta una cotización automática pendiente (solo admin): se sigue usando la anterior. */
$admin = exigir_admin();
$c = resolver_cotizacion_pendiente((int) post('id', '0'), false, (int) $admin['id']);
if (!$c) {
    flash('error', 'Esa cotización ya no estaba pendiente.');
} else {
    registrar_actividad('cotizacion_descartada', ($c['tipo']) . ': ' . fmt_monto($c['valor_venta']) . ' (' . $c['variacion_pct'] . '%)');
    flash('ok', 'Cotización descartada: se sigue usando la anterior.');
}
redirigir(url('cotizacion'));
