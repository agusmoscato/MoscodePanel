-- migracion_009.sql — Cobro automático de dominios (como los servicios anuales) y aviso de renovación por
-- WhatsApp para dominios. Importala UNA sola vez desde phpMyAdmin (pestaña SQL), después de la 008. Una
-- instalación nueva con install.php ya trae todo. Hacé un backup antes (phpMyAdmin → Exportar).
--
-- No hace falta cargar nada a mano para los dominios existentes: la próxima vez que se generen los cargos
-- (botón "Generar cargos del mes", o el resumen mensual automático) los que ya estén dentro de sus días de
-- anticipo generan su cargo solos.

ALTER TABLE dominios
    ADD COLUMN dias_anticipo SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER fecha_vencimiento,
    ADD COLUMN aviso_renovacion_enviado_en DATETIME NULL AFTER estado;
