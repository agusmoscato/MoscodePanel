<?php
/**
 * webhook_mp.php — Recibe las notificaciones de Mercado Pago.
 * URL a configurar en Mercado Pago (una por usuario, se ve en Configuración):  https://TUDOMINIO.com/webhook/mp/<token del usuario>   (la vieja /webhook_mp.php?token=… sigue funcionando)
 *
 * Seguridad: (1) token secreto en la URL (identifica al usuario), (2) firma x-signature si el usuario cargó su clave, y
 * (3) nunca se confía en el contenido: el pago se consulta a la API de Mercado Pago.
 * Responde 200 rápido; ante un error transitorio responde 500 para que Mercado Pago reintente.
 */
declare(strict_types=1);

$raiz = RAIZ_PRIVADA;
require_once $raiz . '/includes/mercadopago.php';

header('Content-Type: text/plain; charset=utf-8');

// Cada usuario tiene su URL con su token: el token identifica al dueño del pago y se usa SU access token.
$dueno = usuario_por_webhook_token((string) ($_GET['token'] ?? ''));
if (!$dueno) {
    http_response_code(403);
    exit('Token inválido');
}
if (!(int) $dueno['activo']) {
    http_response_code(200);
    exit('Cuenta desactivada');
}
fijar_usuario((int) $dueno['id']);
if (cfg('mp_activo', '0') !== '1') {
    http_response_code(200);
    exit('Mercado Pago desactivado');
}
// El ID del pago llega en la query (data.id) y/o en el cuerpo JSON {"type":"payment","data":{"id":"123"}}
$cuerpo = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);   // tope de 64 KB
$tipo = (string) ($_GET['type'] ?? $_GET['topic'] ?? ($cuerpo['type'] ?? ''));
$id = (string) ($_GET['data_id'] ?? $_GET['data.id'] ?? $_GET['id'] ?? ($cuerpo['data']['id'] ?? ''));

if ($tipo !== 'payment' || $id === '') {
    http_response_code(200);      // otros eventos (merchant_order, etc.): se ignoran
    exit('ignorado');
}
if (!mp_firma_valida($id, (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''), (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''))) {
    mp_log($id, 'firma_invalida', 'x-signature no coincide');
    http_response_code(401);
    exit('Firma inválida');
}

try {
    $r = mp_procesar_pago($id);
    http_response_code(200);
    echo $r;
} catch (Throwable $ex) {
    error_log('Webhook MP: ' . $ex->getMessage());
    http_response_code(500);
    echo 'error';
}
