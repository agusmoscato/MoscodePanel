<?php
/** Mi cuenta: datos del usuario logueado y cambio de contraseña. */
$titulo = 'Mi cuenta';
$yo = fila('SELECT usuario, nombre, email, rol, ultimo_login_en, creado_en FROM usuarios WHERE id = ?', [$usuario['id']]);
?>
<div class="pagina-cab">
    <div>
        <h1>Mi cuenta</h1>
        <div class="sub">Usuario <strong><?= e($yo['usuario']) ?></strong> · <?= e(ROLES[$yo['rol']] ?? $yo['rol']) ?><?= $yo['ultimo_login_en'] ? ' · último ingreso ' . e(fmt_fecha_hora($yo['ultimo_login_en'])) : '' ?> · tu IP actual para el panel: <span class="mono"><?= e(ip_cliente()) ?></span></div>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <h2 class="card-tit">Mis datos</h2>
        <form method="post" action="<?= e(url_accion('mi_cuenta_guardar')) ?>" data-validar novalidate>
            <?= csrf_campo() ?>
            <label>Nombre <input name="nombre" required maxlength="120" value="<?= e(viejo('nombre', $yo['nombre'])) ?>"></label>
            <label>Email <input type="email" name="email" inputmode="email" maxlength="160" value="<?= e(viejo('email', (string) $yo['email'])) ?>"></label>
            <label>Contraseña actual <span class="ayuda inline">(solo si cambiás el email)</span> <input type="password" name="clave_actual" autocomplete="current-password"></label>
            <button class="btn" type="submit"><?= icono('check') ?>Guardar</button>
        </form>
    </section>

    <section class="card">
        <h2 class="card-tit">Cambiar contraseña</h2>
        <form method="post" action="<?= e(url_accion('password_cambiar')) ?>" autocomplete="off" data-validar novalidate>
            <?= csrf_campo() ?>
            <label>Contraseña actual <input type="password" name="actual" required autocomplete="current-password"></label>
            <div class="fila-campos c2">
                <label>Nueva (mínimo <?= CLAVE_MIN_LARGO ?> caracteres) <input type="password" name="nueva" required minlength="<?= CLAVE_MIN_LARGO ?>" autocomplete="new-password"></label>
                <label>Repetir la nueva <input type="password" name="nueva2" required minlength="<?= CLAVE_MIN_LARGO ?>" autocomplete="new-password"></label>
            </div>
            <button class="btn sec" type="submit"><?= icono('lock') ?>Cambiar contraseña</button>
            <p class="ayuda">Al cambiarla se cierran tus otras sesiones abiertas.</p>
        </form>
    </section>
</div>

<?php $dispositivos = recordar_dispositivos(); ?>
<section class="mt-16 card" id="dispositivos">
    <h2 class="card-tit">Dispositivos con sesión iniciada</h2>
    <p class="suave">Los dispositivos donde marcaste "Mantener sesión iniciada" (por <?= recordar_dias() ?> días desde el último uso). Si no reconocés alguno, cerralo y cambiá tu contraseña.</p>
    <?php if (!$dispositivos): ?>
        <div class="vacio-est"><?= icono('shield') ?><p>No hay dispositivos recordados. La próxima vez que inicies sesión con la casilla marcada, aparece acá.</p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($dispositivos as $d): ?>
                <li class="item">
                    <div class="item-main">
                        <div class="item-tit"><?= e($d['dispositivo']) ?> <?= $d['actual'] ? chip('este dispositivo', 'ok') : '' ?></div>
                        <div class="item-sub">
                            <span>Último uso <?= e(hace_cuanto($d['ultimo_uso_en'])) ?> (<?= e(fmt_fecha_hora($d['ultimo_uso_en'])) ?>)</span>
                            <span class="mono">IP <?= e($d['ip']) ?></span>
                            <span>Desde <?= e(fecha_corta(substr($d['creado_en'], 0, 10))) ?></span>
                        </div>
                    </div>
                    <form class="en-linea" method="post" action="<?= e(url_accion('sesion_cerrar')) ?>"
                          data-confirmar-titulo="Cerrar sesión" data-confirmar="<?= $d['actual'] ? 'Es este dispositivo: vas a tener que volver a iniciar sesión.' : 'Ese dispositivo va a tener que volver a iniciar sesión.' ?>" data-confirmar-boton="Cerrar">
                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                        <button class="btn sec chico" type="submit"><?= icono('log-out', 'chico') ?>Cerrar</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form method="post" action="<?= e(url_accion('sesion_cerrar_otras')) ?>" class="mt-12"
          data-confirmar-titulo="Cerrar las demás sesiones" data-confirmar="Todos los demás dispositivos van a tener que volver a iniciar sesión. Este sigue abierto." data-confirmar-boton="Cerrar todas">
        <?= csrf_campo() ?>
        <button class="btn sec" type="submit"><?= icono('shield', 'chico') ?>Cerrar todas las demás sesiones</button>
    </form>
</section>

<section class="card mt-16" id="seguridad">
    <h2 class="card-tit">Verificación en dos pasos</h2>
    <?php $dosPasos = totp_activo((int) $usuario['id']); ?>
    <p><?= $dosPasos ? chip('activada', 'ok') : chip('desactivada', 'mute') ?> <span class="suave"><?= $dosPasos ? 'Se pide un código de tu celular al iniciar sesión.' : 'Protegé tu cuenta con un código de tu celular además de la contraseña.' ?></span></p>
    <a class="btn sec" href="<?= e(url('dos_pasos')) ?>"><?= icono('shield', 'chico') ?><?= $dosPasos ? 'Administrar' : 'Activar' ?></a>
</section>

<section class="card mt-16" id="actividad">
    <h2 class="card-tit">Actividad de mi cuenta</h2>
    <p class="suave">Ingresos, cambios de contraseña, 2FA y acciones sobre tu cuenta. Si ves algo que no reconocés, cambiá tu contraseña y cerrá las demás sesiones.</p>
    <?php $actividad = actividad_propia(40); ?>
    <?php if (!$actividad): ?>
        <div class="vacio-est"><?= icono('shield') ?><p>Todavía no hay actividad registrada.</p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($actividad as $a): ?>
                <li class="item">
                    <div class="item-main">
                        <div class="item-tit"><?= e(EVENTOS_ACTIVIDAD[$a['evento']] ?? $a['evento']) ?></div>
                        <div class="item-sub">
                            <span class="mono"><?= e(fmt_fecha_hora($a['creado_en'])) ?></span>
                            <?php if ($a['actor'] && (int) $a['actor_id'] !== (int) $usuario['id']): ?><span>por @<?= e($a['actor']) ?> (administrador)</span><?php endif; ?>
                            <span class="mono">IP <?= e($a['ip']) ?></span>
                            <?php if ($a['dispositivo'] !== ''): ?><span><?= e($a['dispositivo']) ?></span><?php endif; ?>
                        </div>
                        <?php if ($a['detalle'] !== ''): ?><div class="suave"><?= e($a['detalle']) ?></div><?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
