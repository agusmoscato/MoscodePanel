<?php
/** Cambio obligatorio de contraseña (primer ingreso o contraseña reseteada). Se muestra sin el menú. */
$titulo = 'Cambiar contraseña';
?>
<main class="login-caja">
    <div class="login-logo">
        <img class="logo-img" src="<?= e(asset('assets/img/moscode.svg')) ?>" alt="" width="56" height="56">
        <span class="login-marca" aria-label="moscode">moscode</span>
    </div>
    <form method="post" action="<?= e(url_accion('password_cambiar')) ?>" class="login-card" autocomplete="off">
        <h1>Elegí tu contraseña</h1>
        <p class="ayuda">Hola <?= e($usuario['nombre']) ?>, la contraseña que tenés es temporal. Para seguir tenés que elegir una nueva (mínimo <?= CLAVE_MIN_LARGO ?> caracteres).</p>
        <?php foreach (flash_obtener() as $f): ?>
            <div class="error-campo" role="alert"><?= icono('circle-alert') ?><span><?= e($f['mensaje']) ?></span></div>
        <?php endforeach; ?>
        <?= csrf_campo() ?>
        <label>Contraseña temporal <input type="password" name="actual" required autofocus autocomplete="current-password"></label>
        <label>Contraseña nueva <input type="password" name="nueva" required minlength="<?= CLAVE_MIN_LARGO ?>" autocomplete="new-password"></label>
        <label>Repetir la nueva <input type="password" name="nueva2" required minlength="<?= CLAVE_MIN_LARGO ?>" autocomplete="new-password"></label>
        <button class="btn" type="submit">Guardar y entrar</button>
    </form>
    <form method="post" action="<?= e(url_base('/salir')) ?>" class="centro-mt-12"><?= csrf_campo() ?><button class="btn fantasma chico" type="submit">Salir</button></form>
</main>
