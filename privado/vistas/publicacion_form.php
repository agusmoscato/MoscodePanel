<?php
/**
 * Alta / edición de una publicación en pantalla completa. Normalmente el formulario se abre en el bottom sheet
 * (sheet_publicacion.php); esta pantalla queda para cuando no hay JS y para volver con el error si algo no validó.
 */
$id = (int) get('id', '0');
$p = $id ? publicacion_obtener($id) : null;
if ($id && !$p) {
    flash('error', 'La publicación no existe.');
    redirigir(url('redes'));
}
$fecha = fecha_valida(get('fecha')) ? get('fecha') : date('Y-m-d');
$pfTipos = redes_tipos();
if ($p && !in_array($p['tipo'], $pfTipos, true)) {
    $pfTipos[] = $p['tipo'];           // un tipo que ya no está en la lista sigue valiendo para esta publicación
}
$base = $p ?? ['fecha' => $fecha, 'hora' => '', 'tipo' => $pfTipos[0], 'estado' => 'idea', 'titulo' => '', 'copy_texto' => '', 'notas' => '', 'link' => ''];
$pf = ['id' => $id, 'hora' => viejo('hora', hora_corta($base['hora']))];
foreach (['fecha', 'tipo', 'estado', 'titulo', 'copy_texto', 'notas', 'link'] as $campo) {
    $pf[$campo] = viejo($campo, $base[$campo]);
}
$titulo = $id ? 'Editar publicación' : 'Nueva publicación';
$volver = $p ? url('publicacion', ['id' => $id]) : url('redes', ['mes' => substr($fecha, 0, 7), 'dia' => $fecha]);
?>
<div class="pagina-cab">
    <a class="btn fantasma chico" href="<?= e($volver) ?>"><?= icono('chevron-left', 'chico') ?><?= $p ? '<span class="trunc-btn">' . e($p['titulo']) . '</span>' : 'Calendario' ?></a>
</div>
<div class="form-titulo"><h1><?= e($titulo) ?></h1></div>

<form method="post" action="<?= e(url_accion('publicacion_guardar')) ?>" data-validar novalidate>
    <?= csrf_campo() ?>
    <section class="card">
        <?php require __DIR__ . '/../includes/form_publicacion.php'; ?>
    </section>
    <div class="form-fijo">
        <a class="btn sec" href="<?= e($volver) ?>">Cancelar</a>
        <button class="btn" type="submit"><?= icono('check') ?>Guardar</button>
    </div>
</form>
