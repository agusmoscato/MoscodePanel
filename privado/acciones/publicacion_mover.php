<?php
/**
 * Cambia la fecha de una publicación: arrastrar y soltar en el calendario de escritorio. Se llama por fetch (app.js)
 * con el token CSRF y responde JSON (el toast de confirmación lo arma app.js con el "mensaje").
 */
header('Content-Type: application/json; charset=utf-8');
$id = (int) post('id', '0');
$fecha = post('fecha');
if (!publicacion_mover($id, $fecha)) {
    http_response_code(publicacion_obtener($id) ? 422 : 404);
    echo json_encode(['ok' => false, 'mensaje' => 'No se pudo mover la publicación.']);
    exit;
}
echo json_encode(['ok' => true, 'fecha' => $fecha, 'mensaje' => 'Publicación movida al ' . fecha_larga($fecha) . '.'], JSON_UNESCAPED_UNICODE);
