<?php
/** Dashboard: totales del mes, deudores, próximos vencimientos y cotización. */
$titulo = 'Inicio';
$periodo = date('Y-m');
$cot = cotizacion_vigente();
$cotValor = $cot ? (float) $cot['valor_venta'] : null;

// Totales del mes actual (por moneda)
$mes = filas(
    "SELECT moneda, SUM(monto) AS total, SUM(monto_pagado) AS cobrado, SUM(monto - monto_pagado) AS pendiente
     FROM cargos WHERE usuario_id = {U} AND periodo = ? AND estado <> 'anulado' AND " . sql_exigible_o_pagado() . " GROUP BY moneda",
    [$periodo]
);
$aCobrar = por_moneda($mes, 'total');
$cobrado = por_moneda($mes, 'cobrado');
$pendiente = por_moneda($mes, 'pendiente');

// Deuda acumulada de meses anteriores
$ant = filas(
    "SELECT moneda, SUM(monto - monto_pagado) AS saldo FROM cargos
     WHERE usuario_id = {U} AND periodo < ? AND estado IN ('pendiente','parcial') AND " . sql_exigible() . " GROUP BY moneda",
    [$periodo]
);
$deudaAnt = por_moneda($ant, 'saldo');

// Deudores: deuda total por cliente (todos los cargos pendientes)
$deudores = [];
foreach (filas(
    "SELECT c.id, c.nombre, ca.moneda, SUM(ca.monto - ca.monto_pagado) AS saldo
     FROM cargos ca JOIN clientes c ON c.id = ca.cliente_id
     WHERE ca.usuario_id = {U} AND c.usuario_id = {U} AND ca.estado IN ('pendiente','parcial') AND " . sql_exigible('ca') . " GROUP BY c.id, c.nombre, ca.moneda"
) as $f) {
    $deudores[$f['id']]['nombre'] = $f['nombre'];
    $deudores[$f['id']]['monedas'][$f['moneda']] = (float) $f['saldo'];
}
foreach ($deudores as $id => &$d) {
    $d['id'] = $id;
    $d['ars'] = a_ars($d['monedas'], $cotValor) ?? ($d['monedas']['ARS'] ?? 0);
}
unset($d);
usort($deudores, fn($a, $b) => $b['ars'] <=> $a['ars']);

// Próximos vencimientos (dominios y servicios anuales) hasta 30 días, incluidos los ya vencidos
$limite = date('Y-m-d', strtotime('+30 days'));
$venc = [];
foreach (filas(
    "SELECT d.id, d.dominio AS nombre, d.fecha_vencimiento AS fecha, c.id AS cid, c.nombre AS cliente
     FROM dominios d JOIN clientes c ON c.id = d.cliente_id
     WHERE d.usuario_id = {U} AND c.usuario_id = {U} AND d.estado = 'activo' AND d.fecha_vencimiento <= ?", [$limite]
) as $f) {
    $venc[] = $f + ['tipo' => 'Dominio'];
}
foreach (filas(
    "SELECT s.id, s.nombre, s.proximo_vencimiento AS fecha, c.id AS cid, c.nombre AS cliente
     FROM servicios s JOIN clientes c ON c.id = s.cliente_id
     WHERE s.usuario_id = {U} AND c.usuario_id = {U} AND s.estado = 'activo' AND s.tipo_cobro = 'anual' AND s.proximo_vencimiento <= ?", [$limite]
) as $f) {
    $venc[] = $f + ['tipo' => 'Servicio anual'];
}
// Cuotas de ventas en cuotas que vencen en los próximos 30 días (o ya vencidas con saldo)
foreach (cuotas_proximas(30) as $f) {
    $venc[] = ['id' => $f['id'], 'nombre' => $f['concepto'] . ' · cuota ' . $f['cuota_numero'] . '/' . $f['cuota_total'], 'fecha' => $f['fecha'], 'cid' => $f['cid'], 'cliente' => $f['cliente'], 'tipo' => 'Cuota'];
}
usort($venc, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));
foreach ($venc as &$v) {
    $v['dias'] = dias_hasta($v['fecha']);
}
unset($v);
$errorCot = cfg('cotizacion_error');

// --- Presentación: protagonista del mes ---
$totalArs = a_ars($aCobrar, $cotValor);
$cobradoArs = a_ars($cobrado, $cotValor);
$convertible = $totalArs !== null && $cobradoArs !== null;       // hay USD y no hay cotización → se muestra por moneda
$baseTotal = $convertible ? $totalArs : (float) $aCobrar['ARS'];
$baseCobrado = $convertible ? $cobradoArs : (float) $cobrado['ARS'];
$pct = $baseTotal > 0 ? (int) round(min(100, $baseCobrado / $baseTotal * 100)) : 0;
$hayCargos = abs($aCobrar['ARS']) > 0.004 || abs($aCobrar['USD']) > 0.004;

$vencen7 = count(array_filter($venc, fn($v) => $v['dias'] <= 7));
$vencidos = count(array_filter($venc, fn($v) => $v['dias'] < 0));

// Datos de contacto de los deudores (para el botón de WhatsApp), en una sola consulta
$contactos = [];
if ($deudores) {
    $ids = array_map(fn($d) => (int) $d['id'], $deudores);
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    foreach (filas("SELECT id, nombre, contacto, telefono, portal_token FROM clientes WHERE usuario_id = {U} AND id IN ($marcas)", $ids) as $c) {
        $contactos[(int) $c['id']] = $c;
    }
}

// Redes: publicaciones de los próximos 7 días (hoy incluido). La card se muestra solo a quien usa el módulo.
$redesSemana = usa_redes() ? publicaciones_entre(date('Y-m-d'), date('Y-m-d', strtotime('+6 days'))) : null;
?>
<div class="pagina-cab">
    <div>
        <h1>Inicio</h1>
        <div class="sub"><?= e(ucfirst(mes_nombre($periodo))) ?></div>
    </div>
    <div class="acciones">
        <form method="post" action="<?= e(url_accion('cargos_generar')) ?>" class="en-linea"
              data-confirmar-titulo="Generar cargos del mes"
              data-confirmar="Se generan los cargos de <?= e(mes_nombre($periodo)) ?>. Los que ya existen no se duplican."
              data-confirmar-boton="Generar">
            <?= csrf_campo() ?>
            <button class="btn sec chico" type="submit"><?= icono('refresh-cw', 'chico') ?>Generar cargos del mes</button>
        </form>
    </div>
</div>

<?php if (es_admin() && cotizaciones_pendientes()): ?>
    <div class="banner warn" role="alert"><?= icono('triangle-alert') ?>
        <div class="banner-txt"><strong>Hay una cotización del dólar pendiente de confirmar</strong> (varió más del <?= (int) COTIZACION_SALTO_PCT ?>%). <a href="<?= e(url('cotizacion')) ?>">Confirmarla o descartarla</a>.</div></div>
<?php endif; ?>
<?php if (!$cot): ?>
    <div class="banner warn" role="alert"><?= icono('triangle-alert') ?>
        <div class="banner-txt">Todavía no hay cotización del dólar. <a href="<?= e(url('cotizacion')) ?>">Actualizala o cargala a mano</a>.</div></div>
<?php elseif ($errorCot !== ''): ?>
    <div class="banner warn" role="alert"><?= icono('triangle-alert') ?>
        <div class="banner-txt"><strong>No se pudo actualizar el dólar.</strong> Se usa la última cotización guardada (<?= e(fmt_fecha_hora($cot['creado_en'])) ?>).
            <div class="hereda-opaco suave"><?= e($errorCot) ?></div>
            <form method="post" action="<?= e(url_accion('cotizacion_actualizar')) ?>" class="mt-8">
                <?= csrf_campo() ?><button class="btn sec chico" type="submit"><?= icono('refresh-cw', 'chico') ?>Reintentar ahora</button>
            </form>
        </div></div>
<?php endif; ?>

<section class="card hero" aria-labelledby="hero-tit">
    <div class="hero-etq" id="hero-tit"><?= icono('wallet', 'chico') ?>A cobrar en <?= e(mes_nombre($periodo)) ?></div>
    <?php if ($hayCargos): ?>
        <div class="hero-total"><?= $convertible ? monto_html($totalArs) : montos_html($aCobrar) ?></div>
        <?php if ($convertible && $aCobrar['USD'] > 0.004): ?>
            <div class="hero-sub">Incluye <?= monto_html($aCobrar['USD'], 'USD') ?> al dólar de <?= monto_html($cotValor) ?></div>
        <?php elseif (!$convertible): ?>
            <div class="hero-sub">Sin cotización del dólar: los totales se muestran por moneda.</div>
        <?php endif; ?>

        <div class="hero-prog"><span>Cobrado</span><strong><?= $pct ?>%</strong></div>
        <div class="progreso" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Porcentaje cobrado del mes"><span data-ancho="<?= (int) $pct ?>"></span></div>
        <div class="hero-cifras">
            <div><small>Cobrado</small><?= montos_html($cobrado, 'ok-txt') ?></div>
            <div><small>Pendiente</small><?= montos_html($pendiente) ?></div>
        </div>
    <?php else: ?>
        <div class="hero-total"><?= monto_html(0) ?></div>
        <div class="hero-sub">Todavía no hay cargos generados este mes.</div>
    <?php endif; ?>
</section>

<div class="grid-stats">
    <div class="card">
        <div class="stat-etq"><?= icono('clock', 'chico') ?>Deuda acumulada</div>
        <div class="stat-val"><?= montos_html($deudaAnt) ?></div>
        <div class="stat-pie">de meses anteriores</div>
    </div>
    <div class="card">
        <div class="stat-etq"><?= icono('users', 'chico') ?>Deudores</div>
        <div class="stat-val"><?= count($deudores) ?></div>
        <div class="stat-pie">clientes con saldo</div>
    </div>
    <div class="card">
        <div class="stat-etq"><?= icono('calendar-clock', 'chico') ?>Vencen en 7 días</div>
        <div class="stat-val"><?= $vencen7 ?></div>
        <div class="stat-pie"><?= $vencidos ? e($vencidos . ' ya vencido' . ($vencidos > 1 ? 's' : '')) : 'ninguno vencido' ?></div>
    </div>
</div>

<div class="grid-2">
    <section class="card" aria-labelledby="venc-tit">
        <div class="card-cab">
            <h2 id="venc-tit">Próximos vencimientos</h2>
            <a class="btn fantasma chico" href="<?= e(url('vencimientos')) ?>">Ver todos<?= icono('chevron-right', 'chico') ?></a>
        </div>
        <?php if (!$venc): ?>
            <div class="vacio-est"><?= icono('calendar-days') ?><p><strong>Todo al día.</strong> Nada vence en los próximos 30 días.</p></div>
        <?php else: ?>
            <ul class="lista">
                <?php foreach (array_slice($venc, 0, 8) as $v): ?>
                    <li class="item">
                        <div class="item-main">
                            <div class="item-tit mono"><?= e($v['nombre']) ?></div>
                            <div class="item-sub"><?= chip($v['tipo'], 'mute') ?><a href="<?= e(url('cliente', ['id' => $v['cid']])) ?>" class="trunc"><?= e($v['cliente']) ?></a></div>
                        </div>
                        <div class="item-der">
                            <?= chip_vencimiento($v['dias']) ?>
                            <span class="suave mono"><?= e(fecha_corta($v['fecha'])) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($venc) > 8): ?><p class="u-margin-12px-0-0 suave">y <?= count($venc) - 8 ?> más…</p><?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="deu-tit">
        <div class="card-cab">
            <h2 id="deu-tit">Clientes con deuda</h2>
            <a class="btn fantasma chico" href="<?= e(url_exportar('deudores')) ?>" title="Descargar CSV"><?= icono('download', 'chico') ?>CSV</a>
        </div>
        <?php if (!$deudores): ?>
            <div class="vacio-est"><?= icono('circle-check') ?><p><strong>Nadie debe nada.</strong> Todos los clientes están al día.</p></div>
        <?php else: ?>
            <ul class="lista">
                <?php foreach ($deudores as $dd):
                    $c = $contactos[(int) $dd['id']] ?? null;
                    $wa = $c ? link_whatsapp($c) : '';
                    $ini = mb_strtoupper(mb_substr($dd['nombre'], 0, 1)); ?>
                    <li class="item">
                        <div class="avatar" aria-hidden="true"><?= e($ini) ?></div>
                        <div class="item-main">
                            <div class="item-tit"><a href="<?= e(url('cliente', ['id' => $dd['id']])) ?>"><?= e($dd['nombre']) ?></a></div>
                            <div class="item-sub"><?= montos_html($dd['monedas']) ?></div>
                        </div>
                        <div class="item-acc">
                            <?php if ($wa !== ''): ?>
                                <a class="btn sec chico icono" href="<?= e($wa) ?>" target="_blank" rel="noopener noreferrer" aria-label="Cobrar a <?= e($dd['nombre']) ?> por WhatsApp" title="WhatsApp"><?= icono('message-circle') ?></a>
                            <?php endif; ?>
                            <a class="btn chico icono" href="<?= e(url('pago_form', ['cliente_id' => $dd['id']])) ?>" data-abrir="pago" data-cliente="<?= (int) $dd['id'] ?>" aria-label="Registrar pago de <?= e($dd['nombre']) ?>" title="Registrar pago"><?= icono('wallet') ?></a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<?php if ($redesSemana !== null): ?>
<section class="card mt-16" aria-labelledby="redes-tit">
    <div class="card-cab">
        <h2 id="redes-tit">Redes esta semana</h2>
        <a class="btn fantasma chico" href="<?= e(url('redes')) ?>">Calendario<?= icono('chevron-right', 'chico') ?></a>
    </div>
    <?php if (!$redesSemana): ?>
        <div class="pad-8-0 vacio-est"><?= icono('megaphone') ?><p>Nada programado para los próximos 7 días.</p>
            <a class="btn sec chico" href="<?= e(url('publicacion_form')) ?>" data-abrir="publicacion"><?= icono('plus', 'chico') ?>Nueva publicación</a></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($redesSemana as $p): ?><?= pub_item_html($p, true) ?><?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php endif; ?>
