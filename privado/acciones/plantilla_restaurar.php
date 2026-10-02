<?php
/** Vuelve a la plantilla de WhatsApp por defecto. */
cfg_borrar('plantilla_whatsapp');
cfg_borrar('plantilla_whatsapp_legacy');
flash('ok', 'Plantilla de WhatsApp restaurada a la de por defecto.');
redirigir(url('configuracion') . '#whatsapp');
