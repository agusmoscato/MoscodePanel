<?php
/**
 * dominios.php — Cobro automático de dominios (como los servicios anuales) y su aviso de renovación.
 * Reproduce el bug reportado (el cargo de un dominio renovado no quedaba registrado) y prueba el
 * comportamiento nuevo: generación automática por anticipo, idempotencia, "Renovar" sin mover la fecha al
 * cobrar, reutilización del cargo del período, y el aviso de renovación por WhatsApp.
 */
declare(strict_types=1);

seccion('generar_cargos() crea el cargo de un dominio dentro de su ventana de anticipo');
$usuarioId = nuevo_usuario_de_prueba('dominios');
$clienteId = nuevo_cliente_de_prueba('Cliente Dominios', ['telefono' => '3415551111', 'contacto' => 'Ana']);

$domId = insertar('dominios', [
    'dominio' => 'auto-genera.com', 'proveedor' => 'NIC.ar', 'fecha_vencimiento' => date('Y-m-d', strtotime('+10 days')),
    'dias_anticipo' => 30, 'costo_renovacion' => 3000, 'moneda_costo' => 'ARS',
    'precio_cliente' => 9000, 'moneda_precio' => 'ARS', 'estado' => 'activo',
    'cliente_id' => $clienteId, 'creado_en' => date('Y-m-d H:i:s'),
]);
$vencimientoOriginal = date('Y-m-d', strtotime('+10 days'));

$r1 = generar_cargos(date('Y-m'));
verificar('generar_cargos() crea 1 cargo de dominio', 1, $r1['dominios']);

$cargo = fila('SELECT * FROM cargos WHERE dominio_id = ? AND usuario_id = {U}', [$domId]);
verificar_cierto('el cargo existe', $cargo !== null);
verificar_cierto('concepto empieza con "Dominio auto-genera.com"', str_starts_with((string) $cargo['concepto'], 'Dominio auto-genera.com'));
verificar('fecha_vencimiento del cargo = vencimiento del dominio (no "hoy")', $vencimientoOriginal, $cargo['fecha_vencimiento']);
verificar('monto del cargo = precio_cliente', '9000.00', $cargo['monto']);

seccion('correr generar_cargos() de nuevo no duplica (idempotente)');
$r2 = generar_cargos(date('Y-m'));
verificar('la segunda corrida no crea otro cargo de este dominio', 0, $r2['dominios']);
verificar('sigue habiendo un solo cargo para este dominio', 1, (int) valor('SELECT COUNT(*) FROM cargos WHERE dominio_id = ? AND usuario_id = {U}', [$domId]));

seccion('el cargo aparece en la deuda, en los cargos pendientes y en el mensaje de WhatsApp');
$deuda = deuda_cliente($clienteId);
verificar('deuda_cliente() ARS = 9000', 9000.0, $deuda['ARS']);
verificar('cargos_pendientes() trae 1 fila', 1, count(cargos_pendientes($clienteId)));
$cliente = fila('SELECT * FROM clientes WHERE id = ? AND usuario_id = {U}', [$clienteId]);
verificar_contiene('mensaje_cobro() dice "Dominio auto-genera.com"', 'Dominio auto-genera.com', mensaje_cobro($cliente));

seccion('un dominio fuera de la ventana de anticipo, o sin precio al cliente, no genera cargo');
$domLejosId = insertar('dominios', [
    'dominio' => 'lejos.com', 'proveedor' => '', 'fecha_vencimiento' => date('Y-m-d', strtotime('+200 days')),
    'dias_anticipo' => 30, 'costo_renovacion' => 1000, 'moneda_costo' => 'ARS',
    'precio_cliente' => 2000, 'moneda_precio' => 'ARS', 'estado' => 'activo',
    'cliente_id' => $clienteId, 'creado_en' => date('Y-m-d H:i:s'),
]);
$domGratisId = insertar('dominios', [
    'dominio' => 'gratis.com', 'proveedor' => '', 'fecha_vencimiento' => date('Y-m-d', strtotime('+5 days')),
    'dias_anticipo' => 30, 'costo_renovacion' => 1000, 'moneda_costo' => 'ARS',
    'precio_cliente' => 0, 'moneda_precio' => 'ARS', 'estado' => 'activo',
    'cliente_id' => $clienteId, 'creado_en' => date('Y-m-d H:i:s'),
]);
generar_cargos(date('Y-m'));
verificar('dominio lejano (fuera del anticipo) no genera cargo', 0, (int) valor('SELECT COUNT(*) FROM cargos WHERE dominio_id = ? AND usuario_id = {U}', [$domLejosId]));
verificar('dominio con precio_cliente = 0 no genera cargo', 0, (int) valor('SELECT COUNT(*) FROM cargos WHERE dominio_id = ? AND usuario_id = {U}', [$domGratisId]));

seccion('cobrar el cargo NO cambia la fecha de vencimiento del dominio (eso se hace aparte, con "Renovar")');
$cargoPendiente = fila("SELECT * FROM cargos WHERE dominio_id = ? AND usuario_id = {U} AND estado IN ('pendiente','parcial')", [$domId]);
registrar_pago($clienteId, date('Y-m-d'), (float) $cargoPendiente['monto'], $cargoPendiente['moneda'], 'transferencia', 'pago dominio', 1000.0, [$cargoPendiente['id'] => (float) $cargoPendiente['monto']]);
$domTrasPago = fila('SELECT fecha_vencimiento FROM dominios WHERE id = ? AND usuario_id = {U}', [$domId]);
verificar('fecha_vencimiento del dominio no cambió al cobrar', $vencimientoOriginal, $domTrasPago['fecha_vencimiento']);
verificar('el cargo quedó pagado', 'pagado', fila('SELECT estado FROM cargos WHERE id = ? AND usuario_id = {U}', [$cargoPendiente['id']])['estado']);

seccion('"Renovar" (el cargo de este período ya existe: lo usa, no crea otro) avanza un año y resetea el aviso');
actualizar('dominios', $domId, ['aviso_renovacion_enviado_en' => date('Y-m-d H:i:s')]);
ejecutar_accion($usuarioId, 'dominio_renovar', ['id' => (string) $domId, 'vence' => $vencimientoOriginal]);
$domRenovado = fila('SELECT fecha_vencimiento, aviso_renovacion_enviado_en FROM dominios WHERE id = ? AND usuario_id = {U}', [$domId]);
verificar('fecha_vencimiento avanzó un año', date('Y-m-d', strtotime($vencimientoOriginal . ' +1 year')), $domRenovado['fecha_vencimiento']);
verificar_cierto('el aviso se reseteó (quedó NULL)', $domRenovado['aviso_renovacion_enviado_en'] === null);
verificar('sigue habiendo un solo cargo para este dominio (no se duplicó al renovar)', 1, (int) valor('SELECT COUNT(*) FROM cargos WHERE dominio_id = ? AND usuario_id = {U}', [$domId]));

seccion('"Renovar" SIN que exista todavía el cargo del período lo crea en el momento');
$vencimientoLejos = fila('SELECT fecha_vencimiento FROM dominios WHERE id = ? AND usuario_id = {U}', [$domLejosId])['fecha_vencimiento'];
ejecutar_accion($usuarioId, 'dominio_renovar', ['id' => (string) $domLejosId, 'vence' => $vencimientoLejos]);
$cargoLejos = fila('SELECT * FROM cargos WHERE dominio_id = ? AND usuario_id = {U}', [$domLejosId]);
verificar_cierto('el cargo del período que terminó se creó al renovar', $cargoLejos !== null);
verificar('la fecha del cargo es la del vencimiento ANTERIOR (el período que se cerró), no la nueva', $vencimientoLejos, $cargoLejos['fecha_vencimiento'] ?? null);
$domLejosRenovado = fila('SELECT fecha_vencimiento FROM dominios WHERE id = ? AND usuario_id = {U}', [$domLejosId]);
verificar('la fecha del dominio avanzó un año', date('Y-m-d', strtotime($vencimientoLejos . ' +1 year')), $domLejosRenovado['fecha_vencimiento']);

seccion('aviso de renovación por WhatsApp para un dominio (igual que un servicio anual)');
$domAviso = fila('SELECT d.*, c.contacto, c.telefono, c.nombre AS cliente_nombre FROM dominios d JOIN clientes c ON c.id = d.cliente_id AND c.usuario_id = {U} WHERE d.id = ? AND d.usuario_id = {U}', [$domGratisId]);
$clienteAviso = ['nombre' => $domAviso['cliente_nombre'], 'contacto' => $domAviso['contacto'], 'telefono' => $domAviso['telefono']];
$item = item_renovacion_dominio($domAviso);
$mensajeAviso = mensaje_renovacion($clienteAviso, $item);
verificar_contiene('el aviso menciona el nombre del dominio', 'gratis.com', $mensajeAviso);
verificar_cierto('el link de WhatsApp es válido', str_starts_with(link_whatsapp_renovacion($clienteAviso, $item), 'https://wa.me/'));

ejecutar_accion($usuarioId, 'aviso_renovacion_enviar', ['tipo' => 'dominio', 'id' => (string) $domGratisId]);
$avisoGuardado = fila('SELECT aviso_renovacion_enviado_en FROM dominios WHERE id = ? AND usuario_id = {U}', [$domGratisId]);
verificar_cierto('el aviso de dominio quedó registrado', $avisoGuardado['aviso_renovacion_enviado_en'] !== null);

seccion('aislamiento: otro usuario no puede marcar el aviso ni renovar el dominio ajeno');
$otroUsuarioId = nuevo_usuario_de_prueba('dominios_ajeno');
ejecutar_accion($otroUsuarioId, 'aviso_renovacion_enviar', ['tipo' => 'dominio', 'id' => (string) $domId]);
fijar_usuario($usuarioId);   // nuevo_usuario_de_prueba() dejó el contexto en el usuario ajeno: hay que volver al dueño para verificar
// No hay forma de leer la respuesta JSON desde acá (es otro proceso): lo que importa es que NO haya tocado la fila.
$domSinTocar = fila('SELECT aviso_renovacion_enviado_en FROM dominios WHERE id = ? AND usuario_id = {U}', [$domId]);
verificar_cierto('el aviso del dominio ajeno sigue en NULL (el otro usuario no lo pudo marcar)', $domSinTocar['aviso_renovacion_enviado_en'] === null);

seccion('editar el dominio a mano (sin pasar por "Renovar") también resetea el aviso si cambia el vencimiento');
actualizar('dominios', $domId, ['aviso_renovacion_enviado_en' => date('Y-m-d H:i:s')]);
$nuevaFecha = date('Y-m-d', strtotime($domRenovado['fecha_vencimiento'] . ' +3 days'));
ejecutar_accion($usuarioId, 'dominio_guardar', [
    'id' => (string) $domId, 'cliente_id' => (string) $clienteId, 'dominio' => 'auto-genera.com', 'proveedor' => 'NIC.ar',
    'fecha_vencimiento' => $nuevaFecha, 'dias_anticipo' => '15', 'costo_renovacion' => '3000', 'moneda_costo' => 'ARS',
    'precio_cliente' => '9000', 'moneda_precio' => 'ARS', 'estado' => 'activo',
]);
$domEditado = fila('SELECT fecha_vencimiento, dias_anticipo, aviso_renovacion_enviado_en FROM dominios WHERE id = ? AND usuario_id = {U}', [$domId]);
verificar('la fecha de vencimiento quedó la editada', $nuevaFecha, $domEditado['fecha_vencimiento']);
verificar('los días de anticipo quedaron los editados', 15, (int) $domEditado['dias_anticipo']);
verificar_cierto('el aviso se reseteó al cambiar la fecha a mano', $domEditado['aviso_renovacion_enviado_en'] === null);
