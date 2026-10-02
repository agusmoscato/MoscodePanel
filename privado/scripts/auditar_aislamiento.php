<?php
/**
 * auditar_aislamiento.php — Auditor estático del aislamiento entre usuarios (solo consola).
 *
 * Recorre todo el código PHP del panel, junta cada consulta SQL (literales unidos con "." y sql_exigible())
 * y comprueba con verificar_aislamiento() que cada tabla de datos de usuario lleve su filtro {U}.
 * Las consultas dentro de sin_filtro('motivo', ...) se listan aparte para revisarlas a mano.
 *
 * Uso:  php privado/scripts/auditar_aislamiento.php        (código de salida 1 si hay problemas)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo por consola.');
}

$raiz = str_replace(chr(92), '/', dirname(__DIR__));
require $raiz . '/includes/db.php';

$archivos = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $ruta = str_replace('\\', '/', $f->getPathname());
    if ($f->getExtension() !== 'php' || str_contains($ruta, '/scripts/') || str_ends_with($ruta, '/includes/db.php') || str_contains($ruta, '/install/')) {
        continue;
    }
    $archivos[] = $ruta;
}
$publico = dirname($raiz) . '/public_html';
if (is_dir($publico)) {
    foreach (glob($publico . '/*.php') ?: [] as $f) {
        $archivos[] = str_replace('\\', '/', $f);
    }
}
sort($archivos);

$problemas = [];
$excepciones = [];
$consultas = 0;

foreach ($archivos as $archivo) {
    $tokens = token_get_all(file_get_contents($archivo));
    $n = count($tokens);
    $buffer = '';
    $linea = 0;
    $profundidad = 0;
    $sinFiltroEn = [];       // profundidades de paréntesis donde se abrió un sin_filtro(
    $ultimoTexto = '';

    $volcar = function () use (&$buffer, &$linea, &$sinFiltroEn, &$problemas, &$excepciones, &$consultas, $archivo, $raiz) {
        $sql = $buffer;
        $buffer = '';
        if (!preg_match('/\b(SELECT|UPDATE|DELETE|INSERT)\b/i', $sql) || !preg_match('/\b(FROM|INTO|UPDATE)\b/i', $sql)) {
            return;
        }
        $consultas++;
        $rel = ltrim(str_replace(dirname($raiz), '', $archivo), '/');
        $p = verificar_aislamiento($sql, false);
        if ($sinFiltroEn) {
            $excepciones[] = "$rel:$linea — " . preg_replace('/\s+/', ' ', mb_substr(trim($sql), 0, 90));
        } elseif ($p) {
            $problemas[] = "$rel:$linea — " . implode('; ', $p) . "\n      " . preg_replace('/\s+/', ' ', mb_substr(trim($sql), 0, 140));
        }
    };

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t)) {
            [$id, $texto, $lin] = $t;
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $buffer === '' && $linea = $lin;
                $buffer .= ' ' . substr($texto, 1, -1);
                continue;
            }
            if ($id === T_ENCAPSED_AND_WHITESPACE) {
                $buffer === '' && $linea = $lin;
                $buffer .= $texto;
                continue;
            }
            if ($id === T_VARIABLE && $buffer !== '' && $ultimoTexto === 'enc') {
                $buffer .= '?';
                continue;
            }
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($id === T_STRING && strtolower($texto) === 'sin_filtro') {
                $volcar();
                $sinFiltroEn[] = $profundidad;
                continue;
            }
            // Llamada a sql_exigible()/sql_exigible_o_pagado() dentro de una concatenación
            if ($id === T_STRING && $buffer !== '' && str_starts_with(strtolower($texto), 'sql_exigible')) {
                $prof = 0;
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($tokens[$j] === '(') {
                        $prof++;
                    } elseif ($tokens[$j] === ')') {
                        $prof--;
                        if ($prof === 0) {
                            break;
                        }
                    }
                }
                $i = $j;
                $buffer .= ' (1=1) ';
                continue;
            }
            $volcar();
            continue;
        }
        // Símbolos sueltos
        if ($t === '.' && $buffer !== '') {
            continue;                      // concatenación: el SQL sigue
        }
        if ($t === '"') {
            $ultimoTexto = $ultimoTexto === 'enc' ? '' : 'enc';
            continue;
        }
        if ($t === '(') {
            $profundidad++;
        } elseif ($t === ')') {
            $profundidad--;
            if ($sinFiltroEn && end($sinFiltroEn) === $profundidad) {
                array_pop($sinFiltroEn);
            }
        }
        if ($t === ',' || $t === ')' || $t === ';' || $t === ']') {
            $volcar();
        }
    }
    $volcar();
}

echo "Consultas SQL analizadas: $consultas en " . count($archivos) . " archivos.\n\n";
echo "Consultas globales declaradas con sin_filtro() (revisar a mano): " . count($excepciones) . "\n";
foreach ($excepciones as $e) {
    echo "  · $e\n";
}
echo "\n";
if ($problemas) {
    echo 'PROBLEMAS (' . count($problemas) . "):\n";
    foreach ($problemas as $p) {
        echo "  ✗ $p\n";
    }
    exit(1);
}
echo "OK: toda consulta a tablas de usuario lleva su filtro {U}.\n";
