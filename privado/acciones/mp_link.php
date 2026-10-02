<?php
/** Genera el link de pago de Mercado Pago de un cargo y vuelve a la ficha del cliente. */
$cargoId = (int) post('id', '0');
$cargo = fila('SELECT cliente_id FROM cargos WHERE id = ? AND usuario_id = {U}', [$cargoId]);
if (!$cargo) {
    redirigir(url('clientes'));
}
try {
    mp_link_para_cargo($cargoId);
    flash('ok', 'Link de pago generado. Lo ves en la ficha, junto al cargo.');
} catch (Throwable $ex) {
    flash('error', 'No se pudo generar el link: ' . mensaje_seguro($ex));
}
redirigir(url('cliente', ['id' => $cargo['cliente_id']]));
