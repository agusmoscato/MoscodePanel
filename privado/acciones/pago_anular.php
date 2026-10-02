<?php
/** Anula un pago (desimputa sus cargos y queda registrado quién, cuándo y por qué). El pago no se borra nunca. */
$id = (int) post('id', '0');
$volver = url('pago_anular', ['id' => $id]);
$pago = fila('SELECT id, cliente_id FROM pagos WHERE id = ? AND usuario_id = {U}', [$id]);
if (!$pago) {
    flash('error', 'El pago no existe.');
    redirigir(url('clientes'));
}
if (!nonce_consumir()) {
    flash('error', 'Ese formulario ya se envió (o venció). Revisá el historial de pagos.');
    redirigir(url('cliente', ['id' => $pago['cliente_id']]) . '#pagos');
}
try {
    $r = anular_pago($id, post('motivo'), (int) $usuario['id']);
} catch (RuntimeException $ex) {
    volver_con_error($ex->getMessage(), $volver);
}
flash('ok', 'Pago anulado. Se desimputó de ' . $r['cargos_revertidos'] . ' cargo(s) y quedó registrado en tu actividad. El pago no se borró.');
redirigir(url('cliente', ['id' => $pago['cliente_id']]) . '#pagos');
