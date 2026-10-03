<?php
/**
 * resumen.php — Automatizaciones de la Fase 2:
 *   - resumen del primer día hábil del mes (genera cargos + envía deuda por cliente)
 *   - avisos de vencimientos de dominios y servicios anuales
 */
declare(strict_types=1);

/** Días de aviso configurados, de mayor a menor: "30,15,7,0" → [30, 15, 7, 0]. */
function dias_aviso_config(): array
{
    $dias = [];
    foreach (explode(',', cfg('dias_aviso', '30,15,7,0')) as $d) {
        $d = trim($d);
        if ($d !== '' && ctype_digit($d) && (int) $d <= 365) {
            $dias[(int) $d] = (int) $d;
        }
    }
    rsort($dias);
    return array_values($dias) ?: [30, 15, 7, 0];
}

/**
 * Escalón de aviso que corresponde cuando faltan $dias días: el menor de los configurados
 * que sea >= $dias (null si faltan más días que el mayor escalón). $escalones va de mayor a menor.
 */
function escalon_aviso(array $escalones, int $dias): ?int
{
    $escalon = null;
    foreach ($escalones as $e) {
        if ($e >= $dias) {
            $escalon = $e;
        }
    }
    return $escalon;
}
/**
 * Arma el texto del resumen mensual por cliente. Devuelve [texto, cantidad de clientes].
 * Cuenta la deuda exigible más las cuotas de planes de pago que vencen en este mes (aunque todavía no
 * hayan vencido el primer día hábil), y las detalla aparte.
 */
function texto_resumen_deuda(string $periodo, ?float $cot): array
{
    $filas = filas(
        "SELECT c.id, c.nombre, ca.moneda,
                SUM(ca.monto - ca.monto_pagado) AS total,
                SUM(CASE WHEN ca.periodo < ? THEN ca.monto - ca.monto_pagado ELSE 0 END) AS anterior
         FROM cargos ca JOIN clientes c ON c.id = ca.cliente_id
         WHERE ca.usuario_id = {U} AND c.usuario_id = {U} AND ca.estado IN ('pendiente','parcial') AND " . sql_exigible('ca') . '
         GROUP BY c.id, c.nombre, ca.moneda',
        [$periodo]
    );
    $clientes = [];
    foreach ($filas as $f) {
        $clientes[$f['id']]['nombre'] = $f['nombre'];
        $clientes[$f['id']]['total'][$f['moneda']] = (float) $f['total'];
        $clientes[$f['id']]['anterior'][$f['moneda']] = (float) $f['anterior'];
    }
    // Cuotas que vencen este mes y todavía no vencieron: se suman al total del mes y se listan
    $totalCuotas = ['ARS' => 0.0, 'USD' => 0.0];
    foreach (filas(
        "SELECT c.id, c.nombre, ca.moneda, ca.monto - ca.monto_pagado AS saldo, ca.fecha_vencimiento, ca.concepto
         FROM cargos ca JOIN clientes c ON c.id = ca.cliente_id
         WHERE ca.usuario_id = {U} AND c.usuario_id = {U} AND ca.plan_id IS NOT NULL
           AND ca.estado IN ('pendiente','parcial') AND ca.periodo = ? AND ca.fecha_vencimiento > CURDATE()
         ORDER BY ca.fecha_vencimiento, ca.id",
        [$periodo]
    ) as $f) {
        $clientes[$f['id']]['nombre'] = $f['nombre'];
        $clientes[$f['id']]['total'][$f['moneda']] = ($clientes[$f['id']]['total'][$f['moneda']] ?? 0.0) + (float) $f['saldo'];
        $clientes[$f['id']]['anterior'][$f['moneda']] ??= 0.0;
        $clientes[$f['id']]['cuotas'][] = $f;
        $totalCuotas[$f['moneda']] += (float) $f['saldo'];
    }
    foreach ($clientes as &$c) {
        $c['ars'] = a_ars($c['total'], $cot) ?? ($c['total']['ARS'] ?? 0);
    }
    unset($c);
    uasort($clientes, fn($a, $b) => $b['ars'] <=> $a['ars']);

    $lineas = ['Resumen de cobros — ' . mes_nombre($periodo), ''];
    $granTotal = ['ARS' => 0.0, 'USD' => 0.0];
    foreach ($clientes as $c) {
        $linea = '• ' . $c['nombre'] . ': ' . fmt_por_moneda($c['total']);
        $ant = array_filter($c['anterior'], fn($v) => $v > 0.004);
        if ($ant) {
            $linea .= ' (arrastra ' . fmt_por_moneda($ant) . ' de meses anteriores)';
        }
        $lineas[] = $linea;
        foreach ($c['cuotas'] ?? [] as $q) {
            $lineas[] = '    ↳ ' . $q['concepto'] . ': ' . fmt_monto($q['saldo'], $q['moneda']) . ' (vence ' . date('d/m', strtotime($q['fecha_vencimiento'])) . ')';
        }
        foreach ($c['total'] as $m => $v) {
            $granTotal[$m] += $v;
        }
    }
    if (!$clientes) {
        $lineas[] = 'Ningún cliente tiene deuda. 🎉';
    } else {
        $lineas[] = '';
        $lineas[] = 'TOTAL A COBRAR: ' . fmt_por_moneda($granTotal);
        if ($totalCuotas['ARS'] > 0.004 || $totalCuotas['USD'] > 0.004) {
            $lineas[] = '(incluye ' . fmt_por_moneda($totalCuotas) . ' en cuotas que vencen este mes)';
        }
        $totalArs = a_ars($granTotal, $cot);
        if ($cot && $granTotal['USD'] > 0 && $totalArs !== null) {
            $lineas[] = 'Equivale a ' . fmt_monto($totalArs) . ' (dólar a ' . fmt_monto($cot) . ')';
        }
    }
    if (!hay_feriados_del_anio((int) substr($periodo, 0, 4))) {
        $lineas[] = '';
        $lineas[] = '⚠ No hay feriados cargados para ' . substr($periodo, 0, 4) . ': el día hábil puede estar mal calculado.';
    }
    return [implode("\n", $lineas), count($clientes)];
}
/**
 * Ejecuta el resumen mensual.
 *  - Normal (cron diario): actúa solo si hoy es día hábil y el resumen del mes todavía no se
 *    envió. Eso cubre el primer día hábil y, si falló o el cron no corrió, reintenta los días
 *    hábiles siguientes del mismo mes.
 *  - $forzar: ignora esas condiciones y NO marca el mes como hecho (sirve para probar).
 * Devuelve ['ejecutado' => bool, 'lineas' => string[], 'texto' => string].
 */
function ejecutar_resumen_mensual(bool $forzar = false, bool $actualizarDolar = true): array
{
    $hoy = date('Y-m-d');
    $periodo = date('Y-m');
    $log = [];

    if (!$forzar) {
        if (cfg('resumen_periodo_hecho') === $periodo) {
            return ['ejecutado' => false, 'lineas' => ["El resumen de $periodo ya se envió."], 'texto' => ''];
        }
        if (!es_dia_habil($hoy)) {
            return ['ejecutado' => false, 'lineas' => ["Hoy ($hoy) no es día hábil. Primer día hábil de $periodo: " . primer_dia_habil($periodo) . '.'], 'texto' => ''];
        }
        $log[] = "Hoy ($hoy) es día hábil y el resumen de $periodo está pendiente.";
    } else {
        $log[] = 'Ejecución forzada (prueba): no marca el mes como enviado.';
    }

    // Cotización fresca (si falla, se usa la última guardada). El cron la actualiza una sola vez para todos.
    if ($actualizarDolar) {
        $c = actualizar_cotizacion();
        $log[] = $c['ok'] ? 'Dólar actualizado.' : 'Dólar: no se pudo actualizar (' . $c['mensaje'] . '), se usa la última guardada.';
    }

    $r = generar_cargos($periodo);
    $log[] = "Cargos generados: {$r['mensuales']} mensuales, {$r['anuales']} anuales, {$r['dominios']} de dominios.";

    [$texto, $n] = texto_resumen_deuda($periodo, cotizacion_valor());
    $res = notificar(
        'resumen_mensual',
        'Resumen de cobros — ' . mes_nombre($periodo),
        $texto,
        [['tipo' => 'resumen', 'id' => 0, 'dias' => 0, 'venc' => $periodo . '-01']]
    );
    foreach ($res['detalle'] as $d) {
        $log[] = $d;
    }

    if (notificacion_exitosa($res)) {
        if (!$forzar) {
            cfg_set('resumen_periodo_hecho', $periodo);
        }
        $log[] = "Resumen enviado ($n clientes con deuda).";
    } else {
        $log[] = $forzar
            ? 'No se pudo enviar por ningún canal (revisá la configuración).'
            : 'No se pudo enviar por ningún canal: se reintenta el próximo día hábil.';
    }
    return ['ejecutado' => true, 'lineas' => $log, 'texto' => $texto];
}

/**
 * Avisos de vencimiento de dominios y servicios anuales.
 * Para cada vencimiento se determina el "escalón" de aviso que corresponde: el menor de los
 * días configurados que sea >= a los días que faltan (ej. con 30/15/7/0 y faltando 10 días,
 * corresponde el aviso de 15). Cada escalón se envía una sola vez por vencimiento.
 * Todo se manda en un único mensaje para no inundar.
 */
function ejecutar_avisos_vencimientos(): array
{
    $escalones = dias_aviso_config();
    $maximo = $escalones[0];
    $limite = date('Y-m-d', strtotime("+$maximo days"));

    $items = [];
    foreach (filas(
        "SELECT d.id, d.dominio AS nombre, d.fecha_vencimiento AS venc, c.nombre AS cliente
         FROM dominios d JOIN clientes c ON c.id = d.cliente_id
         WHERE d.usuario_id = {U} AND c.usuario_id = {U} AND d.estado = 'activo' AND d.fecha_vencimiento BETWEEN CURDATE() AND ?",
        [$limite]
    ) as $f) {
        $items[] = $f + ['tipo' => 'dominio', 'etiqueta' => 'Dominio'];
    }
    foreach (filas(
        "SELECT s.id, s.nombre, s.proximo_vencimiento AS venc, c.nombre AS cliente
         FROM servicios s JOIN clientes c ON c.id = s.cliente_id
         WHERE s.usuario_id = {U} AND c.usuario_id = {U} AND s.estado = 'activo' AND s.tipo_cobro = 'anual' AND s.proximo_vencimiento BETWEEN CURDATE() AND ?",
        [$limite]
    ) as $f) {
        $items[] = $f + ['tipo' => 'servicio', 'etiqueta' => 'Servicio anual'];
    }
    // Cuotas de planes de pago que vencen dentro de la ventana de aviso
    foreach (filas(
        "SELECT ca.id, CONCAT(pl.concepto, ' ', ca.cuota_numero, '/', ca.cuota_total) AS nombre, ca.fecha_vencimiento AS venc,
                c.nombre AS cliente, ca.monto - ca.monto_pagado AS saldo, ca.moneda
         FROM cargos ca JOIN clientes c ON c.id = ca.cliente_id JOIN planes_pago pl ON pl.id = ca.plan_id
         WHERE ca.usuario_id = {U} AND c.usuario_id = {U} AND pl.usuario_id = {U} AND ca.plan_id IS NOT NULL
           AND pl.estado = 'activo' AND ca.estado IN ('pendiente','parcial') AND ca.fecha_vencimiento BETWEEN CURDATE() AND ?",
        [$limite]
    ) as $f) {
        $items[] = $f + ['tipo' => 'cuota', 'etiqueta' => 'Cuota'];
    }

    $pendientes = [];
    $refs = [];
    foreach ($items as $it) {
        $dias = dias_hasta($it['venc']);
        $escalon = escalon_aviso($escalones, $dias);
        if ($escalon === null || ya_avisado($it['tipo'], (int) $it['id'], $escalon, $it['venc'])) {
            continue;
        }
        $pendientes[] = $it + ['dias' => $dias];
        $refs[] = ['tipo' => $it['tipo'], 'id' => (int) $it['id'], 'dias' => $escalon, 'venc' => $it['venc']];
    }

    if (!$pendientes) {
        return ['enviado' => false, 'lineas' => ['No hay avisos nuevos para enviar.']];
    }

    usort($pendientes, fn($a, $b) => $a['dias'] <=> $b['dias']);
    $lineas = ['Vencimientos próximos:', ''];
    foreach ($pendientes as $p) {
        $cuando = $p['dias'] === 0 ? 'VENCE HOY' : 'en ' . $p['dias'] . ' días';
        $monto = isset($p['saldo']) ? ' — ' . fmt_monto($p['saldo'], $p['moneda']) : '';
        $lineas[] = sprintf('• %s %s (%s) — %s, %s%s', $p['etiqueta'], $p['nombre'], $p['cliente'], fmt_fecha($p['venc']), $cuando, $monto);
    }
    $res = notificar('vencimientos', 'Vencimientos próximos (' . count($pendientes) . ')', implode("\n", $lineas), $refs);
    $log = $res['detalle'];
    $log[] = notificacion_exitosa($res)
        ? count($pendientes) . ' vencimientos avisados.'
        : 'No se pudo enviar por ningún canal: se reintenta en la próxima corrida.';
    return ['enviado' => notificacion_exitosa($res), 'lineas' => $log, 'texto' => implode("\n", $lineas)];
}
