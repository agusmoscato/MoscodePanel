<?php
/** Resumen de cuenta de un cliente, pensado para imprimir o guardar como PDF desde el navegador (fondo blanco). */
$id = (int) get('id', '0');
$c = cliente_propio($id);
if (!$c) {
    redirigir(url('clientes'));
}
$titulo = 'Resumen de cuenta';
$cot = cotizacion_vigente();
$cotValor = $cot ? (float) $cot['valor_venta'] : null;
$pend = cargos_pendientes($id);
$deuda = deuda_cliente($id);
$totalArs = a_ars($deuda, $cotValor);
$pagos = filas('SELECT * FROM pagos WHERE usuario_id = {U} AND cliente_id = ? AND anulado_en IS NULL ORDER BY fecha DESC, id DESC LIMIT 20', [$id]);
?>
<div class="pagina-cab no-imprimir">
    <a class="btn fantasma chico" href="<?= e(url('cliente', ['id' => $id])) ?>"><?= icono('chevron-left', 'chico') ?><span class="trunc-btn"><?= e($c['nombre']) ?></span></a>
    <button class="btn" type="button" data-imprimir><?= icono('printer') ?>Imprimir / guardar PDF</button>
</div>

<article class="card hoja">
    <header class="hoja-cabecera">
        <div>
            <div class="hoja-marca"><?= e(cfg('nombre_propio') ?: 'Resumen de cuenta') ?></div>
            <div class="suave">Resumen de cuenta al <span class="mono"><?= e(fecha_corta(date('Y-m-d'))) ?></span></div>
        </div>
        <div class="derecha">
            <strong><?= e($c['nombre']) ?></strong><br>
            <?php if ($c['cuit']): ?><span class="suave">CUIT <span class="mono"><?= e($c['cuit']) ?></span></span><br><?php endif; ?>
            <?php if ($c['contacto']): ?><span class="suave">Atn. <?= e($c['contacto']) ?></span><?php endif; ?>
        </div>
    </header>

    <h2>Saldo adeudado</h2>
    <div class="hero-total hoja-total"><?= $deuda['ARS'] > 0.004 || $deuda['USD'] > 0.004 ? montos_html($deuda) : monto_html(0) ?></div>
    <?php if ($totalArs !== null && $deuda['USD'] > 0.004): ?>
        <div class="suave">Equivale a <?= monto_html($totalArs) ?> (dólar <?= e(mb_strtolower(DOLAR_TIPOS[cfg('dolar_tipo', 'blue')] ?? '')) ?> venta a <?= monto_html($cotValor) ?>, <?= e(fmt_fecha_hora($cot['creado_en'])) ?>)</div>
    <?php endif; ?>

    <h2 class="hoja-sec">Detalle de cargos pendientes</h2>
    <?php if (!$pend): ?>
        <p class="suave">No hay cargos pendientes.</p>
    <?php else: ?>
        <div class="tabla-scroll"><table class="tabla-hoja">
            <thead><tr><th>Concepto</th><th>Vence</th><th class="num">Monto</th><th class="num">Pagado</th><th class="num">Saldo</th></tr></thead>
            <tbody>
            <?php foreach ($pend as $cg): ?>
                <tr><td><?= e($cg['concepto']) ?></td><td class="mono"><?= e(fecha_corta($cg['fecha_vencimiento'])) ?></td>
                    <td class="num"><?= monto_html($cg['monto'], $cg['moneda']) ?></td><td class="num"><?= monto_html($cg['monto_pagado'], $cg['moneda']) ?></td>
                    <td class="num"><strong><?= monto_html($cg['saldo'], $cg['moneda']) ?></strong></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>

    <h2 class="hoja-sec">Últimos pagos recibidos</h2>
    <?php if (!$pagos): ?>
        <p class="suave">Sin pagos registrados.</p>
    <?php else: ?>
        <div class="tabla-scroll"><table class="tabla-hoja">
            <thead><tr><th>Fecha</th><th>Medio</th><th class="num">Monto</th><th>Nota</th></tr></thead>
            <tbody>
            <?php foreach ($pagos as $p): ?>
                <tr><td class="mono"><?= e(fecha_corta($p['fecha'])) ?></td><td><?= e(MEDIOS_PAGO[$p['medio']] ?? $p['medio']) ?></td>
                    <td class="num"><?= monto_html($p['monto'], $p['moneda']) ?></td><td><?= e($p['nota']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>

    <?php if (cfg('alias_cbu') !== ''): ?>
        <p class="hoja-pie"><strong>Datos para transferir:</strong> <span class="mono"><?= e(cfg('alias_cbu')) ?></span><?= cfg('nombre_propio') !== '' ? ' — ' . e(cfg('nombre_propio')) : '' ?></p>
    <?php endif; ?>
</article>
