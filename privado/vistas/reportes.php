<?php
/** Reportes: ingresos, facturado vs cobrado, ranking de clientes y rentabilidad de dominios. */
$titulo = 'Reportes';
$anio = (int) get('anio', date('Y'));
if ($anio < 2000 || $anio > 2100) {
    $anio = (int) date('Y');
}
$cot = cotizacion_valor();
$ingMes = rep_ingresos_mensual($anio);
$saldoCuotas = rep_saldo_cuotas($cot);
$ingAnio = rep_ingresos_anual();
$fact = rep_facturado_mensual($anio, $cot);
$ranking = rep_ranking_clientes($anio, $cot);
$doms = rep_rentabilidad_dominios($cot);

$totalAnio = array_sum(array_column($ingMes, 'total_ars'));
$facturadoAnio = array_sum(array_column($fact, 'facturado'));
$cobradoAnio = array_sum(array_column($fact, 'cobrado'));
$pendienteAnio = array_sum(array_column($fact, 'pendiente'));
$etiquetas = array_map(fn($m) => MESES_CORTOS[$m], range(1, 12));
$graf = fn(array $d) => e(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK));
$sumMargen = 0.0;
foreach ($doms as $d) {
    $sumMargen += (float) ($d['margen'] ?? 0);
}
$maxRanking = $ranking ? max(array_column($ranking, 'facturado')) : 0;
$mesesLargos = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
?>
<div class="pagina-cab">
    <div>
        <h1>Reportes</h1>
        <div class="sub">Ingresos, facturación y rentabilidad</div>
    </div>
    <div class="acciones">
        <a class="btn sec chico icono" href="<?= e(url('reportes', ['anio' => $anio - 1])) ?>" aria-label="Año <?= $anio - 1 ?>"><?= icono('chevron-left') ?></a>
        <span class="fw-600 mono"><?= $anio ?></span>
        <a class="btn sec chico icono" href="<?= e(url('reportes', ['anio' => $anio + 1])) ?>" aria-label="Año <?= $anio + 1 ?>"><?= icono('chevron-right') ?></a>
        <button class="btn sec chico icono" type="button" data-imprimir aria-label="Imprimir o guardar PDF" title="Imprimir / PDF"><?= icono('printer') ?></button>
    </div>
</div>

<?php if (!$cot): ?>
    <div class="banner warn"><?= icono('triangle-alert') ?><div class="banner-txt">No hay cotización del dólar guardada: los montos en USD no se pueden pasar a pesos en los reportes de facturación y dominios.</div></div>
<?php endif; ?>

<div class="grid-stats grid-4">
    <div class="card"><div class="stat-etq"><?= icono('wallet', 'chico') ?>Ingresos <?= $anio ?></div><div class="stat-val ok-txt"><?= monto_html($totalAnio) ?></div><div class="stat-pie">pagos recibidos</div></div>
    <div class="card"><div class="stat-etq"><?= icono('receipt', 'chico') ?>Facturado</div><div class="stat-val"><?= monto_html($facturadoAnio) ?></div><div class="stat-pie">cargos del año</div></div>
    <div class="card"><div class="stat-etq"><?= icono('clock', 'chico') ?>Pendiente</div><div class="stat-val<?= $pendienteAnio > 0.004 ? ' warn-txt' : '' ?>"><?= monto_html($pendienteAnio) ?></div><div class="stat-pie">de lo facturado</div></div>
    <div class="card"><div class="stat-etq"><?= icono('globe', 'chico') ?>Margen de dominios</div><div class="stat-val"><?= monto_html($sumMargen) ?></div><div class="stat-pie">por renovación</div></div>
</div>

<?php if ($saldoCuotas['cuotas'] > 0): ?>
<div class="card">
    <div class="stat-etq"><?= icono('credit-card', 'chico') ?>Saldo a cobrar en cuotas</div>
    <div class="stat-val"><?= montos_html($saldoCuotas['por_moneda']) ?></div>
    <div class="stat-pie"><?= (int) $saldoCuotas['cuotas'] ?> cuota<?= $saldoCuotas['cuotas'] > 1 ? 's' : '' ?> de <?= (int) $saldoCuotas['planes'] ?> plan<?= $saldoCuotas['planes'] > 1 ? 'es' : '' ?> activo<?= $saldoCuotas['planes'] > 1 ? 's' : '' ?><?= $saldoCuotas['total_ars'] !== null && $saldoCuotas['por_moneda']['USD'] > 0.004 ? ' · ≈ ' . monto_html($saldoCuotas['total_ars']) . ' al dólar vigente' : '' ?> · no es deuda hasta cada vencimiento</div>
</div>
<?php endif; ?>

<section class="card">
    <div class="card-cab"><h2>Ingresos por mes</h2>
        <a class="btn fantasma chico no-imprimir" href="<?= e(url_exportar('rep_ingresos', ['anio' => $anio])) ?>"><?= icono('download', 'chico') ?>CSV</a></div>
    <div class="grafico"><canvas data-grafico="<?= $graf(['tipo' => 'bar', 'etiquetas' => $etiquetas, 'series' => [['nombre' => 'Cobrado (ARS)', 'datos' => array_column($ingMes, 'total_ars')]]]) ?>" aria-label="Ingresos por mes" role="img"></canvas></div>
    <details class="acordeon" data-abierto-escritorio>
        <summary><span>Detalle por mes</span><?= icono('chevron-down') ?></summary>
        <div class="acordeon-cuerpo">
            <ul class="lista">
                <?php foreach ($ingMes as $r): ?>
                    <li class="item<?= $r['total_ars'] <= 0 ? ' item-apagado' : '' ?>">
                        <div class="item-main"><div class="item-tit"><?= e($mesesLargos[$r['mes']]) ?></div>
                            <?php if ($r['ars'] > 0 || $r['usd'] > 0): ?><div class="item-sub"><?= montos_html(['ARS' => $r['ars'], 'USD' => $r['usd']]) ?></div><?php endif; ?></div>
                        <div class="item-der"><strong><?= monto_html($r['total_ars']) ?></strong></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="u-margin-8px-0-0 suave">Los pagos en dólares se pasan a pesos con la cotización guardada en cada pago.</p>
        </div>
    </details>
</section>

<section class="card">
    <h2 class="card-tit">Ingresos por año</h2>
    <?php if (!$ingAnio): ?>
        <div class="vacio-est"><?= icono('chart-column') ?><p>Todavía no hay pagos registrados.</p></div>
    <?php else: ?>
        <div class="grafico"><canvas data-grafico="<?= $graf(['tipo' => 'bar', 'etiquetas' => array_map(fn($a) => (string) $a['anio'], $ingAnio), 'series' => [['nombre' => 'Cobrado (ARS)', 'datos' => array_column($ingAnio, 'total_ars')]]]) ?>" aria-label="Ingresos por año" role="img"></canvas></div>
        <ul class="lista">
            <?php foreach (array_reverse($ingAnio) as $r): ?>
                <li class="item">
                    <div class="item-main"><div class="item-tit mono"><?= (int) $r['anio'] ?></div>
                        <div class="item-sub"><?= montos_html(['ARS' => $r['ars'], 'USD' => $r['usd']]) ?></div></div>
                    <div class="item-der"><strong><?= monto_html($r['total_ars']) ?></strong></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-cab"><h2>Facturado vs cobrado</h2>
        <a class="btn fantasma chico no-imprimir" href="<?= e(url_exportar('rep_facturado', ['anio' => $anio])) ?>"><?= icono('download', 'chico') ?>CSV</a></div>
    <div class="grafico"><canvas data-grafico="<?= $graf(['tipo' => 'bar', 'etiquetas' => $etiquetas, 'series' => [['nombre' => 'Cobrado', 'datos' => array_column($fact, 'cobrado')], ['nombre' => 'Pendiente', 'datos' => array_column($fact, 'pendiente')]], 'apilado' => true]) ?>" aria-label="Cobrado y pendiente por mes" role="img"></canvas></div>
    <details class="acordeon" data-abierto-escritorio>
        <summary><span>Detalle por mes</span><?= icono('chevron-down') ?></summary>
        <div class="acordeon-cuerpo">
            <ul class="lista">
                <?php foreach ($fact as $r): ?>
                    <li class="item<?= $r['facturado'] <= 0 ? ' item-apagado' : '' ?>">
                        <div class="item-main"><div class="item-tit"><?= e($mesesLargos[$r['mes']]) ?></div>
                            <div class="item-sub">Facturado <?= monto_html($r['facturado']) ?></div></div>
                        <div class="item-der">
                            <span class="ok-txt"><?= monto_html($r['cobrado']) ?></span>
                            <?= $r['pendiente'] > 0.004 ? chip_html('pendiente ' . monto_html($r['pendiente']), 'warn') : '' ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="u-margin-8px-0-0 suave">Los cargos en dólares se pasan a pesos con la cotización del día en que se generó cada cargo (si alguno no la tiene guardada, con la de hoy<?= $cot ? ': ' . monto_html($cot) : '' ?>).</p>
        </div>
    </details>
</section>

<section class="card">
    <div class="card-cab"><h2>Ranking de clientes</h2>
        <a class="btn fantasma chico no-imprimir" href="<?= e(url_exportar('rep_ranking', ['anio' => $anio])) ?>"><?= icono('download', 'chico') ?>CSV</a></div>
    <?php if (!$ranking): ?>
        <div class="vacio-est"><?= icono('users') ?><p>Sin cargos en <?= $anio ?>.</p></div>
    <?php else: ?>
        <ol class="lista ranking">
            <?php foreach ($ranking as $i => $r): $ancho = $maxRanking > 0 ? max(2, (int) round($r['facturado'] / $maxRanking * 100)) : 0; ?>
                <li class="item">
                    <span class="puesto mono"><?= $i + 1 ?></span>
                    <div class="item-main">
                        <div class="item-tit"><a href="<?= e(url('cliente', ['id' => $r['cliente_id']])) ?>"><?= e($r['cliente']) ?></a></div>
                        <div class="barra-rel" aria-hidden="true"><span data-ancho="<?= (int) $ancho ?>"></span></div>
                        <div class="item-sub">Cobrado <?= monto_html($r['cobrado']) ?><?= $r['pendiente'] > 0.004 ? ' · pendiente ' . monto_html($r['pendiente']) : '' ?></div>
                    </div>
                    <div class="item-der"><strong><?= monto_html($r['facturado']) ?></strong></div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-cab"><h2>Rentabilidad de dominios</h2>
        <a class="btn fantasma chico no-imprimir" href="<?= e(url_exportar('rep_dominios')) ?>"><?= icono('download', 'chico') ?>CSV</a></div>
    <?php if (!$doms): ?>
        <div class="vacio-est"><?= icono('globe') ?><p>No hay dominios activos.</p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($doms as $d): ?>
                <li class="item">
                    <div class="item-main">
                        <div class="item-tit mono"><?= e($d['dominio']) ?></div>
                        <div class="item-sub"><?= e($d['cliente']) ?>
                            <?php if ($d['margen'] !== null): ?>· cobra <?= monto_html($d['precio_ars']) ?> · costo <?= monto_html($d['costo_ars']) ?><?php endif; ?></div>
                    </div>
                    <div class="item-der">
                        <?php if ($d['margen'] === null): ?>
                            <?= chip('sin cotización', 'warn') ?>
                        <?php else: ?>
                            <strong class="<?= $d['margen'] < 0 ? 'texto-mal' : '' ?>"><?= monto_html($d['margen']) ?></strong>
                            <?= $d['margen_pct'] === null ? '' : chip(number_format($d['margen_pct'], 1, ',', '.') . '%', $d['margen'] < 0 ? 'bad' : 'ok') ?>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card no-imprimir">
    <h2 class="card-tit">Exportar listados a CSV</h2>
    <p class="suave">Abren directo en Excel (UTF-8, separador «;», decimales con coma).</p>
    <div class="acciones">
        <?php foreach (['clientes' => 'Clientes', 'deudores' => 'Deudores', 'servicios' => 'Servicios', 'dominios' => 'Dominios', 'cargos' => 'Cargos pendientes', 'pagos' => 'Pagos ' . $anio] as $t => $n): ?>
            <a class="btn sec chico" href="<?= e(url_exportar($t, $t === 'pagos' ? ['anio' => $anio] : [])) ?>"><?= icono('download', 'chico') ?><?= e($n) ?></a>
        <?php endforeach; ?>
    </div>
</section>
