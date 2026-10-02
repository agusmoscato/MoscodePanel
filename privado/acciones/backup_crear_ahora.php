<?php
/** Hace un backup ahora (solo admin). */
$admin = exigir_admin();
try {
    $r = backup_crear();
    backup_rotar();
    cfg_set('backup_ultimo', date('Y-m-d H:i:s'));
    registrar_actividad('backup_creado', basename($r['archivo']) . ' (manual)', (int) $admin['id'], (int) $admin['id']);
    flash('ok', 'Backup creado: ' . basename($r['archivo']) . ' (' . number_format($r['bytes'] / 1024, 1, ',', '.') . ' KB, ' . $r['tablas'] . ' tablas, ' . $r['filas'] . ' filas).');
} catch (Throwable $ex) {
    flash('error', 'No se pudo crear el backup: ' . mensaje_seguro($ex, 'error al generar el archivo (mirá el log del servidor).'));
}
redirigir(url('backups'));
