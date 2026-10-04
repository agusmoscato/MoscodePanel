<?php
/** Guarda la configuración del usuario (solo claves permitidas). Las credenciales van en credenciales_guardar. */
$volver = url('configuracion');
$email = post('email_aviso');
if ($err = largo_excedido(['El nombre' => [post('nombre_propio'), 120], 'El alias o CBU' => [post('alias_cbu'), 120]])) {
    volver_con_error($err, $volver);
}
if ($email !== '' && !email_valido($email)) {
    volver_con_error('El email de aviso no es válido.', $volver);
}
$metodo = post('email_metodo', 'servidor');
if (!in_array($metodo, ['servidor', 'smtp'], true)) {
    volver_con_error('Método de envío inválido.', $volver);
}
$remitente = post('email_remitente');
if ($remitente !== '' && (!email_valido($remitente) || str_starts_with($remitente, '-'))) {
    volver_con_error('El remitente no es un email válido.', $volver);
}
$tipo = post('dolar_tipo', 'blue');
if (!isset(DOLAR_TIPOS[$tipo])) {
    volver_con_error('Tipo de dólar inválido.', $volver);
}

// Días de aviso: lista de números separados por coma (ej. "30,15,7,0")
$dias = [];
foreach (explode(',', post('dias_aviso')) as $d) {
    $d = trim($d);
    if ($d === '') {
        continue;
    }
    if (!ctype_digit($d) || (int) $d > 365) {
        volver_con_error('Los días de aviso deben ser números entre 0 y 365 separados por coma (ej. 30,15,7,0).', $volver);
    }
    $dias[(int) $d] = (int) $d;
}
if (!$dias) {
    volver_con_error('Indicá al menos un día de aviso.', $volver);
}
rsort($dias);

$plantilla = str_replace("\r\n", "\n", (string) ($_POST['plantilla_whatsapp'] ?? ''));
if (trim($plantilla) === '') {
    volver_con_error('La plantilla de WhatsApp no puede estar vacía.', $volver);
}
$plantillaRenovacion = str_replace("\r\n", "\n", (string) ($_POST['plantilla_renovacion'] ?? ''));
if (trim($plantillaRenovacion) === '') {
    volver_con_error('La plantilla del aviso de renovación no puede estar vacía.', $volver);
}
[$redesTipos, $errTipos] = redes_tipos_parsear((string) ($_POST['redes_tipos'] ?? implode("\n", redes_tipos())));
if ($errTipos !== null) {
    volver_con_error($errTipos, $volver);
}

cfg_set('nombre_propio', post('nombre_propio'));
cfg_set('alias_cbu', post('alias_cbu'));
cfg_set('email_aviso', $email);
cfg_set('email_metodo', $metodo);
$remitente === '' ? cfg_borrar('email_remitente') : cfg_set('email_remitente', $remitente);
cfg_set('dolar_tipo', $tipo);
cfg_set('dias_aviso', implode(',', $dias));
cfg_set('notif_email', post('notif_email') === '1' ? '1' : '0');
cfg_set('notif_telegram', post('notif_telegram') === '1' ? '1' : '0');
cfg_set('portal_activo', post('portal_activo') === '1' ? '1' : '0');
cfg_set('mp_activo', post('mp_activo') === '1' ? '1' : '0');
cfg_set('redes_aviso', post('redes_aviso') === '1' ? '1' : '0');
if ($redesTipos === PUB_TIPOS_DEFECTO) {
    cfg_borrar('redes_tipos');                   // es la lista por defecto: no hace falta guardarla
} else {
    cfg_set('redes_tipos', implode("\n", $redesTipos));
}
$plantilla = mb_substr($plantilla, 0, 2000);
if (trim($plantilla) === trim(PLANTILLA_WHATSAPP_DEFECTO)) {
    cfg_borrar('plantilla_whatsapp');            // es la de por defecto: no hace falta guardarla
    cfg_borrar('plantilla_whatsapp_legacy');
} else {
    cfg_set('plantilla_whatsapp', $plantilla);
    if (str_contains($plantilla, '{contacto}')) {
        cfg_borrar('plantilla_whatsapp_legacy'); // ya usa las variables nuevas: {cliente} pasa a ser el nombre del cliente
    }
}
$plantillaRenovacion = mb_substr($plantillaRenovacion, 0, 2000);
if (trim($plantillaRenovacion) === trim(PLANTILLA_RENOVACION_DEFECTO)) {
    cfg_borrar('plantilla_renovacion');          // es la de por defecto: no hace falta guardarla
} else {
    cfg_set('plantilla_renovacion', $plantillaRenovacion);
}
flash('ok', 'Configuración guardada.');
redirigir($volver);
