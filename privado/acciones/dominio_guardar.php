<?php
/** Crea o actualiza un dominio. */
$id = (int) post('id', '0');
$clienteId = (int) post('cliente_id', '0');
$volver = $id ? url('dominio_form', ['id' => $id]) : url('dominio_form', ['cliente_id' => $clienteId]);

if (!cliente_propio($clienteId)) {
    redirigir(url('clientes'));
}
$actual = $id ? fila('SELECT * FROM dominios WHERE id = ? AND cliente_id = ? AND usuario_id = {U}', [$id, $clienteId]) : null;
if ($id && !$actual) {
    redirigir(url('cliente', ['id' => $clienteId]));
}

$d = [
    'dominio'           => mb_strtolower(post('dominio')),
    'proveedor'         => post('proveedor'),
    'fecha_vencimiento' => post('fecha_vencimiento'),
    'dias_anticipo'     => (int) post('dias_anticipo', (string) ANTICIPO_POR_DEFECTO_DIAS),
    'costo_renovacion'  => parsear_monto(post('costo_renovacion', '0')) ?? -1.0,
    'moneda_costo'      => post('moneda_costo', 'ARS'),
    'precio_cliente'    => parsear_monto(post('precio_cliente', '0')) ?? -1.0,
    'moneda_precio'     => post('moneda_precio', 'ARS'),
    'estado'            => post('estado', 'activo'),
];
if ($d['dias_anticipo'] < 0 || $d['dias_anticipo'] > 365) {
    volver_con_error('Los días de anticipo deben estar entre 0 y 365.', $volver);
}
if ($d['dominio'] === '') {
    volver_con_error('El nombre del dominio es obligatorio.', $volver);
}
if (!preg_match('/^(?=.{4,190}$)(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z0-9\-]{2,24}$/', $d['dominio'])) {
    volver_con_error('El dominio no tiene un formato válido (ej. midominio.com.ar).', $volver);
}
if ($err = largo_excedido(['El proveedor' => [$d['proveedor'], 80]])) {
    volver_con_error($err, $volver);
}
if (fila('SELECT id FROM dominios WHERE usuario_id = {U} AND dominio = ? AND id <> ?', [$d['dominio'], $id])) {
    volver_con_error('Ya cargaste ese dominio.', $volver);
}
if (!fecha_valida($d['fecha_vencimiento'])) {
    volver_con_error('La fecha de vencimiento no es válida.', $volver);
}
if ($d['costo_renovacion'] < 0 || $d['precio_cliente'] < 0) {
    volver_con_error('Los montos no son válidos: escribí solo números (ej. 15000 o 40,50), sin negativos.', $volver);
}
if (!in_array($d['moneda_costo'], ['ARS', 'USD'], true) || !in_array($d['moneda_precio'], ['ARS', 'USD'], true)
    || !in_array($d['estado'], ['activo', 'baja'], true)) {
    volver_con_error('Hay un valor inválido en el formulario.', $volver);
}

if ($id) {
    if ($actual['fecha_vencimiento'] !== $d['fecha_vencimiento']) {
        $d['aviso_renovacion_enviado_en'] = null;   // cambió la fecha de vencimiento: el aviso anterior ya no aplica
    }
    actualizar('dominios', $id, $d);
    flash('ok', 'Dominio actualizado.');
} else {
    insertar('dominios', $d + ['cliente_id' => $clienteId, 'creado_en' => date('Y-m-d H:i:s')]);
    flash('ok', 'Dominio creado.');
}
redirigir(url('cliente', ['id' => $clienteId]));
