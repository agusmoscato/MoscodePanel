<?php
/** Agrega un feriado a mano (o convierte en manual uno existente). */
exigir_admin();   // los feriados son compartidos: solo los edita el admin
$fecha = post('fecha');
$desc = post('descripcion');
if (!fecha_valida($fecha) || $desc === '') {
    flash('error', 'Completá una fecha válida y una descripción.');
    redirigir(url('feriados'));
}
q(
    "INSERT INTO feriados (fecha, descripcion, origen) VALUES (?, ?, 'manual')
     ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), origen = 'manual'",
    [$fecha, mb_substr($desc, 0, 160)]
);
flash('ok', 'Feriado guardado.');
redirigir(url('feriados', ['anio' => (int) substr($fecha, 0, 4)]));
