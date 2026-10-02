<?php
/** Genera un juego nuevo de códigos de recuperación (los anteriores dejan de valer). Pide contraseña actual y un código. */
$uid = (int) $usuario['id'];
$volver = url('dos_pasos');
if (!totp_activo($uid)) {
    redirigir($volver);
}
if ($err = verificar_clave_propia($uid, (string) ($_POST['clave_actual'] ?? '')) ?? verificar_codigo_2fa_propio($uid, (string) ($_POST['codigo'] ?? ''))) {
    volver_con_error($err, $volver);
}
$_SESSION['codigos_recuperacion'] = totp_generar_codigos($uid);
registrar_actividad('codigos_regenerados');
flash('ok', 'Códigos de recuperación nuevos generados. Los anteriores ya no sirven.');
redirigir($volver);
