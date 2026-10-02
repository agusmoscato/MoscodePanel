<?php
/**
 * Cambia la contraseña del usuario logueado (pide la actual y la nueva dos veces, mínimo 10 caracteres).
 * Cierra las demás sesiones abiertas. Con una contraseña temporal es el paso obligatorio del primer ingreso.
 */
$forzado = (int) $usuario['debe_cambiar_clave'] === 1;
$error = cambiar_clave_propia(
    (int) $usuario['id'],
    (string) ($_POST['actual'] ?? ''),
    (string) ($_POST['nueva'] ?? ''),
    (string) ($_POST['nueva2'] ?? '')
);
if ($error !== null) {
    flash('error', $error);
    redirigir($forzado ? url('cambiar_clave') : url('mi_cuenta'));
}
flash('ok', 'Contraseña actualizada. Se cerraron tus otras sesiones abiertas.');
redirigir($forzado ? url('dashboard') : url('mi_cuenta'));
