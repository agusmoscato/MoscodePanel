<?php
/** Crea o actualiza un servicio. Si cambia el monto, queda en el historial de precios. */
$id = (int) post('id', '0');
$clienteId = (int) post('cliente_id', '0');
$volver = $id ? url('servicio_form', ['id' => $id]) : url('servicio_form', ['cliente_id' => $clienteId]);

if (!cliente_propio($clienteId)) {
    redirigir(url('clientes'));
}
$actual = $id ? fila('SELECT * FROM servicios WHERE id = ? AND cliente_id = ? AND usuario_id = {U}', [$id, $clienteId]) : null;
if ($id && !$actual) {
    redirigir(url('cliente', ['id' => $clienteId]));
}

$porCantidad = post('por_cantidad') === '1';
$d = [
    'nombre'         => post('nombre'),
    'descripcion'    => post('descripcion'),
    'monto'          => -1.0,   // se completa más abajo: a mano, o cantidad × precio por unidad
    'moneda'         => post('moneda', 'ARS'),
    'tipo_cobro'     => post('tipo_cobro', 'mensual'),
    'fecha_inicio'   => post('fecha_inicio'),
    'estado'         => post('estado', 'activo'),
    'inicio_mensual' => post('inicio_mensual', 'mes_siguiente'),
    'dias_anticipo'  => (int) post('dias_anticipo', (string) ANTICIPO_POR_DEFECTO_DIAS),
    'por_cantidad'   => $porCantidad ? 1 : 0,
    'cantidad'       => null,
    'unidad'         => '',
    'unidad_singular' => '',
    'precio_unidad'  => null,
    'detalle'        => '',
];
if (!isset(INICIO_MENSUAL[$d['inicio_mensual']]) || $d['dias_anticipo'] < 0 || $d['dias_anticipo'] > 365) {
    volver_con_error('Revisá los días de anticipo (0 a 365) y la opción de inicio mensual.', $volver);
}
if ($d['nombre'] === '') {
    volver_con_error('El nombre del servicio es obligatorio.', $volver);
}
if ($err = largo_excedido([
    'El nombre' => [$d['nombre'], 160], 'La descripción' => [$d['descripcion'], 160],
    'La unidad' => [post('unidad'), 60], 'La unidad en singular' => [post('unidad_singular'), 60],
    'El detalle' => [post('detalle'), 160],
])) {
    volver_con_error($err, $volver);
}
if ($porCantidad) {
    $cantidad = parsear_monto(post('cantidad'));
    $precioUnidad = parsear_monto(post('precio_unidad'));
    $unidad = post('unidad');
    if ($cantidad === null || $cantidad < 0.01) {
        volver_con_error('La cantidad no es válida: escribí un número mayor a cero.', $volver);
    }
    if ($precioUnidad === null || $precioUnidad < 0.01) {
        volver_con_error('El precio por unidad no es válido: escribí un número mayor a cero.', $volver);
    }
    if ($unidad === '') {
        volver_con_error('Indicá la unidad (ej. "usuarios", "cuentas de mail").', $volver);
    }
    $monto = round($cantidad * $precioUnidad, 2);
    if ($monto < 0.01 || $monto > MONTO_MAX) {
        volver_con_error('El monto resultante (cantidad × precio por unidad) no es válido.', $volver);
    }
    $d['cantidad'] = $cantidad;
    $d['precio_unidad'] = $precioUnidad;
    $d['unidad'] = $unidad;
    $d['unidad_singular'] = post('unidad_singular');
    $d['detalle'] = post('detalle');
    $d['monto'] = $monto;
} else {
    $d['monto'] = parsear_monto(post('monto')) ?? -1.0;
    if ($d['monto'] < 0.01) {
        volver_con_error('El monto no es válido: escribí solo números (ej. 15000 o 40,50), mayor a cero.', $volver);
    }
}
if (!in_array($d['moneda'], ['ARS', 'USD'], true) || !in_array($d['tipo_cobro'], ['mensual', 'anual'], true)
    || !in_array($d['estado'], ['activo', 'pausado', 'baja'], true)) {
    volver_con_error('Hay un valor inválido en el formulario.', $volver);
}
if (!fecha_valida($d['fecha_inicio'])) {
    volver_con_error('La fecha de inicio no es válida.', $volver);
}

// Vencimiento: solo para servicios anuales (por defecto, un año después del inicio)
$venc = post('proximo_vencimiento');
if ($d['tipo_cobro'] === 'anual') {
    if ($venc === '') {
        $venc = date('Y-m-d', strtotime($d['fecha_inicio'] . ' +1 year'));
    } elseif (!fecha_valida($venc)) {
        volver_con_error('La fecha de vencimiento no es válida.', $volver);
    }
    $d['proximo_vencimiento'] = $venc;
} else {
    $d['proximo_vencimiento'] = null;
}

if ($id) {
    if (abs((float) $actual['monto'] - $d['monto']) > 0.004) {
        insertar('servicios_precios_hist', [
            'servicio_id' => $id, 'monto_anterior' => $actual['monto'], 'monto_nuevo' => $d['monto'],
            'porcentaje' => porcentaje_historial((float) $actual['monto'], $d['monto']),
            'motivo' => 'Edición manual', 'fecha' => date('Y-m-d H:i:s'),
        ]);
    }
    if ($actual['proximo_vencimiento'] !== $d['proximo_vencimiento']) {
        $d['aviso_renovacion_enviado_en'] = null;   // cambió la fecha de vencimiento: el aviso anterior ya no aplica
    }
    actualizar('servicios', $id, $d);
    flash('ok', 'Servicio actualizado.');
} else {
    $nuevo = insertar('servicios', $d + ['cliente_id' => $clienteId, 'creado_en' => date('Y-m-d H:i:s')]);
    flash('ok', 'Servicio creado.');
}
redirigir(url('cliente', ['id' => $clienteId]));
