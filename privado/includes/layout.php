<?php
/**
 * layout.php — Estructura común de las pantallas internas.
 * Recibe: $titulo, $contenido, $pagina, $usuario.
 *   Escritorio (≥1024px): sidebar fijo colapsable.
 *   Celular: topbar + barra inferior + drawer (el mismo sidebar) + botón flotante de acciones rápidas.
 */
$cot = cotizacion_vigente();
$cotFalla = cfg('cotizacion_error') !== '';
$nombreDolar = DOLAR_TIPOS[cfg('dolar_tipo', 'blue')] ?? 'Dólar';

// Secciones del menú: [página, texto, ícono]
$grupos = [
    'Principal' => [
        ['dashboard', 'Inicio', 'house'],
        ['clientes', 'Clientes', 'users'],
        ['cargos', 'Cobros', 'receipt'],
        ['vencimientos', 'Vencimientos', 'calendar-clock'],
        ['redes', 'Redes', 'megaphone'],
    ],
    'Gestión' => [
        ['precios', 'Precios', 'tag'],
        ['reportes', 'Reportes', 'chart-column'],
        ['cotizacion', 'Dólar', 'circle-dollar-sign'],
    ],
    'Sistema' => [
        ['mi_cuenta', 'Mi cuenta', 'circle-user'],
        ['configuracion', 'Configuración', 'settings'],
        ['feriados', 'Feriados', 'calendar-days'],
        ['notificaciones', 'Notificaciones', 'bell'],
    ],
];
// Ventas en cuotas, junto a los cobros
array_splice($grupos['Principal'], 3, 0, [['planes', 'Cuotas', 'credit-card']]);
if (es_admin($usuario)) {
    $grupos['Sistema'][] = ['usuarios', 'Usuarios', 'users'];
    $grupos['Sistema'][] = ['actividad', 'Actividad', 'list-checks'];
    $grupos['Sistema'][] = ['backups', 'Backups', 'download'];
}
// Las pantallas hijas resaltan la sección a la que pertenecen
$seccion = match ($pagina) {
    'cliente', 'cliente_form', 'servicio_form', 'dominio_form', 'resumen_cuenta', 'elegir_cliente' => 'clientes',
    'pago_form' => 'cargos',
    'plan', 'plan_form', 'plan_editar' => 'planes',
    'usuario_form' => 'usuarios',
    'dos_pasos' => 'mi_cuenta',
    'publicacion', 'publicacion_form' => 'redes',
    'pago_anular' => 'clientes',
    default => $pagina,
};
$principales = ['dashboard', 'clientes', 'cargos', 'vencimientos'];

// Acciones rápidas: si estamos dentro de la ficha de un cliente, ya van con ese cliente
$ctxCliente = match (true) {
    in_array($pagina, ['cliente', 'resumen_cuenta'], true) => (int) ($_GET['id'] ?? 0),
    in_array($pagina, ['pago_form', 'servicio_form', 'dominio_form'], true) => (int) ($_GET['cliente_id'] ?? 0),
    default => 0,
};
$destino = fn(string $para, string $pagForm) => $ctxCliente
    ? url($pagForm, ['cliente_id' => $ctxCliente])
    : url('elegir_cliente', ['para' => $para]);
$rapidas = [
    // [texto, ícono, link (si no hay JS), atributos extra]; "Registrar pago" abre el bottom sheet de pago
    ['Registrar pago', 'wallet', $destino('pago', 'pago_form'), ' data-abrir="pago"' . ($ctxCliente ? ' data-cliente="' . $ctxCliente . '"' : '')],
    ['Nueva venta en cuotas', 'credit-card', $ctxCliente ? url('plan_form', ['cliente_id' => $ctxCliente]) : url('elegir_cliente', ['para' => 'plan']), ''],
    ['Nuevo cliente', 'user-plus', url('cliente_form'), ''],
    ['Nuevo servicio', 'layers', $destino('servicio', 'servicio_form'), ''],
    ['Nuevo dominio', 'globe', $destino('dominio', 'dominio_form'), ''],
    ['Nueva publicación', 'megaphone', url('publicacion_form'), ' data-abrir="publicacion"'],
];
$flash = flash_obtener();
// Botón "ojito" (ocultar montos): en la topbar (celular) y en la barra de arriba del contenido (escritorio).
// El estado lo aplica tema.js antes de pintar (clase montos-ocultos en <html>); app.js lo alterna.
$ojito = fn(string $clase) => '<button type="button" class="btn fantasma icono ojito ' . $clase . '" data-montos-toggle aria-pressed="false" aria-label="Ocultar montos" title="Ocultar montos">'
    . icono('eye', 'ojo-abierto') . icono('eye-off', 'ojo-cerrado') . '</button>';
?><!doctype html>
<html lang="es-AR" data-theme="dark" data-tema-usuario="<?= e(cfg('tema')) ?>" data-montos-al-abrir="<?= cfg('montos_ocultos_al_abrir', '0') === '1' ? '1' : '0' ?>">
<head>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<?= ui_head($titulo . ' — Moscode') ?>
<?php if ($pagina === 'dos_pasos'): ?><script src="<?= e(asset('assets/vendor/qrcode/qrcode.js')) ?>" defer></script><?php endif; ?>
<?php if ($pagina === 'reportes'): ?><script src="<?= e(asset('assets/vendor/chartjs/chart.umd.min.js')) ?>" defer></script><?php endif; ?>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</head>
<body<?= in_array($pagina, ['cliente_form', 'servicio_form', 'dominio_form', 'pago_form', 'configuracion', 'precios', 'plan_form', 'plan_editar', 'usuario_form', 'mi_cuenta', 'publicacion_form'], true) ? ' class="sin-fab"' : '' ?>>

<aside class="sidebar" id="sidebar" aria-label="Menú principal">
    <div class="sb-cab">
        <a class="logo" href="<?= e(url('dashboard')) ?>" aria-label="Moscode, ir al inicio">
            <img class="logo-img" src="<?= e(asset('assets/img/moscode.svg')) ?>" alt="" width="32" height="32"><span class="logo-txt">moscode</span>
        </a>
        <button type="button" class="btn fantasma icono chico sb-colapsar" data-sidebar-colapsar aria-label="Colapsar o expandir el menú" title="Colapsar menú">
            <?= icono('panel-left-close') ?>
        </button>
        <button type="button" class="btn fantasma icono sb-cerrar" data-cerrar="drawer" aria-label="Cerrar menú"><?= icono('x') ?></button>
    </div>

    <div class="sb-accion">
        <button type="button" class="btn bloque" data-abrir="sheet" aria-haspopup="dialog" title="Acciones rápidas">
            <?= icono('plus') ?><span class="btn-txt">Acción rápida</span>
        </button>
    </div>

    <nav class="sb-nav">
        <?php foreach ($grupos as $nombreGrupo => $items): ?>
            <div class="sb-titulo"><?= e($nombreGrupo) ?></div>
            <?php foreach ($items as [$pg, $texto, $ic]): ?>
                <a class="nav-item<?= $seccion === $pg ? ' activo' : '' ?>" href="<?= e(url($pg)) ?>" title="<?= e($texto) ?>"<?= $seccion === $pg ? ' aria-current="page"' : '' ?>>
                    <?= icono($ic) ?><span class="nav-txt"><?= e($texto) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sb-pie">
        <a class="cotiz<?= $cotFalla ? ' alerta-cot' : '' ?>" href="<?= e(url('cotizacion')) ?>" title="Cotización del dólar">
            <?= icono($cotFalla ? 'triangle-alert' : 'circle-dollar-sign') ?>
            <span class="cotiz-dato">
                <span class="cotiz-etq">Dólar <?= e(mb_strtolower($nombreDolar)) ?></span>
                <span class="cotiz-val"><?= $cot ? monto_html($cot['valor_venta']) : 'sin dato' ?></span>
                <span class="cotiz-hora"><?= $cot ? e(hace_cuanto($cot['creado_en'])) . ($cotFalla ? ' · falló la última' : '') : 'Cargala en Dólar' ?></span>
            </span>
        </a>
        <button type="button" class="nav-item" data-tema-toggle aria-label="Cambiar entre tema oscuro y claro">
            <span class="solo-oscuro nav-ico-txt"><?= icono('sun') ?><span class="nav-txt">Tema claro</span></span>
            <span class="solo-claro nav-ico-txt"><?= icono('moon') ?><span class="nav-txt">Tema oscuro</span></span>
        </button>
        <form method="post" action="<?= e(url_base('/salir')) ?>">
            <?= csrf_campo() ?>
            <button type="submit" class="nav-item" title="Cerrar sesión"><?= icono('log-out') ?><span class="nav-txt">Salir (<?= e($usuario['usuario']) ?>)</span></button>
        </form>
    </div>
</aside>
<div class="scrim" aria-hidden="true"></div>

<div class="contenido-wrap">
    <header class="topbar">
        <a class="logo" href="<?= e(url('dashboard')) ?>" aria-label="Moscode, ir al inicio"><img class="logo-img" src="<?= e(asset('assets/img/moscode.svg')) ?>" alt="" width="30" height="30"></a>
        <div class="topbar-titulo"><?= e($titulo) ?></div>
        <?= $ojito('ojito-topbar') ?>
        <a class="cotiz<?= $cotFalla ? ' alerta-cot' : '' ?>" href="<?= e(url('cotizacion')) ?>" aria-label="Dólar <?= e($nombreDolar) ?>">
            <?= icono($cotFalla ? 'triangle-alert' : 'circle-dollar-sign', 'chico') ?>
            <span class="cotiz-val"><?= $cot ? monto_html($cot['valor_venta'], 'ARS', 'sin-simbolo', 0) : '—' ?></span>
        </a>
    </header>

    <div class="barra-escritorio"><?= $ojito('ojito-escritorio') ?></div>
    <main class="contenido" id="contenido">
        <?= $contenido ?>
    </main>
</div>

<nav class="bottomnav" aria-label="Navegación rápida">
    <?php foreach ([['dashboard', 'Inicio', 'house'], ['clientes', 'Clientes', 'users'], ['cargos', 'Cobros', 'receipt'], ['vencimientos', 'Vencim.', 'calendar-clock']] as [$pg, $texto, $ic]): ?>
        <a class="bn-item<?= $seccion === $pg ? ' activo' : '' ?>" href="<?= e(url($pg)) ?>"<?= $seccion === $pg ? ' aria-current="page"' : '' ?>>
            <span class="bn-ico"><?= icono($ic) ?></span><?= e($texto) ?>
        </a>
    <?php endforeach; ?>
    <button type="button" class="bn-item<?= !in_array($seccion, $principales, true) ? ' activo' : '' ?>" data-abrir="drawer" aria-expanded="false" aria-controls="sidebar">
        <span class="bn-ico"><?= icono('ellipsis') ?></span>Más
    </button>
</nav>

<button type="button" class="fab" data-abrir="sheet" aria-haspopup="dialog" aria-label="Acciones rápidas"><?= icono('plus') ?></button>

<div class="sheet" id="sheet-acciones" role="dialog" aria-modal="true" aria-label="Acciones rápidas">
    <div class="sheet-asa" aria-hidden="true"></div>
    <div class="sheet-tit">
        <h2>Acciones rápidas</h2>
        <button type="button" class="btn fantasma icono chico" data-cerrar="sheet" aria-label="Cerrar"><?= icono('x') ?></button>
    </div>
    <div class="acciones-rapidas">
        <?php foreach ($rapidas as [$texto, $ic, $href, $attrs]): ?>
            <a class="accion-rapida" href="<?= e($href) ?>"<?= $attrs ?>><?= icono($ic) ?><?= e($texto) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php require __DIR__ . '/sheet_pago.php'; ?>
<?php require __DIR__ . '/sheet_publicacion.php'; ?>

<div class="modal-fondo" id="modal-fondo">
    <div class="modal" role="alertdialog" aria-modal="true" aria-labelledby="modal-titulo" aria-describedby="modal-texto">
        <h2 id="modal-titulo" data-modal-titulo>¿Confirmás?</h2>
        <p id="modal-texto" data-modal-texto></p>
        <div class="modal-acc">
            <button type="button" class="btn sec" data-modal-cancelar>Cancelar</button>
            <button type="button" class="btn" data-modal-ok>Confirmar</button>
        </div>
    </div>
</div>

<div class="toasts" id="toasts" aria-live="polite"></div>
<div id="flash-data" hidden>
    <?php foreach ($flash as $f): ?><p data-tipo="<?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></p><?php endforeach; ?>
</div>
<noscript>
    <div class="contenido"><?php foreach ($flash as $f): ?><div class="banner <?= $f['tipo'] === 'error' ? 'bad' : 'warn' ?>"><div class="banner-txt"><?= e($f['mensaje']) ?></div></div><?php endforeach; ?></div>
</noscript>
</body>
</html>
