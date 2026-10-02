<?php
/** Genera una URL de webhook de Mercado Pago nueva (la anterior deja de funcionar). Hay que pegar la nueva en Mercado Pago. */
q('UPDATE usuarios SET webhook_token = ? WHERE id = ?', [bin2hex(random_bytes(16)), $usuario['id']]);
registrar_actividad('credenciales', 'Se regeneró la URL del webhook de Mercado Pago');
flash('ok', 'URL del webhook regenerada. Pegá la nueva en tu cuenta de Mercado Pago: la anterior ya no funciona.');
redirigir(url('configuracion') . '#canales');
