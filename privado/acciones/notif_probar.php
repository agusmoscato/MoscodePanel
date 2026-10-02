<?php
/** Envía un mensaje de prueba por email o Telegram con las credenciales del usuario (no queda en el log de avisos). */
$canal = post('canal');
$texto = 'Prueba de Moscode — ' . date('d/m/Y H:i') . '. Si lo ves, el canal funciona.';
try {
    if ($canal === 'email') {
        if (cfg('email_aviso') === '') {
            throw new RuntimeException('Cargá primero el "Email donde recibo los avisos" y guardá la configuración.');
        }
        enviar_email('Prueba — Moscode', $texto);
        flash('ok', 'Email entregado al ' . (email_metodo() === 'smtp' ? 'servidor SMTP' : 'servidor de correo del hosting (mail de PHP)') . ' para ' . cfg('email_aviso') . '. Revisá la bandeja y la carpeta de spam' . (email_metodo() === 'servidor' ? ' (la primera vez suele caer ahí: ver el README)' : '') . '.');
    } elseif ($canal === 'telegram') {
        if (cred('telegram.token') === '' || cred('telegram.chat_id') === '') {
            throw new RuntimeException('Faltan el token o el chat_id de Telegram (cargalos en Configuración → Canales).');
        }
        enviar_telegram($texto);
        flash('ok', 'Mensaje enviado por Telegram.');
    } else {
        throw new RuntimeException('Canal inválido.');
    }
} catch (Throwable $ex) {
    flash('error', 'Falló la prueba: ' . mensaje_seguro($ex));
}
redirigir(url('configuracion'));
