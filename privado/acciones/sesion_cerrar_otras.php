<?php
/** Cierra todas las demás sesiones (otros dispositivos, con o sin "recordarme"); este dispositivo sigue iniciado. */
cerrar_otras_sesiones((int) $usuario['id']);
registrar_actividad('sesiones_cerradas');
flash('ok', 'Se cerraron todas las demás sesiones.');
redirigir(url('mi_cuenta') . '#dispositivos');
