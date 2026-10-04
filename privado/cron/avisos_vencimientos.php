<?php
/**
 * cron/avisos_vencimientos.php — Avisos de dominios, servicios anuales y cuotas por vencer.
 * Se programa una vez por día. Recorre los usuarios activos; cada uno recibe los avisos de SUS clientes por SUS
 * canales (email/Telegram) y con SUS días de anticipación. Cada escalón se envía una sola vez.
 * También manda el aviso de Redes (lo que hay que publicar hoy) a quien lo activó en Configuración.
 * Solo ejecutable por CLI o con token.
 */
defined('SIN_SESION') || define('SIN_SESION', true);
require_once __DIR__ . '/../includes/bootstrap.php';
cron_proteger();
cron_bloquear('avisos_vencimientos');

$ver = ($_GET['ver'] ?? '') === '1' || in_array('--ver', $argv ?? [], true);
echo date('Y-m-d H:i:s') . " — avisos_vencimientos\n";
foreach (usuarios_activos() as $u) {
    echo "\n[" . cron_etiqueta($u) . "]\n";
    try {
        $r = con_usuario((int) $u['id'], fn() => ejecutar_avisos_vencimientos());
        echo implode("\n", $r['lineas']) . "\n";
        if ($ver) {
            echo "--- Texto ---\n" . ($r['texto'] ?? '') . "\n";
        }
    } catch (Throwable $ex) {
        error_log("avisos_vencimientos [{$u['usuario']}]: " . $ex->getMessage());
        echo 'ERROR (ver el log del servidor)' . (cron_es_web() ? '' : ': ' . $ex->getMessage()) . "\n";
    }
    // Redes: lo que hay que publicar hoy (solo si el usuario activó ese aviso). Aparte: si fallan los vencimientos, sale igual
    try {
        $r = con_usuario((int) $u['id'], fn() => ejecutar_aviso_redes());
        echo implode("\n", $r['lineas']) . "\n";
        if ($ver && isset($r['texto'])) {
            echo "--- Texto ---\n" . $r['texto'] . "\n";
        }
    } catch (Throwable $ex) {
        error_log("avisos_vencimientos (redes) [{$u['usuario']}]: " . $ex->getMessage());
        echo 'Redes: ERROR (ver el log del servidor)' . (cron_es_web() ? '' : ': ' . $ex->getMessage()) . "\n";
    }
}
