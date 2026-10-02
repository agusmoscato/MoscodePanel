<?php
/**
 * cotizacion.php — Cotización del dólar (valor venta).
 *
 * Fuente principal: scraping de dolarhoy.com. Alternativa: API pública dolarapi.com.
 * Cada cotización se guarda en la tabla `cotizaciones` (historial). Si la
 * actualización falla, se sigue usando la última guardada y se muestra un aviso.
 */
declare(strict_types=1);

const DOLAR_TIPOS = [
    'blue'    => 'Blue',
    'oficial' => 'Oficial',
    'mep'     => 'MEP (bolsa)',
    'ccl'     => 'CCL (contado con liqui)',
    'tarjeta' => 'Tarjeta',
];

// Página de cada tipo en dolarhoy.com
const DOLARHOY_PAGINAS = [
    'blue'    => 'cotizaciondolarblue',
    'oficial' => 'cotizaciondolaroficial',
    'mep'     => 'cotizaciondolarbolsa',
    'ccl'     => 'cotizaciondolarcontadoconliqui',
    'tarjeta' => 'cotizaciondolartarjeta',
];

// "casa" de cada tipo en dolarapi.com
const DOLARAPI_CASAS = [
    'blue'    => 'blue',
    'oficial' => 'oficial',
    'mep'     => 'bolsa',
    'ccl'     => 'contadoconliqui',
    'tarjeta' => 'tarjeta',
];

/** Variación (en %) que deja una cotización automática pendiente de confirmar por el administrador. */
const COTIZACION_SALTO_PCT = 20.0;

function http_get(string $url, int $timeout = 12): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERAGENT  => 'Mozilla/5.0 (compatible; PanelCobros/1.0)',
        CURLOPT_HTTPHEADER => ['Accept-Language: es-AR,es;q=0.9'],
    ] + curl_opciones_base($timeout, true));
    [$cuerpo, $codigo, $error] = curl_limitado($ch);
    curl_close($ch);

    if ($cuerpo === false || $codigo < 200 || $codigo >= 300) {
        throw new RuntimeException("No se pudo leer " . (parse_url($url, PHP_URL_HOST) ?: 'la fuente') . " (HTTP $codigo) $error");
    }
    return $cuerpo;
}

/** "$1.405" / "1.405,50" / "1405,5" → float, o null si no es un número razonable. */
function parsear_numero_ar(string $texto): ?float
{
    $t = preg_replace('/[^\d\.,]/', '', $texto) ?? '';
    if ($t === '') {
        return null;
    }
    if (str_contains($t, ',')) {
        $t = str_replace('.', '', $t);
        $t = str_replace(',', '.', $t);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $t)) {
        $t = str_replace('.', '', $t);        // "1.405" = mil cuatrocientos cinco
    }
    $n = (float) $t;
    return ($n > 0 && $n < 1000000) ? round($n, 2) : null;
}

/** Valor venta desde dolarhoy.com (parseo del HTML). */
function cotizacion_desde_dolarhoy(string $tipo): float
{
    $pagina = DOLARHOY_PAGINAS[$tipo] ?? null;
    if (!$pagina) {
        throw new RuntimeException("Tipo de dólar desconocido: $tipo");
    }
    $html = http_get("https://dolarhoy.com/$pagina");

    // Estructura verificada (oct-2026): <div class="topic">Venta</div><div class="value">$1.555,00</div>
    // El primer "Venta" de la página es el del tipo de dólar principal de esa URL.
    $patrones = [
        '/<div[^>]*class="topic"[^>]*>\s*Venta\s*<\/div>\s*<div[^>]*class="value"[^>]*>\s*\$?\s*([\d\.,]+)/is',
        // variante anterior del sitio (class="val")
        '/<div[^>]*class="topic"[^>]*>\s*Venta\s*<\/div>\s*<div[^>]*class="val"[^>]*>\s*\$?\s*([\d\.,]+)/is',
    ];
    foreach ($patrones as $p) {
        if (preg_match($p, $html, $m)) {
            $n = parsear_numero_ar($m[1]);
            if ($n !== null) {
                return $n;
            }
        }
    }
    throw new RuntimeException('dolarhoy.com: no se encontró el valor de venta (¿cambió el HTML de la página?).');
}

function cotizacion_desde_dolarapi(string $tipo): float
{
    $casa = DOLARAPI_CASAS[$tipo] ?? null;
    if (!$casa) {
        throw new RuntimeException("Tipo de dólar desconocido: $tipo");
    }
    $json = json_decode(http_get("https://dolarapi.com/v1/dolares/$casa"), true);
    $venta = is_array($json) ? ($json['venta'] ?? null) : null;
    if (!is_int($venta) && !is_float($venta) && !(is_string($venta) && is_numeric($venta))) {
        throw new RuntimeException('dolarapi.com: respuesta sin valor de venta numérico.');
    }
    $n = round((float) $venta, 2);
    if (!is_finite($n) || $n <= 0 || $n >= 1000000) {
        throw new RuntimeException('dolarapi.com: valor de venta fuera de rango.');
    }
    return $n;
}

/**
 * Guarda una cotización. Las automáticas (scraping / API) son COMPARTIDAS por todos los usuarios (usuario_id NULL).
 * Las manuales ($personal = true) valen solo para quien las cargó y se aplican siempre.
 *
 * Una automática que varía más de COTIZACION_SALTO_PCT (20%) contra la última aplicada NO se descarta (una devaluación
 * puede ser real): queda "pendiente", se sigue usando la anterior y se avisa a los administradores para que la
 * confirmen o descarten en el panel. Devuelve 'aplicada' o 'pendiente'.
 */
function guardar_cotizacion(string $tipo, float $valor, string $fuente, bool $personal = false): string
{
    if ($personal) {
        q(
            "INSERT INTO cotizaciones (usuario_id, tipo, valor_venta, fuente, creado_en, estado) VALUES (?, ?, ?, ?, NOW(), 'aplicada')",
            [usuario_id(), $tipo, $valor, $fuente]
        );
        return 'aplicada';
    }
    $anterior = fila("SELECT valor_venta FROM cotizaciones WHERE tipo = ? AND usuario_id IS NULL AND estado = 'aplicada' ORDER BY id DESC LIMIT 1", [$tipo]);
    $variacion = $anterior ? ($valor - (float) $anterior['valor_venta']) / (float) $anterior['valor_venta'] * 100 : 0.0;
    if ($anterior && abs($variacion) > COTIZACION_SALTO_PCT) {
        $pend = fila("SELECT id, valor_venta FROM cotizaciones WHERE tipo = ? AND usuario_id IS NULL AND estado = 'pendiente' ORDER BY id DESC LIMIT 1", [$tipo]);
        if ($pend && abs($valor - (float) $pend['valor_venta']) / (float) $pend['valor_venta'] < 0.01) {
            return 'pendiente';                    // el mismo valor que ya está esperando confirmación: no se repite ni se vuelve a avisar
        }
        q(
            "INSERT INTO cotizaciones (usuario_id, tipo, valor_venta, fuente, creado_en, estado, variacion_pct) VALUES (NULL, ?, ?, ?, NOW(), 'pendiente', ?)",
            [$tipo, $valor, $fuente, round(max(-9999, min(9999, $variacion)), 2)]
        );
        $id = (int) db()->lastInsertId();
        cotizacion_avisar_admins($tipo, (float) $anterior['valor_venta'], $valor, $variacion, $id);
        return 'pendiente';
    }
    q("INSERT INTO cotizaciones (usuario_id, tipo, valor_venta, fuente, creado_en, estado) VALUES (NULL, ?, ?, ?, NOW(), 'aplicada')", [$tipo, $valor, $fuente]);
    // Si había una pendiente de este tipo y el mercado volvió a valores normales, ya no tiene sentido confirmarla
    q("UPDATE cotizaciones SET estado = 'descartada', resuelta_en = NOW() WHERE tipo = ? AND usuario_id IS NULL AND estado = 'pendiente'", [$tipo]);
    return 'aplicada';
}

/** Avisa a cada administrador activo, por SUS canales, que hay una cotización esperando confirmación. */
function cotizacion_avisar_admins(string $tipo, float $anterior, float $nueva, float $variacion, int $cotizacionId): void
{
    $texto = sprintf(
        "La cotización del dólar %s pasó de %s a %s (%+.1f%%) y supera el %d%% de variación.\n"
        . "Se sigue usando la anterior hasta que la confirmes o la descartes en el panel (menú Dólar).",
        DOLAR_TIPOS[$tipo] ?? $tipo, fmt_monto($anterior), fmt_monto($nueva), $variacion, (int) COTIZACION_SALTO_PCT
    );
    $admins = sin_filtro('cotización: avisar a los administradores', fn() => filas("SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1"));
    foreach ($admins as $a) {
        try {
            con_usuario((int) $a['id'], fn() => notificar(
                'cotizacion_pendiente', 'Cotización del dólar pendiente de confirmar', $texto,
                [['tipo' => 'cotizacion', 'id' => $cotizacionId, 'dias' => 0, 'venc' => date('Y-m-d')]]
            ));
        } catch (Throwable $ex) {
            error_log('Aviso de cotización pendiente: ' . $ex->getMessage());
        }
    }
}

/** Cotizaciones automáticas esperando confirmación (para el panel del administrador). */
function cotizaciones_pendientes(): array
{
    return filas("SELECT * FROM cotizaciones WHERE usuario_id IS NULL AND estado = 'pendiente' ORDER BY id DESC");
}

/** Confirma (true) o descarta (false) una cotización pendiente. Devuelve la fila o null si no estaba pendiente. */
function resolver_cotizacion_pendiente(int $id, bool $confirmar, int $adminId): ?array
{
    $c = fila("SELECT * FROM cotizaciones WHERE id = ? AND usuario_id IS NULL AND estado = 'pendiente'", [$id]);
    if (!$c) {
        return null;
    }
    $n = q(
        "UPDATE cotizaciones SET estado = ?, resuelta_por = ?, resuelta_en = NOW()" . ($confirmar ? ', creado_en = NOW()' : '') . " WHERE id = ? AND estado = 'pendiente'",
        [$confirmar ? 'aplicada' : 'descartada', $adminId, $id]
    )->rowCount();
    return $n === 1 ? $c : null;
}

/**
 * Actualiza la cotización de un tipo de dólar (por defecto, el del usuario en contexto) desde la fuente
 * global elegida por el admin. Devuelve ['ok' => bool, 'valor' => ?float, 'mensaje' => string].
 */
function actualizar_cotizacion(?string $tipo = null): array
{
    $tipo = $tipo ?? cfg('dolar_tipo', 'blue');
    $fuente = cfg('dolar_fuente', 'dolarhoy');
    try {
        $valor = $fuente === 'dolarapi' ? cotizacion_desde_dolarapi($tipo) : cotizacion_desde_dolarhoy($tipo);
        $estado = guardar_cotizacion($tipo, $valor, $fuente);
        cfg_set('cotizacion_error', '');
        if ($estado === 'pendiente') {
            return ['ok' => true, 'valor' => $valor, 'mensaje' => "La cotización nueva ($valor) varía más del " . (int) COTIZACION_SALTO_PCT . '% respecto de la anterior: quedó PENDIENTE de confirmar por el administrador y se sigue usando la anterior.'];
        }
        return ['ok' => true, 'valor' => $valor, 'mensaje' => "Cotización actualizada: $valor ($tipo, $fuente)."];
    } catch (Throwable $ex) {
        $msg = $ex->getMessage() . ' — ' . date('d/m/Y H:i');
        cfg_set('cotizacion_error', $msg);
        error_log('Cotización: ' . $msg);
        return ['ok' => false, 'valor' => null, 'mensaje' => $msg];
    }
}

/** Tipos de dólar que usa algún usuario activo (el que no eligió ninguno usa "blue"). */
function tipos_dolar_en_uso(): array
{
    return sin_filtro('cron: tipos de dólar elegidos por los usuarios activos', function () {
        $tipos = [];
        foreach (filas(
            "SELECT DISTINCT uc.valor FROM usuario_config uc JOIN usuarios u ON u.id = uc.usuario_id
             WHERE uc.clave = 'dolar_tipo' AND u.activo = 1"
        ) as $f) {
            $tipos[$f['valor']] = true;
        }
        $sinElegir = (int) valor(
            "SELECT COUNT(*) FROM usuarios u WHERE u.activo = 1
             AND NOT EXISTS (SELECT 1 FROM usuario_config uc WHERE uc.usuario_id = u.id AND uc.clave = 'dolar_tipo')"
        );
        if ($sinElegir > 0 || !$tipos) {
            $tipos['blue'] = true;
        }
        return array_values(array_filter(array_keys($tipos), fn($t) => isset(DOLAR_TIPOS[$t])));
    });
}

/**
 * Para el cron: actualiza UNA vez cada tipo de dólar en uso (la cotización es compartida).
 * Deja el aviso de error global si alguno falla. Devuelve ['ok' => bool, 'mensajes' => string[]].
 */
function actualizar_cotizaciones_en_uso(): array
{
    $fuente = cfg('dolar_fuente', 'dolarhoy');
    $mensajes = [];
    $fallos = [];
    foreach (tipos_dolar_en_uso() as $tipo) {
        try {
            $valor = $fuente === 'dolarapi' ? cotizacion_desde_dolarapi($tipo) : cotizacion_desde_dolarhoy($tipo);
            $estado = guardar_cotizacion($tipo, $valor, $fuente);
            $mensajes[] = ($estado === 'pendiente' ? 'PENDIENTE DE CONFIRMAR' : 'OK') . " $tipo: $valor ($fuente)";
        } catch (Throwable $ex) {
            $fallos[] = "$tipo: " . $ex->getMessage();
            $mensajes[] = "ERROR $tipo: " . $ex->getMessage();
            error_log("Cotización $tipo: " . $ex->getMessage());
        }
    }
    cfg_set('cotizacion_error', $fallos ? implode(' | ', $fallos) . ' — ' . date('d/m/Y H:i') : '');
    return ['ok' => !$fallos, 'mensajes' => $mensajes];
}

/**
 * Última cotización del tipo de dólar del usuario en contexto: la más reciente entre la compartida
 * (automática) y las que cargó él a mano. Null si no hay ninguna.
 */
function cotizacion_vigente(): ?array
{
    return fila(
        'SELECT tipo, valor_venta, fuente, creado_en FROM cotizaciones
         WHERE tipo = ? AND estado = \'aplicada\' AND (usuario_id IS NULL OR usuario_id = {U}) ORDER BY id DESC LIMIT 1',
        [cfg('dolar_tipo', 'blue')]
    );
}

/** Solo el número de la cotización vigente (o null). */
function cotizacion_valor(): ?float
{
    $c = cotizacion_vigente();
    return $c ? (float) $c['valor_venta'] : null;
}
