<?php
/**
 * planes.php — Ventas en cuotas (planes de pago): cobros únicos que no son servicios recurrentes
 * (desarrollo de una web, un sistema…).
 *
 * Cada cuota es un CARGO normal (cargos.plan_id / cuota_numero / cuota_total): usa la cuenta corriente,
 * los pagos parciales, la imputación y la cotización por cargo. Una cuota solo cuenta como deuda desde su
 * fecha de vencimiento; las futuras se muestran aparte como "a vencer" (ver sql_exigible() en cargos.php).
 *
 * Reglas:
 *  - Total ÷ cantidad, redondeado a 2 decimales; la última cuota absorbe la diferencia (550/3 = 183,33 + 183,33 + 183,34).
 *  - Se pueden editar monto y fecha de cada cuota antes de guardar (la suma tiene que dar el total).
 *  - Cancelar un plan anula las cuotas pendientes; lo ya pagado queda (una cuota con pago parcial se cierra por lo pagado).
 */
declare(strict_types=1);

const FRECUENCIAS = [
    'semanal'    => 'Semanal',
    'quincenal'  => 'Quincenal (cada 15 días)',
    'mensual'    => 'Mensual',
    'bimestral'  => 'Bimestral',
    'trimestral' => 'Trimestral',
];
const PLAN_MAX_CUOTAS = 120;

/** Reparte el total en $n cuotas: base redondeada a 2 decimales y la última absorbe la diferencia. */
function dividir_en_cuotas(float $total, int $n): array
{
    $n = max(1, $n);
    $centavos = (int) round($total * 100);
    $base = (int) round($centavos / $n);
    $montos = array_fill(0, $n, $base / 100);
    $montos[$n - 1] = ($centavos - $base * ($n - 1)) / 100;
    return array_map(fn($m) => round($m, 2), $montos);
}

/**
 * Fechas de vencimiento de las cuotas desde la primera. En las frecuencias por meses se conserva el día
 * (si el mes no lo tiene, se usa el último día: 31/01 → 28/02 → 31/03).
 */
function fechas_cuotas(string $primera, int $n, string $frecuencia): array
{
    $t0 = strtotime($primera);
    $d0 = (int) date('j', $t0);
    $m0 = (int) date('n', $t0);
    $a0 = (int) date('Y', $t0);
    $meses = ['mensual' => 1, 'bimestral' => 2, 'trimestral' => 3][$frecuencia] ?? 0;
    $fechas = [];
    for ($i = 0; $i < $n; $i++) {
        if ($meses > 0) {
            $total = $m0 - 1 + $i * $meses;
            $anio = $a0 + intdiv($total, 12);
            $mes = $total % 12 + 1;
            $ultimo = (int) date('t', mktime(0, 0, 0, $mes, 1, $anio));
            $fechas[] = sprintf('%04d-%02d-%02d', $anio, $mes, min($d0, $ultimo));
        } else {
            $dias = $frecuencia === 'semanal' ? 7 * $i : 15 * $i;
            $fechas[] = date('Y-m-d', strtotime("+$dias days", $t0));
        }
    }
    return $fechas;
}

/** Cuotas por defecto de un plan: [['monto' => x, 'fecha' => 'AAAA-MM-DD'], ...]. */
function cuotas_por_defecto(float $total, int $n, string $primera, string $frecuencia): array
{
    $montos = dividir_en_cuotas($total, $n);
    $fechas = fechas_cuotas($primera, $n, $frecuencia);
    $c = [];
    foreach ($montos as $i => $m) {
        $c[] = ['monto' => $m, 'fecha' => $fechas[$i]];
    }
    return $c;
}

/** Valida las cuotas editadas: cantidad, montos, fechas y que la suma dé el total. Devuelve el error o null. */
function validar_cuotas(array $cuotas, float $total, int $n): ?string
{
    if (count($cuotas) !== $n) {
        return "Tienen que ser $n cuotas.";
    }
    $suma = 0.0;
    $anterior = '';
    foreach (array_values($cuotas) as $i => $c) {
        $num = $i + 1;
        if (($c['monto'] ?? 0) < 0.01) {
            return "El monto de la cuota $num tiene que ser mayor a cero.";
        }
        if (!fecha_valida((string) ($c['fecha'] ?? ''))) {
            return "La fecha de la cuota $num no es válida.";
        }
        if ($anterior !== '' && $c['fecha'] < $anterior) {
            return "La fecha de la cuota $num no puede ser anterior a la de la cuota " . ($num - 1) . '.';
        }
        $anterior = $c['fecha'];
        $suma += (float) $c['monto'];
    }
    if (abs($suma - $total) > 0.005) {
        return 'La suma de las cuotas (' . number_format($suma, 2, ',', '.') . ') no coincide con el total (' . number_format($total, 2, ',', '.') . ').';
    }
    return null;
}

/** Cotización para registrar pagos de un plan: obligatoria en USD; en pesos se usa la vigente o 1. */
function cotizacion_para_pago(string $moneda): float
{
    $cot = cotizacion_valor();
    if ($moneda === 'USD' && !$cot) {
        throw new RuntimeException('Falta la cotización del dólar para registrar el pago de una cuota en USD (cargala en Dólar).');
    }
    return $cot ?: 1.0;
}

/**
 * Crea un plan con sus cuotas (cada una es un cargo) y, si corresponde, registra los pagos de las
 * primeras $pagadas cuotas ($pago = ['fecha' => ..., 'medio' => ...]). Todo en una transacción.
 * Devuelve el id del plan. Lanza RuntimeException si algo no es válido.
 */
function crear_plan(int $clienteId, array $d, array $cuotas, int $pagadas = 0, ?array $pago = null): int
{
    $cliente = cliente_propio($clienteId);
    if (!$cliente) {
        throw new RuntimeException('El cliente no existe.');
    }
    $concepto = trim((string) ($d['concepto'] ?? ''));
    $moneda = (string) ($d['moneda'] ?? 'ARS');
    $total = round((float) ($d['monto_total'] ?? 0), 2);
    $n = (int) ($d['cuotas'] ?? 0);
    $frecuencia = (string) ($d['frecuencia'] ?? 'mensual');
    if ($concepto === '') {
        throw new RuntimeException('El concepto es obligatorio.');
    }
    if ($total < 0.01) {
        throw new RuntimeException('El monto total tiene que ser mayor a cero.');
    }
    if ($n < 1 || $n > PLAN_MAX_CUOTAS) {
        throw new RuntimeException('La cantidad de cuotas tiene que estar entre 1 y ' . PLAN_MAX_CUOTAS . '.');
    }
    if (!in_array($moneda, ['ARS', 'USD'], true) || !isset(FRECUENCIAS[$frecuencia])) {
        throw new RuntimeException('Moneda o frecuencia inválidas.');
    }
    $cuotas = array_values($cuotas);
    if ($err = validar_cuotas($cuotas, $total, $n)) {
        throw new RuntimeException($err);
    }
    if ($pagadas < 0 || $pagadas > $n) {
        throw new RuntimeException('La cantidad de cuotas ya cobradas no es válida.');
    }
    $cot = 0.0;
    if ($pagadas > 0) {
        if (!fecha_valida((string) ($pago['fecha'] ?? '')) || !isset(MEDIOS_PAGO[$pago['medio'] ?? ''])) {
            throw new RuntimeException('Indicá la fecha y el medio de pago de las cuotas ya cobradas.');
        }
        $cot = cotizacion_para_pago($moneda);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $planId = insertar('planes_pago', [
            'cliente_id' => $clienteId, 'concepto' => mb_substr($concepto, 0, 160),
            'descripcion' => mb_substr(trim((string) ($d['descripcion'] ?? '')), 0, 255), 'monto_total' => $total,
            'moneda' => $moneda, 'cuotas' => $n, 'frecuencia' => $frecuencia, 'fecha_primera' => $cuotas[0]['fecha'],
            'notas' => trim((string) ($d['notas'] ?? '')) ?: null, 'estado' => 'activo', 'creado_en' => date('Y-m-d H:i:s'),
        ]);
        foreach ($cuotas as $i => $c) {
            $num = $i + 1;
            crear_cargo([
                'cliente_id' => $clienteId, 'plan_id' => $planId, 'cuota_numero' => $num, 'cuota_total' => $n,
                'concepto' => "$concepto — Cuota $num/$n", 'fecha_vencimiento' => $c['fecha'],
                'monto' => $c['monto'], 'moneda' => $moneda, 'clave_unica' => "P$planId:$num",
            ]);
        }
        // Las primeras N ya cobradas: un pago por cuota, imputado a esa cuota
        for ($num = 1; $num <= $pagadas; $num++) {
            $cargo = fila('SELECT id, monto FROM cargos WHERE usuario_id = {U} AND plan_id = ? AND cuota_numero = ?', [$planId, $num]);
            registrar_pago(
                $clienteId, $pago['fecha'], (float) $cargo['monto'], $moneda, $pago['medio'],
                "Cuota $num/$n — $concepto", $cot, [(int) $cargo['id'] => (float) $cargo['monto']]
            );
        }
        $pdo->commit();
        return $planId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($ex instanceof RuntimeException) {
            throw $ex;
        }
        error_log('crear_plan: ' . get_class($ex) . ': ' . $ex->getMessage());
        throw new RuntimeException('No se pudo crear el plan. Revisá los datos e intentá de nuevo.');
    }
}

/** Plan del usuario por id (o null). */
function plan_propio(int $id): ?array
{
    return $id > 0 ? fila('SELECT * FROM planes_pago WHERE id = ? AND usuario_id = {U}', [$id]) : null;
}

/** Cuotas (cargos) de un plan en orden. Con $bloquear (dentro de una transacción) se bloquean las filas. */
function cuotas_de_plan(int $planId, bool $bloquear = false): array
{
    return filas(
        'SELECT ca.*, (ca.monto - ca.monto_pagado) AS saldo FROM cargos ca
         WHERE ca.usuario_id = {U} AND ca.plan_id = ? ORDER BY ca.cuota_numero' . ($bloquear ? ' FOR UPDATE' : ''),
        [$planId]
    );
}

/**
 * Corre $fn($plan) dentro de una transacción con la fila del plan bloqueada (SELECT … FOR UPDATE). Así dos pedidos
 * simultáneos sobre el mismo plan (cobrar, editar, cancelar, o un webhook de Mercado Pago) se hacen uno después del otro,
 * y cada uno lee las cuotas ya actualizadas por el anterior. Si algo falla, no queda nada a medias.
 */
function con_plan_bloqueado(int $planId, callable $fn)
{
    $pdo = db();
    $propia = !$pdo->inTransaction();
    if ($propia) {
        $pdo->beginTransaction();
    }
    try {
        $plan = fila('SELECT * FROM planes_pago WHERE id = ? AND usuario_id = {U} FOR UPDATE', [$planId]);
        $r = $fn($plan);
        if ($propia) {
            $pdo->commit();
        }
        return $r;
    } catch (Throwable $ex) {
        if ($propia && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/** Estado visible del plan: activo / completado / cancelado. */
function plan_estado_visible(array $plan, int $pendientes): string
{
    if ($plan['estado'] === 'cancelado') {
        return 'cancelado';
    }
    return $pendientes === 0 ? 'completado' : 'activo';
}

/**
 * Planes del usuario con su progreso: cuotas pagadas/total, cobrado, saldo y próxima cuota.
 * Filtros: $clienteId y $estado ('activo' | 'completado' | 'cancelado' | '' = todos).
 */
function planes_listado(?int $clienteId = null, string $estado = ''): array
{
    $params = [];
    $extra = '';
    if ($clienteId) {
        $extra = ' AND pl.cliente_id = ?';
        $params[] = $clienteId;
    }
    $planes = filas(
        "SELECT pl.*, c.nombre AS cliente,
                COALESCE(SUM(CASE WHEN ca.estado <> 'anulado' THEN ca.monto_pagado END), 0) AS cobrado,
                COUNT(CASE WHEN ca.estado = 'pagado' THEN 1 END) AS n_pagadas,
                COUNT(CASE WHEN ca.estado IN ('pendiente','parcial') THEN 1 END) AS n_pendientes,
                COALESCE(SUM(CASE WHEN ca.estado IN ('pendiente','parcial') THEN ca.monto - ca.monto_pagado END), 0) AS saldo
         FROM planes_pago pl JOIN clientes c ON c.id = pl.cliente_id
         LEFT JOIN cargos ca ON ca.plan_id = pl.id AND ca.usuario_id = {U}
         WHERE pl.usuario_id = {U} AND c.usuario_id = {U}$extra
         GROUP BY pl.id ORDER BY pl.estado, pl.creado_en DESC, pl.id DESC",
        $params
    );
    if (!$planes) {
        return [];
    }
    // Próxima cuota pendiente de cada plan
    $ids = array_map(fn($p) => (int) $p['id'], $planes);
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $proxima = [];
    foreach (filas(
        "SELECT ca.plan_id, ca.id, ca.cuota_numero, ca.fecha_vencimiento, ca.monto - ca.monto_pagado AS saldo FROM cargos ca
         WHERE ca.usuario_id = {U} AND ca.estado IN ('pendiente','parcial') AND ca.plan_id IN ($marcas)
         ORDER BY ca.fecha_vencimiento, ca.cuota_numero",
        $ids
    ) as $f) {
        $proxima[$f['plan_id']] ??= $f;
    }
    $salida = [];
    foreach ($planes as $p) {
        $p['estado_visible'] = plan_estado_visible($p, (int) $p['n_pendientes']);
        $p['proxima'] = $proxima[$p['id']] ?? null;
        if ($estado === '' || $p['estado_visible'] === $estado) {
            $salida[] = $p;
        }
    }
    return $salida;
}

function pagar_cuotas(int $planId, array $cargoIds, string $fecha, string $medio): array
{
    return con_plan_bloqueado($planId, function ($plan) use ($planId, $cargoIds, $fecha, $medio) {
        if (!$plan || $plan['estado'] !== 'activo') {
            throw new RuntimeException('El plan no existe o está cancelado.');
        }
        if (!fecha_valida($fecha) || !isset(MEDIOS_PAGO[$medio])) {
            throw new RuntimeException('Revisá la fecha y el medio de pago.');
        }
        $cargoIds = array_values(array_unique(array_map('intval', $cargoIds)));
        if (!$cargoIds) {
            throw new RuntimeException('Elegí al menos una cuota.');
        }
        $manual = [];
        $total = 0.0;
        $nums = [];
        foreach (cuotas_de_plan($planId, true) as $c) {
            if (in_array((int) $c['id'], $cargoIds, true)) {
                if (!in_array($c['estado'], ['pendiente', 'parcial'], true)) {
                    throw new RuntimeException('La cuota ' . $c['cuota_numero'] . ' ya está pagada o anulada.');
                }
                $manual[(int) $c['id']] = round((float) $c['saldo'], 2);
                $total += (float) $c['saldo'];
                $nums[] = $c['cuota_numero'];
            }
        }
        if (count($manual) !== count($cargoIds)) {
            throw new RuntimeException('Alguna de las cuotas elegidas no pertenece a este plan.');
        }
        $cot = cotizacion_para_pago($plan['moneda']);
        $pagoId = registrar_pago(
            (int) $plan['cliente_id'], $fecha, round($total, 2), $plan['moneda'], $medio,
            'Cuota' . (count($nums) > 1 ? 's ' : ' ') . implode(', ', $nums) . '/' . $plan['cuotas'] . ' — ' . $plan['concepto'],
            $cot, $manual
        );
        return [$pagoId, round($total, 2)];
    });
}

function editar_cuotas_pendientes(int $planId, array $nuevas): void
{
    con_plan_bloqueado($planId, function ($plan) use ($planId, $nuevas) {
        if (!$plan || $plan['estado'] !== 'activo') {
            throw new RuntimeException('El plan no existe o está cancelado.');
        }
        $editables = [];
        foreach (cuotas_de_plan($planId, true) as $c) {
            if (in_array($c['estado'], ['pendiente', 'parcial'], true)) {
                $editables[(int) $c['id']] = $c;
            }
        }
        if (!$editables) {
            throw new RuntimeException('El plan no tiene cuotas pendientes.');
        }
        if (array_diff_key($editables, $nuevas) || array_diff_key($nuevas, $editables)) {
            throw new RuntimeException('Faltan o sobran cuotas en lo que se envió.');
        }
        $sumaVieja = 0.0;
        $sumaNueva = 0.0;
        $previa = '';
        foreach ($editables as $id => $c) {
            $monto = round((float) $nuevas[$id]['monto'], 2);
            $fecha = (string) $nuevas[$id]['fecha'];
            $n = $c['cuota_numero'];
            if ($monto < 0.01 || $monto > MONTO_MAX || $monto < round((float) $c['monto_pagado'], 2) - 0.005) {
                throw new RuntimeException("La cuota $n no puede quedar por debajo de lo que ya se pagó (" . fmt_monto($c['monto_pagado'], $plan['moneda']) . ') ni ser cero.');
            }
            if (!fecha_valida($fecha)) {
                throw new RuntimeException("La fecha de la cuota $n no es válida.");
            }
            if ($previa !== '' && $fecha < $previa) {
                throw new RuntimeException("La fecha de la cuota $n no puede ser anterior a la de la cuota anterior.");
            }
            $previa = $fecha;
            $sumaVieja += (float) $c['monto'];
            $sumaNueva += $monto;
        }
        if (abs($sumaVieja - $sumaNueva) > 0.005) {
            throw new RuntimeException('El saldo a redistribuir es ' . fmt_monto($sumaVieja, $plan['moneda']) . ' y las cuotas suman ' . fmt_monto($sumaNueva, $plan['moneda']) . '.');
        }
        foreach ($editables as $id => $c) {
            $monto = round((float) $nuevas[$id]['monto'], 2);
            $pagado = round((float) $c['monto_pagado'], 2);
            $estado = $pagado >= $monto - 0.015 ? 'pagado' : ($pagado > 0 ? 'parcial' : 'pendiente');
            // El link de Mercado Pago de una cuota cuyo monto cambió ya no sirve
            q(
                'UPDATE cargos SET monto = ?, fecha_vencimiento = ?, periodo = ?, estado = ?,
                        mp_link = NULL, mp_preference_id = NULL, mp_saldo = NULL, mp_creado_en = NULL
                 WHERE id = ? AND plan_id = ? AND usuario_id = {U}',
                [$monto, $nuevas[$id]['fecha'], substr((string) $nuevas[$id]['fecha'], 0, 7), $estado, $id, $planId]
            );
        }
    });
}

function cancelar_plan(int $planId): array
{
    return con_plan_bloqueado($planId, function ($plan) use ($planId) {
        if (!$plan || $plan['estado'] !== 'activo') {
            throw new RuntimeException('El plan no existe o ya está cancelado.');
        }
        $res = ['anuladas' => 0, 'cerradas' => 0];
        foreach (cuotas_de_plan($planId, true) as $c) {
            if (!in_array($c['estado'], ['pendiente', 'parcial'], true)) {
                continue;
            }
            if ((float) $c['monto_pagado'] < 0.005) {
                q("UPDATE cargos SET estado = 'anulado' WHERE id = ? AND usuario_id = {U}", [$c['id']]);
                $res['anuladas']++;
            } else {
                q("UPDATE cargos SET monto = monto_pagado, estado = 'pagado' WHERE id = ? AND usuario_id = {U}", [$c['id']]);
                $res['cerradas']++;
            }
        }
        q("UPDATE planes_pago SET estado = 'cancelado', cancelado_en = NOW() WHERE id = ? AND usuario_id = {U}", [$planId]);
        return $res;
    });
}

/**
 * Cuotas de planes activos que vencen dentro de los próximos $dias días (incluye las ya vencidas con saldo),
 * para el dashboard y la pantalla de vencimientos.
 */
function cuotas_proximas(int $dias): array
{
    return filas(
        "SELECT ca.id, ca.plan_id, ca.cuota_numero, ca.cuota_total, ca.fecha_vencimiento AS fecha, ca.moneda,
                ca.monto - ca.monto_pagado AS saldo, pl.concepto, c.id AS cid, c.nombre AS cliente
         FROM cargos ca JOIN planes_pago pl ON pl.id = ca.plan_id JOIN clientes c ON c.id = ca.cliente_id
         WHERE ca.usuario_id = {U} AND pl.usuario_id = {U} AND c.usuario_id = {U} AND pl.estado = 'activo'
           AND ca.estado IN ('pendiente','parcial') AND ca.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
         ORDER BY ca.fecha_vencimiento, ca.id",
        [$dias]
    );
}
