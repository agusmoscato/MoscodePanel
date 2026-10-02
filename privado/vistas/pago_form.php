<?php
/** Registro de un pago con imputación automática o manual a cargos (pantalla completa). */
$clienteId = (int) get('cliente_id', '0');
$cliente = fila('SELECT id, nombre FROM clientes WHERE id = ? AND usuario_id = {U}', [$clienteId]);
if (!$cliente) {
    redirigir(url('clientes'));
}
$titulo = 'Registrar pago';
$pendientes = cargos_pendientes($clienteId);
$deuda = deuda_cliente($clienteId);
$cot = cotizacion_valor();
$modo = viejo('modo', 'auto');
$volver = url('cliente', ['id' => $clienteId]);
$monedaSel = viejo('moneda', $deuda['ARS'] > 0.004 || $deuda['USD'] <= 0.004 ? 'ARS' : 'USD');
$montoSug = viejo('monto', (string) ($monedaSel === 'USD' ? ($deuda['USD'] > 0.004 ? $deuda['USD'] : '') : ($deuda['ARS'] > 0.004 ? $deuda['ARS'] : '')));
?>
<div class="pagina-cab">
    <a class="btn fantasma chico" href="<?= e($volver) ?>"><?= icono('chevron-left', 'chico') ?><span class="trunc-btn"><?= e($cliente['nombre']) ?></span></a>
</div>
<div class="form-titulo"><h1>Registrar pago</h1><p class="suave">Cliente: <?= e($cliente['nombre']) ?></p></div>

<div class="card">
    <div class="stat-etq">Deuda actual</div>
    <div class="stat-val"><?= $deuda['ARS'] > 0.004 || $deuda['USD'] > 0.004 ? montos_html($deuda) : monto_html(0) ?></div>
</div>

<form method="post" action="<?= e(url_accion('pago_guardar')) ?>" data-validar novalidate>
    <?= csrf_campo() ?><?= nonce_campo() ?>
    <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">

    <section class="card">
        <h2 class="card-tit">Pago</h2>
        <div class="fila-campos c2">
            <label>Monto
                <input type="number" name="monto" inputmode="decimal" required min="0.01" step="0.01" placeholder="0,00" value="<?= e($montoSug) ?>">
            </label>
            <div>
                <span class="etq">Moneda</span>
                <div class="segmentado" role="radiogroup" aria-label="Moneda">
                    <?php foreach (['ARS' => '$ Pesos', 'USD' => 'US$ Dólares'] as $v => $t): ?>
                        <label class="chip-radio"><input type="radio" name="moneda" value="<?= $v ?>"<?= $monedaSel === $v ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <span class="etq">Medio de pago</span>
        <div class="segmentado" role="radiogroup" aria-label="Medio de pago">
            <?php foreach (MEDIOS_PAGO as $v => $t): ?>
                <label class="chip-radio"><input type="radio" name="medio" value="<?= e($v) ?>"<?= viejo('medio', 'transferencia') === $v ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
            <?php endforeach; ?>
        </div>
        <div class="fila-campos c3">
            <label>Fecha
                <input type="date" name="fecha" required value="<?= e(viejo('fecha', date('Y-m-d'))) ?>">
            </label>
            <label>Dólar usado
                <input type="number" name="cotizacion" inputmode="decimal" min="0.01" step="0.01" value="<?= e(viejo('cotizacion', $cot ? (string) $cot : '')) ?>">
                <span class="ayuda">Se guarda con el pago. Necesario si algo es en USD.</span>
            </label>
            <label>Nota
                <input name="nota" maxlength="255" value="<?= e(viejo('nota')) ?>">
            </label>
        </div>
    </section>

    <section class="card">
        <h2 class="card-tit">Imputación</h2>
        <?php if (!$pendientes): ?>
            <p class="suave">No hay cargos pendientes: el pago queda registrado sin imputar (saldo a favor).</p>
            <input type="hidden" name="modo" value="auto">
        <?php else: ?>
            <div class="segmentado" role="radiogroup" aria-label="Modo de imputación">
                <label class="chip-radio"><input type="radio" name="modo" value="auto"<?= $modo === 'auto' ? ' checked' : '' ?>><span>Automática (más antiguo primero)</span></label>
                <label class="chip-radio"><input type="radio" name="modo" value="manual"<?= $modo === 'manual' ? ' checked' : '' ?>><span>Manual</span></label>
            </div>

            <div data-mostrar-si="modo=manual">
                <p class="suave">Escribí cuánto va a cada cargo, en la <strong>moneda del pago</strong>. Los que dejes vacíos no se tocan.</p>
                <ul class="lista imputar">
                    <?php foreach ($pendientes as $cg): ?>
                        <li class="item">
                            <div class="item-main">
                                <div class="item-tit"><?= e($cg['concepto']) ?></div>
                                <div class="item-sub"><span class="mono"><?= e(fecha_corta($cg['fecha_vencimiento'])) ?></span>Saldo <?= monto_html($cg['saldo'], $cg['moneda']) ?></div>
                            </div>
                            <input class="imp-input" type="number" name="imputar[<?= (int) $cg['id'] ?>]" inputmode="decimal" min="0" step="0.01" placeholder="0,00" aria-label="Monto a imputar a <?= e($cg['concepto']) ?>"
                                   value="<?= e($_SESSION['viejo']['imputar'][$cg['id']] ?? '') ?>">
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </section>

    <div class="form-fijo">
        <a class="btn sec" href="<?= e($volver) ?>">Cancelar</a>
        <button class="btn" type="submit"><?= icono('check') ?>Registrar pago</button>
    </div>
</form>
