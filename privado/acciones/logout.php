<?php
/** Cierra la sesión (y olvida este dispositivo). */
registrar_actividad('logout');
cerrar_sesion();
if (es_https() && !headers_sent()) {
    header('Clear-Site-Data: "cache"');           // el navegador descarta lo que tenga en caché de esta sesión
}
redirigir(url_base('/login'));
