<?php
/**
 * precios.php — Ajuste de precios por porcentaje: aplica a servicios normales y, en los por cantidad,
 * mantiene precio_unidad × cantidad = monto (si no, quedarían desincronizados tras el ajuste).
 */
declare(strict_types=1);

seccion('ajuste por porcentaje: un servicio por cantidad mantiene precio_unidad × cantidad = monto');
$usuarioId = nuevo_usuario_de_prueba('precios');
$clienteId = nuevo_cliente_de_prueba('Cliente Precios');

$servicioNormalId = insertar('servicios', [
    'nombre' => 'Mantenimiento', 'descripcion' => '', 'monto' => 10000, 'moneda' => 'ARS', 'tipo_cobro' => 'mensual',
    'fecha_inicio' => '2026-01-01', 'proximo_vencimiento' => null, 'dias_anticipo' => 30, 'inicio_mensual' => 'mes_siguiente',
    'estado' => 'activo', 'por_cantidad' => 0, 'cantidad' => null, 'unidad' => '', 'unidad_singular' => '',
    'precio_unidad' => null, 'detalle' => '', 'cliente_id' => $clienteId, 'creado_en' => date('Y-m-d H:i:s'),
]);
$servicioCantidadId = insertar('servicios', [
    'nombre' => 'Casillas', 'descripcion' => '', 'monto' => 175, 'moneda' => 'USD', 'tipo_cobro' => 'anual',
    'fecha_inicio' => '2025-01-01', 'proximo_vencimiento' => date('Y-m-d', strtotime('+100 days')), 'dias_anticipo' => 30,
    'inicio_mensual' => 'mes_siguiente', 'estado' => 'activo', 'por_cantidad' => 1, 'cantidad' => 7, 'unidad' => 'mails',
    'unidad_singular' => 'cuenta de mail', 'precio_unidad' => 25, 'detalle' => '', 'cliente_id' => $clienteId, 'creado_en' => date('Y-m-d H:i:s'),
]);

$p = ['clientes' => [$clienteId], 'porcentaje' => 20.0, 'moneda' => '', 'tipo' => '', 'pausados' => false, 'redondeo' => 'ninguno'];
$filas = calcular_ajuste($p);
verificar('calcular_ajuste() trae los 2 servicios', 2, count($filas));
aplicar_ajuste($filas, 20.0);

$normal = fila('SELECT monto FROM servicios WHERE id = ? AND usuario_id = {U}', [$servicioNormalId]);
verificar('servicio normal: +20% de 10000', '12000.00', $normal['monto']);

$cantidad = fila('SELECT monto, precio_unidad, cantidad FROM servicios WHERE id = ? AND usuario_id = {U}', [$servicioCantidadId]);
verificar('servicio por cantidad: el monto subió (+20% de 175 = 210)', '210.00', $cantidad['monto']);
$consistencia = round((float) $cantidad['precio_unidad'] * (float) $cantidad['cantidad'], 2);
verificar('precio_unidad × cantidad sigue dando exactamente el monto', round((float) $cantidad['monto'], 2), $consistencia);

$hist = filas('SELECT * FROM servicios_precios_hist WHERE usuario_id = {U} ORDER BY servicio_id');
verificar('quedaron 2 filas en el historial de precios (una por servicio)', 2, count($hist));
