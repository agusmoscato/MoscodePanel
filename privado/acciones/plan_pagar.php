<?php
/** Paga por adelantado una o varias cuotas de un plan (un solo pago, imputado a las cuotas elegidas). */
$planId = (int) post('plan_id', '0');
if (!nonce_consumir()) {
    flash('error', 'Ese formulario ya se envió (o venció). Revisá las cuotas antes de volver a registrar el pago.');
    redirigir(url('plan', ['id' => $planId]));
}
try {
    [, $total] = pagar_cuotas($planId, array_map('intval', (array) ($_POST['cuotas'] ?? [])), post('fecha'), post('medio', 'transferencia'));
} catch (RuntimeException $ex) {
    flash('error', $ex->getMessage());
    redirigir(url('plan', ['id' => $planId]));
}
flash('ok', 'Pago registrado: ' . fmt_monto($total, plan_propio($planId)['moneda']) . '.');
redirigir(url('plan', ['id' => $planId]));
