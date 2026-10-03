<?php
/** Alta / edición de un servicio de un cliente. */
$id = (int) get('id', '0');
$s = $id ? fila('SELECT * FROM servicios WHERE id = ? AND usuario_id = {U}', [$id]) : null;
$clienteId = $s ? (int) $s['cliente_id'] : (int) get('cliente_id', '0');
$cliente = fila('SELECT id, nombre FROM clientes WHERE id = ? AND usuario_id = {U}', [$clienteId]);
if (!$cliente) {
    redirigir(url('clientes'));
}
$s = $s ?? ['nombre' => '', 'descripcion' => '', 'monto' => '', 'moneda' => 'ARS', 'tipo_cobro' => 'mensual', 'fecha_inicio' => date('Y-m-d'), 'proximo_vencimiento' => '', 'dias_anticipo' => ANTICIPO_POR_DEFECTO_DIAS, 'inicio_mensual' => 'mes_siguiente', 'estado' => 'activo', 'por_cantidad' => 0, 'cantidad' => '', 'unidad' => '', 'unidad_singular' => '', 'precio_unidad' => '', 'detalle' => ''];
$titulo = $id ? 'Editar servicio' : 'Nuevo servicio';
$hist = $id ? filas('SELECT * FROM servicios_precios_hist WHERE usuario_id = {U} AND servicio_id = ? ORDER BY fecha DESC LIMIT 20', [$id]) : [];
$volver = url('cliente', ['id' => $clienteId]);
?>
<div class="pagina-cab">
    <a class="btn fantasma chico" href="<?= e($volver) ?>"><?= icono('chevron-left', 'chico') ?><span class="trunc-btn"><?= e($cliente['nombre']) ?></span></a>
</div>
<div class="form-titulo"><h1><?= e($titulo) ?></h1><p class="suave">Cliente: <?= e($cliente['nombre']) ?></p></div>

<form method="post" action="<?= e(url_accion('servicio_guardar')) ?>" data-validar novalidate>
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">

    <section class="card">
        <h2 class="card-tit">Servicio</h2>
        <label>Nombre
            <input name="nombre" required maxlength="160" placeholder="Hosting, mantenimiento, sistema a medida…" value="<?= e(viejo('nombre', $s['nombre'])) ?>">
        </label>
        <label>Descripción <span class="inline ayuda">(opcional; se agrega al mensaje de cobro si es el único servicio)</span>
            <input name="descripcion" maxlength="255" placeholder="Ej: renovación anual" value="<?= e(viejo('descripcion', (string) ($s['descripcion'] ?? ''))) ?>">
        </label>
        <div class="fila-campos c2">
            <div data-mostrar-si="por_cantidad=0">
                <label>Monto
                    <input type="number" name="monto" inputmode="decimal" min="0" step="0.01" placeholder="0,00" value="<?= e(viejo('monto', (string) $s['monto'])) ?>">
                </label>
            </div>
            <div>
                <span class="etq">Moneda</span>
                <div class="segmentado" role="radiogroup" aria-label="Moneda">
                    <?php foreach (['ARS' => '$ Pesos', 'USD' => 'US$ Dólares'] as $v => $t): ?>
                        <label class="chip-radio"><input type="radio" name="moneda" value="<?= $v ?>"<?= viejo('moneda', $s['moneda']) === $v ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <label class="check"><input type="checkbox" name="por_cantidad" value="1"<?= viejo('por_cantidad', (string) $s['por_cantidad']) === '1' ? ' checked' : '' ?>>Cobro por cantidad
            <span class="inline ayuda">(Google Workspace por usuarios, casillas de mail por cuenta…)</span></label>

        <div data-mostrar-si="por_cantidad=1" data-calc-cantidad>
            <div class="fila-campos c2">
                <label>Cantidad
                    <input type="number" name="cantidad" inputmode="decimal" min="0" step="0.01" placeholder="0" value="<?= e(viejo('cantidad', (string) $s['cantidad'])) ?>" data-calc-factor>
                </label>
                <label>Precio por unidad
                    <input type="number" name="precio_unidad" inputmode="decimal" min="0" step="0.01" placeholder="0,00" value="<?= e(viejo('precio_unidad', (string) $s['precio_unidad'])) ?>" data-calc-factor>
                </label>
            </div>
            <div class="fila-campos c2">
                <label>Unidad <span class="inline ayuda">(plural)</span>
                    <input name="unidad" maxlength="60" placeholder="usuarios, cuentas de mail…" value="<?= e(viejo('unidad', (string) $s['unidad'])) ?>">
                </label>
                <label>Unidad en singular <span class="inline ayuda">(opcional, para el aviso de renovación)</span>
                    <input name="unidad_singular" maxlength="60" placeholder="usuario, cuenta de mail…" value="<?= e(viejo('unidad_singular', (string) $s['unidad_singular'])) ?>">
                </label>
            </div>
            <label>Detalle opcional <span class="inline ayuda">(se agrega al aviso de renovación)</span>
                <input name="detalle" maxlength="160" placeholder="Ej: 10.00 GB de almacenamiento" value="<?= e(viejo('detalle', (string) $s['detalle'])) ?>">
            </label>
            <p class="ayuda">El monto se calcula solo: cantidad × precio por unidad = <strong data-calc-resultado>0,00</strong>.</p>
        </div>
    </section>

    <section class="card">
        <h2 class="card-tit">Cobro</h2>
        <span class="etq">Tipo de cobro</span>
        <div class="segmentado" role="radiogroup" aria-label="Tipo de cobro">
            <?php foreach (['mensual' => 'Mensual', 'anual' => 'Anual'] as $v => $t): ?>
                <label class="chip-radio"><input type="radio" name="tipo_cobro" value="<?= $v ?>"<?= viejo('tipo_cobro', $s['tipo_cobro']) === $v ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
            <?php endforeach; ?>
        </div>

        <div class="fila-campos c2">
            <label>Fecha de inicio
                <input type="date" name="fecha_inicio" required value="<?= e(viejo('fecha_inicio', $s['fecha_inicio'])) ?>">
            </label>
            <label data-mostrar-si="tipo_cobro=anual">Fecha de vencimiento
                <input type="date" name="proximo_vencimiento" value="<?= e(viejo('proximo_vencimiento', (string) $s['proximo_vencimiento'])) ?>">
                <span class="ayuda">Si la dejás vacía, será un año después del inicio.</span>
            </label>
        </div>
        <div data-mostrar-si="tipo_cobro=anual">
            <label>Días de anticipo del cargo
                <input type="number" name="dias_anticipo" inputmode="numeric" min="0" max="365" step="1" value="<?= e(viejo('dias_anticipo', (string) $s['dias_anticipo'])) ?>">
                <span class="ayuda">El cargo se genera esta cantidad de días antes del vencimiento (0 a 365).</span>
            </label>
        </div>
        <div data-mostrar-si="tipo_cobro=mensual">
            <label>Si empieza a mitad de mes
                <select name="inicio_mensual">
                    <?php foreach (INICIO_MENSUAL as $v => $t): ?>
                        <option value="<?= e($v) ?>"<?= viejo('inicio_mensual', $s['inicio_mensual']) === $v ? ' selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="ayuda">Solo afecta al primer mes y solo si la fecha de inicio no es el día 1.</span>
            </label>
        </div>
    </section>

    <section class="card">
        <h2 class="card-tit">Estado</h2>
        <div class="mb-0 segmentado" role="radiogroup" aria-label="Estado">
            <?php foreach (['activo' => 'Activo', 'pausado' => 'Pausado', 'baja' => 'Dado de baja'] as $v => $t): ?>
                <label class="chip-radio"><input type="radio" name="estado" value="<?= $v ?>"<?= viejo('estado', $s['estado']) === $v ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="form-fijo">
        <a class="btn sec" href="<?= e($volver) ?>">Cancelar</a>
        <button class="btn" type="submit"><?= icono('check') ?>Guardar</button>
    </div>
</form>

<?php if ($id && $hist): ?>
<section class="card">
    <h2 class="card-tit">Historial de precios</h2>
    <ul class="lista">
        <?php foreach ($hist as $h): ?>
            <li class="item">
                <div class="item-main">
                    <div class="item-sub"><span class="mono"><?= e(fecha_corta($h['fecha'])) ?></span><?= e($h['motivo']) ?><?= $h['porcentaje'] !== null ? ' (' . e($h['porcentaje']) . '%)' : '' ?></div>
                    <div class="cambio-precio"><?= monto_html($h['monto_anterior'], $s['moneda']) ?><?= icono('arrow-right', 'chico') ?><strong><?= monto_html($h['monto_nuevo'], $s['moneda']) ?></strong></div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($id): ?>
<div class="zona-peligro">
    <form method="post" action="<?= e(url_accion('servicio_eliminar')) ?>"
          data-confirmar-titulo="Eliminar servicio" data-confirmar="Se elimina este servicio. Solo se puede si nunca generó cargos." data-confirmar-boton="Eliminar">
        <?= csrf_campo() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn peligro chico" type="submit"><?= icono('trash-2', 'chico') ?>Eliminar servicio</button>
        <span class="suave">Si ya generó cargos, pasalo a "dado de baja".</span>
    </form>
</div>
<?php endif; ?>
