<?php
/** Alta de usuario (solo admin). */
exigir_admin();
$titulo = 'Nuevo usuario';
?>
<div class="pagina-cab">
    <div>
        <h1>Nuevo usuario</h1>
        <div class="sub">Arranca con configuración por defecto, sin credenciales y sin clientes</div>
    </div>
</div>
<section class="card">
    <form method="post" action="<?= e(url_accion('usuario_crear')) ?>" autocomplete="off" data-validar novalidate>
        <?= csrf_campo() ?>
        <div class="fila-campos c2">
            <label>Usuario <input name="usuario" required maxlength="60" autocapitalize="none" spellcheck="false" value="<?= e(viejo('usuario')) ?>"></label>
            <label>Nombre <input name="nombre" maxlength="120" value="<?= e(viejo('nombre')) ?>"></label>
        </div>
        <div class="fila-campos c2">
            <label>Email <span class="inline ayuda">(opcional)</span> <input type="email" name="email" maxlength="160" value="<?= e(viejo('email')) ?>"></label>
            <label>Rol
                <select name="rol">
                    <?php foreach (ROLES as $v => $t): ?><option value="<?= e($v) ?>"<?= viejo('rol', 'usuario') === $v ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
        <label>Contraseña temporal <span class="inline ayuda">(vacía = se genera una de 12 caracteres)</span>
            <input type="text" name="clave" minlength="<?= CLAVE_TEMP_MIN ?>" autocomplete="off" spellcheck="false">
        </label>
        <p class="ayuda">El usuario tendrá que cambiarla al iniciar sesión. El administrador no ve los datos de clientes de otros usuarios.</p>
        <div class="form-fijo">
            <a class="btn sec" href="<?= e(url('usuarios')) ?>">Cancelar</a>
            <button class="btn" type="submit"><?= icono('check') ?>Crear usuario</button>
        </div>
    </form>
</section>
