<?php
/**
 * Marca el aviso de renovación anual de un servicio como enviado. Se llama por fetch (ver app.js), no por un
 * <form> normal: el link de WhatsApp va directo en el href del botón y lo abre el propio navegador con el
 * clic — así no choca con la CSP (form-action 'self' no permite que un <form> termine en wa.me).
 */
header('Content-Type: application/json; charset=utf-8');
$id = (int) post('id', '0');
$s = fila("SELECT id FROM servicios WHERE id = ? AND usuario_id = {U} AND tipo_cobro = 'anual'", [$id]);
if (!$s) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}
actualizar('servicios', $id, ['aviso_renovacion_enviado_en' => date('Y-m-d H:i:s')]);
echo json_encode(['ok' => true, 'fecha' => date('d/m')]);
