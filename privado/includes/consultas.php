<?php
/**
 * consultas.php — Consultas con filtros compartidas entre los listados en pantalla y la exportación a CSV.
 * Todas filtran por el usuario en contexto ({U}); ver db.php.
 */
declare(strict_types=1);

/** Escapa los comodines de LIKE y arma el patrón %texto%. */
function patron_like(string $texto): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto) . '%';
}

/**
 * Cargos filtrados por estado, período y texto.
 * Estados: 'abiertos' (pendientes y parciales EXIGIBLES), 'a_vencer' (cuotas futuras), 'pendiente', 'parcial',
 * 'pagado', 'anulado' o 'todos'.
 */
function cargos_filtrados(string $estado, string $periodo, string $buscar, int $limite = 300): array
{
    $extra = [];
    $params = [];
    if ($estado === 'abiertos') {
        $extra[] = "ca.estado IN ('pendiente','parcial') AND " . sql_exigible('ca');
    } elseif ($estado === 'a_vencer') {
        $extra[] = "ca.estado IN ('pendiente','parcial') AND ca.plan_id IS NOT NULL AND ca.fecha_vencimiento > CURDATE()";
    } elseif (in_array($estado, ['pendiente', 'parcial', 'pagado', 'anulado'], true)) {
        $extra[] = 'ca.estado = ?';
        $params[] = $estado;
    }
    if (preg_match('/^\d{4}-\d{2}$/', $periodo)) {
        $extra[] = 'ca.periodo = ?';
        $params[] = $periodo;
    }
    if ($buscar !== '') {
        $extra[] = '(c.nombre LIKE ? OR ca.concepto LIKE ?)';
        $like = patron_like($buscar);
        array_push($params, $like, $like);
    }
    return filas(
        'SELECT ca.*, c.nombre AS cliente FROM cargos ca JOIN clientes c ON c.id = ca.cliente_id
         WHERE ca.usuario_id = {U} AND c.usuario_id = {U}' . ($extra ? ' AND ' . implode(' AND ', $extra) : '')
        . ' ORDER BY ca.fecha_vencimiento DESC, ca.id DESC LIMIT ' . max(1, $limite),
        $params
    );
}

/** Clientes filtrados; cada fila trae n_serv, n_dom y 'deuda' => ['ARS' => x, 'USD' => y] (deuda exigible). */
function clientes_filtrados(string $buscar, string $estado, bool $soloDeuda): array
{
    $extra = [];
    $params = [];
    if ($buscar !== '') {
        $extra[] = '(c.nombre LIKE ? OR c.contacto LIKE ? OR c.email LIKE ? OR c.cuit LIKE ? OR c.telefono LIKE ?)';
        $like = patron_like($buscar);
        array_push($params, $like, $like, $like, $like, $like);
    }
    if (in_array($estado, ['activo', 'inactivo'], true)) {
        $extra[] = 'c.estado = ?';
        $params[] = $estado;
    }
    $clientes = filas(
        "SELECT c.*,
            (SELECT COUNT(*) FROM servicios s WHERE s.usuario_id = {U} AND s.cliente_id = c.id AND s.estado = 'activo') AS n_serv,
            (SELECT COUNT(*) FROM dominios d WHERE d.usuario_id = {U} AND d.cliente_id = c.id AND d.estado = 'activo') AS n_dom
         FROM clientes c WHERE c.usuario_id = {U}" . ($extra ? ' AND ' . implode(' AND ', $extra) : '') . ' ORDER BY c.nombre',
        $params
    );
    $deudas = [];
    foreach (filas(
        "SELECT ca.cliente_id, ca.moneda, SUM(ca.monto - ca.monto_pagado) AS saldo FROM cargos ca
         WHERE ca.usuario_id = {U} AND ca.estado IN ('pendiente','parcial') AND " . sql_exigible('ca') . '
         GROUP BY ca.cliente_id, ca.moneda'
    ) as $f) {
        $deudas[$f['cliente_id']][$f['moneda']] = (float) $f['saldo'];
    }
    foreach ($clientes as &$c) {
        $c['deuda'] = ['ARS' => $deudas[$c['id']]['ARS'] ?? 0.0, 'USD' => $deudas[$c['id']]['USD'] ?? 0.0];
        $c['tiene_deuda'] = $c['deuda']['ARS'] > 0.004 || $c['deuda']['USD'] > 0.004;
    }
    unset($c);
    return $soloDeuda ? array_values(array_filter($clientes, fn($c) => $c['tiene_deuda'])) : $clientes;
}

/** Cliente del usuario por id (o null): es la comprobación de "este id es mío" que usan pantallas y acciones. */
function cliente_propio(int $id): ?array
{
    return $id > 0 ? fila('SELECT * FROM clientes WHERE id = ? AND usuario_id = {U}', [$id]) : null;
}
