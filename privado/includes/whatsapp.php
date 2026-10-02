<?php
/**
 * whatsapp.php — Mensaje de cobro y link wa.me para cada cliente.
 *
 * La plantilla es de cada usuario (se edita en Configuración). Variables:
 *   {contacto}        nombre de pila del contacto (si no hay, el nombre del cliente)
 *   {cliente}         nombre del cliente (en plantillas viejas "legacy" conserva su significado anterior: el contacto)
 *   {servicios}       servicios / conceptos con cargos pendientes ("Hosting y Mantenimiento")
 *   {descripcion}     " (descripción)" del servicio o plan, solo si hay uno solo y la tiene
 *   {lineas}          detalle con importes y conversión a pesos (ver lineas_cobro())
 *   {saldo_favor}     pagos sin imputar del cliente, en pesos
 *   {total_a_abonar}  total en pesos menos el saldo a favor
 *   {alias}           alias o CBU del usuario
 *   Compatibles de antes: {empresa} {detalle} {total} {yo} {portal}
 */
declare(strict_types=1);

const PLANTILLA_WHATSAPP_DEFECTO = "Hola {contacto}! Te paso el cobro de {servicios} de este mes para {cliente}{descripcion}:\n{lineas}\nResto a favor: {saldo_favor}\nTotal a abonar: {total_a_abonar}\nDecime cómo preferís pagarlo: efectivo o transferencia. Si es transferencia, el alias es {alias}.\n¡Gracias!";

/** Plantilla del usuario, o la de por defecto si no editó ninguna. */
function plantilla_whatsapp_actual(): string
{
    $p = cfg('plantilla_whatsapp');
    return trim($p) !== '' ? $p : PLANTILLA_WHATSAPP_DEFECTO;
}

/** Normaliza un teléfono argentino a formato internacional para wa.me (solo dígitos). */
function telefono_para_whatsapp(string $tel): string
{
    $d = preg_replace('/\D/', '', $tel) ?? '';
    $d = preg_replace('/^00/', '', $d) ?? '';
    if ($d === '') {
        return '';
    }
    if (str_starts_with($d, '549')) {
        return $d;
    }
    if (str_starts_with($d, '54')) {
        return '549' . substr($d, 2);                 // 54 + área + número → agrega el 9 de celular
    }
    $d = ltrim($d, '0');
    if (preg_match('/^(11)15(\d{8})$/', $d, $m)) {     // 11 15 xxxxxxxx (Buenos Aires con el 15)
        return '549' . $m[1] . $m[2];
    }
    if (strlen($d) === 10) {
        return '549' . $d;                             // área + número, sin país
    }
    return $d;                                         // otro país u otro formato: se deja tal cual
}

// --- Formato de los números del mensaje ---------------------------------------------------------------

/** Número con punto de miles; sin decimales si es entero ("61.800"), con 2 si no ("183,33"). */
function num_wa(float $n): string
{
    $n = round($n, 2);
    return number_format($n, abs($n - round($n)) < 0.005 ? 0 : 2, ',', '.');
}

function ars_wa(float $n): string
{
    return 'AR$ ' . num_wa($n);
}

function usd_wa(float $n): string
{
    return 'USD ' . num_wa($n);
}

/** "$1545" (sin separador de miles y sin decimales si es entera, como en los mensajes de ejemplo). */
function cotizacion_wa(float $c): string
{
    $c = round($c, 2);
    return '$' . number_format($c, abs($c - round($c)) < 0.005 ? 0 : 2, ',', '');
}

/** "dólar blue venta $1545, dolarhoy.com, 02/09" */
function texto_cotizacion_wa(array $cot): string
{
    $tipos = ['blue' => 'blue', 'oficial' => 'oficial', 'mep' => 'MEP', 'ccl' => 'CCL', 'tarjeta' => 'tarjeta'];
    $fuentes = ['dolarhoy' => 'dolarhoy.com', 'dolarapi' => 'dolarapi.com', 'manual' => 'carga manual'];
    return 'dólar ' . ($tipos[$cot['tipo']] ?? $cot['tipo']) . ' venta ' . cotizacion_wa((float) $cot['valor_venta'])
        . ', ' . ($fuentes[$cot['fuente']] ?? $cot['fuente']) . ', ' . date('d/m', strtotime((string) $cot['creado_en']));
}

/** "A", "A y B", "A, B y C" */
function unir_con_y(array $items): string
{
    $items = array_values($items);
    if (count($items) <= 1) {
        return $items[0] ?? '';
    }
    $ultimo = array_pop($items);
    return implode(', ', $items) . ' y ' . $ultimo;
}

// --- Datos del cobro ----------------------------------------------------------------------------------

/**
 * Cargos exigibles de un cliente con el nombre/descripción de lo que cobran, para armar el mensaje.
 * Cada fila: nombre (lo que se muestra), desc, es_cuota, cuota_numero/total y los datos del cargo.
 */
function cargos_para_cobro(int $clienteId): array
{
    $filas = filas(
        "SELECT ca.*, (ca.monto - ca.monto_pagado) AS saldo, s.nombre AS serv_nombre, s.descripcion AS serv_desc,
                d.dominio AS dom_nombre, pl.concepto AS plan_concepto, pl.descripcion AS plan_desc
         FROM cargos ca
         LEFT JOIN servicios s ON s.id = ca.servicio_id AND s.usuario_id = {U}
         LEFT JOIN dominios d ON d.id = ca.dominio_id AND d.usuario_id = {U}
         LEFT JOIN planes_pago pl ON pl.id = ca.plan_id AND pl.usuario_id = {U}
         WHERE ca.usuario_id = {U} AND ca.cliente_id = ? AND ca.estado IN ('pendiente','parcial') AND " . sql_exigible('ca') . '
         ORDER BY ca.fecha_vencimiento, ca.id',
        [$clienteId]
    );
    foreach ($filas as &$f) {
        $f['es_cuota'] = $f['plan_id'] !== null;
        if ($f['es_cuota']) {
            $f['nombre'] = (string) $f['plan_concepto'];
            $f['desc'] = (string) $f['plan_desc'];
        } elseif ($f['servicio_id'] !== null && $f['serv_nombre'] !== null) {
            $f['nombre'] = (string) $f['serv_nombre'];
            $f['desc'] = (string) $f['serv_desc'];
        } elseif ($f['dominio_id'] !== null && $f['dom_nombre'] !== null) {
            $f['nombre'] = 'Dominio ' . $f['dom_nombre'];
            $f['desc'] = '';
        } else {
            $f['nombre'] = (string) $f['concepto'];
            $f['desc'] = '';
        }
    }
    unset($f);
    return $filas;
}

/**
 * Bloque {lineas} y totales. Devuelve ['lineas' => string, 'total_ars' => ?float, 'total_texto' => string].
 *  - Un solo cargo en USD (que no es cuota): "Total: USD 40 → AR$ 61.800 (dólar blue venta $1545, dolarhoy.com, 02/09)"
 *  - Un solo cargo en ARS (que no es cuota): "Total: AR$ 61.800"
 *  - Varios cargos, o una cuota: una línea por cargo ("- Hosting (4 usuarios): USD 40 → AR$ 61.800",
 *    "- Desarrollo web, cuota 2/3: USD 183,33 → AR$ 282.000") y al final la cotización usada y el total.
 */
function lineas_cobro(array $cargos, ?array $cot): array
{
    if (!$cargos) {
        return ['lineas' => 'No hay cargos pendientes.', 'total_ars' => 0.0, 'total_texto' => ars_wa(0)];
    }
    $valor = (float) ($cot['valor_venta'] ?? 0);
    $hayUsd = false;
    $faltaCot = false;
    $totalArs = 0.0;
    $totalUsdSinCot = 0.0;
    $items = [];
    foreach ($cargos as $c) {
        $saldo = (float) $c['saldo'];
        if ($c['moneda'] === 'USD') {
            $hayUsd = true;
            if ($valor > 0) {
                $ars = round($saldo * $valor, 2);
                $totalArs += $ars;
                $items[] = ['c' => $c, 'texto' => usd_wa($saldo) . ' → ' . ars_wa($ars)];
            } else {
                $faltaCot = true;
                $totalUsdSinCot += $saldo;
                $items[] = ['c' => $c, 'texto' => usd_wa($saldo)];
            }
        } else {
            $totalArs += $saldo;
            $items[] = ['c' => $c, 'texto' => ars_wa($saldo)];
        }
    }
    $totalArs = round($totalArs, 2);
    $totalTexto = ars_wa($totalArs);
    if ($faltaCot) {   // hay dólares pero no hay cotización: no se puede dar un total único en pesos
        $totalTexto = ($totalArs > 0.004 ? ars_wa($totalArs) . ' + ' : '') . usd_wa($totalUsdSinCot);
    }

    $detalleCot = ($hayUsd && $cot) ? '(' . texto_cotizacion_wa($cot) . ')' : '';
    // Un solo cargo que no es una cuota: formato compacto
    if (count($cargos) === 1 && !$cargos[0]['es_cuota']) {
        $linea = 'Total: ' . $items[0]['texto'] . ($detalleCot !== '' ? ' ' . $detalleCot : '');
        return ['lineas' => $linea, 'total_ars' => $faltaCot ? null : $totalArs, 'total_texto' => $totalTexto];
    }
    $lineas = [];
    foreach ($items as $it) {
        $c = $it['c'];
        if ($c['es_cuota']) {
            $etiqueta = $c['nombre'] . ', cuota ' . $c['cuota_numero'] . '/' . $c['cuota_total'];
        } else {
            $etiqueta = $c['nombre'] . ($c['desc'] !== '' ? ' (' . $c['desc'] . ')' : '');
        }
        $lineas[] = '- ' . $etiqueta . ': ' . $it['texto'];
    }
    if ($detalleCot !== '') {
        $lineas[] = 'Cotización usada: ' . texto_cotizacion_wa($cot);
    }
    $lineas[] = 'Total: ' . $totalTexto;
    return ['lineas' => implode("\n", $lineas), 'total_ars' => $faltaCot ? null : $totalArs, 'total_texto' => $totalTexto];
}

/** Arma el texto de cobro reemplazando las variables de la plantilla del usuario. */
function mensaje_cobro(array $cliente): string
{
    $id = (int) $cliente['id'];
    $cargos = cargos_para_cobro($id);
    $cot = cotizacion_vigente();
    $l = lineas_cobro($cargos, $cot);

    // Nombres distintos de lo que se cobra (un servicio con varios meses pendientes cuenta una vez)
    $nombres = [];
    $descripciones = [];
    foreach ($cargos as $c) {
        $nombres[$c['nombre']] = $c['nombre'];
        if ($c['desc'] !== '') {
            $descripciones[$c['nombre']] = $c['desc'];
        }
    }
    $unico = count($nombres) === 1 ? array_key_first($nombres) : null;
    $descripcion = ($unico !== null && isset($descripciones[$unico])) ? ' (' . $descripciones[$unico] . ')' : '';

    $saldoFavor = saldo_favor_ars($id);
    $totalAbonar = ($l['total_ars'] !== null) ? ars_wa(max(0.0, round($l['total_ars'] - $saldoFavor, 2))) : $l['total_texto'];

    $contactoCompleto = trim((string) ($cliente['contacto'] ?? ''));
    $nombreCliente = (string) $cliente['nombre'];
    $pila = $contactoCompleto !== '' ? (preg_split('/\s+/u', $contactoCompleto)[0] ?? $contactoCompleto) : $nombreCliente;

    // Variables de antes (se mantienen para quien ya las usa)
    $detalleViejo = [];
    foreach ($cargos as $c) {
        $detalleViejo[] = '- ' . $c['concepto'] . ' (vence ' . fmt_fecha($c['fecha_vencimiento']) . '): ' . fmt_monto($c['saldo'], $c['moneda']);
    }
    $deuda = deuda_cliente($id);
    $totalArsViejo = a_ars($deuda, $cot ? (float) $cot['valor_venta'] : null);
    $totalViejo = fmt_por_moneda($deuda);
    if ($deuda['USD'] > 0.004 && $totalArsViejo !== null) {
        $totalViejo .= ' (≈ ' . fmt_monto($totalArsViejo) . ')';
    }
    $linkPortal = '';
    if (cfg('portal_activo', '0') === '1') {
        $tokenPortal = array_key_exists('portal_token', $cliente)
            ? $cliente['portal_token']
            : valor('SELECT portal_token FROM clientes WHERE id = ? AND usuario_id = {U}', [$id]);
        $linkPortal = $tokenPortal ? url_portal(app_url(), $tokenPortal) : '';
    }
    // En plantillas viejas editadas, {cliente} sigue siendo la persona de contacto (como antes)
    $cliVar = cfg('plantilla_whatsapp_legacy') === '1' ? $pila : $nombreCliente;

    $vars = [
        '{contacto}'       => $pila,
        '{cliente}'        => $cliVar,
        '{servicios}'      => $nombres ? unir_con_y($nombres) : '(sin cargos pendientes)',
        '{descripcion}'    => $descripcion,
        '{lineas}'         => $l['lineas'],
        '{saldo_favor}'    => ars_wa($saldoFavor),
        '{total_a_abonar}' => $totalAbonar,
        '{alias}'          => cfg('alias_cbu'),
        '{empresa}'        => $nombreCliente,
        '{detalle}'        => $detalleViejo ? implode("\n", $detalleViejo) : '(sin cargos pendientes)',
        '{total}'          => $totalViejo,
        '{yo}'             => cfg('nombre_propio'),
        '{portal}'         => $linkPortal,
    ];
    return strtr(plantilla_whatsapp_actual(), $vars);
}

/** Link wa.me con el mensaje cargado, o '' si el cliente no tiene teléfono. */
function link_whatsapp(array $cliente): string
{
    $num = telefono_para_whatsapp((string) $cliente['telefono']);
    if ($num === '') {
        return '';
    }
    return 'https://wa.me/' . $num . '?text=' . rawurlencode(mensaje_cobro($cliente));
}

/** Botón HTML "Cobrar por WhatsApp" (vacío si no hay teléfono o no hay deuda). */
function boton_whatsapp(array $cliente, string $clase = 'btn chico'): string
{
    $deuda = deuda_cliente((int) $cliente['id']);
    if ($deuda['ARS'] < 0.005 && $deuda['USD'] < 0.005) {
        return '';
    }
    $link = link_whatsapp($cliente);
    if ($link === '') {
        return '<span class="suave">sin teléfono</span>';
    }
    return '<a class="' . e($clase) . '" href="' . e($link) . '" target="_blank" rel="noopener noreferrer">WhatsApp</a>';
}
