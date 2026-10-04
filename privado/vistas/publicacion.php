<?php
/** Una publicación: estado con un toque, copy con "Copiar copy", notas, link, duplicar, editar y eliminar. */
$id = (int) get('id', '0');
$p = publicacion_obtener($id);
if (!$p) {
    flash('error', 'La publicación no existe.');
    redirigir(url('redes'));
}
$titulo = $p['titulo'];
$volver = url('redes', ['mes' => substr($p['fecha'], 0, 7), 'dia' => $p['fecha']]);
$dias = dias_hasta($p['fecha']);
$copy = (string) $p['copy_texto'];
$editarHref = url('publicacion_form', ['id' => $id]);
?>
<div class="pagina-cab">
    <a class="btn fantasma chico" href="<?= e($volver) ?>"><?= icono('chevron-left', 'chico') ?>Calendario</a>
    <div class="acciones">
        <a class="btn sec chico" href="<?= e($editarHref) ?>" data-abrir="publicacion" data-pub-editar="pub-datos"><?= icono('pencil', 'chico') ?>Editar</a>
    </div>
</div>
<script type="application/json" id="pub-datos"><?= publicacion_json($p) ?></script>

<section class="card">
    <div class="pub-meta">
        <?= chip($p['tipo'], '', 'megaphone') ?>
        <span class="mono"><?= e(ucfirst(fecha_larga($p['fecha'], true))) ?><?= $p['hora'] ? ' · ' . e(hora_corta($p['hora'])) : '' ?></span>
        <span class="suave"><?= e(texto_relativo($dias)) ?></span>
    </div>
    <h1 class="pub-titulo"><?= e($p['titulo']) ?></h1>

    <form method="post" action="<?= e(url_accion('publicacion_estado')) ?>" class="m-0">
        <?= csrf_campo() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <span class="etq">Estado <span class="fw-400 suave">— tocá para cambiarlo</span></span>
        <div class="segmentado mb-0" role="group" aria-label="Cambiar estado">
            <?php foreach (PUB_ESTADOS as $k => $t): $actual = $p['estado'] === $k; ?>
                <button type="submit" name="estado" value="<?= e($k) ?>" class="pub-estado est-<?= e($k) ?><?= $actual ? ' actual' : '' ?>" aria-pressed="<?= $actual ? 'true' : 'false' ?>">
                    <span class="pub-punto est-<?= e($k) ?>" aria-hidden="true"></span><?= e($t) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </form>
    <?php if ($dias < 0 && $p['estado'] !== 'publicado'): ?>
        <p class="ayuda warn-txt mt-12"><?= icono('triangle-alert', 'chico') ?> La fecha ya pasó y todavía no está marcada como Publicado.</p>
    <?php endif; ?>
</section>

<section class="card" aria-labelledby="copy-tit">
    <div class="card-cab">
        <h2 id="copy-tit">Copy</h2>
        <?php if ($copy !== ''): ?>
            <button type="button" class="btn chico" data-copiar="<?= e($copy) ?>" data-copiar-ok="Copy copiado al portapapeles"><?= icono('copy', 'chico') ?>Copiar copy</button>
        <?php endif; ?>
    </div>
    <?php if ($copy !== ''): ?>
        <div class="pub-copy"><?= e($copy) ?></div>
        <p class="ayuda mt-8"><?= number_format(mb_strlen($copy), 0, ',', '.') ?> caracteres</p>
    <?php else: ?>
        <p class="suave m-0">Todavía no tiene copy. <a href="<?= e($editarHref) ?>" data-abrir="publicacion" data-pub-editar="pub-datos">Escribilo</a>.</p>
    <?php endif; ?>
</section>

<?php if ((string) $p['notas'] !== '' || $p['link'] !== ''): ?>
<section class="card">
    <dl class="datos">
        <?php if ((string) $p['notas'] !== ''): ?>
            <div><dt>Notas internas</dt><dd class="pub-notas"><?= e($p['notas']) ?></dd></div>
        <?php endif; ?>
        <?php if ($p['link'] !== ''): ?>
            <div><dt>Link</dt><dd><a class="trunc" href="<?= e($p['link']) ?>" target="_blank" rel="noopener noreferrer"><?= icono('external-link', 'chico') ?> <?= e($p['link']) ?></a></dd></div>
        <?php endif; ?>
    </dl>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="dup-tit">
    <h2 id="dup-tit" class="card-tit">Duplicar</h2>
    <form method="post" action="<?= e(url_accion('publicacion_duplicar')) ?>" class="pub-duplicar" data-validar novalidate>
        <?= csrf_campo() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <label class="mb-0">Fecha de la copia
            <input type="date" name="fecha" required value="<?= e(date('Y-m-d', strtotime($p['fecha'] . ' +7 days'))) ?>">
        </label>
        <button class="btn sec" type="submit"><?= icono('copy-plus') ?>Duplicar</button>
    </form>
    <p class="ayuda mt-8">Se copian el tipo, la hora, el título, el copy, las notas y el link.<?= $p['estado'] === 'publicado' ? ' Como esta ya está publicada, la copia queda en "Listo".' : '' ?></p>
</section>

<div class="zona-peligro">
    <form method="post" action="<?= e(url_accion('publicacion_eliminar')) ?>"
          data-confirmar-titulo="Eliminar publicación" data-confirmar="Se elimina «<?= e($p['titulo']) ?>» con su copy y sus notas. No se puede deshacer." data-confirmar-boton="Eliminar">
        <?= csrf_campo() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn peligro chico" type="submit"><?= icono('trash-2', 'chico') ?>Eliminar publicación</button>
    </form>
</div>
