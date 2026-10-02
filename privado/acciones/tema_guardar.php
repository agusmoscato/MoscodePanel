<?php
/** Guarda el tema preferido del usuario (oscuro o claro). Lo llama el switch de tema; no devuelve contenido. */
$tema = post('tema');
if (in_array($tema, ['dark', 'light'], true)) {
    cfg_set('tema', $tema);
}
http_response_code(204);
