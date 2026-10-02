<?php $titulo = 'No encontrado'; ?>
<div class="card">
    <div class="vacio-est">
        <?= icono('circle-alert') ?>
        <p><strong>Página no encontrada.</strong> El link no existe o cambió.</p>
        <a class="btn" href="<?= e(url('dashboard')) ?>"><?= icono('house') ?>Volver al inicio</a>
    </div>
</div>
