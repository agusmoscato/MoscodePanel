<?php
/** Quita la verificación en dos pasos de un usuario que perdió el celular (solo admin). Cierra todas sus sesiones. */
$admin = exigir_admin();
$id = (int) post('id', '0');
if ($id === (int) $admin['id']) {
    flash('error', 'Tu propia verificación en dos pasos se desactiva desde Mi cuenta.');
    redirigir(url('usuarios'));
}
$u = fila('SELECT id, usuario, totp_activo FROM usuarios WHERE id = ?', [$id]);
if (!$u || !(int) $u['totp_activo']) {
    flash('error', 'Ese usuario no tiene la verificación en dos pasos activada.');
    redirigir(url('usuarios'));
}
totp_desactivar($id);
q('UPDATE usuarios SET sesion_version = sesion_version + 1 WHERE id = ?', [$id]);   // si perdió el celular, se cierran sus sesiones
recordar_revocar_usuario($id);
registrar_actividad('dos_pasos_quitado', 'Por el administrador (el usuario puede volver a activarla)', $id, (int) $admin['id']);
flash('ok', 'Se quitó la verificación en dos pasos de ' . $u['usuario'] . ' y se cerraron sus sesiones. Puede volver a activarla desde Mi cuenta.');
redirigir(url('usuarios'));
