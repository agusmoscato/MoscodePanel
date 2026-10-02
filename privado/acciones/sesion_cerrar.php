<?php
/** Cierra un dispositivo con sesión iniciada (borra su token; la sesión abierta en ese dispositivo cae en su próximo pedido). */
$id = (int) post('id', '0');
$f = fila('SELECT id FROM sesiones_recordar WHERE id = ? AND usuario_id = {U}', [$id]);
if (!$f) {
    flash('error', 'Ese dispositivo ya no está en la lista.');
    redirigir(url('mi_cuenta') . '#dispositivos');
}
if ($id === (int) ($_SESSION['rid'] ?? 0)) {
    cerrar_sesion();                       // es este mismo dispositivo: equivale a salir
    redirigir(url_base('/login'));
}
q('DELETE FROM sesiones_recordar WHERE id = ? AND usuario_id = {U}', [$id]);
registrar_actividad('sesion_cerrada');
flash('ok', 'Dispositivo cerrado.');
redirigir(url('mi_cuenta') . '#dispositivos');
