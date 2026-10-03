<?php
/** Ficha del cliente: servicios, dominios, cuenta corriente y pagos. */
$id = (int) get('id', '0');
$c = cliente_propio($id);
if (!$c) {
    flash('error', 'El cliente no existe.');
    redirigir(url('clientes'));
}
$titulo = $c['nombre'];
$cotValor = cotizacion_valor();

$servicios = filas('SELECT * FROM servicios WHERE usuario_id = {U} AND cliente_id = ? ORDER BY FIELD(estado,\'activo\',\'pausado\',\'baja\'), nombre', [$id]);
$dominios = filas('SELECT * FROM dominios WHERE usuario_id = {U} AND cliente_id = ? ORDER BY estado, fecha_vencimiento', [$id]);
$pendientes = cargos_pendientes($id);
$planesCliente = planes_listado($id);
$cuotasAVencer = cuotas_a_vencer($id);
$deuda = deuda_cliente($id);
$deudaArs = a_ars($deuda, $cotValor);
$historialCargos = filas("SELECT * FROM cargos WHERE usuario_id = {U} AND cliente_id = ? AND estado IN ('pagado','anulado') ORDER BY fecha_vencimiento DESC, id DESC LIMIT 30", [$id]);
$pagos = filas(
    'SELECT p.*, (SELECT COALESCE(SUM(monto_pago),0) FROM pago_imputaciones WHERE usuario_id = {U} AND pago_id = p.id) AS imputado
     FROM pagos p WHERE p.usuario_id = {U} AND p.cliente_id = ? ORDER BY p.fecha DESC, p.id DESC LIMIT 50',
    [$id]
);
$mpOk = mp_activo();
$tieneDeuda = $deuda['ARS'] > 0.004 || $deuda['USD'] > 0.004;
$wa = ($tieneDeuda && $c['telefono'] !== '') ? link_whatsapp($c) : '';
$portalActivo = cfg('portal_activo', '0') === '1';
$linkPortal = ($portalActivo && $c['portal_token']) ? url_portal(app_url(), $c['portal_token']) : '';
?>
<div class="pagina-cab">
    <a class="btn fantasma chico" href="<?= e(url('clientes')) ?>"><?= icono('chevron-left', 'chico') ?>Clientes</a>
    <div class="acciones">
        <a class="btn sec chico icono" href="<?= e(url('cliente_form', ['id' => $id])) ?>" aria-label="Editar cliente" title="Editar"><?= icono('pencil') ?></a>
    </div>
</div>

<section class="card ficha-cab">
    <div class="ficha-id">
        <div class="avatar grande" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($c['nombre'], 0, 1))) ?></div>
        <div class="ficha-datos">
            <h1><?= e($c['nombre']) ?></h1>
            <div class="item-sub">
                <?= $c['estado'] === 'inactivo' ? chip_estado('inactivo') : chip_estado('activo') ?>
                <?php if ($c['contacto']): ?><span><?= e($c['contacto']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="ficha-deuda">
        <div class="stat-etq">Deuda actual</div>
        <div class="hero-total ficha-total<?= $tieneDeuda ? '' : ' ok-txt' ?>"><?= $tieneDeuda ? montos_html($deuda) : monto_html(0) ?></div>
        <?php if ($tieneDeuda && $deudaArs !== null && $deuda['USD'] > 0.004): ?>
            <div class="suave">≈ <?= monto_html($deudaArs) ?> con el dólar a <?= monto_html($cotValor) ?></div>
        <?php elseif (!$tieneDeuda): ?>
            <div class="suave">Está al día. No debe nada.</div>
        <?php endif; ?>
    </div>

    <div class="ficha-acciones">
        <a class="btn" href="<?= e(url('pago_form', ['cliente_id' => $id])) ?>" data-abrir="pago" data-cliente="<?= $id ?>"><?= icono('wallet') ?>Registrar pago</a>
        <?php if ($wa !== ''): ?><a class="btn sec" href="<?= e($wa) ?>" target="_blank" rel="noopener noreferrer"><?= icono('message-circle') ?>WhatsApp</a><?php endif; ?>
        <a class="btn sec" href="<?= e(url('resumen_cuenta', ['id' => $id])) ?>"><?= icono('file-text') ?>Resumen PDF</a>
        <?php if ($portalActivo): ?><a class="btn sec" href="#info" data-tab-ir="info"><?= icono('link') ?>Portal</a><?php endif; ?>
    </div>
</section>

<div class="tabs-wrap" data-tabs="cliente-<?= $id ?>">
    <div class="tabs" role="tablist" aria-label="Secciones del cliente">
        <button type="button" class="tab" role="tab" data-tab="servicios">Servicios <span class="tab-n"><?= count($servicios) ?></span></button>
        <button type="button" class="tab" role="tab" data-tab="dominios">Dominios <span class="tab-n"><?= count($dominios) ?></span></button>
        <button type="button" class="tab" role="tab" data-tab="cargos">Cargos <span class="tab-n<?= $pendientes ? ' alerta' : '' ?>"><?= count($pendientes) ?></span></button>
        <button type="button" class="tab" role="tab" data-tab="cuotas">Cuotas <span class="tab-n"><?= count($planesCliente) ?></span></button>
        <button type="button" class="tab" role="tab" data-tab="pagos">Pagos <span class="tab-n"><?= count($pagos) ?></span></button>
        <button type="button" class="tab" role="tab" data-tab="info">Info</button>
    </div>

    <!-- SERVICIOS -->
    <section class="panel" data-panel="servicios" role="tabpanel" aria-label="Servicios">
        <div class="panel-cab"><h2>Servicios</h2>
            <a class="btn sec chico" href="<?= e(url('servicio_form', ['cliente_id' => $id])) ?>"><?= icono('plus', 'chico') ?>Servicio</a></div>
        <?php if (!$servicios): ?>
            <div class="card"><div class="vacio-est"><?= icono('layers') ?><p><strong>Sin servicios.</strong> Agregá hosting, mantenimiento, un sistema a medida…</p>
                <a class="btn" href="<?= e(url('servicio_form', ['cliente_id' => $id])) ?>"><?= icono('plus') ?>Agregar servicio</a></div></div>
        <?php else: ?>
            <div class="cards-grid">
            <?php foreach ($servicios as $s):
                $dd = ($s['tipo_cobro'] === 'anual' && $s['proximo_vencimiento']) ? dias_hasta($s['proximo_vencimiento']) : null;
                $ofrecerAviso = ofrecer_aviso_renovacion($dd, (string) $c['telefono']); ?>
                <article class="mini<?= $s['estado'] !== 'activo' ? ' apagada' : '' ?>">
                    <div class="mini-cab">
                        <div class="mini-tit"><?= e($s['nombre']) ?></div>
                        <?= chip_estado($s['estado']) ?>
                    </div>
                    <div class="mini-monto"><?= monto_html($s['monto'], $s['moneda']) ?><span class="suave"> / <?= $s['tipo_cobro'] === 'anual' ? 'año' : 'mes' ?></span></div>
                    <?php if ($s['moneda'] === 'USD' && $cotValor): ?><div class="suave">≈ <?= monto_html($s['monto'] * $cotValor) ?></div><?php endif; ?>
                    <?php if ($s['por_cantidad']): ?><div class="suave"><?= e(detalle_cantidad_servicio($s)) ?> · <?= e(monto_codigo_wa((float) $s['precio_unidad'], $s['moneda'])) ?>/<?= e($s['unidad_singular'] ?: $s['unidad']) ?></div><?php endif; ?>
                    <div class="mini-meta">
                        <?= chip(ucfirst($s['tipo_cobro']), 'mute', $s['tipo_cobro'] === 'anual' ? 'calendar-days' : 'refresh-cw') ?>
                        <?php if ($dd !== null): ?><?= chip_vencimiento($dd) ?><span class="suave mono"><?= e(fecha_corta($s['proximo_vencimiento'])) ?></span><?php endif; ?>
                        <span class="suave">desde <?= e(fecha_corta($s['fecha_inicio'])) ?></span>
                    </div>
                    <?php if ($dd !== null): ?>
                    <div class="suave" data-aviso-estado="servicio:<?= (int) $s['id'] ?>"><?= $s['aviso_renovacion_enviado_en'] ? 'aviso enviado el ' . e(date('d/m', strtotime($s['aviso_renovacion_enviado_en']))) : '' ?></div>
                    <?php endif; ?>
                    <div class="mini-acc">
                        <?php if ($ofrecerAviso): ?>
                        <a class="btn sec chico" href="<?= e(link_whatsapp_renovacion($c, $s)) ?>" target="_blank" rel="noopener noreferrer" data-aviso-renovacion="servicio:<?= (int) $s['id'] ?>"><?= icono('message-circle', 'chico') ?>Avisar renovación</a>
                        <?php endif; ?>
                        <a class="btn sec chico" href="<?= e(url('servicio_form', ['id' => $s['id']])) ?>"><?= icono('pencil', 'chico') ?>Editar</a>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- DOMINIOS -->
    <section class="panel" data-panel="dominios" role="tabpanel" aria-label="Dominios">
        <div class="panel-cab"><h2>Dominios</h2>
            <a class="btn sec chico" href="<?= e(url('dominio_form', ['cliente_id' => $id])) ?>"><?= icono('plus', 'chico') ?>Dominio</a></div>
        <?php if (!$dominios): ?>
            <div class="card"><div class="vacio-est"><?= icono('globe') ?><p><strong>Sin dominios.</strong> Cargalos para que te avise antes de que venzan.</p>
                <a class="btn" href="<?= e(url('dominio_form', ['cliente_id' => $id])) ?>"><?= icono('plus') ?>Agregar dominio</a></div></div>
        <?php else: ?>
            <div class="cards-grid">
            <?php foreach ($dominios as $d):
                $dd = dias_hasta($d['fecha_vencimiento']);
                $ofrecerAvisoDom = $d['estado'] === 'activo' && ofrecer_aviso_renovacion($dd, (string) $c['telefono']); ?>
                <article class="mini<?= $d['estado'] === 'baja' ? ' apagada' : '' ?>">
                    <div class="mini-cab">
                        <div class="mini-tit mono"><?= e($d['dominio']) ?></div>
                        <?= $d['estado'] === 'baja' ? chip_estado('baja') : chip_vencimiento($dd) ?>
                    </div>
                    <div class="mini-meta">
                        <?php if ($d['proveedor']): ?><?= chip($d['proveedor'], 'mute') ?><?php endif; ?>
                        <span class="suave">vence <span class="mono"><?= e(fecha_corta($d['fecha_vencimiento'])) ?></span></span>
                    </div>
                    <div class="mini-doble">
                        <div><small>Costo (vos pagás)</small><?= monto_html($d['costo_renovacion'], $d['moneda_costo']) ?></div>
                        <div><small>Precio al cliente</small><?= monto_html($d['precio_cliente'], $d['moneda_precio']) ?></div>
                    </div>
                    <?php if ($d['estado'] === 'activo'): ?>
                    <div class="suave" data-aviso-estado="dominio:<?= (int) $d['id'] ?>"><?= $d['aviso_renovacion_enviado_en'] ? 'aviso enviado el ' . e(date('d/m', strtotime($d['aviso_renovacion_enviado_en']))) : '' ?></div>
                    <?php endif; ?>
                    <div class="mini-acc">
                        <?php if ($d['estado'] === 'activo'): ?>
                        <form class="en-linea acc-renovar" method="post" action="<?= e(url_accion('dominio_renovar')) ?>"
                              data-confirmar-titulo="Renovar dominio" data-confirmar="¿Renovar <?= e($d['dominio']) ?> por un año más? (Hacelo después de renovarlo en el proveedor.)" data-confirmar-boton="Renovar">
                            <?= csrf_campo() ?><?= nonce_campo() ?><input type="hidden" name="vence" value="<?= e($d['fecha_vencimiento']) ?>">
                            <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                            <button class="btn chico" type="submit"><?= icono('refresh-cw', 'chico') ?>Renovar</button>
                        </form>
                        <?php if ($ofrecerAvisoDom): ?>
                        <a class="btn sec chico" href="<?= e(link_whatsapp_renovacion($c, item_renovacion_dominio($d))) ?>" target="_blank" rel="noopener noreferrer" data-aviso-renovacion="dominio:<?= (int) $d['id'] ?>"><?= icono('message-circle', 'chico') ?>Avisar renovación</a>
                        <?php endif; ?>
                        <?php endif; ?>
                        <a class="btn sec chico" href="<?= e(url('dominio_form', ['id' => $d['id']])) ?>"><?= icono('pencil', 'chico') ?>Editar</a>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- CARGOS -->
    <section class="panel" data-panel="cargos" role="tabpanel" aria-label="Cargos">
        <div class="panel-cab"><h2>Cargos pendientes</h2></div>
        <?php if (!$pendientes): ?>
            <div class="card"><div class="vacio-est"><?= icono('circle-check') ?><p><strong>Sin cargos pendientes.</strong> Todo cobrado.</p></div></div>
        <?php else: ?>
            <div class="card flush tabla">
                <table class="tabla-resp">
                    <thead><tr><th>Concepto</th><th>Vence</th><th>Estado</th><th class="num">Monto</th><th class="num">Pagado</th><th class="num">Saldo</th><?php if ($mpOk): ?><th>Mercado Pago</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($pendientes as $cg): $dv = dias_hasta($cg['fecha_vencimiento']); ?>
                        <tr>
                            <td class="celda-titulo"><?= e($cg['concepto']) ?></td>
                            <td data-label="Vence"><span class="mono"><?= e(fecha_corta($cg['fecha_vencimiento'])) ?></span> <?= $dv < 0 ? chip('vencido', 'bad') : '' ?></td>
                            <td data-label="Estado"><?= chip_estado($cg['estado']) ?></td>
                            <td class="num sin-movil" data-label="Monto"><?= monto_html($cg['monto'], $cg['moneda']) ?></td>
                            <td class="num sin-movil" data-label="Pagado"><?= monto_html($cg['monto_pagado'], $cg['moneda']) ?></td>
                            <td class="celda-monto num" data-label="Saldo"><strong><?= monto_html($cg['saldo'], $cg['moneda']) ?></strong></td>
                            <?php if ($mpOk): ?>
                            <td class="celda-acciones" data-label="Mercado Pago">
                                <?php if ($cg['mp_link'] && $cg['mp_saldo'] !== null && abs((float) $cg['mp_saldo'] - (float) $cg['saldo']) < 0.005): ?>
                                    <div class="link-copiar">
                                        <input class="campo-link" readonly value="<?= e($cg['mp_link']) ?>" data-seleccionar aria-label="Link de pago">
                                        <button type="button" class="btn sec chico icono" data-copiar="<?= e($cg['mp_link']) ?>" aria-label="Copiar link de pago"><?= icono('copy') ?></button>
                                    </div>
                                <?php endif; ?>
                                <form class="en-linea" method="post" action="<?= e(url_accion('mp_link')) ?>"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $cg['id'] ?>"><button class="btn sec chico" type="submit"><?= icono('link', 'chico') ?><?= $cg['mp_link'] ? 'Renovar link' : 'Generar link' ?></button></form>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($historialCargos): ?>
        <details class="acordeon">
            <summary><span>Cargos anteriores (pagados / anulados)</span><?= icono('chevron-down') ?></summary>
            <div class="acordeon-cuerpo">
                <ul class="lista">
                    <?php foreach ($historialCargos as $cg): ?>
                        <li class="item">
                            <div class="item-main"><div class="item-tit"><?= e($cg['concepto']) ?></div>
                                <div class="item-sub"><span class="mono"><?= e(fecha_corta($cg['fecha_vencimiento'])) ?></span><?= chip_estado($cg['estado']) ?></div></div>
                            <div class="item-der"><?= monto_html($cg['monto'], $cg['moneda']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </details>
        <?php endif; ?>
    </section>

    <!-- CUOTAS -->
    <section class="panel" data-panel="cuotas" role="tabpanel" aria-label="Cuotas">
        <div class="panel-cab"><h2>Ventas en cuotas</h2>
            <a class="btn sec chico" href="<?= e(url('plan_form', ['cliente_id' => $id])) ?>"><?= icono('plus', 'chico') ?>Nueva venta en cuotas</a>
        </div>
        <?php if (!$planesCliente): ?>
            <div class="card"><div class="vacio-est"><?= icono('credit-card') ?><p><strong>Sin ventas en cuotas.</strong></p></div></div>
        <?php else: ?>
            <div class="card">
                <ul class="lista">
                    <?php foreach ($planesCliente as $p): $pct = $p['cuotas'] > 0 ? (int) round($p['n_pagadas'] / $p['cuotas'] * 100) : 0; ?>
                        <li class="item">
                            <a class="item-main" href="<?= e(url('plan', ['id' => $p['id']])) ?>">
                                <div class="item-tit"><?= e($p['concepto']) ?> <?= chip_estado($p['estado_visible']) ?></div>
                                <div class="item-sub">
                                    <span><?= (int) $p['n_pagadas'] ?>/<?= (int) $p['cuotas'] ?> pagadas</span>
                                    <span>Cobrado <?= monto_html($p['cobrado'], $p['moneda']) ?></span>
                                    <?php if ($p['proxima']): ?><span>Próxima: cuota <?= (int) $p['proxima']['cuota_numero'] ?> · <?= e(fecha_corta($p['proxima']['fecha_vencimiento'])) ?></span><?php endif; ?>
                                </div>
                                <div class="progreso" role="img" aria-label="<?= $pct ?>% pagado"><span data-ancho="<?= (int) $pct ?>"></span></div>
                            </a>
                            <div class="item-der"><div class="stat-etq">Saldo</div><?= monto_html($p['saldo'], $p['moneda']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($cuotasAVencer): ?>
            <h3 class="mt-16 card-tit">Cuotas a vencer <span class="suave">(todavía no son deuda)</span></h3>
            <div class="card">
                <ul class="lista">
                    <?php foreach ($cuotasAVencer as $q): ?>
                        <li class="item">
                            <div class="item-main"><div class="item-tit"><?= e($q['concepto']) ?></div>
                                <div class="item-sub"><span class="mono"><?= e(fecha_corta($q['fecha_vencimiento'])) ?></span><?= chip_estado('a_vencer') ?></div></div>
                            <div class="item-der"><?= monto_html($q['saldo'], $q['moneda']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </section>

    <!-- PAGOS -->
    <section class="panel" data-panel="pagos" role="tabpanel" aria-label="Pagos">
        <div class="panel-cab"><h2>Historial de pagos</h2>
            <a class="btn chico" href="<?= e(url('pago_form', ['cliente_id' => $id])) ?>" data-abrir="pago" data-cliente="<?= $id ?>"><?= icono('wallet', 'chico') ?>Registrar pago</a></div>
        <?php if (!$pagos): ?>
            <div class="card"><div class="vacio-est"><?= icono('wallet') ?><p><strong>Todavía no registró pagos.</strong></p></div></div>
        <?php else: ?>
            <div class="card flush tabla">
                <table class="tabla-resp">
                    <thead><tr><th>Fecha</th><th>Medio</th><th class="num">Monto</th><th>Nota</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($pagos as $p): $libre = round((float) $p['monto'] - (float) $p['imputado'], 2); $anulado = $p['anulado_en'] !== null; ?>
                        <tr<?= $anulado ? ' class="fila-anulada"' : '' ?>>
                            <td class="celda-titulo mono"><?= e(fecha_corta($p['fecha'])) ?></td>
                            <td data-label="Medio"><?= chip(MEDIOS_PAGO[$p['medio']] ?? $p['medio'], 'mute') ?></td>
                            <td class="celda-monto num">
                                <?= monto_html($p['monto'], $p['moneda']) ?>
                                <?php if ($anulado): ?>
                                    <div><?= chip('anulado', 'bad') ?></div>
                                <?php else: ?>
                                    <?php if ($p['moneda'] === 'USD'): ?><div class="suave">dólar a <?= monto_html($p['cotizacion_usada']) ?></div><?php endif; ?>
                                    <?php if ($libre > 0.009): ?><div><?= chip('sin imputar ' . fmt_monto($libre, $p['moneda']), 'warn') ?></div><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td data-label="Nota"><?= e($p['nota'] ?: '—') ?><?php if ($anulado): ?><div class="suave">Anulado el <?= e(fmt_fecha_hora($p['anulado_en'])) ?>: <?= e((string) $p['anulado_motivo']) ?></div><?php endif; ?></td>
                            <td class="celda-acciones"><?php if (!$anulado): ?><a class="btn fantasma chico peligro-suave" href="<?= e(url('pago_anular', ['id' => $p['id']])) ?>"><?= icono('ban', 'chico') ?>Anular</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- INFO -->
    <section class="panel" data-panel="info" id="info" role="tabpanel" aria-label="Información">
        <div class="card">
            <div class="card-cab"><h2>Datos de contacto</h2>
                <a class="btn sec chico" href="<?= e(url('cliente_form', ['id' => $id])) ?>"><?= icono('pencil', 'chico') ?>Editar</a></div>
            <dl class="datos">
                <div><dt>Contacto</dt><dd><?= e($c['contacto'] ?: '—') ?></dd></div>
                <div><dt>Email</dt><dd><?= $c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '—' ?></dd></div>
                <div><dt>Teléfono / WhatsApp</dt><dd class="mono"><?= e($c['telefono'] ?: '—') ?></dd></div>
                <div><dt>CUIT</dt><dd class="mono"><?= e($c['cuit'] ?: '—') ?></dd></div>
            </dl>
            <?php if ($c['notas']): ?><h3 class="mt-16">Notas</h3><p class="u-font-size-9375rem suave"><?= nl2br(e($c['notas'])) ?></p><?php endif; ?>
        </div>

        <?php if ($portalActivo): ?>
        <div class="card no-imprimir">
            <div class="card-cab"><h2>Portal del cliente</h2></div>
            <?php if ($linkPortal !== ''): ?>
                <p class="suave">Link secreto: quien lo tenga ve la deuda y los vencimientos de este cliente (nada más).</p>
                <div class="link-copiar">
                    <input class="campo-link" readonly value="<?= e($linkPortal) ?>" data-seleccionar aria-label="Link del portal">
                    <button type="button" class="btn sec chico icono" data-copiar="<?= e($linkPortal) ?>" aria-label="Copiar link del portal"><?= icono('copy') ?></button>
                </div>
                <div class="mt-12 acciones">
                    <form class="en-linea" method="post" action="<?= e(url_accion('portal_generar')) ?>" data-confirmar-titulo="Regenerar link" data-confirmar="El link actual dejará de funcionar. ¿Generar uno nuevo?" data-confirmar-boton="Regenerar"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn sec chico" type="submit"><?= icono('refresh-cw', 'chico') ?>Regenerar</button></form>
                    <form class="en-linea" method="post" action="<?= e(url_accion('portal_revocar')) ?>" data-confirmar-titulo="Revocar link" data-confirmar="El link deja de funcionar al instante. ¿Revocarlo?" data-confirmar-boton="Revocar"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn peligro chico" type="submit">Revocar</button></form>
                </div>
            <?php else: ?>
                <p class="suave">Este cliente no tiene un link activo.</p>
                <form method="post" action="<?= e(url_accion('portal_generar')) ?>"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn sec" type="submit"><?= icono('link') ?>Generar link del portal</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card no-imprimir">
            <div class="card-cab"><h2>Otras acciones</h2></div>
            <div class="acciones">
                <a class="btn sec" href="<?= e(url('precios', ['cliente_id' => $id])) ?>"><?= icono('tag') ?>Ajustar precios</a>
            </div>
            <div class="zona-peligro">
                <form method="post" action="<?= e(url_accion('cliente_eliminar')) ?>"
                      data-confirmar-titulo="Eliminar cliente" data-confirmar="Se elimina este cliente con todos sus servicios y dominios. No se puede deshacer." data-confirmar-boton="Eliminar">
                    <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn peligro chico" type="submit"><?= icono('trash-2', 'chico') ?>Eliminar cliente</button>
                    <span class="suave">Solo se puede si no tiene cargos ni pagos; si no, marcalo como inactivo.</span>
                </form>
            </div>
        </div>
    </section>
</div>
