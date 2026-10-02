<?php
/** Ventas en cuotas: lista general con progreso, cobrado, saldo y próxima cuota. */
$titulo = 'Cuotas';
$estado = get('estado', 'activo');
if (!in_array($estado, ['activo', 'completado', 'cancelado', ''], true)) {
    $estado = 'activo';
}
$planes = planes_listado(null, $estado);
$resumen = rep_saldo_cuotas(cotizacion_valor() ?: null)['por_moneda'];
?>
<div class="pagina-cab">
    <div>
        <h1>Ventas en cuotas</h1>
        <div class="sub">Cobros únicos pagados en cuotas (desarrollos, proyectos…)</div>
    </div>
    <a class="btn chico" href="<?= e(url('elegir_cliente', ['para' => 'plan'])) ?>"><?= icono('plus', 'chico') ?>Nueva venta en cuotas</a>
</div>

<?php if (array_sum($resumen) > 0.004): ?>
    <div class="card">
        <div class="stat-etq">Saldo a cobrar en cuotas</div>
        <div class="stat-val"><?= montos_html($resumen) ?></div>
    </div>
<?php endif; ?>

<div class="segmentado" role="tablist" aria-label="Estado">
    <?php foreach (['activo' => 'Activos', 'completado' => 'Completados', 'cancelado' => 'Cancelados', '' => 'Todos'] as $v => $t): ?>
        <a class="chip-radio<?= $estado === $v ? ' activo' : '' ?>" href="<?= e(url('planes', ['estado' => $v])) ?>"<?= $estado === $v ? ' aria-current="true"' : '' ?>><span><?= e($t) ?></span></a>
    <?php endforeach; ?>
</div>

<section class="card">
    <?php if (!$planes): ?>
        <div class="vacio-est"><?= icono('credit-card') ?><p><strong>No hay ventas en cuotas<?= $estado !== '' ? ' ' . e(['activo' => 'activas', 'completado' => 'completadas', 'cancelado' => 'canceladas'][$estado]) : '' ?>.</strong></p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($planes as $p):
                $pct = $p['cuotas'] > 0 ? (int) round($p['n_pagadas'] / $p['cuotas'] * 100) : 0; ?>
                <li class="item">
                    <a class="item-main" href="<?= e(url('plan', ['id' => $p['id']])) ?>">
                        <div class="item-tit"><?= e($p['concepto']) ?> <span class="suave">· <?= e($p['cliente']) ?></span></div>
                        <div class="item-sub">
                            <?= chip_estado($p['estado_visible']) ?>
                            <span><?= (int) $p['n_pagadas'] ?>/<?= (int) $p['cuotas'] ?> cuotas pagadas</span>
                            <span>Cobrado <?= monto_html($p['cobrado'], $p['moneda']) ?></span>
                            <?php if ($p['proxima']): ?><span>Próxima: cuota <?= (int) $p['proxima']['cuota_numero'] ?> · <?= e(fecha_corta($p['proxima']['fecha_vencimiento'])) ?></span><?php endif; ?>
                        </div>
                        <div class="progreso" role="img" aria-label="<?= $pct ?>% pagado"><span data-ancho="<?= (int) $pct ?>"></span></div>
                    </a>
                    <div class="item-der">
                        <div class="stat-etq">Saldo</div>
                        <?= monto_html($p['saldo'], $p['moneda']) ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
