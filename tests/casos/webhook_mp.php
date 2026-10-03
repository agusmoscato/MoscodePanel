<?php
/**
 * webhook_mp.php — Webhook de Mercado Pago, simulado (sin salir a internet):
 *  1) el endpoint real por HTTP (/webhook/mp/<token>): token de la cuenta, cuenta desactivada, integración apagada,
 *     eventos que no son pagos, y la firma x-signature (sin firma, firma falsa, firma vieja, firma válida);
 *  2) lo que pasa con cada respuesta posible de la API de pagos, con mp_aplicar_pago() y respuestas armadas a mano
 *     (la consulta a la API está separada justo para esto): aprobado, duplicado, pendiente, otra moneda, cargo de otra
 *     cuenta, cargo ya pagado, reembolso, y un pago anulado en el panel que Mercado Pago vuelve a avisar.
 */
declare(strict_types=1);

$u = nuevo_usuario_con_clave('mp', 'Clave-MP-2026');
$token = bin2hex(random_bytes(16));
q('UPDATE usuarios SET webhook_token = ? WHERE id = ?', [$token, $u['id']]);
$ruta = '/webhook/mp/' . $token;
$cuerpoPago = fn(string $id): string => json_encode(['type' => 'payment', 'data' => ['id' => $id]]);
$mp = new Navegador('MercadoPago WebHook v1.0 payment');
$postJson = fn(string $r, string $cuerpo, array $cab = []) => $mp->pedir('POST', $r, null, array_merge(['Content-Type: application/json'], $cab), $cuerpo);

seccion('endpoint: token, cuenta e integración');
$r = $postJson('/webhook/mp/' . bin2hex(random_bytes(16)), $cuerpoPago('123'));
verificar('token desconocido: 403', 403, $r['codigo']);
$r = $postJson($ruta, $cuerpoPago('123'));
verificar('integración apagada en la cuenta: 200 sin hacer nada', [200, 'Mercado Pago desactivado'], [$r['codigo'], $r['cuerpo']]);
fijar_usuario($u['id']);
cfg_set('mp_activo', '1');
$r = $postJson($ruta, json_encode(['type' => 'merchant_order', 'data' => ['id' => '55']]));
verificar('evento que no es un pago: 200 "ignorado"', [200, 'ignorado'], [$r['codigo'], $r['cuerpo']]);
$r = $mp->get($ruta . '?type=payment&data.id=abc');
verificar('aviso por query string con un id inválido: 200 "ignorado" (no consulta la API)', [200, 'ignorado'], [$r['codigo'], $r['cuerpo']]);
$r = $mp->get('/webhook_mp.php?token=' . $token . '&type=merchant_order&id=1');
verificar('la URL vieja (/webhook_mp.php?token=…) sigue funcionando', [200, 'ignorado'], [$r['codigo'], $r['cuerpo']]);

seccion('endpoint: firma x-signature (con clave secreta cargada)');
$secreto = 'clave-secreta-del-webhook-' . bin2hex(random_bytes(4));
fijar_usuario($u['id']);
cfg_set('mp_webhook_secret', $secreto);
verificar_cierto('la clave secreta se guarda cifrada', !str_contains((string) valor("SELECT valor FROM usuario_config WHERE usuario_id = {U} AND clave = 'mp_webhook_secret'"), $secreto));
$firmar = function (string $id, string $requestId, int $ts) use ($secreto): string {
    return 'ts=' . $ts . ',v1=' . hash_hmac('sha256', "id:$id;request-id:$requestId;ts:$ts;", $secreto);
};
$idPrueba = 'abc123';   // alfanumérico: pasa la firma pero mp_procesar_pago lo ignora sin llamar a la API
$r = $postJson($ruta, $cuerpoPago($idPrueba));
verificar('sin firma: 401', 401, $r['codigo']);
$r = $postJson($ruta, $cuerpoPago($idPrueba), ['x-signature: ts=' . time() . ',v1=' . str_repeat('0', 64), 'x-request-id: req-1']);
verificar('firma falsa: 401', 401, $r['codigo']);
$r = $postJson($ruta, $cuerpoPago($idPrueba), ['x-signature: ' . $firmar($idPrueba, 'req-2', time() - 600), 'x-request-id: req-2']);
verificar('firma válida pero de hace 10 minutos (repetición): 401', 401, $r['codigo']);
$r = $postJson($ruta, $cuerpoPago($idPrueba), ['x-signature: ' . $firmar($idPrueba, 'req-3', time()), 'x-request-id: req-3']);
verificar('firma válida y reciente: pasa (200, el id no es de un pago real → "ignorado")', [200, 'ignorado'], [$r['codigo'], $r['cuerpo']]);
$r = $postJson($ruta, $cuerpoPago($idPrueba), ['x-signature: ' . $firmar($idPrueba, 'req-3', time() * 1000), 'x-request-id: req-3']);
verificar('ts en milisegundos (como lo manda Mercado Pago) también vale', 200, $r['codigo']);
fijar_usuario($u['id']);
verificar_cierto('las firmas inválidas quedan en el log del webhook', (int) valor("SELECT COUNT(*) FROM mp_webhook_log WHERE usuario_id = {U} AND resultado = 'firma_invalida'") >= 3);

seccion('cuenta desactivada: responde 200 y no procesa');
q('UPDATE usuarios SET activo = 0 WHERE id = ?', [$u['id']]);
$r = $postJson($ruta, $cuerpoPago('123'));
verificar('cuenta desactivada: 200 "Cuenta desactivada"', [200, 'Cuenta desactivada'], [$r['codigo'], $r['cuerpo']]);
q('UPDATE usuarios SET activo = 1 WHERE id = ?', [$u['id']]);

seccion('pago aprobado de un cargo: se registra e imputa, una sola vez');
fijar_usuario($u['id']);
$cli = nuevo_cliente_de_prueba('Cliente MP');
crear_cargo(['cliente_id' => $cli, 'concepto' => 'Hosting MP', 'fecha_vencimiento' => date('Y-m-d'), 'monto' => 15000, 'moneda' => 'ARS', 'clave_unica' => 'MP1']);
$cargoId = (int) valor("SELECT id FROM cargos WHERE usuario_id = {U} AND clave_unica = 'MP1'");
$respuestaApi = fn(string $estado, float $monto, string $ref, string $moneda = 'ARS') => [
    'id' => 1, 'status' => $estado, 'transaction_amount' => $monto, 'currency_id' => $moneda, 'external_reference' => $ref,
];
$reg = fn(string $pid) => fila('SELECT id, monto, moneda, anulado_en FROM pagos WHERE mp_payment_id = ? AND usuario_id = {U}', [$pid]);
verificar('aprobado: "registrado"', 'registrado', mp_aplicar_pago('9000001', $respuestaApi('approved', 15000, "cargo-$cargoId"), null));
$pago = fila("SELECT * FROM pagos WHERE usuario_id = {U} AND mp_payment_id = '9000001'");
verificar('el pago quedó con medio mercadopago y el payment_id', ['mercadopago', '15000.00'], [$pago['medio'] ?? null, $pago['monto'] ?? null]);
verificar('el cargo quedó pagado', 'pagado', valor('SELECT estado FROM cargos WHERE id = ? AND usuario_id = {U}', [$cargoId]));
verificar('Mercado Pago reintenta el mismo aviso: "duplicado"', 'duplicado', mp_aplicar_pago('9000001', $respuestaApi('approved', 15000, "cargo-$cargoId"), $reg('9000001')));
verificar('sigue habiendo un solo pago con ese id', 1, (int) valor("SELECT COUNT(*) FROM pagos WHERE usuario_id = {U} AND mp_payment_id = '9000001'"));

seccion('respuestas que no registran nada');
crear_cargo(['cliente_id' => $cli, 'concepto' => 'Soporte MP', 'fecha_vencimiento' => date('Y-m-d'), 'monto' => 2000, 'moneda' => 'ARS', 'clave_unica' => 'MP2']);
$cargo2 = (int) valor("SELECT id FROM cargos WHERE usuario_id = {U} AND clave_unica = 'MP2'");
verificar('pago pendiente/en proceso: "ignorado"', 'ignorado', mp_aplicar_pago('9000002', $respuestaApi('in_process', 2000, "cargo-$cargo2"), null));
verificar('otra moneda: "ignorado"', 'ignorado', mp_aplicar_pago('9000003', $respuestaApi('approved', 20, "cargo-$cargo2", 'USD'), null));
verificar('external_reference que no es un cargo: "ignorado"', 'ignorado', mp_aplicar_pago('9000004', $respuestaApi('approved', 2000, 'pedido-77'), null));
verificar('monto cero: "ignorado"', 'ignorado', mp_aplicar_pago('9000005', $respuestaApi('approved', 0, "cargo-$cargo2"), null));
$otra = nuevo_usuario_de_prueba('mp_otra');
$cliOtra = nuevo_cliente_de_prueba('Cliente de otra cuenta');
crear_cargo(['cliente_id' => $cliOtra, 'concepto' => 'Cargo ajeno', 'fecha_vencimiento' => date('Y-m-d'), 'monto' => 500, 'moneda' => 'ARS', 'clave_unica' => 'MPX']);
$cargoAjeno = (int) valor("SELECT id FROM cargos WHERE usuario_id = {U} AND clave_unica = 'MPX'");
fijar_usuario($u['id']);   // el webhook corre en el contexto de la cuenta del token
verificar('cargo de OTRA cuenta (por su URL): "ignorado"', 'ignorado', mp_aplicar_pago('9000006', $respuestaApi('approved', 500, "cargo-$cargoAjeno"), null));
verificar('ninguno de esos registró un pago', 0, (int) valor("SELECT COUNT(*) FROM pagos WHERE usuario_id = {U} AND mp_payment_id IN ('9000002','9000003','9000004','9000005','9000006')"));
verificar('el cargo propio sigue pendiente', 'pendiente', valor('SELECT estado FROM cargos WHERE id = ? AND usuario_id = {U}', [$cargo2]));
verificar('el cargo ajeno sigue pendiente', 'pendiente', con_usuario($otra, fn() => valor('SELECT estado FROM cargos WHERE id = ? AND usuario_id = {U}', [$cargoAjeno])));

seccion('pago sobre un cargo que ya estaba pagado: se registra sin imputar (saldo a favor), no se pierde');
verificar('"registrado"', 'registrado', mp_aplicar_pago('9000007', $respuestaApi('approved', 15000, "cargo-$cargoId"), null));
$pago7 = (int) valor("SELECT id FROM pagos WHERE usuario_id = {U} AND mp_payment_id = '9000007'");
verificar('todo el pago quedó sin imputar', 15000.0, pago_sin_imputar($pago7));
verificar('el log lo marca para revisar', 'registrado_sin_imputar', valor("SELECT resultado FROM mp_webhook_log WHERE usuario_id = {U} AND payment_id = '9000007' ORDER BY id DESC LIMIT 1"));

seccion('reembolso de un pago ya registrado: no se revierte solo, queda para revisar');
verificar('"revisar"', 'revisar', mp_aplicar_pago('9000001', $respuestaApi('refunded', 15000, "cargo-$cargoId"), $reg('9000001')));
verificar('el pago original sigue vigente (se revisa a mano)', null, valor("SELECT anulado_en FROM pagos WHERE usuario_id = {U} AND mp_payment_id = '9000001'"));

seccion('un pago anulado en el panel no vuelve a entrar si Mercado Pago reenvía el aviso');
anular_pago($pago7, 'Duplicado, se devolvió al cliente', $u['id']);
verificar('mp_procesar_pago: "duplicado" (sin consultar la API)', 'duplicado', mp_procesar_pago('9000007'));
verificar('sigue habiendo un solo pago con ese id', 1, (int) valor("SELECT COUNT(*) FROM pagos WHERE usuario_id = {U} AND mp_payment_id = '9000007'"));
verificar('id de pago con letras: "ignorado" (sin consultar la API)', 'ignorado', mp_procesar_pago('12ab'));
