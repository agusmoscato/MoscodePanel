<?php
/** Cambia el estado de una publicación con un toque (Idea → En preparación → Listo → Publicado, o al revés). */
$id = (int) post('id', '0');
$estado = post('estado');
if (!publicacion_cambiar_estado($id, $estado)) {
    redirigir(publicacion_obtener($id) ? url('publicacion', ['id' => $id]) : url('redes'));
}
flash('ok', 'Estado: ' . PUB_ESTADOS[$estado] . '.');
redirigir(url('publicacion', ['id' => $id]));
