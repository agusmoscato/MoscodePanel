<?php
/**
 * Selector de cliente para las acciones rápidas (registrar pago, nuevo servicio, nuevo dominio)
 * cuando se las dispara fuera de la ficha de un cliente. Solo navega: no modifica nada.
 */
$para = get('para');
$destinos = [
    'pago'     => ['Registrar pago', 'pago_form', 'wallet'],
    'servicio' => ['Nuevo servicio', 'servicio_form', 'layers'],
    'dominio'  => ['Nuevo dominio', 'dominio_form', 'globe'],
    'plan'     => ['Nueva venta en cuotas', 'plan_form', 'credit-card'],
];
if (!isset($destinos[$para])) {
    redirigir(url('dashboard'));
}
[$titulo, $paginaDestino, $icono] = $destinos[$para];
$buscar = get('q');
$clientes = clientes_filtrados($buscar, 'activo', false);
// Para registrar un pago, primero los que tienen deuda
if ($para === 'pago') {
    usort($clientes, fn($a, $b) => [!$a['tiene_deuda'], $a['nombre']] <=> [!$b['tiene_deuda'], $b['nombre']]);
}
?>
<div class="pagina-cab">
    <div>
        <h1><?= e($titulo) ?></h1>
        <div class="sub">Elegí el cliente</div>
    </div>
    <a class="btn sec chico" href="<?= e(url('dashboard')) ?>">Cancelar</a>
</div>

<form class="buscador" method="get" role="search" data-autoenviar>
    <input type="hidden" name="p" value="elegir_cliente">
    <input type="hidden" name="para" value="<?= e($para) ?>">
    <?= icono('search') ?>
    <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Buscar cliente" aria-label="Buscar cliente" autocomplete="off" autofocus>
</form>

<div class="card">
    <?php if (!$clientes): ?>
        <div class="vacio-est"><?= icono('users') ?><p><strong>No hay clientes</strong> para elegir.</p>
            <a class="btn" href="<?= e(url('cliente_form')) ?>"><?= icono('user-plus') ?>Nuevo cliente</a></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($clientes as $c): ?>
                <li class="item">
                    <div class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($c['nombre'], 0, 1))) ?></div>
                    <div class="item-main">
                        <div class="item-tit"><a href="<?= e(url($paginaDestino, ['cliente_id' => $c['id']])) ?>"><?= e($c['nombre']) ?></a></div>
                        <div class="item-sub">
                            <?php if ($para === 'pago'): ?>
                                <?= $c['tiene_deuda'] ? 'Debe ' . montos_html($c['deuda']) : chip_estado('al_dia') ?>
                            <?php else: ?>
                                <?= (int) $c['n_serv'] ?> servicio<?= (int) $c['n_serv'] === 1 ? '' : 's' ?> · <?= (int) $c['n_dom'] ?> dominio<?= (int) $c['n_dom'] === 1 ? '' : 's' ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a class="btn sec chico icono" href="<?= e(url($paginaDestino, ['cliente_id' => $c['id']])) ?>" aria-label="Elegir a <?= e($c['nombre']) ?>"><?= icono('chevron-right') ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
