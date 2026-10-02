<?php
/** Registro de notificaciones enviadas (email / Telegram) y de webhooks de Mercado Pago. */
$titulo = 'Notificaciones';
$log = filas(
    "SELECT l.*, CASE l.referencia_tipo
        WHEN 'dominio' THEN (SELECT dominio FROM dominios WHERE usuario_id = {U} AND id = l.referencia_id)
        WHEN 'servicio' THEN (SELECT nombre FROM servicios WHERE usuario_id = {U} AND id = l.referencia_id)
        WHEN 'cuota' THEN (SELECT CONCAT(pl.concepto, ' ', ca.cuota_numero, '/', ca.cuota_total) FROM cargos ca JOIN planes_pago pl ON pl.id = ca.plan_id
                           WHERE ca.usuario_id = {U} AND pl.usuario_id = {U} AND ca.id = l.referencia_id)
        ELSE NULL END AS referencia
     FROM notificaciones_log l WHERE l.usuario_id = {U} ORDER BY l.id DESC LIMIT 150"
);
$webhooks = filas('SELECT * FROM mp_webhook_log WHERE usuario_id = {U} ORDER BY id DESC LIMIT 40');
$nombresTipo = ['resumen_mensual' => 'Resumen mensual', 'vencimientos' => 'Vencimientos'];
?>
<div class="pagina-cab">
    <div>
        <h1>Notificaciones</h1>
        <div class="sub">Avisos enviados y webhooks recibidos</div>
    </div>
    <a class="btn fantasma chico" href="<?= e(url('configuracion')) ?>"><?= icono('chevron-left', 'chico') ?>Configuración</a>
</div>

<section class="card">
    <h2 class="card-tit">Avisos enviados</h2>
    <?php if (!$log): ?>
        <div class="vacio-est"><?= icono('bell') ?><p><strong>Todavía no se envió ninguna notificación.</strong> Probalas desde Configuración.</p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($log as $l): ?>
                <li class="item">
                    <div class="item-main">
                        <div class="item-tit"><?= e($nombresTipo[$l['tipo']] ?? $l['tipo']) ?><?= $l['referencia'] ? ' · <span class="mono">' . e($l['referencia']) . '</span>' : '' ?></div>
                        <div class="item-sub">
                            <span class="mono"><?= e(fmt_fecha_hora($l['enviado_en'])) ?></span>
                            <?= chip($l['canal'], 'mute', $l['canal'] === 'email' ? 'mail' : 'send') ?>
                            <?php if (in_array($l['referencia_tipo'], ['dominio', 'servicio', 'cuota'], true)): ?><?= chip('aviso ' . (int) $l['dias_aviso'] . ' d', 'mute') ?><?php endif; ?>
                        </div>
                        <?php if (!$l['exito']): ?><div class="detalle-error"><?= e($l['detalle']) ?></div><?php endif; ?>
                    </div>
                    <?= $l['exito'] ? chip('enviado', 'ok') : chip('falló', 'bad') ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card">
    <h2 class="card-tit">Webhooks de Mercado Pago</h2>
    <?php if (!$webhooks): ?>
        <div class="vacio-est"><?= icono('wallet') ?><p>Todavía no llegó ninguna notificación de Mercado Pago.</p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($webhooks as $w):
                $tipoChip = $w['resultado'] === 'registrado' ? 'ok' : (in_array($w['resultado'], ['error', 'firma_invalida'], true) ? 'bad' : 'mute'); ?>
                <li class="item">
                    <div class="item-main">
                        <div class="item-tit mono">Pago <?= e($w['payment_id'] ?: '—') ?></div>
                        <div class="item-sub"><span class="mono"><?= e(fmt_fecha_hora($w['creado_en'])) ?></span><?= e($w['detalle']) ?></div>
                    </div>
                    <?= chip($w['resultado'], $tipoChip) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
