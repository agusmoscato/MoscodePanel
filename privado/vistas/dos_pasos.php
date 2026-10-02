<?php
/** Verificación en dos pasos (TOTP): activar con QR, ver el estado, regenerar códigos de recuperación y desactivar. */
$titulo = 'Verificación en dos pasos';
$uid = (int) $usuario['id'];
$activo = totp_activo($uid);
$codigos = $_SESSION['codigos_recuperacion'] ?? null;
unset($_SESSION['codigos_recuperacion']);         // se muestran una sola vez
$cripto = cripto_disponible();
if (!$activo) {
    if (empty($_SESSION['totp_pendiente'])) {
        $_SESSION['totp_pendiente'] = totp_generar_secreto();
    }
    $secreto = (string) $_SESSION['totp_pendiente'];
    $uri = totp_uri((string) $usuario['usuario'], $secreto);
}
?>
<div class="pagina-cab">
    <a class="btn fantasma chico" href="<?= e(url('mi_cuenta')) ?>"><?= icono('chevron-left', 'chico') ?>Mi cuenta</a>
</div>
<div class="form-titulo"><h1>Verificación en dos pasos</h1>
    <p class="suave">Además de la contraseña, al iniciar sesión se pide un código de 6 dígitos de tu celular (Google Authenticator, Authy, Microsoft Authenticator, 1Password…). Con "Mantener sesión iniciada" el código se pide solo al iniciar sesión, no cada vez que abrís el panel.</p></div>

<?php if ($codigos): ?>
    <section class="card" id="codigos">
        <h2 class="card-tit"><?= icono('key-round', 'chico') ?> Tus códigos de recuperación</h2>
        <div class="banner warn"><?= icono('triangle-alert') ?><div class="banner-txt"><strong>Guardalos ahora: no se vuelven a mostrar.</strong> Cada uno sirve una sola vez para entrar si perdés el celular. Guardalos en un lugar seguro (gestor de contraseñas o impresos), no en el mismo celular.</div></div>
        <ol class="codigos-rec mono">
            <?php foreach ($codigos as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
        </ol>
        <div class="fila-flex">
            <button type="button" class="btn sec chico" data-copiar="<?= e(implode("\n", $codigos)) ?>"><?= icono('copy', 'chico') ?>Copiar</button>
            <button type="button" class="btn sec chico no-imprimir" data-imprimir><?= icono('printer', 'chico') ?>Imprimir</button>
        </div>
    </section>
<?php endif; ?>

<?php if (!$activo): ?>
    <?php if (!$cripto): ?>
        <div class="banner warn"><?= icono('triangle-alert') ?><div class="banner-txt">Falta la clave maestra en <code>config.php</code>: sin ella no se puede guardar el secreto. Pedíselo al administrador.</div></div>
    <?php endif; ?>
    <section class="card">
        <h2 class="card-tit">1. Escaneá el código QR</h2>
        <p class="suave">Abrí tu app de autenticación, elegí "agregar cuenta" y escaneá este código. El QR se dibuja en tu navegador: el secreto no sale de este panel.</p>
        <div class="qr-caja"><canvas data-qr="<?= e($uri) ?>" role="img" aria-label="Código QR para la app de autenticación"></canvas></div>
        <details class="acordeon">
            <summary><span>¿No podés escanear? Cargalo a mano</span><?= icono('chevron-down') ?></summary>
            <div class="acordeon-cuerpo">
                <p class="suave">En la app elegí "ingresar clave de configuración" con tipo "basado en tiempo":</p>
                <p class="mono secreto-2fa" data-seleccionar><?= e(trim(chunk_split($secreto, 4, ' '))) ?></p>
            </div>
        </details>
    </section>
    <form method="post" action="<?= e(url_accion('dos_pasos_activar')) ?>" autocomplete="off" data-validar novalidate>
        <?= csrf_campo() ?>
        <section class="card">
            <h2 class="card-tit">2. Confirmá con un código</h2>
            <div class="fila-campos c2">
                <label>Tu contraseña actual <input type="password" name="clave_actual" required autocomplete="current-password"></label>
                <label>Código de 6 dígitos de la app <input name="codigo" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]{6,7}" placeholder="123456"></label>
            </div>
            <p class="ayuda">Al activarla se cierran tus otras sesiones abiertas, y te mostramos códigos de recuperación de un solo uso.</p>
            <button class="btn" type="submit" <?= $cripto ? '' : 'disabled' ?>><?= icono('shield') ?>Activar verificación en dos pasos</button>
        </section>
    </form>
<?php else: ?>
    <section class="card">
        <h2 class="card-tit">Estado</h2>
        <p><?= chip('activada', 'ok') ?> Te quedan <strong><?= totp_codigos_restantes() ?></strong> códigos de recuperación sin usar.</p>
        <p class="ayuda">Si perdés el celular y no tenés códigos, un administrador puede desactivártela (Usuarios → Quitar 2FA).</p>
    </section>
    <div class="grid-2">
        <section class="card">
            <h2 class="card-tit">Códigos de recuperación nuevos</h2>
            <p class="suave">Genera un juego nuevo: los anteriores dejan de valer.</p>
            <form method="post" action="<?= e(url_accion('dos_pasos_regenerar')) ?>" autocomplete="off" data-validar novalidate>
                <?= csrf_campo() ?>
                <label>Contraseña actual <input type="password" name="clave_actual" required autocomplete="current-password"></label>
                <label>Código de la app (o uno de recuperación) <input name="codigo" required autocomplete="one-time-code" maxlength="20"></label>
                <button class="btn sec" type="submit"><?= icono('rotate-ccw', 'chico') ?>Generar códigos nuevos</button>
            </form>
        </section>
        <section class="card">
            <h2 class="card-tit">Desactivar</h2>
            <p class="suave">Volvés a entrar solo con la contraseña. No es recomendable.</p>
            <form method="post" action="<?= e(url_accion('dos_pasos_desactivar')) ?>" autocomplete="off" data-validar novalidate
                  data-confirmar-titulo="Desactivar verificación en dos pasos" data-confirmar="Tu cuenta quedará protegida solo por la contraseña." data-confirmar-boton="Desactivar">
                <?= csrf_campo() ?>
                <label>Contraseña actual <input type="password" name="clave_actual" required autocomplete="current-password"></label>
                <label>Código de la app (o uno de recuperación) <input name="codigo" required autocomplete="one-time-code" maxlength="20"></label>
                <button class="btn fantasma peligro-suave" type="submit"><?= icono('ban', 'chico') ?>Desactivar</button>
            </form>
        </section>
    </div>
<?php endif; ?>
