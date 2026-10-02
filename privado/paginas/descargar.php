<?php
/**
 * descargar.php — Exportaciones a CSV (requiere sesión iniciada; solo lectura).
 *   /exportar/<tipo>.csv  con tipo = clientes|deudores|servicios|dominios|cargos|pagos|rep_ingresos|rep_facturado|rep_ranking|rep_dominios
 * Acepta los mismos filtros que los listados (q, estado, periodo, anio, deuda).
 */
declare(strict_types=1);

$raiz = RAIZ_PRIVADA;
require_once $raiz . '/includes/exportar.php';

require_login();
session_write_close();   // no bloquear la sesión mientras se arma el archivo

$datos = datos_export((string) ($_GET['tipo'] ?? ''), $_GET);
if ($datos === null) {
    http_response_code(404);
    exit('Exportación inexistente');
}
[$nombre, $encabezados, $filas] = $datos;
csv_enviar($nombre, $encabezados, $filas);
