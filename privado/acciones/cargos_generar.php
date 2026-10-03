<?php
/** Genera los cargos del mes actual (idempotente): mensuales, servicios anuales y dominios próximos a vencer. */
$r = generar_cargos();
flash('ok', sprintf('Cargos generados: %d mensuales, %d de servicios anuales y %d de dominios (los existentes no se duplican).', $r['mensuales'], $r['anuales'], $r['dominios']));
redirigir(url('cargos'));
