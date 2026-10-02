<?php
/** Anular un pago: muestra qué se va a deshacer y pide el motivo (obligatorio). */
$id = (int) get('id', '0');
$p = fila('SELECT p.*, c.nombre AS cliente FROM pagos p JOIN clientes c ON c.id = p.cliente_id AND c.usuario_id = {U} WHERE p.id = ? AND p.usuario_id = {U}', [$id]);
if (!$p) {
    redirigir(url('clientes'));
}
$titulo = 'Anular pago';
$imps = filas(
    'SELECT i.monto_cargo, ca.concepto, ca.moneda, ca.estado FROM pago_imputaciones i JOIN cargos ca ON ca.id = i.cargo_id AND ca.usuario_id = {U}
     WHERE i.usuario_id = {U} AND i.pago_id = ? ORDER BY ca.fecha_vencimiento, ca.id',
    [$id]
);
$volver = url('cliente', ['id' => $p['cliente_id']]) . '#pagos';
?>
<div class="pagina-cab">
    <a class="btn fantasma chico" href="<?= e($volver) ?>"><?= icono('chevron-left', 'chico') ?><span class="trunc-btn"><?= e($p['cliente']) ?></span></a>
</div>
<div class="form-titulo"><h1>Anular pago</h1><p class="suave">Cliente: <?= e($p['cliente']) ?></p></div>

<?php if ($p['anulado_en'] !== null): ?>
    <div class="banner warn"><?= icono('triangle-alert') ?><div class="banner-txt"><strong>Este pago ya está anulado</strong> (<?= e(fmt_fecha_hora($p['anulado_en'])) ?>). Motivo: <?= e((string) $p['anulado_motivo']) ?></div></div>
<?php else: ?>
<section class="card">
    <h2 class="card-tit">Pago a anular</h2>
    <dl class="datos">
        <div><dt>Fecha</dt><dd class="mono"><?= e(fecha_corta($p['fecha'])) ?></dd></div>
        <div><dt>Monto</dt><dd><?= monto_html($p['monto'], $p['moneda']) ?></dd></div>
        <div><dt>Medio</dt><dd><?= e(MEDIOS_PAGO[$p['medio']] ?? $p['medio']) ?></dd></div>
        <?php if ($p['nota'] !== ''): ?><div><dt>Nota</dt><dd><?= e($p['nota']) ?></dd></div><?php endif; ?>
    </dl>
    <h3 class="card-tit mt-16">Qué se deshace</h3>
    <?php if (!$imps): ?>
        <p class="suave">Este pago no estaba imputado a ningún cargo (era saldo a favor): solo se marca como anulado.</p>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($imps as $i): ?>
                <li class="item"><div class="item-main"><div class="item-tit"><?= e($i['concepto']) ?></div></div>
                    <div class="item-der">vuelve a deberse <?= monto_html($i['monto_cargo'], $i['moneda']) ?></div></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p class="ayuda">El pago <strong>no se borra</strong>: queda en el historial como anulado, con quién, cuándo y por qué, y deja de contar en reportes y saldos.
        <?= $p['medio'] === 'mercadopago' ? 'Esto NO devuelve la plata en Mercado Pago: si hay que reembolsarla, hacelo desde tu cuenta de Mercado Pago.' : '' ?></p>
</section>

<form method="post" action="<?= e(url_accion('pago_anular')) ?>" data-validar novalidate>
    <?= csrf_campo() ?><?= nonce_campo() ?>
    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
    <section class="card">
        <label>Motivo de la anulación <span class="ayuda inline">(obligatorio)</span>
            <textarea name="motivo" rows="3" required minlength="5" maxlength="255" placeholder="Ej: se cargó dos veces / el cliente pagó otro monto"><?= e(viejo('motivo')) ?></textarea>
        </label>
    </section>
    <div class="form-fijo">
        <a class="btn sec" href="<?= e($volver) ?>">Cancelar</a>
        <button class="btn" type="submit"><?= icono('ban') ?>Anular el pago</button>
    </div>
</form>
<?php endif; ?>
