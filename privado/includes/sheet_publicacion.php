<?php
/**
 * sheet_publicacion.php — Bottom sheet "Nueva / Editar publicación" (lo incluye el layout en todas las pantallas
 * internas: se abre desde el calendario de Redes, desde una publicación y desde el botón "+").
 * app.js lo completa al abrirlo: con data-fecha para una nueva, o con el JSON de data-pub-editar para editar.
 * Sin JS, los mismos botones son links a la pantalla completa (vistas/publicacion_form.php).
 */
$pfTipos = redes_tipos();
$pf = ['id' => 0, 'fecha' => date('Y-m-d'), 'hora' => '', 'tipo' => $pfTipos[0], 'estado' => 'idea', 'titulo' => '', 'copy_texto' => '', 'notas' => '', 'link' => ''];
?>
<div class="sheet sheet-ancha" id="sheet-publicacion" role="dialog" aria-modal="true" aria-labelledby="pub-sheet-tit">
    <div class="sheet-asa" aria-hidden="true"></div>
    <div class="sheet-tit">
        <h2 id="pub-sheet-tit" data-pub-sheet-titulo>Nueva publicación</h2>
        <button type="button" class="btn fantasma icono chico" data-cerrar="publicacion" aria-label="Cerrar"><?= icono('x') ?></button>
    </div>
    <form method="post" action="<?= e(url_accion('publicacion_guardar')) ?>" data-pub-form data-hoy="<?= e(date('Y-m-d')) ?>" data-validar novalidate>
        <?= csrf_campo() ?>
        <?php require __DIR__ . '/form_publicacion.php'; ?>
        <button class="btn bloque" type="submit"><?= icono('check') ?>Guardar publicación</button>
    </form>
</div>
