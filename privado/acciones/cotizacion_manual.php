<?php
/** Carga manual de la cotización: vale solo para este usuario (las automáticas son compartidas). */
$valor = parsear_monto(post('valor'));
if ($valor === null || $valor <= 0 || $valor >= 1000000) {
    flash('error', 'Valor de cotización inválido (usá solo números, ej. 1555 o 1555,50).');
} else {
    guardar_cotizacion(cfg('dolar_tipo', 'blue'), $valor, 'manual', true);
    flash('ok', 'Cotización manual guardada (vale solo para tu cuenta hasta la próxima actualización automática).');
}
redirigir(url('cotizacion'));
