<?php
/**
 * Guarda las credenciales del usuario (SMTP, Telegram y Mercado Pago), cifradas en la base.
 * Los campos secretos vacíos conservan el valor guardado; para borrarlos se tilda "Quitar".
 */
$volver = url('configuracion') . '#canales';
if (!cripto_disponible()) {
    volver_con_error('Falta la clave maestra (seguridad) en config.php: sin ella no se pueden guardar credenciales.', $volver);
}
$host = strtolower(post('smtp_host'));
$puerto = (int) post('smtp_puerto', '465');
$seg = post('smtp_seguridad', 'ssl');
$desde = post('smtp_desde');
$chat = post('telegram_chat_id');
$tgToken = trim((string) ($_POST['telegram_token'] ?? ''));
if (!in_array($seg, ['ssl', 'tls'], true)) {
    volver_con_error('La seguridad del SMTP tiene que ser SSL o TLS.', $volver);
}
if ($host !== '') {
    try {
        smtp_destino_seguro($host, $puerto);            // dominio público, puerto de correo y sin IPs internas
    } catch (RuntimeException $ex) {
        volver_con_error($ex->getMessage(), $volver);
    }
}
if ($desde !== '' && !email_valido($desde)) {
    volver_con_error('El email remitente del SMTP no es válido.', $volver);
}
if ($chat !== '' && !preg_match('/^-?\d{3,20}$/', $chat)) {
    volver_con_error('El chat_id de Telegram es un número (puede empezar con -).', $volver);
}
if ($tgToken !== '' && !preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/', $tgToken)) {
    volver_con_error('El token de Telegram no tiene el formato correcto (lo da @BotFather: números, dos puntos y letras).', $volver);
}
if ($err = largo_excedido([
    'El usuario SMTP' => [post('smtp_usuario'), 160], 'El nombre del remitente' => [post('smtp_desde_nombre'), 120],
    'La contraseña SMTP' => [(string) ($_POST['smtp_clave'] ?? ''), 200], 'El access token de Mercado Pago' => [(string) ($_POST['mp_access_token'] ?? ''), 300],
    'La clave del webhook' => [(string) ($_POST['mp_webhook_secret'] ?? ''), 200],
])) {
    volver_con_error($err, $volver);
}

$cambios = [];
$visibles = ['smtp_host' => $host, 'smtp_puerto' => (string) $puerto, 'smtp_seguridad' => $seg, 'smtp_usuario' => post('smtp_usuario'),
    'smtp_desde' => $desde, 'smtp_desde_nombre' => post('smtp_desde_nombre'), 'telegram_chat_id' => $chat];
foreach ($visibles as $clave => $valor) {
    if (cfg($clave) !== $valor) {
        $cambios[] = $clave;
    }
    $valor === '' ? cfg_borrar($clave) : cfg_set($clave, $valor);
}
$quitar = array_map('strval', (array) ($_POST['quitar'] ?? []));
foreach (['smtp_clave', 'telegram_token', 'mp_access_token', 'mp_webhook_secret'] as $clave) {
    $nuevo = trim((string) ($_POST[$clave] ?? ''));
    if (in_array($clave, $quitar, true)) {
        cfg_borrar($clave);
        $cambios[] = $clave . ' (quitada)';
    } elseif ($nuevo !== '') {
        cfg_set($clave, $nuevo);
        $cambios[] = $clave . ' (nueva)';
    }
}
if ($cambios) {
    registrar_actividad('credenciales', 'Cambió: ' . implode(', ', $cambios));    // solo los nombres, nunca los valores
}
flash('ok', 'Credenciales guardadas (cifradas).');
redirigir($volver);
