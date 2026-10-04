<?php
/**
 * form_publicacion.php — Campos del formulario de una publicación. Lo usan el bottom sheet (sheet_publicacion.php,
 * que app.js completa al abrirlo) y la pantalla completa (vistas/publicacion_form.php, sin JS o al volver con error).
 * Recibe: $pf (valores: id, fecha, hora, tipo, estado, titulo, copy_texto, notas, link) y $pfTipos (opciones de tipo).
 * Sin ids en los campos: las dos versiones pueden convivir en la misma página.
 */
?>
<input type="hidden" name="id" value="<?= (int) $pf['id'] ?>">
<div class="fila-campos c2">
    <label>Fecha
        <input type="date" name="fecha" required value="<?= e($pf['fecha']) ?>">
    </label>
    <label>Hora <span class="inline ayuda">(opcional)</span>
        <input type="time" name="hora" value="<?= e($pf['hora']) ?>">
    </label>
</div>
<label>Tipo
    <select name="tipo" required>
        <?php foreach ($pfTipos as $t): ?>
            <option value="<?= e($t) ?>"<?= $pf['tipo'] === $t ? ' selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
    </select>
</label>
<span class="etq">Estado</span>
<div class="segmentado" role="radiogroup" aria-label="Estado">
    <?php foreach (PUB_ESTADOS as $k => $t): ?>
        <label class="chip-radio"><input type="radio" name="estado" value="<?= e($k) ?>"<?= $pf['estado'] === $k ? ' checked' : '' ?>><span><i class="pub-punto est-<?= e($k) ?>" aria-hidden="true"></i><?= e($t) ?></span></label>
    <?php endforeach; ?>
</div>
<label>Título
    <input name="titulo" required maxlength="<?= PUB_TITULO_MAX ?>" placeholder="Ej: Post2 - Mito vs Realidad" value="<?= e($pf['titulo']) ?>">
</label>
<label>Copy
    <textarea name="copy_texto" rows="8" class="pub-copy-campo" placeholder="El texto de la publicación, con emojis y saltos de línea"><?= e($pf['copy_texto']) ?></textarea>
    <span class="ayuda">Se guarda tal cual lo escribís (emojis y saltos de línea incluidos). Hasta <?= number_format(PUB_COPY_MAX, 0, ',', '.') ?> caracteres.</span>
</label>
<label>Notas internas <span class="inline ayuda">(no se publican)</span>
    <textarea name="notas" rows="3" maxlength="<?= PUB_NOTAS_MAX ?>"><?= e($pf['notas']) ?></textarea>
</label>
<label>Link <span class="inline ayuda">(opcional: el diseño en Drive, Canva…)</span>
    <input type="url" name="link" inputmode="url" maxlength="<?= PUB_LINK_MAX ?>" placeholder="https://drive.google.com/…" autocapitalize="none" spellcheck="false" value="<?= e($pf['link']) ?>">
</label>
