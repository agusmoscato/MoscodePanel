<?php
/**
 * install.php — Instalador: crea las tablas y el usuario administrador inicial.
 * Se borra solo al terminar (si no puede, borralo a mano desde el Administrador de archivos).
 */
declare(strict_types=1);

$raiz = RAIZ_PRIVADA;

session_name('panel_install');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => es_https(), 'httponly' => true, 'samesite' => 'Lax']);
session_start();
$esHttps = es_https();

/**
 * ¿La aplicación ya está instalada? Sí si hay usuarios o si quedó la marca de instalación (aunque después se vacíe
 * la tabla, nadie puede volver a instalar y crear un admin sin autenticarse: para eso está scripts/crear_usuario.php).
 * Solo una tabla inexistente cuenta como instalación nueva: cualquier otro error corta con un mensaje genérico.
 */
function esta_instalado(): bool
{
    try {
        if (!valor("SHOW TABLES LIKE 'usuarios'")) {
            return false;
        }
        if ((int) valor('SELECT COUNT(*) FROM usuarios') > 0) {
            return true;
        }
        return valor("SELECT valor FROM configuracion WHERE clave = 'instalado'") === '1';
    } catch (Throwable $ex) {
        if (str_contains($ex->getMessage(), "doesn't exist") && !str_contains($ex->getMessage(), 'usuarios')) {
            return false;                 // todavía no existe la tabla de configuración: instalación nueva
        }
        error_log('Instalador: no se pudo verificar el estado: ' . $ex->getMessage());
        pagina_error_500('No se pudo verificar el estado de la instalación.');
    }
}

$errores = [];
$hecho = false;
$borrado = false;

// Si ya está instalado, el instalador se niega a seguir (aunque el archivo siga ahí).
if (esta_instalado()) {
    http_response_code(403);
    echo 'El panel ya está instalado. Borrá privado/paginas/install.php.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_inst'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        exit('Token inválido');
    }
    $usuario = trim((string) ($_POST['usuario'] ?? ''));
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $clave = (string) ($_POST['clave'] ?? '');
    $clave2 = (string) ($_POST['clave2'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $usuario)) {
        $errores[] = 'El usuario debe tener entre 3 y 60 caracteres (letras, números, punto, guion).';
    }
    if ($err = validar_clave_nueva($clave, $usuario)) {
        $errores[] = $err;
    }
    if ($clave !== $clave2) {
        $errores[] = 'Las contraseñas no coinciden.';
    }

    if (!$errores) {
        $bloqueado = false;
        try {
            // Un solo instalador a la vez: dos pedidos simultáneos no crean dos administradores
            $bloqueado = (int) valor("SELECT GET_LOCK('moscode_instalar', 5)") === 1;
            if (!$bloqueado) {
                throw new RuntimeException('Hay otra instalación en curso. Esperá unos segundos y probá de nuevo.');
            }
            if (esta_instalado()) {                   // se revisa de nuevo ya con el lock tomado
                throw new RuntimeException('El panel ya está instalado.');
            }
            // Crear tablas: se separa el .sql por ";" al final de línea.
            $sql = file_get_contents($raiz . '/install/install.sql');
            foreach (preg_split('/;\s*\n/', (string) $sql) as $sentencia) {
                // Se descartan las líneas de comentario para detectar sentencias vacías.
                $limpia = trim(preg_replace('/^--.*$/m', '', $sentencia) ?? '');
                if ($limpia !== '') {
                    db()->exec($limpia);
                }
            }
            insertar('usuarios', [
                'usuario' => $usuario,
                'password_hash' => password_hash($clave, PASSWORD_DEFAULT),
                'nombre' => $nombre !== '' ? mb_substr($nombre, 0, 120) : $usuario,
                'rol' => 'admin',
                'activo' => 1,
                'debe_cambiar_clave' => 0,
                'creado_en' => date('Y-m-d H:i:s'),
            ]);
            cfg_set('instalado', '1');                // marca de instalación: el instalador no vuelve a funcionar
            $hecho = true;
            $borrado = @unlink(__FILE__);
        } catch (Throwable $ex) {
            if ($ex instanceof RuntimeException) {
                $errores[] = $ex->getMessage();
            } else {
                error_log('Instalador: ' . get_class($ex) . ': ' . $ex->getMessage());
                $errores[] = 'No se pudo completar la instalación. Revisá la conexión a la base y los permisos (el detalle quedó en el log del servidor).';
            }
        } finally {
            if ($bloqueado) {
                try {
                    valor("SELECT RELEASE_LOCK('moscode_instalar')");
                } catch (Throwable) {
                }
            }
        }
    }
}

if (empty($_SESSION['csrf_inst'])) {
    $_SESSION['csrf_inst'] = bin2hex(random_bytes(16));
}
?><!doctype html>
<html lang="es-AR" data-theme="dark">
<head>
<?= ui_head('Instalación — Moscode') ?>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</head>
<body class="login-pagina">
<main class="login-caja">
    <div class="login-logo">
        <img class="logo-img" src="<?= e(asset('assets/img/moscode.svg')) ?>" alt="" width="56" height="56">
        <span class="login-marca" aria-label="moscode">moscode<span class="cursor" aria-hidden="true"></span></span>
    </div>
    <div class="login-card">
        <h1>Instalación</h1>
        <?php if ($hecho): ?>
            <div class="u-background-var-ok-bg-color-var-ok banner"><?= icono('circle-check') ?><span class="banner-txt">Listo: tablas creadas y administrador cargado.</span></div>
            <?php if (!$borrado): ?>
                <div class="banner bad"><?= icono('triangle-alert') ?><span class="banner-txt"><strong>Importante:</strong> no pude borrar privado/paginas/install.php. Borralo a mano desde el Administrador de archivos.</span></div>
            <?php else: ?>
                <p class="suave">La página de instalación se eliminó automáticamente.</p>
            <?php endif; ?>
            <a class="btn" href="<?= e(url_base('/login')) ?>">Ir al login</a>
        <?php else: ?>
            <?php foreach ($errores as $m): ?><div class="error-campo" role="alert"><?= icono('circle-alert') ?><span><?= e($m) ?></span></div><?php endforeach; ?>
            <?php if (!cripto_disponible()): ?><div class="banner warn"><?= icono('triangle-alert') ?><span class="banner-txt">Falta <code>seguridad.clave_maestra</code> en config.php (ver config.example.php): sin ella no se podrán guardar credenciales de email, Telegram ni Mercado Pago.</span></div><?php endif; ?>
            <?php if (!$esHttps): ?><div class="banner warn"><?= icono('triangle-alert') ?><span class="banner-txt">Estás en HTTP sin cifrar. Activá el SSL en hPanel antes de usar el panel.</span></div><?php endif; ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_inst']) ?>">
                <label>Usuario administrador
                    <input name="usuario" required minlength="3" maxlength="60" autocapitalize="none" spellcheck="false" value="<?= e($_POST['usuario'] ?? '') ?>">
                </label>
                <label>Tu nombre
                    <input name="nombre" maxlength="120" value="<?= e($_POST['nombre'] ?? '') ?>">
                </label>
                <label>Contraseña (mínimo 10 caracteres)
                    <input type="password" name="clave" required minlength="10" autocomplete="new-password">
                </label>
                <label>Repetir contraseña
                    <input type="password" name="clave2" required minlength="10" autocomplete="new-password">
                </label>
                <button class="btn" type="submit">Instalar</button>
            </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
