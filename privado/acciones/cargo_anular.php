<?php
/** Anula un cargo pendiente sin pagos. */
$id = (int) post('id', '0');
$n = q("UPDATE cargos SET estado = 'anulado' WHERE id = ? AND usuario_id = {U} AND plan_id IS NULL AND estado = 'pendiente' AND monto_pagado = 0", [$id])->rowCount();
flash($n ? 'ok' : 'error', $n ? 'Cargo anulado.' : 'No se pudo anular (¿ya tiene pagos, o es una cuota de un plan? Las cuotas se manejan desde el plan).');
redirigir(url('cargos'));
