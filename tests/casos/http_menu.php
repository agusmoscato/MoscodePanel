<?php
/**
 * http_menu.php — El menú del sidebar (escritorio y drawer del celular, es el mismo HTML) agrupado en tres secciones
 * desplegables: Principal, Gestión y Sistema. Qué ítem va en cada una, cuál arranca abierta (Principal siempre; la que
 * tiene la pantalla actual, también), la accesibilidad del encabezado (un botón con aria-expanded y aria-controls) y
 * que la barra inferior del celular no cambió. La animación y el modo colapsado a íconos son CSS/JS: se verifican
 * las reglas que los aplican.
 */
declare(strict_types=1);

/** Secciones del sidebar de una página: id => [nombre, abierta (clase), aria-expanded, aria-controls, [hrefs]]. */
$secciones = function (string $html): array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $res = [];
    foreach ($xp->query('//aside[@id="sidebar"]//div[contains(concat(" ", @class, " "), " sb-grupo ")]') as $g) {
        $btn = $xp->query('.//button[@data-sb-grupo]', $g)->item(0);
        $items = $xp->query('.//div[contains(@class, "sb-items")]', $g)->item(0);
        $hrefs = [];
        foreach ($xp->query('.//a[contains(@class, "nav-item")]', $g) as $a) {
            $hrefs[] = $a->getAttribute('href');
        }
        $res[$items ? $items->getAttribute('id') : '?'] = [
            'nombre' => $btn ? trim($btn->textContent) : null,
            'abierta' => str_contains(' ' . $g->getAttribute('class') . ' ', ' abierto '),
            'expanded' => $btn ? $btn->getAttribute('aria-expanded') : null,
            'controls' => $btn ? $btn->getAttribute('aria-controls') : null,
            'hrefs' => $hrefs,
        ];
    }
    return $res;
};

seccion('qué va en cada sección');
$admin = nuevo_usuario_con_clave('menu_admin', 'Clave-Menu-2026', 'admin');
$nav = new Navegador();
$nav->login($admin['usuario'], $admin['clave']);
$s = $secciones($nav->get('/')['cuerpo']);
verificar('tres secciones, en orden', ['sb-grupo-principal', 'sb-grupo-gestion', 'sb-grupo-sistema'], array_keys($s));
verificar('nombres', ['Principal', 'Gestión', 'Sistema'], array_column($s, 'nombre'));
verificar('Principal: Inicio, Clientes, Cobros, Vencimientos y Redes', ['/', '/clientes', '/cobros', '/vencimientos', '/redes'], $s['sb-grupo-principal']['hrefs']);
verificar('Gestión: Cuotas, Servicios, Dominios, Ajuste de precios, Reportes y Dólar', ['/cuotas', '/servicios', '/dominios', '/precios', '/reportes', '/dolar'], $s['sb-grupo-gestion']['hrefs']);
verificar('Sistema (admin): Configuración, Mi cuenta, Usuarios, Feriados, Notificaciones (y webhooks), Registro de actividad y Backups',
    ['/configuracion', '/mi-cuenta', '/usuarios', '/feriados', '/notificaciones', '/actividad', '/backups'], $s['sb-grupo-sistema']['hrefs']);
$dash = $nav->get('/')['cuerpo'];
verificar_contiene('"Ajuste de precios" con su nombre', '<span class="nav-txt">Ajuste de precios</span>', $dash);
verificar_contiene('el log de webhooks está en Notificaciones (lo dice el tooltip)', 'href="/notificaciones" title="Notificaciones enviadas y log de webhooks"', $dash);
verificar_contiene('"Registro de actividad"', '<span class="nav-txt">Registro de actividad</span>', $dash);

$comun = nuevo_usuario_con_clave('menu_comun', 'Clave-Menu-2026');
$navComun = new Navegador();
$navComun->login($comun['usuario'], $comun['clave']);
verificar('Sistema (usuario común): sin Usuarios, Registro de actividad ni Backups (son solo de admin)',
    ['/configuracion', '/mi-cuenta', '/feriados', '/notificaciones'], $secciones($navComun->get('/')['cuerpo'])['sb-grupo-sistema']['hrefs']);
fijar_usuario($admin['id']);

seccion('las pantallas nuevas de Servicios y Dominios (destino del menú)');
foreach (['/servicios', '/servicios?estado=', '/dominios', '/dominios?estado=baja'] as $ruta) {
    verificar("$ruta responde 200", 200, $nav->get($ruta)['codigo']);
}
$cli = nuevo_cliente_de_prueba('Cliente del menú');
insertar('servicios', ['cliente_id' => $cli, 'nombre' => 'Hosting del menú', 'descripcion' => '', 'monto' => 4321, 'moneda' => 'ARS', 'tipo_cobro' => 'mensual',
    'fecha_inicio' => date('Y-m-01'), 'estado' => 'activo', 'creado_en' => date('Y-m-d H:i:s')]);
insertar('dominios', ['cliente_id' => $cli, 'dominio' => 'menu-prueba.com.ar', 'fecha_vencimiento' => date('Y-m-d', strtotime('+20 days')),
    'precio_cliente' => 9000, 'estado' => 'activo', 'creado_en' => date('Y-m-d H:i:s')]);
$srvPag = $nav->get('/servicios')['cuerpo'];
verificar_contiene('Servicios: el servicio con su cliente', 'Hosting del menú <span class="suave">· Cliente del menú</span>', $srvPag);
verificar_contiene('Servicios: su monto (con monto_html, lo oculta el ojito)', '<span class="val">4.321,00</span>', $srvPag);
$domPag = $nav->get('/dominios')['cuerpo'];
verificar_contiene('Dominios: el dominio con su cliente', 'menu-prueba.com.ar</span> <span class="suave">· Cliente del menú</span>', $domPag);
verificar('Dominios de baja: no aparece uno activo', false, str_contains($nav->get('/dominios?estado=baja')['cuerpo'], 'menu-prueba.com.ar'));
verificar('otra cuenta no ve los servicios ni los dominios', [false, false],
    [str_contains($navComun->get('/servicios?estado=')['cuerpo'], 'Hosting del menú'), str_contains($navComun->get('/dominios?estado=')['cuerpo'], 'menu-prueba')]);
fijar_usuario($admin['id']);

seccion('cuál arranca desplegada: Principal siempre; Gestión y Sistema cerradas, salvo que la pantalla esté adentro');
$abiertas = fn(string $ruta) => array_keys(array_filter($secciones($nav->get($ruta)['cuerpo']), fn($g) => $g['abierta']));
$idPlan = crear_plan($cli, ['concepto' => 'Menú en cuotas', 'descripcion' => '', 'monto_total' => 3000, 'moneda' => 'ARS', 'cuotas' => 3, 'frecuencia' => 'mensual', 'notas' => ''],
    cuotas_por_defecto(3000, 3, date('Y-m-d', strtotime('+5 days')), 'mensual'));
foreach ([
    '/' => ['sb-grupo-principal'], '/clientes' => ['sb-grupo-principal'], "/clientes/$cli" => ['sb-grupo-principal'], '/redes' => ['sb-grupo-principal'],
    '/redes/nueva' => ['sb-grupo-principal'], '/elegir-cliente?para=pago' => ['sb-grupo-principal'],
    '/cuotas' => ['sb-grupo-principal', 'sb-grupo-gestion'], "/cuotas/$idPlan" => ['sb-grupo-principal', 'sb-grupo-gestion'],
    '/servicios' => ['sb-grupo-principal', 'sb-grupo-gestion'], '/dominios' => ['sb-grupo-principal', 'sb-grupo-gestion'],
    '/precios' => ['sb-grupo-principal', 'sb-grupo-gestion'], '/reportes' => ['sb-grupo-principal', 'sb-grupo-gestion'], '/dolar' => ['sb-grupo-principal', 'sb-grupo-gestion'],
    '/configuracion' => ['sb-grupo-principal', 'sb-grupo-sistema'], '/mi-cuenta/dos-pasos' => ['sb-grupo-principal', 'sb-grupo-sistema'],
    '/usuarios/nuevo' => ['sb-grupo-principal', 'sb-grupo-sistema'], '/notificaciones' => ['sb-grupo-principal', 'sb-grupo-sistema'],
    '/actividad' => ['sb-grupo-principal', 'sb-grupo-sistema'], '/feriados' => ['sb-grupo-principal', 'sb-grupo-sistema'],
] as $ruta => $esperado) {
    verificar("$ruta: abiertas", $esperado, $abiertas($ruta));
}
$s = $secciones($nav->get('/reportes')['cuerpo']);
verificar_contiene('el ítem de la pantalla actual queda resaltado', 'class="nav-item activo" href="/reportes" title="Reportes" aria-current="page"', $nav->get('/reportes')['cuerpo']);

seccion('accesible: el encabezado es un <button> con aria-expanded y aria-controls que apunta a su lista');
foreach ($s as $id => $g) {
    verificar("$id: aria-controls apunta a su lista", $id, $g['controls']);
    verificar("$id: aria-expanded coincide con si está abierta", $g['abierta'] ? 'true' : 'false', $g['expanded']);
}
verificar_contiene('el encabezado es un botón (anda con Enter y Espacio)', '<button type="button" class="sb-titulo" data-sb-grupo aria-expanded="false" aria-controls="sb-grupo-sistema">', $nav->get('/reportes')['cuerpo']);

seccion('CSS y JS (sin nada inline: CSP)');
$css = (string) file_get_contents(RAIZ_PROYECTO . '/public_html/assets/css/app.css');
$js = (string) file_get_contents(RAIZ_PROYECTO . '/public_html/assets/js/app.js');
$tema = (string) file_get_contents(RAIZ_PROYECTO . '/public_html/assets/js/tema.js');
verificar_cierto('transición suave al desplegar (grid-template-rows)', str_contains($css, 'transition: grid-template-rows .25s'));
verificar_cierto('plegadas solo con JS: tema.js marca html.js antes de pintar (sin JS se ve todo)', str_contains($tema, "raiz.classList.add('js')") && str_contains($css, '.js .sb-items { grid-template-rows: 0fr; visibility: hidden;'));
verificar_cierto('plegada: visibility hidden (sus links no reciben el foco con Tab)', str_contains($css, '.js .sb-grupo.abierto .sb-items { grid-template-rows: 1fr; visibility: visible; }'));
verificar_cierto('colapsado a íconos: sin acordeón, todo a la vista y una línea entre secciones',
    str_contains($css, 'html.sidebar-min .sb-items { grid-template-rows: 1fr; visibility: visible; transition: none; }')
    && str_contains($css, 'html.sidebar-min .sb-grupo + .sb-grupo { margin-top: var(--s2); padding-top: var(--s2); border-top: 1px solid var(--border); }')
    && str_contains($css, 'html.sidebar-min .nav-txt, html.sidebar-min .sb-titulo'));
verificar_cierto('colapsado: el nombre queda en el tooltip (title de cada ítem)', (bool) preg_match('/class="nav-item" href="\/servicios" title="Servicios"/', $dash));
verificar_cierto('app.js alterna la clase y aria-expanded', str_contains($js, "cabGrupo.closest('.sb-grupo').classList.toggle('abierto')") && str_contains($js, "cabGrupo.setAttribute('aria-expanded'"));
verificar('el sidebar no trae estilos ni scripts inline', 0, preg_match_all('/<aside class="sidebar".*?<\/aside>/s', $dash, $m) ? preg_match_all('/ style=|<script|onclick=/', $m[0][0]) : -1);

seccion('la barra inferior del celular no cambia');
verificar_cierto('Inicio, Clientes, Cobros, Vencim. y Más', (bool) preg_match('~<nav class="bottomnav".*?href="/".*?Inicio.*?href="/clientes".*?Clientes.*?href="/cobros".*?Cobros.*?href="/vencimientos".*?Vencim\..*?data-abrir="drawer".*?Más.*?</nav>~s', $dash));
