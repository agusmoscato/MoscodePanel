<?php
/**
 * smtp.php — Cliente SMTP mínimo propio (sin librerías).
 * Soporta SSL directo (puerto 465) o STARTTLS (587) con AUTH LOGIN, texto plano UTF-8.
 */
declare(strict_types=1);

/**
 * Envía un email de texto plano. Lanza RuntimeException ante cualquier error.
 */
function smtp_enviar(string $para, string $asunto, string $texto, ?array $adjunto = null): void
{
    $host = strtolower(trim((string) cred('smtp.host', '')));
    $puerto = (int) cred('smtp.puerto', '465');
    $seguridad = (string) cred('smtp.seguridad', 'ssl');
    $usuario = (string) cred('smtp.usuario', '');
    $clave = (string) cred('smtp.clave', '');
    $desde = (string) cred('smtp.desde', $usuario);
    $desdeNombre = (string) cred('smtp.desde_nombre', '');

    if ($host === '' || $usuario === '' || $clave === '' || $clave === 'CAMBIAR') {
        throw new RuntimeException('SMTP sin configurar (cargalo en Configuración → Canales).');
    }
    if (!in_array($seguridad, ['ssl', 'tls'], true)) {
        throw new RuntimeException('Seguridad SMTP inválida: elegí SSL o TLS en Configuración → Canales.');
    }
    // Evita inyección de cabeceras y de parámetros SMTP
    foreach ([$para, $desde, $desdeNombre, $asunto] as $campo) {
        if (preg_match('/[\r\n]/', $campo)) {
            throw new RuntimeException('Datos de email inválidos.');
        }
    }
    if (!email_valido($para) || !email_valido($desde)) {
        throw new RuntimeException('El email de origen o de destino no es válido.');
    }
    $ip = smtp_destino_seguro($host, $puerto);      // host público y puerto de correo; se conecta a la IP ya resuelta

    $destino = ($seguridad === 'ssl' ? 'ssl://' : 'tcp://') . $ip . ':' . $puerto;
    $tls = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true, 'crypto_method' => $tls]]);
    $sock = @stream_socket_client($destino, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        throw new RuntimeException("SMTP: no se pudo conectar a $host:$puerto");
    }
    stream_set_timeout($sock, 20);

    try {
        smtp_esperar($sock, [220]);
        $ehlo = gethostname() ?: 'localhost';
        smtp_cmd($sock, "EHLO $ehlo", [250]);

        if ($seguridad === 'tls') {
            smtp_cmd($sock, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, $tls)) {
                throw new RuntimeException('SMTP: falló STARTTLS.');
            }
            smtp_cmd($sock, "EHLO $ehlo", [250]);
        }

        smtp_cmd($sock, 'AUTH LOGIN', [334]);
        smtp_cmd($sock, base64_encode($usuario), [334]);
        smtp_cmd($sock, base64_encode($clave), [235]);

        smtp_cmd($sock, "MAIL FROM:<$desde>", [250]);
        smtp_cmd($sock, "RCPT TO:<$para>", [250, 251]);
        smtp_cmd($sock, 'DATA', [354]);

        $dominio = substr(strrchr($desde, '@') ?: '@localhost', 1);
        $cabeceras = [
            'Date: ' . date('r'),
            'From: ' . ($desdeNombre !== '' ? '=?UTF-8?B?' . base64_encode($desdeNombre) . "?= <$desde>" : $desde),
            "To: <$para>",
            'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
            'MIME-Version: 1.0',
        ];
        [$tipo, $cte, $cuerpo] = email_cuerpo_mime($texto, $adjunto);
        $cabeceras[] = 'Content-Type: ' . $tipo;
        if ($cte !== null) {
            $cabeceras[] = 'Content-Transfer-Encoding: ' . $cte;
        }
        // Base64 nunca genera líneas que empiecen con "." así que no hace falta "dot-stuffing".
        fwrite($sock, implode("\r\n", $cabeceras) . "\r\n\r\n" . $cuerpo . "\r\n.\r\n");
        smtp_esperar($sock, [250]);
        smtp_cmd($sock, 'QUIT', [221]);
    } finally {
        fclose($sock);
    }
}

/** Envía un comando y valida el código de respuesta. */
function smtp_cmd($sock, string $comando, array $codigosOk): string
{
    fwrite($sock, $comando . "\r\n");
    return smtp_esperar($sock, $codigosOk);
}

/** Lee una respuesta (posiblemente multilínea) y valida su código. */
function smtp_esperar($sock, array $codigosOk): string
{
    $resp = '';
    $lineas = 0;
    while (($linea = fgets($sock, 1024)) !== false && ++$lineas <= 50) {      // tope de líneas de una respuesta
        $resp .= $linea;
        // Las líneas intermedias tienen "250-..."; la última "250 ..."
        if (strlen($linea) < 4 || $linea[3] === ' ') {
            break;
        }
    }
    $codigo = (int) substr($resp, 0, 3);
    if (!in_array($codigo, $codigosOk, true)) {
        throw new RuntimeException('SMTP: respuesta inesperada: ' . mb_substr(trim($resp), 0, 120));
    }
    return $resp;
}

/** Dominio de app.url sin "www." (para el remitente por defecto). */
function email_dominio_app(): string
{
    $host = strtolower((string) parse_url((string) conf('app.url', ''), PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    return $host !== '' ? $host : 'localhost';
}

/** Remitente por defecto del método "Servidor": avisos@ + dominio de app.url. */
function email_remitente_defecto(): string
{
    return 'avisos@' . email_dominio_app();
}

/** Remitente efectivo del método "Servidor" (el del usuario o el por defecto). */
function email_remitente(): string
{
    $r = trim(cfg('email_remitente'));
    return $r !== '' ? $r : email_remitente_defecto();
}

/** Método de envío del usuario: "servidor" (mail() de PHP, por defecto) o "smtp". */
function email_metodo(): string
{
    if (!cfg_existe('email_metodo')) {
        // Quien ya tenía SMTP cargado antes de existir esta opción sigue con SMTP; el resto, con el mail del servidor
        return (cred('smtp.host') !== '' && cred('smtp.usuario') !== '') ? 'smtp' : 'servidor';
    }
    return cfg('email_metodo') === 'smtp' ? 'smtp' : 'servidor';
}

/**
 * Arma las cabeceras de un email para mail(): From, Reply-To, Date, Message-ID, MIME y UTF-8.
 * Función pura (se prueba sola). Lanza RuntimeException si algún dato tiene saltos de línea.
 */
function email_cabeceras_servidor(string $desde, string $desdeNombre, string $para, string $asunto, ?string $messageId = null, ?string $fecha = null): array
{
    foreach ([$desde, $desdeNombre, $para, $asunto] as $campo) {
        if (preg_match('/[\r\n]/', $campo)) {
            throw new RuntimeException('Datos de email inválidos.');
        }
    }
    $dominio = substr(strrchr($desde, '@') ?: '@localhost', 1);
    $de = $desdeNombre !== '' ? '=?UTF-8?B?' . base64_encode($desdeNombre) . "?= <$desde>" : $desde;
    return [
        'From' => $de,
        'Reply-To' => $desde,
        'Date' => $fecha ?? date('r'),
        'Message-ID' => $messageId ?? '<' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
        'X-Mailer' => 'Moscode Panel',
    ];
}

/**
 * Envía un email de texto plano con la función mail() de PHP (sin SMTP: no hace falta crear una casilla).
 * Usa el remitente del usuario (o avisos@dominio), cabeceras completas y el parámetro -f. Lanza
 * RuntimeException con un mensaje claro si mail() devuelve false.
 */
function servidor_enviar(string $para, string $asunto, string $texto, ?array $adjunto = null): void
{
    $desde = email_remitente();
    if (!email_valido($desde) || str_starts_with($desde, '-')) {
        throw new RuntimeException('El remitente no es un email válido (corregilo en Configuración → Notificaciones).');
    }
    if (!email_valido($para)) {
        throw new RuntimeException('El email de aviso (destino) no es válido.');
    }
    if (!function_exists('mail')) {
        throw new RuntimeException('Este servidor tiene desactivada la función mail() de PHP. Usá el método SMTP.');
    }
    $cab = email_cabeceras_servidor($desde, (string) cfg('email_remitente_nombre', 'Moscode'), $para, $asunto);
    $lineas = [];
    foreach ($cab as $k => $v) {
        $lineas[] = "$k: $v";
    }
    $cuerpo = str_replace(["\r\n", "\r"], "\n", $texto);
    $cuerpo = str_replace("\n", "\r\n", $cuerpo);
    if ($adjunto !== null) {
        [$tipo, , $cuerpo] = email_cuerpo_mime($texto, $adjunto);
        $lineas = array_values(array_filter($lineas, fn($l) => !str_starts_with($l, 'Content-Type:') && !str_starts_with($l, 'Content-Transfer-Encoding:')));
        $lineas[] = 'Content-Type: ' . $tipo;
    }
    $ok = @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, implode("\r\n", $lineas), '-f' . $desde);
    if (!$ok) {
        $ult = error_get_last();
        $detalle = $ult && str_contains((string) $ult['message'], 'mail(') ? ' Detalle: ' . trim(preg_replace('/^mail\(\):\s*/', '', (string) $ult['message'])) : '';
        throw new RuntimeException('mail() de PHP devolvió false: el servidor no aceptó el mensaje (el hosting puede tener el envío desactivado o limitado, o el remitente no es de tu dominio). Probá con otro remitente o usá SMTP.' . $detalle);
    }
}

/**
 * Valida el servidor SMTP que cargó un usuario: nombre de dominio público (no IP, no localhost), puerto de correo
 * (25, 465, 587 o 2525) y que resuelva a IPs públicas. Devuelve la IP a la que hay que conectarse (así no se puede
 * cambiar el destino entre la verificación y la conexión). Lanza RuntimeException con un mensaje claro.
 */
function smtp_destino_seguro(string $host, int $puerto): string
{
    $host = strtolower(trim($host));
    if (!in_array($puerto, [25, 465, 587, 2525], true)) {
        throw new RuntimeException('El puerto SMTP tiene que ser 25, 465, 587 o 2525.');
    }
    if (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host)) {
        throw new RuntimeException('El servidor SMTP tiene que ser un nombre de dominio (ej. smtp.tudominio.com), no una IP.');
    }
    $ips = @gethostbynamel($host) ?: [];
    if (!$ips) {
        throw new RuntimeException("No se pudo resolver el servidor SMTP $host.");
    }
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('El servidor SMTP resuelve a una dirección interna: no está permitido.');
        }
    }
    return $ips[0];
}

/**
 * Arma el cuerpo MIME de un email: texto solo, o multipart con un adjunto ($adjunto = ['nombre' => ..., 'contenido' => bytes]).
 * Devuelve [valor de Content-Type, valor de Content-Transfer-Encoding o null, cuerpo].
 */
function email_cuerpo_mime(string $texto, ?array $adjunto): array
{
    if ($adjunto === null) {
        return ['text/plain; charset=UTF-8', 'base64', chunk_split(base64_encode($texto), 76, "\r\n")];
    }
    $b = '=_mosc_' . bin2hex(random_bytes(12));
    $nombre = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $adjunto['nombre']);
    $cuerpo = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto), 76, "\r\n")
        . "--$b\r\nContent-Type: application/octet-stream; name=\"$nombre\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$nombre\"\r\n\r\n"
        . chunk_split(base64_encode((string) $adjunto['contenido']), 76, "\r\n")
        . "--$b--\r\n";
    return ["multipart/mixed; boundary=\"$b\"", null, $cuerpo];
}