<?php
/**
 * Redes: calendario mensual de publicaciones (lunes a domingo) y vista de lista con las próximas.
 *   /redes?mes=2026-10&dia=2026-10-04          calendario (dia = el seleccionado: en el celular se ve su lista debajo)
 *   /redes?vista=lista&tipo=Reel&estado=listo   próximas, con filtros
 *   /redes?vista=lista&atrasadas=1              de días pasados sin marcar como Publicado
 * Todo funciona sin JS (links); app.js agrega abrir el formulario en el bottom sheet y elegir el día sin recargar.
 */
$titulo = 'Redes';
$vista = get('vista') === 'lista' ? 'lista' : 'calendario';
$hoy = date('Y-m-d');
$tipos = redes_tipos();
$nAtrasadas = publicaciones_atrasadas_cantidad();

if ($vista === 'calendario') {
    $mes = mes_valido(get('mes')) ? get('mes') : date('Y-m');
    $semanas = calendario_semanas($mes);
    $desde = $semanas[0][0];
    $hasta = $semanas[count($semanas) - 1][6];
    $porDia = [];
    foreach (publicaciones_entre($desde, $hasta) as $p) {
        $porDia[$p['fecha']][] = $p;
    }
    $dia = get('dia');
    if (!fecha_valida($dia) || $dia < $desde || $dia > $hasta) {
        $dia = substr($hoy, 0, 7) === $mes ? $hoy : $mes . '-01';
    }
    $mesAnt = date('Y-m', strtotime($mes . '-01 -1 month'));
    $mesSig = date('Y-m', strtotime($mes . '-01 +1 month'));
    $pubsMes = array_sum(array_map('count', array_filter($porDia, fn($f) => str_starts_with($f, $mes), ARRAY_FILTER_USE_KEY)));
} else {
    $fTipo = get('tipo');
    $fTipo = ($fTipo !== '' && mb_strlen($fTipo) <= PUB_TIPO_MAX) ? $fTipo : '';
    $fEstado = isset(PUB_ESTADOS[get('estado')]) ? get('estado') : '';
    $atrasadas = get('atrasadas') === '1';
    $pubs = $atrasadas ? publicaciones_atrasadas($fTipo) : publicaciones_proximas($fTipo, $fEstado);
    $grupos = [];
    foreach ($pubs as $p) {
        $grupos[$p['fecha']][] = $p;
    }
    $urlLista = fn(array $cambios) => url('redes', array_merge(
        ['vista' => 'lista', 'tipo' => $fTipo, 'estado' => $atrasadas ? '' : $fEstado, 'atrasadas' => $atrasadas ? '1' : ''], $cambios));
}
$nuevaHref = url('publicacion_form', ['fecha' => $vista === 'calendario' ? $dia : $hoy]);
?>
<div class="pagina-cab">
    <div>
        <h1>Redes</h1>
        <div class="sub">Calendario de publicaciones</div>
    </div>
    <div class="acciones">
        <a class="btn chico" href="<?= e($nuevaHref) ?>" data-abrir="publicacion" data-fecha="<?= e($vista === 'calendario' ? $dia : $hoy) ?>"><?= icono('plus', 'chico') ?>Nueva publicación</a>
    </div>
</div>

<div class="chips mb-16" role="group" aria-label="Vista">
    <a class="chip-f<?= $vista === 'calendario' ? ' activo' : '' ?>" href="<?= e(url('redes')) ?>"<?= $vista === 'calendario' ? ' aria-current="page"' : '' ?>><?= icono('calendar-days', 'chico') ?>Calendario</a>
    <a class="chip-f<?= $vista === 'lista' && !$atrasadas ? ' activo' : '' ?>" href="<?= e(url('redes', ['vista' => 'lista'])) ?>"<?= $vista === 'lista' ? ' aria-current="page"' : '' ?>><?= icono('list', 'chico') ?>Lista</a>
    <?php if ($nAtrasadas): ?>
        <a class="chip-f<?= $vista === 'lista' && $atrasadas ? ' activo' : '' ?>" href="<?= e(url('redes', ['vista' => 'lista', 'atrasadas' => '1'])) ?>"><?= icono('triangle-alert', 'chico') ?>Atrasadas (<?= $nAtrasadas ?>)</a>
    <?php endif; ?>
</div>

<?php if ($vista === 'calendario'): ?>
<section class="card cal" aria-labelledby="cal-tit" data-calendario data-nueva-url="<?= e(url('publicacion_form')) ?>" data-mover-url="<?= e(url_accion('publicacion_mover')) ?>">
    <div class="cal-cab">
        <div class="cal-tit">
            <h2 id="cal-tit" class="cal-mes"><?= e(ucfirst(mes_nombre($mes))) ?></h2>
            <span class="suave"><?= $pubsMes ? e($pubsMes . ' publicaci' . ($pubsMes === 1 ? 'ón' : 'ones')) : 'Sin publicaciones' ?></span>
        </div>
        <div class="cal-nav">
            <a class="btn sec chico icono" href="<?= e(url('redes', ['mes' => $mesAnt])) ?>" aria-label="Mes anterior" title="Mes anterior"><?= icono('chevron-left') ?></a>
            <a class="btn sec chico" href="<?= e(url('redes', ['mes' => substr($hoy, 0, 7), 'dia' => $hoy])) ?>">Hoy</a>
            <a class="btn sec chico icono" href="<?= e(url('redes', ['mes' => $mesSig])) ?>" aria-label="Mes siguiente" title="Mes siguiente"><?= icono('chevron-right') ?></a>
        </div>
    </div>

    <div class="cal-sem" aria-hidden="true">
        <?php foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $d): ?><span><?= $d ?></span><?php endforeach; ?>
    </div>
    <ol class="cal-grilla">
        <?php foreach ($semanas as $semana): foreach ($semana as $f):
            $delDia = $porDia[$f] ?? [];
            $n = count($delDia);
            $clases = 'cal-dia' . (substr($f, 0, 7) !== $mes ? ' fuera' : '') . ($f === $hoy ? ' hoy' : '') . ($f === $dia ? ' sel' : '') . ($n ? '' : ' vacio');
            $tituloDia = ucfirst(fecha_larga($f)); ?>
            <li class="<?= $clases ?>" data-fecha="<?= e($f) ?>" data-titulo="<?= e($tituloDia) ?>">
                <a class="cal-num" href="<?= e(url('redes', ['mes' => $mes, 'dia' => $f])) ?>"
                   aria-label="<?= e($tituloDia . ($f === $hoy ? ', hoy' : '') . ': ' . ($n ? $n . ' publicaci' . ($n === 1 ? 'ón' : 'ones') : 'sin publicaciones')) ?>"<?= $f === $dia ? ' aria-current="date"' : '' ?>><?= (int) substr($f, 8, 2) ?></a>
                <?php if ($n): ?>
                    <div class="cal-pubs">
                        <?php foreach ($delDia as $p): ?>
                            <a class="cal-pub est-<?= e($p['estado']) ?>" data-pub-id="<?= (int) $p['id'] ?>" data-orden="<?= e(($p['hora'] ?? '99:99') . sprintf('%010d', (int) $p['id'])) ?>" href="<?= e(url('publicacion', ['id' => (int) $p['id']])) ?>"
                               title="<?= e(($p['hora'] ? hora_corta($p['hora']) . ' · ' : '') . $p['tipo'] . ($p['redes'] !== '' ? ' (' . str_replace(',', ', ', $p['redes']) . ')' : '') . ' · ' . $p['titulo'] . ' · ' . PUB_ESTADOS[$p['estado']]) ?>">
                                <span class="pub-punto est-<?= e($p['estado']) ?>" aria-hidden="true"></span><span class="cal-pub-txt"><span class="cal-pub-tipo"><?= e($p['tipo']) ?></span><?= redes_iconos_html($p['redes']) ?> <?= e($p['titulo']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="cal-puntos" aria-hidden="true">
                        <?php foreach (array_slice($delDia, 0, 3) as $p): ?><span class="pub-punto est-<?= e($p['estado']) ?>"></span><?php endforeach; ?>
                        <?php if ($n > 3): ?><span class="cal-mas">+<?= $n - 3 ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <a class="cal-agregar" href="<?= e(url('publicacion_form', ['fecha' => $f])) ?>" data-abrir="publicacion" data-fecha="<?= e($f) ?>"
                   aria-label="Agregar publicación el <?= e(fecha_larga($f)) ?>" title="Agregar publicación"><?= icono('plus', 'chico') ?></a>
            </li>
        <?php endforeach; endforeach; ?>
    </ol>
    <div class="cal-leyenda" aria-label="Colores por estado">
        <?php foreach (PUB_ESTADOS as $k => $t): ?><span><span class="pub-punto est-<?= e($k) ?>" aria-hidden="true"></span><?= e($t) ?></span><?php endforeach; ?>
    </div>
</section>

<?php /* Lista del día elegido (en el celular, debajo del calendario; en escritorio el calendario ya muestra todo) */ ?>
<div class="cal-panel" aria-live="polite">
    <?php foreach ($porDia as $f => $delDia): ?>
        <section class="card" data-dia-panel="<?= e($f) ?>"<?= $f === $dia ? '' : ' hidden' ?>>
            <div class="card-cab">
                <h2><?= e(ucfirst(fecha_larga($f))) ?></h2>
                <a class="btn chico" href="<?= e(url('publicacion_form', ['fecha' => $f])) ?>" data-abrir="publicacion" data-fecha="<?= e($f) ?>"><?= icono('plus', 'chico') ?>Agregar</a>
            </div>
            <ul class="lista"><?php foreach ($delDia as $p): ?><?= pub_item_html($p) ?><?php endforeach; ?></ul>
        </section>
    <?php endforeach; ?>
    <section class="card" data-dia-panel-vacio<?= isset($porDia[$dia]) ? ' hidden' : '' ?>>
        <div class="card-cab">
            <h2 data-dia-titulo><?= e(ucfirst(fecha_larga($dia))) ?></h2>
            <a class="btn chico" href="<?= e(url('publicacion_form', ['fecha' => $dia])) ?>" data-abrir="publicacion" data-fecha="<?= e($dia) ?>" data-dia-agregar><?= icono('plus', 'chico') ?>Agregar</a>
        </div>
        <p class="suave m-0">No hay publicaciones para este día.</p>
    </section>
</div>

<?php else: ?>
<div class="chips" role="group" aria-label="Filtrar por tipo">
    <a class="chip-f<?= $fTipo === '' ? ' activo' : '' ?>" href="<?= e($urlLista(['tipo' => ''])) ?>">Todos los tipos</a>
    <?php foreach ($tipos as $t): ?>
        <a class="chip-f<?= $fTipo === $t ? ' activo' : '' ?>" href="<?= e($urlLista(['tipo' => $t])) ?>"><?= e($t) ?></a>
    <?php endforeach; ?>
</div>
<?php if (!$atrasadas): ?>
    <div class="chips mt-8" role="group" aria-label="Filtrar por estado">
        <a class="chip-f<?= $fEstado === '' ? ' activo' : '' ?>" href="<?= e($urlLista(['estado' => ''])) ?>">Todos los estados</a>
        <?php foreach (PUB_ESTADOS as $k => $t): ?>
            <a class="chip-f<?= $fEstado === $k ? ' activo' : '' ?>" href="<?= e($urlLista(['estado' => $k])) ?>"><span class="pub-punto est-<?= e($k) ?>" aria-hidden="true"></span><?= e($t) ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<p class="cuenta-res"><?= count($pubs) ?> publicaci<?= count($pubs) === 1 ? 'ón' : 'ones' ?> <?= $atrasadas ? 'de días pasados sin marcar como Publicado' : 'desde hoy' ?></p>

<?php if (!$pubs): ?>
    <div class="card vacio-est">
        <?= icono('megaphone') ?>
        <p><strong><?= $atrasadas ? 'No hay publicaciones atrasadas.' : 'No hay publicaciones próximas' . ($fTipo !== '' || $fEstado !== '' ? ' con ese filtro.' : '.') ?></strong></p>
        <a class="btn" href="<?= e(url('publicacion_form', ['fecha' => $hoy])) ?>" data-abrir="publicacion" data-fecha="<?= e($hoy) ?>"><?= icono('plus') ?>Nueva publicación</a>
    </div>
<?php else: ?>
    <?php foreach ($grupos as $f => $delDia):
        $d = dias_hasta($f); ?>
        <h2 class="grupo-tit<?= $d < 0 ? ' bad' : '' ?>"><?= e(ucfirst(fecha_larga($f, substr($f, 0, 4) !== substr($hoy, 0, 4)))) ?> · <?= e(texto_relativo($d)) ?></h2>
        <section class="card"><ul class="lista"><?php foreach ($delDia as $p): ?><?= pub_item_html($p) ?><?php endforeach; ?></ul></section>
    <?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
