<?php
/**
 * servicios_cantidad.php — Servicios por cantidad (cantidad × precio por unidad): alta real vía
 * servicio_guardar, monto calculado en el servidor, descripción automática en el cobro, historial de
 * precios, y aislamiento multiusuario.
 */
declare(strict_types=1);

seccion('alta de un servicio mensual por cantidad (Google Workspace): el monto se calcula en el servidor');
$usuarioId = nuevo_usuario_de_prueba('servicios_cant');
$clienteId = nuevo_cliente_de_prueba('Cliente Cantidad', ['telefono' => '3416000000', 'contacto' => 'Martin']);

ejecutar_accion($usuarioId, 'servicio_guardar', [
    'id' => '0', 'cliente_id' => (string) $clienteId, 'nombre' => 'Google Workspace', 'descripcion' => '',
    'monto' => '999999', 'moneda' => 'USD', 'por_cantidad' => '1', 'cantidad' => '4', 'precio_unidad' => '6',
    'unidad' => 'usuarios', 'unidad_singular' => 'usuario', 'detalle' => '', 'tipo_cobro' => 'mensual',
    'fecha_inicio' => '2026-01-01', 'estado' => 'activo', 'inicio_mensual' => 'mes_siguiente', 'dias_anticipo' => '30',
]);
$servicio = fila("SELECT * FROM servicios WHERE nombre = 'Google Workspace' AND usuario_id = {U}");
verificar_cierto('el servicio se creó', $servicio !== null);
verificar('el monto ignora lo enviado y usa cantidad × precio por unidad (4×6)', '24.00', $servicio['monto']);

seccion('el mensaje de cobro arma sola la descripción ("4 usuarios")');
generar_cargos(date('Y-m'));
$cliente = fila('SELECT * FROM clientes WHERE id = ? AND usuario_id = {U}', [$clienteId]);
verificar_contiene('mensaje_cobro() dice "(4 usuarios)"', '(4 usuarios)', mensaje_cobro($cliente));

seccion('cambiar la cantidad recalcula el monto y queda en el historial de precios');
ejecutar_accion($usuarioId, 'servicio_guardar', [
    'id' => (string) $servicio['id'], 'cliente_id' => (string) $clienteId, 'nombre' => 'Google Workspace', 'descripcion' => '',
    'monto' => '', 'moneda' => 'USD', 'por_cantidad' => '1', 'cantidad' => '10', 'precio_unidad' => '6',
    'unidad' => 'usuarios', 'unidad_singular' => 'usuario', 'detalle' => '', 'tipo_cobro' => 'mensual',
    'fecha_inicio' => '2026-01-01', 'estado' => 'activo', 'inicio_mensual' => 'mes_siguiente', 'dias_anticipo' => '30',
]);
$servicioEditado = fila('SELECT monto FROM servicios WHERE id = ? AND usuario_id = {U}', [$servicio['id']]);
verificar('el monto subió a 10×6', '60.00', $servicioEditado['monto']);
$hist = fila('SELECT monto_anterior, monto_nuevo FROM servicios_precios_hist WHERE servicio_id = ? AND usuario_id = {U}', [$servicio['id']]);
verificar_cierto('quedó una fila en el historial de precios', $hist !== null);
verificar('el historial dice 24 → 60', ['24.00', '60.00'], [$hist['monto_anterior'], $hist['monto_nuevo']]);

seccion('descripción manual: se respeta aunque el servicio sea por cantidad (no se pisa)');
ejecutar_accion($usuarioId, 'servicio_guardar', [
    'id' => '0', 'cliente_id' => (string) $clienteId, 'nombre' => 'Casillas de mail', 'descripcion' => 'plan anual',
    'monto' => '', 'moneda' => 'USD', 'por_cantidad' => '1', 'cantidad' => '7', 'precio_unidad' => '25',
    'unidad' => 'mails', 'unidad_singular' => 'cuenta de mail', 'detalle' => '10.00 GB de almacenamiento',
    'tipo_cobro' => 'anual', 'fecha_inicio' => '2025-12-01', 'proximo_vencimiento' => date('Y-m-d', strtotime('+10 days')),
    'estado' => 'activo', 'inicio_mensual' => 'mes_siguiente', 'dias_anticipo' => '30',
]);
generar_cargos(date('Y-m'));
verificar_contiene('mensaje_cobro() respeta la descripción manual', '(plan anual)', mensaje_cobro($cliente));

seccion('aislamiento: otro usuario no puede editar el servicio ajeno');
$otroUsuarioId = nuevo_usuario_de_prueba('servicios_cant_ajeno');
ejecutar_accion($otroUsuarioId, 'servicio_guardar', [
    'id' => (string) $servicio['id'], 'cliente_id' => (string) $clienteId, 'nombre' => 'hackeado', 'monto' => '1',
    'moneda' => 'ARS', 'tipo_cobro' => 'mensual', 'fecha_inicio' => '2026-01-01', 'estado' => 'activo',
    'inicio_mensual' => 'mes_siguiente', 'dias_anticipo' => '30',
]);
fijar_usuario($usuarioId);   // nuevo_usuario_de_prueba() dejó el contexto en el usuario ajeno: hay que volver al dueño para verificar
$servicioSinTocar = fila('SELECT nombre FROM servicios WHERE id = ? AND usuario_id = {U}', [$servicio['id']]);
verificar('el nombre del servicio no cambió (aislado del otro usuario)', 'Google Workspace', $servicioSinTocar['nombre']);
