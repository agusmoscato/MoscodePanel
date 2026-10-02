<?php
/** Crea un plan de pago en cuotas (y registra las primeras N cuotas ya cobradas, si se indicó). */
$clienteId = (int) post('cliente_id', '0');
$params = [
    'cliente_id' => $clienteId, 'concepto' => post('concepto'), 'descripcion' => post('descripcion'), 'monto_total' => post('monto_total'),
    'moneda' => post('moneda', 'ARS'), 'cuotas' => post('cuotas'), 'frecuencia' => post('frecuencia', 'mensual'),
    'fecha_primera' => post('fecha_primera'), 'notas' => post('notas'), 'previsualizar' => 1,
];
$volver = url('plan_form', $params);
if (!cliente_propio($clienteId)) {
    redirigir(url('clientes'));
}
if (!nonce_consumir()) {
    flash('error', 'Ese formulario ya se envió (o venció). Revisá la lista de cuotas antes de crear otro plan.');
    redirigir(url('cliente', ['id' => $clienteId]));
}
if ($err = largo_excedido(['El concepto' => [post('concepto'), 160], 'La descripción' => [post('descripcion'), 255], 'Las notas' => [post('notas'), 1000]])) {
    volver_con_error($err, $volver);
}
$cuotas = [];
foreach ((array) ($_POST['cuotas_det'] ?? []) as $c) {
    $cuotas[] = ['monto' => a_decimal((string) ($c['monto'] ?? '')), 'fecha' => trim((string) ($c['fecha'] ?? ''))];
}
try {
    $planId = crear_plan(
        $clienteId,
        ['concepto' => post('concepto'), 'descripcion' => post('descripcion'), 'monto_total' => a_decimal(post('monto_total')), 'moneda' => post('moneda', 'ARS'),
         'cuotas' => (int) post('cuotas'), 'frecuencia' => post('frecuencia', 'mensual'), 'notas' => post('notas')],
        $cuotas,
        (int) post('pagadas', '0'),
        ['fecha' => post('pago_fecha'), 'medio' => post('pago_medio', 'transferencia')]
    );
} catch (RuntimeException $ex) {
    volver_con_error($ex->getMessage(), $volver);
}
$pagadas = (int) post('pagadas', '0');
flash('ok', 'Plan creado.' . ($pagadas > 0 ? " Se registraron los pagos de las primeras $pagadas cuota" . ($pagadas > 1 ? 's.' : '.') : ''));
redirigir(url('plan', ['id' => $planId]));
