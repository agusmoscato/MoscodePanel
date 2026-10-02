<?php
/** Elimina un servicio que nunca generó cargos. */
$id = (int) post('id', '0');
$s = fila('SELECT id, cliente_id FROM servicios WHERE id = ? AND usuario_id = {U}', [$id]);
if (!$s) {
    redirigir(url('clientes'));
}
if ((int) valor('SELECT COUNT(*) FROM cargos WHERE usuario_id = {U} AND servicio_id = ?', [$id]) > 0) {
    flash('error', 'El servicio ya generó cargos: no se puede eliminar. Pasalo a "dado de baja".');
    redirigir(url('servicio_form', ['id' => $id]));
}
q('DELETE FROM servicios WHERE id = ? AND usuario_id = {U}', [$id]);
flash('ok', 'Servicio eliminado.');
redirigir(url('cliente', ['id' => $s['cliente_id']]));
