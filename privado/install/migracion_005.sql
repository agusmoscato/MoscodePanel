-- migracion_005.sql — Multiusuario + planes en cuotas.
-- Importala UNA sola vez desde phpMyAdmin (pestaña SQL), después de la 001 a la 004, sobre una base ya
-- instalada. Una instalación nueva con install.php ya trae todo esto (no necesita la migración).
-- IMPORTANTE: hacé un backup de la base antes (phpMyAdmin → Exportar).
--
-- Qué hace:
--  1. usuarios: rol, activo, email, cambio de clave obligatorio, versión de sesión y token de webhook.
--  2. Elige al admin: el usuario "amoscato" si existe; si no, el de id más bajo. Todos los datos y la
--     configuración que ya existen pasan a ese usuario.
--  3. usuario_config: la configuración personal sale de "configuracion" y pasa a este usuario
--     (queda en "configuracion" solo lo global: fuente del dólar y su aviso de error).
--  4. usuario_id (obligatorio) en clientes, servicios, dominios, cargos, pagos, imputaciones, historial de
--     precios, log de notificaciones y log de webhooks. Cotizaciones manuales: quedan para el admin.
--  5. servicios.descripcion y planes de pago en cuotas (planes_pago + columnas nuevas en cargos).
--  6. Plantilla de WhatsApp: si seguía siendo la vieja por defecto, se descarta (usa la nueva por defecto);
--     si la habías editado, se conserva y se marca "legacy" (mantiene el significado viejo de {cliente}).
--
-- Después de importarla, corré una vez por consola:  php privado/scripts/migrar_005.php
-- (pasa tus credenciales de config.php —SMTP, Telegram, Mercado Pago— a tu usuario, cifradas).

SET @admin := COALESCE((SELECT id FROM usuarios WHERE usuario = 'amoscato' LIMIT 1), (SELECT MIN(id) FROM usuarios));

-- 1. Usuarios ------------------------------------------------------------------------------------------
ALTER TABLE usuarios
    ADD COLUMN email VARCHAR(160) NOT NULL DEFAULT '' AFTER nombre,
    ADD COLUMN rol ENUM('admin','usuario') NOT NULL DEFAULT 'usuario' AFTER email,
    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER rol,
    ADD COLUMN debe_cambiar_clave TINYINT(1) NOT NULL DEFAULT 0 AFTER activo,
    ADD COLUMN sesion_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER debe_cambiar_clave,
    ADD COLUMN webhook_token VARCHAR(64) NULL AFTER sesion_version,
    ADD COLUMN ultimo_login_en DATETIME NULL AFTER webhook_token,
    ADD UNIQUE KEY uq_webhook_token (webhook_token);

UPDATE usuarios SET rol = 'admin' WHERE id = @admin;

-- 2/3. Configuración por usuario -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuario_config (
    usuario_id INT UNSIGNED NOT NULL,
    clave VARCHAR(80) NOT NULL,
    valor MEDIUMTEXT NOT NULL,
    cifrado TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (usuario_id, clave),
    CONSTRAINT fk_ucfg_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO usuario_config (usuario_id, clave, valor, cifrado)
    SELECT @admin, clave, valor, 0 FROM configuracion
    WHERE clave IN ('nombre_propio', 'alias_cbu', 'email_aviso', 'dolar_tipo', 'dias_aviso', 'plantilla_whatsapp',
                    'notif_email', 'notif_telegram', 'resumen_periodo_hecho', 'portal_activo', 'mp_activo');

DELETE FROM configuracion
    WHERE clave IN ('nombre_propio', 'alias_cbu', 'email_aviso', 'dolar_tipo', 'dias_aviso', 'plantilla_whatsapp',
                    'notif_email', 'notif_telegram', 'resumen_periodo_hecho', 'portal_activo', 'mp_activo');

INSERT IGNORE INTO configuracion (clave, valor) VALUES ('dolar_fuente', 'dolarhoy'), ('cotizacion_error', '');

-- 6. Plantilla de WhatsApp -----------------------------------------------------------------------------
DELETE FROM usuario_config
    WHERE usuario_id = @admin AND clave = 'plantilla_whatsapp'
      AND valor = 'Hola {cliente}! Te paso el detalle de lo pendiente:\n\n{detalle}\n\nTotal: {total}\n\nPodés abonar por transferencia al alias {alias}. ¡Gracias!';

INSERT IGNORE INTO usuario_config (usuario_id, clave, valor, cifrado)
    SELECT usuario_id, 'plantilla_whatsapp_legacy', '1', 0 FROM usuario_config
    WHERE usuario_id = @admin AND clave = 'plantilla_whatsapp';

-- 4. usuario_id en las tablas con datos de usuario -----------------------------------------------------
ALTER TABLE clientes ADD COLUMN usuario_id INT UNSIGNED NULL AFTER id;
UPDATE clientes SET usuario_id = @admin;
ALTER TABLE clientes MODIFY usuario_id INT UNSIGNED NOT NULL,
    ADD INDEX idx_usuario (usuario_id, estado, nombre),
    ADD CONSTRAINT fk_cli_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT;

ALTER TABLE servicios ADD COLUMN usuario_id INT UNSIGNED NULL AFTER id;
UPDATE servicios SET usuario_id = @admin;
ALTER TABLE servicios MODIFY usuario_id INT UNSIGNED NOT NULL,
    ADD COLUMN descripcion VARCHAR(160) NOT NULL DEFAULT '' AFTER nombre,
    ADD INDEX idx_usuario (usuario_id),
    ADD CONSTRAINT fk_serv_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT;

ALTER TABLE servicios_precios_hist ADD COLUMN usuario_id INT UNSIGNED NULL AFTER id;
UPDATE servicios_precios_hist SET usuario_id = @admin;
ALTER TABLE servicios_precios_hist MODIFY usuario_id INT UNSIGNED NOT NULL,
    ADD INDEX idx_usuario (usuario_id),
    ADD CONSTRAINT fk_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT;

ALTER TABLE dominios ADD COLUMN usuario_id INT UNSIGNED NULL AFTER id;
UPDATE dominios SET usuario_id = @admin;
ALTER TABLE dominios MODIFY usuario_id INT UNSIGNED NOT NULL,
    ADD INDEX idx_usuario (usuario_id),
    ADD CONSTRAINT fk_dom_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT;

-- 5. Planes en cuotas ----------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS planes_pago (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    cliente_id INT UNSIGNED NOT NULL,
    concepto VARCHAR(160) NOT NULL,
    descripcion VARCHAR(255) NOT NULL DEFAULT '',
    monto_total DECIMAL(12,2) NOT NULL,
    moneda ENUM('ARS','USD') NOT NULL DEFAULT 'ARS',
    cuotas SMALLINT UNSIGNED NOT NULL,
    frecuencia ENUM('semanal','quincenal','mensual','bimestral','trimestral') NOT NULL DEFAULT 'mensual',
    fecha_primera DATE NOT NULL,
    notas TEXT NULL,
    estado ENUM('activo','cancelado') NOT NULL DEFAULT 'activo',
    cancelado_en DATETIME NULL,
    creado_en DATETIME NOT NULL,
    INDEX idx_usuario (usuario_id, estado),
    INDEX idx_cliente (cliente_id),
    CONSTRAINT fk_plan_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_plan_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cargos ADD COLUMN usuario_id INT UNSIGNED NULL AFTER id;
UPDATE cargos SET usuario_id = @admin;
ALTER TABLE cargos MODIFY usuario_id INT UNSIGNED NOT NULL,
    ADD COLUMN plan_id INT UNSIGNED NULL AFTER dominio_id,
    ADD COLUMN cuota_numero SMALLINT UNSIGNED NULL AFTER plan_id,
    ADD COLUMN cuota_total SMALLINT UNSIGNED NULL AFTER cuota_numero,
    ADD INDEX idx_usuario_periodo (usuario_id, periodo),
    ADD INDEX idx_plan (plan_id),
    ADD CONSTRAINT fk_cargo_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_cargo_plan FOREIGN KEY (plan_id) REFERENCES planes_pago(id) ON DELETE RESTRICT;

ALTER TABLE pagos ADD COLUMN usuario_id INT UNSIGNED NULL AFTER id;
UPDATE pagos SET usuario_id = @admin;
ALTER TABLE pagos MODIFY usuario_id INT UNSIGNED NOT NULL,
    ADD INDEX idx_usuario_fecha (usuario_id, fecha),
    ADD CONSTRAINT fk_pago_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT;

ALTER TABLE pago_imputaciones ADD COLUMN usuario_id INT UNSIGNED NULL AFTER id;
UPDATE pago_imputaciones SET usuario_id = @admin;
ALTER TABLE pago_imputaciones MODIFY usuario_id INT UNSIGNED NOT NULL,
    ADD INDEX idx_usuario (usuario_id),
    ADD CONSTRAINT fk_imp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT;

ALTER TABLE notificaciones_log ADD COLUMN usuario_id INT UNSIGNED NULL AFTER id;
UPDATE notificaciones_log SET usuario_id = @admin;
ALTER TABLE notificaciones_log MODIFY usuario_id INT UNSIGNED NOT NULL,
    DROP INDEX idx_dedup,
    ADD INDEX idx_dedup (usuario_id, referencia_tipo, referencia_id, dias_aviso, vencimiento_ref, canal, exito),
    ADD CONSTRAINT fk_nlog_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT;

ALTER TABLE mp_webhook_log ADD COLUMN usuario_id INT UNSIGNED NULL AFTER id;
UPDATE mp_webhook_log SET usuario_id = @admin;
ALTER TABLE mp_webhook_log MODIFY usuario_id INT UNSIGNED NOT NULL,
    ADD INDEX idx_usuario (usuario_id),
    ADD CONSTRAINT fk_mplog_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT;

-- Cotizaciones: las automáticas son compartidas (usuario_id NULL); las manuales son de quien las cargó.
ALTER TABLE cotizaciones ADD COLUMN usuario_id INT UNSIGNED NULL AFTER id,
    ADD INDEX idx_usuario (usuario_id),
    ADD CONSTRAINT fk_cot_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE;
UPDATE cotizaciones SET usuario_id = @admin WHERE fuente = 'manual';
