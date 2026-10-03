<?php
/**
 * Renueva un dominio por un año (esto se hace DESPUÉS de renovarlo de verdad en el proveedor). No cambia el
 * cargo: si todavía no existe el de este período (el anticipo automático no llegó a generarlo, o el precio
 * recién se cargó), lo crea acá; si ya existe, lo usa y no duplica (ver generar_cargo_dominio()).
 */
$id = (int) post('id', '0');
$esperada = post('vence');          // la fecha de vencimiento que se veía al renovar: si ya cambió, es un doble clic
$dom = fila("SELECT * FROM dominios WHERE id = ? AND usuario_id = {U} AND estado = 'activo'", [$id]);
if (!$dom) {
    redirigir(url('clientes'));
}
if (!nonce_consumir() || ($esperada !== '' && $esperada !== $dom['fecha_vencimiento'])) {
    flash('error', 'Ese dominio ya se renovó (o el formulario venció). Revisá la fecha de vencimiento actual.');
    redirigir(url('cliente', ['id' => $dom['cliente_id']]));
}
$nuevo = date('Y-m-d', strtotime($dom['fecha_vencimiento'] . ' +1 year'));

$pdo = db();
$pdo->beginTransaction();
try {
    // UPDATE condicionado a la fecha esperada: dos pedidos simultáneos no suman dos años
    $n = q(
        'UPDATE dominios SET fecha_vencimiento = ?, aviso_renovacion_enviado_en = NULL WHERE id = ? AND usuario_id = {U} AND fecha_vencimiento = ?',
        [$nuevo, $id, $dom['fecha_vencimiento']]
    )->rowCount();
    if ($n !== 1) {
        throw new RuntimeException('Ese dominio ya se renovó.');
    }
    $msg = 'Dominio renovado hasta ' . fmt_fecha($nuevo) . '.';
    if ((float) $dom['precio_cliente'] > 0) {
        // $dom todavía tiene la fecha VIEJA (la leída antes del UPDATE): es el período que se está cerrando.
        $msg .= generar_cargo_dominio($dom) ? ' Se generó el cargo al cliente.' : ' Ya existía el cargo de este período.';
    }
    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!($ex instanceof RuntimeException)) {
        error_log('dominio_renovar: ' . $ex->getMessage());
    }
    flash('error', $ex instanceof RuntimeException ? $ex->getMessage() : 'No se pudo renovar el dominio. Intentá de nuevo.');
    redirigir(url('cliente', ['id' => $dom['cliente_id']]));
}
flash('ok', $msg);
redirigir(url('cliente', ['id' => $dom['cliente_id']]));
