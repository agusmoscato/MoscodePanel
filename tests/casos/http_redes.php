<?php
/**
 * http_redes.php — Módulo Redes por HTTP, como un navegador: crear desde el formulario, las pantallas (calendario,
 * lista, publicación, formulario, dashboard) con los emojis del copy intactos en el HTML y en el JSON que usa el
 * bottom sheet, nada que la CSP bloquee, el sidebar y el botón "+", y el aislamiento entre cuentas por URL y acciones.
 */
declare(strict_types=1);

$COPY = "Mito vs Realidad 🤔\n\n❌ \"Postear todos los días alcanza\"\n✅ Sin estrategia no hay resultados 📈\n\n👩‍💻👍🏽 🇦🇷 <b>no es HTML</b> & \"comillas\"";
$MARCA = 'ZZREDES' . strtoupper(bin2hex(random_bytes(3)));
$hoy = date('Y-m-d');
$en2 = date('Y-m-d', strtotime('+2 days'));

/** Lo que la CSP del panel bloquearía (igual que en http_cabeceras.php). */
$violacionesCsp = function (string $html): array {
    $p = [];
    if (preg_match('~<script\b(?![^>]*\bsrc=)(?![^>]*type="application/(?:ld\+)?json")[^>]*>~i', $html, $m)) {
        $p[] = 'script en línea: ' . $m[0];
    }
    if (preg_match('~<[a-z][^>]*\sstyle\s*=~i', $html, $m)) {
        $p[] = 'atributo style: ' . mb_substr($m[0], 0, 80);
    }
    if (preg_match('~<[a-z][^>]*\son[a-z]+\s*=~i', $html, $m)) {
        $p[] = 'manejador en línea: ' . mb_substr($m[0], 0, 80);
    }
    if (stripos($html, 'javascript:') !== false) {
        $p[] = 'URL javascript:';
    }
    return $p;
};

seccion('crear una publicación desde el formulario (POST con csrf, como el bottom sheet)');
$duena = nuevo_usuario_con_clave('redes_http', 'Clave-Redes-2026');
$nav = new Navegador();
$nav->login($duena['usuario'], $duena['clave']);
$r = $nav->get('/redes');
verificar('/redes responde 200', 200, $r['codigo']);
verificar_contiene('el calendario arranca vacío', 'Sin publicaciones', $r['cuerpo']);
$r = $nav->post('/acciones/publicacion_guardar', [
    'id' => '0', 'fecha' => $en2, 'hora' => '19:00', 'tipo' => 'Reel', 'estado' => 'listo',
    'titulo' => $MARCA . ' Post2 - Mito vs Realidad 🔥', 'copy_texto' => $COPY, 'notas' => 'Notas ' . $MARCA, 'link' => 'https://drive.google.com/x',
    'redes' => ['Instagram', 'TikTok'],
]);
verificar('guardar redirige al calendario, en el mes y el día de la publicación', true, redirige_a($r, '/redes') && str_contains($r['location'], 'dia=' . $en2));
fijar_usuario($duena['id']);
$id = (int) valor('SELECT id FROM publicaciones WHERE usuario_id = {U} ORDER BY id DESC LIMIT 1');
verificar_cierto('quedó guardada', $id > 0);
verificar('el copy llegó idéntico por HTTP (emojis, comillas, <, &)', $COPY, valor('SELECT copy_texto FROM publicaciones WHERE id = ? AND usuario_id = {U}', [$id]));

seccion('el calendario muestra la publicación en su día, con tipo, título y color del estado');
$cal = $nav->get('/redes?mes=' . substr($en2, 0, 7) . '&dia=' . $en2)['cuerpo'];
verificar_contiene('título con emoji en el calendario', e($MARCA . ' Post2 - Mito vs Realidad 🔥'), $cal);
verificar_contiene('link a la publicación', 'href="/redes/' . $id . '"', $cal);
verificar_contiene('color del estado (Listo)', 'cal-pub est-listo', $cal);
verificar_contiene('el tipo', '<span class="cal-pub-tipo">Reel</span>', $cal);
verificar_contiene('la celda del día', 'data-fecha="' . $en2 . '"', $cal);
verificar_contiene('lista del día elegido (la que se ve debajo en el celular)', 'data-dia-panel="' . $en2 . '"', $cal);
verificar_contiene('cada día tiene su "+" para agregar en esa fecha (abre el sheet; sin JS, la pantalla completa)', 'href="/redes/nueva?fecha=' . $en2 . '" data-abrir="publicacion" data-fecha="' . $en2 . '"', $cal);
verificar_contiene('semana de lunes a domingo', '<span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span>', $cal);
verificar_contiene('botón Hoy', '>Hoy</a>', $cal);
verificar_contiene('el bottom sheet del formulario está en la página', 'id="sheet-publicacion"', $cal);
$mesAnt = date('Y-m', strtotime(substr($en2, 0, 7) . '-01 -1 month'));
verificar_contiene('flecha al mes anterior', 'href="/redes?mes=' . $mesAnt . '"', $cal);
$otroMes = $nav->get('/redes?mes=2000-01')['cuerpo'];
verificar('en otro mes no aparece', false, str_contains($otroMes, $MARCA));
verificar('un mes inválido no rompe (cae en el actual)', 200, $nav->get('/redes?mes=2026-99&dia=xx')['codigo']);

seccion('vista Lista con filtros por tipo y estado');
$lista = $nav->get('/redes?vista=lista')['cuerpo'];
verificar_contiene('lista: aparece la próxima', $MARCA, $lista);
verificar_contiene('lista: filtro por tipo', 'href="/redes?vista=lista&amp;tipo=Reel"', $lista);
verificar_contiene('lista: filtro por estado', 'href="/redes?vista=lista&amp;estado=listo"', $lista);
verificar_contiene('filtro Reel: aparece', $MARCA, $nav->get('/redes?vista=lista&tipo=Reel')['cuerpo']);
verificar('filtro Post: no aparece', false, str_contains($nav->get('/redes?vista=lista&tipo=Post')['cuerpo'], $MARCA));
verificar_contiene('filtro Listo: aparece', $MARCA, $nav->get('/redes?vista=lista&estado=listo')['cuerpo']);
verificar('filtro Idea: no aparece', false, str_contains($nav->get('/redes?vista=lista&estado=idea')['cuerpo'], $MARCA));

seccion('la publicación: copy con "Copiar copy" y su contador, redes, estado con un toque');
$pag = $nav->get('/redes/' . $id)['cuerpo'];
verificar_contiene('el copy se ve escapado y con sus emojis', e($COPY), $pag);
verificar_contiene('botón "Copiar copy" con el copy entero en data-copiar', 'data-copiar="' . e($COPY) . '"', $pag);
verificar_contiene('cuatro botones de estado (un toque = un POST)', 'name="estado" value="publicado"', $pag);
verificar_contiene('el estado actual está marcado', 'pub-estado est-listo actual" aria-pressed="true"', $pag);
verificar('ya no hay "Duplicar"', false, str_contains($pag, 'publicacion_duplicar') || str_contains($pag, 'Duplicar'));
verificar_contiene('contador del copy (caracteres y hashtags)', '/ 2.200 caracteres · 0 hashtags', $pag);
verificar_contiene('las redes, con su ícono', '#red-instagram"></use></svg></i>Instagram</span>', $pag);
verificar_contiene('notas internas', 'Notas ' . $MARCA, $pag);
verificar_contiene('link con rel noopener', 'href="https://drive.google.com/x" target="_blank" rel="noopener noreferrer"', $pag);
verificar('el HTML escapa el copy: no aparece <b> sin escapar', false, str_contains($pag, '<b>no es HTML</b>'));
// El JSON del formulario de edición (lo lee app.js para el bottom sheet) trae los datos exactos
preg_match('~<script type="application/json" id="pub-datos">(.*?)</script>~s', $pag, $m);
$json = json_decode($m[1] ?? '', true);
verificar('JSON de edición: copy idéntico (emojis incluidos)', $COPY, $json['copy_texto'] ?? null);
verificar('JSON de edición: hora corta y tipo', ['19:00', 'Reel', $id], [$json['hora'] ?? null, $json['tipo'] ?? null, $json['id'] ?? null]);
verificar('JSON de edición: no se puede cerrar el <script> desde el copy', false, str_contains($m[1] ?? '', '</'));

$nav->get('/redes/' . $id);
$r = $nav->post('/acciones/publicacion_estado', ['id' => (string) $id, 'estado' => 'publicado']);
verificar('cambiar estado vuelve a la publicación', true, redirige_a($r, '/redes/' . $id));
verificar('estado cambiado a Publicado', 'publicado', valor('SELECT estado FROM publicaciones WHERE id = ? AND usuario_id = {U}', [$id]));
verificar('Publicado sin link de la publicación: no hay botón "Ver la publicación"', false, str_contains($nav->get('/redes/' . $id)['cuerpo'], 'Ver la publicación'));
$r = $nav->post('/acciones/publicacion_guardar', [
    'id' => (string) $id, 'fecha' => $en2, 'hora' => '19:00', 'tipo' => 'Reel', 'estado' => 'publicado', 'titulo' => $MARCA . ' Post2 - Mito vs Realidad 🔥',
    'copy_texto' => $COPY, 'notas' => 'Notas ' . $MARCA, 'link' => 'https://drive.google.com/x', 'redes' => ['Instagram', 'TikTok'],
    'link_publicado' => 'https://www.instagram.com/p/xyz/',
]);
verificar_contiene('con link de la publicación: botón "Ver la publicación"', 'href="https://www.instagram.com/p/xyz/" target="_blank" rel="noopener noreferrer"', $nav->get('/redes/' . $id)['cuerpo']);
verificar_contiene('el formulario lo muestra solo con estado Publicado', 'data-mostrar-si="estado=publicado" hidden>Link de la publicación', $nav->get("/redes/$id/editar")['cuerpo']);

seccion('título completo en el calendario (sin cortar) y arrastrar y soltar para cambiar el día');
$tituloLargo = $MARCA . ' Un título bien largo que en la celda del calendario tiene que verse completo, con salto de línea';
$nav->get('/redes');
$nav->post('/acciones/publicacion_guardar', ['id' => '0', 'fecha' => $hoy, 'tipo' => 'Post', 'estado' => 'listo', 'titulo' => $tituloLargo, 'redes' => ['Facebook']]);
$idHoy = (int) valor('SELECT MAX(id) FROM publicaciones WHERE usuario_id = {U}');
$cal = $nav->get('/redes?mes=' . substr($hoy, 0, 7))['cuerpo'];
verificar_contiene('el título entero está en la etiqueta', e($tituloLargo) . '</span>', $cal);
verificar_contiene('con el ícono de su red', '#red-facebook', $cal);
$css = (string) file_get_contents(RAIZ_PROYECTO . '/public_html/assets/css/app.css');
verificar_cierto('CSS: la etiqueta hace salto de línea (sin "…" ni nowrap)', str_contains($css, '.cal-pub-txt { min-width: 0; overflow-wrap: anywhere; white-space: normal; }')
    && !preg_match('/\.cal-pub-txt \{[^}]*(ellipsis|nowrap)/', $css));
verificar_contiene('cada etiqueta lleva su id para arrastrarla', 'data-pub-id="' . $idHoy . '"', $cal);
verificar_contiene('el calendario sabe a dónde mandar el cambio', 'data-mover-url="/acciones/publicacion_mover"', $cal);
$enOtro = date('Y-m-d', strtotime('+5 days'));
$r = $nav->post('/acciones/publicacion_mover', ['id' => (string) $idHoy, 'fecha' => $enOtro]);
$jr = json_decode($r['cuerpo'], true);
verificar('mover: responde JSON ok con el mensaje para el toast', [200, true], [$r['codigo'], $jr['ok'] ?? null]);
verificar_contiene('mover: mensaje', 'Publicación movida al', (string) ($jr['mensaje'] ?? ''));
verificar('mover: la fecha cambió', $enOtro, valor('SELECT fecha FROM publicaciones WHERE id = ? AND usuario_id = {U}', [$idHoy]));
$r = $nav->post('/acciones/publicacion_mover', ['id' => (string) $idHoy, 'fecha' => 'cualquiera']);
verificar('mover con fecha inválida: 422 y no cambia', [422, $enOtro], [$r['codigo'], valor('SELECT fecha FROM publicaciones WHERE id = ? AND usuario_id = {U}', [$idHoy])]);
$r = $nav->pedir('POST', '/acciones/publicacion_mover', ['id' => (string) $idHoy, 'fecha' => $hoy]);
verificar('mover sin token CSRF: 403 y no cambia', [403, $enOtro], [$r['codigo'], valor('SELECT fecha FROM publicaciones WHERE id = ? AND usuario_id = {U}', [$idHoy])]);
$nav->post('/acciones/publicacion_mover', ['id' => (string) $idHoy, 'fecha' => $hoy]);

seccion('error de validación: vuelve al formulario completo con lo escrito');
$nav->get('/redes');
$r = $nav->post('/acciones/publicacion_guardar', ['id' => '0', 'fecha' => $hoy, 'tipo' => 'Reel', 'estado' => 'idea', 'titulo' => '', 'copy_texto' => 'Texto 😀 que no se pierde']);
verificar('sin título: vuelve a /redes/nueva', true, redirige_a($r, '/redes/nueva'));
$form = $nav->get($r['location'])['cuerpo'];
verificar_contiene('el error se muestra', 'El título es obligatorio.', $form);
verificar_contiene('el copy escrito se repuebla (con su emoji)', 'Texto 😀 que no se pierde', $form);

seccion('dashboard: card "Redes esta semana"; sidebar y botón "+"');
$dash = $nav->get('/')['cuerpo'];
verificar_contiene('card en el dashboard', 'Redes esta semana', $dash);
verificar_contiene('con la publicación de hoy', $MARCA, $dash);
verificar_contiene('y su estado', '>Listo</span>', $dash);
verificar_contiene('"Redes" en el sidebar', 'href="/redes" title="Redes"', $dash);
verificar_contiene('"Nueva publicación" en el botón +', 'href="/redes/nueva" data-abrir="publicacion"', $dash);
$navOtra = new Navegador();
$sinRedes = nuevo_usuario_con_clave('redes_sin_uso', 'Clave-Redes-2026');
$navOtra->login($sinRedes['usuario'], $sinRedes['clave']);
verificar('quien no usa Redes no ve la card en el dashboard', false, str_contains($navOtra->get('/')['cuerpo'], 'Redes esta semana'));
fijar_usuario($duena['id']);

seccion('nada que la CSP bloquee en las pantallas de Redes');
foreach (['/redes', '/redes?mes=' . substr($en2, 0, 7) . '&dia=' . $en2, '/redes?vista=lista', '/redes?vista=lista&atrasadas=1', "/redes/$id", "/redes/$id/editar",
    '/redes/nueva?fecha=' . $hoy, '/', '/configuracion'] as $ruta) {
    $r = $nav->get($ruta);
    verificar("$ruta responde 200", 200, $r['codigo']);
    verificar("$ruta: sin contenido en línea que bloquee la CSP", [], $violacionesCsp($r['cuerpo']));
}

seccion('Configuración → Redes: tipos editables y aviso del día');
$conf = $nav->get('/configuracion')['cuerpo'];
verificar_contiene('textarea con los tipos por defecto', "Post\nReel\nHistoria\nCarrusel</textarea>", $conf);
$r = $nav->post('/acciones/config_guardar', [
    'dias_aviso' => '30,15,7,0', 'plantilla_whatsapp' => PLANTILLA_WHATSAPP_DEFECTO, 'plantilla_renovacion' => PLANTILLA_RENOVACION_DEFECTO,
    'dolar_tipo' => 'blue', 'email_metodo' => 'servidor', 'redes_tipos' => "Post\nReel\nTikTok 🎵", 'redes_aviso' => '1',
]);
verificar('guardar configuración', true, redirige_a($r, '/configuracion'));
verificar('tipos guardados (con emoji)', ['Post', 'Reel', 'TikTok 🎵'], redes_tipos());
verificar('aviso del día activado', true, redes_aviso_activo());
verificar_contiene('el formulario ofrece el tipo nuevo', '<option value="TikTok 🎵">', $nav->get('/redes/nueva')['cuerpo']);

seccion('aislamiento: otra cuenta no ve ni toca nada de Redes de la dueña');
$huella = fn() => con_usuario($duena['id'], fn() => md5(json_encode(filas('SELECT * FROM publicaciones WHERE usuario_id = {U} ORDER BY id'))));
$huellaInicial = $huella();
$ajena = nuevo_usuario_con_clave('redes_ajena', 'Clave-Ajena-2026');
insertar('publicaciones', ['fecha' => $hoy, 'tipo' => 'Post', 'estado' => 'idea', 'titulo' => 'Propia de la ajena', 'creado_en' => date('Y-m-d H:i:s')]);
$navAjena = new Navegador();
$navAjena->login($ajena['usuario'], $ajena['clave']);
foreach (['/redes', '/redes?mes=' . substr($en2, 0, 7) . '&dia=' . $en2, '/redes?vista=lista', '/redes?vista=lista&tipo=Reel', '/redes?vista=lista&atrasadas=1',
    "/redes/$id", "/redes/$id/editar", "/redes/$idHoy", '/'] as $ruta) {
    $r = $navAjena->get($ruta);
    verificar_cierto("ajena: $ruta (HTTP {$r['codigo']}) sin datos de la dueña", $r['codigo'] < 500 && !str_contains($r['cuerpo'] . $r['location'], $MARCA));
}
verificar('ajena: /redes/{id de la dueña} la manda al calendario', true, redirige_a($navAjena->get("/redes/$id"), '/redes'));
verificar_contiene('control: la ajena sí ve la suya', 'Propia de la ajena', $navAjena->get('/redes?vista=lista')['cuerpo']);
foreach ([
    'publicacion_guardar' => ['id' => (string) $id, 'fecha' => $hoy, 'tipo' => 'Post', 'estado' => 'idea', 'titulo' => 'Pisada'],
    'publicacion_estado' => ['id' => (string) $id, 'estado' => 'idea'],
    'publicacion_mover' => ['id' => (string) $id, 'fecha' => $hoy],
    'publicacion_eliminar' => ['id' => (string) $id],
] as $accion => $datos) {
    $navAjena->get('/redes');
    $r = $navAjena->post('/acciones/' . $accion, $datos);
    verificar_cierto("ajena: $accion con el id de la dueña (HTTP {$r['codigo']}) no cambia nada", $r['codigo'] < 500 && $huella() === $huellaInicial);
}
verificar('a la ajena no le quedó ninguna copia', 1, (int) con_usuario($ajena['id'], fn() => valor('SELECT COUNT(*) FROM publicaciones WHERE usuario_id = {U}')));
$navAjena->get('/redes');
$r = $navAjena->pedir('POST', '/acciones/publicacion_eliminar', ['id' => (string) $id]);
verificar('acción sin token CSRF: 403', 403, $r['codigo']);
fijar_usuario($duena['id']);
