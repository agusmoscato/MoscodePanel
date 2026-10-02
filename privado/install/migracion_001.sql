-- migracion_001.sql — Solo para bases YA instaladas con la versión inicial de la Fase 1.
-- Importala UNA vez desde phpMyAdmin (pestaña SQL). Una instalación nueva no la necesita.
-- Agrega las opciones por servicio: días de anticipo del cargo anual y modo de inicio mensual.

ALTER TABLE servicios
    ADD COLUMN dias_anticipo SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER proximo_vencimiento,
    ADD COLUMN inicio_mensual ENUM('mes_siguiente','completo','prorrateo') NOT NULL DEFAULT 'mes_siguiente' AFTER dias_anticipo;
