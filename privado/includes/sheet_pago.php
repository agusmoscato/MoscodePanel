<?php
/**
 * sheet_pago.php — Bottom sheet "Registrar pago" (lo incluye el layout en todas las pantallas internas).
 * Flujo rápido: elegir cliente → monto ya sugerido con su deuda → medio de pago → guardar.
 * Envía a la misma acción de siempre (pago_guardar) con imputación automática; para imputar a mano
 * a cargos puntuales hay un link a la pantalla completa (pago_form).
 */
$clientesPago = clientes_filtrados('', 'activo', false);
// Primero los que tienen deuda
usort($clientesPago, fn($a, $b) => [!$a['tiene_deuda'], mb_strtolower($a['nombre'])] <=> [!$b['tiene_deuda'], mb_strtolower($b['nombre'])]);
?>
<div class="sheet" id="sheet-pago" role="dialog" aria-modal="true" aria-labelledby="pago-tit">
    <div class="sheet-asa" aria-hidden="true"></div>
    <div class="sheet-tit">
        <h2 id="pago-tit">Registrar pago</h2>
        <button type="button" class="btn fantasma icono chico" data-cerrar="pago" aria-label="Cerrar"><?= icono('x') ?></button>
    </div>

    <form method="post" action="<?= e(url_accion('pago_guardar')) ?>" data-pago-form data-validar novalidate>
        <?= csrf_campo() ?><?= nonce_campo() ?>
        <input type="hidden" name="modo" value="auto">

        <label>Cliente
            <select name="cliente_id" required data-pago-cliente>
                <option value="" disabled selected>Elegí un cliente…</option>
                <?php foreach ($clientesPago as $cp): ?>
                    <option value="<?= (int) $cp['id'] ?>" data-ars="<?= e((string) $cp['deuda']['ARS']) ?>" data-usd="<?= e((string) $cp['deuda']['USD']) ?>"
                            data-debe="<?= e($cp['tiene_deuda'] ? 'Debe ' . fmt_por_moneda($cp['deuda']) : 'Está al día') ?>">
                        <?= e($cp['nombre']) ?><?= $cp['tiene_deuda'] ? ' — con deuda' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="ayuda" data-pago-debe aria-live="polite"></span>
        </label>

        <div class="fila-campos c2">
            <label>Monto
                <input type="number" name="monto" inputmode="decimal" step="0.01" min="0.01" required placeholder="0,00" data-pago-monto>
            </label>
            <div>
                <span class="etq">Moneda</span>
                <div class="segmentado" role="radiogroup" aria-label="Moneda">
                    <label class="chip-radio"><input type="radio" name="moneda" value="ARS" checked data-pago-moneda><span>$ ARS</span></label>
                    <label class="chip-radio"><input type="radio" name="moneda" value="USD" data-pago-moneda><span>US$ USD</span></label>
                </div>
            </div>
        </div>

        <span class="etq">Medio de pago</span>
        <div class="segmentado" role="radiogroup" aria-label="Medio de pago">
            <?php foreach (MEDIOS_PAGO as $clave => $texto): ?>
                <label class="chip-radio"><input type="radio" name="medio" value="<?= e($clave) ?>"<?= $clave === 'transferencia' ? ' checked' : '' ?>><span><?= e($texto) ?></span></label>
            <?php endforeach; ?>
        </div>

        <div class="mt-16 fila-campos c2">
            <label>Fecha
                <input type="date" name="fecha" value="<?= e(date('Y-m-d')) ?>" required>
            </label>
            <label>Nota (opcional)
                <input name="nota" maxlength="255" placeholder="Ej: transferencia de octubre">
            </label>
        </div>

        <button class="btn bloque" type="submit"><?= icono('check') ?>Registrar pago</button>
        <p class="centro-m-12-0-0 suave">Se aplica al cargo más antiguo primero.
            <a href="<?= e(url('pago_form')) ?>" data-pago-avanzado>Imputar a mano</a></p>
    </form>
</div>
