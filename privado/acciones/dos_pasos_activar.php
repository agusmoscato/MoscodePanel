<?php
/** Activa la verificación en dos pasos: confirma el primer código de la app y entrega los códigos de recuperación (una sola vez). */
$uid = (int) $usuario['id'];
$volver = url('dos_pasos');
if (!cripto_disponible()) {
    volver_con_error('Falta la clave maestra en config.php: sin ella no se puede guardar el secreto. Pedíselo al administrador.', $volver);
}
if (totp_activo($uid)) {
    redirigir($volver);
}
$secreto = (string) ($_SESSION['totp_pendiente'] ?? '');
if ($secreto === '') {
    volver_con_error('La pantalla venció. Volvé a empezar.', $volver);
}
if ($err = verificar_clave_propia($uid, (string) ($_POST['clave_actual'] ?? ''))) {
    volver_con_error($err, $volver);
}
$codigos = totp_activar($uid, $secreto, (string) ($_POST['codigo'] ?? ''));
if ($codigos === null) {
    q('INSERT INTO login_intentos (ip, usuario, exitoso, creado_en) VALUES (?, ?, 0, NOW())', [ip_cliente(), 'clave:' . $uid]);
    volver_con_error('El código no es correcto. Revisá la hora del celular y probá con el código nuevo que muestra la app.', $volver);
}
unset($_SESSION['totp_pendiente']);
cerrar_otras_sesiones($uid);                  // lo que estaba abierto antes de activar el 2FA se cierra
registrar_actividad('dos_pasos_activado');
$_SESSION['codigos_recuperacion'] = $codigos;
flash('ok', 'Verificación en dos pasos activada. Guardá los códigos de recuperación: no se vuelven a mostrar.');
redirigir($volver);
