<?php
/**
 * form_publicacion.php — Campos del formulario de una publicación. Lo usan el bottom sheet (sheet_publicacion.php,
 * que app.js completa al abrirlo) y la pantalla completa (vistas/publicacion_form.php, sin JS o al volver con error).
 * Recibe: $pf (valores: id, fecha, hora, tipo, redes (lista), estado, titulo, copy_texto, notas, link, link_publicado),
 * $pfTipos y $pfRedes (opciones de tipo y de red).
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
<span class="etq">Red <span class="fw-400 suave">— podés elegir varias</span></span>
<div class="segmentado" role="group" aria-label="Redes" data-pub-redes>
    <?php foreach ($pfRedes as $r): ?>
        <label class="chip-radio"><input type="checkbox" name="redes[]" value="<?= e($r) ?>"<?= in_array($r, $pf['redes'], true) ? ' checked' : '' ?>><span><?= red_icono_html($r, false) ?><?= e($r) ?></span></label>
    <?php endforeach; ?>
</div>
<span class="etq">Estado</span>
<div class="segmentado" role="radiogroup" aria-label="Estado">
    <?php foreach (PUB_ESTADOS as $k => $t): ?>
        <label class="chip-radio"><input type="radio" name="estado" value="<?= e($k) ?>"<?= $pf['estado'] === $k ? ' checked' : '' ?>><span><i class="pub-punto est-<?= e($k) ?>" aria-hidden="true"></i><?= e($t) ?></span></label>
    <?php endforeach; ?>
</div>
<label data-mostrar-si="estado=publicado" hidden>Link de la publicación <span class="inline ayuda">(opcional: el post ya publicado)</span>
    <input type="url" name="link_publicado" inputmode="url" maxlength="<?= PUB_LINK_MAX ?>" placeholder="https://www.instagram.com/p/…" autocapitalize="none" spellcheck="false" value="<?= e($pf['link_publicado']) ?>">
</label>
<label>Título
    <input name="titulo" required maxlength="<?= PUB_TITULO_MAX ?>" placeholder="Ej: Post2 - Mito vs Realidad" value="<?= e($pf['titulo']) ?>">
</label>
<label>Copy
    <textarea name="copy_texto" rows="8" class="pub-copy-campo" data-pub-copy placeholder="El texto de la publicación, con emojis y saltos de línea"><?= e($pf['copy_texto']) ?></textarea>
    <?php [$nCar, $nHash] = copy_contador($pf['copy_texto']); ?>
    <span class="ayuda pub-contador<?= $nCar > PUB_LIMITE_INSTAGRAM ? ' pasado' : ($nCar >= PUB_LIMITE_AVISO ? ' cerca' : '') ?>" data-pub-contador
          data-limite="<?= PUB_LIMITE_INSTAGRAM ?>" data-aviso="<?= PUB_LIMITE_AVISO ?>" aria-live="polite"><?= e(texto_contador($nCar, $nHash)) ?></span>
</label>
<label>Notas internas <span class="inline ayuda">(no se publican)</span>
    <textarea name="notas" rows="3" maxlength="<?= PUB_NOTAS_MAX ?>"><?= e($pf['notas']) ?></textarea>
</label>
<label>Link <span class="inline ayuda">(opcional: el diseño en Drive, Canva…)</span>
    <input type="url" name="link" inputmode="url" maxlength="<?= PUB_LINK_MAX ?>" placeholder="https://drive.google.com/…" autocapitalize="none" spellcheck="false" value="<?= e($pf['link']) ?>">
</label>
