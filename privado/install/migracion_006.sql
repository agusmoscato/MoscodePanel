-- migracion_006.sql — "Mantener sesión iniciada" (cookie de recordarme con selector + validador).
-- Importala UNA sola vez desde phpMyAdmin (pestaña SQL), después de la 005. Una instalación nueva con
-- install.php ya trae la tabla. Hacé un backup antes.
-- En la base solo se guarda el HASH del validador; los tokens se borran al cerrar sesión, al cambiar o
-- resetear la contraseña y al desactivar al usuario.

CREATE TABLE IF NOT EXISTS sesiones_recordar (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    selector CHAR(24) NOT NULL,
    validador_hash CHAR(64) NOT NULL,
    validador_anterior_hash CHAR(64) NULL,
    rotado_en DATETIME NULL,
    sesion_version INT UNSIGNED NOT NULL,
    dispositivo VARCHAR(255) NOT NULL DEFAULT '',
    ip VARCHAR(45) NOT NULL DEFAULT '',
    creado_en DATETIME NOT NULL,
    ultimo_uso_en DATETIME NOT NULL,
    vence_en DATETIME NOT NULL,
    UNIQUE KEY uq_selector (selector),
    INDEX idx_usuario (usuario_id),
    INDEX idx_vence (vence_en),
    CONSTRAINT fk_rec_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
