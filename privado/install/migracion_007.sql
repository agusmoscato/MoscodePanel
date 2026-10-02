-- migracion_007.sql — Seguridad: 2FA, registro de actividad, anulación de pagos, cotización pendiente de confirmar e índices.
-- Importala UNA sola vez desde phpMyAdmin (pestaña SQL), después de la 006. Una instalación nueva con install.php ya
-- trae todo. Hacé un backup antes (phpMyAdmin → Exportar).

-- Verificación en dos pasos
ALTER TABLE usuarios
    ADD COLUMN totp_secreto TEXT NULL AFTER ultimo_login_en,
    ADD COLUMN totp_activo TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secreto,
    ADD COLUMN totp_ultimo_paso BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_activo;

CREATE TABLE IF NOT EXISTS totp_recuperacion (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    codigo_hash CHAR(64) NOT NULL,
    usado_en DATETIME NULL,
    creado_en DATETIME NOT NULL,
    INDEX idx_usuario (usuario_id, usado_en),
    CONSTRAINT fk_totp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de actividad de seguridad
CREATE TABLE IF NOT EXISTS registro_actividad (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NULL,
    evento VARCHAR(40) NOT NULL,
    detalle VARCHAR(500) NOT NULL DEFAULT '',
    visibilidad ENUM('cuenta','datos') NOT NULL DEFAULT 'cuenta',
    ip VARCHAR(45) NOT NULL DEFAULT '',
    dispositivo VARCHAR(120) NOT NULL DEFAULT '',
    creado_en DATETIME NOT NULL,
    INDEX idx_usuario (usuario_id, id),
    INDEX idx_visibilidad (visibilidad, id),
    CONSTRAINT fk_act_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anular un pago: el pago nunca se borra, queda marcado (quién, cuándo y por qué)
ALTER TABLE pagos
    ADD COLUMN anulado_en DATETIME NULL AFTER creado_en,
    ADD COLUMN anulado_por INT UNSIGNED NULL AFTER anulado_en,
    ADD COLUMN anulado_motivo VARCHAR(255) NULL AFTER anulado_por;

-- Cotización automática con salto grande: queda "pendiente" hasta que el admin la confirme o descarte
ALTER TABLE cotizaciones
    ADD COLUMN estado ENUM('aplicada','pendiente','descartada') NOT NULL DEFAULT 'aplicada' AFTER creado_en,
    ADD COLUMN variacion_pct DECIMAL(8,2) NULL AFTER estado,
    ADD COLUMN resuelta_por INT UNSIGNED NULL AFTER variacion_pct,
    ADD COLUMN resuelta_en DATETIME NULL AFTER resuelta_por,
    ADD INDEX idx_vigente (tipo, estado, usuario_id, id);

-- Índices de las consultas más usadas
ALTER TABLE login_intentos ADD INDEX idx_creado (creado_en);
ALTER TABLE cargos
    ADD INDEX idx_usuario_estado (usuario_id, estado, cliente_id),
    ADD INDEX idx_usuario_venc (usuario_id, fecha_vencimiento, id);
ALTER TABLE servicios ADD INDEX idx_usuario_venc (usuario_id, tipo_cobro, estado, proximo_vencimiento);
ALTER TABLE dominios ADD INDEX idx_usuario_venc (usuario_id, estado, fecha_vencimiento);
ALTER TABLE notificaciones_log ADD INDEX idx_usuario_id (usuario_id, id);
ALTER TABLE mp_webhook_log ADD INDEX idx_usuario_id (usuario_id, id);
