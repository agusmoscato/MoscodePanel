<?php
/**
 * login2fa.php — Segundo paso del ingreso: código de la app de autenticación (o de recuperación).
 * Se llega acá con la contraseña ya verificada ($_SESSION['pre2fa'], válido 5 minutos); todavía no hay sesión iniciada.
 */
declare(strict_types=1);

if (usuario_actual()) {
    redirigir(url('dashboard'));
}
if (empty($_SESSION['pre2fa'])) {
    redirigir(url_base('/login'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    [$ok, $error, $reiniciar] = completar_login_2fa((string) ($_POST['codigo'] ?? ''));
    if ($ok) {
        redirigir(url('dashboard'));
    }
    if ($reiniciar) {
        $_SESSION['aviso_login'] = $error;
        redirigir(url_base('/login'));
    }
}
?><!doctype html>
<html lang="es-AR" data-theme="dark">
<head>
<?= ui_head('Verificación — Moscode', true, false) ?>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</head>
<body class="login-pagina">
<main class="login-caja">
    <div class="login-logo">
        <img class="logo-img" src="<?= e(asset('assets/img/moscode.svg')) ?>" alt="" width="56" height="56">
        <span class="login-marca" aria-label="moscode">moscode</span>
    </div>
    <form method="post" class="login-card" autocomplete="off">
        <h1>Verificación en dos pasos</h1>
        <p class="ayuda">Abrí tu app de autenticación e ingresá el código de 6 dígitos de Moscode. Si perdiste el celular, usá un código de recuperación.</p>
        <?php if ($error): ?>
            <div class="error-campo" role="alert"><?= icono('circle-alert') ?><span><?= e($error) ?></span></div>
        <?php endif; ?>
        <?= csrf_campo() ?>
        <label>Código
            <input name="codigo" required autofocus inputmode="text" autocomplete="one-time-code" maxlength="20" spellcheck="false" autocapitalize="characters" placeholder="123456">
        </label>
        <button class="btn" type="submit">Verificar</button>
        <a class="btn fantasma chico" href="<?= e(url_base('/login')) ?>">Volver</a>
    </form>
</main>
</body>
</html>
