<?php
/**
 * run.php — Corre TODAS las pruebas del proyecto (un solo comando):
 *
 *   php tests/run.php
 *
 * Necesita PHP 8.1+ con pdo_mysql, y un MariaDB/MySQL alcanzable (ver soporte.php para las variables de
 * entorno de conexión; por defecto, el mismo MariaDB portable de tests/.tools/ que arranca tests/entorno.sh).
 * Si no tenés ese entorno armado todavía, corré tests/entorno.sh en vez de este archivo directamente: lo
 * descarga, lo levanta, corre esto y lo apaga.
 *
 * Cada corrida borra y vuelve a crear la base de prueba desde cero (no toca privado/config.php ni ninguna
 * base real). Casos de prueba: cada archivo de tests/casos/*.php, en orden alfabético.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por consola.');
}

require __DIR__ . '/soporte.php';

$db = config_db_prueba();
echo "Base de prueba: {$db['name']} en {$db['host']}:{$db['port']} (usuario {$db['user']})\n";

try {
    preparar_base_de_prueba($db);
} catch (Throwable $ex) {
    fwrite(STDERR, "No se pudo preparar la base de prueba: {$ex->getMessage()}\n");
    fwrite(STDERR, "¿Está corriendo el MariaDB portable? Corré tests/entorno.sh, o fijá MOSCODE_TEST_DB_* si usás otro.\n");
    exit(1);
}
@unlink(__DIR__ . '/.entorno/privado/config.php');   // config nueva (con clave maestra nueva) en cada corrida completa
preparar_entorno_app($db);
echo "Esquema recreado desde privado/install/install.sql.\n";

$casos = glob(__DIR__ . '/casos/*.php') ?: [];
sort($casos);
foreach ($casos as $archivo) {
    echo "\n=== " . basename($archivo) . " ===\n";
    require $archivo;
}

$pruebas = $GLOBALS['__pruebas'];
$fallos = $GLOBALS['__fallos'];
echo "\n============================================================\n";
echo $pruebas . ' pruebas, ' . ($pruebas - count($fallos)) . " OK, " . count($fallos) . " fallaron.\n";
if ($fallos) {
    echo "Fallaron:\n";
    foreach ($fallos as $f) {
        echo "  - $f\n";
    }
}
exit($fallos ? 1 : 0);
