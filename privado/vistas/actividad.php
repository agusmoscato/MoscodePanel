<?php
/** Actividad de seguridad de todas las cuentas (solo admin; nunca muestra datos de clientes). */
exigir_admin();
$titulo = 'Actividad de las cuentas';
$evento = get('evento');
$uidFiltro = (int) get('cuenta', '0');
$lista = actividad_cuentas(200, $evento !== '' ? $evento : null, $uidFiltro ?: null);
$cuentas = filas('SELECT id, usuario FROM usuarios ORDER BY usuario');
?>
<div class="pagina-cab">
    <div>
        <h1>Actividad de las cuentas</h1>
        <div class="sub">Ingresos, cambios de contraseña, 2FA y acciones de administración. No incluye datos de clientes.</div>
    </div>
</div>
<form method="get" action="<?= e(url('actividad')) ?>" class="fila-campos c3">
    <label>Cuenta
        <select name="cuenta"><option value="">Todas</option>
            <?php foreach ($cuentas as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $uidFiltro === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['usuario']) ?></option><?php endforeach; ?>
        </select></label>
    <label>Evento
        <select name="evento"><option value="">Todos</option>
            <?php foreach (EVENTOS_ACTIVIDAD as $k => $t): if ($k === 'pago_anulado') { continue; } ?><option value="<?= e($k) ?>"<?= $evento === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
        </select></label>
    <div class="alinear-abajo"><button class="btn sec" type="submit"><?= icono('search', 'chico') ?>Filtrar</button></div>
</form>
<section class="card mt-16">
    <?php if (!$lista): ?>
        <div class="vacio-est"><?= icono('shield') ?><p>No hay eventos con ese filtro.</p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($lista as $a): ?>
                <li class="item">
                    <div class="item-main">
                        <div class="item-tit"><?= e(EVENTOS_ACTIVIDAD[$a['evento']] ?? $a['evento']) ?> <span class="suave mono">@<?= e($a['cuenta']) ?></span></div>
                        <div class="item-sub">
                            <span class="mono"><?= e(fmt_fecha_hora($a['creado_en'])) ?></span>
                            <?php if ($a['actor'] && $a['actor'] !== $a['cuenta']): ?><span>por @<?= e($a['actor']) ?></span><?php endif; ?>
                            <span class="mono">IP <?= e($a['ip']) ?></span>
                            <?php if ($a['dispositivo'] !== ''): ?><span><?= e($a['dispositivo']) ?></span><?php endif; ?>
                        </div>
                        <?php if ($a['detalle'] !== ''): ?><div class="suave"><?= e($a['detalle']) ?></div><?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
