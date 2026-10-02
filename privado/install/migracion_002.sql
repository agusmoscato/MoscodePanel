-- migracion_002.sql — Fase 2. Solo para bases YA instaladas antes de la Fase 2.
-- Importala UNA vez desde phpMyAdmin (pestaña SQL). Una instalación nueva ya lo trae.
-- No cambia la estructura de tablas (feriados y notificaciones_log ya existían);
-- solo agrega valores de configuración nuevos. Es seguro correrla más de una vez.

INSERT IGNORE INTO configuracion (clave, valor) VALUES
    ('notif_email', '1'),
    ('notif_telegram', '1'),
    ('resumen_periodo_hecho', '');
