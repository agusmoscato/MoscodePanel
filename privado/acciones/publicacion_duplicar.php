<?php
/** Duplica una publicación en otra fecha y abre la copia. */
$id = (int) post('id', '0');
$fecha = post('fecha');
if (!publicacion_obtener($id)) {
    redirigir(url('redes'));
}
if (!fecha_valida($fecha)) {
    volver_con_error('Elegí una fecha válida para la copia.', url('publicacion', ['id' => $id]));
}
$nuevo = publicacion_duplicar($id, $fecha);
flash('ok', 'Publicación duplicada para el ' . fecha_larga($fecha) . '.');
redirigir(url('publicacion', ['id' => $nuevo]));
