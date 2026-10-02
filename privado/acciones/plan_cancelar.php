<?php
/** Cancela un plan: las cuotas pendientes se anulan; lo ya pagado queda. */
$planId = (int) post('plan_id', '0');
try {
    $r = cancelar_plan($planId);
} catch (RuntimeException $ex) {
    flash('error', $ex->getMessage());
    redirigir(url('planes'));
}
flash('ok', "Plan cancelado: {$r['anuladas']} cuota(s) anulada(s)" . ($r['cerradas'] ? " y {$r['cerradas']} cerrada(s) por lo ya pagado." : '. Lo ya cobrado queda registrado.'));
redirigir(url('plan', ['id' => $planId]));
