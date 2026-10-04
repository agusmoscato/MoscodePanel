<?php
/** Elimina una publicación y vuelve al calendario, en su mes. */
$id = (int) post('id', '0');
$p = publicacion_obtener($id);
if (!$p) {
    redirigir(url('redes'));
}
q('DELETE FROM publicaciones WHERE id = ? AND usuario_id = {U}', [$id]);
flash('ok', 'Publicación eliminada.');
redirigir(url('redes', ['mes' => substr($p['fecha'], 0, 7), 'dia' => $p['fecha']]));
