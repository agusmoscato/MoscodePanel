<?php
/** Aplica el ajuste de precios previsualizado (recalcula y verifica que nada haya cambiado). */
[$p, $error] = parametros_ajuste($_POST);
$volver = url('precios');
if (!nonce_consumir()) {
    flash('error', 'Ese ajuste ya se aplicó (o el formulario venció). Revisá los precios antes de volver a generarlo.');
    redirigir(url('clientes'));
}
if ($error) {
    flash('error', $error);
    redirigir($volver);
}
$filas = calcular_ajuste($p);
if (!$filas) {
    flash('error', 'Ningún servicio coincide con esos filtros.');
    redirigir($volver);
}
// Si algún precio cambió desde la vista previa, se pide revisar de nuevo
if (!hash_equals(firma_ajuste($filas), post('firma'))) {
    flash('error', 'Los servicios cambiaron desde la vista previa. Volvé a generarla antes de aplicar.');
    redirigir($volver);
}
$n = aplicar_ajuste($filas, $p['porcentaje']);
flash('ok', "Ajuste aplicado a $n servicio(s). Queda en el historial de precios de cada uno.");
redirigir(url('clientes'));
