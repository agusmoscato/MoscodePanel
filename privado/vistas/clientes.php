<?php
/** Listado de clientes con búsqueda y filtros. */
$titulo = 'Clientes';
$buscar = get('q');
$estado = get('estado', 'activo');
$soloDeuda = get('deuda') === '1';
$clientes = clientes_filtrados($buscar, $estado, $soloDeuda);
$filtrosUrl = ['q' => $buscar, 'estado' => $estado, 'deuda' => $soloDeuda ? '1' : ''];

// Próximo vencimiento por cliente (dominios activos y servicios anuales activos)
$proximos = [];
foreach (filas(
    "SELECT cliente_id, MIN(f) AS f FROM (
        SELECT cliente_id, fecha_vencimiento AS f FROM dominios WHERE usuario_id = {U} AND estado = 'activo'
        UNION ALL
        SELECT cliente_id, proximo_vencimiento FROM servicios WHERE usuario_id = {U} AND estado = 'activo' AND tipo_cobro = 'anual' AND proximo_vencimiento IS NOT NULL
     ) x GROUP BY cliente_id"
) as $r) {
    $proximos[(int) $r['cliente_id']] = $r['f'];
}

// Chips de filtro (muestran lo activo y permiten cambiarlo con un toque)
$chipUrl = fn(array $cambios) => url('clientes', array_filter(array_merge($filtrosUrl, $cambios), fn($v) => $v !== '' && $v !== null));
$hayFiltros = $buscar !== '' || $estado !== 'activo' || $soloDeuda;
?>
<div class="pagina-cab">
    <div>
        <h1>Clientes</h1>
    </div>
    <div class="acciones">
        <a class="btn sec chico icono" href="<?= e(url_exportar('clientes', array_filter($filtrosUrl))) ?>" aria-label="Exportar a CSV" title="Exportar CSV"><?= icono('download') ?></a>
        <button class="btn sec chico icono" type="button" data-imprimir aria-label="Imprimir o guardar PDF" title="Imprimir / PDF"><?= icono('printer') ?></button>
        <a class="btn chico" href="<?= e(url('cliente_form')) ?>"><?= icono('user-plus', 'chico') ?>Nuevo cliente</a>
    </div>
</div>

<form class="buscador" method="get" role="search" data-autoenviar>
    <input type="hidden" name="p" value="clientes">
    <?php if ($estado !== 'activo'): ?><input type="hidden" name="estado" value="<?= e($estado) ?>"><?php endif; ?>
    <?php if ($soloDeuda): ?><input type="hidden" name="deuda" value="1"><?php endif; ?>
    <?= icono('search') ?>
    <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Buscar clientes" aria-label="Buscar clientes" enterkeyhint="search" autocomplete="off">
</form>

<div class="chips" role="group" aria-label="Filtros">
    <a class="chip-f<?= $estado === 'activo' ? ' activo' : '' ?>" href="<?= e($chipUrl(['estado' => ''])) ?>">Activos</a>
    <a class="chip-f<?= $estado === 'inactivo' ? ' activo' : '' ?>" href="<?= e($chipUrl(['estado' => 'inactivo'])) ?>">Inactivos</a>
    <a class="chip-f<?= $estado === 'todos' ? ' activo' : '' ?>" href="<?= e($chipUrl(['estado' => 'todos'])) ?>">Todos</a>
    <a class="chip-f<?= $soloDeuda ? ' activo' : '' ?>" href="<?= e($chipUrl(['deuda' => $soloDeuda ? '' : '1'])) ?>"><?= icono('circle-alert', 'chico') ?>Con deuda</a>
    <?php if ($hayFiltros): ?><a class="chip-f" href="<?= e(url('clientes')) ?>"><?= icono('x', 'chico') ?>Limpiar</a><?php endif; ?>
</div>

<div class="cuenta-res"><?= count($clientes) ?> cliente<?= count($clientes) === 1 ? '' : 's' ?><?= $buscar !== '' ? ' para “' . e($buscar) . '”' : '' ?></div>

<?php if (!$clientes): ?>
    <div class="card">
        <div class="vacio-est">
            <?= icono('users') ?>
            <?php if ($hayFiltros): ?>
                <p><strong>No encontramos clientes</strong> con esos filtros.</p>
                <a class="btn sec" href="<?= e(url('clientes')) ?>">Limpiar filtros</a>
            <?php else: ?>
                <p><strong>Todavía no cargaste clientes.</strong> Empezá agregando el primero.</p>
                <a class="btn" href="<?= e(url('cliente_form')) ?>"><?= icono('user-plus') ?>Nuevo cliente</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card flush tabla">
        <table class="tabla-resp">
            <thead>
                <tr><th>Cliente</th><th>Contacto</th><th class="num">Servicios</th><th class="num">Dominios</th><th>Próx. vencimiento</th><th class="num">Deuda</th></tr>
            </thead>
            <tbody>
            <?php foreach ($clientes as $c):
                $prox = $proximos[(int) $c['id']] ?? null;
                $dias = $prox ? dias_hasta($prox) : null; ?>
                <tr data-href="<?= e(url('cliente', ['id' => $c['id']])) ?>">
                    <td class="celda-titulo">
                        <a href="<?= e(url('cliente', ['id' => $c['id']])) ?>" class="u-color-var-text trunc"><?= e($c['nombre']) ?></a>
                        <?php if ($c['estado'] === 'inactivo'): ?><?= chip_estado('inactivo') ?><?php endif; ?>
                    </td>
                    <td class="sin-movil"><span class="trunc"><?= e($c['contacto'] ?: '—') ?></span><?php if ($c['telefono']): ?><span class="suave mono"><?= e($c['telefono']) ?></span><?php endif; ?></td>
                    <td class="num" data-label="Servicios"><?= (int) $c['n_serv'] ?></td>
                    <td class="num sin-movil" data-label="Dominios"><?= (int) $c['n_dom'] ?></td>
                    <td data-label="Próx. vencimiento">
                        <?php if ($prox): ?><span class="mono"><?= e(fecha_corta($prox)) ?></span> <?= chip_vencimiento($dias) ?><?php else: ?><span class="suave">—</span><?php endif; ?>
                    </td>
                    <td class="celda-monto num">
                        <?= $c['tiene_deuda'] ? '<span class="chip bad">' . montos_html($c['deuda']) . '</span>' : chip_estado('al_dia') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
