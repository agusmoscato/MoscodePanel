<?php
/** Desactiva la verificación en dos pasos (pide la contraseña actual y un código). */
$uid = (int) $usuario['id'];
$volver = url('dos_pasos');
if (!totp_activo($uid)) {
    redirigir($volver);
}
if ($err = verificar_clave_propia($uid, (string) ($_POST['clave_actual'] ?? '')) ?? verificar_codigo_2fa_propio($uid, (string) ($_POST['codigo'] ?? ''))) {
    volver_con_error($err, $volver);
}
totp_desactivar($uid);
registrar_actividad('dos_pasos_desactivado');
flash('ok', 'Verificación en dos pasos desactivada.');
redirigir($volver);
