<?php
/**
 * db.php — Conexión PDO, aislamiento entre usuarios y configuración.
 *
 * AISLAMIENTO ENTRE USUARIOS (multiusuario)
 * -----------------------------------------
 * Cada usuario gestiona sus datos de forma independiente. Para que no dependa de acordarse de un filtro
 * en cada consulta, TODA consulta pasa por q() y q() la verifica antes de ejecutarla:
 *
 *  - Las tablas con datos de usuario (TABLAS_USUARIO) deben aparecer filtradas con la marca {U}, que q()
 *    reemplaza por el id del usuario logueado:   FROM clientes c WHERE c.usuario_id = {U}
 *    (una condición por cada referencia a la tabla, incluidos JOIN y subconsultas).
 *  - Los INSERT incluyen la columna usuario_id con el valor {U} (insertar() lo hace solo).
 *  - Si una consulta nombra una tabla de usuario sin ese filtro, NO se ejecuta y lanza una excepción.
 *  - Las pocas consultas legítimamente globales (crons que recorren usuarios, buscar al dueño de un token)
 *    usan sin_filtro('motivo', fn() => ...), así se pueden auditar con un simple grep.
 */
declare(strict_types=1);

/** Tablas cuyas filas pertenecen a un usuario (todas llevan usuario_id). */
const TABLAS_USUARIO = [
    'clientes', 'servicios', 'servicios_precios_hist', 'dominios', 'planes_pago', 'cargos', 'pagos',
    'pago_imputaciones', 'notificaciones_log', 'mp_webhook_log', 'usuario_config', 'sesiones_recordar',
    'registro_actividad', 'totp_recuperacion',
];

/** Ajustes globales (tabla configuracion); todo lo demás es configuración de cada usuario. */
const CFG_GLOBALES = ['dolar_fuente', 'cotizacion_error', 'instalado', 'backup_email', 'backup_email_admin', 'backup_ultimo_email', 'backup_ultimo'];

/** Devuelve la conexión PDO (se crea una sola vez). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', conf('db.host'), conf('db.nombre'));
        try {
            $pdo = new PDO($dsn, (string) conf('db.usuario'), (string) conf('db.clave'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Hora de Argentina (UTC-3, sin horario de verano) para NOW(), CURDATE(), etc.
            $pdo->exec("SET time_zone = '-03:00'");
            // Modo estricto: un dato demasiado largo o fuera de rango da error en vez de truncarse o saturarse en silencio
            $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE'");
        } catch (PDOException $ex) {
            error_log('Conexión a la base fallida: ' . $ex->getMessage());
            pagina_error_500('El servicio no está disponible en este momento.');
        }
    }
    return $pdo;
}

// ---------------------------------------------------------------------------------------------------------
// Contexto de usuario
// ---------------------------------------------------------------------------------------------------------

/** Id del usuario en contexto (el logueado, el dueño del portal o del webhook, o el que recorre un cron). */
function usuario_id(): int
{
    $id = (int) ($GLOBALS['__uid'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('No hay un usuario en contexto para esta operación.');
    }
    return $id;
}

function hay_usuario(): bool
{
    return (int) ($GLOBALS['__uid'] ?? 0) > 0;
}

/** Fija el usuario para el resto de la petición (login, portal, webhook). */
function fijar_usuario(?int $id): void
{
    $GLOBALS['__uid'] = $id ?? 0;
}

/** Ejecuta $fn con otro usuario en contexto (lo usan los crons) y restaura el anterior. */
function con_usuario(int $id, callable $fn)
{
    $previo = (int) ($GLOBALS['__uid'] ?? 0);
    $GLOBALS['__uid'] = $id;
    try {
        return $fn();
    } finally {
        $GLOBALS['__uid'] = $previo;
    }
}

/**
 * Ejecuta consultas SIN el filtro por usuario. Solo para casos legítimos y explicados en $motivo:
 * recorrer usuarios en un cron, encontrar al dueño de un token, etc. Buscalas con: grep -rn "sin_filtro(".
 */
function sin_filtro(string $motivo, callable $fn)
{
    $previo = (bool) ($GLOBALS['__sin_filtro'] ?? false);
    $GLOBALS['__sin_filtro'] = true;
    try {
        return $fn();
    } finally {
        $GLOBALS['__sin_filtro'] = $previo;
    }
}

/**
 * Verifica que la consulta filtre por usuario cada tabla de TABLAS_USUARIO que nombra.
 * Lanza RuntimeException si falta algún filtro. Devuelve la lista de problemas (vacía si está bien)
 * cuando $lanzar es false (lo usa el auditor estático).
 */
function verificar_aislamiento(string $sql, bool $lanzar = true): array
{
    // Sin comentarios ni contenido de strings (para no confundirse con textos)
    $limpio = preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~', "~'(?:[^'\\\\]|\\\\.|'')*'~s"], ['', '', "''"], $sql) ?? $sql;
    $tablas = implode('|', TABLAS_USUARIO);
    $reservadas = 'where|set|on|inner|left|right|cross|join|using|group|order|limit|values|select|union|as|for|having|natural|straight_join|partition|ignore|lock|and|or';
    preg_match_all(
        '/\b(FROM|JOIN|INTO|UPDATE)\s+`?(' . $tablas . ')`?(?![\w])(?:\s+(?:AS\s+)?(?!(?:' . $reservadas . ')\b)([a-z_][a-z0-9_]*))?/i',
        $limpio,
        $refs,
        PREG_SET_ORDER
    );
    $problemas = [];
    $porIdent = [];
    // Los JOIN con coma (FROM clientes c, cargos ca) se prestan a olvidar el filtro de una de las tablas: no se permiten
    if (preg_match('/\b(?:FROM|JOIN)\s+`?(?:' . $tablas . ')`?(?:\s+(?:AS\s+)?(?!(?:' . $reservadas . ')\b)[a-z_][a-z0-9_]*)?\s*,/i', $limpio)) {
        $problemas[] = 'JOIN con coma entre tablas de usuario: usá JOIN … ON y filtrá cada tabla';
    }
    foreach ($refs as $r) {
        $verbo = strtoupper($r[1]);
        $tabla = strtolower($r[2]);
        $alias = $r[3] ?? '';
        if ($verbo === 'INTO') {
            // INSERT: la lista de columnas debe incluir usuario_id y los valores {U}
            $ok = preg_match('/INTO\s+`?' . $tabla . '`?\s*\(([^)]*)\)/i', $limpio, $m)
                && preg_match('/\busuario_id\b/', $m[1]) && str_contains($sql, '{U}');
            if (!$ok) {
                $problemas[] = "INSERT en $tabla sin usuario_id = {U}";
            }
            continue;
        }
        $ident = $alias !== '' ? strtolower($alias) : $tabla;
        $porIdent[$ident]['refs'] = ($porIdent[$ident]['refs'] ?? 0) + 1;
        $porIdent[$ident]['tabla'] = $tabla;
        $porIdent[$ident]['alias'] = $alias !== '';
    }
    foreach ($porIdent as $ident => $d) {
        $filtros = preg_match_all('/\b' . preg_quote($ident, '/') . '\.usuario_id\s*=\s*\{U\}/i', $limpio);
        if (!$d['alias']) {   // sin alias también vale el filtro "pelado"
            $filtros += preg_match_all('/(?<![\w.])usuario_id\s*=\s*\{U\}/i', $limpio);
        }
        if ($filtros < $d['refs']) {
            $problemas[] = "{$d['tabla']}" . ($d['alias'] ? " ($ident)" : '') . " sin filtrar por usuario ({$d['refs']} referencia/s, $filtros filtro/s)";
        }
    }
    if ($problemas && $lanzar) {
        error_log('AISLAMIENTO: ' . implode('; ', $problemas) . ' — ' . preg_replace('/\s+/', ' ', mb_substr($sql, 0, 300)));
        throw new RuntimeException('Consulta bloqueada por falta de filtro de usuario: ' . implode('; ', $problemas));
    }
    return $problemas;
}

/** Verifica y expande {U}. */
function preparar_sql(string $sql): string
{
    if (!($GLOBALS['__sin_filtro'] ?? false)) {
        verificar_aislamiento($sql);
    }
    if (str_contains($sql, '{U}')) {
        $sql = str_replace('{U}', (string) usuario_id(), $sql);
    }
    return $sql;
}

// ---------------------------------------------------------------------------------------------------------
// Consultas
// ---------------------------------------------------------------------------------------------------------

/** Ejecuta una consulta preparada (verificada) y devuelve el statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare(preparar_sql($sql));
    $st->execute($params);
    return $st;
}

/** Primera fila o null. */
function fila(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

/** Todas las filas. */
function filas(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Primera columna de la primera fila (o null). */
function valor(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

/**
 * Inserta una fila a partir de un array columna => valor. Devuelve el id nuevo.
 * En las tablas de usuario agrega usuario_id solo (siempre el del contexto: no se puede forzar otro).
 */
function insertar(string $tabla, array $datos): int
{
    $tenant = in_array($tabla, TABLAS_USUARIO, true);
    if ($tenant) {
        unset($datos['usuario_id']);
    }
    foreach (array_keys($datos) as $c) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', (string) $c)) {
            throw new InvalidArgumentException('Nombre de columna inválido.');
        }
    }
    $cols = array_keys($datos);
    $valores = array_fill(0, count($cols), '?');
    if ($tenant) {
        array_unshift($cols, 'usuario_id');
        array_unshift($valores, '{U}');
    }
    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $tabla,
        implode(',', array_map(fn($c) => "`$c`", $cols)),
        implode(',', $valores)
    );
    q($sql, array_values($datos));
    return (int) db()->lastInsertId();
}

/** Actualiza una fila por id (en las tablas de usuario, solo si es del usuario). Devuelve filas afectadas. */
function actualizar(string $tabla, int $id, array $datos): int
{
    if (in_array($tabla, TABLAS_USUARIO, true)) {
        unset($datos['usuario_id']);                 // nunca se puede cambiar el dueño de una fila
    }
    foreach (array_keys($datos) as $c) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', (string) $c)) {
            throw new InvalidArgumentException('Nombre de columna inválido.');
        }
    }
    $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($datos)));
    $extra = in_array($tabla, TABLAS_USUARIO, true) ? ' AND usuario_id = {U}' : '';
    return q("UPDATE `$tabla` SET $set WHERE id=?$extra", [...array_values($datos), $id])->rowCount();
}

// ---------------------------------------------------------------------------------------------------------
// Configuración
// ---------------------------------------------------------------------------------------------------------

/** ¿Esta clave de usuario se guarda cifrada? (SMTP, Telegram y Mercado Pago) */
function cfg_es_secreta(string $clave): bool
{
    return (bool) preg_match('/^(smtp_|telegram_|mp_access_token$|mp_webhook_secret$)/', $clave);
}

/** Ajustes globales (tabla configuracion). */
function cfg_global_cache(bool $recargar = false): array
{
    static $cache = null;
    if ($cache === null || $recargar) {
        $cache = [];
        foreach (filas('SELECT clave, valor FROM configuracion') as $f) {
            $cache[$f['clave']] = (string) $f['valor'];
        }
    }
    return $cache;
}

/** Configuración del usuario en contexto: clave => [valor guardado, cifrado]. */
function cfg_usuario_cache(bool $recargar = false): array
{
    static $cache = [];
    $uid = usuario_id();
    if (!isset($cache[$uid]) || $recargar) {
        $cache[$uid] = [];
        foreach (filas('SELECT clave, valor, cifrado FROM usuario_config WHERE usuario_id = {U}') as $f) {
            $cache[$uid][$f['clave']] = [(string) $f['valor'], (int) $f['cifrado']];
        }
    }
    return $cache[$uid];
}

/** Lee un ajuste: global (CFG_GLOBALES) o del usuario en contexto (descifrándolo si corresponde). */
function cfg(string $clave, string $defecto = ''): string
{
    if (in_array($clave, CFG_GLOBALES, true)) {
        return cfg_global_cache()[$clave] ?? $defecto;
    }
    $c = cfg_usuario_cache()[$clave] ?? null;
    if ($c === null) {
        return $defecto;
    }
    if ($c[1]) {
        $plano = descifrar($c[0], usuario_id() . '|' . $clave);
        if ($plano === null) {
            error_log("Config: no se pudo descifrar '$clave' (¿cambió la clave maestra?)");
            return $defecto;
        }
        return $plano;
    }
    return $c[0];
}

/** ¿Existe el ajuste guardado (aunque esté vacío)? */
function cfg_existe(string $clave): bool
{
    return in_array($clave, CFG_GLOBALES, true) ? isset(cfg_global_cache()[$clave]) : isset(cfg_usuario_cache()[$clave]);
}

/** Guarda un ajuste (los de SMTP, Telegram y Mercado Pago se cifran solos). */
function cfg_set(string $clave, string $valor): void
{
    if (in_array($clave, CFG_GLOBALES, true)) {
        q('INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)', [$clave, $valor]);
        cfg_global_cache(true);
        return;
    }
    $cifrar = cfg_es_secreta($clave) && $valor !== '';
    q(
        'INSERT INTO usuario_config (usuario_id, clave, valor, cifrado) VALUES ({U}, ?, ?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), cifrado = VALUES(cifrado)',
        [$clave, $cifrar ? cifrar($valor, usuario_id() . '|' . $clave) : $valor, $cifrar ? 1 : 0]
    );
    cfg_usuario_cache(true);
}

/** Borra un ajuste del usuario (vuelve a regir el valor por defecto). */
function cfg_borrar(string $clave): void
{
    q('DELETE FROM usuario_config WHERE usuario_id = {U} AND clave = ?', [$clave]);
    cfg_usuario_cache(true);
}

/** Credenciales del usuario (cifradas en la base): ruta con puntos → clave guardada. */
const CRED_MAPA = [
    'smtp.host' => 'smtp_host', 'smtp.puerto' => 'smtp_puerto', 'smtp.seguridad' => 'smtp_seguridad',
    'smtp.usuario' => 'smtp_usuario', 'smtp.clave' => 'smtp_clave', 'smtp.desde' => 'smtp_desde',
    'smtp.desde_nombre' => 'smtp_desde_nombre', 'telegram.token' => 'telegram_token',
    'telegram.chat_id' => 'telegram_chat_id', 'mercadopago.access_token' => 'mp_access_token',
    'mercadopago.webhook_secret' => 'mp_webhook_secret',
];

/** Lee una credencial del usuario en contexto: cred('smtp.host'), cred('telegram.token')... */
function cred(string $ruta, string $defecto = ''): string
{
    return cfg(CRED_MAPA[$ruta] ?? $ruta, $defecto);
}
