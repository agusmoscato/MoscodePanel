<?php
/** Vencimientos de dominios y servicios anuales, en línea de tiempo agrupada por urgencia. */
$titulo = 'Vencimientos';
$tipo = in_array(get('tipo'), ['dominio', 'servicio', 'cuota'], true) ? get('tipo') : '';
$horizonte = date('Y-m-d', strtotime('+180 days'));

$items = [];
if ($tipo === '' || $tipo === 'dominio') {
    foreach (filas(
        "SELECT d.*, d.fecha_vencimiento AS fecha, d.precio_cliente AS monto, d.moneda_precio AS moneda,
                c.id AS cid, c.nombre AS cliente, c.contacto, c.telefono
         FROM dominios d JOIN clientes c ON c.id = d.cliente_id
         WHERE d.usuario_id = {U} AND c.usuario_id = {U} AND d.estado = 'activo' AND d.fecha_vencimiento <= ?", [$horizonte]
    ) as $f) {
        $items[] = $f + ['tipo' => 'Dominio', 'nombre' => $f['dominio']];
    }
}
if ($tipo === '' || $tipo === 'servicio') {
    foreach (filas(
        "SELECT s.*, s.proximo_vencimiento AS fecha, c.id AS cid, c.nombre AS cliente, c.contacto, c.telefono
         FROM servicios s JOIN clientes c ON c.id = s.cliente_id
         WHERE s.usuario_id = {U} AND c.usuario_id = {U} AND s.estado = 'activo' AND s.tipo_cobro = 'anual' AND s.proximo_vencimiento IS NOT NULL AND s.proximo_vencimiento <= ?", [$horizonte]
    ) as $f) {
        $items[] = $f + ['tipo' => 'Servicio anual'];
    }
}
if ($tipo === '' || $tipo === 'cuota') {
    foreach (cuotas_proximas(180) as $f) {
        $items[] = ['id' => $f['id'], 'nombre' => $f['concepto'] . ' · cuota ' . $f['cuota_numero'] . '/' . $f['cuota_total'], 'fecha' => $f['fecha'],
            'monto' => $f['saldo'], 'moneda' => $f['moneda'], 'cid' => $f['cid'], 'cliente' => $f['cliente'], 'tipo' => 'Cuota'];
    }
}
usort($items, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));

// Agrupación: vencidos · esta semana (0-7 días) · este mes (hasta fin de mes) · próximos
$diasFinMes = dias_hasta(date('Y-m-t'));
$grupos = ['Vencidos' => [], 'Esta semana' => [], 'Este mes' => [], 'Próximos' => []];
foreach ($items as $it) {
    $it['dias'] = dias_hasta($it['fecha']);
    $g = $it['dias'] < 0 ? 'Vencidos' : ($it['dias'] <= 7 ? 'Esta semana' : ($it['dias'] <= $diasFinMes ? 'Este mes' : 'Próximos'));
    $grupos[$g][] = $it;
}
$iconoGrupo = ['Vencidos' => 'triangle-alert', 'Esta semana' => 'clock', 'Este mes' => 'calendar-days', 'Próximos' => 'calendar-clock'];
$urlTipo = fn(string $t) => url('vencimientos', $t !== '' ? ['tipo' => $t] : []);
?>
<div class="pagina-cab">
    <div>
        <h1>Vencimientos</h1>
        <div class="sub">Dominios, servicios anuales y cuotas de los próximos 6 meses</div>
    </div>
</div>

<div class="chips" role="group" aria-label="Filtrar por tipo">
    <a class="chip-f<?= $tipo === '' ? ' activo' : '' ?>" href="<?= e($urlTipo('')) ?>">Todos</a>
    <a class="chip-f<?= $tipo === 'dominio' ? ' activo' : '' ?>" href="<?= e($urlTipo('dominio')) ?>"><?= icono('globe', 'chico') ?>Dominios</a>
    <a class="chip-f<?= $tipo === 'servicio' ? ' activo' : '' ?>" href="<?= e($urlTipo('servicio')) ?>"><?= icono('layers', 'chico') ?>Servicios anuales</a>
    <a class="chip-f<?= $tipo === 'cuota' ? ' activo' : '' ?>" href="<?= e($urlTipo('cuota')) ?>"><?= icono('credit-card', 'chico') ?>Cuotas</a>
</div>

<?php if (!$items): ?>
    <div class="mt-16 card">
        <div class="vacio-est"><?= icono('calendar-days') ?><p><strong>No hay vencimientos próximos.</strong> Cuando cargues dominios o servicios anuales, los vas a ver acá.</p></div>
    </div>
<?php endif; ?>

<?php foreach ($grupos as $nombre => $lista): if (!$lista) { continue; } ?>
    <div class="grupo-tit<?= $nombre === 'Vencidos' ? ' bad' : '' ?>"><?= icono($iconoGrupo[$nombre], 'chico') ?><?= e($nombre) ?> <span class="suave">(<?= count($lista) ?>)</span></div>
    <ul class="timeline">
        <?php foreach ($lista as $it): $tipoU = tipo_urgencia($it['dias']);
            $esAvisable = $it['tipo'] === 'Servicio anual' || $it['tipo'] === 'Dominio';
            $ofrecerAviso = $esAvisable && ofrecer_aviso_renovacion((int) $it['dias'], (string) ($it['telefono'] ?? ''));
            $claveAviso = ($it['tipo'] === 'Dominio' ? 'dominio' : 'servicio') . ':' . (int) $it['id']; ?>
            <li class="tl-item <?= e($tipoU) ?>">
                <div class="card">
                    <div class="item-main">
                        <div class="item-tit mono"><a href="<?= e(url('cliente', ['id' => $it['cid']])) ?>"><?= e($it['nombre']) ?></a></div>
                        <div class="item-sub"><?= chip($it['tipo'], 'mute') ?><span class="trunc"><?= e($it['cliente']) ?></span></div>
                        <?php if ($esAvisable): ?>
                            <div class="suave" data-aviso-estado="<?= e($claveAviso) ?>"><?= !empty($it['aviso_renovacion_enviado_en']) ? 'aviso enviado el ' . e(date('d/m', strtotime($it['aviso_renovacion_enviado_en']))) : '' ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="item-der">
                        <?= chip_vencimiento($it['dias']) ?>
                        <span class="suave mono"><?= e(fecha_corta($it['fecha'])) ?></span>
                        <?php if ((float) $it['monto'] > 0): ?><?= monto_html($it['monto'], $it['moneda']) ?><?php endif; ?>
                        <?php if ($ofrecerAviso):
                            $clienteAviso = ['telefono' => $it['telefono'], 'contacto' => $it['contacto'], 'nombre' => $it['cliente']];
                            $itemAviso = $it['tipo'] === 'Dominio' ? item_renovacion_dominio($it) : $it;
                            $linkRenovacion = link_whatsapp_renovacion($clienteAviso, $itemAviso); ?>
                        <a class="btn sec chico" href="<?= e($linkRenovacion) ?>" target="_blank" rel="noopener noreferrer" data-aviso-renovacion="<?= e($claveAviso) ?>"><?= icono('message-circle', 'chico') ?>Avisar renovación</a>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endforeach; ?>
