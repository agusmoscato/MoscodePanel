<?php
/**
 * panel.php — Vistas y acciones del panel (las llama public_html/index.php con la ruta ya resuelta en $RUTA).
 *   vista   → privado/vistas/<nombre>.php dentro del layout            (GET)
 *   accion  → privado/acciones/<nombre>.php, solo POST con CSRF         (POST)
 *
 * Todo corre en el contexto del usuario logueado (require_login lo fija: de ahí sale el aislamiento de datos).
 * Con contraseña temporal solo se permite la pantalla de cambio, esa acción y salir.
 */
declare(strict_types=1);

$usuario = require_login(true);
fijar_usuario((int) $usuario['id']);

$forzado = (int) $usuario['debe_cambiar_clave'] === 1;
if ($forzado && !(($RUTA['tipo'] === 'vista' && $RUTA['nombre'] === 'cambiar_clave')
        || ($RUTA['tipo'] === 'accion' && in_array($RUTA['nombre'], ['password_cambiar', 'logout'], true)))) {
    redirigir(url('cambiar_clave'));
}

// --- Acciones (modifican datos) ---
if ($RUTA['tipo'] === 'accion') {
    $accion = $RUTA['nombre'];
    $archivo = RAIZ_PRIVADA . '/acciones/' . $accion . '.php';
    if (!preg_match('/^[a-z0-9_]+$/', $accion) || !is_file($archivo)) {
        pagina_no_encontrada();
    }
    csrf_verificar();
    unset($_SESSION['viejo']);   // se vuelve a cargar solo si la acción falla con volver_con_error()
    require $archivo;
    exit;
}

// --- Vistas ---
$pagina = $RUTA['nombre'];
$archivo = RAIZ_PRIVADA . '/vistas/' . $pagina . '.php';
if (!is_file($archivo)) {
    pagina_no_encontrada();
}

$titulo = 'Panel';
ob_start();
require $archivo;
$contenido = ob_get_clean();
unset($_SESSION['viejo']);       // el formulario ya se repobló

require RAIZ_PRIVADA . '/includes/' . ($forzado ? 'layout_minimo.php' : 'layout.php');
