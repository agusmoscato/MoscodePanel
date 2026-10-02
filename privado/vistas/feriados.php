<?php
/** Feriados de Argentina: listado por año, alta manual e importación desde la API. */
$titulo = 'Feriados';
$admin = es_admin();
$anio = (int) get('anio', date('Y'));
if ($anio < 2000 || $anio > 2100) {
    $anio = (int) date('Y');
}
$lista = filas('SELECT * FROM feriados WHERE fecha BETWEEN ? AND ? ORDER BY fecha', ["$anio-01-01", "$anio-12-31"]);
$dias = ['', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
?>
<div class="pagina-cab">
    <div>
        <h1>Feriados <?= $anio ?></h1>
        <div class="sub">Se excluyen del cálculo del primer día hábil del mes</div>
    </div>
    <div class="acciones">
        <a class="btn sec chico icono" href="<?= e(url('feriados', ['anio' => $anio - 1])) ?>" aria-label="Año <?= $anio - 1 ?>"><?= icono('chevron-left') ?></a>
        <span class="fw-600 mono"><?= $anio ?></span>
        <a class="btn sec chico icono" href="<?= e(url('feriados', ['anio' => $anio + 1])) ?>" aria-label="Año <?= $anio + 1 ?>"><?= icono('chevron-right') ?></a>
    </div>
</div>

<?php if (!$lista): ?>
    <div class="banner warn"><?= icono('triangle-alert') ?><div class="banner-txt">No hay feriados cargados para <?= $anio ?>. Sin ellos, el "primer día hábil" se calcula solo excluyendo fines de semana. <?= $admin ? 'Importalos o cargalos a mano.' : 'Avisale al administrador.' ?></div></div>
<?php endif; ?>

<?php if ($admin): ?>
<div class="grid-2">
    <section class="card">
        <h2 class="card-tit">Importar desde argentinadatos.com</h2>
        <p class="suave">Trae los feriados nacionales del año. Incluye los "puentes" turísticos (marcados); quitá los que no quieras contar. Los que cargaste a mano no se pisan.</p>
        <form method="post" action="<?= e(url_accion('feriados_importar')) ?>">
            <?= csrf_campo() ?>
            <input type="hidden" name="anio" value="<?= $anio ?>">
            <button class="btn" type="submit"><?= icono('download') ?>Importar <?= $anio ?></button>
        </form>
    </section>
    <section class="card">
        <h2 class="card-tit">Agregar a mano</h2>
        <form method="post" action="<?= e(url_accion('feriado_guardar')) ?>" data-validar novalidate>
            <?= csrf_campo() ?>
            <div class="fila-campos c2">
                <label>Fecha <input type="date" name="fecha" required value="<?= $anio ?>-01-01"></label>
                <label>Descripción <input name="descripcion" maxlength="160" required placeholder="Ej: Feriado provincial"></label>
            </div>
            <button class="btn sec" type="submit"><?= icono('plus') ?>Agregar</button>
        </form>
    </section>
</div>
<?php endif; ?>

<section class="mt-16 card">
    <?php if (!$lista): ?>
        <div class="vacio-est"><?= icono('calendar-days') ?><p>Sin feriados cargados para <?= $anio ?>.</p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($lista as $f): $t = strtotime($f['fecha']); ?>
                <li class="item">
                    <div class="fecha-cal" aria-hidden="true"><span><?= e($dias[(int) date('N', $t)]) ?></span><strong><?= e(date('j', $t)) ?></strong></div>
                    <div class="item-main">
                        <div class="item-tit"><?= e($f['descripcion']) ?></div>
                        <div class="item-sub"><span class="mono"><?= e(fecha_corta($f['fecha'])) ?></span><?= chip($f['origen'], $f['origen'] === 'manual' ? 'info' : '') ?></div>
                    </div>
                    <?php if ($admin): ?><form class="en-linea" method="post" action="<?= e(url_accion('feriado_eliminar')) ?>"
                          data-confirmar-titulo="Quitar feriado" data-confirmar="Se quita «<?= e($f['descripcion']) ?>» del calendario." data-confirmar-boton="Quitar">
                        <?= csrf_campo() ?><input type="hidden" name="fecha" value="<?= e($f['fecha']) ?>"><input type="hidden" name="anio" value="<?= $anio ?>">
                        <button class="btn fantasma chico icono peligro-suave" type="submit" aria-label="Quitar feriado" title="Quitar"><?= icono('trash-2') ?></button>
                    </form><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
