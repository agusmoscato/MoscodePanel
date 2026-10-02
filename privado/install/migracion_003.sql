-- migracion_003.sql — Fase 3. Solo para bases YA instaladas antes de la Fase 3.
-- Importala UNA vez desde phpMyAdmin (pestaña SQL). Una instalación nueva ya lo trae.
-- Agrega: datos del link de Mercado Pago en cada cargo y el registro de webhooks recibidos.

ALTER TABLE cargos
    ADD COLUMN mp_cotizacion DECIMAL(12,2) NULL AFTER mp_link,
    ADD COLUMN mp_saldo DECIMAL(12,2) NULL AFTER mp_cotizacion,
    ADD COLUMN mp_creado_en DATETIME NULL AFTER mp_saldo;

CREATE TABLE IF NOT EXISTS mp_webhook_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_id VARCHAR(40) NOT NULL DEFAULT '',
    resultado VARCHAR(30) NOT NULL,
    detalle VARCHAR(500) NOT NULL DEFAULT '',
    creado_en DATETIME NOT NULL,
    INDEX idx_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
