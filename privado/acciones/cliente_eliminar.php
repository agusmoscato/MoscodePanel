<?php
/** Elimina un cliente (solo si no tiene cargos ni pagos). */
$id = (int) post('id', '0');
if (!cliente_propio($id)) {
    redirigir(url('clientes'));
}
$tiene = (int) valor('SELECT (SELECT COUNT(*) FROM cargos WHERE usuario_id = {U} AND cliente_id = ?) + (SELECT COUNT(*) FROM pagos WHERE usuario_id = {U} AND cliente_id = ?) + (SELECT COUNT(*) FROM planes_pago WHERE usuario_id = {U} AND cliente_id = ?)', [$id, $id, $id]);
if ($tiene > 0) {
    flash('error', 'Tiene cargos o pagos registrados: no se puede eliminar. Marcalo como inactivo.');
    redirigir(url('cliente', ['id' => $id]));
}
q('DELETE FROM clientes WHERE id = ? AND usuario_id = {U}', [$id]);   // servicios y dominios se borran en cascada
flash('ok', 'Cliente eliminado.');
redirigir(url('clientes'));
