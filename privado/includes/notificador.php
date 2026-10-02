<?php
/**
 * notificador.php — Envío de avisos por email y Telegram, con registro en notificaciones_log
 * y control para no repetir el mismo aviso.
 */
declare(strict_types=1);

/** POST con cURL (form-urlencoded). Devuelve el cuerpo o lanza RuntimeException. */
function http_post(string $url, array $datos, int $timeout = 15): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($datos)] + curl_opciones_base($timeout));
    [$cuerpo, $codigo, $error] = curl_limitado($ch);
    curl_close($ch);
    if ($cuerpo === false) {
        throw new RuntimeException("Error de red: $error");
    }
    if ($codigo < 200 || $codigo >= 300) {
        throw new RuntimeException("HTTP $codigo: " . mb_substr($cuerpo, 0, 200));
    }
    return $cuerpo;
}

/** ¿Está activo el canal? (flag en Configuración + datos en config.php) */
function canal_activo(string $canal): bool
{
    if ($canal === 'email') {
        return cfg('notif_email', '1') === '1' && cfg('email_aviso') !== ''
            && (email_metodo() === 'servidor' || (cred('smtp.host') !== '' && cred('smtp.usuario') !== ''));
    }
    return cfg('notif_telegram', '1') === '1' && cred('telegram.token') !== '' && cred('telegram.chat_id') !== '';
}

function enviar_email(string $asunto, string $texto, ?array $adjunto = null): void
{
    if (email_metodo() === 'smtp') {
        smtp_enviar(cfg('email_aviso'), $asunto, $texto, $adjunto);
    } else {
        servidor_enviar(cfg('email_aviso'), $asunto, $texto, $adjunto);
    }
}

function enviar_telegram(string $texto): void
{
    $token = cred('telegram.token');
    if (!preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/', $token)) {
        throw new RuntimeException('El token de Telegram no tiene el formato correcto (lo da @BotFather).');
    }
    // Telegram limita los mensajes a 4096 caracteres
    $resp = http_post("https://api.telegram.org/bot$token/sendMessage", [
        'chat_id' => cred('telegram.chat_id'),
        'text'    => mb_substr($texto, 0, 4000),
    ]);
    $json = json_decode($resp, true);
    if (empty($json['ok'])) {
        throw new RuntimeException('Telegram: ' . ($json['description'] ?? 'respuesta inválida'));
    }
}

/**
 * Envía un aviso por todos los canales activos y lo registra en el log.
 *
 * @param array $refs  Referencias que cubre este mensaje, para no repetirlo:
 *                     [['tipo'=>'dominio','id'=>3,'dias'=>7,'venc'=>'2026-11-01'], ...]
 *                     (una fila de log por canal y por referencia)
 * @return array ['email' => true|false|null, 'telegram' => ..., 'detalle' => [...]]
 *               null = canal inactivo; false = falló.
 */
function notificar(string $tipo, string $asunto, string $texto, array $refs = []): array
{
    $refs = $refs ?: [['tipo' => '', 'id' => 0, 'dias' => 0, 'venc' => null]];
    $res = ['email' => null, 'telegram' => null, 'detalle' => []];

    foreach (['email', 'telegram'] as $canal) {
        if (!canal_activo($canal)) {
            $res['detalle'][] = "$canal: inactivo (sin configurar o desactivado)";
            continue;
        }
        try {
            $canal === 'email' ? enviar_email($asunto, $texto) : enviar_telegram($asunto . "\n\n" . $texto);
            $ok = true;
            $msg = 'Enviado';
        } catch (Throwable $ex) {
            $ok = false;
            $msg = $ex->getMessage();
            error_log("Notificación $canal ($tipo): $msg");
        }
        $res[$canal] = $ok;
        $res['detalle'][] = "$canal: " . ($ok ? 'enviado' : "FALLÓ — $msg");
        $dest = $canal === 'email' ? cfg('email_aviso') : cred('telegram.chat_id');
        foreach ($refs as $r) {
            insertar('notificaciones_log', [
                'canal' => $canal, 'tipo' => $tipo, 'referencia_tipo' => $r['tipo'], 'referencia_id' => $r['id'],
                'dias_aviso' => $r['dias'], 'vencimiento_ref' => $r['venc'], 'destinatario' => $dest,
                'exito' => $ok ? 1 : 0, 'detalle' => mb_substr($msg, 0, 500), 'enviado_en' => date('Y-m-d H:i:s'),
            ]);
        }
    }
    return $res;
}

/** ¿Algún canal lo envió bien? */
function notificacion_exitosa(array $res): bool
{
    return $res['email'] === true || $res['telegram'] === true;
}

/** ¿Este aviso ya se envió con éxito por algún canal? */
function ya_avisado(string $refTipo, int $refId, int $dias, string $venc): bool
{
    return (bool) valor(
        'SELECT 1 FROM notificaciones_log
         WHERE usuario_id = {U} AND referencia_tipo = ? AND referencia_id = ? AND dias_aviso = ? AND vencimiento_ref = ? AND exito = 1 LIMIT 1',
        [$refTipo, $refId, $dias, $venc]
    );
}
