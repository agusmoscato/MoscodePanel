<?php
/**
 * Marca el aviso de renovación anual (de un servicio anual o de un dominio) como enviado. Se llama por fetch
 * (ver app.js), no por un <form> normal: el link de WhatsApp va directo en el href del botón y lo abre el
 * propio navegador con el clic — así no choca con la CSP (form-action 'self' no permite que un <form> termine
 * en wa.me).
 */
header('Content-Type: application/json; charset=utf-8');
$tipo = post('tipo');
$id = (int) post('id', '0');
if ($tipo === 'servicio') {
    $tabla = 'servicios';
    $existe = fila("SELECT id FROM servicios WHERE id = ? AND usuario_id = {U} AND tipo_cobro = 'anual'", [$id]);
} elseif ($tipo === 'dominio') {
    $tabla = 'dominios';
    $existe = fila("SELECT id FROM dominios WHERE id = ? AND usuario_id = {U} AND estado = 'activo'", [$id]);
} else {
    $tabla = '';
    $existe = null;
}
if (!$existe) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}
actualizar($tabla, $id, ['aviso_renovacion_enviado_en' => date('Y-m-d H:i:s')]);
echo json_encode(['ok' => true, 'fecha' => date('d/m')]);
