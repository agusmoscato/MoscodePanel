<?php
/**
 * http_cabeceras.php — Cabeceras de seguridad y CSP en las respuestas reales de la app (por HTTP).
 *
 * La CSP no permite 'unsafe-inline': además de verificar la cabecera, se revisa que el HTML de las pantallas no traiga
 * nada que esa política bloquearía en el navegador (scripts en línea, atributos style="…", onclick="…", javascript:).
 *
 * No cubre los archivos estáticos (CSS, JS, imágenes, offline.html): esos reciben sus cabeceras desde
 * public_html/.htaccess, que el servidor embebido de PHP no interpreta; acá solo se verifica que el .htaccess las declare.
 */
declare(strict_types=1);

$CSP_ESPERADA = "default-src 'none'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self'; connect-src 'self'; "
    . "manifest-src 'self'; worker-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'";

/** Verifica las cabeceras comunes de una respuesta dinámica. */
$verificarCabeceras = function (string $donde, array $r, bool $https = false) use ($CSP_ESPERADA): void {
    $c = $r['cab'];
    $uno = fn(string $n) => $c[$n][0] ?? null;
    verificar("$donde: Content-Security-Policy", $CSP_ESPERADA . ($https ? '; upgrade-insecure-requests' : ''), $uno('content-security-policy'));
    verificar("$donde: X-Content-Type-Options", 'nosniff', $uno('x-content-type-options'));
    verificar("$donde: X-Frame-Options", 'DENY', $uno('x-frame-options'));
    verificar("$donde: Referrer-Policy", 'same-origin', $uno('referrer-policy'));
    verificar("$donde: Cross-Origin-Opener-Policy", 'same-origin', $uno('cross-origin-opener-policy'));
    verificar("$donde: Cross-Origin-Resource-Policy", 'same-origin', $uno('cross-origin-resource-policy'));
    verificar_contiene("$donde: Permissions-Policy apaga cámara/micrófono/ubicación", 'camera=(), microphone=(), geolocation=()', (string) $uno('permissions-policy'));
    verificar("$donde: sin X-Powered-By (no revela la versión de PHP)", false, isset($c['x-powered-by']));
    verificar_contiene("$donde: Cache-Control no-store", 'no-store', (string) $uno('cache-control'));
    verificar("$donde: HSTS " . ($https ? 'presente (HTTPS)' : 'ausente (HTTP)'), $https, isset($c['strict-transport-security']));
};

/** Lo que la CSP bloquearía en el navegador. Devuelve la lista de problemas encontrados (vacía si está bien). */
$violacionesCsp = function (string $html): array {
    $p = [];
    if (preg_match_all('~<script\b(?![^>]*\bsrc=)(?![^>]*type="application/(?:ld\+)?json")[^>]*>~i', $html, $m)) {
        $p[] = 'script en línea: ' . $m[0][0];
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
    if (preg_match_all('~<(?:script|link)\b[^>]*\b(?:src|href)="(https?:)?//~i', $html, $m)) {
        $p[] = 'recurso externo (la CSP solo permite self)';
    }
    return $p;
};

seccion('cabeceras en el login, en una pantalla del panel, en un 404 y en un CSV');
$verificarCabeceras('/login', (new Navegador())->get('/login'));
$u = nuevo_usuario_con_clave('cabeceras', 'Clave-Cabeceras-2026', 'admin');
nuevo_cliente_de_prueba('Cliente Cabeceras');
$nav = new Navegador();
$nav->login($u['usuario'], $u['clave']);
$verificarCabeceras('panel /clientes', $nav->get('/clientes'));
$r404 = $nav->get('/no-existe-esta-ruta');
verificar('ruta inexistente: 404', 404, $r404['codigo']);
$verificarCabeceras('404', $r404);
$csv = $nav->get('/exportar/clientes.csv');
verificar_contiene('CSV: Content-Type text/csv', 'text/csv', (string) ($csv['cab']['content-type'][0] ?? ''));
verificar_contiene('CSV: se descarga como adjunto', 'attachment', (string) ($csv['cab']['content-disposition'][0] ?? ''));
$verificarCabeceras('CSV', $csv);

seccion('detrás del proxy con HTTPS: HSTS y upgrade-insecure-requests; la cookie de sesión sale Secure');
$https = new Navegador();
$rHttps = $https->get('/login', ['X-Forwarded-Proto: https']);
$verificarCabeceras('/login por HTTPS', $rHttps, true);
verificar_contiene('cookie de sesión con Secure por HTTPS', 'secure', strtolower(implode(' | ', $rHttps['cab']['set-cookie'] ?? [])));

seccion('el HTML de las pantallas no trae nada que la CSP bloquearía');
$pantallas = ['/login', '/', '/clientes', '/clientes/nuevo', '/cobros', '/vencimientos', '/cuotas', '/precios', '/reportes', '/dolar',
    '/configuracion', '/mi-cuenta', '/mi-cuenta/dos-pasos', '/notificaciones', '/actividad', '/usuarios', '/usuarios/nuevo', '/feriados', '/backups',
    '/elegir-cliente?para=pago', '/servicios', '/dominios'];
fijar_usuario($u['id']);
$cid = (int) valor('SELECT id FROM clientes WHERE usuario_id = {U} LIMIT 1');
$pantallas[] = '/clientes/' . $cid;
$pantallas[] = '/clientes/' . $cid . '/resumen';
$pantallas[] = '/clientes/' . $cid . '/servicios/nuevo';
$pantallas[] = '/clientes/' . $cid . '/dominios/nuevo';
$pantallas[] = '/clientes/' . $cid . '/cuotas/nueva';
$pantallas[] = '/pagos/nuevo?cliente_id=' . $cid;
foreach ($pantallas as $ruta) {
    $r = $ruta === '/login' ? (new Navegador())->get($ruta) : $nav->get($ruta);
    if ($r['codigo'] !== 200) {
        verificar("$ruta responde 200", 200, $r['codigo']);
        continue;
    }
    verificar("$ruta: sin contenido en línea que bloquee la CSP", [], $violacionesCsp($r['cuerpo']));
}

seccion('archivos estáticos: el .htaccess les pone sus cabeceras (no se puede probar con el servidor embebido)');
$htaccess = (string) file_get_contents(RAIZ_PROYECTO . '/public_html/.htaccess');
foreach (['X-Content-Type-Options "nosniff"', 'X-Frame-Options "DENY"', 'Strict-Transport-Security', 'Header unset X-Powered-By', 'Content-Security-Policy'] as $directiva) {
    verificar_contiene(".htaccess declara $directiva", $directiva, $htaccess);
}
verificar_contiene('.htaccess no lista carpetas', 'Options -Indexes', $htaccess);
