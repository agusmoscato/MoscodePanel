<?php
/** Elimina un dominio. Los cargos ya generados se conservan (quedan sin dominio asociado). */
$id = (int) post('id', '0');
$dom = fila('SELECT id, cliente_id FROM dominios WHERE id = ? AND usuario_id = {U}', [$id]);
if (!$dom) {
    redirigir(url('clientes'));
}
q('DELETE FROM dominios WHERE id = ? AND usuario_id = {U}', [$id]);
flash('ok', 'Dominio eliminado.');
redirigir(url('cliente', ['id' => $dom['cliente_id']]));
