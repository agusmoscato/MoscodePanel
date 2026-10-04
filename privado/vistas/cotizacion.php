<?php
/** Cotización del dólar: valor vigente, actualización, carga manual e historial. */
$titulo = 'Dólar';
$cot = cotizacion_vigente();
$error = cfg('cotizacion_error');
$historial = filas('SELECT * FROM cotizaciones WHERE tipo = ? AND (usuario_id IS NULL OR usuario_id = {U}) ORDER BY id DESC LIMIT 60', [cfg('dolar_tipo', 'blue')]);
$pendientes = cotizaciones_pendientes();
$tipoActual = DOLAR_TIPOS[cfg('dolar_tipo', 'blue')] ?? 'Dólar';
?>
<div class="pagina-cab">
    <div>
        <h1>Dólar</h1>
        <div class="sub">Cotización para convertir los montos en USD</div>
    </div>
    <a class="btn sec chico" href="<?= e(url('configuracion')) ?>"><?= icono('settings', 'chico') ?>Elegir tipo</a>
</div>

<?php foreach ($pendientes as $pc): ?>
    <div class="banner warn" role="alert"><?= icono('triangle-alert') ?>
        <div class="banner-txt"><strong>Cotización pendiente de confirmar: dólar <?= e(mb_strtolower(DOLAR_TIPOS[$pc['tipo']] ?? $pc['tipo'])) ?> a <?= monto_html($pc['valor_venta']) ?></strong>
            (<?= e(number_format((float) $pc['variacion_pct'], 1, ',', '.')) ?>% contra la anterior, supera el <?= (int) COTIZACION_SALTO_PCT ?>%). Mientras no se confirme, todos siguen usando la cotización anterior.
            <?php if (es_admin()): ?>
                <div class="fila-flex mt-8">
                    <form class="en-linea" method="post" action="<?= e(url_accion('cotizacion_confirmar')) ?>"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $pc['id'] ?>"><button class="btn chico" type="submit"><?= icono('check', 'chico') ?>Confirmar</button></form>
                    <form class="en-linea" method="post" action="<?= e(url_accion('cotizacion_descartar')) ?>"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $pc['id'] ?>"><button class="btn sec chico" type="submit"><?= icono('x', 'chico') ?>Descartar</button></form>
                </div>
            <?php else: ?>
                <div class="suave">Un administrador tiene que confirmarla o descartarla.</div>
            <?php endif; ?>
        </div></div>
<?php endforeach; ?>

<?php if ($error !== ''): ?>
    <div class="banner warn" role="alert"><?= icono('triangle-alert') ?>
        <div class="banner-txt"><strong>La última actualización automática falló.</strong> Se usa la última cotización guardada.
            <div class="hereda-opaco suave"><?= e($error) ?></div></div></div>
<?php endif; ?>

<div class="grid-2">
    <section class="card hero">
        <div class="hero-etq"><?= icono('circle-dollar-sign', 'chico') ?>Dólar <?= e(mb_strtolower($tipoActual)) ?> — valor venta</div>
        <?php if ($cot): ?>
            <div class="hero-total"><?= monto_html($cot['valor_venta']) ?></div>
            <div class="hero-sub">
                Actualizado <?= e(hace_cuanto($cot['creado_en'])) ?> (<?= e(fmt_fecha_hora($cot['creado_en'])) ?>) · fuente <?= chip($cot['fuente'], 'mute') ?>
            </div>
        <?php else: ?>
            <div class="pad-16-0 vacio-est"><?= icono('circle-dollar-sign') ?><p><strong>Todavía no hay cotización.</strong> Actualizala o cargala a mano.</p></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url_accion('cotizacion_actualizar')) ?>" class="mt-16">
            <?= csrf_campo() ?>
            <button class="btn" type="submit"><?= icono('refresh-cw') ?>Actualizar ahora (<?= e($tipoActual) ?>)</button>
        </form>
    </section>

    <section class="card">
        <h2 class="card-tit">Cargar a mano</h2>
        <form method="post" action="<?= e(url_accion('cotizacion_manual')) ?>" data-validar novalidate>
            <?= csrf_campo() ?>
            <label>Valor venta (pesos por 1 USD)
                <input type="number" name="valor" inputmode="decimal" required min="0.01" step="0.01" placeholder="Ej: 1555,00">
            </label>
            <button class="btn sec" type="submit"><?= icono('check') ?>Guardar cotización manual</button>
        </form>
        <p class="u-margin-12px-0-0 suave">Sirve si la actualización automática falla. Vale solo para tu cuenta y queda en tu historial como "manual".</p>
    </section>
</div>

<section class="mt-16 card">
    <h2 class="card-tit">Historial</h2>
    <?php if (!$historial): ?>
        <div class="vacio-est"><?= icono('clock') ?><p>Sin registros todavía.</p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($historial as $h): ?>
                <li class="item">
                    <div class="item-main">
                        <div class="item-sub"><span class="mono"><?= e(fmt_fecha_hora($h['creado_en'])) ?></span><?= chip(DOLAR_TIPOS[$h['tipo']] ?? $h['tipo'], 'mute') ?><?= chip($h['fuente'], $h['fuente'] === 'manual' ? 'info' : '') ?><?= $h['estado'] === 'pendiente' ? chip('pendiente de confirmar', 'warn') : ($h['estado'] === 'descartada' ? chip('descartada', 'mute') : '') ?></div>
                    </div>
                    <div class="item-der"><?= monto_html($h['valor_venta']) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
