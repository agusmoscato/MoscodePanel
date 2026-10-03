-- migracion_008.sql — Servicios por cantidad (cantidad x precio por unidad) y aviso de renovación anual por WhatsApp.
-- Importala UNA sola vez desde phpMyAdmin (pestaña SQL), después de la 007. Una instalación nueva con install.php
-- ya trae todo. Hacé un backup antes (phpMyAdmin → Exportar).

ALTER TABLE servicios
    ADD COLUMN por_cantidad TINYINT(1) NOT NULL DEFAULT 0 AFTER estado,
    ADD COLUMN cantidad DECIMAL(10,2) NULL AFTER por_cantidad,
    ADD COLUMN unidad VARCHAR(60) NOT NULL DEFAULT '' AFTER cantidad,
    ADD COLUMN unidad_singular VARCHAR(60) NOT NULL DEFAULT '' AFTER unidad,
    ADD COLUMN precio_unidad DECIMAL(12,2) NULL AFTER unidad_singular,
    ADD COLUMN detalle VARCHAR(160) NOT NULL DEFAULT '' AFTER precio_unidad,
    ADD COLUMN aviso_renovacion_enviado_en DATETIME NULL AFTER detalle;
