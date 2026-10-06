-- migracion_011.sql — Redes: a qué redes va cada publicación (Instagram, Facebook…) y el link del post ya publicado.
-- Importala UNA sola vez desde phpMyAdmin (pestaña SQL), después de la 010. Una instalación nueva con install.php
-- ya trae todo. Hacé un backup antes (phpMyAdmin → Exportar).

ALTER TABLE publicaciones
    ADD COLUMN redes VARCHAR(400) NOT NULL DEFAULT '' AFTER tipo,
    ADD COLUMN link_publicado VARCHAR(500) NOT NULL DEFAULT '' AFTER link;
