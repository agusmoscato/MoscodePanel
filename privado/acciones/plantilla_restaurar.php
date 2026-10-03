<?php
/** Vuelve a la plantilla de WhatsApp (cobro o aviso de renovación) por defecto. */
if (post('tipo', 'cobro') === 'renovacion') {
    cfg_borrar('plantilla_renovacion');
    flash('ok', 'Plantilla del aviso de renovación restaurada a la de por defecto.');
    redirigir(url('configuracion') . '#renovacion');
}
cfg_borrar('plantilla_whatsapp');
cfg_borrar('plantilla_whatsapp_legacy');
flash('ok', 'Plantilla de WhatsApp restaurada a la de por defecto.');
redirigir(url('configuracion') . '#whatsapp');
