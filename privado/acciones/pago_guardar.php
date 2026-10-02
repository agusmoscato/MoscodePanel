<?php
/** Registra un pago (con imputación automática o manual). */
$clienteId = (int) post('cliente_id', '0');
$volver = url('pago_form', ['cliente_id' => $clienteId]);
if (!cliente_propio($clienteId)) {
    redirigir(url('clientes'));
}
if (!nonce_consumir()) {
    flash('error', 'Ese formulario ya se envió (o venció). Revisá el historial de pagos antes de volver a registrarlo.');
    redirigir(url('cliente', ['id' => $clienteId]));
}

$fecha = post('fecha');
$monto = parsear_monto(post('monto'));
$moneda = post('moneda', 'ARS');
$medio = post('medio', 'transferencia');
$vigente = cotizacion_valor();
$cotTexto = post('cotizacion');
$cot = $cotTexto !== '' ? parsear_monto($cotTexto) : ($vigente ?? 0.0);

if (!fecha_valida($fecha)) {
    volver_con_error('La fecha no es válida.', $volver);
}
if ($monto === null || $monto < 0.01) {
    volver_con_error('El monto no es válido: escribí solo números (ej. 12500 o 12500,50), mayor a cero.', $volver);
}
if (!in_array($moneda, ['ARS', 'USD'], true) || !isset(MEDIOS_PAGO[$medio])) {
    volver_con_error('Hay un valor inválido en el formulario.', $volver);
}
if ($cot === null || $cot <= 0) {
    volver_con_error('Cargá la cotización del dólar (o actualizala en la sección Dólar).', $volver);
}
// La cotización del pago no puede alejarse de la vigente más del doble ni de la mitad (error de tipeo)
if ($vigente !== null && $vigente > 0 && $moneda === 'USD' && ($cot < $vigente * 0.5 || $cot > $vigente * 2)) {
    volver_con_error('La cotización ' . fmt_monto($cot) . ' se aleja demasiado de la vigente (' . fmt_monto($vigente) . '). Revisala o actualizá el dólar.', $volver);
}
if ($err = largo_excedido(['La nota' => [post('nota'), 255]])) {
    volver_con_error($err, $volver);
}

$manual = null;
if (post('modo') === 'manual') {
    $manual = [];
    foreach (array_slice((array) ($_POST['imputar'] ?? []), 0, 500, true) as $cargoId => $m) {
        $v = is_string($m) ? parsear_monto($m) : null;
        if ($v !== null && $v > 0) {
            $manual[(int) $cargoId] = $v;
        }
    }
}

try {
    $pagoId = registrar_pago($clienteId, $fecha, $monto, $moneda, $medio, post('nota'), $cot, $manual);
} catch (RuntimeException $ex) {
    volver_con_error($ex->getMessage(), $volver);
}

$libre = pago_sin_imputar($pagoId);
flash('ok', 'Pago registrado.' . ($libre > 0 ? ' Quedaron ' . fmt_monto($libre, $moneda) . ' sin imputar (saldo a favor).' : ''));
redirigir(url('cliente', ['id' => $clienteId]));
