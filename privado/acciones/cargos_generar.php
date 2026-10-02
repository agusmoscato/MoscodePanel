<?php
/** Genera los cargos del mes actual (idempotente) y los de servicios anuales próximos. */
$r = generar_cargos();
flash('ok', sprintf('Cargos generados: %d mensuales y %d anuales nuevos (los existentes no se duplican).', $r['mensuales'], $r['anuales']));
redirigir(url('cargos'));
