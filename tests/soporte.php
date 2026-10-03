<?php
/**
 * soporte.php — Arranque común de las pruebas de integración (con base de datos real).
 *
 * No toca privado/config.php: define su PROPIO RAIZ_PRIVADA (una carpeta dentro de tests/.entorno/, generada
 * sola) con un config.php que apunta a la base de prueba, y de ahí en más usa privado/includes/bootstrap.php
 * tal cual lo usa la aplicación real (bootstrap.php resuelve sus propios includes por __DIR__, no por
 * RAIZ_PRIVADA, así que esto no duplica ni un solo archivo de privado/).
 *
 * Conexión a la base (todas tienen default, se pueden pisar por variable de entorno):
 *   MOSCODE_TEST_DB_HOST  (127.0.0.1)   MOSCODE_TEST_DB_USER  (root)   MOSCODE_TEST_DB_NAME (moscode_pruebas)
 *   MOSCODE_TEST_DB_PORT  (33069)       MOSCODE_TEST_DB_PASS  ('')
 *
 * Cada corrida de tests/run.php BORRA y vuelve a crear esa base desde install.sql: nunca hay datos viejos
 * de una corrida anterior contaminando la siguiente (fue justo lo que pasó a mano en esta sesión: pagos de
 * una prueba se imputaban al cargo de otra). Un usuario/cliente nuevo por caso de prueba alcanza para que no
 * se pisen entre sí dentro de la misma corrida.
 */
declare(strict_types=1);

const RAIZ_PROYECTO = __DIR__ . '/..';

function env_o_defecto(string $var, string $defecto): string
{
    $v = getenv($var);
    return $v === false || $v === '' ? $defecto : $v;
}

function config_db_prueba(): array
{
    return [
        'host'  => env_o_defecto('MOSCODE_TEST_DB_HOST', '127.0.0.1'),
        'port'  => env_o_defecto('MOSCODE_TEST_DB_PORT', '33069'),
        'user'  => env_o_defecto('MOSCODE_TEST_DB_USER', 'root'),
        'pass'  => env_o_defecto('MOSCODE_TEST_DB_PASS', ''),
        'name'  => env_o_defecto('MOSCODE_TEST_DB_NAME', 'moscode_pruebas'),
    ];
}

/** Borra y vuelve a crear la base de prueba desde install.sql (siempre arranca limpia). */
function preparar_base_de_prueba(array $db): void
{
    $dsn = "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    $nombre = $db['name'];
    $pdo->exec("DROP DATABASE IF EXISTS `$nombre`");
    $pdo->exec("CREATE DATABASE `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$nombre`");
    $sql = file_get_contents(RAIZ_PROYECTO . '/privado/install/install.sql');
    if ($sql === false) {
        throw new RuntimeException('No se pudo leer privado/install/install.sql');
    }
    $pdo->exec($sql);
}

/**
 * Escribe el config.php de prueba en una carpeta aparte (si todavía no existe: la clave maestra tiene que
 * ser la MISMA durante toda la corrida, incluidos los subprocesos de ejecutar_accion(), si no lo que cifra
 * uno no lo puede leer el otro) y fija RAIZ_PRIVADA para que bootstrap.php la use.
 */
function preparar_entorno_app(array $db): void
{
    $raiz = __DIR__ . '/.entorno/privado';
    $archivoConfig = $raiz . '/config.php';
    if (!is_file($archivoConfig)) {
        if (!is_dir($raiz) && !mkdir($raiz, 0777, true) && !is_dir($raiz)) {
            throw new RuntimeException("No se pudo crear $raiz");
        }
        $cfg = [
            'db' => ['host' => $db['host'] . ':' . $db['port'], 'nombre' => $db['name'], 'usuario' => $db['user'], 'clave' => $db['pass']],
            'app' => ['url' => 'http://localhost:8099', 'cron_token' => 'token_de_prueba_1234567890123456789012', 'proxies_confiables' => []],
            'seguridad' => ['clave_maestra' => base64_encode(random_bytes(32)), 'clave_backup' => base64_encode(random_bytes(32)), 'recordar_dias' => 90],
        ];
        file_put_contents($archivoConfig, "<?php\nreturn " . var_export($cfg, true) . ";\n");
    }
    define('RAIZ_PRIVADA', $raiz);
    define('SIN_SESION', true);
    $_SESSION = [];
    require RAIZ_PROYECTO . '/privado/includes/bootstrap.php';
}

// --- Aserciones (globales, cuentan a través de todos los tests/casos/*.php de la corrida) ---------------------

$GLOBALS['__pruebas'] = 0;
$GLOBALS['__fallos'] = [];

function verificar(string $nombre, $esperado, $real): void
{
    $GLOBALS['__pruebas']++;
    if ($esperado === $real) {
        echo "  OK      $nombre\n";
        return;
    }
    $GLOBALS['__fallos'][] = $nombre;
    echo "  FALLÓ   $nombre\n          esperado: " . var_export($esperado, true) . "\n          obtenido: " . var_export($real, true) . "\n";
}

function verificar_contiene(string $nombre, string $aguja, string $pajar): void
{
    verificar($nombre, true, str_contains($pajar, $aguja));
}

function verificar_cierto(string $nombre, bool $condicion): void
{
    verificar($nombre, true, $condicion);
}

/** Título de una sección dentro de un caso (solo para que el log se lea ordenado). */
function seccion(string $titulo): void
{
    echo "\n--- $titulo ---\n";
}

// --- Fixtures: un usuario y, si hace falta, un cliente nuevos y aislados por caso de prueba --------------------

/** Usuario nuevo (activo, admin) para un caso de prueba; deja el contexto fijado en él. */
function nuevo_usuario_de_prueba(string $prefijo): int
{
    static $n = 0;
    $n++;
    $id = insertar('usuarios', [
        'usuario' => $prefijo . '_' . $n . '_' . bin2hex(random_bytes(3)),
        'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        'nombre' => $prefijo, 'email' => $prefijo . '@pruebas.test', 'rol' => 'admin', 'activo' => 1,
        'creado_en' => date('Y-m-d H:i:s'),
    ]);
    fijar_usuario($id);
    return $id;
}

/** Cliente nuevo para el usuario en contexto. $extra pisa cualquier campo por defecto (ej. ['telefono' => '']). */
function nuevo_cliente_de_prueba(string $nombre, array $extra = []): int
{
    $base = [
        'nombre' => $nombre, 'contacto' => 'Contacto', 'email' => '',
        'telefono' => '3410000000', 'cuit' => '', 'notas' => null,
        'estado' => 'activo', 'creado_en' => date('Y-m-d H:i:s'),
    ];
    return insertar('clientes', $extra + $base);
}

/**
 * Corre una acción real de privado/acciones/ (igual que la llamaría panel.php) en un SUBPROCESO aparte: las
 * acciones terminan con redirigir() (header + exit), que mataría toda la corrida de pruebas si se hiciera
 * con un require directo acá. El subproceso usa la misma base ya preparada (no la vuelve a crear) y, si el
 * POST no trae "nonce", genera uno válido solo (la mayoría de las acciones ni lo usan). Para ver qué pasó,
 * la que llama vuelve a consultar la base después (fila(), valor(), etc.), como lo haría la vista siguiente.
 */
function ejecutar_accion(int $usuarioId, string $accion, array $post = []): void
{
    // El JSON va en base64: escapeshellarg() en Windows borra las comillas dobles de un argumento
    // (las cambia por nada), así que un JSON pasado tal cual llega roto al otro lado.
    $b64 = base64_encode(json_encode($post));
    $cmd = implode(' ', array_map('escapeshellarg', [
        PHP_BINARY, __DIR__ . '/harness_accion.php', (string) $usuarioId, $accion, $b64,
    ]));
    exec($cmd . ' 2>&1', $salida, $codigo);
    if ($codigo !== 0) {
        throw new RuntimeException("ejecutar_accion($accion) terminó con código $codigo:\n" . implode("\n", $salida));
    }
}
