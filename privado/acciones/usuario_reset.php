<?php
/** Asigna una contraseña temporal a otro usuario (solo admin): queda obligado a cambiarla al ingresar. */
$admin = exigir_admin();
$id = (int) post('id', '0');
if ($id === (int) $admin['id']) {
    flash('error', 'Para cambiar tu propia contraseña usá Mi cuenta (un reseteo propio cerraría tu sesión antes de que veas la clave).');
    redirigir(url('usuarios'));
}
$r = resetear_clave($id, (string) ($_POST['clave'] ?? ''));
if (!$r['ok']) {
    flash('error', $r['error']);
    redirigir(url('usuarios'));
}
$u = fila('SELECT usuario FROM usuarios WHERE id = ?', [$id]);
registrar_actividad('clave_reseteada', 'Por el administrador', $id, (int) $admin['id']);
$_SESSION['clave_temporal'] = ['usuario' => $u['usuario'], 'clave' => $r['clave'], 'tipo' => 'reseteada'];
flash('ok', 'Contraseña temporal asignada. Se cerraron sus sesiones abiertas.');
redirigir(url('usuarios'));
