<?php
/**
 * renovacion_servicios.php — Servicios anuales: cobrar completo avanza el vencimiento un año y resetea el
 * aviso; anular el pago lo revierte. Aviso de renovación por WhatsApp y su aislamiento multiusuario.
 */
declare(strict_types=1);

seccion('cobrar completo un servicio anual avanza el vencimiento un año y resetea el aviso');
$usuarioId = nuevo_usuario_de_prueba('renov_serv');
$clienteId = nuevo_cliente_de_prueba('Cliente Renovación', ['telefono' => '3417001122', 'contacto' => 'Carla']);
$vencimientoOriginal = date('Y-m-d', strtotime('+15 days'));
$servicioId = insertar('servicios', [
    'nombre' => 'Hosting anual', 'descripcion' => '', 'monto' => 15000, 'moneda' => 'ARS', 'tipo_cobro' => 'anual',
    'fecha_inicio' => '2025-01-01', 'proximo_vencimiento' => $vencimientoOriginal, 'dias_anticipo' => 30,
    'inicio_mensual' => 'mes_siguiente', 'estado' => 'activo', 'por_cantidad' => 0, 'cantidad' => null,
    'unidad' => '', 'unidad_singular' => '', 'precio_unidad' => null, 'detalle' => '',
    'cliente_id' => $clienteId, 'creado_en' => date('Y-m-d H:i:s'),
]);
generar_cargos(date('Y-m'));
actualizar('servicios', $servicioId, ['aviso_renovacion_enviado_en' => date('Y-m-d H:i:s')]);

$cargo = fila('SELECT * FROM cargos WHERE servicio_id = ? AND usuario_id = {U}', [$servicioId]);
$pagoId = registrar_pago($clienteId, date('Y-m-d'), (float) $cargo['monto'], $cargo['moneda'], 'transferencia', 'pago anual', 1000.0, [$cargo['id'] => (float) $cargo['monto']]);

$servicioPagado = fila('SELECT proximo_vencimiento, aviso_renovacion_enviado_en FROM servicios WHERE id = ? AND usuario_id = {U}', [$servicioId]);
verificar('proximo_vencimiento avanzó un año', date('Y-m-d', strtotime($vencimientoOriginal . ' +1 year')), $servicioPagado['proximo_vencimiento']);
verificar_cierto('el aviso se reseteó a NULL', $servicioPagado['aviso_renovacion_enviado_en'] === null);

seccion('anular el pago revierte el vencimiento (pero no hace falta que el aviso vuelva: ya no corresponde)');
anular_pago($pagoId, 'prueba de reversión', $usuarioId);
$servicioRevertido = fila('SELECT proximo_vencimiento FROM servicios WHERE id = ? AND usuario_id = {U}', [$servicioId]);
verificar('proximo_vencimiento volvió al original', $vencimientoOriginal, $servicioRevertido['proximo_vencimiento']);

seccion('aviso de renovación por WhatsApp de un servicio anual');
$cliente = fila('SELECT * FROM clientes WHERE id = ? AND usuario_id = {U}', [$clienteId]);
$servicio = fila('SELECT * FROM servicios WHERE id = ? AND usuario_id = {U}', [$servicioId]);
verificar_cierto('el link de WhatsApp es válido', str_starts_with(link_whatsapp_renovacion($cliente, $servicio), 'https://wa.me/'));

ejecutar_accion($usuarioId, 'aviso_renovacion_enviar', ['tipo' => 'servicio', 'id' => (string) $servicioId]);
$avisoGuardado = fila('SELECT aviso_renovacion_enviado_en FROM servicios WHERE id = ? AND usuario_id = {U}', [$servicioId]);
verificar_cierto('el aviso quedó registrado', $avisoGuardado['aviso_renovacion_enviado_en'] !== null);

seccion('aislamiento: otro usuario no puede marcar el aviso ni editar el servicio ajeno');
$otroUsuarioId = nuevo_usuario_de_prueba('renov_serv_ajeno');
ejecutar_accion($otroUsuarioId, 'aviso_renovacion_enviar', ['tipo' => 'servicio', 'id' => (string) $servicioId]);
fijar_usuario($usuarioId);   // nuevo_usuario_de_prueba() dejó el contexto en el usuario ajeno: hay que volver al dueño para verificar
$avisoPrevio = fila('SELECT aviso_renovacion_enviado_en FROM servicios WHERE id = ? AND usuario_id = {U}', [$servicioId]);
verificar('el aviso no cambió por la acción del otro usuario (sigue siendo la del dueño)', $avisoGuardado['aviso_renovacion_enviado_en'], $avisoPrevio['aviso_renovacion_enviado_en']);

seccion('una clave "tipo" inválida no marca nada');
ejecutar_accion($usuarioId, 'aviso_renovacion_enviar', ['tipo' => 'otracosa', 'id' => (string) $servicioId]);
$avisoTrasInvalido = fila('SELECT aviso_renovacion_enviado_en FROM servicios WHERE id = ? AND usuario_id = {U}', [$servicioId]);
verificar('sigue siendo el mismo valor de antes', $avisoPrevio['aviso_renovacion_enviado_en'], $avisoTrasInvalido['aviso_renovacion_enviado_en']);
