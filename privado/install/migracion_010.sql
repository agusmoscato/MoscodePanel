-- migracion_010.sql — Módulo "Redes" (calendario de publicaciones) y toda la base en utf8mb4 (emojis).
-- Importala UNA sola vez desde phpMyAdmin (pestaña SQL), después de la 009. Una instalación nueva con install.php
-- ya trae todo. Hacé un backup antes (phpMyAdmin → Exportar).
--
-- utf8mb4: el copy de las publicaciones usa muchos emojis, que necesitan 4 bytes por carácter. Las tablas del panel
-- se crearon siempre en utf8mb4, pero la base en sí puede haber quedado con el juego de caracteres por defecto del
-- hosting (latin1 o utf8 de 3 bytes), y alguna tabla creada o importada a mano también. Esto deja la base y TODAS las
-- tablas en utf8mb4_unicode_ci. En una tabla que ya está en utf8mb4 no cambia nada (solo la reescribe; con los datos
-- de este panel tarda menos de un segundo). La conexión del panel ya usa utf8mb4 (privado/includes/db.php).

ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE usuarios CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE login_intentos CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE configuracion CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usuario_config CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE clientes CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE servicios CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE servicios_precios_hist CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE dominios CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE planes_pago CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE cargos CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE pagos CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE pago_imputaciones CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE cotizaciones CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE feriados CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE notificaciones_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE mp_webhook_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE sesiones_recordar CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE totp_recuperacion CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE registro_actividad CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Calendario de publicaciones para redes sociales (cada usuario el suyo)
CREATE TABLE IF NOT EXISTS publicaciones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    hora TIME NULL,
    tipo VARCHAR(40) NOT NULL,
    estado ENUM('idea','preparacion','listo','publicado') NOT NULL DEFAULT 'idea',
    titulo VARCHAR(160) NOT NULL,
    copy_texto TEXT NULL,
    notas TEXT NULL,
    link VARCHAR(500) NOT NULL DEFAULT '',
    creado_en DATETIME NOT NULL,
    actualizado_en DATETIME NULL,
    INDEX idx_usuario_fecha (usuario_id, fecha, hora),
    CONSTRAINT fk_pub_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
