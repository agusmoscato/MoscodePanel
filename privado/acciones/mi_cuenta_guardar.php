<?php
/** Datos de la cuenta del usuario logueado: nombre a mostrar y email (cambiar el email pide la contraseña actual). */
$volver = url('mi_cuenta');
$nombre = post('nombre');
$email = post('email');
if ($nombre === '') {
    volver_con_error('El nombre es obligatorio.', $volver);
}
if ($err = largo_excedido(['El nombre' => [$nombre, 120]])) {
    volver_con_error($err, $volver);
}
if ($email !== '' && !email_valido($email)) {
    volver_con_error('El email no es válido.', $volver);
}
$actual = (string) valor('SELECT email FROM usuarios WHERE id = ?', [$usuario['id']]);
if ($email !== $actual) {
    if ($err = verificar_clave_propia((int) $usuario['id'], (string) ($_POST['clave_actual'] ?? ''))) {
        volver_con_error('Para cambiar el email hace falta tu contraseña actual. ' . $err, $volver);
    }
    registrar_actividad('email_cambiado', 'De «' . $actual . '» a «' . $email . '»');
}
q('UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?', [$nombre, $email, $usuario['id']]);
flash('ok', 'Datos actualizados.');
redirigir($volver);
