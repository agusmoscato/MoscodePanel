<?php
/**
 * portal.php — Portal del cliente: ve su deuda y sus vencimientos con un link secreto, sin usuario.
 *   /portal/<token>                      → estado de cuenta            (también /portal.php?t=<token>, links viejos)
 *   /portal/<token>/pagar/<cargo>        → redirige al link de pago de Mercado Pago de ese cargo
 *
 * Multiusuario: el token identifica al cliente y, con él, a su dueño (el usuario del panel): todo lo que se muestra
 * (nombre, alias, cobros, link de Mercado Pago) es el de ese usuario.
 *
 * Seguridad: el token es largo y aleatorio (192 bits), se puede revocar desde el panel, solo
 * muestra datos de ese cliente, y los intentos con tokens inválidos se limitan por IP.
 */
declare(strict_types=1);

$raiz = RAIZ_PRIVADA;

// El token viaja en la URL: que no se filtre por Referer ni quede en cachés.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function portal_error(int $codigo, string $mensaje): never
{
    http_response_code($codigo);
    ?><!doctype html>
<html lang="es-AR" data-theme="light">
<head><?= ui_head('Portal de clientes', false, false) ?></head>
<body class="login-pagina">
<main class="login-caja">
    <div class="login-card">
        <div class="pad-8-0 vacio-est"><?= icono('circle-alert') ?><p><strong>Portal de clientes</strong></p><p><?= e($mensaje) ?></p></div>
    </div>
</main>
</body>
</html><?php
    exit;
}

// Límite de intentos con token inválido (reutiliza la tabla de intentos de login)
$ip = ip_cliente();
$desde = date('Y-m-d H:i:s', time() - 900);
if ((int) valor("SELECT COUNT(*) FROM login_intentos WHERE usuario = 'portal' AND exitoso = 0 AND ip = ? AND creado_en >= ?", [$ip, $desde]) >= 20) {
    portal_error(429, 'Demasiados intentos. Probá de nuevo en unos minutos.');
}

$token = (string) ($_GET['t'] ?? '');
// Buscar al dueño del token es la única consulta global: después todo corre en el contexto de ese usuario
$c = preg_match('/^[a-f0-9]{48}$/', $token)
    ? sin_filtro('portal: el token identifica al cliente y a su dueño', fn() => fila(
        'SELECT c.* FROM clientes c JOIN usuarios u ON u.id = c.usuario_id WHERE c.portal_token = ? AND u.activo = 1',
        [$token]
    ))
    : null;
if ($c) {
    fijar_usuario((int) $c['usuario_id']);
    if (cfg('portal_activo', '0') !== '1') {
        $c = null;            // el dueño desactivó el portal: se comporta como un link inválido
    }
}
if (!$c) {
    q("INSERT INTO login_intentos (ip, usuario, exitoso, creado_en) VALUES (?, 'portal', 0, NOW())", [$ip]);
    portal_error(404, 'El link no es válido o fue desactivado. Pedile uno nuevo a tu proveedor.');
}
$id = (int) $c['id'];

// Ir a pagar con Mercado Pago (solo cargos de este cliente)
if (isset($_GET['pagar'])) {
    $cargoId = (int) $_GET['pagar'];
    if (!mp_activo() || !fila('SELECT id FROM cargos WHERE usuario_id = {U} AND id = ? AND cliente_id = ?', [$cargoId, $id])) {
        portal_error(404, 'No se puede generar el pago de este cargo.');
    }
    // Límite propio de /pagar: cada pedido puede crear una preferencia en Mercado Pago
    if ((int) valor("SELECT COUNT(*) FROM login_intentos WHERE usuario = 'portal_pagar' AND ip = ? AND creado_en >= ?", [$ip, $desde]) >= 30) {
        portal_error(429, 'Demasiados pedidos. Probá de nuevo en unos minutos.');
    }
    q("INSERT INTO login_intentos (ip, usuario, exitoso, creado_en) VALUES (?, 'portal_pagar', 1, NOW())", [$ip]);
    try {
        $link = mp_link_para_cargo($cargoId);
    } catch (Throwable $ex) {
        error_log('Portal MP: ' . $ex->getMessage());
        portal_error(500, 'No se pudo generar el link de pago. Probá más tarde o pagá por transferencia.');
    }
    // Solo se redirige a Mercado Pago (https y un dominio de Mercado Pago / Mercado Libre)
    $h = parse_url($link);
    if (($h['scheme'] ?? '') !== 'https' || !preg_match('/(^|\.)(mercadopago|mercadolibre)\.[a-z.]{2,12}$/i', (string) ($h['host'] ?? ''))) {
        error_log('Portal MP: destino de pago inesperado');
        portal_error(500, 'No se pudo generar el link de pago. Probá más tarde o pagá por transferencia.');
    }
    header('Location: ' . $link);
    exit;
}

$cot = cotizacion_vigente();
$cotValor = $cot ? (float) $cot['valor_venta'] : null;
$pend = cargos_pendientes($id);
$deuda = deuda_cliente($id);
$totalArs = a_ars($deuda, $cotValor);
$tieneDeuda = $deuda['ARS'] > 0.004 || $deuda['USD'] > 0.004;
$pagos = filas('SELECT fecha, monto, moneda, medio FROM pagos WHERE usuario_id = {U} AND cliente_id = ? AND anulado_en IS NULL ORDER BY fecha DESC, id DESC LIMIT 10', [$id]);
$venc = [];
foreach (filas("SELECT dominio AS nombre, fecha_vencimiento AS fecha FROM dominios WHERE usuario_id = {U} AND cliente_id = ? AND estado = 'activo'", [$id]) as $f) {
    $venc[] = $f + ['tipo' => 'Dominio'];
}
foreach (filas("SELECT nombre, proximo_vencimiento AS fecha FROM servicios WHERE usuario_id = {U} AND cliente_id = ? AND estado = 'activo' AND tipo_cobro = 'anual' AND proximo_vencimiento IS NOT NULL", [$id]) as $f) {
    $venc[] = $f + ['tipo' => 'Servicio anual'];
}
usort($venc, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));
$aVencer = cuotas_a_vencer($id);
$mpOk = mp_activo();
$saludo = $c['contacto'] !== '' ? $c['contacto'] : $c['nombre'];
$proveedor = cfg('nombre_propio') !== '' ? cfg('nombre_propio') : 'Estado de cuenta';
?><!doctype html>
<html lang="es-AR" data-theme="light">
<head>
<?= ui_head('Estado de cuenta — ' . $c['nombre'], false, false) ?>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</head>
<body class="portal">
<header class="portal-cab">
    <div class="portal-cab-in">
        <span class="logo"><img class="logo-img" src="<?= e(asset('assets/img/moscode.svg')) ?>" alt="" width="32" height="32"><span class="portal-prov"><?= e($proveedor) ?></span></span>
        <button class="btn sec chico no-imprimir" type="button" data-imprimir aria-label="Imprimir o guardar PDF"><?= icono('printer', 'chico') ?><span class="btn-txt">Imprimir / PDF</span></button>
    </div>
</header>

<main class="portal-main">
    <section class="card hero portal-hero">
        <div class="hero-etq">Hola, <?= e($saludo) ?></div>
        <?php if ($tieneDeuda): ?>
            <div class="mt-12 stat-etq">Saldo pendiente</div>
            <div class="hero-total"><?= montos_html($deuda) ?></div>
            <?php if ($totalArs !== null && $deuda['USD'] > 0.004): ?>
                <div class="hero-sub">≈ <?= monto_html($totalArs) ?> con el dólar a <?= monto_html($cotValor) ?> (<?= e(fmt_fecha_hora($cot['creado_en'])) ?>)</div>
            <?php endif; ?>
        <?php else: ?>
            <div class="portal-aldia"><?= icono('circle-check', 'grande') ?><div><strong>Estás al día.</strong><div class="suave">No tenés saldo pendiente. ¡Gracias!</div></div></div>
        <?php endif; ?>
        <div class="mt-12 suave"><?= e($c['nombre']) ?> · al <span class="mono"><?= e(fecha_corta(date('Y-m-d'))) ?></span></div>
    </section>

    <?php if ($tieneDeuda && cfg('alias_cbu') !== ''): ?>
    <section class="card">
        <h2 class="card-tit">Para pagar por transferencia</h2>
        <div class="link-copiar">
            <input class="campo-link alias-grande" readonly value="<?= e(cfg('alias_cbu')) ?>" data-seleccionar aria-label="Alias o CBU">
            <button type="button" class="btn sec icono no-imprimir" data-copiar="<?= e(cfg('alias_cbu')) ?>" aria-label="Copiar alias"><?= icono('copy') ?></button>
        </div>
        <?php if (cfg('nombre_propio') !== ''): ?><div class="mt-8 suave">A nombre de <?= e(cfg('nombre_propio')) ?></div><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($pend): ?>
    <section aria-labelledby="pend-tit">
        <h2 id="pend-tit" class="portal-sec">Cargos pendientes</h2>
        <?php foreach ($pend as $cg): $dv = dias_hasta($cg['fecha_vencimiento']); ?>
            <article class="card portal-cargo">
                <div class="portal-cargo-cab">
                    <div class="item-tit"><?= e($cg['concepto']) ?></div>
                    <div class="portal-cargo-monto"><?= monto_html($cg['saldo'], $cg['moneda']) ?></div>
                </div>
                <div class="item-sub">
                    <span>Vence <span class="mono"><?= e(fecha_corta($cg['fecha_vencimiento'])) ?></span></span>
                    <?= $dv < 0 ? chip('vencido ' . texto_relativo($dv), 'bad') : ($dv <= 7 ? chip('vence ' . texto_relativo($dv), 'warn') : '') ?>
                    <?= $cg['estado'] === 'parcial' ? chip('pago parcial', 'info') : '' ?>
                </div>
                <?php if ($mpOk): ?>
                    <a class="btn bloque portal-pagar no-imprimir" href="<?= e(url_base('/portal/' . $token . '/pagar/' . (int) $cg['id'])) ?>"><?= icono('wallet') ?>Pagar con Mercado Pago</a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($aVencer): ?>
    <section aria-labelledby="cuo-tit">
        <h2 id="cuo-tit" class="portal-sec">Próximas cuotas</h2>
        <?php foreach ($aVencer as $cg): ?>
            <article class="card portal-cargo">
                <div class="portal-cargo-cab">
                    <div class="item-tit"><?= e($cg['concepto']) ?></div>
                    <div class="portal-cargo-monto"><?= monto_html($cg['saldo'], $cg['moneda']) ?></div>
                </div>
                <div class="item-sub"><span>Vence <span class="mono"><?= e(fecha_corta($cg['fecha_vencimiento'])) ?></span></span><?= chip('a vencer', 'info') ?></div>
            </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($venc): ?>
    <section aria-labelledby="venc-tit">
        <h2 id="venc-tit" class="portal-sec">Próximos vencimientos</h2>
        <ul class="timeline">
            <?php foreach ($venc as $v): $d = dias_hasta($v['fecha']); ?>
                <li class="tl-item <?= e(tipo_urgencia($d)) ?>">
                    <div class="card">
                        <div class="item-main">
                            <div class="item-tit mono"><?= e($v['nombre']) ?></div>
                            <div class="item-sub"><?= chip($v['tipo'], 'mute') ?><span class="mono"><?= e(fecha_corta($v['fecha'])) ?></span></div>
                        </div>
                        <div class="item-der"><?= chip_vencimiento($d) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($pagos): ?>
    <section class="card" aria-labelledby="pag-tit">
        <h2 id="pag-tit" class="card-tit">Últimos pagos</h2>
        <ul class="lista">
            <?php foreach ($pagos as $p): ?>
                <li class="item">
                    <div class="item-main"><div class="item-tit mono"><?= e(fecha_corta($p['fecha'])) ?></div><div class="item-sub"><?= e(MEDIOS_PAGO[$p['medio']] ?? $p['medio']) ?></div></div>
                    <div class="item-der"><?= monto_html($p['monto'], $p['moneda']) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <footer class="portal-pie">Estado de cuenta generado el <?= e(fecha_corta(date('Y-m-d'))) ?></footer>
</main>

<div class="toasts" id="toasts" aria-live="polite"></div>
</body>
</html>
