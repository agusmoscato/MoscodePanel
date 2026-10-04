<?php
/** Mi cuenta → Privacidad en pantalla: "Ocultar montos al abrir la app" (lo lee tema.js desde data-montos-al-abrir). */
cfg_set('montos_ocultos_al_abrir', post('montos_ocultos_al_abrir') === '1' ? '1' : '0');
flash('ok', post('montos_ocultos_al_abrir') === '1'
    ? 'Listo: la app va a arrancar con los montos ocultos cada vez que la abras.'
    : 'Listo: la app arranca como la dejaste la última vez (con o sin montos).');
redirigir(url('mi_cuenta'));
