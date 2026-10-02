<?php
/** Usuarios (solo admin): alta, activar/desactivar, contraseña temporal y ajustes globales. Sin acceso a datos de clientes. */
exigir_admin();
$titulo = 'Usuarios';
$lista = filas('SELECT id, usuario, nombre, email, rol, activo, debe_cambiar_clave, totp_activo, ultimo_login_en, creado_en FROM usuarios ORDER BY id');
$temporal = $_SESSION['clave_temporal'] ?? null;
unset($_SESSION['clave_temporal']);
$fuente = cfg('dolar_fuente', 'dolarhoy');
?>
<div class="pagina-cab">
    <div>
        <h1>Usuarios</h1>
        <div class="sub">Cada usuario gestiona sus propios clientes; acá solo administrás las cuentas</div>
    </div>
    <a class="btn chico" href="<?= e(url('usuario_form')) ?>"><?= icono('user-plus', 'chico') ?>Nuevo usuario</a>
</div>

<?php if ($temporal): ?>
    <div class="banner warn" role="status"><?= icono('key-round') ?>
        <div class="banner-txt">
            <strong>Contraseña temporal <?= $temporal['tipo'] === 'creada' ? 'del usuario nuevo' : 'asignada' ?>: <?= e($temporal['usuario']) ?></strong>
            <div class="mt-8 link-copiar">
                <input class="campo-link mono" readonly value="<?= e($temporal['clave']) ?>" data-seleccionar aria-label="Contraseña temporal">
                <button type="button" class="btn sec chico icono" data-copiar="<?= e($temporal['clave']) ?>" aria-label="Copiar contraseña"><?= icono('copy') ?></button>
            </div>
            <div class="hereda-opaco suave">Se muestra una sola vez. Tiene que cambiarla al iniciar sesión.</div>
        </div>
    </div>
<?php endif; ?>

<section class="card">
    <ul class="lista">
        <?php foreach ($lista as $u): $propio = (int) $u['id'] === (int) $usuario['id']; ?>
            <li class="item">
                <div class="item-main">
                    <div class="item-tit"><?= e($u['nombre']) ?> <span class="suave mono">@<?= e($u['usuario']) ?></span></div>
                    <div class="item-sub">
                        <?= chip(ROLES[$u['rol']] ?? $u['rol'], $u['rol'] === 'admin' ? 'info' : 'mute') ?>
                        <?= (int) $u['activo'] ? chip('activo', 'ok') : chip('desactivado', 'bad') ?>
                        <?= (int) $u['debe_cambiar_clave'] ? chip('clave temporal', 'warn') : '' ?>
                        <?= (int) $u['totp_activo'] ? chip('2FA', 'ok') : '' ?>
                        <span class="suave"><?= $u['ultimo_login_en'] ? 'último ingreso ' . e(fmt_fecha_hora($u['ultimo_login_en'])) : 'nunca ingresó' ?></span>
                    </div>
                </div>
                <div class="fila-flex-fin item-der">
                    <?php if (!$propio): ?>
                    <form class="en-linea" method="post" action="<?= e(url_accion('usuario_reset')) ?>" data-confirmar-titulo="Resetear contraseña"
                          data-confirmar="Se genera una contraseña temporal para <?= e($u['usuario']) ?> y se cierran sus sesiones." data-confirmar-boton="Resetear">
                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <button class="btn sec chico" type="submit"><?= icono('key-round', 'chico') ?>Resetear clave</button>
                    </form>
                    <?php if ((int) $u['totp_activo']): ?>
                        <form class="en-linea" method="post" action="<?= e(url_accion('usuario_2fa_quitar')) ?>" data-confirmar-titulo="Quitar verificación en dos pasos"
                              data-confirmar="Se le quita el 2FA a <?= e($u['usuario']) ?> y se cierran sus sesiones (por si perdió el celular). Puede volver a activarlo." data-confirmar-boton="Quitar 2FA">
                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button class="btn sec chico" type="submit"><?= icono('shield', 'chico') ?>Quitar 2FA</button>
                        </form>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!$propio): ?>
                        <form class="en-linea" method="post" action="<?= e(url_accion('usuario_estado')) ?>" data-confirmar-titulo="<?= (int) $u['activo'] ? 'Desactivar cuenta' : 'Activar cuenta' ?>"
                              data-confirmar="<?= (int) $u['activo'] ? e($u['usuario']) . ' ya no va a poder ingresar (sus datos se conservan).' : e($u['usuario']) . ' va a poder ingresar de nuevo.' ?>" data-confirmar-boton="Confirmar">
                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="activo" value="<?= (int) $u['activo'] ? '0' : '1' ?>">
                            <button class="btn sec chico" type="submit"><?= icono((int) $u['activo'] ? 'ban' : 'check', 'chico') ?><?= (int) $u['activo'] ? 'Desactivar' : 'Activar' ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="mt-16 card">
    <h2 class="card-tit">Cotización del dólar (global)</h2>
    <form method="post" action="<?= e(url_accion('sistema_guardar')) ?>">
        <?= csrf_campo() ?>
        <label>Fuente de la actualización automática
            <select name="dolar_fuente">
                <option value="dolarhoy"<?= $fuente === 'dolarhoy' ? ' selected' : '' ?>>dolarhoy.com (scraping)</option>
                <option value="dolarapi"<?= $fuente === 'dolarapi' ? ' selected' : '' ?>>dolarapi.com (API pública)</option>
            </select>
        </label>
        <button class="btn sec" type="submit"><?= icono('check') ?>Guardar</button>
    </form>
</section>
