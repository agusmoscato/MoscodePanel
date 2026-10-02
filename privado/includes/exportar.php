<?php
/**
 * exportar.php — Exportación a CSV que abre bien en Excel: UTF-8 con BOM, separador ";",
 * decimales con coma y fechas dd/mm/aaaa.
 */
declare(strict_types=1);

/** Formatea una celda. Protege contra "inyección de fórmulas" de Excel (=, +, -, @ al inicio de un texto). */
function csv_celda($v): string
{
    if ($v === null) {
        return '';
    }
    if (is_float($v) || is_int($v)) {
        return is_int($v) ? (string) $v : number_format($v, 2, ',', '');
    }
    $s = (string) $v;
    if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) {
        $s = "'" . $s;
    }
    return $s;
}

/** Envía el CSV al navegador y termina. */
function csv_enviar(string $nombre, array $encabezados, array $filas): never
{
    $archivo = preg_replace('/[^a-z0-9_\-]/i', '_', $nombre) . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Cache-Control: no-store');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");   // BOM para que Excel detecte UTF-8
    fputcsv($o, array_map('csv_celda', $encabezados), ';', '"', '\\', "\r\n");
    foreach ($filas as $f) {
        fputcsv($o, array_map('csv_celda', $f), ';', '"', '\\', "\r\n");
    }
    fclose($o);
    exit;
}

/**
 * Datos de cada exportación: devuelve [nombre_archivo, encabezados, filas] o null si el tipo no existe.
 * Los filtros llegan en $p (los mismos parámetros que usan los listados).
 */
function datos_export(string $tipo, array $p): ?array
{
    $txt = fn(string $k, string $def = ''): string => is_string($p[$k] ?? null) ? trim($p[$k]) : $def;
    $anio = (int) ($txt('anio') ?: date('Y'));
    $cot = cotizacion_valor();

    switch ($tipo) {
        case 'clientes':
        case 'deudores':
            $lista = clientes_filtrados($txt('q'), $txt('estado', $tipo === 'deudores' ? 'todos' : 'activo'), $tipo === 'deudores' || $txt('deuda') === '1');
            $filas = [];
            foreach ($lista as $c) {
                $filas[] = [$c['nombre'], $c['contacto'], $c['email'], $c['telefono'], $c['cuit'], $c['estado'],
                    (int) $c['n_serv'], (int) $c['n_dom'], (float) $c['deuda']['ARS'], (float) $c['deuda']['USD'],
                    $cot ? ars_equivalente($c['deuda'], $cot) : null];
            }
            if ($tipo === 'deudores') {
                usort($filas, fn($a, $b) => ($b[10] ?? $b[8]) <=> ($a[10] ?? $a[8]));
            }
            return [$tipo, ['Cliente', 'Contacto', 'Email', 'Teléfono', 'CUIT', 'Estado', 'Servicios activos', 'Dominios activos', 'Deuda ARS', 'Deuda USD', 'Deuda total en ARS (dólar vigente)'], $filas];

        case 'servicios':
            $filas = [];
            foreach (filas('SELECT s.*, c.nombre AS cliente FROM servicios s JOIN clientes c ON c.id = s.cliente_id WHERE s.usuario_id = {U} AND c.usuario_id = {U} ORDER BY c.nombre, s.nombre') as $s) {
                $filas[] = [$s['cliente'], $s['nombre'], (float) $s['monto'], $s['moneda'], $s['tipo_cobro'], fmt_fecha($s['fecha_inicio']), $s['tipo_cobro'] === 'anual' ? fmt_fecha($s['proximo_vencimiento']) : '', $s['estado']];
            }
            return ['servicios', ['Cliente', 'Servicio', 'Monto', 'Moneda', 'Cobro', 'Inicio', 'Próx. vencimiento', 'Estado'], $filas];

        case 'dominios':
            $filas = [];
            foreach (filas('SELECT d.*, c.nombre AS cliente FROM dominios d JOIN clientes c ON c.id = d.cliente_id WHERE d.usuario_id = {U} AND c.usuario_id = {U} ORDER BY d.fecha_vencimiento') as $d) {
                $filas[] = [$d['dominio'], $d['cliente'], $d['proveedor'], fmt_fecha($d['fecha_vencimiento']), (float) $d['costo_renovacion'], $d['moneda_costo'], (float) $d['precio_cliente'], $d['moneda_precio'], $d['estado']];
            }
            return ['dominios', ['Dominio', 'Cliente', 'Proveedor', 'Vencimiento', 'Costo renovación', 'Moneda costo', 'Precio cliente', 'Moneda precio', 'Estado'], $filas];

        case 'cargos':
            $filas = [];
            foreach (cargos_filtrados($txt('estado', 'abiertos'), $txt('periodo'), $txt('q'), 50000) as $c) {
                $filas[] = [$c['cliente'], $c['concepto'], $c['periodo'], fmt_fecha($c['fecha_vencimiento']), (float) $c['monto'], $c['moneda'], (float) $c['monto_pagado'], round((float) $c['monto'] - (float) $c['monto_pagado'], 2), $c['estado']];
            }
            return ['cargos', ['Cliente', 'Concepto', 'Período', 'Vencimiento', 'Monto', 'Moneda', 'Pagado', 'Saldo', 'Estado'], $filas];

        case 'pagos':
            $filas = [];
            $params = [];
            $sql = 'SELECT p.*, c.nombre AS cliente FROM pagos p JOIN clientes c ON c.id = p.cliente_id WHERE p.usuario_id = {U} AND c.usuario_id = {U}';
            if (ctype_digit($txt('anio'))) {
                $sql .= ' AND p.fecha >= ? AND p.fecha < ?';
                $params[] = (int) $txt('anio') . '-01-01';
                $params[] = ((int) $txt('anio') + 1) . '-01-01';
            }
            foreach (filas($sql . ' ORDER BY p.fecha DESC, p.id DESC', $params) as $x) {
                $filas[] = [fmt_fecha($x['fecha']), $x['cliente'], (float) $x['monto'], $x['moneda'], $x['medio'], (float) $x['cotizacion_usada'], $x['nota'],
                    $x['anulado_en'] ? 'Anulado' : 'Vigente', $x['anulado_en'] ? fmt_fecha_hora($x['anulado_en']) : '', (string) $x['anulado_motivo']];
            }
            return ['pagos', ['Fecha', 'Cliente', 'Monto', 'Moneda', 'Medio', 'Cotización usada', 'Nota', 'Estado', 'Anulado el', 'Motivo de la anulación'], $filas];

        case 'rep_ingresos':
            $filas = [];
            foreach (rep_ingresos_mensual($anio) as $r) {
                $filas[] = [MESES_CORTOS[$r['mes']] . ' ' . $anio, $r['ars'], $r['usd'], $r['total_ars']];
            }
            return ["ingresos_$anio", ['Mes', 'Cobrado en ARS', 'Cobrado en USD', 'Total en ARS (cotización de cada pago)'], $filas];

        case 'rep_facturado':
            $filas = [];
            foreach (rep_facturado_mensual($anio, $cot) as $r) {
                $filas[] = [MESES_CORTOS[$r['mes']] . ' ' . $anio, $r['facturado'], $r['cobrado'], $r['pendiente']];
            }
            return ["facturado_vs_cobrado_$anio", ['Mes', 'Facturado (ARS)', 'Cobrado (ARS)', 'Pendiente (ARS)'], $filas];

        case 'rep_ranking':
            $filas = [];
            foreach (rep_ranking_clientes($anio, $cot) as $i => $r) {
                $filas[] = [$i + 1, $r['cliente'], $r['facturado'], $r['cobrado'], $r['pendiente']];
            }
            return ["ranking_clientes_$anio", ['#', 'Cliente', 'Facturado (ARS)', 'Cobrado (ARS)', 'Pendiente (ARS)'], $filas];

        case 'rep_dominios':
            $filas = [];
            foreach (rep_rentabilidad_dominios($cot) as $d) {
                $filas[] = [$d['dominio'], $d['cliente'], $d['precio_ars'], $d['costo_ars'], $d['margen'], $d['margen_pct'] === null ? '' : number_format($d['margen_pct'], 1, ',', '') . '%'];
            }
            return ['rentabilidad_dominios', ['Dominio', 'Cliente', 'Precio al cliente (ARS)', 'Costo renovación (ARS)', 'Margen (ARS)', 'Margen %'], $filas];
    }
    return null;
}
