<?php
/**
 * http_aislamiento.php — Aislamiento entre cuentas en TODAS las pantallas y exportaciones, por HTTP.
 *
 * Una cuenta "dueña" carga datos con una marca única (ZZAJENO…) en nombres, notas y conceptos, y un monto
 * reconocible (98765,43). Otra cuenta común y un administrador recorren cada pantalla del panel, cada pantalla con
 * los ids de la dueña en la URL, y cada exportación CSV: la marca no puede aparecer en ninguna respuesta. Como control
 * (para que "no aparece" no pase por accidente), la dueña sí la ve en esas mismas pantallas y exportaciones.
 * Al final, la cuenta común intenta las acciones que reciben ids con los de la dueña: los datos de la dueña no cambian.
 *
 * Complementa al auditor estático (privado/scripts/auditar_aislamiento.php), que revisa el SQL sin ejecutarlo.
 */
declare(strict_types=1);

$MARCA = 'ZZAJENO' . strtoupper(bin2hex(random_bytes(3)));
$marcaMin = strtolower($MARCA);
// El monto se busca con sus decimales (en pantalla "98.765,43", en el CSV "98765,43" o "98765.43"): "98765" solo podría aparecer por azar en un token
$contieneMarca = fn(string $texto): bool => stripos($texto, $MARCA) !== false || (bool) preg_match('/98\.?765[,.]43/', $texto);

seccion('datos de la cuenta dueña (con la marca ' . $MARCA . ')');
$duena = nuevo_usuario_con_clave('duena', 'Clave-Duena-2026');
guardar_cotizacion('blue', 1000.0, 'manual', true);
$cli = nuevo_cliente_de_prueba($MARCA . ' SA', ['email' => $marcaMin . '@cliente.test', 'notas' => 'Notas ' . $MARCA, 'contacto' => 'Contacto ' . $MARCA]);
$srv = insertar('servicios', [
    'cliente_id' => $cli, 'nombre' => $MARCA . ' mantenimiento', 'descripcion' => '', 'monto' => 98765.43, 'moneda' => 'ARS',
    'tipo_cobro' => 'mensual', 'fecha_inicio' => date('Y-m-01', strtotime('-2 months')), 'estado' => 'activo', 'creado_en' => date('Y-m-d H:i:s'),
]);
$srvAnual = insertar('servicios', [
    'cliente_id' => $cli, 'nombre' => $MARCA . ' hosting anual', 'descripcion' => '', 'monto' => 5000, 'moneda' => 'ARS',
    'tipo_cobro' => 'anual', 'fecha_inicio' => date('Y-m-d', strtotime('-1 year +20 days')), 'proximo_vencimiento' => date('Y-m-d', strtotime('+20 days')),
    'estado' => 'activo', 'creado_en' => date('Y-m-d H:i:s'),
]);
$dom = insertar('dominios', [
    'dominio' => $marcaMin . '.com.ar', 'proveedor' => 'NIC.ar', 'fecha_vencimiento' => date('Y-m-d', strtotime('+20 days')), 'dias_anticipo' => 30,
    'costo_renovacion' => 1000, 'moneda_costo' => 'ARS', 'precio_cliente' => 5000, 'moneda_precio' => 'ARS', 'estado' => 'activo',
    'cliente_id' => $cli, 'creado_en' => date('Y-m-d H:i:s'),
]);
generar_cargos(date('Y-m'));
$pago = registrar_pago($cli, date('Y-m-d'), 98765.43, 'ARS', 'transferencia', 'Pago ' . $MARCA, 1000.0);
$pagoAnulable = registrar_pago($cli, date('Y-m-d'), 1234.0, 'ARS', 'efectivo', 'Otro pago ' . $MARCA, 1000.0);
$plan = crear_plan($cli, ['concepto' => $MARCA . ' web en cuotas', 'descripcion' => '', 'monto_total' => 3000, 'moneda' => 'ARS', 'cuotas' => 3,
    'frecuencia' => 'mensual', 'notas' => ''], cuotas_por_defecto(3000, 3, date('Y-m-d', strtotime('+5 days')), 'mensual'));
$cargoDuena = (int) valor('SELECT id FROM cargos WHERE usuario_id = {U} AND cliente_id = ? AND estado IN (\'pendiente\',\'parcial\') ORDER BY id LIMIT 1', [$cli]);
ejecutar_accion($duena['id'], 'pago_anular', ['id' => (string) $pagoAnulable, 'motivo' => 'Prueba de aislamiento ' . $MARCA]);
fijar_usuario($duena['id']);
verificar_cierto('la dueña tiene cliente, servicios, dominio, cargos, pagos y plan', $cli && $srv && $srvAnual && $dom && $pago && $plan && $cargoDuena);
verificar_cierto('el pago anulado quedó anulado', valor('SELECT anulado_en FROM pagos WHERE id = ? AND usuario_id = {U}', [$pagoAnulable]) !== null);

/** Huella de todos los datos de la dueña: si una acción ajena cambia algo, cambia. */
$huella = function () use ($duena): string {
    return con_usuario($duena['id'], fn() => md5(json_encode([
        filas('SELECT * FROM clientes WHERE usuario_id = {U} ORDER BY id'),
        filas('SELECT * FROM servicios WHERE usuario_id = {U} ORDER BY id'),
        filas('SELECT * FROM dominios WHERE usuario_id = {U} ORDER BY id'),
        filas('SELECT * FROM cargos WHERE usuario_id = {U} ORDER BY id'),
        filas('SELECT * FROM pagos WHERE usuario_id = {U} ORDER BY id'),
        filas('SELECT * FROM pago_imputaciones WHERE usuario_id = {U} ORDER BY id'),
        filas('SELECT * FROM planes_pago WHERE usuario_id = {U} ORDER BY id'),
    ])));
};
$huellaInicial = $huella();

// Pantallas sin ids, y pantallas con los ids de la dueña en la URL
$pantallas = ['/', '/clientes', '/clientes?estado=todos', '/clientes?deuda=1', '/cobros', '/cobros?estado=todos', 
    '/vencimientos', '/vencimientos?tipo=dominio', '/vencimientos?tipo=servicio', '/vencimientos?tipo=cuota', '/cuotas', '/cuotas?estado=todos', '/precios',
    '/reportes', '/dolar', '/configuracion', '/mi-cuenta', '/notificaciones', '/elegir-cliente?para=pago', '/elegir-cliente?para=plan',
    '/servicios', '/servicios?estado=', '/dominios', '/dominios?estado='];
$conIds = ["/clientes/$cli", "/clientes/$cli/editar", "/clientes/$cli/resumen", "/clientes/$cli/servicios/nuevo", "/clientes/$cli/dominios/nuevo",
    "/clientes/$cli/cuotas/nueva", "/servicios/$srv/editar", "/servicios/$srvAnual/editar", "/dominios/$dom/editar", "/cuotas/$plan", "/cuotas/$plan/editar",
    "/pagos/$pago/anular", "/pagos/$pagoAnulable/anular", "/pagos/nuevo?cliente_id=$cli", "/precios?cliente_id=$cli",
    "/precios?previsualizar=1&clientes[]=$cli&porcentaje=10&redondeo=ninguno"];
$adminExtra = ['/usuarios', '/actividad', '/actividad?cuenta=' . $duena['id'], '/feriados', '/backups'];
$exportaciones = ['clientes', 'clientes?estado=todos', 'deudores', 'servicios', 'dominios', 'cargos', 'cargos?estado=todos', 'pagos', 'pagos?anio=' . date('Y'),
    'rep_ingresos', 'rep_facturado', 'rep_ranking', 'rep_dominios'];

seccion('control: la dueña SÍ ve sus datos (si no, "no aparece" no probaría nada)');
$navDuena = new Navegador();
$navDuena->login($duena['usuario'], $duena['clave']);
foreach (['/clientes', "/clientes/$cli", "/cuotas/$plan", "/pagos/$pago/anular", "/dominios/$dom/editar", '/vencimientos'] as $ruta) {
    verificar_cierto("dueña: $ruta muestra sus datos", $contieneMarca($navDuena->get($ruta)['cuerpo']));
}
foreach ($exportaciones as $exp) {
    $r = $navDuena->get('/exportar/' . (str_contains($exp, '?') ? str_replace('?', '.csv?', $exp) : "$exp.csv"));
    verificar_cierto("dueña: exportación $exp trae sus datos", $r['codigo'] === 200 && $contieneMarca($r['cuerpo']));
}

foreach (['usuario común' => 'usuario', 'administrador' => 'admin'] as $quien => $rol) {
    seccion("una cuenta $quien no ve NADA de la dueña: pantallas, pantallas con sus ids y exportaciones");
    $ajena = nuevo_usuario_con_clave('ajena_' . $rol, 'Clave-Ajena-2026', $rol);
    nuevo_cliente_de_prueba('Cliente propio de la ajena');
    $nav = new Navegador();
    $nav->login($ajena['usuario'], $ajena['clave']);
    foreach (array_merge($pantallas, $conIds, $rol === 'admin' ? $adminExtra : []) as $ruta) {
        $r = $nav->get($ruta);
        $ok = $r['codigo'] < 500 && !$contieneMarca($r['cuerpo'] . $r['location']);
        verificar_cierto("$quien: $ruta (HTTP {$r['codigo']}) sin datos de la dueña", $ok);
    }
    foreach ($exportaciones as $exp) {
        $ruta = '/exportar/' . (str_contains($exp, '?') ? str_replace('?', '.csv?', $exp) : "$exp.csv");
        $r = $nav->get($ruta);
        verificar_cierto("$quien: exportación $exp sin datos de la dueña", $r['codigo'] === 200 && !$contieneMarca($r['cuerpo']));
    }
    verificar('exportación inexistente: 404', 404, $nav->get('/exportar/no_existe.csv')['codigo']);
}

seccion('una cuenta común no puede modificar nada de la dueña con sus ids (acciones)');
$ajena = nuevo_usuario_con_clave('ajena_acciones', 'Clave-Ajena-2026');
$cliPropio = nuevo_cliente_de_prueba('Propio de la ajena');
insertar('dominios', [
    'dominio' => 'propio-ajena.com', 'proveedor' => '', 'fecha_vencimiento' => date('Y-m-d', strtotime('+100 days')), 'dias_anticipo' => 30,
    'costo_renovacion' => 0, 'moneda_costo' => 'ARS', 'precio_cliente' => 0, 'moneda_precio' => 'ARS', 'estado' => 'activo',
    'cliente_id' => $cliPropio, 'creado_en' => date('Y-m-d H:i:s'),
]);
$nav = new Navegador();
$nav->login($ajena['usuario'], $ajena['clave']);
$acciones = [
    'cliente_guardar' => ['id' => $cli, 'nombre' => 'Pisado', 'estado' => 'activo'],
    'servicio_guardar' => ['id' => $srv, 'cliente_id' => $cli, 'nombre' => 'Pisado', 'monto' => '1', 'moneda' => 'ARS', 'tipo_cobro' => 'mensual',
        'fecha_inicio' => date('Y-m-d'), 'estado' => 'activo', 'inicio_mensual' => 'mes_siguiente', 'dias_anticipo' => '30'],
    'dominio_guardar' => ['id' => $dom, 'cliente_id' => $cli, 'dominio' => 'pisado.com', 'fecha_vencimiento' => date('Y-m-d'), 'dias_anticipo' => '30',
        'costo_renovacion' => '1', 'moneda_costo' => 'ARS', 'precio_cliente' => '1', 'moneda_precio' => 'ARS', 'estado' => 'activo'],
    'dominio_renovar' => ['id' => $dom, 'vence' => date('Y-m-d', strtotime('+20 days'))],
    'cargo_anular' => ['id' => $cargoDuena],
    'pago_anular' => ['id' => $pago, 'motivo' => 'Anulado por otra cuenta'],
    'pago_guardar' => ['cliente_id' => $cli, 'monto' => '10', 'moneda' => 'ARS', 'fecha' => date('Y-m-d'), 'medio' => 'efectivo', 'modo' => 'auto', 'cotizacion' => '1000'],
    'plan_pagar' => ['plan_id' => $plan, 'cuotas' => [$cargoDuena], 'fecha' => date('Y-m-d'), 'medio' => 'efectivo'],
    'plan_cancelar' => ['plan_id' => $plan],
    'plan_guardar' => ['cliente_id' => $cli, 'concepto' => 'Pisado', 'monto_total' => '100', 'moneda' => 'ARS', 'cuotas' => '1', 'frecuencia' => 'mensual',
        'fecha_primera' => date('Y-m-d'), 'cuotas_det' => [['monto' => '100', 'fecha' => date('Y-m-d')]]],
    'aviso_renovacion_enviar' => ['tipo' => 'dominio', 'id' => $dom],
    'portal_generar' => ['id' => $cli],
    'mp_link' => ['id' => $cargoDuena],
    'servicio_eliminar' => ['id' => $srv],
    'dominio_eliminar' => ['id' => $dom],
    'cliente_eliminar' => ['id' => $cli],
];
foreach ($acciones as $accion => $datos) {
    $nav->get("/clientes/$cliPropio");     // nonce y csrf frescos, como si viniera de un formulario propio
    $r = $nav->post('/acciones/' . $accion, array_map(fn($v) => is_array($v) ? $v : (string) $v, $datos));
    verificar_cierto("$accion con ids de la dueña: no rompe (HTTP {$r['codigo']}) y no cambia nada", $r['codigo'] < 500 && $huella() === $huellaInicial);
}
// Control: con el mismo mecanismo, sobre lo SUYO, las acciones sí funcionan (csrf y nonce bien armados)
$domPropio = (int) con_usuario($ajena['id'], fn() => valor('SELECT id FROM dominios WHERE usuario_id = {U} AND cliente_id = ?', [$cliPropio]));
$venceAntes = (string) con_usuario($ajena['id'], fn() => valor('SELECT fecha_vencimiento FROM dominios WHERE id = ? AND usuario_id = {U}', [$domPropio]));
$nav->get("/clientes/$cliPropio");
$nav->post('/acciones/dominio_renovar', ['id' => (string) $domPropio, 'vence' => $venceAntes]);
verificar('control: renovar SU dominio sí lo renueva', date('Y-m-d', strtotime($venceAntes . ' +1 year')),
    con_usuario($ajena['id'], fn() => valor('SELECT fecha_vencimiento FROM dominios WHERE id = ? AND usuario_id = {U}', [$domPropio])));
$nav->get("/clientes/$cliPropio");
$nav->post('/acciones/cliente_guardar', ['id' => (string) $cliPropio, 'nombre' => 'Propio renombrado', 'estado' => 'activo']);
verificar('control: editar SU cliente sí lo cambia', 'Propio renombrado', con_usuario($ajena['id'], fn() => valor('SELECT nombre FROM clientes WHERE id = ? AND usuario_id = {U}', [$cliPropio])));

$nav->get("/clientes/$cliPropio");
$r = $nav->pedir('POST', '/acciones/cliente_eliminar', ['id' => (string) $cliPropio]);
verificar('acción sin token CSRF: 403', 403, $r['codigo']);
