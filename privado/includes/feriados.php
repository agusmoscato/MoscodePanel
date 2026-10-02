<?php
/**
 * feriados.php — Días hábiles y feriados de Argentina.
 *
 * Los feriados viven en la tabla `feriados` (editable desde el panel). Se pueden importar
 * desde argentinadatos.com; los cargados a mano nunca se pisan al importar.
 */
declare(strict_types=1);

/** ¿Es día hábil? (no es sábado, domingo ni feriado) */
function es_dia_habil(string $ymd): bool
{
    $diaSemana = (int) date('N', strtotime($ymd));   // 1 = lunes ... 7 = domingo
    if ($diaSemana >= 6) {
        return false;
    }
    return !fila('SELECT fecha FROM feriados WHERE fecha = ?', [$ymd]);
}

/** Primer día hábil del mes (AAAA-MM) como AAAA-MM-DD. */
function primer_dia_habil(string $periodo): string
{
    for ($d = 1; $d <= 31; $d++) {
        $f = sprintf('%s-%02d', $periodo, $d);
        if (es_dia_habil($f)) {
            return $f;
        }
    }
    return $periodo . '-01';   // no debería pasar nunca
}

/** ¿Hay feriados cargados para ese año? (si no, el cálculo del día hábil puede fallar) */
function hay_feriados_del_anio(int $anio): bool
{
    return (int) valor('SELECT COUNT(*) FROM feriados WHERE fecha BETWEEN ? AND ?', ["$anio-01-01", "$anio-12-31"]) > 0;
}

/**
 * Importa los feriados de un año desde argentinadatos.com.
 * Devuelve la cantidad importada. Lanza RuntimeException si falla.
 */
function importar_feriados(int $anio): int
{
    $json = json_decode(http_get("https://api.argentinadatos.com/v1/feriados/$anio"), true);
    if (!is_array($json) || !$json) {
        throw new RuntimeException('La API de feriados no devolvió datos.');
    }
    $n = 0;
    foreach ($json as $f) {
        if (!is_array($f) || empty($f['fecha']) || !is_string($f['fecha']) || !fecha_valida($f['fecha']) || substr($f['fecha'], 0, 4) !== (string) $anio) {
            continue;                       // solo fechas válidas del año pedido
        }
        $desc = trim(strip_tags(is_string($f['nombre'] ?? null) ? $f['nombre'] : 'Feriado'));
        if (($f['tipo'] ?? '') === 'puente') {
            $desc .= ' (puente)';
        }
        // Si ya existe uno cargado a mano, se respeta; si es de la API, se actualiza.
        q(
            "INSERT INTO feriados (fecha, descripcion, origen) VALUES (?, ?, 'api')
             ON DUPLICATE KEY UPDATE descripcion = IF(origen = 'api', VALUES(descripcion), descripcion)",
            [$f['fecha'], mb_substr($desc, 0, 160)]
        );
        $n++;
    }
    return $n;
}
