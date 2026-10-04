<?php
/**
 * ui.php — Helpers de presentación (solo HTML/formato; sin lógica de negocio ni acceso a datos).
 * Los usan las vistas y el layout.
 */
declare(strict_types=1);

/** Ruta de un asset con la versión central (?v=...) para que el celular cargue siempre lo nuevo. */
function asset(string $ruta): string
{
    return base_path() . '/' . ltrim($ruta, '/') . '?v=' . rawurlencode(APP_VERSION);
}

/** Ícono del sprite (assets/img/iconos.svg, Lucide). Todos del mismo tamaño y trazo fino. */
function icono(string $nombre, string $clase = ''): string
{
    return '<svg class="ico' . ($clase !== '' ? ' ' . e($clase) : '') . '" aria-hidden="true" focusable="false">'
        . '<use href="' . asset('assets/img/iconos.svg') . '#' . e($nombre) . '"></use></svg>';
}

/**
 * Monto en fuente mono con la moneda bien visible: "$ 1.234,50" / "US$ 1.234,00".
 * Devuelve HTML ya escapado.
 *
 * Es el ÚNICO lugar que pinta un monto en pantalla: el "ojito" (ocultar montos) funciona con la clase
 * montos-ocultos en <html> y el CSS esconde el .val de cada .monto y muestra "•••••" en su lugar
 * ("$ •••••" / "US$ •••••"). Un monto pintado de otra forma quedaría visible: para textos libres que
 * traen montos (mensajes, registros) está montos_en_texto_html(); tests/casos/http_ocultar_montos.php
 * recorre las pantallas y falla si encuentra un monto fuera de un .monto.
 */
function monto_html($monto, string $moneda = 'ARS', string $clase = '', int $decimales = 2): string
{
    $simbolo = MONEDAS[$moneda] ?? $moneda;
    return '<span class="monto mono ' . e($clase) . ($moneda === 'USD' ? ' usd' : '') . '"><span class="mon">' . e($simbolo)
        . '</span> <span class="val">' . e(number_format((float) $monto, $decimales, ',', '.')) . '</span></span>';
}

/** Monto escrito en un texto: "$ 1.234,50", "US$ 40,00" o un número con formato de plata sin símbolo ("1.234,50"). */
const PATRON_MONTO_TEXTO = '/(US\$|\$)\s?(-?\d(?:[\d.]*\d)?(?:,\d{1,2})?)|(?<![\d.,])(\d{1,3}(?:\.\d{3})*,\d{2})(?![\d,]|\s?%)/u';

/**
 * Texto libre (un registro de actividad, el detalle de un aviso) escapado para HTML, con cada monto que contenga
 * envuelto en un .monto (para que el ojito también lo oculte). Lo mismo hace app.js con los toasts.
 */
function montos_en_texto_html(string $texto): string
{
    // Los textos entre montos y los montos encontrados, en orden: texto0, monto0, texto1, monto1, ...
    preg_match_all(PATRON_MONTO_TEXTO, $texto, $coinc, PREG_SET_ORDER);
    $textos = preg_split(PATRON_MONTO_TEXTO, $texto) ?: [$texto];
    $html = '';
    foreach ($textos as $k => $t) {
        $html .= e($t);
        if (isset($coinc[$k])) {
            $m = $coinc[$k];
            $html .= ($m[1] ?? '') !== ''
                ? '<span class="monto"><span class="mon">' . e($m[1]) . '</span> <span class="val">' . e($m[2]) . '</span></span>'
                : '<span class="monto monto-solo"><span class="val">' . e($m[3]) . '</span></span>';
        }
    }
    return $html;
}

/** Chip con contenido HTML ya escapado (por ejemplo un monto_html()). */
function chip_html(string $html, string $tipo = ''): string
{
    return '<span class="chip' . ($tipo !== '' ? ' ' . e($tipo) : '') . '">' . $html . '</span>';
}

/** Montos por moneda ("$ x" y "US$ y"), omitiendo las monedas en cero. */
function montos_html(array $porMoneda, string $clase = ''): string
{
    $partes = [];
    foreach (['ARS', 'USD'] as $m) {
        if (abs((float) ($porMoneda[$m] ?? 0)) > 0.004) {
            $partes[] = monto_html($porMoneda[$m], $m, $clase);
        }
    }
    return $partes ? implode(' <span class="sep">·</span> ', $partes) : monto_html(0, 'ARS', $clase);
}

/** Fecha corta: "15 oct 2026". */
function fecha_corta(?string $ymd): string
{
    static $meses = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    if (!$ymd) {
        return '—';
    }
    $t = strtotime(substr($ymd, 0, 10));
    return $t ? date('j', $t) . ' ' . $meses[(int) date('n', $t)] . ' ' . date('Y', $t) : $ymd;
}

/** Texto relativo en días: "hoy", "mañana", "en 5 días", "hace 3 días", "hace 2 meses". */
function texto_relativo(int $dias): string
{
    if ($dias === 0) {
        return 'hoy';
    }
    if ($dias === 1) {
        return 'mañana';
    }
    if ($dias === -1) {
        return 'ayer';
    }
    if ($dias > 0) {
        return $dias >= 60 ? 'en ' . (int) round($dias / 30) . ' meses' : 'en ' . $dias . ' días';
    }
    $atras = abs($dias);
    if ($atras >= 60) {
        return 'hace ' . (int) round($atras / 30) . ' meses';
    }
    return 'hace ' . $atras . ' días';
}

/** Tiempo transcurrido desde un datetime: "hace 5 min", "hace 2 h", "hace 3 d". */
function hace_cuanto(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $seg = max(0, time() - (int) strtotime($datetime));
    if ($seg < 90) {
        return 'recién';
    }
    if ($seg < 3600) {
        return 'hace ' . (int) round($seg / 60) . ' min';
    }
    if ($seg < 86400) {
        return 'hace ' . (int) round($seg / 3600) . ' h';
    }
    return 'hace ' . (int) round($seg / 86400) . ' d';
}

/** Chip genérico. $tipo: ok | warn | bad | info | mute (vacío = neutro). */
function chip(string $texto, string $tipo = '', string $icono = ''): string
{
    return '<span class="chip' . ($tipo !== '' ? ' ' . e($tipo) : '') . '">' . ($icono !== '' ? icono($icono) : '') . e($texto) . '</span>';
}

/** Tipo de chip según los días que faltan: vencido o ≤7 rojo, ≤15 ámbar, el resto neutro. */
function tipo_urgencia(int $dias): string
{
    if ($dias <= 7) {
        return 'bad';
    }
    return $dias <= 15 ? 'warn' : 'mute';
}

/** Chip de vencimiento: "vence en 3 días" / "vencido hace 2 días". */
function chip_vencimiento(int $dias): string
{
    $texto = $dias < 0 ? 'vencido ' . texto_relativo($dias) : ($dias === 0 ? 'vence hoy' : 'vence ' . texto_relativo($dias));
    return chip($texto, tipo_urgencia($dias));
}

/** Chip de estado de cargo / cliente / servicio. */
function chip_estado(string $estado): string
{
    $mapa = [
        'pagado' => ['ok', 'pagado'], 'activo' => ['ok', 'activo'], 'al_dia' => ['ok', 'al día'],
        'pendiente' => ['warn', 'pendiente'], 'pausado' => ['warn', 'pausado'],
        'parcial' => ['info', 'parcial'],
        'vencido' => ['bad', 'vencido'], 'baja' => ['bad', 'baja'],
        'inactivo' => ['mute', 'inactivo'], 'anulado' => ['mute', 'anulado'],
        'completado' => ['ok', 'completado'], 'cancelado' => ['mute', 'cancelado'], 'a_vencer' => ['info', 'a vencer'],
    ];
    [$tipo, $texto] = $mapa[$estado] ?? ['mute', $estado];
    return chip($texto, $tipo);
}

/**
 * <head> común de todas las pantallas: meta, favicon, manifest PWA, fuentes locales y CSS.
 * Todos los assets llevan la versión central (?v=). $conTema = true carga tema.js (tema oscuro/claro con
 * preferencia guardada); el portal lo desactiva y fuerza el tema claro. $conPwa = false omite el manifest y los
 * metadatos de app instalable (el portal del cliente no debe instalarse como si fuera el panel).
 */
function ui_head(string $titulo, bool $conTema = true, bool $conPwa = true): string
{
    $h = '<meta charset="utf-8">' . "\n"
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n"
        . '<meta name="robots" content="noindex, nofollow">' . "\n"
        . '<meta name="theme-color" content="' . ($conTema ? '#141517' : '#F3F4F6') . '">' . "\n"
        . '<meta name="app-version" content="' . e(APP_VERSION) . '">' . "\n"
        . '<meta name="base-url" content="' . e(base_path()) . '">' . "\n"
        . '<title>' . e($titulo) . '</title>' . "\n"
        . ($conPwa ? '<link rel="manifest" href="' . e(asset('manifest.webmanifest')) . '">' . "\n" : '')
        . '<link rel="icon" href="' . e(asset('favicon.svg')) . '" type="image/svg+xml">' . "\n"
        . '<link rel="icon" href="' . e(asset('assets/img/favicon-32.png')) . '" sizes="32x32" type="image/png">' . "\n"
        . '<link rel="apple-touch-icon" href="' . e(asset('assets/img/apple-touch-icon.png')) . '">' . "\n"
        . ($conPwa ? '<meta name="mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-title" content="Moscode">' . "\n" : '')
        . '<link rel="preload" href="' . e(base_path()) . '/assets/fonts/inter-400.woff2" as="font" type="font/woff2" crossorigin>' . "\n"
        . '<link rel="preload" href="' . e(base_path()) . '/assets/fonts/inter-600.woff2" as="font" type="font/woff2" crossorigin>' . "\n"
        . '<link rel="stylesheet" href="' . e(asset('assets/css/app.css')) . '">' . "\n";
    if ($conTema) {
        $h .= '<script src="' . e(asset('assets/js/tema.js')) . '"></script>' . "\n";
    }
    return $h;
}

/**
 * Página 404 (o 405) con el diseño de la app. Con sesión iniciada se muestra dentro del layout; sin sesión,
 * como página suelta (sin revelar nada del panel). Termina la ejecución.
 */
function pagina_no_encontrada(int $codigo = 404): never
{
    http_response_code($codigo);
    $usuario = usuario_actual();
    if ($usuario && (int) $usuario['debe_cambiar_clave'] !== 1) {
        $pagina = 'no_encontrado';
        $titulo = $codigo === 405 ? 'Método no permitido' : 'No encontrado';
        $codigoError = $codigo;
        ob_start();
        require RAIZ_PRIVADA . '/vistas/no_encontrado.php';
        $contenido = ob_get_clean();
        require RAIZ_PRIVADA . '/includes/layout.php';
        exit;
    }
    ?><!doctype html>
<html lang="es-AR" data-theme="dark">
<head><?= ui_head('No encontrado — Moscode', true, false) ?></head>
<body class="login-pagina">
<main class="login-caja">
    <div class="login-card">
        <div class="pad-8-0 vacio-est">
            <?= icono('circle-alert') ?>
            <p><strong><?= $codigo === 405 ? 'Método no permitido' : 'No encontramos esa página' ?></strong></p>
            <p>El link no existe o ya no está disponible.</p>
            <a class="btn" href="<?= e(url_base('/login')) ?>">Ir al inicio</a>
        </div>
    </div>
</main>
</body>
</html><?php
    exit;
}
