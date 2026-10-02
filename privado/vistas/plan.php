<?php
/** Ficha de un plan en cuotas: progreso, cuotas con su estado y acciones (pagar por adelantado, editar, cancelar). */
$planId = (int) get('id', '0');
$plan = plan_propio($planId);
if (!$plan) {
    redirigir(url('planes'));
}
$cliente = cliente_propio((int) $plan['cliente_id']);
$cuotas = cuotas_de_plan($planId);
$titulo = $plan['concepto'];
$moneda = $plan['moneda'];

$pagadas = 0;
$cobrado = 0.0;
$saldo = 0.0;
$pendientes = 0;
foreach ($cuotas as $c) {
    if ($c['estado'] === 'anulado') {
        continue;
    }
    $cobrado += (float) $c['monto_pagado'];
    if ($c['estado'] === 'pagado') {
        $pagadas++;
    } else {
        $pendientes++;
        $saldo += (float) $c['saldo'];
    }
}
$estadoVisible = plan_estado_visible($plan, $pendientes);
$activo = $plan['estado'] === 'activo';
$pct = $plan['cuotas'] > 0 ? (int) round($pagadas / $plan['cuotas'] * 100) : 0;
$hoy = date('Y-m-d');
$pagable = array_filter($cuotas, fn($c) => in_array($c['estado'], ['pendiente', 'parcial'], true));
?>
<div class="pagina-cab">
    <a class="btn fantasma chico" href="<?= e(url('cliente', ['id' => $cliente['id']])) ?>"><?= icono('chevron-left', 'chico') ?><span class="trunc-btn"><?= e($cliente['nombre']) ?></span></a>
    <?php if ($activo && $pendientes): ?>
        <div class="acciones">
            <a class="btn sec chico" href="<?= e(url('plan_editar', ['id' => $planId])) ?>"><?= icono('pencil', 'chico') ?>Editar pendientes</a>
        </div>
    <?php endif; ?>
</div>
<div class="form-titulo">
    <h1><?= e($plan['concepto']) ?> <?= chip_estado($estadoVisible) ?></h1>
    <?php if ($plan['descripcion'] !== ''): ?><p class="suave"><?= e($plan['descripcion']) ?></p><?php endif; ?>
</div>

<div class="card">
    <div class="grid-3">
        <div><div class="stat-etq">Total</div><div class="stat-val"><?= monto_html($plan['monto_total'], $moneda) ?></div></div>
        <div><div class="stat-etq">Cobrado</div><div class="stat-val"><?= monto_html($cobrado, $moneda) ?></div></div>
        <div><div class="stat-etq">Saldo</div><div class="stat-val"><?= monto_html($saldo, $moneda) ?></div></div>
    </div>
    <p class="u-margin-12px-0-6px suave"><?= $pagadas ?> de <?= (int) $plan['cuotas'] ?> cuotas pagadas · <?= e(FRECUENCIAS[$plan['frecuencia']] ?? $plan['frecuencia']) ?></p>
    <div class="progreso" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Cuotas pagadas"><span data-ancho="<?= (int) $pct ?>"></span></div>
    <?php if ($plan['notas']): ?><p class="mt-12 suave"><?= nl2br(e($plan['notas'])) ?></p><?php endif; ?>
</div>

<form method="post" action="<?= e(url_accion('plan_pagar')) ?>" data-validar novalidate>
    <?= csrf_campo() ?><?= nonce_campo() ?>
    <input type="hidden" name="plan_id" value="<?= $planId ?>">
    <section class="card">
        <h2 class="card-tit">Cuotas</h2>
        <ul class="lista">
            <?php foreach ($cuotas as $c):
                $vence = $c['fecha_vencimiento'];
                $abierta = in_array($c['estado'], ['pendiente', 'parcial'], true);
                $visible = $abierta ? ($vence > $hoy ? 'a_vencer' : ($vence < $hoy ? 'vencido' : $c['estado'])) : $c['estado']; ?>
                <li class="item">
                    <?php if ($activo && $abierta): ?>
                        <label class="m-0 check"><input type="checkbox" name="cuotas[]" value="<?= (int) $c['id'] ?>" aria-label="Pagar la cuota <?= (int) $c['cuota_numero'] ?>"></label>
                    <?php endif; ?>
                    <div class="item-main">
                        <div class="item-tit">Cuota <?= (int) $c['cuota_numero'] ?>/<?= (int) $c['cuota_total'] ?></div>
                        <div class="item-sub">
                            <?= chip_estado($visible) ?>
                            <span class="mono"><?= e(fecha_corta($vence)) ?></span>
                            <?php if ($c['estado'] === 'parcial'): ?><span>pagado <?= monto_html($c['monto_pagado'], $moneda) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="item-der"><?= monto_html($abierta ? $c['saldo'] : $c['monto'], $moneda) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <?php if ($activo && $pagable): ?>
    <section class="card">
        <h2 class="card-tit">Registrar pago de las cuotas tildadas</h2>
        <p class="suave">Sirve para cobrar una cuota a término o pagar por adelantado cuotas que todavía no vencieron.</p>
        <div class="fila-campos c2">
            <label>Fecha del pago <input type="date" name="fecha" required value="<?= e($hoy) ?>"></label>
            <label>Medio
                <select name="medio"><?php foreach (MEDIOS_PAGO as $v => $t): ?><option value="<?= e($v) ?>"><?= e($t) ?></option><?php endforeach; ?></select>
            </label>
        </div>
        <button class="btn" type="submit"><?= icono('wallet') ?>Registrar pago</button>
    </section>
    <?php endif; ?>
</form>

<?php if ($activo): ?>
    <form method="post" action="<?= e(url_accion('plan_cancelar')) ?>" data-confirmar-titulo="Cancelar plan"
          data-confirmar="Las cuotas pendientes se anulan. Lo que ya se cobró queda registrado." data-confirmar-boton="Cancelar plan">
        <?= csrf_campo() ?><input type="hidden" name="plan_id" value="<?= $planId ?>">
        <button class="btn fantasma peligro-suave" type="submit"><?= icono('ban', 'chico') ?>Cancelar el plan</button>
    </form>
<?php endif; ?>
