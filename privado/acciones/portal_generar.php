<?php
/** Genera (o regenera, revocando el anterior) el link secreto del portal de un cliente. */
$id = (int) post('id', '0');
if (!cliente_propio($id)) {
    redirigir(url('clientes'));
}
if (cfg('portal_activo', '0') !== '1') {
    flash('error', 'El portal está desactivado. Activalo primero en Configuración.');
    redirigir(url('cliente', ['id' => $id]));
}
q('UPDATE clientes SET portal_token = ? WHERE id = ? AND usuario_id = {U}', [token_aleatorio(24), $id]);   // 48 caracteres hex
flash('ok', 'Link del portal generado. Si había uno anterior, dejó de funcionar.');
redirigir(url('cliente', ['id' => $id]));
