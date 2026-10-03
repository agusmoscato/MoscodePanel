<?php
/**
 * avisos_renovacion.php — Los DOS avisos de renovación, que son cosas distintas:
 *  (a) el botón "Avisar renovación" (WhatsApp AL CLIENTE): para dominios aparece igual que para servicios
 *      anuales, desde AVISO_RENOVACION_DIAS (60) días antes. Antes los dominios lo mostraban recién a los 7.
 *  (b) el aviso INTERNO (para vos, por email/Telegram) cuando a un dominio le faltan DOMINIO_AVISO_INTERNO_DIAS (7)
 *      días o menos y todavía no se marcó como renovado; sale aunque 7 no esté entre los días de aviso configurados.
 */
declare(strict_types=1);

seccion('(a) umbral del botón de WhatsApp: el mismo para servicios y dominios');
verificar('AVISO_RENOVACION_DIAS = 60', 60, AVISO_RENOVACION_DIAS);
verificar('a 60 días se ofrece', true, ofrecer_aviso_renovacion(60, '3415550000'));
verificar('a 45 días se ofrece (antes, para un dominio, no)', true, ofrecer_aviso_renovacion(45, '3415550000'));
verificar('vencido (días negativos) se sigue ofreciendo', true, ofrecer_aviso_renovacion(-3, '3415550000'));
verificar('a 61 días no se ofrece', false, ofrecer_aviso_renovacion(61, '3415550000'));
verificar('sin teléfono no se ofrece', false, ofrecer_aviso_renovacion(10, ''));
verificar('sin fecha (servicio mensual) no se ofrece', false, ofrecer_aviso_renovacion(null, '3415550000'));

seccion('(a) en la ficha del cliente y en Vencimientos, un dominio a 45 días muestra "Avisar renovación"');
$dueno = nuevo_usuario_con_clave('avisos', 'Clave-Avisos-2026');
$cli = nuevo_cliente_de_prueba('Cliente Avisos', ['telefono' => '3415552222']);
$dom45 = insertar('dominios', [
    'dominio' => 'cuarenta-y-cinco.com', 'proveedor' => 'NIC.ar', 'fecha_vencimiento' => date('Y-m-d', strtotime('+45 days')),
    'dias_anticipo' => 30, 'costo_renovacion' => 100, 'moneda_costo' => 'ARS', 'precio_cliente' => 500, 'moneda_precio' => 'ARS',
    'estado' => 'activo', 'cliente_id' => $cli, 'creado_en' => date('Y-m-d H:i:s'),
]);
$dom90 = insertar('dominios', [
    'dominio' => 'noventa.com', 'proveedor' => 'NIC.ar', 'fecha_vencimiento' => date('Y-m-d', strtotime('+90 days')),
    'dias_anticipo' => 30, 'costo_renovacion' => 100, 'moneda_costo' => 'ARS', 'precio_cliente' => 500, 'moneda_precio' => 'ARS',
    'estado' => 'activo', 'cliente_id' => $cli, 'creado_en' => date('Y-m-d H:i:s'),
]);
$nav = new Navegador();
$nav->login($dueno['usuario'], $dueno['clave']);
$ficha = $nav->get('/clientes/' . $cli);
verificar('la ficha del cliente responde 200', 200, $ficha['codigo']);
verificar_contiene('ficha: dominio a 45 días tiene el botón', 'data-aviso-renovacion="dominio:' . $dom45 . '"', $ficha['cuerpo']);
verificar_cierto('ficha: dominio a 90 días NO tiene el botón', !str_contains($ficha['cuerpo'], 'data-aviso-renovacion="dominio:' . $dom90 . '"'));
$venc = $nav->get('/vencimientos');
verificar_contiene('Vencimientos: dominio a 45 días tiene el botón', 'data-aviso-renovacion="dominio:' . $dom45 . '"', $venc['cuerpo']);
verificar_cierto('Vencimientos: dominio a 90 días NO tiene el botón', !str_contains($venc['cuerpo'], 'data-aviso-renovacion="dominio:' . $dom90 . '"'));

seccion('(b) aviso interno: dominio sin renovar a 7 días o menos, aunque 7 no esté en los días configurados');
fijar_usuario($dueno['id']);
cfg_set('dias_aviso', '30,15');          // a propósito sin el 7
$vence5 = date('Y-m-d', strtotime('+5 days'));
$domSinRenovar = insertar('dominios', [
    'dominio' => 'sin-renovar.com', 'proveedor' => 'DonWeb', 'fecha_vencimiento' => $vence5,
    'dias_anticipo' => 30, 'costo_renovacion' => 100, 'moneda_costo' => 'ARS', 'precio_cliente' => 0, 'moneda_precio' => 'ARS',
    'estado' => 'activo', 'cliente_id' => $cli, 'creado_en' => date('Y-m-d H:i:s'),
]);
$servicio5 = insertar('servicios', [
    'cliente_id' => $cli, 'nombre' => 'Hosting anual cinco', 'descripcion' => '', 'monto' => 100, 'moneda' => 'ARS',
    'tipo_cobro' => 'anual', 'fecha_inicio' => date('Y-m-d', strtotime('-1 year')), 'proximo_vencimiento' => $vence5,
    'estado' => 'activo', 'creado_en' => date('Y-m-d H:i:s'),
]);
// Los dos ya recibieron el aviso del escalón de 15 días (como si el cron hubiera corrido hace una semana)
foreach ([['dominio', $domSinRenovar], ['servicio', $servicio5]] as [$t, $id]) {
    insertar('notificaciones_log', [
        'canal' => 'email', 'tipo' => 'vencimientos', 'referencia_tipo' => $t, 'referencia_id' => $id, 'dias_aviso' => 15,
        'vencimiento_ref' => $vence5, 'destinatario' => 'yo@pruebas.test', 'exito' => 1, 'detalle' => 'Enviado',
        'enviado_en' => date('Y-m-d H:i:s', strtotime('-7 days')),
    ]);
}
$r = ejecutar_avisos_vencimientos();
$texto = (string) ($r['texto'] ?? '');
verificar_contiene('el aviso incluye el dominio sin renovar', 'sin-renovar.com', $texto);
verificar_contiene('y dice que falta renovarlo en el proveedor', 'SIN RENOVAR: renovalo en DonWeb', $texto);
verificar_cierto('el servicio anual (ya avisado en el escalón de 15) no se repite: el 7 es solo para dominios', !str_contains($texto, 'Hosting anual cinco'));
verificar_cierto('un dominio a 45 días no entra en el aviso interno', !str_contains($texto, 'cuarenta-y-cinco.com'));

seccion('(b) un dominio ya renovado ("Renovar" movió la fecha un año) no genera el aviso interno');
$nav->get('/clientes/' . $cli);    // nonce fresco del formulario "Renovar"
$nav->post('/acciones/dominio_renovar', ['id' => (string) $domSinRenovar, 'vence' => $vence5]);
fijar_usuario($dueno['id']);
verificar('el dominio quedó renovado por un año', date('Y-m-d', strtotime($vence5 . ' +1 year')), valor('SELECT fecha_vencimiento FROM dominios WHERE id = ? AND usuario_id = {U}', [$domSinRenovar]));
$r2 = ejecutar_avisos_vencimientos();
verificar_cierto('después de renovar, el dominio ya no figura en el aviso', !str_contains((string) ($r2['texto'] ?? ''), 'sin-renovar.com'));
