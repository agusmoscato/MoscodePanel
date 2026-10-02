<?php
/** Activa o desactiva una cuenta (solo admin). Desactivar cierra sus sesiones. */
$admin = exigir_admin();
$id = (int) post('id', '0');
$activo = post('activo') === '1';
$r = cambiar_estado_usuario($id, $activo, (int) $admin['id']);
if ($r['ok']) {
    registrar_actividad($activo ? 'usuario_activado' : 'usuario_desactivado', '', $id, (int) $admin['id']);
}
flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? ($activo ? 'Cuenta activada.' : 'Cuenta desactivada: ya no puede ingresar.') : $r['error']);
redirigir(url('usuarios'));
