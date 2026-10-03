<?php
/**
 * mensajes.php — Funciones puras de mensajes (servicios por cantidad y aviso de renovación anual). No usan
 * la base: solo arman arrays a mano y verifican el texto que sale.
 */
declare(strict_types=1);

// --- descripcion_auto_servicio(): la {descripcion} del mensaje de cobro ---------------------------------

seccion('descripcion_auto_servicio()');
verificar(
    'descripcion manual: se respeta aunque el servicio sea por cantidad',
    'renovación anual',
    descripcion_auto_servicio(['descripcion' => 'renovación anual', 'por_cantidad' => true, 'cantidad' => 4, 'unidad' => 'usuarios'])
);
verificar(
    'descripcion automática por cantidad: "4 usuarios"',
    '4 usuarios',
    descripcion_auto_servicio(['descripcion' => '', 'por_cantidad' => true, 'cantidad' => 4, 'unidad' => 'usuarios'])
);
verificar(
    'servicio no por cantidad y sin descripción: vacío',
    '',
    descripcion_auto_servicio(['descripcion' => '', 'por_cantidad' => false, 'cantidad' => null, 'unidad' => ''])
);

// --- detalle_cantidad_servicio(): para el aviso de renovación -------------------------------------------

seccion('detalle_cantidad_servicio()');
verificar(
    'detalle de cantidad con detalle opcional',
    'los 7 mails con 10.00 GB de almacenamiento',
    detalle_cantidad_servicio(['cantidad' => 7, 'unidad' => 'mails', 'detalle' => '10.00 GB de almacenamiento'])
);
verificar(
    'detalle de cantidad sin detalle opcional',
    'los 4 usuarios',
    detalle_cantidad_servicio(['cantidad' => 4, 'unidad' => 'usuarios', 'detalle' => ''])
);
verificar(
    'artículo en singular cuando la cantidad es 1',
    'el 1 usuario',
    detalle_cantidad_servicio(['cantidad' => 1, 'unidad' => 'usuario', 'detalle' => ''])
);

// --- monto_codigo_wa(): montos con el código de moneda, sin convertir -----------------------------------

seccion('monto_codigo_wa()');
verificar('monto entero con código de moneda', '175 USD', monto_codigo_wa(175.0, 'USD'));
verificar('monto con decimales con código de moneda', '61.800,50 ARS', monto_codigo_wa(61800.5, 'ARS'));

// --- saludo_horario(): según la hora ---------------------------------------------------------------------

seccion('saludo_horario()');
verificar('saludo a las 9 (mañana)', 'Buenos días', saludo_horario(9));
verificar('saludo a las 11 (límite mañana)', 'Buenos días', saludo_horario(11));
verificar('saludo a las 12 (mediodía)', 'Buenas tardes', saludo_horario(12));
verificar('saludo a las 19 (límite tarde)', 'Buenas tardes', saludo_horario(19));
verificar('saludo a las 20 (noche)', 'Buenas noches', saludo_horario(20));
verificar('saludo a las 23', 'Buenas noches', saludo_horario(23));

// --- texto_cuando_vencimiento(): relativo a hoy -----------------------------------------------------------

seccion('texto_cuando_vencimiento()');
$hoy = new DateTime('today');
verificar('vencimiento hoy mismo: "este mes"', 'este mes', texto_cuando_vencimiento($hoy->format('Y-m-d')));
$finDeMes = (clone $hoy)->modify('last day of this month');
verificar('vencimiento a fin de este mes: "este mes"', 'este mes', texto_cuando_vencimiento($finDeMes->format('Y-m-d')));
$mesQueViene = (clone $hoy)->modify('first day of next month');
verificar('vencimiento el mes que viene: "el mes que viene"', 'el mes que viene', texto_cuando_vencimiento($mesQueViene->format('Y-m-d')));
$lejos = (clone $hoy)->modify('+6 months');
verificar('vencimiento lejano: "el dd/mm"', 'el ' . $lejos->format('d/m'), texto_cuando_vencimiento($lejos->format('Y-m-d')));

// --- renderizar_condicional(): bloque {si_cantidad}...{fin_si_cantidad} ----------------------------------

seccion('renderizar_condicional()');
$plantillaCond = 'Valor: {total}{si_cantidad}, a razón de {precio_unidad}{fin_si_cantidad}. Gracias.';
verificar(
    'bloque condicional activo: se conserva el contenido (sin las marcas)',
    'Valor: {total}, a razón de {precio_unidad}. Gracias.',
    renderizar_condicional($plantillaCond, 'cantidad', true)
);
verificar(
    'bloque condicional inactivo: se quita entero',
    'Valor: {total}. Gracias.',
    renderizar_condicional($plantillaCond, 'cantidad', false)
);

// --- datos_mensaje_renovacion() + plantilla por defecto: integración completa ----------------------------

seccion('datos_mensaje_renovacion() + PLANTILLA_RENOVACION_DEFECTO (servicio por cantidad)');
$servicioCantidad = [
    'nombre' => 'los mails', 'proximo_vencimiento' => $hoy->format('Y-m-d'), 'monto' => 175.0, 'moneda' => 'USD',
    'por_cantidad' => true, 'cantidad' => 7, 'unidad' => 'mails', 'unidad_singular' => 'cuenta de mail',
    'detalle' => '10.00 GB de almacenamiento', 'precio_unidad' => 25.0,
];
$cliente = ['nombre' => 'Martin Pérez', 'contacto' => 'Martin', 'telefono' => '3416000000'];
$vars = datos_mensaje_renovacion($cliente, $servicioCantidad, 'blue');

verificar('vars: {contacto} es el nombre de pila', 'Martin', $vars['{contacto}']);
verificar('vars: {servicio} es el nombre del servicio', 'los mails', $vars['{servicio}']);
verificar('vars: {cuando} "este mes"', 'este mes', $vars['{cuando}']);
verificar('vars: {fecha_vencimiento} en dd/mm', $hoy->format('d/m'), $vars['{fecha_vencimiento}']);
verificar('vars: {vigencia_desde} en dd/mm/aaaa', $hoy->format('d/m/Y'), $vars['{vigencia_desde}']);
verificar('vars: {vigencia_hasta} un año después', (clone $hoy)->modify('+1 year')->format('d/m/Y'), $vars['{vigencia_hasta}']);
verificar('vars: {detalle_cantidad} con artículo, cantidad, unidad y detalle', 'los 7 mails con 10.00 GB de almacenamiento', $vars['{detalle_cantidad}']);
verificar('vars: {total} sin convertir, con código de moneda', '175 USD', $vars['{total}']);
verificar('vars: {precio_unidad} sin convertir, con código de moneda', '25 USD', $vars['{precio_unidad}']);
verificar('vars: {unidad_singular} tal como se cargó', 'cuenta de mail', $vars['{unidad_singular}']);
verificar('vars: {tipo_dolar} tal como se pasó', 'blue', $vars['{tipo_dolar}']);

$plantilla = renderizar_condicional(PLANTILLA_RENOVACION_DEFECTO, 'cantidad', true);
$mensaje = strtr($plantilla, $vars);
verificar_contiene('mensaje (por cantidad): saluda por el nombre de pila', 'Martin, ¿Cómo estás?', $mensaje);
verificar_contiene('mensaje (por cantidad): nombra el servicio y cuándo vence', 'la renovación del servicio de los mails que vence este mes (exactamente el ' . $hoy->format('d/m') . ')', $mensaje);
verificar_contiene(
    'mensaje (por cantidad): detalle, vigencia, total y precio por unidad',
    'La renovación de los 7 mails con 10.00 GB de almacenamiento tendría vigencia desde el ' . $hoy->format('d/m/Y')
        . ' hasta el ' . (clone $hoy)->modify('+1 year')->format('d/m/Y') . ' y tiene un valor de 175 USD, quedaría a razón de 25 USD anuales por cuenta de mail.',
    $mensaje
);

seccion('mismo servicio pero SIN cantidad');
$servicioSinCantidad = $servicioCantidad;
$servicioSinCantidad['por_cantidad'] = false;
$varsSinCantidad = datos_mensaje_renovacion($cliente, $servicioSinCantidad, 'blue');
verificar('vars sin cantidad: {detalle_cantidad} cae al nombre del servicio', 'los mails', $varsSinCantidad['{detalle_cantidad}']);

$plantillaSinCantidad = renderizar_condicional(PLANTILLA_RENOVACION_DEFECTO, 'cantidad', false);
$mensajeSinCantidad = strtr($plantillaSinCantidad, $varsSinCantidad);
verificar('mensaje (sin cantidad): no menciona el precio por unidad', false, str_contains($mensajeSinCantidad, 'quedaría a razón de'));
verificar_contiene('mensaje (sin cantidad): el valor termina la oración con punto', 'tiene un valor de 175 USD. Este pago puede ser', $mensajeSinCantidad);

seccion('item_renovacion_dominio(): un dominio se adapta al mismo formato');
$dominio = ['dominio' => 'midominio.com.ar', 'fecha_vencimiento' => $hoy->format('Y-m-d'), 'precio_cliente' => 2000.0, 'moneda_precio' => 'ARS'];
$itemDominio = item_renovacion_dominio($dominio);
verificar('adaptado: por_cantidad es false', false, $itemDominio['por_cantidad']);
$varsDominio = datos_mensaje_renovacion($cliente, $itemDominio, 'blue');
verificar('vars de dominio: {servicio} es el nombre del dominio', 'midominio.com.ar', $varsDominio['{servicio}']);
verificar('vars de dominio: {detalle_cantidad} cae al nombre del dominio', 'midominio.com.ar', $varsDominio['{detalle_cantidad}']);
$mensajeDominio = strtr(renderizar_condicional(PLANTILLA_RENOVACION_DEFECTO, 'cantidad', false), $varsDominio);
verificar('mensaje de dominio: no menciona el precio por unidad', false, str_contains($mensajeDominio, 'quedaría a razón de'));
