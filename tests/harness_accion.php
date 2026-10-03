<?php
/**
 * harness_accion.php — Corre UNA acción del panel fuera de HTTP, en su propio proceso (lo usa
 * ejecutar_accion() de soporte.php; no se llama a mano).
 *   php harness_accion.php <usuario_id> <accion> '<json de $_POST, en base64>'
 * (en base64 porque escapeshellarg() en Windows borra las comillas dobles de un argumento normal)
 */
declare(strict_types=1);

require __DIR__ . '/soporte.php';
preparar_entorno_app(config_db_prueba());   // misma base que ya preparó tests/run.php: NO la recrea

$usuarioId = (int) $argv[1];
$accion = $argv[2];
$post = json_decode(base64_decode($argv[3] ?? ''), true) ?: [];

if (!isset($post['nonce'])) {
    $n = bin2hex(random_bytes(16));
    $_SESSION['nonces'] = [$n => time()];
    $post['nonce'] = $n;
}
$_POST = $post;
$_SERVER['REQUEST_METHOD'] = 'POST';
fijar_usuario($usuarioId);
// Lo mismo que deja panel.php (require_login) para las acciones: varias usan $usuario['id'] (ej. quién anuló un pago)
$usuario = fila('SELECT id, usuario, nombre, email, rol, activo, debe_cambiar_clave, sesion_version FROM usuarios WHERE id = ?', [$usuarioId]);

require RAIZ_PROYECTO . '/privado/acciones/' . $accion . '.php';
