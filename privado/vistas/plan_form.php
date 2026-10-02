<?php
/**
 * Nueva venta en cuotas. Paso 1: datos del plan. Paso 2 (vista previa): cuotas editables con la suma validada,
 * y opcionalmente "ya cobré las primeras N cuotas".
 */
$clienteId = (int) get('cliente_id', '0');
$cliente = cliente_propio($clienteId);
if (!$cliente) {
    redirigir(url('elegir_cliente', ['para' => 'plan']));
}
$titulo = 'Nueva venta en cuotas';
$volver = url('cliente', ['id' => $clienteId]);

$dato = fn(string $k, string $def = '') => viejo($k, get($k, $def));
$concepto = $dato('concepto');
$descripcion = $dato('descripcion');
$totalTxt = $dato('monto_total');
$moneda = $dato('moneda', 'ARS');
$n = (int) $dato('cuotas', '3');
$frecuencia = $dato('frecuencia', 'mensual');
$primera = $dato('fecha_primera', date('Y-m-d'));
$notas = $dato('notas');
$total = a_decimal($totalTxt);

$previa = get('previsualizar') === '1';
$errorPaso1 = '';
$cuotas = [];
if ($previa) {
    if ($concepto === '' || $total < 0.01 || $n < 1 || $n > PLAN_MAX_CUOTAS || !fecha_valida($primera)
        || !in_array($moneda, ['ARS', 'USD'], true) || !isset(FRECUENCIAS[$frecuencia])) {
        $errorPaso1 = 'Completá el concepto, un monto mayor a cero, entre 1 y ' . PLAN_MAX_CUOTAS . ' cuotas y una fecha válida.';
        $previa = false;
    } else {
        $cuotas = cuotas_por_defecto($total, $n, $primera, $frecuencia);
        // Si el guardado falló, se conservan las cuotas que se estaban editando
        $editadas = $_SESSION['viejo']['cuotas_det'] ?? null;
        if (is_array($editadas) && count($editadas) === $n) {
            $cuotas = [];
            foreach (array_values($editadas) as $c) {
                $cuotas[] = ['monto' => a_decimal((string) ($c['monto'] ?? '')), 'fecha' => (string) ($c['fecha'] ?? '')];
            }
        }
    }
}
$cot = cotizacion_valor();
?>
<div class="pagina-cab">
    <a class="btn fantasma chico" href="<?= e($volver) ?>"><?= icono('chevron-left', 'chico') ?><span class="trunc-btn"><?= e($cliente['nombre']) ?></span></a>
</div>
<div class="form-titulo"><h1>Nueva venta en cuotas</h1><p class="suave">Cliente: <?= e($cliente['nombre']) ?></p></div>

<?php if ($errorPaso1 !== ''): ?>
    <div class="error-campo" role="alert"><?= icono('circle-alert') ?><span><?= e($errorPaso1) ?></span></div>
<?php endif; ?>

<form method="get" action="<?= e(url('plan_form')) ?>" data-validar novalidate>
    <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
    <input type="hidden" name="previsualizar" value="1">
    <section class="card">
        <h2 class="card-tit">Plan</h2>
        <label>Concepto <input name="concepto" required maxlength="160" placeholder="Ej: Desarrollo web" value="<?= e($concepto) ?>"></label>
        <label>Descripción <span class="inline ayuda">(opcional)</span> <input name="descripcion" maxlength="255" value="<?= e($descripcion) ?>"></label>
        <div class="fila-campos c2">
            <label>Monto total <input type="number" name="monto_total" inputmode="decimal" required min="0.01" step="0.01" value="<?= e($totalTxt) ?>"></label>
            <div>
                <span class="etq">Moneda</span>
                <div class="segmentado" role="radiogroup" aria-label="Moneda">
                    <?php foreach (['ARS' => '$ Pesos', 'USD' => 'US$ Dólares'] as $v => $t): ?>
                        <label class="chip-radio"><input type="radio" name="moneda" value="<?= $v ?>"<?= $moneda === $v ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="fila-campos c3">
            <label>Cantidad de cuotas <input type="number" name="cuotas" inputmode="numeric" required min="1" max="<?= PLAN_MAX_CUOTAS ?>" value="<?= $n ?>"></label>
            <label>Frecuencia
                <select name="frecuencia">
                    <?php foreach (FRECUENCIAS as $v => $t): ?><option value="<?= e($v) ?>"<?= $frecuencia === $v ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Fecha de la primera cuota <input type="date" name="fecha_primera" required value="<?= e($primera) ?>"></label>
        </div>
        <label>Notas <span class="inline ayuda">(opcional)</span> <textarea name="notas" rows="2" maxlength="1000"><?= e($notas) ?></textarea></label>
        <button class="btn<?= $previa ? ' sec' : '' ?>" type="submit"><?= icono('list-checks') ?><?= $previa ? 'Recalcular cuotas' : 'Ver cuotas' ?></button>
    </section>
</form>

<?php if ($previa): ?>
<form method="post" action="<?= e(url_accion('plan_guardar')) ?>" data-validar novalidate>
    <?= csrf_campo() ?><?= nonce_campo() ?>
    <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
    <input type="hidden" name="concepto" value="<?= e($concepto) ?>">
    <input type="hidden" name="descripcion" value="<?= e($descripcion) ?>">
    <input type="hidden" name="monto_total" value="<?= e((string) $total) ?>">
    <input type="hidden" name="moneda" value="<?= e($moneda) ?>">
    <input type="hidden" name="cuotas" value="<?= $n ?>">
    <input type="hidden" name="frecuencia" value="<?= e($frecuencia) ?>">
    <input type="hidden" name="fecha_primera" value="<?= e($primera) ?>">
    <input type="hidden" name="notas" value="<?= e($notas) ?>">

    <section class="card" data-suma-cuotas data-total="<?= e((string) $total) ?>">
        <h2 class="card-tit">Cuotas</h2>
        <p class="suave">Total <?= monto_html($total, $moneda) ?> en <?= $n ?> cuota<?= $n > 1 ? 's' : '' ?>. Podés ajustar el monto y la fecha de cada una; la suma tiene que dar el total.</p>
        <ul class="lista">
            <?php foreach ($cuotas as $i => $c): ?>
                <li class="item">
                    <div class="item-main"><div class="item-tit">Cuota <?= $i + 1 ?>/<?= $n ?></div></div>
                    <div class="fila-flex item-der">
                        <input type="date" name="cuotas_det[<?= $i ?>][fecha]" required value="<?= e($c['fecha']) ?>" aria-label="Fecha de la cuota <?= $i + 1 ?>">
                        <input type="number" class="max-140 mono" name="cuotas_det[<?= $i ?>][monto]" data-suma-cuota required min="0.01" step="0.01" value="<?= e(number_format((float) $c['monto'], 2, '.', '')) ?>" aria-label="Monto de la cuota <?= $i + 1 ?>">
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="ayuda" data-suma-estado aria-live="polite">Suma: <?= e(fmt_monto($total, $moneda)) ?></p>
    </section>

    <section class="card">
        <h2 class="card-tit">¿Ya cobraste alguna cuota?</h2>
        <div class="fila-campos c3">
            <label>Cuotas ya cobradas
                <select name="pagadas">
                    <?php for ($k = 0; $k <= $n; $k++): ?><option value="<?= $k ?>"<?= (int) viejo('pagadas', '0') === $k ? ' selected' : '' ?>><?= $k === 0 ? 'Ninguna' : 'Las primeras ' . $k ?></option><?php endfor; ?>
                </select>
            </label>
            <label>Fecha del pago <input type="date" name="pago_fecha" value="<?= e(viejo('pago_fecha', date('Y-m-d'))) ?>"></label>
            <label>Medio
                <select name="pago_medio">
                    <?php foreach (MEDIOS_PAGO as $v => $t): ?><option value="<?= e($v) ?>"<?= viejo('pago_medio', 'transferencia') === $v ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
        <p class="ayuda">Se registran como pagos de esas cuotas, con la fecha y el medio indicados.<?= $moneda === 'USD' ? ($cot ? ' Cotización del dólar vigente: ' . e(fmt_monto($cot)) . '.' : ' Falta cargar la cotización del dólar para registrar pagos en USD.') : '' ?></p>
    </section>

    <div class="form-fijo">
        <a class="btn sec" href="<?= e($volver) ?>">Cancelar</a>
        <button class="btn" type="submit"><?= icono('check') ?>Crear plan</button>
    </div>
</form>
<?php endif; ?>
