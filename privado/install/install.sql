-- install.sql — Esquema COMPLETO del panel (incluye todas las migraciones hasta la 005).
-- Lo ejecuta public_html/install.php; también se puede importar a mano desde phpMyAdmin.
-- Cada sentencia termina con ";" al final de línea (el instalador separa por eso).
--
-- Multiusuario: toda tabla con datos de un usuario lleva usuario_id (clientes, servicios, dominios,
-- cargos, pagos, planes, historiales, logs y configuración). Las únicas tablas compartidas son
-- feriados, configuracion (solo ajustes globales) y cotizaciones (las automáticas; las manuales
-- llevan el usuario_id de quien las cargó).

CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    nombre VARCHAR(120) NOT NULL DEFAULT '',
    email VARCHAR(160) NOT NULL DEFAULT '',
    rol ENUM('admin','usuario') NOT NULL DEFAULT 'usuario',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    debe_cambiar_clave TINYINT(1) NOT NULL DEFAULT 0,
    sesion_version INT UNSIGNED NOT NULL DEFAULT 1,
    webhook_token VARCHAR(64) NULL UNIQUE,
    ultimo_login_en DATETIME NULL,
    totp_secreto TEXT NULL,
    totp_activo TINYINT(1) NOT NULL DEFAULT 0,
    totp_ultimo_paso BIGINT UNSIGNED NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_intentos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    usuario VARCHAR(60) NOT NULL,
    exitoso TINYINT(1) NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL,
    INDEX idx_ip (ip, creado_en),
    INDEX idx_usuario (usuario, creado_en),
    INDEX idx_creado (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configuracion (
    clave VARCHAR(80) NOT NULL PRIMARY KEY,
    valor TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuario_config (
    usuario_id INT UNSIGNED NOT NULL,
    clave VARCHAR(80) NOT NULL,
    valor MEDIUMTEXT NOT NULL,
    cifrado TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (usuario_id, clave),
    CONSTRAINT fk_ucfg_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clientes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(160) NOT NULL,
    contacto VARCHAR(120) NOT NULL DEFAULT '',
    email VARCHAR(160) NOT NULL DEFAULT '',
    telefono VARCHAR(40) NOT NULL DEFAULT '',
    cuit VARCHAR(20) NOT NULL DEFAULT '',
    notas TEXT NULL,
    estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    portal_token VARCHAR(64) NULL UNIQUE,
    creado_en DATETIME NOT NULL,
    INDEX idx_usuario (usuario_id, estado, nombre),
    CONSTRAINT fk_cli_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servicios (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    cliente_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(160) NOT NULL,
    descripcion VARCHAR(160) NOT NULL DEFAULT '',
    monto DECIMAL(12,2) NOT NULL,
    moneda ENUM('ARS','USD') NOT NULL DEFAULT 'ARS',
    tipo_cobro ENUM('mensual','anual') NOT NULL DEFAULT 'mensual',
    fecha_inicio DATE NOT NULL,
    proximo_vencimiento DATE NULL,
    dias_anticipo SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    inicio_mensual ENUM('mes_siguiente','completo','prorrateo') NOT NULL DEFAULT 'mes_siguiente',
    estado ENUM('activo','pausado','baja') NOT NULL DEFAULT 'activo',
    creado_en DATETIME NOT NULL,
    INDEX idx_cliente (cliente_id),
    INDEX idx_usuario (usuario_id),
    INDEX idx_venc (tipo_cobro, estado, proximo_vencimiento),
    INDEX idx_usuario_venc (usuario_id, tipo_cobro, estado, proximo_vencimiento),
    CONSTRAINT fk_serv_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    CONSTRAINT fk_serv_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servicios_precios_hist (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    servicio_id INT UNSIGNED NOT NULL,
    monto_anterior DECIMAL(12,2) NOT NULL,
    monto_nuevo DECIMAL(12,2) NOT NULL,
    porcentaje DECIMAL(7,2) NULL,
    motivo VARCHAR(160) NOT NULL DEFAULT '',
    fecha DATETIME NOT NULL,
    INDEX idx_servicio (servicio_id),
    INDEX idx_usuario (usuario_id),
    CONSTRAINT fk_hist_servicio FOREIGN KEY (servicio_id) REFERENCES servicios(id) ON DELETE CASCADE,
    CONSTRAINT fk_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dominios (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    cliente_id INT UNSIGNED NOT NULL,
    dominio VARCHAR(190) NOT NULL,
    proveedor VARCHAR(80) NOT NULL DEFAULT '',
    fecha_vencimiento DATE NOT NULL,
    costo_renovacion DECIMAL(12,2) NOT NULL DEFAULT 0,
    moneda_costo ENUM('ARS','USD') NOT NULL DEFAULT 'ARS',
    precio_cliente DECIMAL(12,2) NOT NULL DEFAULT 0,
    moneda_precio ENUM('ARS','USD') NOT NULL DEFAULT 'ARS',
    estado ENUM('activo','baja') NOT NULL DEFAULT 'activo',
    creado_en DATETIME NOT NULL,
    INDEX idx_cliente (cliente_id),
    INDEX idx_usuario (usuario_id),
    INDEX idx_venc (estado, fecha_vencimiento),
    INDEX idx_usuario_venc (usuario_id, estado, fecha_vencimiento),
    CONSTRAINT fk_dom_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    CONSTRAINT fk_dom_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS cargos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    cliente_id INT UNSIGNED NOT NULL,
    servicio_id INT UNSIGNED NULL,
    dominio_id INT UNSIGNED NULL,
    plan_id INT UNSIGNED NULL,
    cuota_numero SMALLINT UNSIGNED NULL,
    cuota_total SMALLINT UNSIGNED NULL,
    concepto VARCHAR(255) NOT NULL,
    periodo CHAR(7) NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    monto DECIMAL(12,2) NOT NULL,
    moneda ENUM('ARS','USD') NOT NULL DEFAULT 'ARS',
    cotizacion DECIMAL(12,2) NULL,
    monto_pagado DECIMAL(12,2) NOT NULL DEFAULT 0,
    estado ENUM('pendiente','parcial','pagado','anulado') NOT NULL DEFAULT 'pendiente',
    clave_unica VARCHAR(80) NOT NULL,
    mp_preference_id VARCHAR(80) NULL,
    mp_link VARCHAR(255) NULL,
    mp_cotizacion DECIMAL(12,2) NULL,
    mp_saldo DECIMAL(12,2) NULL,
    mp_creado_en DATETIME NULL,
    creado_en DATETIME NOT NULL,
    UNIQUE KEY uq_clave (clave_unica),
    INDEX idx_cliente_estado (cliente_id, estado),
    INDEX idx_usuario_periodo (usuario_id, periodo),
    INDEX idx_usuario_estado (usuario_id, estado, cliente_id),
    INDEX idx_usuario_venc (usuario_id, fecha_vencimiento, id),
    INDEX idx_plan (plan_id),
    CONSTRAINT fk_cargo_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cargo_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cargo_servicio FOREIGN KEY (servicio_id) REFERENCES servicios(id) ON DELETE SET NULL,
    CONSTRAINT fk_cargo_dominio FOREIGN KEY (dominio_id) REFERENCES dominios(id) ON DELETE SET NULL,
    CONSTRAINT fk_cargo_plan FOREIGN KEY (plan_id) REFERENCES planes_pago(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pagos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    cliente_id INT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    monto DECIMAL(12,2) NOT NULL,
    moneda ENUM('ARS','USD') NOT NULL DEFAULT 'ARS',
    medio ENUM('transferencia','efectivo','mercadopago','otro') NOT NULL DEFAULT 'transferencia',
    cotizacion_usada DECIMAL(12,2) NOT NULL,
    nota VARCHAR(255) NOT NULL DEFAULT '',
    mp_payment_id VARCHAR(40) NULL UNIQUE,
    creado_en DATETIME NOT NULL,
    anulado_en DATETIME NULL,
    anulado_por INT UNSIGNED NULL,
    anulado_motivo VARCHAR(255) NULL,
    INDEX idx_cliente (cliente_id),
    INDEX idx_usuario_fecha (usuario_id, fecha),
    CONSTRAINT fk_pago_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pago_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pago_imputaciones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    pago_id INT UNSIGNED NOT NULL,
    cargo_id INT UNSIGNED NOT NULL,
    monto_pago DECIMAL(12,2) NOT NULL,
    monto_cargo DECIMAL(12,2) NOT NULL,
    INDEX idx_pago (pago_id),
    INDEX idx_cargo (cargo_id),
    INDEX idx_usuario (usuario_id),
    CONSTRAINT fk_imp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_imp_pago FOREIGN KEY (pago_id) REFERENCES pagos(id) ON DELETE CASCADE,
    CONSTRAINT fk_imp_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cotizaciones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    tipo VARCHAR(20) NOT NULL,
    valor_venta DECIMAL(12,2) NOT NULL,
    fuente VARCHAR(20) NOT NULL,
    creado_en DATETIME NOT NULL,
    estado ENUM('aplicada','pendiente','descartada') NOT NULL DEFAULT 'aplicada',
    variacion_pct DECIMAL(8,2) NULL,
    resuelta_por INT UNSIGNED NULL,
    resuelta_en DATETIME NULL,
    INDEX idx_tipo (tipo, id),
    INDEX idx_vigente (tipo, estado, usuario_id, id),
    INDEX idx_usuario (usuario_id),
    CONSTRAINT fk_cot_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feriados (
    fecha DATE NOT NULL PRIMARY KEY,
    descripcion VARCHAR(160) NOT NULL DEFAULT '',
    origen ENUM('api','manual') NOT NULL DEFAULT 'manual'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notificaciones_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    canal ENUM('email','telegram') NOT NULL,
    tipo VARCHAR(40) NOT NULL,
    referencia_tipo VARCHAR(20) NOT NULL DEFAULT '',
    referencia_id INT UNSIGNED NOT NULL DEFAULT 0,
    dias_aviso SMALLINT NOT NULL DEFAULT 0,
    vencimiento_ref DATE NULL,
    destinatario VARCHAR(190) NOT NULL DEFAULT '',
    exito TINYINT(1) NOT NULL DEFAULT 0,
    detalle TEXT NULL,
    enviado_en DATETIME NOT NULL,
    INDEX idx_dedup (usuario_id, referencia_tipo, referencia_id, dias_aviso, vencimiento_ref, canal, exito),
    INDEX idx_usuario_id (usuario_id, id),
    CONSTRAINT fk_nlog_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_webhook_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    payment_id VARCHAR(40) NOT NULL DEFAULT '',
    resultado VARCHAR(30) NOT NULL,
    detalle VARCHAR(500) NOT NULL DEFAULT '',
    creado_en DATETIME NOT NULL,
    INDEX idx_payment (payment_id),
    INDEX idx_usuario (usuario_id),
    INDEX idx_usuario_id (usuario_id, id),
    CONSTRAINT fk_mplog_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes GLOBALES (solo los maneja el admin). Todo lo demás es configuración de cada usuario (usuario_config).
INSERT IGNORE INTO configuracion (clave, valor) VALUES
    ('dolar_fuente', 'dolarhoy'),
    ('cotizacion_error', '');

-- Sesiones recordadas ("Mantener sesión iniciada"): selector + hash del validador
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


-- Verificación en dos pasos: códigos de recuperación de un solo uso (solo el hash)
CREATE TABLE IF NOT EXISTS totp_recuperacion (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    codigo_hash CHAR(64) NOT NULL,
    usado_en DATETIME NULL,
    creado_en DATETIME NOT NULL,
    INDEX idx_usuario (usuario_id, usado_en),
    CONSTRAINT fk_totp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de actividad de seguridad (quién, qué y cuándo; nunca valores secretos)
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
