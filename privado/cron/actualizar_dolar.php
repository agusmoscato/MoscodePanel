<?php
/**
 * cron/actualizar_dolar.php — Actualiza la cotización del dólar (una sola vez para todos los usuarios).
 * Baja cada tipo de dólar que usa algún usuario activo; la cotización queda compartida.
 * Cron Job en hPanel (lunes a viernes, cada hora):  0 * * * 1-5
 * Comando: /usr/bin/php /home/USUARIO/.../privado/cron/actualizar_dolar.php
 */
defined('SIN_SESION') || define('SIN_SESION', true);
require_once __DIR__ . '/../includes/bootstrap.php';
cron_proteger();
cron_bloquear('actualizar_dolar');

$r = actualizar_cotizaciones_en_uso();
echo date('Y-m-d H:i:s') . ' — actualizar_dolar' . PHP_EOL . implode(PHP_EOL, $r['mensajes']) . PHP_EOL;
exit($r['ok'] ? 0 : 1);
