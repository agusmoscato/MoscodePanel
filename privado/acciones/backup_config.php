<?php
/** Activa o desactiva el envío semanal del backup por email al administrador que lo configura. */
$admin = exigir_admin();
$activo = post('backup_email') === '1';
if ($activo && cfg('email_aviso') === '') {
    flash('error', 'Primero cargá tu email de aviso en Configuración.');
    redirigir(url('backups'));
}
cfg_set('backup_email', $activo ? '1' : '0');
cfg_set('backup_email_admin', (string) (int) $admin['id']);
flash('ok', $activo ? 'Listo: los domingos se te manda el backup por email.' : 'Se desactivó el envío por email.');
redirigir(url('backups'));
