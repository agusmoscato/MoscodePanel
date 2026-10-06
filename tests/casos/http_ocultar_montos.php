<?php
/**
 * http_ocultar_montos.php — El "ojito" (ocultar montos) por HTTP.
 *
 * El ocultado es central: monto_html() envuelve cada monto en <span class="monto">…<span class="val">…</span></span> y
 * el CSS esconde el .val cuando <html> tiene la clase montos-ocultos (la pone tema.js antes de pintar). Eso solo sirve
 * si NINGÚN monto se pinta por otro camino, así que acá una cuenta con datos de todo tipo (servicios mensuales,
 * anuales y por cantidad, dominios, cargos, pagos con saldo a favor y anulados, cuotas, historial de precios,
 * cotización, publicaciones) recorre cada pantalla y se buscan montos en el texto visible y en los title/aria-label que
 * NO estén dentro de un .monto. Quedan afuera, a propósito, los campos de formulario y sus ayudantes en vivo (cantidad ×
 * precio, suma de las cuotas: es lo que se está cargando), los
 * data-* (el texto del WhatsApp, los datos de los gráficos), los mensajes flash (app.js los muestra con los montos
 * envueltos) y el portal del cliente (no carga tema.js: el ojito no existe ahí).
 *
 * Lo que pasa en el navegador (la clase antes de pintar, el botón, "$ •••••", los gráficos) se verificó con Chrome;
 * acá se comprueban las piezas: los botones, el atributo de la opción de Mi cuenta, el CSS y el JS que las aplican.
 */
declare(strict_types=1);

seccion('cuenta con montos de todo tipo');
$u = nuevo_usuario_con_clave('ojito', 'Clave-Ojito-2026', 'admin');
guardar_cotizacion('blue', 1234.56, 'manual', true);
$cli = nuevo_cliente_de_prueba('Cliente Ojito');
$srv = insertar('servicios', [
    'cliente_id' => $cli, 'nombre' => 'Mantenimiento', 'descripcion' => '', 'monto' => 87654.32, 'moneda' => 'ARS',
    'tipo_cobro' => 'mensual', 'fecha_inicio' => date('Y-m-01', strtotime('-2 months')), 'estado' => 'activo', 'creado_en' => date('Y-m-d H:i:s'),
]);
insertar('servicios', [
    'cliente_id' => $cli, 'nombre' => 'Hosting anual', 'descripcion' => '', 'monto' => 120.5, 'moneda' => 'USD',
    'tipo_cobro' => 'anual', 'fecha_inicio' => date('Y-m-d', strtotime('-1 year +10 days')), 'proximo_vencimiento' => date('Y-m-d', strtotime('+10 days')),
    'estado' => 'activo', 'creado_en' => date('Y-m-d H:i:s'),
]);
insertar('servicios', [
    'cliente_id' => $cli, 'nombre' => 'Google Workspace', 'descripcion' => '', 'monto' => 21000, 'moneda' => 'ARS', 'tipo_cobro' => 'mensual',
    'fecha_inicio' => date('Y-m-01', strtotime('-1 month')), 'estado' => 'activo', 'por_cantidad' => 1, 'cantidad' => 3, 'unidad' => 'usuarios',
    'unidad_singular' => 'usuario', 'precio_unidad' => 7000, 'creado_en' => date('Y-m-d H:i:s'),
]);
$dom = insertar('dominios', [
    'dominio' => 'ojito-prueba.com.ar', 'proveedor' => 'NIC.ar', 'fecha_vencimiento' => date('Y-m-d', strtotime('+12 days')), 'dias_anticipo' => 30,
    'costo_renovacion' => 4500, 'moneda_costo' => 'ARS', 'precio_cliente' => 9800.75, 'moneda_precio' => 'ARS', 'estado' => 'activo',
    'cliente_id' => $cli, 'creado_en' => date('Y-m-d H:i:s'),
]);
insertar('servicios_precios_hist', ['servicio_id' => $srv, 'monto_anterior' => 80000, 'monto_nuevo' => 87654.32, 'porcentaje' => 9.57,
    'motivo' => 'Ajuste', 'fecha' => date('Y-m-d H:i:s')]);
generar_cargos(date('Y-m'));
$pago = registrar_pago($cli, date('Y-m-d'), 50000, 'ARS', 'transferencia', 'Pago parcial', 1234.56);
$pagoFavor = registrar_pago($cli, date('Y-m-d'), 999999.99, 'ARS', 'efectivo', 'Pago de más (saldo a favor)', 1234.56);
$pagoAnulado = registrar_pago($cli, date('Y-m-d'), 3333.33, 'ARS', 'efectivo', 'Para anular', 1234.56);
anular_pago($pagoAnulado, 'Prueba del ojito', $u['id']);
$cli2 = nuevo_cliente_de_prueba('Cliente con deuda');
insertar('servicios', [
    'cliente_id' => $cli2, 'nombre' => 'Soporte', 'descripcion' => '', 'monto' => 15500, 'moneda' => 'ARS', 'tipo_cobro' => 'mensual',
    'fecha_inicio' => date('Y-m-01', strtotime('-3 months')), 'estado' => 'activo', 'creado_en' => date('Y-m-d H:i:s'),
]);
generar_cargos(date('Y-m'));
$plan = crear_plan($cli2, ['concepto' => 'Web en cuotas', 'descripcion' => '', 'monto_total' => 300000, 'moneda' => 'ARS', 'cuotas' => 3,
    'frecuencia' => 'mensual', 'notas' => ''], cuotas_por_defecto(300000, 3, date('Y-m-d', strtotime('+5 days')), 'mensual'));
$pub = insertar('publicaciones', ['fecha' => date('Y-m-d'), 'tipo' => 'Post', 'estado' => 'listo', 'titulo' => 'Promo', 'copy_texto' => 'Precio especial',
    'creado_en' => date('Y-m-d H:i:s')]);
verificar_cierto('hay cargos, pagos (uno anulado), cuotas y servicios', $pago && $pagoFavor && $plan && $dom);

/**
 * Montos fuera de un .monto en una página: en el texto visible y en title/aria-label. Devuelve los fragmentos
 * encontrados (lista vacía = todo bien). No mira campos de formulario, scripts, data-* ni los mensajes flash.
 */
$montosSueltos = function (string $html): array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    // Los ayudantes en vivo de un formulario (cantidad × precio, suma de las cuotas) son parte de lo que se está cargando
    foreach ($xp->query('//script|//style|//template|//textarea|//input|//select|//noscript|//*[@id="flash-data"]|//*[@data-calc-resultado]|//*[@data-suma-estado]|//*[@data-monto-publico]') as $n) {
        $n->parentNode->removeChild($n);
    }
    $dentroDeMonto = 'ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " monto ")]';
    $sueltos = [];
    foreach ($xp->query('//body//text()[not(' . $dentroDeMonto . ')]') as $t) {
        if (preg_match(PATRON_MONTO_TEXTO, $t->nodeValue, $m)) {
            $sueltos[] = trim($m[0]) . ' en «' . mb_substr(trim(preg_replace('/\s+/', ' ', $t->nodeValue)), 0, 70) . '»';
        }
    }
    foreach ($xp->query('//body//@title|//body//@aria-label') as $a) {
        if (preg_match(PATRON_MONTO_TEXTO, $a->value, $m)) {
            $sueltos[] = trim($m[0]) . ' en ' . $a->name . '="' . mb_substr($a->value, 0, 70) . '"';
        }
    }
    return $sueltos;
};

seccion('control: el detector encuentra un monto suelto (si no, "no hay sueltos" no probaría nada)');
verificar('detecta "$ 1.234,56" suelto', 1, count($montosSueltos('<body><p>Total $ 1.234,56</p></body>')));
verificar('detecta un número con formato de plata sin símbolo', 1, count($montosSueltos('<body><p>Suma (1.234,56)</p></body>')));
verificar('detecta un monto en un title', 1, count($montosSueltos('<body><a title="Debe $ 500">x</a></body>')));
verificar('un monto_html() no cuenta como suelto', [], $montosSueltos('<body><p>Total ' . monto_html(1234.56) . ' y ' . monto_html(5, 'USD') . '</p></body>'));
verificar('un porcentaje no es un monto', [], $montosSueltos('<body><p>Ajuste del 10,00% y 12,5 %</p></body>'));
verificar('un campo de formulario no se mira', [], $montosSueltos('<body><input value="1.234,56"><textarea>$ 10</textarea></body>'));

seccion('monto_html(): la estructura que oculta el CSS');
verificar('ARS', '<span class="monto mono "><span class="mon">$</span> <span class="val">1.234,56</span></span>', monto_html(1234.56));
verificar('USD', '<span class="monto mono  usd"><span class="mon">US$</span> <span class="val">40,00</span></span>', monto_html(40, 'USD'));
verificar('montos_en_texto_html(): envuelve los montos de un texto libre y escapa el resto',
    'Pago de <span class="monto"><span class="mon">$</span> <span class="val">1.000</span></span>. Suma (<span class="monto monto-solo"><span class="val">2.000,50</span></span>) &lt;b&gt; 10,00%',
    montos_en_texto_html('Pago de $ 1.000. Suma (2.000,50) <b> 10,00%'));

seccion('ningún monto fuera de monto_html() en las pantallas');
$nav = new Navegador();
$nav->login($u['usuario'], $u['clave']);
$pantallas = ['/', '/clientes', '/clientes?estado=todos', '/clientes?deuda=1', '/cobros', '/cobros?estado=todos', '/vencimientos', '/cuotas',
    '/cuotas?estado=todos', '/precios', "/precios?previsualizar=1&clientes[]=$cli&clientes[]=$cli2&porcentaje=10&redondeo=ninguno", '/reportes',
    '/reportes?anio=' . date('Y'), '/dolar', '/configuracion', '/mi-cuenta', '/notificaciones', '/actividad', '/usuarios', '/backups', '/feriados',
    '/elegir-cliente?para=pago', '/redes', '/redes?vista=lista', "/redes/$pub", '/servicios', '/servicios?estado=', '/dominios', '/dominios?estado=',
    "/clientes/$cli", "/clientes/$cli/resumen", "/clientes/$cli2", "/clientes/$cli2/resumen", "/clientes/$cli/editar", "/servicios/$srv/editar",
    "/dominios/$dom/editar", "/cuotas/$plan", "/cuotas/$plan/editar", "/clientes/$cli2/cuotas/nueva", "/pagos/nuevo?cliente_id=$cli2",
    "/pagos/$pago/anular"];
foreach ($pantallas as $ruta) {
    $r = $nav->get($ruta);
    if ($r['codigo'] !== 200) {
        verificar("$ruta responde 200", 200, $r['codigo']);
        continue;
    }
    verificar("$ruta: todos los montos dentro de un .monto", [], $montosSueltos($r['cuerpo']));
}
$dash = $nav->get('/')['cuerpo'];
verificar_cierto('control: el dashboard sí tiene montos (dentro de .monto)', substr_count($dash, '<span class="val">') > 3);
verificar_contiene('control: el saldo a favor se ve en la ficha (como monto)', '<span class="val">' . number_format(pago_sin_imputar($pagoFavor), 2, ',', '.') . '</span>', $nav->get("/clientes/$cli")['cuerpo']);

seccion('el botón del ojo aparece si y solo si la pantalla tiene algún .monto (decidido solo, sin lista a mano)');
$hayMonto = fn(string $h) => (bool) preg_match('/class="monto[\s"]/', $h);
$hayOjito = fn(string $h) => str_contains($h, 'data-montos-toggle');
$conYsin = ['con' => 0, 'sin' => 0];
$recorridas = array_merge($pantallas, ["/redes/nueva", "/redes/$pub/editar", '/mi-cuenta/dos-pasos', '/usuarios/nuevo', '/clientes/nuevo',
    "/clientes/$cli/servicios/nuevo", "/clientes/$cli/dominios/nuevo", '/cambiar-clave', '/no-existe']);
// Cobertura: entre las rutas recorridas está cada vista del panel (una pantalla nueva que no se agregue acá hace fallar esto)
$vistasRecorridas = [];
foreach ($recorridas as $ruta) {
    $res = resolver_ruta('GET', (string) parse_url($ruta, PHP_URL_PATH), []);
    if (($res['tipo'] ?? '') === 'vista') {
        $vistasRecorridas[$res['nombre']] = true;
    }
}
verificar('se recorren TODAS las vistas del panel (las que faltan)', [], array_values(array_diff(array_keys(RUTAS_VISTAS), array_keys($vistasRecorridas))));
foreach ($recorridas as $ruta) {
    $h = $nav->get($ruta)['cuerpo'];
    $conYsin[$hayMonto($h) ? 'con' : 'sin']++;
    verificar("$ruta: " . ($hayMonto($h) ? 'tiene montos → con ojito' : 'sin montos → sin ojito'), $hayMonto($h), $hayOjito($h));
}
verificar_cierto('control: se recorrieron pantallas con y sin montos (' . $conYsin['con'] . ' con, ' . $conYsin['sin'] . ' sin)', $conYsin['con'] >= 10 && $conYsin['sin'] >= 8);
foreach (['/' => true, "/clientes/$cli" => true, '/cobros' => true, '/cuotas' => true, '/reportes' => true, "/precios?previsualizar=1&clientes[]=$cli&porcentaje=10&redondeo=ninguno" => true, '/dolar' => true,
    '/servicios' => true, '/dominios' => true, '/redes' => false, '/configuracion' => false, '/usuarios' => false, '/feriados' => false, '/backups' => false] as $ruta => $esperado) {
    verificar("$ruta: " . ($esperado ? 'con' : 'sin') . ' ojito', $esperado, $hayOjito($nav->get($ruta)['cuerpo']));
}
$fresca = nuevo_usuario_con_clave('ojito_fresca', 'Clave-Ojito-2026');
$navFresca = new Navegador();
$navFresca->login($fresca['usuario'], $fresca['clave']);
verificar('Mi cuenta (sin montos en su actividad): sin ojito', false, $hayOjito($navFresca->get('/mi-cuenta')['cuerpo']));
fijar_usuario($u['id']);

seccion('la cotización del dólar del sidebar y la topbar: dato público, siempre visible (no es un .monto)');
$redes = $nav->get('/redes')['cuerpo'];
verificar_contiene('sidebar: la cotización se ve, marcada como pública', '<span class="cotiz-val" data-monto-publico>$ 1.234,56</span>', $redes);
verificar_contiene('topbar: ídem', '<span class="cotiz-val" data-monto-publico>1.235</span>', $redes);
verificar('… y no es un .monto (no la oculta el ojito ni hace aparecer el botón)', false, $hayMonto($redes));
verificar_contiene('el estado del ojito se mantiene en todas las pantallas: tema.js lo aplica aunque no haya botón', '/assets/js/tema.js', $redes);

seccion('el botón del ojo: en la topbar (celular) y arriba del contenido (escritorio)');
verificar_contiene('ojito en la topbar', 'ojito ojito-topbar" data-montos-toggle aria-pressed="false" aria-label="Ocultar montos"', $dash);
verificar_contiene('ojito en la barra de escritorio', '<div class="barra-escritorio"><button type="button" class="btn fantasma icono ojito ojito-escritorio" data-montos-toggle', $dash);
verificar_contiene('con los dos íconos (ojo y ojo tachado)', '#eye"></use></svg><svg class="ico ojo-cerrado"', $dash);
$sprite = (string) file_get_contents(RAIZ_PROYECTO . '/public_html/assets/img/iconos.svg');
verificar_cierto('los íconos eye y eye-off están en el sprite', str_contains($sprite, '<symbol id="eye"') && str_contains($sprite, '<symbol id="eye-off"'));
verificar_contiene('tema.js se carga en el <head> sin defer (antes de pintar)', '/assets/js/tema.js?v=' . APP_VERSION . '"></script>', $dash);
verificar_cierto('… y antes del <body>', strpos($dash, 'tema.js') < strpos($dash, '<body'));

seccion('Mi cuenta: "Ocultar montos al abrir la app"');
verificar_contiene('por defecto apagado: data-montos-al-abrir="0"', 'data-montos-al-abrir="0"', $dash);
$mc = $nav->get('/mi-cuenta')['cuerpo'];
verificar_contiene('la opción está en Mi cuenta', 'name="montos_ocultos_al_abrir" value="1">Ocultar montos al abrir la app', $mc);
$r = $nav->post('/acciones/privacidad_guardar', ['montos_ocultos_al_abrir' => '1']);
verificar('guardar vuelve a Mi cuenta', true, redirige_a($r, '/mi-cuenta'));
verificar_contiene('activada: data-montos-al-abrir="1" en todas las pantallas', 'data-montos-al-abrir="1"', $nav->get('/cobros')['cuerpo']);
verificar_contiene('y la casilla aparece marcada', 'value="1" checked>Ocultar montos al abrir la app', $nav->get('/mi-cuenta')['cuerpo']);
$otra = nuevo_usuario_con_clave('ojito_otra', 'Clave-Ojito-2026');
$navOtra = new Navegador();
$navOtra->login($otra['usuario'], $otra['clave']);
verificar_contiene('es por usuario: a otra cuenta no le cambia', 'data-montos-al-abrir="0"', $navOtra->get('/')['cuerpo']);
$nav->get('/mi-cuenta');
$nav->post('/acciones/privacidad_guardar', []);
verificar_contiene('desactivada de nuevo', 'data-montos-al-abrir="0"', $nav->get('/')['cuerpo']);

seccion('lo que NO se oculta: impresión, formularios y portal del cliente');
$css = (string) file_get_contents(RAIZ_PROYECTO . '/public_html/assets/css/app.css');
verificar_cierto('el CSS oculta el .val de cada .monto solo en pantalla (al imprimir se ven)',
    (bool) preg_match('/@media screen \{\s*\.montos-ocultos \.monto > \.val \{ display: none; \}\s*\.montos-ocultos \.monto::after \{ content: "•••••";/u', $css));
verificar('ninguna otra regla del CSS oculta montos fuera de @media screen', 1, substr_count($css, '.montos-ocultos .monto > .val'));
$form = $nav->get("/pagos/nuevo?cliente_id=$cli2")['cuerpo'];
verificar_cierto('los campos de monto de un formulario son inputs (no .monto): se ven mientras se cargan', (bool) preg_match('/<input[^>]+name="monto"/', $form));
$tema = (string) file_get_contents(RAIZ_PROYECTO . '/public_html/assets/js/tema.js');
verificar_cierto('tema.js: aplica la clase desde localStorage antes de pintar', str_contains($tema, "leer('montos-ocultos') === '1'") && str_contains($tema, "raiz.classList.add('montos-ocultos')"));
verificar_cierto('tema.js: "al abrir la app" fuerza oculto en una apertura nueva (sessionStorage)', str_contains($tema, "alAbrir === '1' && apertura"));
fijar_usuario($u['id']);
cfg_set('portal_activo', '1');
$token = bin2hex(random_bytes(24));
q('UPDATE clientes SET portal_token = ? WHERE id = ? AND usuario_id = {U}', [$token, $cli2]);
$portal = (new Navegador())->get('/portal/' . $token);
verificar('el portal responde', 200, $portal['codigo']);
verificar_cierto('control: el portal muestra montos', str_contains($portal['cuerpo'], '<span class="val">'));
verificar('el portal no carga tema.js (el ojito no existe ahí: siempre se ven los montos)', false, str_contains($portal['cuerpo'], 'tema.js'));
verificar('el portal no tiene el botón del ojo', false, str_contains($portal['cuerpo'], 'data-montos-toggle'));

seccion('CSP: sin estilos ni scripts en línea en las pantallas con el ojito');
foreach (['/', '/reportes', '/mi-cuenta', "/clientes/$cli"] as $ruta) {
    $h = $nav->get($ruta)['cuerpo'];
    verificar("$ruta: sin style= ni <script> en línea", false,
        (bool) preg_match('~<[a-z][^>]*\sstyle\s*=~i', $h) || (bool) preg_match('~<script\b(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>~i', $h));
}
