<?php
/**
 * http_pagos_cuotas.php — Registrar y anular pagos, y ventas en cuotas, por HTTP con los formularios reales
 * (pago_guardar, pago_anular, plan_guardar, plan_pagar, plan_editar_guardar, plan_cancelar).
 *
 * Cada sección usa un cliente nuevo para que la imputación automática (que va a los cargos más viejos primero) sea
 * predecible. Los formularios de dinero son de un solo uso (nonce): se prueba que un doble envío no duplica.
 */
declare(strict_types=1);

$u = nuevo_usuario_con_clave('pagos', 'Clave-Pagos-2026');
guardar_cotizacion('blue', 1000.0, 'manual', true);
$nav = new Navegador();
$nav->login($u['usuario'], $u['clave']);

/** Cargo suelto (no de plan) para el cliente, vencido hace $diasAtras días. */
$nuevoCargo = function (int $clienteId, string $concepto, float $monto, int $diasAtras) use ($u): int {
    fijar_usuario($u['id']);
    crear_cargo(['cliente_id' => $clienteId, 'concepto' => $concepto, 'fecha_vencimiento' => date('Y-m-d', strtotime("-$diasAtras days")),
        'monto' => $monto, 'moneda' => 'ARS', 'clave_unica' => 'T' . bin2hex(random_bytes(6))]);
    return (int) valor('SELECT id FROM cargos WHERE usuario_id = {U} AND cliente_id = ? AND concepto = ?', [$clienteId, $concepto]);
};
$cargo = fn(int $id): array => con_usuario($u['id'], fn() => fila('SELECT estado, monto, monto_pagado FROM cargos WHERE id = ? AND usuario_id = {U}', [$id]));
$contarPagos = fn(int $cli): int => (int) con_usuario($u['id'], fn() => valor('SELECT COUNT(*) FROM pagos WHERE usuario_id = {U} AND cliente_id = ?', [$cli]));
$pagar = function (int $cli, array $datos) use ($nav): array {
    $nav->get('/pagos/nuevo?cliente_id=' . $cli);         // abre el formulario: csrf y nonce nuevos
    return $nav->post('/acciones/pago_guardar', $datos + ['cliente_id' => (string) $cli, 'fecha' => date('Y-m-d'), 'moneda' => 'ARS',
        'medio' => 'transferencia', 'modo' => 'auto', 'nota' => '']);
};

seccion('pago con imputación automática: cancela primero el cargo más viejo');
fijar_usuario($u['id']);
$cli = nuevo_cliente_de_prueba('Cliente Pagos Auto');
$c1 = $nuevoCargo($cli, 'Mantenimiento julio', 1000, 60);
$c2 = $nuevoCargo($cli, 'Mantenimiento agosto', 2000, 30);
$r = $pagar($cli, ['monto' => '1500']);
verificar_cierto('registrar el pago vuelve a la ficha del cliente', redirige_a($r, '/clientes/' . $cli));
verificar('el cargo más viejo quedó pagado', ['estado' => 'pagado', 'monto' => '1000.00', 'monto_pagado' => '1000.00'], $cargo($c1));
verificar('el siguiente quedó parcial con el resto (500)', ['estado' => 'parcial', 'monto' => '2000.00', 'monto_pagado' => '500.00'], $cargo($c2));
verificar('deuda del cliente: 1500', 1500.0, con_usuario($u['id'], fn() => deuda_cliente($cli)['ARS']));

seccion('doble envío del mismo formulario: el segundo no registra otro pago');
$nav->get('/pagos/nuevo?cliente_id=' . $cli);
$datos = ['cliente_id' => (string) $cli, 'fecha' => date('Y-m-d'), 'monto' => '100', 'moneda' => 'ARS', 'medio' => 'efectivo', 'modo' => 'auto'];
$nav->post('/acciones/pago_guardar', $datos);
$nav->post('/acciones/pago_guardar', $datos);       // mismo nonce
verificar('de dos envíos con el mismo nonce queda un solo pago nuevo (2 en total con el anterior)', 2, $contarPagos($cli));

seccion('validaciones: monto inválido, imputación mayor al pago, cotización USD fuera de rango');
$antes = $contarPagos($cli);
$pagar($cli, ['monto' => 'abc']);
$pagar($cli, ['monto' => '0']);
$pagar($cli, ['monto' => '100', 'modo' => 'manual', 'imputar' => [$c2 => '500']]);
$pagar($cli, ['monto' => '10', 'moneda' => 'USD', 'cotizacion' => '5000']);
verificar('ninguno de los cuatro pagos inválidos se registró', $antes, $contarPagos($cli));

seccion('imputación manual: el pago va al cargo elegido');
$c3 = $nuevoCargo($cli, 'Mantenimiento septiembre', 700, 1);
$pagar($cli, ['monto' => '300', 'modo' => 'manual', 'imputar' => [$c3 => '300']]);
verificar('el cargo elegido recibió los 300 (aunque había otro más viejo con saldo)', '300.00', $cargo($c3)['monto_pagado']);
verificar('el más viejo con saldo no se tocó', '600.00', $cargo($c2)['monto_pagado']);

seccion('pago mayor a la deuda: lo que sobra queda como saldo a favor');
$deudaAntes = con_usuario($u['id'], fn() => deuda_cliente($cli)['ARS']);
$pagar($cli, ['monto' => '5000']);
fijar_usuario($u['id']);
$pagoGrande = (int) valor('SELECT id FROM pagos WHERE usuario_id = {U} AND cliente_id = ? ORDER BY id DESC LIMIT 1', [$cli]);
verificar('sin deuda después del pago', 0.0, deuda_cliente($cli)['ARS']);
verificar('sin imputar: 5000 − la deuda que había', round(5000 - $deudaAntes, 2), pago_sin_imputar($pagoGrande));
verificar('el saldo a favor del cliente es ese mismo sobrante', round(5000 - $deudaAntes, 2), saldo_favor_ars($cli));

seccion('anular un pago: desimputa, deja registro (quién, cuándo, por qué) y no borra nada');
$cli2 = nuevo_cliente_de_prueba('Cliente Pagos Anular');
$d1 = $nuevoCargo($cli2, 'Hosting', 1000, 40);
$d2 = $nuevoCargo($cli2, 'Soporte', 1000, 20);
$pagar($cli2, ['monto' => '1500']);
fijar_usuario($u['id']);
$pagoAnular = (int) valor('SELECT id FROM pagos WHERE usuario_id = {U} AND cliente_id = ?', [$cli2]);
$pantalla = $nav->get("/pagos/$pagoAnular/anular");
verificar('la pantalla de anulación abre', 200, $pantalla['codigo']);
$nav->post('/acciones/pago_anular', ['id' => (string) $pagoAnular, 'motivo' => 'x']);
fijar_usuario($u['id']);
verificar('con un motivo de menos de 5 letras no se anula', null, valor('SELECT anulado_en FROM pagos WHERE id = ? AND usuario_id = {U}', [$pagoAnular]));
$nav->get("/pagos/$pagoAnular/anular");
$nav->post('/acciones/pago_anular', ['id' => (string) $pagoAnular, 'motivo' => 'Transferencia rechazada por el banco']);
fijar_usuario($u['id']);
$p = fila('SELECT anulado_en, anulado_por, anulado_motivo FROM pagos WHERE id = ? AND usuario_id = {U}', [$pagoAnular]);
verificar_cierto('el pago sigue en la base, marcado como anulado', $p !== null && $p['anulado_en'] !== null);
verificar('con quién lo anuló', $u['id'], (int) $p['anulado_por']);
verificar('y por qué', 'Transferencia rechazada por el banco', $p['anulado_motivo']);
verificar('el primer cargo volvió a deberse entero', ['estado' => 'pendiente', 'monto' => '1000.00', 'monto_pagado' => '0.00'], $cargo($d1));
verificar('el segundo también', ['estado' => 'pendiente', 'monto' => '1000.00', 'monto_pagado' => '0.00'], $cargo($d2));
verificar('la deuda volvió a 2000', 2000.0, deuda_cliente($cli2)['ARS']);
verificar('las imputaciones no se borraron (historia completa)', 2, (int) valor('SELECT COUNT(*) FROM pago_imputaciones WHERE usuario_id = {U} AND pago_id = ?', [$pagoAnular]));
verificar_cierto('quedó en la actividad', (int) valor("SELECT COUNT(*) FROM registro_actividad WHERE usuario_id = {U} AND evento = 'pago_anulado'") >= 1);
$nav->get("/pagos/$pagoAnular/anular");
$nav->post('/acciones/pago_anular', ['id' => (string) $pagoAnular, 'motivo' => 'Otra vez, por las dudas']);
fijar_usuario($u['id']);
verificar('anularlo de nuevo no cambia el motivo original', 'Transferencia rechazada por el banco', valor('SELECT anulado_motivo FROM pagos WHERE id = ? AND usuario_id = {U}', [$pagoAnular]));
verificar('la deuda sigue en 2000 (no se "desanuló" dos veces)', 2000.0, deuda_cliente($cli2)['ARS']);
$csv = $nav->get('/exportar/pagos.csv')['cuerpo'];
verificar_contiene('la exportación de pagos lo muestra como Anulado, con el motivo', 'Transferencia rechazada por el banco', $csv);

seccion('venta en cuotas: la suma tiene que dar el total; las cuotas futuras no son deuda todavía');
$cli3 = nuevo_cliente_de_prueba('Cliente Cuotas');
$hoyMenos10 = date('Y-m-d', strtotime('-10 days'));
$f = fechas_cuotas($hoyMenos10, 3, 'mensual');
$plan = function (array $cuotasDet, bool $abrirFormulario = true) use ($nav, $cli3, $f): void {
    if ($abrirFormulario) {   // sin abrirlo, se reenvía con el mismo nonce (doble clic / volver atrás y reenviar)
        $nav->get('/clientes/' . $cli3 . '/cuotas/nueva?previsualizar=1&concepto=x&monto_total=1000&cuotas=3&frecuencia=mensual&fecha_primera=' . $f[0]);
    }
    $nav->post('/acciones/plan_guardar', ['cliente_id' => (string) $cli3, 'concepto' => 'Sitio web', 'monto_total' => '1000', 'moneda' => 'ARS',
        'cuotas' => '3', 'frecuencia' => 'mensual', 'fecha_primera' => $f[0], 'cuotas_det' => $cuotasDet]);
};
$plan([['monto' => '300', 'fecha' => $f[0]], ['monto' => '300', 'fecha' => $f[1]], ['monto' => '300', 'fecha' => $f[2]]]);
fijar_usuario($u['id']);
verificar('cuotas que suman 900 para un total de 1000: no se crea el plan', 0, (int) valor('SELECT COUNT(*) FROM planes_pago WHERE usuario_id = {U} AND cliente_id = ?', [$cli3]));
$plan([['monto' => '333.33', 'fecha' => $f[0]], ['monto' => '333.33', 'fecha' => $f[1]], ['monto' => '333.34', 'fecha' => $f[2]]]);
fijar_usuario($u['id']);
$planId = (int) valor('SELECT id FROM planes_pago WHERE usuario_id = {U} AND cliente_id = ?', [$cli3]);
verificar_cierto('con la suma correcta se crea el plan', $planId > 0);
$cuotas = filas('SELECT id, cuota_numero, cuota_total, monto, fecha_vencimiento, estado FROM cargos WHERE usuario_id = {U} AND plan_id = ? ORDER BY cuota_numero', [$planId]);
verificar('3 cuotas (cada una es un cargo)', 3, count($cuotas));
verificar('numeradas 1/3, 2/3, 3/3', ['1/3', '2/3', '3/3'], array_map(fn($c) => $c['cuota_numero'] . '/' . $c['cuota_total'], $cuotas));
verificar('con las fechas mensuales', $f, array_column($cuotas, 'fecha_vencimiento'));
verificar('deuda: solo la cuota ya vencida (333,33), no las futuras', 333.33, deuda_cliente($cli3)['ARS']);
$plan([['monto' => '333.33', 'fecha' => $f[0]], ['monto' => '333.33', 'fecha' => $f[1]], ['monto' => '333.34', 'fecha' => $f[2]]], false);
fijar_usuario($u['id']);
verificar('reenviar el mismo formulario no crea un segundo plan', 1, (int) valor('SELECT COUNT(*) FROM planes_pago WHERE usuario_id = {U} AND cliente_id = ?', [$cli3]));
verificar('la pantalla del plan abre', 200, $nav->get('/cuotas/' . $planId)['codigo']);

seccion('un pago automático no se come las cuotas futuras (queda como saldo a favor)');
$pagar($cli3, ['monto' => '1000']);
fijar_usuario($u['id']);
verificar('la cuota vencida quedó pagada', 'pagado', $cargo((int) $cuotas[0]['id'])['estado']);
verificar('la cuota 2 (futura) sigue pendiente', 'pendiente', $cargo((int) $cuotas[1]['id'])['estado']);
verificar('el resto quedó como saldo a favor', 666.67, saldo_favor_ars($cli3));

seccion('pagar cuotas por adelantado desde el plan: un solo pago imputado a las elegidas');
$nav->get('/cuotas/' . $planId);
$nav->post('/acciones/plan_pagar', ['plan_id' => (string) $planId, 'cuotas' => [$cuotas[1]['id']], 'fecha' => date('Y-m-d'), 'medio' => 'efectivo']);
verificar('la cuota 2 quedó pagada por adelantado', 'pagado', $cargo((int) $cuotas[1]['id'])['estado']);
verificar('la 3 sigue pendiente', 'pendiente', $cargo((int) $cuotas[2]['id'])['estado']);
$nav->get('/cuotas/' . $planId);
$nav->post('/acciones/plan_pagar', ['plan_id' => (string) $planId, 'cuotas' => [$cuotas[1]['id']], 'fecha' => date('Y-m-d'), 'medio' => 'efectivo']);
fijar_usuario($u['id']);
verificar('pagar de nuevo una cuota ya pagada no registra otro pago', 1, (int) valor("SELECT COUNT(*) FROM pagos WHERE usuario_id = {U} AND cliente_id = ? AND nota LIKE 'Cuota 2/3%'", [$cli3]));

seccion('editar las cuotas pendientes: hay que redistribuir el mismo saldo');
$id3 = (int) $cuotas[2]['id'];
$fNueva = date('Y-m-d', strtotime($f[2] . ' +15 days'));
$nav->get('/cuotas/' . $planId . '/editar');
$nav->post('/acciones/plan_editar_guardar', ['plan_id' => (string) $planId, 'cuotas' => [$id3 => ['monto' => '500', 'fecha' => $fNueva]]]);
verificar('si no suma el saldo pendiente (333,34), no cambia', '333.34', $cargo($id3)['monto']);
$nav->get('/cuotas/' . $planId . '/editar');
$nav->post('/acciones/plan_editar_guardar', ['plan_id' => (string) $planId, 'cuotas' => [$id3 => ['monto' => '333,34', 'fecha' => $fNueva]]]);
fijar_usuario($u['id']);
verificar('con el mismo saldo, cambia la fecha', $fNueva, valor('SELECT fecha_vencimiento FROM cargos WHERE id = ? AND usuario_id = {U}', [$id3]));

seccion('cancelar el plan: lo pendiente se anula, lo cobrado queda');
$nav->get('/cuotas/' . $planId);
$nav->post('/acciones/plan_cancelar', ['plan_id' => (string) $planId]);
fijar_usuario($u['id']);
verificar('el plan quedó cancelado', 'cancelado', valor('SELECT estado FROM planes_pago WHERE id = ? AND usuario_id = {U}', [$planId]));
verificar('la cuota pendiente quedó anulada', 'anulado', $cargo($id3)['estado']);
verificar('las cobradas siguen pagadas', ['pagado', 'pagado'], [$cargo((int) $cuotas[0]['id'])['estado'], $cargo((int) $cuotas[1]['id'])['estado']]);

seccion('crear un plan con las primeras cuotas ya cobradas registra esos pagos');
$cli4 = nuevo_cliente_de_prueba('Cliente Cuotas Cobradas');
$g = fechas_cuotas(date('Y-m-d'), 2, 'quincenal');
$nav->get('/clientes/' . $cli4 . '/cuotas/nueva?previsualizar=1&concepto=x&monto_total=500&cuotas=2&frecuencia=quincenal&fecha_primera=' . $g[0]);
$nav->post('/acciones/plan_guardar', ['cliente_id' => (string) $cli4, 'concepto' => 'Logo', 'monto_total' => '500', 'moneda' => 'ARS', 'cuotas' => '2',
    'frecuencia' => 'quincenal', 'fecha_primera' => $g[0], 'cuotas_det' => [['monto' => '250', 'fecha' => $g[0]], ['monto' => '250', 'fecha' => $g[1]]],
    'pagadas' => '1', 'pago_fecha' => date('Y-m-d'), 'pago_medio' => 'efectivo']);
fijar_usuario($u['id']);
$plan4 = (int) valor('SELECT id FROM planes_pago WHERE usuario_id = {U} AND cliente_id = ?', [$cli4]);
verificar('cuota 1 pagada al crear', 'pagado', valor('SELECT estado FROM cargos WHERE usuario_id = {U} AND plan_id = ? AND cuota_numero = 1', [$plan4]));
verificar('cuota 2 pendiente', 'pendiente', valor('SELECT estado FROM cargos WHERE usuario_id = {U} AND plan_id = ? AND cuota_numero = 2', [$plan4]));
verificar('un pago de 250 registrado', '250.00', valor('SELECT monto FROM pagos WHERE usuario_id = {U} AND cliente_id = ?', [$cli4]));
