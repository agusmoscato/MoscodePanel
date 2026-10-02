<?php
/**
 * restaurar_backup.php — Restaura (o solo verifica) un backup cifrado hecho por el panel. Solo por consola.
 *
 *   # 1) Verificar que el backup se puede leer (no toca la base):
 *   php privado/scripts/restaurar_backup.php --archivo=privado/backups/moscode-20261002-030000.sql.gz.enc --verificar
 *
 *   # 2) Restaurar sobre una base VACÍA (la que creaste en hPanel):
 *   php privado/scripts/restaurar_backup.php --archivo=... --confirmo
 *
 *   # 3) Restaurar pisando una base que ya tiene datos (borra las tablas y las recrea):
 *   php privado/scripts/restaurar_backup.php --archivo=... --confirmo --forzar
 *
 * La clave del backup (seguridad.clave_backup) se toma de config.php, o de un archivo con --clave-archivo=ruta, o de la
 * variable de entorno MOSCODE_CLAVE_BACKUP (útil si restaurás en otro servidor). Nunca por línea de comandos.
 * La base de destino es la de config.php (db.*).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por consola.');
}
define('SIN_SESION', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

$args = [];
foreach (array_slice($argv ?? [], 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
        $args[$m[1]] = $m[2] ?? '1';
    }
}
if (empty($args['archivo'])) {
    fwrite(STDERR, "Uso: php privado/scripts/restaurar_backup.php --archivo=ruta.sql.gz.enc [--verificar | --confirmo [--forzar]] [--clave-archivo=ruta]\n");
    exit(2);
}
$archivo = $args['archivo'];
if (!is_file($archivo)) {
    $alt = RAIZ_PRIVADA . '/backups/' . basename($archivo);
    $archivo = is_file($alt) ? $alt : $archivo;
}
if (!is_file($archivo)) {
    fwrite(STDERR, "No existe el archivo: {$args['archivo']}\n");
    exit(1);
}

// Clave: archivo, variable de entorno o config.php
$clave = null;
if (!empty($args['clave-archivo'])) {
    $b64 = trim((string) @file_get_contents($args['clave-archivo']));
    $clave = base64_decode($b64, true) ?: null;
} elseif (($env = getenv('MOSCODE_CLAVE_BACKUP')) !== false && $env !== '') {
    $clave = base64_decode($env, true) ?: null;
} else {
    $clave = backup_clave();
}
if ($clave === null || strlen($clave) !== 32) {
    fwrite(STDERR, "Falta la clave del backup (seguridad.clave_backup en config.php, --clave-archivo=ruta o la variable MOSCODE_CLAVE_BACKUP).\n");
    exit(1);
}
if (!function_exists('gzopen') || !function_exists('openssl_decrypt')) {
    fwrite(STDERR, "Falta zlib u OpenSSL en este PHP.\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/moscode-restaurar-' . bin2hex(random_bytes(6)) . '.sql.gz';
try {
    echo "Descifrando...\n";
    backup_descifrar($archivo, $tmp, $clave);
    $lineas = 0;
    $h = fopen('compress.zlib://' . $tmp, 'rb');
    while ($h && fgets($h) !== false) {
        $lineas++;
    }
    $h && fclose($h);
    echo "El backup se descifró y descomprimió bien ($lineas líneas).\n";
    if (isset($args['verificar']) || empty($args['confirmo'])) {
        echo isset($args['verificar']) ? "Verificación terminada: no se tocó la base.\n" : "No se restauró nada: agregá --confirmo para restaurar.\n";
        exit(0);
    }

    $pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', conf('db.host'), conf('db.nombre')), (string) conf('db.usuario'), (string) conf('db.clave'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $existentes = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if ($existentes && empty($args['forzar'])) {
        fwrite(STDERR, 'La base "' . conf('db.nombre') . '" ya tiene ' . count($existentes) . " tablas. Para pisarlas agregá --forzar (se borran y se recrean con los datos del backup).\n");
        exit(1);
    }
    echo 'Restaurando en la base "' . conf('db.nombre') . "\"...\n";
    $n = backup_ejecutar_sql($tmp, $pdo);
    echo "Listo: $n sentencias ejecutadas.\n";
    echo "Si el backup es de otro servidor, revisá app.url y las claves de config.php (la clave maestra tiene que ser la MISMA para que se lean las credenciales cifradas).\n";
} catch (Throwable $ex) {
    fwrite(STDERR, 'ERROR: ' . ($ex instanceof RuntimeException ? $ex->getMessage() : get_class($ex) . ': ' . $ex->getMessage()) . "\n");
    exit(1);
} finally {
    @unlink($tmp);
}
