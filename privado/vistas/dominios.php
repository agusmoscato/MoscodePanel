<?php
/** Dominios de todos los clientes: lista general por fecha de vencimiento, con costo y precio al cliente. */
$titulo = 'Dominios';
$estado = get('estado', 'activo');
if (!in_array($estado, ['activo', 'baja', ''], true)) {
    $estado = 'activo';
}
$dominios = filas(
    "SELECT d.*, c.nombre AS cliente FROM dominios d JOIN clientes c ON c.id = d.cliente_id AND c.usuario_id = {U}
     WHERE d.usuario_id = {U}" . ($estado !== '' ? ' AND d.estado = ?' : '') . "
     ORDER BY d.estado, d.fecha_vencimiento, d.dominio",
    $estado !== '' ? [$estado] : []
);
?>
<div class="pagina-cab">
    <div>
        <h1>Dominios</h1>
        <div class="sub">Los dominios de todos tus clientes, el que vence primero arriba. Se cargan desde la ficha de cada cliente.</div>
    </div>
    <a class="btn chico" href="<?= e(url('elegir_cliente', ['para' => 'dominio'])) ?>"><?= icono('plus', 'chico') ?>Nuevo dominio</a>
</div>

<div class="segmentado" role="tablist" aria-label="Estado">
    <?php foreach (['activo' => 'Activos', 'baja' => 'De baja', '' => 'Todos'] as $v => $t): ?>
        <a class="chip-radio<?= $estado === $v ? ' activo' : '' ?>" href="<?= e(url('dominios', ['estado' => $v])) ?>"<?= $estado === $v ? ' aria-current="true"' : '' ?>><span><?= e($t) ?></span></a>
    <?php endforeach; ?>
</div>

<section class="card">
    <?php if (!$dominios): ?>
        <div class="vacio-est"><?= icono('globe') ?><p><strong>No hay dominios<?= $estado !== '' ? ' ' . e(['activo' => 'activos', 'baja' => 'de baja'][$estado]) : '' ?>.</strong></p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($dominios as $d): ?>
                <li class="item">
                    <a class="item-main" href="<?= e(url('dominio_form', ['id' => $d['id']])) ?>">
                        <div class="item-tit"><span class="mono"><?= e($d['dominio']) ?></span> <span class="suave">· <?= e($d['cliente']) ?></span></div>
                        <div class="item-sub">
                            <?= $d['estado'] === 'baja' ? chip_estado('baja') : chip_vencimiento(dias_hasta($d['fecha_vencimiento'])) ?>
                            <span class="mono"><?= e(fecha_corta($d['fecha_vencimiento'])) ?></span>
                            <?php if ($d['proveedor']): ?><?= chip($d['proveedor'], 'mute') ?><?php endif; ?>
                            <span>Costo <?= monto_html($d['costo_renovacion'], $d['moneda_costo']) ?></span>
                        </div>
                    </a>
                    <div class="item-der">
                        <div class="stat-etq">Al cliente</div>
                        <?= monto_html($d['precio_cliente'], $d['moneda_precio']) ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
