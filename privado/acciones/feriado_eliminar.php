<?php
/** Quita un feriado. */
exigir_admin();   // los feriados son compartidos: solo los edita el admin
$fecha = post('fecha');
if (fecha_valida($fecha)) {
    q('DELETE FROM feriados WHERE fecha = ?', [$fecha]);
    flash('ok', 'Feriado quitado.');
}
redirigir(url('feriados', ['anio' => (int) post('anio', date('Y'))]));
