<?php
/** Crea o actualiza un cliente. */
$id = (int) post('id', '0');
$volver = $id ? url('cliente_form', ['id' => $id]) : url('cliente_form');

$d = [
    'nombre'   => post('nombre'),
    'contacto' => post('contacto'),
    'email'    => post('email'),
    'telefono' => post('telefono'),
    'cuit'     => post('cuit'),
    'notas'    => post('notas'),
    'estado'   => post('estado', 'activo'),
];
if ($d['nombre'] === '') {
    volver_con_error('El nombre es obligatorio.', $volver);
}
if ($err = largo_excedido(['El nombre' => [$d['nombre'], 160], 'El contacto' => [$d['contacto'], 120], 'El email' => [$d['email'], 160],
    'El teléfono' => [$d['telefono'], 40], 'El CUIT' => [$d['cuit'], 20], 'Las notas' => [$d['notas'], 5000]])) {
    volver_con_error($err, $volver);
}
if ($d['email'] !== '' && !email_valido($d['email'])) {
    volver_con_error('El email no es válido.', $volver);
}
if (!telefono_valido($d['telefono'])) {
    volver_con_error('El teléfono solo puede tener números, espacios y los signos + ( ) - .', $volver);
}
if ($d['cuit'] !== '' && !preg_match('/^[0-9\-]{1,20}$/', $d['cuit'])) {
    volver_con_error('El CUIT solo puede tener números y guiones.', $volver);
}
if (!in_array($d['estado'], ['activo', 'inactivo'], true)) {
    $d['estado'] = 'activo';
}
// El teléfono se guarda tal cual; para WhatsApp se usan solo los dígitos más adelante.

if ($id) {
    if (!cliente_propio($id)) {
        redirigir(url('clientes'));
    }
    actualizar('clientes', $id, $d);
    flash('ok', 'Cliente actualizado.');
} else {
    $id = insertar('clientes', $d + ['creado_en' => date('Y-m-d H:i:s')]);
    flash('ok', 'Cliente creado.');
}
redirigir(url('cliente', ['id' => $id]));
