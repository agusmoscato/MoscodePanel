<?php
/** Crea o actualiza una publicación (desde el bottom sheet o desde la pantalla completa). */
$id = (int) post('id', '0');
$actual = $id ? publicacion_obtener($id) : null;
if ($id && !$actual) {
    redirigir(url('redes'));
}
$volver = $id ? url('publicacion_form', ['id' => $id]) : url('publicacion_form', ['fecha' => post('fecha')]);

[$datos, $error] = publicacion_validar([
    'fecha' => post('fecha'), 'hora' => post('hora'), 'tipo' => post('tipo'), 'estado' => post('estado', 'idea'),
    'titulo' => post('titulo'), 'copy_texto' => texto_largo('copy_texto'), 'notas' => texto_largo('notas'), 'link' => post('link'),
    'redes' => is_array($_POST['redes'] ?? null) ? $_POST['redes'] : [], 'link_publicado' => post('link_publicado'),
], $actual ?? []);
if ($error !== null) {
    volver_con_error($error, $volver);
}

if ($id) {
    publicacion_guardar($id, $datos);
    flash('ok', 'Publicación actualizada.');
    redirigir(url('publicacion', ['id' => $id]));
}
publicacion_guardar(0, $datos);
flash('ok', 'Publicación creada para el ' . fecha_larga($datos['fecha']) . '.');
redirigir(url('redes', ['mes' => substr($datos['fecha'], 0, 7), 'dia' => $datos['fecha']]));
