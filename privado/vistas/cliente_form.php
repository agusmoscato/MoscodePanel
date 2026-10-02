<?php
/** Alta / edición de cliente. */
$id = (int) get('id', '0');
$c = $id ? cliente_propio($id) : null;
if ($id && !$c) {
    redirigir(url('clientes'));
}
$c = $c ?? ['nombre' => '', 'contacto' => '', 'email' => '', 'telefono' => '', 'cuit' => '', 'notas' => '', 'estado' => 'activo'];
$titulo = $id ? 'Editar cliente' : 'Nuevo cliente';
$volver = $id ? url('cliente', ['id' => $id]) : url('clientes');
?>
<div class="pagina-cab">
    <a class="btn fantasma chico" href="<?= e($volver) ?>"><?= icono('chevron-left', 'chico') ?><span class="trunc-btn"><?= $id ? e($c['nombre']) : 'Clientes' ?></span></a>
</div>
<div class="form-titulo"><h1><?= e($titulo) ?></h1></div>

<form method="post" action="<?= e(url_accion('cliente_guardar')) ?>" data-validar novalidate>
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <section class="card">
        <h2 class="card-tit">Datos principales</h2>
        <label>Nombre o razón social
            <input name="nombre" required maxlength="160" autocomplete="off" value="<?= e(viejo('nombre', $c['nombre'])) ?>">
        </label>
        <div class="fila-campos c2">
            <label>Persona de contacto
                <input name="contacto" maxlength="120" autocomplete="off" value="<?= e(viejo('contacto', $c['contacto'])) ?>">
            </label>
            <label>CUIT <span class="inline ayuda">(opcional)</span>
                <input class="mono" name="cuit" inputmode="numeric" maxlength="20" placeholder="30-12345678-9" value="<?= e(viejo('cuit', $c['cuit'])) ?>">
            </label>
            <label>Email
                <input type="email" name="email" maxlength="160" autocomplete="off" inputmode="email" value="<?= e(viejo('email', $c['email'])) ?>">
            </label>
            <label>Teléfono / WhatsApp
                <input type="tel" name="telefono" maxlength="40" autocomplete="off" inputmode="tel" placeholder="5491155551234" value="<?= e(viejo('telefono', $c['telefono'])) ?>">
                <span class="ayuda">Con código de país para que ande el botón de WhatsApp.</span>
            </label>
        </div>
    </section>

    <section class="card">
        <h2 class="card-tit">Notas y estado</h2>
        <label>Notas
            <textarea name="notas" rows="3"><?= e(viejo('notas', (string) $c['notas'])) ?></textarea>
        </label>
        <span class="etq">Estado</span>
        <div class="mb-0 segmentado" role="radiogroup" aria-label="Estado">
            <?php foreach (['activo' => 'Activo', 'inactivo' => 'Inactivo'] as $v => $t): ?>
                <label class="chip-radio"><input type="radio" name="estado" value="<?= $v ?>"<?= viejo('estado', $c['estado']) === $v ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
            <?php endforeach; ?>
        </div>
        <p class="mt-8 ayuda">Un cliente inactivo no genera cargos nuevos, pero conserva su deuda pendiente.</p>
    </section>

    <div class="form-fijo">
        <a class="btn sec" href="<?= e($volver) ?>">Cancelar</a>
        <button class="btn" type="submit"><?= icono('check') ?>Guardar</button>
    </div>
</form>
