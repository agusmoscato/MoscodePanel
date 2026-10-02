<?php
/** Edita las cuotas pendientes de un plan (monto y fecha), redistribuyendo el saldo sin cambiar el total pendiente. */
$planId = (int) get('id', '0');
$plan = plan_propio($planId);
if (!$plan || $plan['estado'] !== 'activo') {
    redirigir(url('planes'));
}
$titulo = 'Editar cuotas';
$moneda = $plan['moneda'];
$editables = array_values(array_filter(cuotas_de_plan($planId), fn($c) => in_array($c['estado'], ['pendiente', 'parcial'], true)));
if (!$editables) {
    redirigir(url('plan', ['id' => $planId]));
}
$totalPend = 0.0;
foreach ($editables as $c) {
    $totalPend += (float) $c['monto'];
}
$totalPend = round($totalPend, 2);
$enviadas = $_SESSION['viejo']['cuotas'] ?? [];
?>
<div class="pagina-cab">
    <a class="btn fantasma chico" href="<?= e(url('plan', ['id' => $planId])) ?>"><?= icono('chevron-left', 'chico') ?><span class="trunc-btn"><?= e($plan['concepto']) ?></span></a>
</div>
<div class="form-titulo"><h1>Editar cuotas pendientes</h1><p class="suave">El total de las pendientes (<?= e(fmt_monto($totalPend, $moneda)) ?>) no cambia: si movés un monto, ajustá otra cuota.</p></div>

<form method="post" action="<?= e(url_accion('plan_editar_guardar')) ?>" data-validar novalidate>
    <?= csrf_campo() ?>
    <input type="hidden" name="plan_id" value="<?= $planId ?>">
    <section class="card" data-suma-cuotas data-total="<?= e((string) $totalPend) ?>">
        <ul class="lista">
            <?php foreach ($editables as $c):
                $id = (int) $c['id'];
                $fecha = $enviadas[$id]['fecha'] ?? $c['fecha_vencimiento'];
                $monto = isset($enviadas[$id]['monto']) ? a_decimal((string) $enviadas[$id]['monto']) : (float) $c['monto']; ?>
                <li class="item">
                    <div class="item-main">
                        <div class="item-tit">Cuota <?= (int) $c['cuota_numero'] ?>/<?= (int) $c['cuota_total'] ?></div>
                        <?php if ((float) $c['monto_pagado'] > 0): ?><div class="item-sub suave">Ya pagado <?= monto_html($c['monto_pagado'], $moneda) ?> (no puede quedar por debajo)</div><?php endif; ?>
                    </div>
                    <div class="fila-flex item-der">
                        <input type="date" name="cuotas[<?= $id ?>][fecha]" required value="<?= e($fecha) ?>" aria-label="Fecha de la cuota <?= (int) $c['cuota_numero'] ?>">
                        <input type="number" class="max-140 mono" name="cuotas[<?= $id ?>][monto]" data-suma-cuota required min="0.01" step="0.01" value="<?= e(number_format($monto, 2, '.', '')) ?>" aria-label="Monto de la cuota <?= (int) $c['cuota_numero'] ?>">
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="ayuda" data-suma-estado aria-live="polite"></p>
    </section>
    <div class="form-fijo">
        <a class="btn sec" href="<?= e(url('plan', ['id' => $planId])) ?>">Cancelar</a>
        <button class="btn" type="submit"><?= icono('check') ?>Guardar cuotas</button>
    </div>
</form>
