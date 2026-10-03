<?php
/** Alta / edición de un dominio de un cliente. */
$id = (int) get('id', '0');
$d = $id ? fila('SELECT * FROM dominios WHERE id = ? AND usuario_id = {U}', [$id]) : null;
$clienteId = $d ? (int) $d['cliente_id'] : (int) get('cliente_id', '0');
$cliente = fila('SELECT id, nombre FROM clientes WHERE id = ? AND usuario_id = {U}', [$clienteId]);
if (!$cliente) {
    redirigir(url('clientes'));
}
$d = $d ?? ['dominio' => '', 'proveedor' => '', 'fecha_vencimiento' => '', 'dias_anticipo' => ANTICIPO_POR_DEFECTO_DIAS, 'costo_renovacion' => '0', 'moneda_costo' => 'ARS', 'precio_cliente' => '0', 'moneda_precio' => 'ARS', 'estado' => 'activo'];
$titulo = $id ? 'Editar dominio' : 'Nuevo dominio';
$volver = url('cliente', ['id' => $clienteId]);
$monedas = ['ARS' => '$ Pesos', 'USD' => 'US$ Dólares'];
?>
<div class="pagina-cab">
    <a class="btn fantasma chico" href="<?= e($volver) ?>"><?= icono('chevron-left', 'chico') ?><span class="trunc-btn"><?= e($cliente['nombre']) ?></span></a>
</div>
<div class="form-titulo"><h1><?= e($titulo) ?></h1><p class="suave">Cliente: <?= e($cliente['nombre']) ?></p></div>

<form method="post" action="<?= e(url_accion('dominio_guardar')) ?>" data-validar novalidate>
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">

    <section class="card">
        <h2 class="card-tit">Dominio</h2>
        <label>Nombre del dominio
            <input class="mono" name="dominio" required maxlength="190" placeholder="ejemplo.com.ar" autocapitalize="none" spellcheck="false" value="<?= e(viejo('dominio', $d['dominio'])) ?>">
        </label>
        <div class="fila-campos c2">
            <label>Proveedor
                <input name="proveedor" maxlength="80" list="proveedores" placeholder="NIC.ar, GoDaddy, Hostinger…" value="<?= e(viejo('proveedor', $d['proveedor'])) ?>">
                <datalist id="proveedores"><option value="NIC.ar"><option value="GoDaddy"><option value="Hostinger"><option value="Namecheap"></datalist>
            </label>
            <label>Fecha de vencimiento
                <input type="date" name="fecha_vencimiento" required value="<?= e(viejo('fecha_vencimiento', $d['fecha_vencimiento'])) ?>">
            </label>
        </div>
        <label>Días de anticipo del cargo
            <input type="number" name="dias_anticipo" inputmode="numeric" min="0" max="365" step="1" value="<?= e(viejo('dias_anticipo', (string) $d['dias_anticipo'])) ?>">
            <span class="ayuda">El cargo al cliente se genera esta cantidad de días antes del vencimiento (0 a 365). Renovar el dominio no cambia esta fecha: eso se hace aparte, con el botón "Renovar".</span>
        </label>
    </section>

    <section class="card">
        <h2 class="card-tit">Costos y precio</h2>
        <div class="fila-campos c2">
            <label>Costo de renovación <span class="inline ayuda">(lo que pagás vos)</span>
                <input type="number" name="costo_renovacion" inputmode="decimal" min="0" step="0.01" value="<?= e(viejo('costo_renovacion', (string) $d['costo_renovacion'])) ?>">
            </label>
            <div>
                <span class="etq">Moneda del costo</span>
                <div class="segmentado" role="radiogroup" aria-label="Moneda del costo">
                    <?php foreach ($monedas as $v => $t): ?>
                        <label class="chip-radio"><input type="radio" name="moneda_costo" value="<?= $v ?>"<?= viejo('moneda_costo', $d['moneda_costo']) === $v ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <label>Precio al cliente
                <input type="number" name="precio_cliente" inputmode="decimal" min="0" step="0.01" value="<?= e(viejo('precio_cliente', (string) $d['precio_cliente'])) ?>">
            </label>
            <div>
                <span class="etq">Moneda del precio</span>
                <div class="segmentado" role="radiogroup" aria-label="Moneda del precio">
                    <?php foreach ($monedas as $v => $t): ?>
                        <label class="chip-radio"><input type="radio" name="moneda_precio" value="<?= $v ?>"<?= viejo('moneda_precio', $d['moneda_precio']) === $v ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <h2 class="card-tit">Estado</h2>
        <div class="mb-0 segmentado" role="radiogroup" aria-label="Estado">
            <?php foreach (['activo' => 'Activo', 'baja' => 'Dado de baja'] as $v => $t): ?>
                <label class="chip-radio"><input type="radio" name="estado" value="<?= $v ?>"<?= viejo('estado', $d['estado']) === $v ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="form-fijo">
        <a class="btn sec" href="<?= e($volver) ?>">Cancelar</a>
        <button class="btn" type="submit"><?= icono('check') ?>Guardar</button>
    </div>
</form>

<?php if ($id): ?>
<div class="zona-peligro">
    <form method="post" action="<?= e(url_accion('dominio_eliminar')) ?>"
          data-confirmar-titulo="Eliminar dominio" data-confirmar="Se elimina este dominio. Los cargos ya generados se conservan." data-confirmar-boton="Eliminar">
        <?= csrf_campo() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn peligro chico" type="submit"><?= icono('trash-2', 'chico') ?>Eliminar dominio</button>
    </form>
</div>
<?php endif; ?>
