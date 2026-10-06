<?php
/** Servicios de todos los clientes (hosting, mantenimiento…): lista general con monto, cobro y próximo vencimiento. */
$titulo = 'Servicios';
$estado = get('estado', 'activo');
if (!in_array($estado, ['activo', 'pausado', 'baja', ''], true)) {
    $estado = 'activo';
}
$servicios = filas(
    "SELECT s.*, c.nombre AS cliente FROM servicios s JOIN clientes c ON c.id = s.cliente_id AND c.usuario_id = {U}
     WHERE s.usuario_id = {U}" . ($estado !== '' ? ' AND s.estado = ?' : '') . "
     ORDER BY FIELD(s.estado, 'activo', 'pausado', 'baja'), c.nombre, s.nombre",
    $estado !== '' ? [$estado] : []
);
?>
<div class="pagina-cab">
    <div>
        <h1>Servicios</h1>
        <div class="sub">Los servicios de todos tus clientes. Se cargan desde la ficha de cada cliente.</div>
    </div>
    <a class="btn chico" href="<?= e(url('elegir_cliente', ['para' => 'servicio'])) ?>"><?= icono('plus', 'chico') ?>Nuevo servicio</a>
</div>

<div class="segmentado" role="tablist" aria-label="Estado">
    <?php foreach (['activo' => 'Activos', 'pausado' => 'Pausados', 'baja' => 'De baja', '' => 'Todos'] as $v => $t): ?>
        <a class="chip-radio<?= $estado === $v ? ' activo' : '' ?>" href="<?= e(url('servicios', ['estado' => $v])) ?>"<?= $estado === $v ? ' aria-current="true"' : '' ?>><span><?= e($t) ?></span></a>
    <?php endforeach; ?>
</div>

<section class="card">
    <?php if (!$servicios): ?>
        <div class="vacio-est"><?= icono('layers') ?><p><strong>No hay servicios<?= $estado !== '' ? ' ' . e(['activo' => 'activos', 'pausado' => 'pausados', 'baja' => 'de baja'][$estado]) : '' ?>.</strong></p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($servicios as $s):
                $dd = ($s['tipo_cobro'] === 'anual' && $s['proximo_vencimiento']) ? dias_hasta($s['proximo_vencimiento']) : null; ?>
                <li class="item">
                    <a class="item-main" href="<?= e(url('servicio_form', ['id' => $s['id']])) ?>">
                        <div class="item-tit"><?= e($s['nombre']) ?> <span class="suave">· <?= e($s['cliente']) ?></span></div>
                        <div class="item-sub">
                            <?= chip_estado($s['estado']) ?>
                            <?= chip(ucfirst($s['tipo_cobro']), 'mute', $s['tipo_cobro'] === 'anual' ? 'calendar-days' : 'refresh-cw') ?>
                            <?php if ($s['por_cantidad']): ?><span><?= e(detalle_cantidad_servicio($s)) ?></span><?php endif; ?>
                            <?php if ($dd !== null && $s['estado'] === 'activo'): ?><?= chip_vencimiento($dd) ?><span class="mono"><?= e(fecha_corta($s['proximo_vencimiento'])) ?></span><?php endif; ?>
                        </div>
                    </a>
                    <div class="item-der">
                        <div class="stat-etq"><?= $s['tipo_cobro'] === 'anual' ? 'Por año' : 'Por mes' ?></div>
                        <?= monto_html($s['monto'], $s['moneda']) ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
