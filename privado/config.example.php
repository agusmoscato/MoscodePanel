<?php
/**
 * config.example.php — Plantilla de configuración.
 *
 * Copiá este archivo como "config.php" (en la misma carpeta) y completalo.
 * "config.php" NUNCA debe quedar accesible por web: esta carpeta (privado/)
 * va fuera de public_html o, si no se puede, protegida con su .htaccess.
 *
 * Multiusuario: las credenciales de email (SMTP), Telegram y Mercado Pago ya NO van acá. Las carga cada
 * usuario en Configuración → Canales y quedan cifradas en la base con la clave maestra de abajo.
 */
return [
    // Base de datos MySQL (la creás en hPanel → Bases de datos)
    'db' => [
        'host'    => 'localhost',
        'nombre'  => 'u123456789_admin',
        'usuario' => 'u123456789_admin',
        'clave'   => 'CAMBIAR',
    ],

    'app' => [
        // URL pública del panel, sin barra final (se usa en links de portal y webhooks)
        'url'        => 'https://panel.tudominio.com',
        // Cron por URL: lo más seguro es DEJARLO ASÍ (desactivado) y programar los crons por consola. Si de verdad lo
        // necesitás, poné un token de al menos 32 caracteres y mandalo en la cabecera X-Cron-Token (el token en la URL
        // queda en los logs del servidor).
        'cron_token' => 'CAMBIAR_POR_UN_TOKEN_LARGO_Y_ALEATORIO',
        // Solo si el sitio está detrás de un proxy/CDN y "Mi cuenta" muestra la misma IP para todos: las IP de esos
        // proxies (se lee X-Forwarded-For únicamente cuando el pedido viene de una de ellas). Normalmente vacío.
        'proxies_confiables' => [],
    ],

    'seguridad' => [
        // Clave que cifra las credenciales de los usuarios (SMTP, Telegram, Mercado Pago) y el secreto del 2FA.
        // 32 bytes en base64. Generala UNA vez con:   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
        // Guardá una copia fuera del servidor: si se pierde, cada usuario tiene que volver a cargar sus credenciales.
        'clave_maestra' => '',
        // Para rotarla sin perder nada (ver README → "Rotar la clave maestra"), en lugar de 'clave_maestra':
        //   'claves'       => ['k1' => '<la vieja>', 'k2' => '<la nueva>'],
        //   'clave_activa' => 'k2',

        // Clave de los BACKUPS (distinta de la maestra, mismo formato). Los backups se guardan cifrados con ella.
        // Sin esta clave no hay backups. Guardá una copia fuera del servidor: sin ella no se pueden restaurar.
        'clave_backup' => '',

        // Días que dura "Mantener sesión iniciada" (se renueva con el uso, con un tope absoluto de 180). 1 a 365.
        'recordar_dias' => 90,
    ],
    /*
     * Solo para actualizar desde una versión anterior (migración a multiusuario): si todavía tenés acá los bloques
     * 'smtp', 'telegram' y 'mercadopago' de la versión vieja, dejalos hasta correr
     *     php privado/scripts/migrar_005.php
     * que los copia (cifrados) a la configuración del administrador. Después podés borrarlos.
     */
];
