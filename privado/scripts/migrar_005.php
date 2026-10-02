<?php
/**
 * migrar_005.php — Complemento de migracion_005.sql (correr UNA vez por consola, después de importarla).
 *
 *   php privado/scripts/migrar_005.php
 *
 * Copia las credenciales que tenías en config.php (bloques 'smtp', 'telegram' y 'mercadopago') a la configuración
 * del administrador, cifradas con seguridad.clave_maestra, y le crea su token de webhook. No pisa lo que el
 * administrador ya hubiera cargado en la pantalla de Configuración. Se puede correr más de una vez sin problema.
 * Si el webhook de Mercado Pago ya está configurado con el token viejo (mercadopago.webhook_token), sigue
 * funcionando: se acepta ese token y se asigna al administrador.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por consola.');
}

define('SIN_SESION', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

if (!cripto_disponible()) {
    fwrite(STDERR, "Falta seguridad.clave_maestra en config.php.\n"
        . "Generala con:  php -r \"echo base64_encode(random_bytes(32)), PHP_EOL;\"  y pegala en config.php; después volvé a correr este script.\n");
    exit(1);
}

$admin = sin_filtro('migración: buscar al administrador', fn() => fila("SELECT id, usuario FROM usuarios WHERE rol = 'admin' AND activo = 1 ORDER BY (usuario = 'amoscato') DESC, id LIMIT 1"));
if (!$admin) {
    fwrite(STDERR, "No hay ningún administrador activo (¿se importó migracion_005.sql?).\n");
    exit(1);
}

$origen = [
    'smtp_host' => 'smtp.host', 'smtp_puerto' => 'smtp.puerto', 'smtp_seguridad' => 'smtp.seguridad', 'smtp_usuario' => 'smtp.usuario',
    'smtp_clave' => 'smtp.clave', 'smtp_desde' => 'smtp.desde', 'smtp_desde_nombre' => 'smtp.desde_nombre',
    'telegram_token' => 'telegram.token', 'telegram_chat_id' => 'telegram.chat_id',
    'mp_access_token' => 'mercadopago.access_token', 'mp_webhook_secret' => 'mercadopago.webhook_secret',
];

con_usuario((int) $admin['id'], function () use ($origen, $admin) {
    $copiadas = [];
    foreach ($origen as $clave => $ruta) {
        $valor = trim((string) conf($ruta, ''));
        if ($valor === '' || $valor === 'CAMBIAR' || cfg_existe($clave)) {
            continue;
        }
        cfg_set($clave, $valor);
        $copiadas[] = $clave;
    }
    asegurar_webhook_token((int) $admin['id']);
    echo "Administrador: {$admin['usuario']}\n";
    echo $copiadas ? 'Credenciales copiadas (cifradas): ' . implode(', ', $copiadas) . "\n" : "No había credenciales nuevas para copiar.\n";
    echo 'Tu URL de webhook de Mercado Pago ahora es: ' . mp_webhook_url() . "\n";
    if (token_configurado((string) conf('mercadopago.webhook_token', ''))) {
        echo "(La URL vieja con mercadopago.webhook_token sigue funcionando y llega a tu cuenta.)\n";
    }
});
echo "Listo. Ya podés borrar los bloques smtp, telegram y mercadopago de config.php.\n";
