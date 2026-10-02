<?php
/**
 * cron/backup.php — Backup diario de la base (volcado SQL comprimido y CIFRADO con seguridad.clave_backup).
 * Programar una vez por día (de madrugada). Guarda en privado/backups/ y conserva los últimos 14.
 * Si el administrador activó el envío por email, los domingos manda el backup cifrado por email (una vez por semana).
 * Solo por consola o con el token del cron.
 */
defined('SIN_SESION') || define('SIN_SESION', true);
require_once __DIR__ . '/../includes/bootstrap.php';
cron_proteger();
cron_bloquear('backup');

echo date('Y-m-d H:i:s') . " — backup\n";
try {
    $r = backup_crear();
} catch (Throwable $ex) {
    error_log('Backup: ' . $ex->getMessage());
    echo 'ERROR: ' . ($ex instanceof RuntimeException ? $ex->getMessage() : 'no se pudo crear el backup (ver el log del servidor)') . "\n";
    exit(1);
}
$borrados = backup_rotar();
echo '  Backup: ' . basename($r['archivo']) . ' · ' . number_format($r['bytes'] / 1024, 1, ',', '.') . ' KB · ' . $r['tablas'] . ' tablas · ' . $r['filas'] . " filas\n";
echo "  Se conservan los últimos " . BACKUP_CONSERVAR . ($borrados ? " (se borraron $borrados viejos)" : '') . "\n";
cfg_set('backup_ultimo', date('Y-m-d H:i:s'));
foreach (sin_filtro('backup: avisar el evento a los administradores', fn() => filas("SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1")) as $a) {
    registrar_actividad('backup_creado', basename($r['archivo']), (int) $a['id'], (int) $a['id']);
}

// Envío semanal por email (domingos, una vez por semana)
if (cfg('backup_email', '0') === '1' && date('w') === '0' && cfg('backup_ultimo_email') !== date('o-W')) {
    $adminId = (int) cfg('backup_email_admin', '0');
    $max = 20 * 1024 * 1024;
    if ($adminId <= 0) {
        echo "  Email semanal: falta elegir el administrador destinatario.\n";
    } elseif ($r['bytes'] > $max) {
        echo "  Email semanal: el backup pesa más de 20 MB; no se manda por email (bajalo por hPanel).\n";
    } else {
        try {
            con_usuario($adminId, function () use ($r) {
                enviar_email(
                    'Backup semanal de Moscode — ' . date('d/m/Y'),
                    "Adjunto el backup cifrado de la base (" . basename($r['archivo']) . ").\n"
                    . "Está cifrado con tu clave de backup (seguridad.clave_backup de config.php): sin esa clave no se puede abrir.\n"
                    . 'Para restaurarlo: php privado/scripts/restaurar_backup.php (ver el README).',
                    ['nombre' => basename($r['archivo']), 'contenido' => (string) file_get_contents($r['archivo'])]
                );
            });
            cfg_set('backup_ultimo_email', date('o-W'));
            echo "  Email semanal: enviado.\n";
        } catch (Throwable $ex) {
            error_log('Backup por email: ' . $ex->getMessage());
            echo '  Email semanal: FALLÓ' . (cron_es_web() ? '' : ' — ' . $ex->getMessage()) . "\n";
        }
    }
}
