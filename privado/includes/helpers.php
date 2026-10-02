<?php
/**
 * helpers.php — Utilidades generales: escape, formato, mensajes flash, redirecciones.
 */
declare(strict_types=1);

/** Escapa para HTML (siempre usar al imprimir datos). */
function e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Las URLs (url, url_accion, url_exportar…) están en rutas.php

/** Redirige. Los destinos son rutas absolutas del sitio (las arma url()) o URLs completas. */
function redirigir(string $destino): never
{
    header('Location: ' . $destino);
    exit;
}

// --- Mensajes flash (se muestran una vez en la próxima página) ---
function flash(string $tipo, string $mensaje): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function flash_obtener(): array
{
    $m = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $m;
}

// --- Entrada ---
/** Valor de texto del POST, sin espacios sobrantes. */
function post(string $clave, string $porDefecto = ''): string
{
    $v = $_POST[$clave] ?? $porDefecto;
    return is_string($v) ? trim($v) : $porDefecto;
}

/** Valor de texto del GET. */
function get(string $clave, string $porDefecto = ''): string
{
    $v = $_GET[$clave] ?? $porDefecto;
    return is_string($v) ? trim($v) : $porDefecto;
}

const MONTO_MAX = 9999999999.99;      // lo que entra en DECIMAL(12,2)

/**
 * Lee un monto escrito por una persona o enviado por un formulario. Devuelve null si no es un monto válido
 * (vacío, letras, notación científica, negativo, más de 2 decimales o más que MONTO_MAX).
 * Acepta "1234.56", "1234,56", "1.234,56" y "1,234.56". Un único separador seguido de exactamente 3 dígitos
 * (y 1 a 3 antes) es de miles: "1.234" = 1234 y "1,234" = 1234.
 */
function parsear_monto(string $texto): ?float
{
    $t = str_replace([' ', "\u{00A0}"], '', trim($texto));
    if ($t === '' || !preg_match('/^[0-9][0-9.,]*$/', $t) || str_ends_with($t, '.') || str_ends_with($t, ',')) {
        return null;
    }
    $punto = strrpos($t, '.');
    $coma = strrpos($t, ',');
    if ($punto !== false && $coma !== false) {
        $dec = max($punto, $coma);
        $miles = $dec === $punto ? ',' : '.';           // el otro separador solo puede agrupar de a 3 dígitos
        $parteEntera = substr($t, 0, $dec);
        if (!preg_match('/^\d{1,3}(?:' . preg_quote($miles, '/') . '\d{3})+$/', $parteEntera)) {
            return null;
        }
        $ent = str_replace($miles, '', $parteEntera);
        $frac = substr($t, $dec + 1);
    } elseif ($punto !== false || $coma !== false) {
        $sep = $punto !== false ? '.' : ',';
        $pos = (int) max($punto, $coma);
        if (substr_count($t, $sep) > 1) {
            if (!preg_match('/^\d{1,3}(?:' . preg_quote($sep, '/') . '\d{3})+$/', $t)) {
                return null;            // agrupación de miles mal formada (ej. 1,,5 o 12.34.567)
            }
            $ent = str_replace($sep, '', $t);
            $frac = '';
        } else {
            $ent = substr($t, 0, $pos);
            $frac = substr($t, $pos + 1);
            if (strlen($frac) === 3 && strlen($ent) >= 1 && strlen($ent) <= 3) {
                $ent .= $frac;
                $frac = '';
            }
        }
    } else {
        $ent = $t;
        $frac = '';
    }
    if ($ent === '' || !ctype_digit($ent) || ($frac !== '' && !ctype_digit($frac)) || strlen($frac) > 2 || strlen(ltrim($ent, '0')) > 10) {
        return null;
    }
    $n = round((float) ($ent . '.' . str_pad($frac, 2, '0')), 2);
    return $n > MONTO_MAX ? null : $n;
}

/** Monto como float; 0.0 si no es válido (los chequeos "mayor a cero" de cada acción lo rechazan). */
function a_decimal(string $texto): float
{
    return parsear_monto($texto) ?? 0.0;
}

/** Porcentaje (puede ser negativo) entre -99,99 y 1000; 0.0 si no es válido. */
function a_porcentaje(string $texto): float
{
    $t = trim($texto);
    $neg = str_starts_with($t, '-');
    $n = parsear_monto(ltrim($t, '-+'));
    if ($n === null) {
        return 0.0;
    }
    $n = $neg ? -$n : $n;
    return ($n >= -99.99 && $n <= 1000) ? $n : 0.0;
}

/** Campos del POST que nunca se guardan en la sesión (contraseñas, tokens, claves). */
function campos_sensibles(array $post): array
{
    $r = [];
    foreach (array_keys($post) as $k) {
        if (preg_match('/^(csrf|nonce|actual|nueva2?|clave.*|.*_clave|.*token.*|.*secret.*|codigo.*|totp.*)$/i', (string) $k)) {
            $r[] = $k;
        }
    }
    return $r;
}

/** Email válido y razonable (largo, sin comillas ni espacios en la parte local). */
function email_valido(string $email): bool
{
    return strlen($email) <= 160 && (bool) preg_match('/^[A-Za-z0-9._%+\-]{1,64}@[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*\.[A-Za-z]{2,24}$/', $email)
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Teléfono: solo dígitos, espacios y + ( ) - . (hasta 40 caracteres). */
function telefono_valido(string $tel): bool
{
    return $tel === '' || (strlen($tel) <= 40 && (bool) preg_match('/^[0-9+()\-.\s]+$/', $tel));
}

/**
 * Valida largos máximos de campos de texto. $campos = ['Nombre' => [$valor, 160], ...].
 * Devuelve el mensaje de error del primero que se pasa, o null.
 */
function largo_excedido(array $campos): ?string
{
    foreach ($campos as $nombre => [$valor, $max]) {
        if (mb_strlen((string) $valor) > $max) {
            return "$nombre es demasiado largo (máximo $max caracteres).";
        }
    }
    return null;
}

/** Formulario de un solo uso (evita pagos o planes duplicados por doble envío o recarga). */
function nonce_campo(): string
{
    $n = bin2hex(random_bytes(16));
    $_SESSION['nonces'] = array_slice(array_merge($_SESSION['nonces'] ?? [], [$n => time()]), -60, null, true);
    return '<input type="hidden" name="nonce" value="' . $n . '">';
}

/** Consume el nonce del POST: true la primera vez, false si ya se usó o no existe. */
function nonce_consumir(): bool
{
    $n = (string) ($_POST['nonce'] ?? '');
    if ($n === '' || !isset($_SESSION['nonces'][$n])) {
        return false;
    }
    unset($_SESSION['nonces'][$n]);
    return true;
}

/** ¿Es una fecha válida AAAA-MM-DD? */
function fecha_valida(string $f): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $f);
    return $d !== false && $d->format('Y-m-d') === $f && $d->format('Y') >= '2000' && $d->format('Y') <= '2100';
}

/**
 * Para repoblar formularios tras un error: devuelve el valor enviado
 * antes (guardado en sesión) o el valor actual del registro.
 */
function viejo(string $campo, $porDefecto = ''): string
{
    if (isset($_SESSION['viejo']) && array_key_exists($campo, $_SESSION['viejo'])) {
        return (string) $_SESSION['viejo'][$campo];
    }
    return (string) ($porDefecto ?? '');
}

/** Guarda el POST para repoblar el formulario y vuelve a él con un error. */
function volver_con_error(string $mensaje, string $destino): never
{
    $_SESSION['viejo'] = array_diff_key($_POST, array_flip(campos_sensibles($_POST)));   // nunca claves ni tokens en la sesión
    flash('error', $mensaje);
    redirigir($destino);
}

// --- Formato ---
const MONEDAS = ['ARS' => '$', 'USD' => 'US$'];

/** 1234.5 → "$ 1.234,50" / "US$ 1.234,50" */
function fmt_monto($monto, string $moneda = 'ARS'): string
{
    return (MONEDAS[$moneda] ?? $moneda) . ' ' . number_format((float) $monto, 2, ',', '.');
}

function fmt_fecha(?string $ymd): string
{
    if (!$ymd) {
        return '—';
    }
    $d = DateTime::createFromFormat('Y-m-d', substr($ymd, 0, 10));
    return $d ? $d->format('d/m/Y') : $ymd;
}

function fmt_fecha_hora(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $d = new DateTime($dt);
    return $d->format('d/m/Y H:i');
}

/** Días desde hoy hasta la fecha (negativo si ya pasó). */
function dias_hasta(string $ymd): int
{
    $hoy = new DateTime('today');
    $f = new DateTime($ymd);
    return (int) $hoy->diff($f)->format('%r%a');
}

/** Clase CSS según urgencia del vencimiento. */
function clase_urgencia(int $dias): string
{
    if ($dias < 0) {
        return 'urg-vencido';
    }
    if ($dias <= 7) {
        return 'urg-alta';
    }
    if ($dias <= 15) {
        return 'urg-media';
    }
    return 'urg-baja';
}

function texto_dias(int $dias): string
{
    if ($dias < 0) {
        return 'vencido hace ' . abs($dias) . ' d';
    }
    if ($dias === 0) {
        return 'vence hoy';
    }
    return 'en ' . $dias . ' d';
}

/** Suma por moneda: [['moneda'=>'ARS','x'=>10], ...] → ['ARS'=>10.0,'USD'=>0.0] */
function por_moneda(array $filas, string $campo): array
{
    $r = ['ARS' => 0.0, 'USD' => 0.0];
    foreach ($filas as $f) {
        $r[$f['moneda']] = ($r[$f['moneda']] ?? 0.0) + (float) $f[$campo];
    }
    return $r;
}

/** Total en ARS de un ['ARS'=>x,'USD'=>y] con la cotización dada (null si falta cotización y hay USD). */
function a_ars(array $porMoneda, ?float $cotizacion): ?float
{
    $usd = (float) ($porMoneda['USD'] ?? 0);
    if ($usd > 0.004 && !$cotizacion) {
        return null;
    }
    return round((float) ($porMoneda['ARS'] ?? 0) + $usd * (float) $cotizacion, 2);
}

/** Muestra "$ x · US$ y" omitiendo monedas en cero. */
function fmt_por_moneda(array $porMoneda): string
{
    $partes = [];
    foreach (['ARS', 'USD'] as $m) {
        if (abs((float) ($porMoneda[$m] ?? 0)) > 0.004) {
            $partes[] = fmt_monto($porMoneda[$m], $m);
        }
    }
    return $partes ? implode(' · ', $partes) : fmt_monto(0, 'ARS');
}

/** Genera un token aleatorio (hexadecimal). */
function token_aleatorio(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}


// --- Pedidos salientes (cURL) ---

/** Opciones comunes de todo pedido saliente: solo HTTPS, verificación SSL completa, timeouts y pocas redirecciones. */
function curl_opciones_base(int $timeout = 12, bool $seguirRedirecciones = false): array
{
    return [
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => $seguirRedirecciones,
        CURLOPT_MAXREDIRS      => 3,
    ];
}

/**
 * Ejecuta un cURL cortando la descarga si la respuesta pasa de $maxBytes (1 MB por defecto).
 * Devuelve [cuerpo|false, código HTTP, mensaje de error].
 */
function curl_limitado($ch, int $maxBytes = 1048576): array
{
    $buf = '';
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, $datos) use (&$buf, $maxBytes) {
        $buf .= $datos;
        return strlen($buf) > $maxBytes ? 0 : strlen($datos);      // devolver menos de lo recibido aborta la descarga
    });
    $ok = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    if ($ok === false) {
        return [false, $codigo, strlen($buf) > $maxBytes ? 'la respuesta es demasiado grande' : $error];
    }
    return [$buf, $codigo, ''];
}

/** Variación porcentual entre dos montos para el historial (null si no se puede calcular o no entra en DECIMAL(7,2)). */
function porcentaje_historial(float $anterior, float $nuevo): ?float
{
    if ($anterior <= 0) {
        return null;
    }
    $p = round(($nuevo / $anterior - 1) * 100, 2);
    return abs($p) <= 99999.99 ? $p : null;
}

/**
 * Texto de un error para mostrarlo en pantalla: los RuntimeException son mensajes propios y pensados para la persona;
 * cualquier otra excepción (base de datos, PHP) va al log y se muestra un texto genérico (nada de SQL ni rutas).
 */
function mensaje_seguro(Throwable $ex, string $generico = 'Ocurrió un error. Intentá de nuevo.'): string
{
    if ($ex instanceof RuntimeException) {
        return $ex->getMessage();
    }
    error_log(get_class($ex) . ': ' . $ex->getMessage() . ' en ' . basename($ex->getFile()) . ':' . $ex->getLine());
    return $generico;
}
