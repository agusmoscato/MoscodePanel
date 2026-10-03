<?php
/**
 * esquema_migraciones.php — Una instalación vieja actualizada con las migraciones tiene que quedar con el MISMO
 * esquema que una instalación nueva con el install.sql actual (columnas en el mismo orden, tipos, NULL, default,
 * índices). Si alguien agrega una columna a install.sql y se olvida de la migración (o al revés), esto falla.
 *
 * Los install.sql viejos salen de la historia de git (git show <commit>:privado/install/install.sql); si git no
 * está disponible, el caso se saltea con un aviso (no falla). Usa dos bases aparte que borra al terminar.
 */
declare(strict_types=1);

/** Para cada versión publicada: commit cuyo install.sql se toma como "instalación vieja" y las migraciones que le faltan. */
$versionesViejas = [
    '10c16dc' => ['migracion_008.sql', 'migracion_009.sql'],
    'efb0307' => ['migracion_009.sql'],
];

$dbCfg = config_db_prueba();
$servidor = new PDO("mysql:host={$dbCfg['host']};port={$dbCfg['port']};charset=utf8mb4", $dbCfg['user'], $dbCfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

/** Crea (desde cero) la base $nombre y le corre cada SQL de $sqls, en orden. */
$armarBase = function (string $nombre, array $sqls) use ($servidor): void {
    $servidor->exec("DROP DATABASE IF EXISTS `$nombre`");
    $servidor->exec("CREATE DATABASE `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $servidor->exec("USE `$nombre`");
    foreach ($sqls as $sql) {
        $st = $servidor->query($sql);
        do {
            // consumir todos los resultados del multi-statement (si no, el siguiente exec falla)
        } while ($st->nextRowset());
        $st->closeCursor();
    }
};

/** Esquema comparable: columnas (en orden) e índices de cada tabla de $nombre. */
$esquema = function (string $nombre) use ($servidor): array {
    $cols = $servidor->prepare(
        'SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION'
    );
    $cols->execute([$nombre]);
    $idx = $servidor->prepare(
        'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
         FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
    );
    $idx->execute([$nombre]);
    $res = [];
    foreach ($cols->fetchAll() as $c) {
        $res[$c['TABLE_NAME']]['columnas'][] = implode(' | ', [$c['COLUMN_NAME'], $c['COLUMN_TYPE'], $c['IS_NULLABLE'], var_export($c['COLUMN_DEFAULT'], true), $c['EXTRA'], (string) $c['COLLATION_NAME']]);
    }
    foreach ($idx->fetchAll() as $i) {
        $res[$i['TABLE_NAME']]['indices'][] = implode(' | ', [$i['INDEX_NAME'], $i['NON_UNIQUE'], $i['SEQ_IN_INDEX'], $i['COLUMN_NAME']]);
    }
    ksort($res);
    return $res;
};

/** Primera diferencia legible entre dos esquemas ('' si son iguales). */
$diferencia = function (array $a, array $b): string {
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $tabla) {
        if (!isset($a[$tabla]) || !isset($b[$tabla])) {
            return "la tabla $tabla está en una sola de las dos";
        }
        foreach (['columnas', 'indices'] as $parte) {
            $x = $a[$tabla][$parte] ?? [];
            $y = $b[$tabla][$parte] ?? [];
            if ($x !== $y) {
                $n = max(count($x), count($y));
                for ($i = 0; $i < $n; $i++) {
                    if (($x[$i] ?? null) !== ($y[$i] ?? null)) {
                        return "$tabla ($parte #$i): migrada = " . ($x[$i] ?? '(nada)') . ' / nueva = ' . ($y[$i] ?? '(nada)');
                    }
                }
            }
        }
    }
    return '';
};

$dirInstall = RAIZ_PROYECTO . '/privado/install';
$baseNueva = $dbCfg['name'] . '_esq_nueva';
$baseMigrada = $dbCfg['name'] . '_esq_migrada';

seccion('cada migración existe y la anterior a ella también (sin huecos en la numeración)');
$migraciones = glob($dirInstall . '/migracion_*.sql') ?: [];
sort($migraciones);
$numeros = array_map(fn($f) => (int) substr(basename($f, '.sql'), 10), $migraciones);
verificar('migraciones numeradas 1..N sin huecos', range(1, count($numeros)), $numeros);
verificar_cierto('existe migracion_009.sql (dias_anticipo y aviso de renovación en dominios)', is_file($dirInstall . '/migracion_009.sql'));
verificar_contiene('migracion_009.sql agrega dominios.dias_anticipo', 'ADD COLUMN dias_anticipo', (string) file_get_contents($dirInstall . '/migracion_009.sql'));

$armarBase($baseNueva, [file_get_contents($dirInstall . '/install.sql')]);
$esquemaNuevo = $esquema($baseNueva);
verificar_cierto('install.sql actual: dominios tiene dias_anticipo', (bool) preg_grep('/^dias_anticipo \|/', $esquemaNuevo['dominios']['columnas'] ?? []));

$hayGit = (function (): bool {
    exec('git --version 2>&1', $s, $codigo);
    return $codigo === 0;
})();

foreach ($versionesViejas as $commit => $faltantes) {
    seccion("install.sql de $commit + " . implode(' + ', $faltantes) . ' = install.sql actual');
    $viejo = null;
    if ($hayGit) {
        exec('git -C ' . escapeshellarg(RAIZ_PROYECTO) . ' show ' . escapeshellarg("$commit:privado/install/install.sql") . ' 2>&1', $lineas, $codigo);
        $viejo = $codigo === 0 ? implode("\n", $lineas) : null;
        unset($lineas);
    }
    if ($viejo === null) {
        echo "  (salteado: no hay git o no está el commit $commit en este clon)\n";
        continue;
    }
    $sqls = [$viejo];
    foreach ($faltantes as $m) {
        $sqls[] = file_get_contents($dirInstall . '/' . $m);
    }
    $armarBase($baseMigrada, $sqls);
    $dif = $diferencia($esquema($baseMigrada), $esquemaNuevo);
    if ($dif !== '') {
        echo "          primera diferencia: $dif\n";
    }
    verificar("esquema migrado desde $commit idéntico al de una instalación nueva", '', $dif);
}

$servidor->exec("DROP DATABASE IF EXISTS `$baseNueva`");
$servidor->exec("DROP DATABASE IF EXISTS `$baseMigrada`");
