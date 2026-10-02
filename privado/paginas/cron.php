<?php
/**
 * cron.php — Ejecuta un script de privado/cron/ por web, SOLO con token secreto.
 *   /cron/resumen_mensual?token=...&forzar=1
 *   /cron/avisos_vencimientos?token=...
 *   /cron/actualizar_dolar?token=...
 * Sirve para probar a mano o para hostings sin Cron Job por CLI. El token es app.cron_token de config.php (mínimo 32
 * caracteres) y se manda en la cabecera X-Cron-Token (mejor) o en ?token= (queda en los logs: preferí siempre la consola).
 */
declare(strict_types=1);

$raiz = RAIZ_PRIVADA;
$tarea = (string) ($_GET['tarea'] ?? '');
$permitidas = ['resumen_mensual', 'avisos_vencimientos', 'actualizar_dolar', 'mantenimiento', 'backup'];
if (!in_array($tarea, $permitidas, true)) {
    http_response_code(404);
    exit('Tarea inexistente');
}
// El propio script valida el token con cron_proteger() (responde 403 si no coincide).
require $raiz . '/cron/' . $tarea . '.php';
