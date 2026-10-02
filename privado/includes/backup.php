<?php
/**
 * backup.php — Backup de la base de datos hecho con PHP (no depende de mysqldump).
 *
 * Cada backup es un volcado SQL (una sentencia por línea, para poder restaurarlo línea a línea), comprimido con gzip y
 * CIFRADO con una clave APARTE de la maestra (config.php → seguridad.clave_backup, 32 bytes en base64). Sin esa clave el
 * archivo no se puede leer, así que se puede guardar o mandar por email sin exponer los datos de los clientes.
 *
 * Formato del archivo .enc:  "MSCBK1\n\0" · id del archivo (16 bytes) · trozos de hasta 1 MB, cada uno:
 *   [largo del cifrado (4 bytes)] [IV (12)] [tag (16)] [datos cifrados con AES-256-GCM]
 * El dato adicional autenticado de cada trozo incluye el id del archivo, el número de trozo y si es el último:
 * no se pueden reordenar, quitar ni cortar trozos sin que falle la verificación.
 *
 * Se guardan en privado/backups/ (bloqueada por web) y se conservan los últimos 14.
 * Restaurar: php privado/scripts/restaurar_backup.php (ver README).
 */
declare(strict_types=1);

const BACKUP_MAGIA = "MSCBK1\n\0";
const BACKUP_TROZO = 1048576;
const BACKUP_CONSERVAR = 14;
const BACKUP_FILAS_POR_INSERT = 200;

/** Carpeta de backups (se crea sola, con permisos 700 y un .htaccess que la bloquea). */
function backup_dir(): string
{
    $dir = RAIZ_PRIVADA . '/backups';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        throw new RuntimeException('No se pudo crear la carpeta de backups (privado/backups).');
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    if (!is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('La carpeta privado/backups no se puede escribir (revisá los permisos en hPanel).');
    }
    return $dir;
}

/** Clave de los backups (32 bytes) o null si no está configurada o es inválida. */
function backup_clave(): ?string
{
    $raw = base64_decode((string) conf('seguridad.clave_backup', ''), true);
    return ($raw !== false && strlen($raw) === 32) ? $raw : null;
}

/** ¿Se puede hacer un backup en este servidor? Devuelve la lista de problemas (vacía si está todo bien). */
function backup_requisitos(): array
{
    $p = [];
    if (backup_clave() === null) {
        $p[] = 'Falta seguridad.clave_backup en config.php (los backups no se guardan sin cifrar).';
    }
    if (!function_exists('gzopen')) {
        $p[] = 'Falta la extensión zlib de PHP (para comprimir).';
    }
    if (!function_exists('openssl_encrypt') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
        $p[] = 'Falta OpenSSL con AES-256-GCM (para cifrar).';
    }
    return $p;
}

/** Conexión aparte, sin buffer, para recorrer tablas grandes sin llenar la memoria. */
function backup_conexion(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', conf('db.host'), conf('db.nombre'));
    $pdo = new PDO($dsn, (string) conf('db.usuario'), (string) conf('db.clave'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
    ]);
    $pdo->exec("SET time_zone = '-03:00'");
    return $pdo;
}

/** Escribe el volcado SQL en un archivo gzip abierto. Devuelve ['tablas' => n, 'filas' => n]. */
function backup_volcar($gz, PDO $pdo): array
{
    $escribir = fn(string $linea) => gzwrite($gz, $linea . "\n");
    $escribir('-- Moscode backup ' . date('Y-m-d H:i:s') . ' (una sentencia por línea)');
    $escribir('SET NAMES utf8mb4;');
    $escribir('SET FOREIGN_KEY_CHECKS = 0;');
    $escribir("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");
    $tablas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $totalFilas = 0;
    foreach ($tablas as $t) {
        $t = (string) $t;
        $escribir("DROP TABLE IF EXISTS `$t`;");
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $escribir(preg_replace('/\s*\R\s*/', ' ', (string) $create) . ';');
        $cols = null;
        $lote = [];
        $enviar = function () use (&$lote, &$cols, $escribir, $t) {
            if ($lote) {
                $escribir("INSERT INTO `$t` ($cols) VALUES " . implode(',', $lote) . ';');
                $lote = [];
            }
        };
        $st = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
        $nCols = 0;
        foreach ($st as $fila) {
            if ($cols === null) {
                $nCols = count($fila);
                $nombres = [];
                for ($i = 0; $i < $nCols; $i++) {
                    $nombres[] = '`' . $st->getColumnMeta($i)['name'] . '`';
                }
                $cols = implode(',', $nombres);
            }
            $lote[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $fila)) . ')';
            $totalFilas++;
            if (count($lote) >= BACKUP_FILAS_POR_INSERT) {
                $enviar();
            }
        }
        $enviar();
    }
    $escribir('SET FOREIGN_KEY_CHECKS = 1;');
    return ['tablas' => count($tablas), 'filas' => $totalFilas];
}

/** Cifra $origen en $destino (trozos con AES-256-GCM). */
function backup_cifrar(string $origen, string $destino, string $clave): void
{
    $in = fopen($origen, 'rb');
    $out = fopen($destino, 'wb');
    if (!$in || !$out) {
        throw new RuntimeException('No se pudo abrir el archivo del backup.');
    }
    $id = random_bytes(16);
    fwrite($out, BACKUP_MAGIA . $id);
    $actual = fread($in, BACKUP_TROZO);
    $n = 0;
    while ($actual !== false) {
        $siguiente = feof($in) ? '' : fread($in, BACKUP_TROZO);
        $ultimo = ($siguiente === '' || $siguiente === false);
        $iv = random_bytes(12);
        $tag = '';
        $c = openssl_encrypt($actual, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag, $id . pack('N', $n) . ($ultimo ? 'F' : 'C'));
        if ($c === false) {
            throw new RuntimeException('No se pudo cifrar el backup.');
        }
        fwrite($out, pack('N', strlen($c)) . $iv . $tag . $c);
        $n++;
        if ($ultimo) {
            break;
        }
        $actual = $siguiente;
    }
    fclose($in);
    fclose($out);
}

/**
 * Descifra un backup a $destino. Lanza RuntimeException si la clave no es la correcta, el archivo está dañado,
 * truncado o alterado.
 */
function backup_descifrar(string $origen, string $destino, string $clave): void
{
    $in = fopen($origen, 'rb');
    $out = fopen($destino, 'wb');
    if (!$in || !$out) {
        throw new RuntimeException('No se pudo abrir el archivo.');
    }
    $cab = fread($in, strlen(BACKUP_MAGIA) + 16);
    if ($cab === false || strlen($cab) !== strlen(BACKUP_MAGIA) + 16 || !str_starts_with($cab, BACKUP_MAGIA)) {
        throw new RuntimeException('No es un backup de Moscode (cabecera inválida).');
    }
    $id = substr($cab, strlen(BACKUP_MAGIA));
    $n = 0;
    $vistoUltimo = false;
    while (!feof($in)) {
        $h = fread($in, 4 + 12 + 16);
        if ($h === '' || $h === false) {
            break;
        }
        if (strlen($h) !== 32) {
            throw new RuntimeException('Backup dañado (trozo incompleto).');
        }
        $largo = unpack('N', substr($h, 0, 4))[1];
        if ($largo > BACKUP_TROZO + 64) {
            throw new RuntimeException('Backup dañado (tamaño de trozo inválido).');
        }
        $c = '';
        while (strlen($c) < $largo && !feof($in)) {
            $c .= fread($in, $largo - strlen($c));
        }
        if (strlen($c) !== $largo) {
            throw new RuntimeException('Backup truncado.');
        }
        $iv = substr($h, 4, 12);
        $tag = substr($h, 16, 16);
        $plano = false;
        foreach (['C', 'F'] as $marca) {
            $plano = openssl_decrypt($c, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag, $id . pack('N', $n) . $marca);
            if ($plano !== false) {
                $vistoUltimo = $marca === 'F';
                break;
            }
        }
        if ($plano === false) {
            throw new RuntimeException('No se pudo descifrar: la clave del backup no es la correcta o el archivo fue modificado.');
        }
        fwrite($out, $plano);
        $n++;
        if ($vistoUltimo) {
            break;
        }
    }
    fclose($in);
    fclose($out);
    if (!$vistoUltimo) {
        throw new RuntimeException('Backup truncado (falta el final del archivo).');
    }
}

/** Hace un backup completo: volcado → gzip → cifrado. Devuelve ['archivo', 'bytes', 'tablas', 'filas']. */
function backup_crear(): array
{
    if ($problemas = backup_requisitos()) {
        throw new RuntimeException(implode(' ', $problemas));
    }
    $dir = backup_dir();
    $base = $dir . '/moscode-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));   // el sufijo evita pisar otro backup del mismo segundo
    $tmp = $base . '.sql.gz.tmp';
    $final = $base . '.sql.gz.enc';
    $gz = gzopen($tmp, 'wb6');
    if (!$gz) {
        throw new RuntimeException('No se pudo crear el archivo temporal del backup.');
    }
    try {
        $r = backup_volcar($gz, backup_conexion());
        gzclose($gz);
        backup_cifrar($tmp, $final . '.part', (string) backup_clave());
        rename($final . '.part', $final);
        @chmod($final, 0600);
    } catch (Throwable $ex) {
        @gzclose($gz);
        @unlink($final . '.part');
        throw $ex;
    } finally {
        @unlink($tmp);           // el volcado sin cifrar no queda nunca en disco
    }
    return ['archivo' => $final, 'bytes' => (int) filesize($final)] + $r;
}

/** Backups guardados, el más nuevo primero: [['nombre', 'bytes', 'fecha'], ...]. */
function backup_listar(): array
{
    $lista = [];
    foreach (glob(RAIZ_PRIVADA . '/backups/moscode-*.sql.gz.enc') ?: [] as $f) {
        $lista[] = ['nombre' => basename($f), 'bytes' => (int) filesize($f), 'fecha' => (int) filemtime($f)];
    }
    usort($lista, fn($a, $b) => $b['nombre'] <=> $a['nombre']);
    return $lista;
}

/** Borra los backups más viejos y deja los últimos $conservar. Devuelve cuántos borró. */
function backup_rotar(int $conservar = BACKUP_CONSERVAR): int
{
    $n = 0;
    foreach (array_slice(backup_listar(), $conservar) as $b) {
        if (@unlink(RAIZ_PRIVADA . '/backups/' . $b['nombre'])) {
            $n++;
        }
    }
    return $n;
}

/**
 * Restaura un backup (ya descifrado y descomprimido en $sqlGz, un .sql.gz) ejecutando línea por línea.
 * Devuelve la cantidad de sentencias ejecutadas.
 */
function backup_ejecutar_sql(string $sqlGz, PDO $pdo): int
{
    $h = fopen('compress.zlib://' . $sqlGz, 'rb');
    if (!$h) {
        throw new RuntimeException('No se pudo leer el volcado.');
    }
    $n = 0;
    while (($linea = fgets($h)) !== false) {
        $linea = rtrim($linea, "\r\n");
        if ($linea === '' || str_starts_with($linea, '--')) {
            continue;
        }
        $pdo->exec($linea);
        $n++;
    }
    fclose($h);
    return $n;
}
