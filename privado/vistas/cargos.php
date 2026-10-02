<?php
/** Cobros: cargos (cuenta corriente) agrupados por cliente, con filtros. */
$titulo = 'Cobros';
$estado = get('estado', 'abiertos');
$periodo = get('periodo');
$buscar = get('q');
$cargos = cargos_filtrados($estado, $periodo, $buscar, 300);
$filtrosUrl = ['estado' => $estado, 'periodo' => $periodo, 'q' => $buscar];

// Agrupar por cliente (respeta el orden de la consulta) y sumar lo pendiente de cada uno
$grupos = [];
foreach ($cargos as $cg) {
    $g = &$grupos[$cg['cliente_id']];
    $g['id'] = (int) $cg['cliente_id'];
    $g['nombre'] = $cg['cliente'];
    $g['items'][] = $cg;
    if (in_array($cg['estado'], ['pendiente', 'parcial'], true)) {
        $g['saldo'][$cg['moneda']] = ($g['saldo'][$cg['moneda']] ?? 0.0) + ((float) $cg['monto'] - (float) $cg['monto_pagado']);
    }
    unset($g);
}
$estados = ['abiertos' => 'Pendientes', 'parcial' => 'Parciales', 'a_vencer' => 'Cuotas a vencer', 'pagado' => 'Pagados', 'anulado' => 'Anulados', 'todos' => 'Todos'];
$chipUrl = fn(string $e) => url('cargos', array_filter(['estado' => $e === 'abiertos' ? '' : $e, 'periodo' => $periodo, 'q' => $buscar], fn($v) => $v !== ''));
$hayFiltros = $buscar !== '' || $periodo !== '' || $estado !== 'abiertos';
?>
<div class="pagina-cab">
    <div>
        <h1>Cobros</h1>
        <div class="sub">Cargos y pagos de tus clientes</div>
    </div>
    <div class="acciones">
        <a class="btn sec chico icono" href="<?= e(url_exportar('cargos', array_filter($filtrosUrl))) ?>" aria-label="Exportar a CSV" title="Exportar CSV"><?= icono('download') ?></a>
        <button class="btn sec chico icono" type="button" data-imprimir aria-label="Imprimir o guardar PDF" title="Imprimir / PDF"><?= icono('printer') ?></button>
        <form method="post" action="<?= e(url_accion('cargos_generar')) ?>" class="en-linea"
              data-confirmar-titulo="Generar cargos del mes"
              data-confirmar="Se generan los cargos de <?= e(mes_nombre(date('Y-m'))) ?>. Los que ya existen no se duplican." data-confirmar-boton="Generar">
            <?= csrf_campo() ?>
            <button class="btn sec chico" type="submit"><?= icono('refresh-cw', 'chico') ?>Generar cargos</button>
        </form>
        <button class="btn chico" type="button" data-abrir="pago"><?= icono('wallet', 'chico') ?>Registrar pago</button>
    </div>
</div>

<form class="buscador" method="get" role="search" data-autoenviar>
    <input type="hidden" name="p" value="cargos">
    <?php if ($estado !== 'abiertos'): ?><input type="hidden" name="estado" value="<?= e($estado) ?>"><?php endif; ?>
    <?php if ($periodo !== ''): ?><input type="hidden" name="periodo" value="<?= e($periodo) ?>"><?php endif; ?>
    <?= icono('search') ?>
    <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Buscar cliente o concepto" aria-label="Buscar cargos" enterkeyhint="search" autocomplete="off">
</form>

<div class="chips" role="group" aria-label="Estado de los cargos">
    <?php foreach ($estados as $v => $t): ?>
        <a class="chip-f<?= $estado === $v ? ' activo' : '' ?>" href="<?= e($chipUrl($v)) ?>"><?= e($t) ?></a>
    <?php endforeach; ?>
    <form method="get" class="chip-mes">
        <input type="hidden" name="p" value="cargos">
        <?php if ($estado !== 'abiertos'): ?><input type="hidden" name="estado" value="<?= e($estado) ?>"><?php endif; ?>
        <?php if ($buscar !== ''): ?><input type="hidden" name="q" value="<?= e($buscar) ?>"><?php endif; ?>
        <label class="chip-f<?= $periodo !== '' ? ' activo' : '' ?>"><?= icono('calendar-days', 'chico') ?>
            <input type="month" name="periodo" value="<?= e($periodo) ?>" aria-label="Filtrar por período" data-enviar-al-cambiar>
        </label>
    </form>
    <?php if ($hayFiltros): ?><a class="chip-f" href="<?= e(url('cargos')) ?>"><?= icono('x', 'chico') ?>Limpiar</a><?php endif; ?>
</div>

<div class="cuenta-res"><?= count($cargos) ?> cargo<?= count($cargos) === 1 ? '' : 's' ?> en <?= count($grupos) ?> cliente<?= count($grupos) === 1 ? '' : 's' ?><?= count($cargos) === 300 ? ' (se muestran los primeros 300; afiná los filtros, el CSV trae todos)' : '' ?></div>

<?php if (!$cargos): ?>
    <div class="card">
        <div class="vacio-est">
            <?= icono($estado === 'abiertos' && !$hayFiltros ? 'circle-check' : 'receipt') ?>
            <?php if (!$hayFiltros): ?>
                <p><strong>No hay cargos pendientes.</strong> Todo está cobrado, o todavía no generaste los del mes.</p>
                <form method="post" action="<?= e(url_accion('cargos_generar')) ?>"><?= csrf_campo() ?><button class="btn" type="submit"><?= icono('refresh-cw') ?>Generar cargos del mes</button></form>
            <?php else: ?>
                <p><strong>No hay cargos</strong> con esos filtros.</p>
                <a class="btn sec" href="<?= e(url('cargos')) ?>">Limpiar filtros</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($grupos as $g): ?>
    <section class="card grupo-cliente" aria-label="<?= e($g['nombre']) ?>">
        <header class="gc-cab">
            <div class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($g['nombre'], 0, 1))) ?></div>
            <div class="item-main">
                <div class="item-tit"><a href="<?= e(url('cliente', ['id' => $g['id']])) ?>"><?= e($g['nombre']) ?></a></div>
                <div class="item-sub"><?= isset($g['saldo']) ? 'Debe ' . montos_html($g['saldo']) : chip_estado('al_dia') ?></div>
            </div>
            <?php if (isset($g['saldo'])): ?>
                <a class="btn chico" href="<?= e(url('pago_form', ['cliente_id' => $g['id']])) ?>" data-abrir="pago" data-cliente="<?= $g['id'] ?>" aria-label="Registrar pago de <?= e($g['nombre']) ?>"><?= icono('wallet', 'chico') ?>Pagar</a>
            <?php endif; ?>
        </header>
        <ul class="lista">
            <?php foreach ($g['items'] as $cg):
                $abierto = in_array($cg['estado'], ['pendiente', 'parcial'], true);
                $dv = dias_hasta($cg['fecha_vencimiento']); ?>
                <li class="item">
                    <div class="item-main">
                        <div class="item-tit"><?= e($cg['concepto']) ?></div>
                        <div class="item-sub">
                            <span class="mono"><?= e(fecha_corta($cg['fecha_vencimiento'])) ?></span>
                            <?= chip_estado($dv < 0 && $abierto ? 'vencido' : $cg['estado']) ?>
                        </div>
                    </div>
                    <div class="item-der">
                        <?php if ($cg['estado'] === 'anulado'): ?>
                            <span class="suave"><?= monto_html($cg['monto'], $cg['moneda']) ?></span>
                        <?php elseif ($abierto): ?>
                            <?= monto_html($cg['monto'] - $cg['monto_pagado'], $cg['moneda']) ?>
                            <?php if ($cg['estado'] === 'parcial'): ?><span class="suave">de <?= monto_html($cg['monto'], $cg['moneda']) ?></span><?php endif; ?>
                        <?php else: ?>
                            <?= monto_html($cg['monto'], $cg['moneda']) ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($cg['estado'] === 'pendiente' && (float) $cg['monto_pagado'] == 0.0): ?>
                        <form class="en-linea no-imprimir" method="post" action="<?= e(url_accion('cargo_anular')) ?>"
                              data-confirmar-titulo="Anular cargo" data-confirmar="Se anula «<?= e($cg['concepto']) ?>». No se puede deshacer." data-confirmar-boton="Anular">
                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $cg['id'] ?>">
                            <button class="btn fantasma chico icono peligro-suave" type="submit" aria-label="Anular cargo" title="Anular"><?= icono('x') ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
