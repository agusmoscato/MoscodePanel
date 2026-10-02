<?php
/**
 * mercadopago.php — Integración con Mercado Pago (Checkout Pro) usando cURL, sin SDK.
 *
 *  - Se crea una "preferencia" (link de pago) por cargo, con external_reference = "cargo-<id>".
 *  - Mercado Pago avisa al webhook (privado/paginas/webhook.php); ese script NO confía en el
 *    contenido de la notificación: consulta el pago a la API con nuestro access token y recién
 *    ahí registra el pago.
 *  - Modo prueba o real: depende únicamente del access token puesto en config.php.
 *  - Mercado Pago cobra en pesos: un cargo en USD se convierte con la cotización del momento
 *    en que se crea el link (queda guardada en el cargo para imputar el pago exacto).
 */
declare(strict_types=1);

/** ¿La integración está activada y con token cargado? */
function mp_activo(): bool
{
    return cfg('mp_activo', '0') === '1' && cred('mercadopago.access_token') !== '';
}

/** Llamada a la API de Mercado Pago. Devuelve el JSON decodificado o lanza RuntimeException (con ->getCode() = HTTP). */
function mp_api(string $metodo, string $ruta, ?array $json = null): array
{
    $token = cred('mercadopago.access_token');
    if ($token === '') {
        throw new RuntimeException('Falta el access token de Mercado Pago (cargalo en Configuración).');
    }
    $ch = curl_init('https://api.mercadopago.com' . $ruta);
    $cab = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    $opc = [CURLOPT_CUSTOMREQUEST => $metodo] + curl_opciones_base(15);
    if ($json !== null) {
        $cab[] = 'Content-Type: application/json';
        $cab[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(12));
        $opc[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $opc[CURLOPT_HTTPHEADER] = $cab;
    curl_setopt_array($ch, $opc);
    [$cuerpo, $codigo, $error] = curl_limitado($ch);
    curl_close($ch);

    if ($cuerpo === false) {
        throw new RuntimeException('Error de red con Mercado Pago: ' . $error, 0);
    }
    $datos = json_decode((string) $cuerpo, true);
    if ($codigo < 200 || $codigo >= 300) {
        $msg = is_array($datos) ? ($datos['message'] ?? $datos['error'] ?? 'sin detalle') : 'sin detalle';
        throw new RuntimeException("Mercado Pago respondió HTTP $codigo: $msg", $codigo);
    }
    return is_array($datos) ? $datos : [];
}

/** URL pública del panel (sin barra final). */
function app_url(): string
{
    return rtrim((string) conf('app.url', ''), '/');
}

/** URL del webhook para pegar en Mercado Pago. */
function mp_webhook_url(): string
{
    return url_webhook_mp(app_url(), asegurar_webhook_token(usuario_id()));
}

/**
 * Crea la preferencia para un cargo y guarda el link. Devuelve la URL de pago.
 * Reutiliza el link existente si el saldo no cambió y tiene menos de 24 h.
 */
function mp_link_para_cargo(int $cargoId): string
{
    if (!mp_activo()) {
        throw new RuntimeException('Mercado Pago no está activado.');
    }
    $cargo = fila("SELECT * FROM cargos WHERE id = ? AND usuario_id = {U} AND estado IN ('pendiente','parcial')", [$cargoId]);
    if (!$cargo) {
        throw new RuntimeException('El cargo no existe o ya está pagado.');
    }
    $saldo = round((float) $cargo['monto'] - (float) $cargo['monto_pagado'], 2);

    // ¿Sirve el link que ya tiene?
    if ($cargo['mp_link'] && $cargo['mp_saldo'] !== null && abs((float) $cargo['mp_saldo'] - $saldo) < 0.005
        && $cargo['mp_creado_en'] && strtotime($cargo['mp_creado_en']) > time() - 86400) {
        return (string) $cargo['mp_link'];
    }

    $cot = null;
    $precioArs = $saldo;
    if ($cargo['moneda'] === 'USD') {
        $cot = cotizacion_valor();
        if (!$cot) {
            throw new RuntimeException('Falta la cotización del dólar para cobrar un cargo en USD.');
        }
        $precioArs = round($saldo * $cot, 2);
    }
    if ($precioArs < 1) {
        throw new RuntimeException('El monto a cobrar es demasiado chico.');
    }

    $cliente = fila('SELECT nombre, email, portal_token FROM clientes WHERE id = ? AND usuario_id = {U}', [$cargo['cliente_id']]);
    $webhookToken = asegurar_webhook_token(usuario_id());
    $pref = [
        'items' => [[
            'title'       => mb_substr((string) $cargo['concepto'], 0, 250),
            'quantity'    => 1,
            'unit_price'  => $precioArs,
            'currency_id' => 'ARS',
        ]],
        'external_reference' => 'cargo-' . $cargoId,
    ];
    if ($webhookToken !== '' && str_starts_with(app_url(), 'https://')) {
        $pref['notification_url'] = mp_webhook_url();
        $volver = $cliente['portal_token'] ? url_portal(app_url(), $cliente['portal_token']) : app_url() . '/login';
        $pref['back_urls'] = ['success' => $volver, 'pending' => $volver, 'failure' => $volver];
        $pref['auto_return'] = 'approved';
    }
    if ($cliente && filter_var($cliente['email'], FILTER_VALIDATE_EMAIL)) {
        $pref['payer'] = ['email' => $cliente['email']];
    }

    $r = mp_api('POST', '/checkout/preferences', $pref);
    $link = (string) ($r['init_point'] ?? '');
    if ($link === '') {
        throw new RuntimeException('Mercado Pago no devolvió el link de pago.');
    }
    q(
        'UPDATE cargos SET mp_preference_id = ?, mp_link = ?, mp_cotizacion = ?, mp_saldo = ?, mp_creado_en = NOW() WHERE id = ? AND usuario_id = {U}',
        [(string) ($r['id'] ?? ''), $link, $cot, $saldo, $cargoId]
    );
    return $link;
}

function mp_log(string $paymentId, string $resultado, string $detalle = ''): void
{
    insertar('mp_webhook_log', [
        'payment_id' => mb_substr($paymentId, 0, 40), 'resultado' => mb_substr($resultado, 0, 30),
        'detalle' => mb_substr($detalle, 0, 500), 'creado_en' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Procesa una notificación de pago: consulta el pago a la API (nunca se confía en el aviso) y, si está aprobado y
 * corresponde a un cargo de este usuario, lo registra una sola vez por pago.
 *  - Si el cargo ya estaba pagado o anulado, el dinero entró igual: se registra el pago SIN imputar (saldo a favor) y
 *    se avisa, en vez de perderlo o de hacer que Mercado Pago reintente para siempre.
 *  - Reembolsos y contracargos de un pago ya registrado no se revierten solos: se avisa para que lo revises.
 * Devuelve: 'registrado', 'duplicado', 'ignorado', 'no_encontrado' o 'revisar'.
 * Lanza una excepción solo ante errores transitorios (red, base) para que Mercado Pago reintente.
 */
function mp_procesar_pago(string $paymentId): string
{
    if (!preg_match('/^\d{1,20}$/', $paymentId)) {
        mp_log($paymentId, 'ignorado', 'ID de pago inválido');
        return 'ignorado';
    }
    $reg = fila('SELECT id, monto, moneda, anulado_en FROM pagos WHERE mp_payment_id = ? AND usuario_id = {U}', [$paymentId]);
    if ($reg && $reg['anulado_en'] !== null) {
        mp_log($paymentId, 'duplicado', 'Ya registrado (y anulado en el panel)');
        return 'duplicado';
    }

    try {
        $p = mp_api('GET', '/v1/payments/' . $paymentId);
    } catch (RuntimeException $ex) {
        if ($ex->getCode() === 404) {
            mp_log($paymentId, 'no_encontrado', 'El pago no existe (¿notificación de prueba?)');
            return 'no_encontrado';
        }
        mp_log($paymentId, 'error', 'No se pudo consultar el pago a Mercado Pago (HTTP ' . $ex->getCode() . ')');
        throw $ex;
    }

    return mp_aplicar_pago($paymentId, $p, $reg);
}

/**
 * Aplica los datos de un pago ya consultado a la API ($p = respuesta de /v1/payments/<id>). Se separa de la consulta para
 * poder probar todos los casos (cargo cerrado, reembolso, monto inválido…) sin llamar a Mercado Pago.
 * $reg = el pago ya registrado en el panel con ese payment_id (o null).
 */
function mp_aplicar_pago(string $paymentId, array $p, ?array $reg): string
{
    $estado = (string) ($p['status'] ?? '');
    if (in_array($estado, ['refunded', 'charged_back'], true)) {
        mp_log($paymentId, 'revisar', "Estado: $estado (reembolso o contracargo)");
        if ($reg && !ya_avisado('mp_' . $estado, (int) $reg['id'], 0, '2000-01-01')) {
            notificar(
                'mp_' . $estado,
                'Mercado Pago: pago ' . ($estado === 'refunded' ? 'reembolsado' : 'con contracargo'),
                "El pago #$paymentId (" . fmt_monto($reg['monto'], $reg['moneda']) . ") figura como $estado en Mercado Pago, pero estaba registrado en el panel.\n"
                . 'Revisalo y, si corresponde, anulalo desde la ficha del cliente (Pagos → Anular).',
                [['tipo' => 'mp_' . $estado, 'id' => (int) $reg['id'], 'dias' => 0, 'venc' => '2000-01-01']]
            );
        }
        return 'revisar';
    }
    if ($reg) {
        mp_log($paymentId, 'duplicado', 'Ya estaba registrado');
        return 'duplicado';
    }
    if ($estado !== 'approved') {
        mp_log($paymentId, 'ignorado', "Estado: $estado");
        return 'ignorado';
    }
    if (!preg_match('/^cargo-(\d+)$/', (string) ($p['external_reference'] ?? ''), $m)) {
        mp_log($paymentId, 'ignorado', 'external_reference no corresponde a un cargo');
        return 'ignorado';
    }
    if (($p['currency_id'] ?? 'ARS') !== 'ARS') {
        mp_log($paymentId, 'ignorado', 'Moneda no soportada: ' . substr((string) ($p['currency_id'] ?? '?'), 0, 10));
        return 'ignorado';
    }
    $cargo = fila('SELECT * FROM cargos WHERE id = ? AND usuario_id = {U}', [(int) $m[1]]);
    if (!$cargo) {
        mp_log($paymentId, 'ignorado', 'El cargo ' . $m[1] . ' no existe en esta cuenta');
        return 'ignorado';
    }
    $monto = round((float) ($p['transaction_amount'] ?? 0), 2);
    if (!is_finite($monto) || $monto < 0.01 || $monto > MONTO_MAX) {
        mp_log($paymentId, 'ignorado', 'Monto inválido');
        return 'ignorado';
    }

    // Cotización: la guardada al crear el link (cargos en USD) o la vigente.
    $cot = (float) ($cargo['mp_cotizacion'] ?? 0);
    if ($cot <= 0) {
        $cot = cotizacion_valor() ?? 1.0;
    }
    // Cargo ya cerrado: el pago se registra igual pero sin imputar a nada (queda como saldo a favor del cliente)
    $cerrado = in_array($cargo['estado'], ['pagado', 'anulado'], true);
    $manual = $cerrado ? [] : [(int) $cargo['id'] => $monto];
    try {
        $pagoId = registrar_pago(
            (int) $cargo['cliente_id'], date('Y-m-d'), $monto, 'ARS', 'mercadopago',
            'Mercado Pago #' . $paymentId . ($cerrado ? ' (el cargo ya estaba ' . $cargo['estado'] . ')' : ''), $cot, $manual, $paymentId
        );
    } catch (ErrorInternoPago $ex) {
        // Pudo ser un registro en paralelo (índice UNIQUE de mp_payment_id): no es un error
        if (fila('SELECT id FROM pagos WHERE mp_payment_id = ? AND usuario_id = {U}', [$paymentId])) {
            mp_log($paymentId, 'duplicado', 'Registrado en paralelo');
            return 'duplicado';
        }
        mp_log($paymentId, 'error', 'Error técnico al registrar (se reintenta)');
        throw $ex;                       // transitorio: el webhook responde 500 y Mercado Pago reintenta
    } catch (RuntimeException $ex) {
        mp_log($paymentId, 'error_definitivo', mb_substr($ex->getMessage(), 0, 200));
        return 'ignorado';               // un dato inválido no se arregla reintentando: se responde 200 y queda en el log
    }
    if ($cerrado) {
        mp_log($paymentId, 'registrado_sin_imputar', 'Cargo ' . $cargo['id'] . ' ya ' . $cargo['estado'] . ' — ' . fmt_monto($monto));
        notificar(
            'mp_sin_imputar', 'Mercado Pago: pago sobre un cargo cerrado',
            'Entró un pago de ' . fmt_monto($monto) . " por Mercado Pago (#$paymentId) para el cargo «" . $cargo['concepto'] . "», que ya estaba " . $cargo['estado']
            . ". Se registró sin imputar (saldo a favor del cliente). Revisalo en la ficha del cliente.",
            [['tipo' => 'mp_sin_imputar', 'id' => $pagoId, 'dias' => 0, 'venc' => '2000-01-01']]
        );
        return 'registrado';
    }
    mp_log($paymentId, 'registrado', 'Cargo ' . $cargo['id'] . ' — ' . fmt_monto($monto));
    return 'registrado';
}

/**
 * Valida la firma x-signature de Mercado Pago con la clave secreta del usuario.
 * Manifiesto: "id:<data.id>;request-id:<x-request-id>;ts:<ts>;" firmado con HMAC-SHA256. Rechaza firmas con más de
 * 5 minutos de antigüedad (anti-repetición). Si el usuario guardó una clave pero no se puede descifrar (se perdió o
 * cambió la clave maestra) NO se acepta nada sin firma: falla cerrado.
 */
function mp_firma_valida(string $dataId, string $xSignature, string $xRequestId): bool
{
    $secreto = cred('mercadopago.webhook_secret');
    if ($secreto === '') {
        return !cfg_existe('mp_webhook_secret');   // sin clave guardada: queda solo el token de la URL; guardada pero ilegible: se rechaza
    }
    $ts = $v1 = '';
    foreach (explode(',', $xSignature) as $parte) {
        $kv = explode('=', trim($parte), 2);
        if (count($kv) === 2) {
            if ($kv[0] === 'ts') {
                $ts = $kv[1];
            } elseif ($kv[0] === 'v1') {
                $v1 = $kv[1];
            }
        }
    }
    if ($ts === '' || $v1 === '' || !ctype_digit($ts)) {
        return false;
    }
    $seg = (int) $ts > 100000000000 ? intdiv((int) $ts, 1000) : (int) $ts;     // Mercado Pago manda milisegundos o segundos
    if (abs(time() - $seg) > 300) {
        return false;
    }
    $id = ctype_alnum($dataId) ? strtolower($dataId) : $dataId;
    $manifiesto = "id:$id;request-id:$xRequestId;ts:$ts;";
    return hash_equals(hash_hmac('sha256', $manifiesto, $secreto), $v1);
}
