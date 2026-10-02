<?php
/** Importa los feriados del año desde argentinadatos.com. */
exigir_admin();   // los feriados son compartidos: solo los edita el admin
$anio = (int) post('anio', date('Y'));
try {
    $n = importar_feriados($anio);
    flash('ok', "Se importaron $n feriados de $anio.");
} catch (Throwable $ex) {
    flash('error', 'No se pudo importar: ' . mensaje_seguro($ex) . ' Podés cargarlos a mano.');
}
redirigir(url('feriados', ['anio' => $anio]));
