<?php
/** Ajustes globales (solo admin): fuente de la cotización del dólar, compartida por todos los usuarios. */
exigir_admin();
$fuente = post('dolar_fuente', 'dolarhoy');
if (!in_array($fuente, ['dolarhoy', 'dolarapi'], true)) {
    volver_con_error('Fuente de dólar inválida.', url('usuarios'));
}
cfg_set('dolar_fuente', $fuente);
flash('ok', 'Fuente del dólar actualizada para todos los usuarios.');
redirigir(url('usuarios'));
