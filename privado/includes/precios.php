<?php
/**
 * precios.php — Ajuste de precios por porcentaje con vista previa.
 */
declare(strict_types=1);

const REDONDEOS = [
    'ninguno'  => 'Sin redondeo (2 decimales)',
    'entero'   => 'Redondear al entero',
    'centenas' => 'Pesos a la centena más cercana (dólares al entero)',
];

/** Lee y valida los parámetros del ajuste (de GET o POST). Devuelve [params, error|null]. */
function parametros_ajuste(array $src): array
{
    $txt = fn(string $k): string => is_string($src[$k] ?? null) ? trim($src[$k]) : '';   // ignora valores tipo array
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($src['clientes'] ?? [])))));
    $p = [
        'clientes'  => $ids,
        'porcentaje' => a_porcentaje($txt('porcentaje')),
        'moneda'    => in_array($txt('moneda'), ['ARS', 'USD'], true) ? $txt('moneda') : '',
        'tipo'      => in_array($txt('tipo'), ['mensual', 'anual'], true) ? $txt('tipo') : '',
        'pausados'  => $txt('pausados') === '1',
        'redondeo'  => isset(REDONDEOS[$txt('redondeo')]) ? $txt('redondeo') : 'ninguno',
    ];
    $err = null;
    if (!$ids) {
        $err = 'Elegí al menos un cliente.';
    } elseif (count($ids) > 500) {
        $err = 'Elegiste demasiados clientes a la vez (máximo 500).';
    } elseif ($p['porcentaje'] == 0.0) {
        $err = 'Ingresá un porcentaje válido, distinto de cero y entre -90 y 1000.';
    } elseif ($p['porcentaje'] < -90 || $p['porcentaje'] > 1000) {
        $err = 'El porcentaje debe estar entre -90 y 1000.';
    }
    return [$p, $err];
}

/** Aplica el porcentaje y el redondeo a un monto. */
function monto_ajustado(float $monto, float $porcentaje, string $redondeo, string $moneda): float
{
    $n = $monto * (1 + $porcentaje / 100);
    if ($redondeo === 'entero' || ($redondeo === 'centenas' && $moneda === 'USD')) {
        $n = round($n);
    } elseif ($redondeo === 'centenas') {
        $n = round($n / 100) * 100;
    }
    return round($n, 2);
}

/** Servicios afectados con su monto nuevo. Cada fila: id, cliente, nombre, moneda, tipo_cobro, actual, nuevo. */
function calcular_ajuste(array $p): array
{
    $marcas = implode(',', array_fill(0, count($p['clientes']), '?'));
    $sql = "SELECT s.id, s.nombre, s.monto, s.moneda, s.tipo_cobro, s.por_cantidad, s.cantidad, c.id AS cliente_id, c.nombre AS cliente
            FROM servicios s JOIN clientes c ON c.id = s.cliente_id
            WHERE s.usuario_id = {U} AND c.usuario_id = {U} AND s.cliente_id IN ($marcas) AND s.estado " . ($p['pausados'] ? "IN ('activo','pausado')" : "= 'activo'");
    $params = $p['clientes'];
    if ($p['moneda'] !== '') {
        $sql .= ' AND s.moneda = ?';
        $params[] = $p['moneda'];
    }
    if ($p['tipo'] !== '') {
        $sql .= ' AND s.tipo_cobro = ?';
        $params[] = $p['tipo'];
    }
    $filas = filas($sql . ' ORDER BY c.nombre, s.nombre', $params);
    foreach ($filas as &$f) {
        $f['actual'] = (float) $f['monto'];
        $f['nuevo'] = monto_ajustado($f['actual'], $p['porcentaje'], $p['redondeo'], $f['moneda']);
        if ($f['nuevo'] < 0.01 || $f['nuevo'] > MONTO_MAX) {
            $f['nuevo'] = $f['actual'];          // el ajuste daría un monto imposible (cero o enorme): ese servicio no se toca
        }
    }
    unset($f);
    return $filas;
}

/** Huella de lo que se vio en la vista previa: si cambia algo antes de aplicar, se rechaza. */
function firma_ajuste(array $filas): string
{
    return sha1(implode('|', array_map(fn($f) => $f['id'] . ':' . $f['actual'] . '>' . $f['nuevo'], $filas)));
}

/** Aplica el ajuste en una transacción y deja el historial de precios. Devuelve cantidad de servicios. */
function aplicar_ajuste(array $filas, float $porcentaje): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $n = 0;
        foreach ($filas as $f) {
            if (abs($f['nuevo'] - $f['actual']) < 0.005) {
                continue;
            }
            insertar('servicios_precios_hist', [
                'servicio_id' => $f['id'], 'monto_anterior' => $f['actual'], 'monto_nuevo' => $f['nuevo'],
                'porcentaje' => porcentaje_historial($f['actual'], $f['nuevo']),
                'motivo' => 'Ajuste por porcentaje (' . ($porcentaje > 0 ? '+' : '') . $porcentaje . '%)',
                'fecha' => date('Y-m-d H:i:s'),
            ]);
            // Si es por cantidad, el precio por unidad sube con el mismo porcentaje (el monto queda su múltiplo exacto).
            if ($f['por_cantidad'] && (float) $f['cantidad'] > 0) {
                $precioUnidad = round($f['nuevo'] / (float) $f['cantidad'], 2);
                q('UPDATE servicios SET precio_unidad = ?, monto = ? WHERE id = ? AND usuario_id = {U}', [$precioUnidad, round($precioUnidad * (float) $f['cantidad'], 2), $f['id']]);
            } else {
                q('UPDATE servicios SET monto = ? WHERE id = ? AND usuario_id = {U}', [$f['nuevo'], $f['id']]);
            }
            $n++;
        }
        $pdo->commit();
        return $n;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}
