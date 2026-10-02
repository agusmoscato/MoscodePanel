<?php
/**
 * reportes.php — Datos de los reportes (se usan en pantalla y en la exportación a CSV).
 *
 * Criterios:
 *  - "Ingresos" = pagos recibidos (por fecha de pago). Los pagos en USD se pasan a ARS con la
 *    cotización que se guardó en cada pago.
 *  - "Facturado" = cargos del período (no anulados). Los cargos en USD se pasan a ARS con la
 *    cotización guardada en cada cargo el día que se generó (si un cargo no la tiene, con la
 *    vigente hoy). Así los reportes de meses pasados no cambian cuando se mueve el dólar.
 */
declare(strict_types=1);

/** Factor para pasar un monto de cargo a ARS: 1 si es ARS; si es USD, su cotización o (si no tiene) el ? de respaldo. */
const SQL_FACTOR_ARS = "IF(moneda = 'USD', COALESCE(cotizacion, ?), 1)";
const SQL_FACTOR_ARS_CARGO = "IF(ca.moneda = 'USD', COALESCE(ca.cotizacion, ?), 1)";

const MESES_CORTOS = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

/** ARS equivalente de [moneda => monto] con una cotización (USD sin cotización cuenta 0). */
function ars_equivalente(array $porMoneda, ?float $cot): float
{
    return round((float) ($porMoneda['ARS'] ?? 0) + (float) ($porMoneda['USD'] ?? 0) * (float) $cot, 2);
}

/** Ingresos por mes de un año: 12 filas [mes, ars, usd, total_ars]. */
function rep_ingresos_mensual(int $anio): array
{
    $meses = [];
    for ($m = 1; $m <= 12; $m++) {
        $meses[$m] = ['mes' => $m, 'ars' => 0.0, 'usd' => 0.0, 'total_ars' => 0.0];
    }
    foreach (filas(
        'SELECT MONTH(fecha) AS m, moneda, SUM(monto) AS total, SUM(monto * cotizacion_usada) AS total_ars
         FROM pagos WHERE usuario_id = {U} AND anulado_en IS NULL AND fecha >= ? AND fecha < ? GROUP BY MONTH(fecha), moneda',
        [$anio . '-01-01', ($anio + 1) . '-01-01']
    ) as $f) {
        $m = (int) $f['m'];
        if ($f['moneda'] === 'USD') {
            $meses[$m]['usd'] += (float) $f['total'];
            $meses[$m]['total_ars'] += (float) $f['total_ars'];
        } else {
            $meses[$m]['ars'] += (float) $f['total'];
            $meses[$m]['total_ars'] += (float) $f['total'];
        }
    }
    foreach ($meses as &$r) {
        $r['total_ars'] = round($r['total_ars'], 2);
    }
    unset($r);
    return array_values($meses);
}

/** Ingresos por año: filas [anio, ars, usd, total_ars], del más viejo al más nuevo. */
function rep_ingresos_anual(): array
{
    $anios = [];
    foreach (filas(
        'SELECT YEAR(fecha) AS a, moneda, SUM(monto) AS total, SUM(monto * cotizacion_usada) AS total_ars
         FROM pagos WHERE usuario_id = {U} AND anulado_en IS NULL GROUP BY YEAR(fecha), moneda ORDER BY a'
    ) as $f) {
        $a = (int) $f['a'];
        $anios[$a] ??= ['anio' => $a, 'ars' => 0.0, 'usd' => 0.0, 'total_ars' => 0.0];
        if ($f['moneda'] === 'USD') {
            $anios[$a]['usd'] += (float) $f['total'];
            $anios[$a]['total_ars'] += (float) $f['total_ars'];
        } else {
            $anios[$a]['ars'] += (float) $f['total'];
            $anios[$a]['total_ars'] += (float) $f['total'];
        }
    }
    return array_values($anios);
}

/** Facturado vs cobrado vs pendiente por mes (período del cargo) de un año, en ARS. */
function rep_facturado_mensual(int $anio, ?float $cot): array
{
    $meses = [];
    for ($m = 1; $m <= 12; $m++) {
        $meses[$m] = ['facturado' => 0.0, 'cobrado' => 0.0];
    }
    // Cada cargo se pasa a pesos con SU cotización (los de ARS no se convierten). El primer ?
    // del factor es la cotización de respaldo para cargos en USD sin cotización guardada.
    $factor = SQL_FACTOR_ARS;
    foreach (filas(
        "SELECT CAST(SUBSTR(periodo, 6, 2) AS UNSIGNED) AS m,
                SUM(monto * $factor) AS facturado,
                SUM(monto_pagado * $factor) AS cobrado
         FROM cargos WHERE usuario_id = {U} AND periodo LIKE ? AND estado <> 'anulado' GROUP BY m",
        [(float) $cot, (float) $cot, $anio . '-%']
    ) as $f) {
        $meses[(int) $f['m']] = ['facturado' => (float) $f['facturado'], 'cobrado' => (float) $f['cobrado']];
    }
    $out = [];
    foreach ($meses as $m => $r) {
        $out[] = [
            'mes' => $m, 'facturado' => round($r['facturado'], 2), 'cobrado' => round($r['cobrado'], 2),
            'pendiente' => round($r['facturado'] - $r['cobrado'], 2),
        ];
    }
    return $out;
}

/** Ranking de clientes por facturación de un año: filas [cliente_id, cliente, facturado, cobrado, pendiente] en ARS. */
function rep_ranking_clientes(int $anio, ?float $cot): array
{
    $out = [];
    $factor = SQL_FACTOR_ARS_CARGO;   // cotización de cada cargo; el ? es el respaldo si no la tiene
    foreach (filas(
        "SELECT c.id, c.nombre,
                SUM(ca.monto * $factor) AS facturado,
                SUM(ca.monto_pagado * $factor) AS cobrado
         FROM cargos ca JOIN clientes c ON c.id = ca.cliente_id
         WHERE ca.usuario_id = {U} AND c.usuario_id = {U} AND ca.periodo LIKE ? AND ca.estado <> 'anulado' GROUP BY c.id, c.nombre",
        [(float) $cot, (float) $cot, $anio . '-%']
    ) as $f) {
        $fa = round((float) $f['facturado'], 2);
        $co = round((float) $f['cobrado'], 2);
        $out[] = ['cliente_id' => (int) $f['id'], 'cliente' => $f['nombre'], 'facturado' => $fa, 'cobrado' => $co, 'pendiente' => round($fa - $co, 2)];
    }
    usort($out, fn($a, $b) => $b['facturado'] <=> $a['facturado']);
    return $out;
}

/**
 * Rentabilidad de dominios activos (por renovación anual): precio al cliente - costo de renovación, en ARS.
 * 'margen' es null si hay un monto en USD y no hay cotización.
 */
function rep_rentabilidad_dominios(?float $cot): array
{
    $out = [];
    foreach (filas(
        "SELECT d.*, c.nombre AS cliente FROM dominios d JOIN clientes c ON c.id = d.cliente_id
         WHERE d.usuario_id = {U} AND c.usuario_id = {U} AND d.estado = 'activo' ORDER BY d.dominio"
    ) as $d) {
        $necesitaCot = $d['moneda_precio'] === 'USD' || $d['moneda_costo'] === 'USD';
        if ($necesitaCot && !$cot) {
            $out[] = $d + ['precio_ars' => null, 'costo_ars' => null, 'margen' => null, 'margen_pct' => null];
            continue;
        }
        $precio = $d['moneda_precio'] === 'USD' ? (float) $d['precio_cliente'] * $cot : (float) $d['precio_cliente'];
        $costo = $d['moneda_costo'] === 'USD' ? (float) $d['costo_renovacion'] * $cot : (float) $d['costo_renovacion'];
        $out[] = $d + [
            'precio_ars' => round($precio, 2), 'costo_ars' => round($costo, 2), 'margen' => round($precio - $costo, 2),
            'margen_pct' => $precio > 0 ? round(($precio - $costo) / $precio * 100, 1) : null,
        ];
    }
    return $out;
}

/**
 * Saldo que falta cobrar de TODOS los planes en cuotas activos (vencidas y a vencer): por moneda y en pesos
 * (los dólares al valor de hoy: es un saldo futuro, así que se mira con el dólar vigente).
 * Devuelve ['por_moneda' => ['ARS' => x, 'USD' => y], 'total_ars' => ?float, 'cuotas' => n pendientes, 'planes' => n activos].
 */
function rep_saldo_cuotas(?float $cot): array
{
    $f = filas(
        "SELECT ca.moneda, SUM(ca.monto - ca.monto_pagado) AS saldo, COUNT(*) AS n, COUNT(DISTINCT ca.plan_id) AS planes
         FROM cargos ca JOIN planes_pago pl ON pl.id = ca.plan_id
         WHERE ca.usuario_id = {U} AND pl.usuario_id = {U} AND pl.estado = 'activo'
           AND ca.estado IN ('pendiente','parcial') GROUP BY ca.moneda"
    );
    $porMoneda = por_moneda($f, 'saldo');
    return [
        'por_moneda' => $porMoneda,
        'total_ars'  => a_ars($porMoneda, $cot),
        'cuotas'     => (int) array_sum(array_column($f, 'n')),
        'planes'     => (int) valor("SELECT COUNT(*) FROM planes_pago WHERE usuario_id = {U} AND estado = 'activo'"),
    ];
}
