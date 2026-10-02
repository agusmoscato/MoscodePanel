<?php
/** Prueba la conexión con Mercado Pago: consulta quién es el dueño del access token. */
try {
    $u = mp_api('GET', '/users/me');
    $partes = ['Conexión OK con Mercado Pago.', 'Cuenta: ' . ($u['nickname'] ?? '?') . ' (id ' . ($u['id'] ?? '?') . ', país ' . ($u['site_id'] ?? '?') . ')'];
    $partes[] = 'Webhook a configurar: ' . mp_webhook_url();
    flash('ok', implode("\n", $partes));
} catch (Throwable $ex) {
    flash('error', 'Falló la conexión con Mercado Pago: ' . mensaje_seguro($ex));
}
redirigir(url('configuracion'));
