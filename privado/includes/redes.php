<?php
/**
 * redes.php — Módulo "Redes": calendario de publicaciones para redes sociales (posts, reels, historias…).
 *
 * Cada publicación tiene fecha (y hora opcional), tipo (lista editable en Configuración), estado (lista fija),
 * título, copy (texto largo con emojis: la tabla y la conexión son utf8mb4), notas internas y un link opcional.
 * Como todo lo demás, cada usuario ve y toca solo las suyas (tabla publicaciones en TABLAS_USUARIO).
 */
declare(strict_types=1);

/** Estados (lista fija, en el orden del flujo de trabajo): clave => texto. */
const PUB_ESTADOS = ['idea' => 'Idea', 'preparacion' => 'En preparación', 'listo' => 'Listo', 'publicado' => 'Publicado'];

/** Color de cada estado: el tipo de chip del sistema de diseño (y la clase del punto en el calendario). */
const PUB_ESTADO_COLOR = ['idea' => 'mute', 'preparacion' => 'warn', 'listo' => 'info', 'publicado' => 'ok'];

/** Tipos por defecto (cada usuario los puede cambiar en Configuración → Redes). */
const PUB_TIPOS_DEFECTO = ['Post', 'Reel', 'Historia', 'Carrusel'];

/** Redes sociales por defecto (lista editable en Configuración → Redes). Una publicación puede ir a varias. */
const PUB_REDES_DEFECTO = ['Instagram', 'Facebook', 'LinkedIn', 'TikTok'];

/** Ícono del sprite para las redes conocidas (por nombre en minúsculas); las demás se muestran con sus iniciales. */
const PUB_RED_ICONO = ['instagram' => 'red-instagram', 'facebook' => 'red-facebook', 'linkedin' => 'red-linkedin', 'tiktok' => 'red-tiktok'];

/** Límite de caracteres del copy en Instagram: el contador avisa al acercarse y marca en rojo si se pasa (no bloquea). */
const PUB_LIMITE_INSTAGRAM = 2200;
const PUB_LIMITE_AVISO = 2000;     // desde acá, aviso suave

const PUB_TITULO_MAX = 160;
const PUB_COPY_MAX = 5000;       // caracteres (no bytes); con emojis de 4 bytes sigue entrando en un TEXT (64 KB)
const PUB_NOTAS_MAX = 2000;
const PUB_LINK_MAX = 500;
const PUB_TIPO_MAX = 40;
const PUB_TIPOS_MAX = 20;

/** Tipos de publicación del usuario en contexto, en el orden en que los cargó. */
function redes_tipos(): array
{
    $guardado = cfg('redes_tipos');
    if ($guardado === '') {
        return PUB_TIPOS_DEFECTO;
    }
    return array_values(array_filter(array_map('trim', explode("\n", $guardado)), fn($t) => $t !== '')) ?: PUB_TIPOS_DEFECTO;
}

/**
 * Lee la lista de tipos escrita en Configuración (uno por renglón, también se aceptan comas).
 * También sirve para la lista de redes ($singular / $plural / $ejemplo cambian los mensajes).
 * Devuelve [tipos, error]: error es null si está bien. Los repetidos (sin importar mayúsculas) se descartan.
 */
function redes_tipos_parsear(string $texto, string $singular = 'tipo de publicación', string $plural = 'tipos de publicación', string $ejemplo = 'Post', string $un = 'un'): array
{
    $tipos = [];
    foreach (preg_split('/[\r\n,]+/', $texto) ?: [] as $t) {
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        if ($t === '') {
            continue;
        }
        if (mb_strlen($t) > PUB_TIPO_MAX) {
            return [[], 'Cada ' . $singular . ' puede tener hasta ' . PUB_TIPO_MAX . ' caracteres.'];
        }
        $tipos[mb_strtolower($t)] ??= $t;
    }
    if (!$tipos) {
        return [[], 'Cargá al menos ' . $un . ' ' . $singular . ' (por ejemplo ' . $ejemplo . ').'];
    }
    if (count($tipos) > PUB_TIPOS_MAX) {
        return [[], 'Hasta ' . PUB_TIPOS_MAX . ' ' . $plural . '.'];
    }
    return [array_values($tipos), null];
}

/** Redes sociales del usuario en contexto (Instagram, Facebook…), en el orden en que las cargó. */
function redes_plataformas(): array
{
    $guardado = cfg('redes_plataformas');
    if ($guardado === '') {
        return PUB_REDES_DEFECTO;
    }
    return array_values(array_filter(array_map('trim', explode("\n", $guardado)), fn($t) => $t !== '')) ?: PUB_REDES_DEFECTO;
}

/** Redes guardadas en una publicación ("Instagram,TikTok") → lista. Los nombres no llevan comas (las separa el parser). */
function pub_redes(?string $guardado): array
{
    return array_values(array_filter(array_map('trim', explode(',', (string) $guardado)), fn($r) => $r !== ''));
}

/** Ícono chico de una red: el del sprite si es conocida, si no sus iniciales. $conNombre: nombre para lectores de pantalla
 * (false cuando el nombre ya se ve al lado). Devuelve HTML escapado. */
function red_icono_html(string $red, bool $conNombre = true): string
{
    $ico = PUB_RED_ICONO[mb_strtolower($red)] ?? null;
    return '<i class="red-ico" title="' . e($red) . '">'     // <i> y no <span>: dentro de un .chip-radio los span toman el estilo del chip
        . ($ico ? icono($ico, 'chico') : '<i class="red-ini">' . e(mb_strtoupper(mb_substr($red, 0, 2))) . '</i>')
        . ($conNombre ? '<i class="sr-solo">' . e($red) . '</i>' : '') . '</i>';
}

/** Íconos de todas las redes de una publicación (vacío si no tiene). */
function redes_iconos_html(?string $guardado): string
{
    $redes = pub_redes($guardado);
    return $redes ? '<span class="redes-iconos">' . implode('', array_map('red_icono_html', $redes)) . '</span>' : '';
}

/** Contador del copy: [caracteres, hashtags]. Cuenta caracteres como los cuenta Instagram (cada emoji simple, uno). */
function copy_contador(string $copy): array
{
    return [mb_strlen($copy), preg_match_all('/#[\p{L}\p{N}_]+/u', $copy)];
}

/**
 * Texto del contador: "1.234 / 2.200 caracteres · 5 hashtags" (y "— se pasa del límite de Instagram"). El mismo
 * texto lo arma app.js mientras se escribe. Es solo informativo: nunca impide guardar.
 */
function texto_contador(int $caracteres, int $hashtags): string
{
    $t = number_format($caracteres, 0, ',', '.') . ' / ' . number_format(PUB_LIMITE_INSTAGRAM, 0, ',', '.') . ' caracteres · '
        . $hashtags . ' hashtag' . ($hashtags === 1 ? '' : 's');
    if ($caracteres > PUB_LIMITE_INSTAGRAM) {
        return $t . ' — se pasa del límite de Instagram';
    }
    return $caracteres >= PUB_LIMITE_AVISO ? $t . ' — cerca del límite de Instagram' : $t;
}

/** ¿Está activado el aviso del día (lo que hay que publicar hoy) por email/Telegram? */
function redes_aviso_activo(): bool
{
    return cfg('redes_aviso', '0') === '1';
}

/** Una publicación del usuario en contexto (o null si no existe o es de otro). */
function publicacion_obtener(int $id): ?array
{
    return $id > 0 ? fila('SELECT * FROM publicaciones WHERE id = ? AND usuario_id = {U}', [$id]) : null;
}

/** Texto largo de un formulario: saltos de línea normalizados y sin espacios sobrantes al principio y al final. */
function texto_largo(string $campo): string
{
    $v = $_POST[$campo] ?? '';
    return is_string($v) ? trim(str_replace(["\r\n", "\r"], "\n", $v)) : '';
}

/** Hora del formulario ("9:30", "09:30" o "09:30:00") → "09:30:00"; '' → null; false si no es una hora válida. */
function hora_normalizada(string $h): string|null|false
{
    $h = trim($h);
    if ($h === '') {
        return null;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $h, $m) || (int) $m[1] > 23 || (int) $m[2] > 59 || (int) ($m[3] ?? 0) > 59) {
        return false;
    }
    return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
}

/** ¿Es un link http(s) razonable? */
function link_valido(string $url): bool
{
    return mb_strlen($url) <= PUB_LINK_MAX && (bool) preg_match('~^https?://[^\s<>"]+$~i', $url)
        && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Valida y normaliza los datos de una publicación. $actual es la publicación como estaba (al editar): su tipo y sus
 * redes se aceptan aunque ya no estén en las listas de Configuración, para no obligar a cambiarlos al corregir otra cosa.
 * Devuelve [datos listos para guardar, mensaje de error o null].
 */
function publicacion_validar(array $d, array $actual = []): array
{
    $hora = hora_normalizada((string) ($d['hora'] ?? ''));
    $redes = array_values(array_unique(array_filter(array_map(fn($r) => trim((string) $r), is_array($d['redes'] ?? null) ? $d['redes'] : []), fn($r) => $r !== '')));
    $redesValidas = array_merge(redes_plataformas(), pub_redes($actual['redes'] ?? ''));
    $limpio = [
        'fecha'          => trim((string) ($d['fecha'] ?? '')),
        'hora'           => $hora === false ? null : $hora,
        'tipo'           => trim((string) ($d['tipo'] ?? '')),
        'redes'          => implode(',', $redes),
        'estado'         => trim((string) ($d['estado'] ?? 'idea')),
        'titulo'         => trim((string) ($d['titulo'] ?? '')),
        'copy_texto'     => (string) ($d['copy_texto'] ?? ''),
        'notas'          => (string) ($d['notas'] ?? ''),
        'link'           => trim((string) ($d['link'] ?? '')),
        'link_publicado' => trim((string) ($d['link_publicado'] ?? '')),
    ];
    $error = match (true) {
        !fecha_valida($limpio['fecha'])                                  => 'Elegí una fecha válida.',
        $hora === false                                                   => 'La hora no es válida (ej. 18:30).',
        $limpio['titulo'] === ''                                          => 'El título es obligatorio.',
        $limpio['tipo'] === '' || (!in_array($limpio['tipo'], redes_tipos(), true) && $limpio['tipo'] !== ($actual['tipo'] ?? null))
                                                                          => 'Elegí un tipo de publicación de la lista.',
        (bool) array_diff($redes, $redesValidas)                          => 'Elegí las redes de la lista.',
        mb_strlen($limpio['redes']) > 400                                 => 'Demasiadas redes elegidas.',
        !isset(PUB_ESTADOS[$limpio['estado']])                            => 'Elegí un estado de la lista.',
        $limpio['link'] !== '' && !link_valido($limpio['link'])           => 'El link tiene que ser una dirección completa que empiece con https:// (o http://).',
        $limpio['link_publicado'] !== '' && !link_valido($limpio['link_publicado'])
                                                                          => 'El link de la publicación tiene que ser una dirección completa que empiece con https://.',
        default => largo_excedido([
            'El título' => [$limpio['titulo'], PUB_TITULO_MAX], 'El copy' => [$limpio['copy_texto'], PUB_COPY_MAX],
            'Las notas' => [$limpio['notas'], PUB_NOTAS_MAX], 'El tipo' => [$limpio['tipo'], PUB_TIPO_MAX],
        ]),
    };
    $limpio['copy_texto'] = $limpio['copy_texto'] !== '' ? $limpio['copy_texto'] : null;
    $limpio['notas'] = $limpio['notas'] !== '' ? $limpio['notas'] : null;
    return [$limpio, $error];
}

/** Crea (id 0) o actualiza una publicación ya validada. Devuelve su id (0 si el id no es del usuario). */
function publicacion_guardar(int $id, array $datos): int
{
    if ($id > 0) {
        if (!publicacion_obtener($id)) {
            return 0;
        }
        actualizar('publicaciones', $id, $datos + ['actualizado_en' => date('Y-m-d H:i:s')]);
        return $id;
    }
    return insertar('publicaciones', $datos + ['creado_en' => date('Y-m-d H:i:s')]);
}

/**
 * Cambia la fecha (arrastrar y soltar en el calendario). Devuelve false si la fecha no es válida o la publicación no
 * es del usuario.
 */
function publicacion_mover(int $id, string $fecha): bool
{
    if (!fecha_valida($fecha) || !publicacion_obtener($id)) {
        return false;
    }
    actualizar('publicaciones', $id, ['fecha' => $fecha, 'actualizado_en' => date('Y-m-d H:i:s')]);
    return true;
}

/** Cambia el estado. Devuelve false si el estado no existe o la publicación no es del usuario. */
function publicacion_cambiar_estado(int $id, string $estado): bool
{
    if (!isset(PUB_ESTADOS[$estado]) || !publicacion_obtener($id)) {
        return false;
    }
    actualizar('publicaciones', $id, ['estado' => $estado, 'actualizado_en' => date('Y-m-d H:i:s')]);
    return true;
}

/** Publicaciones entre dos fechas (inclusive), ordenadas por fecha y hora (las sin hora, al final del día). */
function publicaciones_entre(string $desde, string $hasta): array
{
    return filas(
        'SELECT * FROM publicaciones WHERE usuario_id = {U} AND fecha BETWEEN ? AND ?
         ORDER BY fecha, hora IS NULL, hora, id',
        [$desde, $hasta]
    );
}

/** Próximas publicaciones (desde hoy), con filtros opcionales por tipo y estado. */
function publicaciones_proximas(string $tipo = '', string $estado = '', int $limite = 300): array
{
    $sql = 'SELECT * FROM publicaciones WHERE usuario_id = {U} AND fecha >= CURDATE()';
    $p = [];
    if ($tipo !== '') {
        $sql .= ' AND tipo = ?';
        $p[] = $tipo;
    }
    if ($estado !== '') {
        $sql .= ' AND estado = ?';
        $p[] = $estado;
    }
    return filas($sql . ' ORDER BY fecha, hora IS NULL, hora, id LIMIT ' . max(1, $limite), $p);
}

/** Publicaciones de días pasados que todavía no se marcaron como publicadas (las "atrasadas"). */
function publicaciones_atrasadas(string $tipo = '', int $limite = 300): array
{
    $sql = "SELECT * FROM publicaciones WHERE usuario_id = {U} AND fecha < CURDATE() AND estado <> 'publicado'";
    $p = [];
    if ($tipo !== '') {
        $sql .= ' AND tipo = ?';
        $p[] = $tipo;
    }
    return filas($sql . ' ORDER BY fecha DESC, hora IS NULL, hora, id LIMIT ' . max(1, $limite), $p);
}

function publicaciones_atrasadas_cantidad(): int
{
    return (int) valor("SELECT COUNT(*) FROM publicaciones WHERE usuario_id = {U} AND fecha < CURDATE() AND estado <> 'publicado'");
}

/** ¿El usuario cargó alguna publicación alguna vez? (el dashboard muestra la card de Redes solo si usa el módulo) */
function usa_redes(): bool
{
    return (bool) valor('SELECT 1 FROM publicaciones WHERE usuario_id = {U} LIMIT 1');
}

/** Semanas (de lunes a domingo) que muestra el calendario de un mes "AAAA-MM": lista de semanas de 7 fechas. */
function calendario_semanas(string $mes): array
{
    $primero = new DateTimeImmutable($mes . '-01');
    $inicio = $primero->modify('-' . ((int) $primero->format('N') - 1) . ' days');
    $ultimo = $primero->modify('last day of this month');
    $fin = $ultimo->modify('+' . (7 - (int) $ultimo->format('N')) . ' days');
    $dias = [];
    for ($d = $inicio; $d <= $fin; $d = $d->modify('+1 day')) {
        $dias[] = $d->format('Y-m-d');
    }
    return array_chunk($dias, 7);
}

/** ¿Es un mes "AAAA-MM" válido (entre 2000 y 2100)? */
function mes_valido(string $mes): bool
{
    return (bool) preg_match('/^(\d{4})-(\d{2})$/', $mes, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12 && (int) $m[1] >= 2000 && (int) $m[1] <= 2100;
}

/** "domingo 4 de octubre" (con $conAnio: "domingo 4 de octubre de 2026"). */
function fecha_larga(string $ymd, bool $conAnio = false): string
{
    static $dias = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
    static $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $t = strtotime($ymd);
    return $dias[(int) date('N', $t)] . ' ' . date('j', $t) . ' de ' . $meses[(int) date('n', $t)] . ($conAnio ? ' de ' . date('Y', $t) : '');
}

/** Hora corta para mostrar: "09:30:00" → "09:30" ('' si no tiene). */
function hora_corta(?string $hora): string
{
    return $hora ? substr($hora, 0, 5) : '';
}

/** Chip de estado de una publicación, con su color. */
function chip_pub_estado(string $estado): string
{
    return chip(PUB_ESTADOS[$estado] ?? $estado, PUB_ESTADO_COLOR[$estado] ?? 'mute');
}

/**
 * Renglón de una publicación en una lista (panel del día, vista Lista y card del dashboard): punto de color del
 * estado, título, hora y tipo, y el chip del estado. Toda la fila lleva a la publicación. Devuelve HTML escapado.
 */
function pub_item_html(array $p, bool $conFecha = false): string
{
    $href = url('publicacion', ['id' => (int) $p['id']]);
    $sub = [];
    if ($conFecha) {
        static $dias = ['', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
        $d = dias_hasta($p['fecha']);
        $t = strtotime($p['fecha']);
        $sub[] = '<span>' . e($d === 0 ? 'hoy' : ($d === 1 ? 'mañana' : $dias[(int) date('N', $t)] . ' ' . date('j', $t))) . '</span>';
    }
    if ($p['hora']) {
        $sub[] = '<span class="mono">' . e(hora_corta($p['hora'])) . '</span>';
    }
    $sub[] = chip($p['tipo']);
    $sub[] = redes_iconos_html($p['redes'] ?? '');
    return '<li class="item pub-item" data-href="' . e($href) . '">'
        . '<span class="pub-punto est-' . e($p['estado']) . '" aria-hidden="true"></span>'
        . '<div class="item-main"><div class="item-tit"><a href="' . e($href) . '">' . e($p['titulo']) . '</a></div>'
        . '<div class="item-sub">' . implode('', $sub) . '</div></div>'
        . '<div class="item-der">' . chip_pub_estado($p['estado']) . '</div></li>';
}

/** Datos de una publicación para el formulario del bottom sheet (JSON en la página; lo lee app.js). */
function publicacion_json(array $p): string
{
    return json_encode([
        'id' => (int) $p['id'], 'fecha' => $p['fecha'], 'hora' => hora_corta($p['hora']), 'tipo' => $p['tipo'], 'estado' => $p['estado'],
        'titulo' => $p['titulo'], 'copy_texto' => (string) $p['copy_texto'], 'notas' => (string) $p['notas'], 'link' => $p['link'],
        'redes' => pub_redes($p['redes'] ?? ''), 'link_publicado' => (string) ($p['link_publicado'] ?? ''),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * Aviso del día por email/Telegram: lo que hay que publicar HOY y todavía no está marcado como Publicado.
 * Solo si el usuario lo activó en Configuración → Redes. Se manda una sola vez por día (si falla, reintenta en la
 * próxima corrida del cron). Lo llama cron/avisos_vencimientos.php, una vez por usuario.
 */
function ejecutar_aviso_redes(): array
{
    if (!redes_aviso_activo()) {
        return ['enviado' => false, 'lineas' => ['Redes: el aviso del día está desactivado.']];
    }
    $hoy = date('Y-m-d');
    $pubs = array_values(array_filter(publicaciones_entre($hoy, $hoy), fn($p) => $p['estado'] !== 'publicado'));
    if (!$pubs) {
        return ['enviado' => false, 'lineas' => ['Redes: no hay nada pendiente para publicar hoy.']];
    }
    if (ya_avisado('redes', 0, 0, $hoy)) {
        return ['enviado' => false, 'lineas' => ['Redes: el aviso de hoy ya se envió.']];
    }
    $base = app_url();
    $lineas = ['Para publicar hoy (' . fecha_larga($hoy) . '):', ''];
    foreach ($pubs as $p) {
        $lineas[] = '• ' . ($p['hora'] ? hora_corta($p['hora']) . ' — ' : '') . $p['tipo'] . ': ' . $p['titulo'] . ' (' . PUB_ESTADOS[$p['estado']] . ')'
            . ($base !== '' ? "\n  " . $base . '/redes/' . (int) $p['id'] : '');
    }
    $texto = implode("\n", $lineas);
    $res = notificar('redes_hoy', 'Para publicar hoy (' . count($pubs) . ')', $texto, [['tipo' => 'redes', 'id' => 0, 'dias' => 0, 'venc' => $hoy]]);
    $log = array_map(fn($d) => 'Redes: ' . $d, $res['detalle']);
    $log[] = notificacion_exitosa($res)
        ? 'Redes: aviso enviado (' . count($pubs) . ' para hoy).'
        : 'Redes: no se pudo enviar por ningún canal, se reintenta en la próxima corrida.';
    return ['enviado' => notificacion_exitosa($res), 'lineas' => $log, 'texto' => $texto];
}
