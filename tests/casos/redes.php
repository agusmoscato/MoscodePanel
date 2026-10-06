<?php
/**
 * redes.php — Módulo Redes (calendario de publicaciones): crear, editar, mover de día, cambiar estado y eliminar con las
 * acciones reales (en subproceso, como desde el formulario), validaciones, emojis en el copy (utf8mb4 de punta a
 * punta: columna, conexión y vuelta exacta), tipos editables, el aviso del día y el aislamiento entre usuarios.
 * Lo que se ve por HTTP (pantallas, CSP, JSON del formulario) está en http_redes.php.
 */
declare(strict_types=1);

// Copy con lo más difícil de guardar: emojis de 4 bytes, secuencias con ZWJ y tono de piel, banderas, saltos de línea
$COPY = "¿Mito o realidad? 🤔\n\n❌ Mito: \"las redes son gratis\"\n✅ Realidad: requieren estrategia 📈🔥\n\n👩‍💻👍🏽 🇦🇷 ✨\n#marketing #emprendedores";
$hoy = date('Y-m-d');
$en3 = date('Y-m-d', strtotime('+3 days'));

/** La publicación más nueva del usuario en contexto (o null). */
$ultima = fn() => fila('SELECT * FROM publicaciones WHERE usuario_id = {U} ORDER BY id DESC LIMIT 1');
$cantidad = fn() => (int) valor('SELECT COUNT(*) FROM publicaciones WHERE usuario_id = {U}');

seccion('utf8mb4: la conexión, la tabla y la columna del copy');
$usuario = nuevo_usuario_de_prueba('redes');
verificar('la conexión del panel está en utf8mb4', 'utf8mb4', valor('SELECT @@character_set_connection'));
verificar('publicaciones.copy_texto es utf8mb4', 'utf8mb4', valor(
    "SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'publicaciones' AND COLUMN_NAME = 'copy_texto'"));
verificar('la tabla publicaciones es utf8mb4_unicode_ci', 'utf8mb4_unicode_ci', valor(
    "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'publicaciones'"));
verificar_cierto('publicaciones está en TABLAS_USUARIO (q() exige el filtro {U})', in_array('publicaciones', TABLAS_USUARIO, true));
$bloqueada = false;
try {
    valor('SELECT COUNT(*) FROM publicaciones');
} catch (RuntimeException $ex) {
    $bloqueada = str_contains($ex->getMessage(), 'filtro de usuario');
}
verificar_cierto('una consulta a publicaciones sin {U} se bloquea', $bloqueada);

seccion('crear con la acción real (copy con emojis, hora, link)');
ejecutar_accion($usuario, 'publicacion_guardar', [
    'id' => '0', 'fecha' => $en3, 'hora' => '9:05', 'tipo' => 'Reel', 'estado' => 'preparacion',
    'titulo' => 'Post2 - Mito vs Realidad 🔥', 'copy_texto' => str_replace("\n", "\r\n", $COPY) . "\r\n\r\n",
    'notas' => "Usar la foto del equipo\r\nPedir aprobación", 'link' => 'https://drive.google.com/file/d/abc123/view',
]);
fijar_usuario($usuario);
$p = $ultima();
verificar_cierto('se creó la publicación', $p !== null);
verificar('fecha', $en3, $p['fecha']);
verificar('hora normalizada (9:05 → 09:05:00)', '09:05:00', $p['hora']);
verificar('tipo', 'Reel', $p['tipo']);
verificar('estado', 'preparacion', $p['estado']);
verificar('título con emoji intacto', 'Post2 - Mito vs Realidad 🔥', $p['titulo']);
verificar('copy idéntico (emojis, ZWJ, tono de piel, bandera; saltos \r\n → \n; sin el espacio final)', $COPY, $p['copy_texto']);
verificar('copy: los bytes guardados son los mismos (sin "?" ni mojibake)', strtoupper(bin2hex($COPY)), (string) valor('SELECT HEX(copy_texto) FROM publicaciones WHERE id = ? AND usuario_id = {U}', [$p['id']]));
verificar('copy: CHAR_LENGTH en la base = mb_strlen en PHP (cada emoji es 1 carácter de 4 bytes)', mb_strlen($COPY), (int) valor('SELECT CHAR_LENGTH(copy_texto) FROM publicaciones WHERE id = ? AND usuario_id = {U}', [$p['id']]));
verificar('notas con salto de línea normalizado', "Usar la foto del equipo\nPedir aprobación", $p['notas']);
verificar('link', 'https://drive.google.com/file/d/abc123/view', $p['link']);
$idA = (int) $p['id'];

seccion('crear: validaciones (no se crea nada si algo está mal)');
$antes = $cantidad();
$base = ['id' => '0', 'fecha' => $hoy, 'hora' => '', 'tipo' => 'Post', 'estado' => 'idea', 'titulo' => 'Algo', 'copy_texto' => '', 'notas' => '', 'link' => ''];
foreach ([
    'sin título' => ['titulo' => '   '],
    'sin fecha' => ['fecha' => ''],
    'fecha imposible' => ['fecha' => '2026-02-30'],
    'hora inválida' => ['hora' => '25:00'],
    'tipo que no está en la lista' => ['tipo' => 'Podcast'],
    'estado inexistente' => ['estado' => 'borrador'],
    'link sin http' => ['link' => 'drive.google.com/x'],
    'link javascript:' => ['link' => 'javascript:alert(1)'],
    'título de más de 160' => ['titulo' => str_repeat('a', 161)],
] as $caso => $cambio) {
    ejecutar_accion($usuario, 'publicacion_guardar', array_merge($base, $cambio));
    fijar_usuario($usuario);
    verificar("$caso: no se crea", $antes, $cantidad());
}
// El copy de 5000 emojis no entra en un argumento de consola (ejecutar_accion): se prueba con las mismas funciones que usa la acción
verificar('copy de más de 5000 caracteres: error de validación', 'El copy es demasiado largo (máximo 5000 caracteres).',
    publicacion_validar(array_merge($base, ['copy_texto' => str_repeat('🔥', PUB_COPY_MAX + 1)]))[1]);
[$largo, $errLargo] = publicacion_validar(array_merge($base, ['copy_texto' => str_repeat('🔥', PUB_COPY_MAX)]));
verificar('copy de exactamente 5000 emojis: válido', null, $errLargo);
$idLargo = publicacion_guardar(0, $largo);
verificar('copy de 5000 emojis (20.000 bytes): se guarda entero', PUB_COPY_MAX, (int) valor('SELECT CHAR_LENGTH(copy_texto) FROM publicaciones WHERE id = ? AND usuario_id = {U}', [$idLargo]));
ejecutar_accion($usuario, 'publicacion_guardar', array_merge($base, ['titulo' => 'Sin hora ni copy']));
fijar_usuario($usuario);
$sinHora = $ultima();
verificar('sin hora: queda NULL', null, $sinHora['hora']);
verificar('sin copy ni notas: quedan NULL', [null, null], [$sinHora['copy_texto'], $sinHora['notas']]);

seccion('editar');
ejecutar_accion($usuario, 'publicacion_guardar', [
    'id' => (string) $idA, 'fecha' => $en3, 'hora' => '18:30', 'tipo' => 'Carrusel', 'estado' => 'listo',
    'titulo' => 'Post2 - Mito vs Realidad (v2)', 'copy_texto' => $COPY . "\n\n👉 Link en la bio", 'notas' => '', 'link' => '',
]);
fijar_usuario($usuario);
$p = publicacion_obtener($idA);
verificar('título editado', 'Post2 - Mito vs Realidad (v2)', $p['titulo']);
verificar('tipo, estado y hora editados', ['Carrusel', 'listo', '18:30:00'], [$p['tipo'], $p['estado'], $p['hora']]);
verificar('copy editado con emojis', $COPY . "\n\n👉 Link en la bio", $p['copy_texto']);
verificar('notas y link vaciados', [null, ''], [$p['notas'], $p['link']]);
verificar_cierto('actualizado_en quedó registrado', $p['actualizado_en'] !== null);
verificar('editar no crea otra', $antes + 2, $cantidad());

seccion('tipos editables en Configuración');
verificar('por defecto: Post, Reel, Historia, Carrusel', PUB_TIPOS_DEFECTO, redes_tipos());
verificar('parsear: renglones y comas, sin repetidos (queda la primera forma escrita) ni espacios de más', [['Post', 'Reel', 'TikTok', 'Story destacada'], null],
    redes_tipos_parsear("Post\r\nReel, TikTok\n  tiktok \n post\nStory   destacada\n\n"));
verificar('parsear: vacío es un error', true, redes_tipos_parsear(" \n ,")[1] !== null);
verificar('parsear: un tipo de más de 40 caracteres es un error', true, redes_tipos_parsear(str_repeat('x', 41))[1] !== null);
cfg_set('redes_tipos', "Post\nReel\nTikTok");
verificar('con la lista propia', ['Post', 'Reel', 'TikTok'], redes_tipos());
ejecutar_accion($usuario, 'publicacion_guardar', array_merge($base, ['tipo' => 'TikTok', 'titulo' => 'Video TikTok']));
fijar_usuario($usuario);
verificar('un tipo nuevo de la lista se puede usar', 'TikTok', $ultima()['tipo']);
// La publicación $idA es "Carrusel", que ya no está en la lista: se puede seguir editando sin cambiarle el tipo
ejecutar_accion($usuario, 'publicacion_guardar', [
    'id' => (string) $idA, 'fecha' => $en3, 'hora' => '18:30', 'tipo' => 'Carrusel', 'estado' => 'listo',
    'titulo' => 'Post2 - Mito vs Realidad (v3)', 'copy_texto' => $COPY, 'notas' => '', 'link' => '',
]);
fijar_usuario($usuario);
verificar('editar una con un tipo que se quitó de la lista: se guarda igual', ['Carrusel', 'Post2 - Mito vs Realidad (v3)'], [publicacion_obtener($idA)['tipo'], publicacion_obtener($idA)['titulo']]);
$antesCarrusel = $cantidad();
ejecutar_accion($usuario, 'publicacion_guardar', array_merge($base, ['tipo' => 'Carrusel', 'titulo' => 'Nueva con tipo viejo']));
fijar_usuario($usuario);
verificar('pero una NUEVA con un tipo que ya no está en la lista: no se crea', $antesCarrusel, $cantidad());

seccion('cambiar el estado con un toque');
ejecutar_accion($usuario, 'publicacion_estado', ['id' => (string) $idA, 'estado' => 'publicado']);
fijar_usuario($usuario);
verificar('pasa a Publicado', 'publicado', publicacion_obtener($idA)['estado']);
ejecutar_accion($usuario, 'publicacion_estado', ['id' => (string) $idA, 'estado' => 'archivado']);
fijar_usuario($usuario);
verificar('un estado inexistente no cambia nada', 'publicado', publicacion_obtener($idA)['estado']);
verificar('la lista de estados es fija y en orden', ['idea', 'preparacion', 'listo', 'publicado'], array_keys(PUB_ESTADOS));

seccion('ya no existe "Duplicar"');
verificar('sin acción publicacion_duplicar', false, is_file(RAIZ_PROYECTO . '/privado/acciones/publicacion_duplicar.php'));
verificar('sin función publicacion_duplicar()', false, function_exists('publicacion_duplicar'));

seccion('redes (selección múltiple, lista editable) y link de la publicación');
verificar('redes por defecto', ['Instagram', 'Facebook', 'LinkedIn', 'TikTok'], redes_plataformas());
ejecutar_accion($usuario, 'publicacion_guardar', array_merge($base, ['titulo' => 'Con redes', 'redes' => ['Instagram', 'TikTok', 'Instagram']]));
fijar_usuario($usuario);
$conRedes = $ultima();
verificar('se guardan las redes elegidas (sin repetir)', 'Instagram,TikTok', $conRedes['redes']);
verificar('pub_redes() las devuelve como lista', ['Instagram', 'TikTok'], pub_redes($conRedes['redes']));
$antes = $cantidad();
ejecutar_accion($usuario, 'publicacion_guardar', array_merge($base, ['titulo' => 'Red inventada', 'redes' => ['Instagram', 'MySpace']]));
fijar_usuario($usuario);
verificar('una red que no está en la lista: no se crea', $antes, $cantidad());
ejecutar_accion($usuario, 'publicacion_guardar', array_merge($base, ['titulo' => 'Sin redes']));
fijar_usuario($usuario);
verificar('sin redes elegidas: queda vacío', '', $ultima()['redes']);
cfg_set('redes_plataformas', "Instagram\nYouTube");
ejecutar_accion($usuario, 'publicacion_guardar', array_merge($base, ['id' => (string) $conRedes['id'], 'titulo' => 'Con redes (editada)', 'redes' => ['TikTok', 'YouTube']]));
fijar_usuario($usuario);
verificar('editar: una red que se quitó de la lista pero la publicación ya tenía, se conserva; una nueva de la lista, también',
    'TikTok,YouTube', publicacion_obtener((int) $conRedes['id'])['redes']);
cfg_borrar('redes_plataformas');
verificar('íconos: Instagram con su ícono, una red desconocida con sus iniciales',
    [true, true], [str_contains(red_icono_html('Instagram'), '#red-instagram'), str_contains(red_icono_html('YouTube'), '<i class="red-ini">YO</i>')]);
verificar('parser de redes: mensaje propio', 'Cargá al menos una red social (por ejemplo Instagram).',
    redes_tipos_parsear('', 'red social', 'redes sociales', 'Instagram', 'una')[1]);
ejecutar_accion($usuario, 'publicacion_guardar', array_merge($base, ['titulo' => 'Ya publicada', 'estado' => 'publicado', 'link_publicado' => 'https://www.instagram.com/p/abc123/']));
fijar_usuario($usuario);
verificar('link de la publicación guardado', 'https://www.instagram.com/p/abc123/', $ultima()['link_publicado']);
$antes = $cantidad();
ejecutar_accion($usuario, 'publicacion_guardar', array_merge($base, ['titulo' => 'Link malo', 'estado' => 'publicado', 'link_publicado' => 'instagram.com/p/x']));
fijar_usuario($usuario);
verificar('link de la publicación inválido: no se crea', $antes, $cantidad());

seccion('contador del copy (límite de Instagram, hashtags): solo informativo');
verificar('copy_contador: caracteres y hashtags', [33, 3], copy_contador('Hola 👋 #marketing #emprende #café'));
verificar('texto normal', '32 / 2.200 caracteres · 3 hashtags', texto_contador(32, 3));
verificar('cerca del límite', '2.100 / 2.200 caracteres · 1 hashtag — cerca del límite de Instagram', texto_contador(2100, 1));
verificar('pasado del límite', '2.201 / 2.200 caracteres · 0 hashtags — se pasa del límite de Instagram', texto_contador(2201, 0));
[$largoIg, $errIg] = publicacion_validar(array_merge($base, ['copy_texto' => str_repeat('a', PUB_LIMITE_INSTAGRAM + 300)]));
verificar('un copy de más de 2.200 caracteres se guarda igual (no bloquea)', null, $errIg);

seccion('mover a otro día (arrastrar y soltar): acción por fetch');
$en10 = date('Y-m-d', strtotime('+10 days'));
ejecutar_accion($usuario, 'publicacion_mover', ['id' => (string) $sinHora['id'], 'fecha' => $en10]);
fijar_usuario($usuario);
verificar('cambió la fecha', $en10, publicacion_obtener((int) $sinHora['id'])['fecha']);
verificar('lo demás no cambia', ['Sin hora ni copy', 'idea'], [publicacion_obtener((int) $sinHora['id'])['titulo'], publicacion_obtener((int) $sinHora['id'])['estado']]);
ejecutar_accion($usuario, 'publicacion_mover', ['id' => (string) $sinHora['id'], 'fecha' => '2026-02-30']);
fijar_usuario($usuario);
verificar('una fecha inválida no la mueve', $en10, publicacion_obtener((int) $sinHora['id'])['fecha']);

seccion('calendario: semanas de lunes a domingo');
$oct = calendario_semanas('2026-10');
verificar('octubre 2026 (empieza jueves): 5 semanas', 5, count($oct));
verificar('la primera celda es el lunes 28/09 y la última el domingo 01/11', ['2026-09-28', '2026-11-01'], [$oct[0][0], $oct[4][6]]);
verificar('cada semana tiene 7 días', [7], array_values(array_unique(array_map('count', $oct))));
$feb = calendario_semanas('2027-02');
verificar('febrero 2027 (empieza lunes y termina domingo): 4 semanas exactas', [4, '2027-02-01', '2027-02-28'], [count($feb), $feb[0][0], $feb[3][6]]);
verificar('mes_valido', [true, false, false, false], [mes_valido('2026-10'), mes_valido('2026-13'), mes_valido('26-10'), mes_valido('1999-12')]);
verificar('hora_normalizada', ['09:05:00', null, false, false], [hora_normalizada('9:05'), hora_normalizada(''), hora_normalizada('24:00'), hora_normalizada('9h')]);
$fechas = array_column(publicaciones_entre($en3, $en10), 'fecha');
$ordenadas = $fechas;
sort($ordenadas);
verificar_cierto('publicaciones_entre: trae las del rango, ordenadas por fecha', $fechas !== [] && $fechas === $ordenadas);

seccion('aviso del día por email/Telegram (Configuración → Redes)');
cfg_set('redes_aviso', '0');
verificar_contiene('desactivado: no hace nada', 'desactivado', implode(' ', ejecutar_aviso_redes()['lineas']));
cfg_set('redes_aviso', '1');
ejecutar_accion($usuario, 'publicacion_guardar', array_merge($base, ['fecha' => $hoy, 'hora' => '10:00', 'tipo' => 'Reel', 'estado' => 'listo', 'titulo' => 'Para hoy pendiente 🔔']));
ejecutar_accion($usuario, 'publicacion_guardar', array_merge($base, ['fecha' => $hoy, 'tipo' => 'Post', 'estado' => 'publicado', 'titulo' => 'Para hoy ya publicada']));
fijar_usuario($usuario);
$r = ejecutar_aviso_redes();
verificar_contiene('el texto incluye la pendiente de hoy, con su hora y tipo', '10:00 — Reel: Para hoy pendiente 🔔 (Listo)', (string) ($r['texto'] ?? ''));
verificar('el texto NO incluye la ya publicada', false, str_contains((string) ($r['texto'] ?? ''), 'ya publicada'));
verificar('sin canales configurados no se manda (y queda para reintentar)', false, $r['enviado']);
// Con un envío exitoso registrado hoy, no se repite
insertar('notificaciones_log', ['canal' => 'telegram', 'tipo' => 'redes_hoy', 'referencia_tipo' => 'redes', 'referencia_id' => 0, 'dias_aviso' => 0,
    'vencimiento_ref' => $hoy, 'destinatario' => 'x', 'exito' => 1, 'detalle' => 'Enviado', 'enviado_en' => date('Y-m-d H:i:s')]);
verificar_contiene('si ya se mandó hoy, no se repite', 'ya se envió', implode(' ', ejecutar_aviso_redes()['lineas']));

seccion('eliminar');
ejecutar_accion($usuario, 'publicacion_eliminar', ['id' => (string) $idLargo]);
fijar_usuario($usuario);
verificar('eliminada', null, publicacion_obtener($idLargo));

seccion('aislamiento: otra cuenta no ve ni toca las publicaciones de esta');
// Ojo: nuevo_usuario_de_prueba() deja el contexto en el usuario NUEVO; para mirar los datos de la dueña hay que
// volver a fijarla (o con_usuario), si no las consultas dan null y la prueba pasaría por accidente.
$huella = fn() => con_usuario($usuario, fn() => md5(json_encode(filas('SELECT * FROM publicaciones WHERE usuario_id = {U} ORDER BY id'))));
$huellaInicial = $huella();
$cantDuena = con_usuario($usuario, $cantidad);
$ajena = nuevo_usuario_de_prueba('redes_ajena');
verificar('la ajena no la ve (publicacion_obtener)', null, publicacion_obtener($idA));
verificar('la ajena no ve ninguna en el rango', [], publicaciones_entre('2000-01-01', '2100-12-31'));
verificar('la ajena tiene sus tipos por defecto (la lista de la dueña es de ella)', PUB_TIPOS_DEFECTO, redes_tipos());
verificar('la ajena no tiene el aviso activado', false, redes_aviso_activo());
verificar('usa_redes(): la ajena no', false, usa_redes());
foreach ([
    'publicacion_guardar' => ['id' => (string) $idA, 'fecha' => $hoy, 'hora' => '', 'tipo' => 'Post', 'estado' => 'idea', 'titulo' => 'Pisada', 'copy_texto' => '', 'notas' => '', 'link' => ''],
    'publicacion_estado' => ['id' => (string) $idA, 'estado' => 'idea'],
    'publicacion_mover' => ['id' => (string) $idA, 'fecha' => $hoy],
    'publicacion_eliminar' => ['id' => (string) $idA],
] as $accion => $datos) {
    ejecutar_accion($ajena, $accion, $datos);
    verificar("$accion con el id de la dueña: los datos de la dueña no cambian", $huellaInicial, $huella());
}
fijar_usuario($ajena);
verificar('y a la ajena no le quedó ninguna (ni al "editar" un id ajeno)', 0, $cantidad());
verificar('control: la dueña sigue teniendo todas las suyas', $cantDuena, con_usuario($usuario, $cantidad));
verificar('control: la dueña sí ve la suya', 'Post2 - Mito vs Realidad (v3)', con_usuario($usuario, fn() => publicacion_obtener($idA)['titulo'] ?? null));
