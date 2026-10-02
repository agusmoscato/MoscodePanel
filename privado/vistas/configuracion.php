<?php
/** Configuración del usuario, agrupada en secciones desplegables. Todo es personal (cada usuario tiene la suya). */
$titulo = 'Configuración';
$emailOk = canal_activo('email');
$tgOk = canal_activo('telegram');
$smtpListo = cred('smtp.host') !== '' && cred('smtp.usuario') !== '' && cred('smtp.clave') !== '';
$metodo = viejo('email_metodo', email_metodo());
$remDefecto = email_remitente_defecto();
$mpToken = cred('mercadopago.access_token') !== '';
$mpWebhookSecret = cred('mercadopago.webhook_secret') !== '';
$cripto = cripto_disponible();
$hoy = date('Y-m-d');
$periodo = date('Y-m');
$resumenHecho = cfg('resumen_periodo_hecho') === $periodo;
$sinFeriados = !hay_feriados_del_anio((int) date('Y'));
$plantillaEditada = cfg('plantilla_whatsapp') !== '';
$legacy = cfg('plantilla_whatsapp_legacy') === '1';
?>
<div class="pagina-cab">
    <div>
        <h1>Configuración</h1>
        <div class="sub">Tus datos, avisos, canales y cobros: son solo tuyos</div>
    </div>
</div>

<div class="atajos">
    <a class="atajo" href="<?= e(url('mi_cuenta')) ?>"><?= icono('circle-user') ?><span>Mi cuenta<small>Nombre, email y contraseña</small></span><?= icono('chevron-right', 'chico') ?></a>
    <a class="atajo" href="<?= e(url('feriados')) ?>"><?= icono('calendar-days') ?><span>Feriados<small><?= $sinFeriados ? 'Faltan cargar los de ' . date('Y') : 'Calendario de días hábiles' ?></small></span><?= icono('chevron-right', 'chico') ?></a>
    <a class="atajo" href="<?= e(url('notificaciones')) ?>"><?= icono('bell') ?><span>Notificaciones enviadas<small>Avisos y webhooks</small></span><?= icono('chevron-right', 'chico') ?></a>
</div>

<form method="post" action="<?= e(url_accion('config_guardar')) ?>" data-validar novalidate>
    <?= csrf_campo() ?>

    <details class="acordeon" data-abierto-escritorio open>
        <summary><span><?= icono('circle-user', 'chico') ?> Mis datos</span><?= icono('chevron-down') ?></summary>
        <div class="acordeon-cuerpo">
            <div class="fila-campos c2">
                <label>Nombre <span class="inline ayuda">(aparece en los mensajes)</span>
                    <input name="nombre_propio" maxlength="120" value="<?= e(viejo('nombre_propio', cfg('nombre_propio'))) ?>">
                </label>
                <label>Alias o CBU para transferencias
                    <input class="mono" name="alias_cbu" maxlength="120" value="<?= e(viejo('alias_cbu', cfg('alias_cbu'))) ?>">
                </label>
            </div>
            <label>Email donde recibo los avisos
                <input type="email" name="email_aviso" inputmode="email" maxlength="160" value="<?= e(viejo('email_aviso', cfg('email_aviso'))) ?>">
            </label>
            <div>
                <span class="etq">Método de envío del email</span>
                <div class="segmentado" role="radiogroup" aria-label="Método de envío">
                    <label class="chip-radio"><input type="radio" name="email_metodo" value="servidor"<?= $metodo === 'servidor' ? ' checked' : '' ?>><span>Servidor (mail de PHP)</span></label>
                    <label class="chip-radio"><input type="radio" name="email_metodo" value="smtp"<?= $metodo === 'smtp' ? ' checked' : '' ?>><span>SMTP</span></label>
                </div>
                <span class="ayuda">"Servidor" no necesita casilla ni contraseña: usa el envío del propio hosting. Con "SMTP" cargá los datos en <a href="#canales">Canales y credenciales</a>.</span>
            </div>
            <label>Remitente <span class="inline ayuda">(solo con "Servidor"; vacío = <?= e($remDefecto) ?>)</span>
                <input type="email" name="email_remitente" inputmode="email" maxlength="160" placeholder="<?= e($remDefecto) ?>" value="<?= e(viejo('email_remitente', cfg('email_remitente'))) ?>">
                <span class="ayuda">Conviene que sea una dirección de tu dominio (<?= e(email_dominio_app()) ?>): así llega mejor. No hace falta que exista la casilla.</span>
            </label>
        </div>
    </details>

    <details class="acordeon" data-abierto-escritorio>
        <summary><span><?= icono('circle-dollar-sign', 'chico') ?> Dólar</span><?= icono('chevron-down') ?></summary>
        <div class="acordeon-cuerpo">
            <label>Tipo de dólar que uso para mis cobros
                <select name="dolar_tipo">
                    <?php foreach (DOLAR_TIPOS as $v => $t): ?>
                        <option value="<?= e($v) ?>"<?= viejo('dolar_tipo', cfg('dolar_tipo', 'blue')) === $v ? ' selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="ayuda">La cotización se actualiza una sola vez para todos; cada usuario elige qué tipo usa.</p>
        </div>
    </details>

    <details class="acordeon" data-abierto-escritorio>
        <summary><span><?= icono('bell', 'chico') ?> Notificaciones</span><?= icono('chevron-down') ?></summary>
        <div class="acordeon-cuerpo">
            <label>Días de anticipación de los avisos de vencimiento
                <input class="mono" name="dias_aviso" required value="<?= e(viejo('dias_aviso', cfg('dias_aviso', '30,15,7,0'))) ?>" placeholder="30,15,7,0">
                <span class="ayuda">Separados por coma. 0 = el mismo día del vencimiento.</span>
            </label>
            <label class="check"><input type="checkbox" name="notif_email" value="1"<?= cfg('notif_email', '1') === '1' ? ' checked' : '' ?>>Avisar por email
                <?= chip($emailOk ? 'listo' : (cfg('email_aviso') === '' ? 'falta el email de aviso' : 'falta cargar el SMTP en Canales'), $emailOk ? 'ok' : 'warn') ?></label>
            <label class="check"><input type="checkbox" name="notif_telegram" value="1"<?= cfg('notif_telegram', '1') === '1' ? ' checked' : '' ?>>Avisar por Telegram
                <?= chip($tgOk ? 'listo' : 'faltan token / chat_id en Canales', $tgOk ? 'ok' : 'warn') ?></label>
            <p class="ayuda">Las credenciales de SMTP y Telegram se cargan más abajo, en <a href="#canales">Canales y credenciales</a>.</p>
        </div>
    </details>

    <details class="acordeon" id="whatsapp" data-abierto-escritorio>
        <summary><span><?= icono('message-circle', 'chico') ?> Mensaje de cobro por WhatsApp</span><?= icono('chevron-down') ?></summary>
        <div class="acordeon-cuerpo">
            <label>Plantilla
                <textarea name="plantilla_whatsapp" rows="10" required maxlength="2000"><?= e(viejo('plantilla_whatsapp', plantilla_whatsapp_actual())) ?></textarea>
                <span class="ayuda">
                    Variables: <code>{contacto}</code> nombre de pila · <code>{cliente}</code> nombre del cliente · <code>{servicios}</code> · <code>{descripcion}</code> ·
                    <code>{lineas}</code> detalle y total · <code>{saldo_favor}</code> · <code>{total_a_abonar}</code> · <code>{alias}</code> · <code>{yo}</code> tu nombre ·
                    <code>{portal}</code> link del portal. También siguen funcionando <code>{detalle}</code>, <code>{total}</code> y <code>{empresa}</code>.
                </span>
            </label>
            <?php if ($legacy): ?>
                <p class="ayuda">Tu plantilla es la anterior: <code>{cliente}</code> sigue siendo el nombre del contacto. Si la pasás a <code>{contacto}</code>, <code>{cliente}</code> pasa a ser el nombre del cliente.</p>
            <?php endif; ?>
            <?php if ($plantillaEditada): ?>
                <button class="btn sec chico" type="submit" form="form-restaurar-plantilla"><?= icono('rotate-ccw', 'chico') ?>Restaurar plantilla por defecto</button>
            <?php else: ?>
                <p class="ayuda">Estás usando la plantilla por defecto.</p>
            <?php endif; ?>
        </div>
    </details>

    <details class="acordeon" data-abierto-escritorio>
        <summary><span><?= icono('wallet', 'chico') ?> Portal y Mercado Pago</span><?= icono('chevron-down') ?></summary>
        <div class="acordeon-cuerpo">
            <label class="check"><input type="checkbox" name="portal_activo" value="1"<?= cfg('portal_activo', '0') === '1' ? ' checked' : '' ?>>Activar el portal de mis clientes</label>
            <p class="ayuda">Cada cliente tiene un link secreto (se genera desde su ficha) que muestra solo su cuenta, con tu nombre y tu alias. Si lo desactivás, todos tus links dejan de funcionar. URL base: <code><?= e(app_url() ?: 'falta app.url en config.php') ?></code></p>

            <label class="check"><input type="checkbox" name="mp_activo" value="1"<?= cfg('mp_activo', '0') === '1' ? ' checked' : '' ?>>Activar links de pago y cobro automático
                <?= chip($mpToken ? 'access token cargado' : 'falta el access token en Canales', $mpToken ? 'ok' : 'warn') ?></label>
            <span class="etq">Webhook para pegar en tu cuenta de Mercado Pago</span>
            <div class="link-copiar">
                <input class="campo-link" readonly value="<?= e(mp_webhook_url()) ?>" data-seleccionar aria-label="URL del webhook">
                <button type="button" class="btn sec chico icono" data-copiar="<?= e(mp_webhook_url()) ?>" aria-label="Copiar URL del webhook"><?= icono('copy') ?></button>
            </div>
            <p class="ayuda">Es tu URL personal: identifica que los pagos son tuyos. No la compartas. Si se filtró: <button type="submit" form="form-webhook-regenerar" class="btn fantasma chico"><?= icono('rotate-ccw', 'chico') ?>Regenerar la URL</button></p>
        </div>
    </details>

    <div class="form-fijo">
        <button class="btn" type="submit"><?= icono('check') ?>Guardar configuración</button>
    </div>
</form>

<form id="form-webhook-regenerar" method="post" action="<?= e(url_accion('webhook_regenerar')) ?>" data-confirmar-titulo="Regenerar la URL del webhook"
      data-confirmar="La URL actual deja de funcionar: tenés que pegar la nueva en Mercado Pago." data-confirmar-boton="Regenerar"><?= csrf_campo() ?></form>

<form id="form-restaurar-plantilla" method="post" action="<?= e(url_accion('plantilla_restaurar')) ?>" data-confirmar-titulo="Restaurar plantilla"
      data-confirmar="Se pierde tu plantilla actual y se vuelve a la de por defecto." data-confirmar-boton="Restaurar"><?= csrf_campo() ?></form>

<form method="post" action="<?= e(url_accion('credenciales_guardar')) ?>" autocomplete="off" data-validar novalidate>
    <?= csrf_campo() ?>
    <details class="acordeon" id="canales" data-abierto-escritorio>
        <summary><span><?= icono('key-round', 'chico') ?> Canales y credenciales</span><?= icono('chevron-down') ?></summary>
        <div class="acordeon-cuerpo">
            <?php if (!$cripto): ?>
                <div class="banner warn"><?= icono('triangle-alert') ?><div class="banner-txt">Falta <code>seguridad.clave_maestra</code> en <code>privado/config.php</code>: sin ella no se pueden guardar credenciales. Pedíselo al administrador.</div></div>
            <?php else: ?>
                <p class="ayuda">Se guardan cifradas y no se vuelven a mostrar. Un campo secreto vacío conserva el valor guardado.</p>
            <?php endif; ?>

            <h3 class="card-tit">Email (SMTP) <span class="fw-400 suave">— solo si elegiste el método SMTP</span></h3>
            <div class="fila-campos c2">
                <label>Servidor <input name="smtp_host" maxlength="160" value="<?= e(cred('smtp.host')) ?>" placeholder="smtp.hostinger.com"></label>
                <label>Puerto <input class="mono" name="smtp_puerto" inputmode="numeric" maxlength="5" value="<?= e(cred('smtp.puerto') ?: '465') ?>"></label>
            </div>
            <div class="fila-campos c2">
                <label>Seguridad
                    <select name="smtp_seguridad">
                        <?php $seg = cred('smtp.seguridad') ?: 'ssl'; ?>
                        <option value="ssl"<?= $seg === 'ssl' ? ' selected' : '' ?>>SSL (465)</option>
                        <option value="tls"<?= $seg === 'tls' ? ' selected' : '' ?>>TLS (587)</option>
                    </select>
                </label>
                <label>Usuario <input name="smtp_usuario" maxlength="160" autocomplete="off" value="<?= e(cred('smtp.usuario')) ?>"></label>
            </div>
            <label>Contraseña <?= cred('smtp.clave') !== '' ? chip('guardada', 'ok') : '' ?>
                <input type="password" name="smtp_clave" autocomplete="new-password" placeholder="<?= cred('smtp.clave') !== '' ? '•••••••• (vacío = conservar)' : '' ?>">
            </label>
            <div class="fila-campos c2">
                <label>Email remitente <input type="email" name="smtp_desde" maxlength="160" value="<?= e(cred('smtp.desde')) ?>"></label>
                <label>Nombre del remitente <input name="smtp_desde_nombre" maxlength="120" value="<?= e(cred('smtp.desde_nombre')) ?>" placeholder="Moscode"></label>
            </div>
            <?php if (cred('smtp.clave') !== ''): ?><label class="check"><input type="checkbox" name="quitar[]" value="smtp_clave">Quitar la contraseña SMTP guardada</label><?php endif; ?>

            <h3 class="mt-20 card-tit">Telegram</h3>
            <div class="fila-campos c2">
                <label>Token del bot <?= cred('telegram.token') !== '' ? chip('guardado', 'ok') : '' ?>
                    <input type="password" name="telegram_token" autocomplete="new-password" placeholder="<?= cred('telegram.token') !== '' ? '•••••••• (vacío = conservar)' : '' ?>">
                </label>
                <label>Chat ID <input class="mono" name="telegram_chat_id" inputmode="numeric" maxlength="22" value="<?= e(cred('telegram.chat_id')) ?>"></label>
            </div>
            <?php if (cred('telegram.token') !== ''): ?><label class="check"><input type="checkbox" name="quitar[]" value="telegram_token">Quitar el token de Telegram guardado</label><?php endif; ?>

            <h3 class="mt-20 card-tit">Mercado Pago</h3>
            <div class="fila-campos c2">
                <label>Access token <?= $mpToken ? chip('guardado', 'ok') : '' ?>
                    <input type="password" name="mp_access_token" autocomplete="new-password" placeholder="<?= $mpToken ? '•••••••• (vacío = conservar)' : '' ?>">
                </label>
                <label>Clave secreta del webhook <span class="inline ayuda">(opcional)</span> <?= $mpWebhookSecret ? chip('guardada', 'ok') : '' ?>
                    <input type="password" name="mp_webhook_secret" autocomplete="new-password" placeholder="<?= $mpWebhookSecret ? '•••••••• (vacío = conservar)' : '' ?>">
                </label>
            </div>
            <?php if ($mpToken): ?><label class="check"><input type="checkbox" name="quitar[]" value="mp_access_token">Quitar el access token guardado</label><?php endif; ?>
            <?php if ($mpWebhookSecret): ?><label class="check"><input type="checkbox" name="quitar[]" value="mp_webhook_secret">Quitar la clave secreta guardada</label><?php endif; ?>

            <button class="btn" type="submit" <?= $cripto ? '' : 'disabled' ?>><?= icono('lock') ?>Guardar credenciales</button>
        </div>
    </details>
</form>

<details class="acordeon" data-abierto-escritorio>
    <summary><span><?= icono('send', 'chico') ?> Probar automatizaciones</span><?= icono('chevron-down') ?></summary>
    <div class="acordeon-cuerpo">
        <dl class="mb-16 datos">
            <div><dt>Hoy</dt><dd><?= e(fecha_corta($hoy)) ?> · <?= es_dia_habil($hoy) ? chip('día hábil', 'ok') : chip('no es día hábil', 'mute') ?></dd></div>
            <div><dt>Primer día hábil de <?= e(mes_nombre($periodo)) ?></dt><dd class="mono"><?= e(fecha_corta(primer_dia_habil($periodo))) ?></dd></div>
            <div><dt>Resumen del mes</dt><dd><?= $resumenHecho ? chip('ya enviado', 'ok') : chip('pendiente', 'warn') ?></dd></div>
        </dl>
        <?php if ($sinFeriados): ?>
            <div class="banner warn"><?= icono('triangle-alert') ?><div class="banner-txt">No hay feriados cargados para <?= date('Y') ?>.<?= es_admin() ? ' <a href="' . e(url('feriados')) . '">Importalos</a>.' : ' Avisale al administrador.' ?></div></div>
        <?php endif; ?>
        <div class="grid-botones">
            <form method="post" action="<?= e(url_accion('notif_probar')) ?>"><?= csrf_campo() ?><input type="hidden" name="canal" value="email"><button class="btn sec bloque" type="submit"><?= icono('mail') ?>Probar email</button></form>
            <form method="post" action="<?= e(url_accion('notif_probar')) ?>"><?= csrf_campo() ?><input type="hidden" name="canal" value="telegram"><button class="btn sec bloque" type="submit"><?= icono('send') ?>Probar Telegram</button></form>
            <form method="post" action="<?= e(url_accion('mp_probar')) ?>"><?= csrf_campo() ?><button class="btn sec bloque" type="submit"><?= icono('wallet') ?>Probar Mercado Pago</button></form>
            <form method="post" action="<?= e(url_accion('automatizacion_probar')) ?>" data-confirmar-titulo="Enviar resumen mensual"
                  data-confirmar="Genera los cargos del mes (si faltan) y envía el resumen ahora por los canales activos." data-confirmar-boton="Enviar"><?= csrf_campo() ?><input type="hidden" name="que" value="resumen"><button class="btn bloque" type="submit"><?= icono('send') ?>Enviar resumen ahora</button></form>
            <form method="post" action="<?= e(url_accion('automatizacion_probar')) ?>"><?= csrf_campo() ?><input type="hidden" name="que" value="avisos"><button class="btn bloque" type="submit"><?= icono('bell') ?>Enviar avisos ahora</button></form>
        </div>
        <p class="mt-12 ayuda">El resumen forzado no marca el mes como enviado: el cron lo enviará igual el primer día hábil.</p>
    </div>
</details>
