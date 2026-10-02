<?php
/** Ajuste de precios por porcentaje: selección, vista previa y aplicación. */
$titulo = 'Ajuste de precios';

$clientesTodos = filas(
    "SELECT c.id, c.nombre FROM clientes c
     WHERE c.usuario_id = {U} AND EXISTS (SELECT 1 FROM servicios s WHERE s.usuario_id = {U} AND s.cliente_id = c.id AND s.estado IN ('activo','pausado'))
     ORDER BY c.nombre"
);

// Los parámetros llegan por GET (formulario de vista previa)
$hayPrevia = isset($_GET['previsualizar']);
$src = $hayPrevia ? $_GET : ['clientes' => (get('cliente_id') !== '' ? [(int) get('cliente_id')] : []), 'porcentaje' => '', 'redondeo' => 'ninguno'];
[$p, $error] = parametros_ajuste($src);
$filas = [];
if ($hayPrevia && !$error) {
    $filas = calcular_ajuste($p);
}
$marcados = array_flip($p['clientes']);
$sube = $p['porcentaje'] > 0;
?>
<div class="pagina-cab">
    <div>
        <h1>Ajuste de precios</h1>
        <div class="sub">Subí o bajá los montos por porcentaje, con vista previa</div>
    </div>
</div>

<?php if ($hayPrevia && $error): ?><div class="banner bad" role="alert"><?= icono('circle-alert') ?><div class="banner-txt"><?= e($error) ?></div></div><?php endif; ?>

<form method="get" data-validar novalidate>
    <input type="hidden" name="p" value="precios">
    <input type="hidden" name="previsualizar" value="1">

    <section class="card">
        <h2 class="card-tit">Qué cambiar</h2>
        <div class="fila-campos c2">
            <label>Porcentaje <span class="inline ayuda">(negativo para bajar)</span>
                <input type="number" name="porcentaje" inputmode="decimal" step="0.01" required placeholder="Ej: 15" value="<?= $p['porcentaje'] != 0.0 ? e((string) $p['porcentaje']) : '' ?>">
            </label>
            <label>Redondeo
                <select name="redondeo"><?php foreach (REDONDEOS as $v => $t): ?>
                    <option value="<?= e($v) ?>"<?= $p['redondeo'] === $v ? ' selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?></select>
            </label>
        </div>
        <span class="etq">Moneda</span>
        <div class="segmentado" role="radiogroup" aria-label="Moneda">
            <?php foreach (['' => 'Todas', 'ARS' => '$ Pesos', 'USD' => 'US$ Dólares'] as $v => $t): ?>
                <label class="chip-radio"><input type="radio" name="moneda" value="<?= e((string) $v) ?>"<?= $p['moneda'] === (string) $v ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
            <?php endforeach; ?>
        </div>
        <span class="etq">Tipo de cobro</span>
        <div class="segmentado" role="radiogroup" aria-label="Tipo de cobro">
            <?php foreach (['' => 'Mensuales y anuales', 'mensual' => 'Mensuales', 'anual' => 'Anuales'] as $v => $t): ?>
                <label class="chip-radio"><input type="radio" name="tipo" value="<?= e((string) $v) ?>"<?= $p['tipo'] === (string) $v ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
            <?php endforeach; ?>
        </div>
        <label class="check"><input type="checkbox" name="pausados" value="1"<?= $p['pausados'] ? ' checked' : '' ?>>Incluir servicios pausados</label>
    </section>

    <section class="card">
        <div class="card-cab">
            <h2>Clientes</h2>
            <label class="m-0 check"><input type="checkbox" data-marcar-todos="clientes[]">Todos</label>
        </div>
        <?php if (!$clientesTodos): ?>
            <div class="vacio-est"><?= icono('users') ?><p>No hay clientes con servicios para ajustar.</p></div>
        <?php else: ?>
            <div class="checklist">
                <?php foreach ($clientesTodos as $c): ?>
                    <label class="check"><input type="checkbox" name="clientes[]" value="<?= (int) $c['id'] ?>"<?= isset($marcados[$c['id']]) ? ' checked' : '' ?>><span><?= e($c['nombre']) ?></span></label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="form-fijo">
        <button class="btn" type="submit"><?= icono('list-checks') ?>Ver vista previa</button>
    </div>
</form>

<?php if ($hayPrevia && !$error): ?>
<section class="card" id="vista-previa" aria-labelledby="prev-tit">
    <div class="card-cab">
        <h2 id="prev-tit">Vista previa <?= chip(($sube ? '+' : '') . $p['porcentaje'] . '%', $sube ? 'warn' : 'info') ?></h2>
        <span class="suave"><?= count($filas) ?> servicio<?= count($filas) === 1 ? '' : 's' ?></span>
    </div>
    <?php if (!$filas): ?>
        <div class="vacio-est"><?= icono('layers') ?><p><strong>Ningún servicio coincide</strong> con esos filtros.</p></div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($filas as $f): $dif = $f['nuevo'] - $f['actual']; ?>
                <li class="item item-previa">
                    <div class="item-main">
                        <div class="item-tit"><?= e($f['nombre']) ?></div>
                        <div class="item-sub"><?= e($f['cliente']) ?><?= chip($f['tipo_cobro'], 'mute') ?></div>
                    </div>
                    <div class="item-der">
                        <div class="antes-despues">
                            <span class="antes"><?= monto_html($f['actual'], $f['moneda']) ?></span>
                            <?= icono('arrow-right', 'chico') ?>
                            <strong><?= monto_html($f['nuevo'], $f['moneda']) ?></strong>
                        </div>
                        <?= chip(($dif >= 0 ? '+' : '−') . fmt_monto(abs($dif), $f['moneda']), $dif > 0.004 ? 'warn' : ($dif < -0.004 ? 'info' : 'mute')) ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="u-margin-12px-0 suave">Los cargos ya generados no cambian: el nuevo monto rige desde los próximos cargos. Queda registrado en el historial de precios de cada servicio.</p>
        <form method="post" action="<?= e(url_accion('precios_aplicar')) ?>"
              data-confirmar-titulo="Aplicar el ajuste"
              data-confirmar="Se actualizan <?= count($filas) ?> servicio(s) con <?= $sube ? '+' : '' ?><?= e((string) $p['porcentaje']) ?>%. Queda registrado en el historial de precios." data-confirmar-boton="Aplicar ajuste">
            <?= csrf_campo() ?><?= nonce_campo() ?>
            <?php foreach ($p['clientes'] as $cid): ?><input type="hidden" name="clientes[]" value="<?= (int) $cid ?>"><?php endforeach; ?>
            <input type="hidden" name="porcentaje" value="<?= e((string) $p['porcentaje']) ?>">
            <input type="hidden" name="redondeo" value="<?= e($p['redondeo']) ?>">
            <input type="hidden" name="moneda" value="<?= e($p['moneda']) ?>">
            <input type="hidden" name="tipo" value="<?= e($p['tipo']) ?>">
            <input type="hidden" name="pausados" value="<?= $p['pausados'] ? '1' : '0' ?>">
            <input type="hidden" name="firma" value="<?= e(firma_ajuste($filas)) ?>">
            <button class="btn bloque" type="submit"><?= icono('check') ?>Aplicar ajuste a <?= count($filas) ?> servicio<?= count($filas) === 1 ? '' : 's' ?></button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>
