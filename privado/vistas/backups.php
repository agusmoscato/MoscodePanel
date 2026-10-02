<?php
/** Backups de la base (solo admin): lista, "hacer backup ahora" y envío semanal por email. */
$admin = exigir_admin();
$titulo = 'Backups';
$problemas = backup_requisitos();
$lista = backup_listar();
$ultimo = cfg('backup_ultimo');
$emailActivo = cfg('backup_email', '0') === '1';
?>
<div class="pagina-cab">
    <div>
        <h1>Backups</h1>
        <div class="sub">Copia de seguridad diaria de toda la base, comprimida y cifrada con una clave aparte</div>
    </div>
</div>

<?php if ($problemas): ?>
    <div class="banner warn"><?= icono('triangle-alert') ?><div class="banner-txt"><strong>Los backups todavía no pueden funcionar:</strong>
        <ul><?php foreach ($problemas as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
        Generá la clave con <code>php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"</code> y pegala en <code>config.php → seguridad → clave_backup</code>. Guardá una copia de esa clave fuera del servidor: sin ella los backups no se pueden abrir.</div></div>
<?php endif; ?>

<div class="grid-2">
    <section class="card">
        <h2 class="card-tit">Estado</h2>
        <dl class="datos">
            <div><dt>Último backup automático</dt><dd><?= $ultimo !== '' ? e(fmt_fecha_hora($ultimo)) : 'todavía ninguno' ?></dd></div>
            <div><dt>Backups guardados</dt><dd><?= count($lista) ?> (se conservan los últimos <?= BACKUP_CONSERVAR ?>)</dd></div>
        </dl>
        <form method="post" action="<?= e(url_accion('backup_crear_ahora')) ?>" class="mt-12" <?= $problemas ? '' : 'data-confirmar-titulo="Hacer un backup ahora" data-confirmar="Se genera un backup completo de la base ahora mismo." data-confirmar-boton="Hacer backup"' ?>>
            <?= csrf_campo() ?>
            <button class="btn" type="submit" <?= $problemas ? 'disabled' : '' ?>><?= icono('download', 'chico') ?>Hacer un backup ahora</button>
        </form>
    </section>
    <section class="card">
        <h2 class="card-tit">Enviarme el backup por email</h2>
        <p class="suave">Una vez por semana (los domingos) se manda el último backup cifrado a tu email de aviso, por tu método de envío configurado. Si pesa más de 20 MB no se manda.</p>
        <form method="post" action="<?= e(url_accion('backup_config')) ?>">
            <?= csrf_campo() ?>
            <label class="check"><input type="checkbox" name="backup_email" value="1"<?= $emailActivo ? ' checked' : '' ?>>Enviar el backup por email cada semana</label>
            <p class="ayuda">Destino: <strong><?= e(cfg('email_aviso') ?: 'falta cargar tu email de aviso en Configuración') ?></strong></p>
            <button class="btn sec" type="submit"><?= icono('check') ?>Guardar</button>
        </form>
    </section>
</div>

<section class="card mt-16">
    <h2 class="card-tit">Backups guardados</h2>
    <?php if (!$lista): ?>
        <div class="vacio-est"><?= icono('download') ?><p>Todavía no hay backups. El cron diario los crea (ver el README) o podés hacer uno ahora.</p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($lista as $b): ?>
                <li class="item">
                    <div class="item-main"><div class="item-tit mono"><?= e($b['nombre']) ?></div>
                        <div class="item-sub"><span><?= e(fmt_fecha_hora(date('Y-m-d H:i:s', $b['fecha']))) ?></span><span><?= e(number_format($b['bytes'] / 1024, 1, ',', '.')) ?> KB</span></div></div>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="ayuda">Los archivos están en <code>privado/backups/</code>. Bajalos por el Administrador de archivos de hPanel o por FTP/SFTP. Para restaurar: README → "Restaurar un backup".</p>
    <?php endif; ?>
</section>
