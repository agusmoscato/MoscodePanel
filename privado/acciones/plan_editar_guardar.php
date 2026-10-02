<?php
/** Guarda la edición de las cuotas pendientes de un plan (redistribuye el saldo). */
$planId = (int) post('plan_id', '0');
$nuevas = [];
foreach ((array) ($_POST['cuotas'] ?? []) as $id => $c) {
    $nuevas[(int) $id] = ['monto' => a_decimal((string) ($c['monto'] ?? '')), 'fecha' => trim((string) ($c['fecha'] ?? ''))];
}
try {
    editar_cuotas_pendientes($planId, $nuevas);
} catch (RuntimeException $ex) {
    volver_con_error($ex->getMessage(), url('plan_editar', ['id' => $planId]));
}
flash('ok', 'Cuotas actualizadas.');
redirigir(url('plan', ['id' => $planId]));
