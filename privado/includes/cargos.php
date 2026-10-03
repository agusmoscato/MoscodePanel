<?php
/**
 * cargos.php — Cuenta corriente: generación idempotente de cargos, pagos e imputaciones.
 *
 * Reglas clave:
 *  - Cada cargo tiene una `clave_unica` (UNIQUE). Se inserta con INSERT IGNORE, así que
 *    correr la generación dos veces nunca duplica cargos.
 *      Servicio mensual: S{id_servicio}:{AAAA-MM}
 *      Servicio anual:   S{id_servicio}:{fecha de vencimiento}
 *      Dominio:          D{id_dominio}:{fecha de vencimiento actual} (no cambia al cobrarlo: solo "Renovar" la mueve)
 *      Cuota de un plan: P{id_plan}:{número de cuota}
 *  - La deuda no se guarda: se calcula sumando (monto - monto_pagado) de los cargos EXIGIBLES.
 *    Un cargo común es exigible desde que existe; una CUOTA de un plan de pago solo desde su fecha de
 *    vencimiento (las futuras son "a vencer": no suman en la deuda hasta que vencen).
 *  - Un pago se imputa a uno o varios cargos (tabla pago_imputaciones). Si moneda del pago
 *    y del cargo difieren, se convierte con la cotización guardada en el pago.
 *  - Todas las consultas filtran por usuario_id = {U} (ver db.php: q() lo exige).
 */
declare(strict_types=1);

const ANTICIPO_POR_DEFECTO_DIAS = 30;   // días de anticipo por defecto del cargo anual (configurable por servicio)
const INICIO_MENSUAL = [
    'mes_siguiente' => 'Empezar el mes siguiente',
    'completo'      => 'Cobrar el mes completo',
    'prorrateo'     => 'Prorratear los días restantes',
];
/** Error técnico al registrar un pago (distinto de un dato inválido): el webhook responde 500 para que Mercado Pago reintente. */
class ErrorInternoPago extends RuntimeException
{
}

const MEDIOS_PAGO = [
    'transferencia' => 'Transferencia',
    'efectivo'      => 'Efectivo',
    'mercadopago'   => 'Mercado Pago',
    'otro'          => 'Otro',
];

/**
 * Condición SQL de "cargo exigible": los comunes siempre; las cuotas de un plan, desde su vencimiento.
 * $alias es el alias de la tabla cargos en la consulta ('' si no tiene).
 */
function sql_exigible(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    return "({$p}plan_id IS NULL OR {$p}fecha_vencimiento <= CURDATE())";
}

/** Igual que sql_exigible, pero cuenta también las cuotas futuras ya pagadas (pago adelantado). Para los totales del mes. */
function sql_exigible_o_pagado(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    return "({$p}plan_id IS NULL OR {$p}fecha_vencimiento <= CURDATE() OR {$p}estado = 'pagado')";
}

/** Inserta un cargo si no existe (por clave_unica). Devuelve true si se creó. */
function crear_cargo(array $d): bool
{
    // Un cargo en USD guarda la cotización del día en que se genera (los reportes usan esa).
    // Si todavía no hay ninguna cotización guardada queda NULL y los reportes usan la vigente.
    $cotizacion = $d['moneda'] === 'USD' ? cotizacion_valor() : null;
    $st = q(
        'INSERT INTO cargos
            (usuario_id, cliente_id, servicio_id, dominio_id, plan_id, cuota_numero, cuota_total, concepto, periodo,
             fecha_vencimiento, monto, moneda, cotizacion, clave_unica, creado_en)
         VALUES ({U}, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE id = id',     // ya existía (clave_unica): no hace nada; cualquier otro error SÍ salta
        [
            $d['cliente_id'], $d['servicio_id'] ?? null, $d['dominio_id'] ?? null,
            $d['plan_id'] ?? null, $d['cuota_numero'] ?? null, $d['cuota_total'] ?? null,
            $d['concepto'], substr($d['fecha_vencimiento'], 0, 7), $d['fecha_vencimiento'], $d['monto'], $d['moneda'],
            $cotizacion, $d['clave_unica'],
        ]
    );
    return $st->rowCount() > 0;
}

/**
 * Genera los cargos mensuales del período (AAAA-MM), los de servicios anuales y los de dominios
 * que vencen dentro de los próximos días de anticipo de cada uno. Idempotente.
 * Devuelve ['mensuales' => n creados, 'anuales' => n creados, 'dominios' => n creados].
 */
function generar_cargos(?string $periodo = null): array
{
    $periodo = $periodo ?: date('Y-m');
    $primerDia = $periodo . '-01';
    $ultimoDia = date('Y-m-t', strtotime($primerDia));
    $res = ['mensuales' => 0, 'anuales' => 0, 'dominios' => 0];

    // Servicios mensuales activos que ya empezaron
    $mensuales = filas(
        "SELECT s.*, c.estado AS cliente_estado FROM servicios s JOIN clientes c ON c.id = s.cliente_id
         WHERE s.usuario_id = {U} AND c.usuario_id = {U}
           AND s.estado = 'activo' AND s.tipo_cobro = 'mensual' AND s.fecha_inicio <= ? AND c.estado = 'activo'",
        [$ultimoDia]
    );
    foreach ($mensuales as $s) {
        $monto = (float) $s['monto'];
        $concepto = $s['nombre'] . ' — ' . mes_nombre($periodo);

        // Primer mes de un servicio que arranca a mitad de mes: depende de la opción elegida.
        if (substr($s['fecha_inicio'], 0, 7) === $periodo && substr($s['fecha_inicio'], 8, 2) !== '01') {
            if ($s['inicio_mensual'] === 'mes_siguiente') {
                continue;   // el primer cargo sale el mes siguiente
            }
            if ($s['inicio_mensual'] === 'prorrateo') {
                $diasMes = (int) date('t', strtotime($primerDia));
                $diasCobrados = $diasMes - (int) substr($s['fecha_inicio'], 8, 2) + 1;   // incluye el día de inicio
                $monto = round($monto * $diasCobrados / $diasMes, 2);
                $concepto .= " (prorrateo $diasCobrados/$diasMes días)";
            }
            // 'completo': se cobra el monto entero
        }

        $creado = crear_cargo([
            'cliente_id'        => $s['cliente_id'],
            'servicio_id'       => $s['id'],
            'concepto'          => $concepto,
            'fecha_vencimiento' => $primerDia,
            'monto'             => $monto,
            'moneda'            => $s['moneda'],
            'clave_unica'       => 'S' . $s['id'] . ':' . $periodo,
        ]);
        $res['mensuales'] += $creado ? 1 : 0;
    }

    // Servicios anuales activos cuyo vencimiento está dentro de SUS días de anticipo (o ya venció)
    $anuales = filas(
        "SELECT s.* FROM servicios s JOIN clientes c ON c.id = s.cliente_id
         WHERE s.usuario_id = {U} AND c.usuario_id = {U}
           AND s.estado = 'activo' AND s.tipo_cobro = 'anual' AND s.proximo_vencimiento IS NOT NULL
           AND s.proximo_vencimiento <= DATE_ADD(CURDATE(), INTERVAL s.dias_anticipo DAY) AND c.estado = 'activo'"
    );
    foreach ($anuales as $s) {
        $creado = crear_cargo([
            'cliente_id'        => $s['cliente_id'],
            'servicio_id'       => $s['id'],
            'concepto'          => $s['nombre'] . ' (anual, vence ' . fmt_fecha($s['proximo_vencimiento']) . ')',
            'fecha_vencimiento' => $s['proximo_vencimiento'],
            'monto'             => $s['monto'],
            'moneda'            => $s['moneda'],
            'clave_unica'       => 'S' . $s['id'] . ':' . $s['proximo_vencimiento'],
        ]);
        $res['anuales'] += $creado ? 1 : 0;
    }

    // Dominios activos con precio al cliente cuyo vencimiento está dentro de SUS días de anticipo (o ya venció).
    // Igual que un servicio anual, pero cobrar el cargo NO mueve la fecha: el dominio se renueva aparte, en el
    // proveedor, con el botón "Renovar" (ver generar_cargo_dominio() y dominio_renovar.php).
    $dominios = filas(
        "SELECT d.* FROM dominios d JOIN clientes c ON c.id = d.cliente_id
         WHERE d.usuario_id = {U} AND c.usuario_id = {U}
           AND d.estado = 'activo' AND d.precio_cliente > 0
           AND d.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL d.dias_anticipo DAY) AND c.estado = 'activo'"
    );
    foreach ($dominios as $dom) {
        $res['dominios'] += generar_cargo_dominio($dom) ? 1 : 0;
    }
    return $res;
}

/**
 * Genera (si no existe) el cargo de renovación de un dominio para el período que vence en su fecha_vencimiento
 * ACTUAL. Idempotente por clave_unica: tanto si lo crea el anticipo automático (generar_cargos()) como si lo
 * pide a mano el botón "Renovar", el mismo período nunca genera dos cargos. No cambia la fecha del dominio.
 */
function generar_cargo_dominio(array $dominio): bool
{
    if ((float) $dominio['precio_cliente'] <= 0) {
        return false;
    }
    return crear_cargo([
        'cliente_id'        => $dominio['cliente_id'],
        'dominio_id'        => $dominio['id'],
        'concepto'          => 'Dominio ' . $dominio['dominio'] . ' (vence ' . fmt_fecha($dominio['fecha_vencimiento']) . ')',
        'fecha_vencimiento' => $dominio['fecha_vencimiento'],
        'monto'             => $dominio['precio_cliente'],
        'moneda'            => $dominio['moneda_precio'],
        'clave_unica'       => 'D' . $dominio['id'] . ':' . $dominio['fecha_vencimiento'],
    ]);
}

function mes_nombre(string $periodo): string
{
    static $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    [$a, $m] = explode('-', $periodo);
    return $meses[(int) $m] . ' ' . $a;
}

/** Cargos EXIGIBLES con saldo pendiente de un cliente, del más antiguo al más nuevo. */
function cargos_pendientes(int $clienteId): array
{
    return filas(
        "SELECT ca.*, (ca.monto - ca.monto_pagado) AS saldo FROM cargos ca
         WHERE ca.usuario_id = {U} AND ca.cliente_id = ? AND ca.estado IN ('pendiente','parcial') AND " . sql_exigible('ca') . '
         ORDER BY ca.fecha_vencimiento, ca.id',
        [$clienteId]
    );
}

/** Cuotas de planes de pago que todavía no vencieron ("a vencer") y tienen saldo, de un cliente. */
function cuotas_a_vencer(int $clienteId): array
{
    return filas(
        "SELECT ca.*, (ca.monto - ca.monto_pagado) AS saldo FROM cargos ca
         WHERE ca.usuario_id = {U} AND ca.cliente_id = ? AND ca.plan_id IS NOT NULL
           AND ca.estado IN ('pendiente','parcial') AND ca.fecha_vencimiento > CURDATE()
         ORDER BY ca.fecha_vencimiento, ca.id",
        [$clienteId]
    );
}

/** Deuda EXIGIBLE de un cliente por moneda: ['ARS' => x, 'USD' => y]. */
function deuda_cliente(int $clienteId): array
{
    $f = filas(
        "SELECT ca.moneda, SUM(ca.monto - ca.monto_pagado) AS saldo FROM cargos ca
         WHERE ca.usuario_id = {U} AND ca.cliente_id = ? AND ca.estado IN ('pendiente','parcial') AND " . sql_exigible('ca') . '
         GROUP BY ca.moneda',
        [$clienteId]
    );
    return por_moneda($f, 'saldo');
}

/**
 * Pagos sin imputar de un cliente (saldo a favor) expresados en pesos. Los pagos en USD se pasan a pesos
 * con la cotización que se guardó en cada pago.
 */
function saldo_favor_ars(int $clienteId): float
{
    $total = 0.0;
    foreach (filas(
        'SELECT p.moneda, p.cotizacion_usada, p.monto - COALESCE(SUM(i.monto_pago), 0) AS libre FROM pagos p
         LEFT JOIN pago_imputaciones i ON i.pago_id = p.id AND i.usuario_id = {U}
         WHERE p.usuario_id = {U} AND p.cliente_id = ? AND p.anulado_en IS NULL GROUP BY p.id, p.moneda, p.cotizacion_usada, p.monto
         HAVING libre > 0.009',
        [$clienteId]
    ) as $f) {
        $total += $f['moneda'] === 'USD' ? (float) $f['libre'] * (float) $f['cotizacion_usada'] : (float) $f['libre'];
    }
    return round($total, 2);
}

/** Convierte un monto entre ARS y USD con la cotización dada (ARS por 1 USD). */
function convertir_monto(float $monto, string $de, string $a, float $cotizacion): float
{
    if ($de === $a) {
        return round($monto, 2);
    }
    return round($de === 'USD' ? $monto * $cotizacion : $monto / $cotizacion, 2);
}

/**
 * Aplica parte de un pago a un cargo. Devuelve cuánto del pago se consumió (en moneda del pago).
 * $disponible está en la moneda del pago.
 */
function imputar_a_cargo(int $pagoId, array $cargo, float $disponible, string $monedaPago, float $cotizacion): float
{
    $saldo = round((float) $cargo['monto'] - (float) $cargo['monto_pagado'], 2);
    if ($saldo < 0.01 || $disponible < 0.01) {
        return 0.0;
    }
    $dispEnCargo = convertir_monto($disponible, $monedaPago, $cargo['moneda'], $cotizacion);
    $aplicadoCargo = min($saldo, $dispEnCargo);
    if ($aplicadoCargo < 0.01) {
        return 0.0;
    }
    // Si se gasta todo lo disponible, usar el monto exacto para evitar diferencias de redondeo.
    $aplicadoPago = ($aplicadoCargo >= $dispEnCargo)
        ? $disponible
        : convertir_monto($aplicadoCargo, $cargo['moneda'], $monedaPago, $cotizacion);

    insertar('pago_imputaciones', [
        'pago_id' => $pagoId, 'cargo_id' => $cargo['id'], 'monto_pago' => $aplicadoPago, 'monto_cargo' => $aplicadoCargo,
    ]);

    $nuevoPagado = round((float) $cargo['monto_pagado'] + $aplicadoCargo, 2);
    $completo = $nuevoPagado >= round((float) $cargo["monto"], 2) - 0.015;   // tolerancia de centavos por redondeo de conversiones
    // La fila está bloqueada (FOR UPDATE) por quien llama; igual se suma en la base en vez de reescribir el valor leído
    q(
        'UPDATE cargos SET monto_pagado = IF(?, monto, monto_pagado + ?), estado = ? WHERE id = ? AND usuario_id = {U}',
        [$completo ? 1 : 0, $aplicadoCargo, $completo ? 'pagado' : 'parcial', $cargo['id']]
    );

    // Servicio anual cobrado completo → el próximo vencimiento pasa a un año después (y el aviso de
    // renovación enviado para el vencimiento anterior ya no aplica al nuevo).
    if ($completo && $cargo['servicio_id']) {
        q(
            "UPDATE servicios SET proximo_vencimiento = DATE_ADD(proximo_vencimiento, INTERVAL 1 YEAR), aviso_renovacion_enviado_en = NULL
             WHERE id = ? AND usuario_id = {U} AND tipo_cobro = 'anual' AND proximo_vencimiento = ?",
            [$cargo['servicio_id'], $cargo['fecha_vencimiento']]
        );
    }
    return $aplicadoPago;
}

/**
 * Registra un pago e imputa a cargos.
 *  - $manual = null  → imputación automática: del cargo exigible más antiguo al más nuevo (las cuotas
 *    futuras no absorben pagos solas: para adelantarlas se imputan a mano).
 *  - $manual = [cargo_id => monto en moneda del pago] → imputación elegida a mano (puede incluir cuotas futuras).
 * Lo que no se imputa queda como pago sin imputar (saldo a favor).
 * Devuelve el id del pago. Lanza RuntimeException ante datos inválidos.
 */
function registrar_pago(
    int $clienteId, string $fecha, float $monto, string $moneda, string $medio,
    string $nota, float $cotizacion, ?array $manual = null, ?string $mpPaymentId = null
): int {
    if ($monto < 0.01) {
        throw new RuntimeException('El monto del pago debe ser mayor a cero.');
    }
    if ($cotizacion <= 0) {
        throw new RuntimeException('Falta la cotización del dólar para registrar el pago.');
    }
    if (!fila('SELECT id FROM clientes WHERE id = ? AND usuario_id = {U}', [$clienteId])) {
        throw new RuntimeException('El cliente no existe.');
    }

    $pdo = db();
    $propia = !$pdo->inTransaction();   // si ya hay una transacción (alta de plan), se suma a esa
    if ($propia) {
        $pdo->beginTransaction();
    }
    try {
        $pagoId = insertar('pagos', [
            'cliente_id' => $clienteId, 'fecha' => $fecha, 'monto' => $monto, 'moneda' => $moneda,
            'medio' => $medio, 'cotizacion_usada' => $cotizacion, 'nota' => $nota,
            'mp_payment_id' => $mpPaymentId, 'creado_en' => date('Y-m-d H:i:s'),
        ]);
        $disponible = $monto;

        if ($manual === null) {
            // Se bloquean las filas para que dos pagos simultáneos no se pisen.
            $cargos = filas(
                "SELECT ca.* FROM cargos ca WHERE ca.usuario_id = {U} AND ca.cliente_id = ?
                   AND ca.estado IN ('pendiente','parcial') AND " . sql_exigible('ca') . '
                 ORDER BY ca.fecha_vencimiento, ca.id FOR UPDATE',
                [$clienteId]
            );
            foreach ($cargos as $c) {
                $disponible -= imputar_a_cargo($pagoId, $c, round($disponible, 2), $moneda, $cotizacion);
                if ($disponible < 0.01) {
                    break;
                }
            }
        } else {
            ksort($manual);          // siempre en el mismo orden: dos pagos cruzados no se traban entre sí (deadlock)
            $total = array_sum($manual);
            if ($total > $monto + 0.005) {
                throw new RuntimeException('La suma imputada supera el monto del pago.');
            }
            foreach ($manual as $cargoId => $montoAImputar) {
                if ($montoAImputar < 0.01) {
                    continue;
                }
                $c = fila(
                    "SELECT ca.* FROM cargos ca WHERE ca.id = ? AND ca.cliente_id = ? AND ca.usuario_id = {U}
                       AND ca.estado IN ('pendiente','parcial') FOR UPDATE",
                    [$cargoId, $clienteId]
                );
                if (!$c) {
                    throw new RuntimeException('Uno de los cargos elegidos no existe o ya está pagado.');
                }
                imputar_a_cargo($pagoId, $c, (float) $montoAImputar, $moneda, $cotizacion);
            }
        }
        if ($propia) {
            $pdo->commit();
        }
        return $pagoId;
    } catch (Throwable $ex) {
        if ($propia) {
            $pdo->rollBack();
        }
        if ($ex instanceof RuntimeException) {
            throw $ex;
        }
        error_log('registrar_pago: ' . get_class($ex) . ': ' . $ex->getMessage());   // el detalle técnico va al log, no a la pantalla
        throw new ErrorInternoPago('No se pudo registrar el pago. Revisá los datos e intentá de nuevo.');
    }
}

/** Monto de un pago que no se imputó a ningún cargo (en moneda del pago). */
function pago_sin_imputar(int $pagoId): float
{
    $r = fila(
        'SELECT p.monto - COALESCE(SUM(i.monto_pago), 0) AS libre FROM pagos p
         LEFT JOIN pago_imputaciones i ON i.pago_id = p.id AND i.usuario_id = {U}
         WHERE p.id = ? AND p.usuario_id = {U} AND p.anulado_en IS NULL GROUP BY p.id, p.monto',
        [$pagoId]
    );
    return $r ? max(0.0, round((float) $r['libre'], 2)) : 0.0;
}


/**
 * Anula un pago: lo desimputa de sus cargos (vuelven a deberse esos montos) y lo marca como anulado con quién, cuándo
 * y por qué. El pago NUNCA se borra de la base (ni sus imputaciones): queda la historia completa. Todo en una
 * transacción con las filas bloqueadas. Un pago de Mercado Pago anulado no se vuelve a registrar si MP reenvía el
 * aviso (el payment_id sigue guardado). Lanza RuntimeException con un mensaje claro si no se puede.
 */
function anular_pago(int $pagoId, string $motivo, int $actorId): array
{
    $motivo = trim($motivo);
    if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 255) {
        throw new RuntimeException('El motivo es obligatorio (entre 5 y 255 caracteres).');
    }
    $pdo = db();
    $propia = !$pdo->inTransaction();
    if ($propia) {
        $pdo->beginTransaction();
    }
    try {
        $p = fila(
            'SELECT p.*, c.nombre AS cliente FROM pagos p JOIN clientes c ON c.id = p.cliente_id AND c.usuario_id = {U}
             WHERE p.id = ? AND p.usuario_id = {U} FOR UPDATE',
            [$pagoId]
        );
        if (!$p) {
            throw new RuntimeException('El pago no existe.');
        }
        if ($p['anulado_en'] !== null) {
            throw new RuntimeException('Este pago ya está anulado.');
        }
        $revertidos = 0;
        $imps = filas('SELECT * FROM pago_imputaciones WHERE usuario_id = {U} AND pago_id = ? ORDER BY cargo_id', [$pagoId]);
        foreach ($imps as $i) {
            $c = fila('SELECT * FROM cargos WHERE id = ? AND usuario_id = {U} FOR UPDATE', [$i['cargo_id']]);
            if (!$c) {
                continue;
            }
            $nuevoPagado = max(0.0, round((float) $c['monto_pagado'] - (float) $i['monto_cargo'], 2));
            $estaba = $c['estado'];
            if ($estaba === 'anulado') {
                $nuevoEstado = 'anulado';
            } elseif ($nuevoPagado >= round((float) $c['monto'], 2) - 0.015) {
                $nuevoEstado = 'pagado';
            } else {
                $nuevoEstado = $nuevoPagado > 0.004 ? 'parcial' : 'pendiente';
            }
            q('UPDATE cargos SET monto_pagado = ?, estado = ? WHERE id = ? AND usuario_id = {U}', [$nuevoPagado, $nuevoEstado, $c['id']]);
            // Un servicio anual cobrado completo había corrido su próximo vencimiento un año: se deshace
            if ($estaba === 'pagado' && $nuevoEstado !== 'pagado' && $c['servicio_id']) {
                q(
                    "UPDATE servicios SET proximo_vencimiento = DATE_SUB(proximo_vencimiento, INTERVAL 1 YEAR), aviso_renovacion_enviado_en = NULL
                     WHERE id = ? AND usuario_id = {U} AND tipo_cobro = 'anual' AND proximo_vencimiento = DATE_ADD(?, INTERVAL 1 YEAR)",
                    [$c['servicio_id'], $c['fecha_vencimiento']]
                );
            }
            $revertidos++;
        }
        $n = q(
            'UPDATE pagos SET anulado_en = NOW(), anulado_por = ?, anulado_motivo = ? WHERE id = ? AND usuario_id = {U} AND anulado_en IS NULL',
            [$actorId, $motivo, $pagoId]
        )->rowCount();
        if ($n !== 1) {
            throw new RuntimeException('Este pago ya está anulado.');
        }
        if ($propia) {
            $pdo->commit();
        }
    } catch (Throwable $ex) {
        if ($propia && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($ex instanceof RuntimeException) {
            throw $ex;
        }
        error_log('anular_pago: ' . get_class($ex) . ': ' . $ex->getMessage());
        throw new RuntimeException('No se pudo anular el pago. Intentá de nuevo.');
    }
    registrar_actividad(
        'pago_anulado',
        'Pago #' . $pagoId . ' de ' . $p['cliente'] . ' por ' . fmt_monto($p['monto'], $p['moneda']) . ' del ' . fmt_fecha($p['fecha']) . '. Motivo: ' . $motivo,
        null, $actorId, 'datos'
    );
    return ['pago' => $p, 'cargos_revertidos' => $revertidos];
}