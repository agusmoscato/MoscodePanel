<?php
/**
 * login.php — Pantalla de ingreso.
 */
declare(strict_types=1);

$raiz = RAIZ_PRIVADA;

if (usuario_actual()) {
    redirigir(url('dashboard'));
}

$error = (string) ($_SESSION['aviso_login'] ?? '');
unset($_SESSION['aviso_login']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    [$ok, $error] = intentar_login(trim((string) ($_POST['usuario'] ?? '')), (string) ($_POST['clave'] ?? ''), !empty($_POST['recordar']));
    if ($ok) {
        redirigir($error === '2fa' ? url_base('/login/2fa') : url('dashboard'));
    }
}
?><!doctype html>
<html lang="es-AR" data-theme="dark">
<head>
<?= ui_head('Ingresar — Moscode') ?>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</head>
<body class="login-pagina">
<main class="login-caja">
    <div class="login-logo">
        <img class="logo-img" src="<?= e(asset('assets/img/moscode.svg')) ?>" alt="" width="56" height="56">
        <span class="login-marca" aria-label="moscode">moscode<span class="cursor" aria-hidden="true"></span></span>
    </div>

    <form method="post" class="login-card" autocomplete="on">
        <h1>Ingresá a tu panel</h1>
        <?php if ($error): ?>
            <div class="error-campo" role="alert"><?= icono('circle-alert') ?><span><?= e($error) ?></span></div>
        <?php endif; ?>
        <?= csrf_campo() ?>
        <label>Usuario
            <input name="usuario" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="60">
        </label>
        <label>Contraseña
            <input type="password" name="clave" required autocomplete="current-password">
        </label>
        <label class="check"><input type="checkbox" name="recordar" value="1" checked>Mantener sesión iniciada</label>
        <button class="btn" type="submit">Ingresar</button>
    </form>
    <p class="login-pie">Panel de clientes y cobros</p>
</main>
</body>
</html>
