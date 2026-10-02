<?php
/** Revoca el link del portal de un cliente (deja de funcionar al instante). */
$id = (int) post('id', '0');
if (cliente_propio($id)) {
    q('UPDATE clientes SET portal_token = NULL WHERE id = ? AND usuario_id = {U}', [$id]);
    flash('ok', 'Link del portal revocado.');
}
redirigir(url('cliente', ['id' => $id]));
