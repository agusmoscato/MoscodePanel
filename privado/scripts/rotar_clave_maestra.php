<?php
/**
 * rotar_clave_maestra.php — Vuelve a cifrar todas las credenciales con la clave maestra ACTIVA. Solo por consola.
 *
 * Pasos para rotar la clave maestra (cada paso se puede revertir hasta el último):
 *   1) Generá una clave nueva:              php privado/scripts/rotar_clave_maestra.php --generar
 *   2) En config.php → seguridad, agregala SIN sacar la vieja y marcala como activa:
 *          'claves'       => ['k1' => '<la vieja>', 'k2' => '<la nueva>'],
 *          'clave_activa' => 'k2',
 *      (si hasta ahora usabas 'clave_maestra', esa es 'k1')
 *   3) Mirá el estado (cuántos valores hay con cada clave):   php privado/scripts/rotar_clave_maestra.php --estado
 *   4) Recifrá todo (transacción: o se cambian todos o ninguno):  php privado/scripts/rotar_clave_maestra.php --recifrar
 *   5) Volvé a correr --estado: tiene que decir que todo está con la clave activa. Recién ahí podés borrar la vieja de config.php.
 *
 * También convierte los valores del formato viejo (sin contexto) al nuevo. Nunca imprime claves ni valores.
 * Alcance: las credenciales de usuario_config marcadas como cifradas (SMTP, Telegram, Mercado Pago) y el secreto del
 * 2FA (usuarios.totp_secreto). Los backups usan otra clave (seguridad.clave_backup) y no se tocan.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por consola.');
}
define('SIN_SESION', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

$modo = $argv[1] ?? '';
if ($modo === '--generar') {
    echo base64_encode(random_bytes(32)), "\n";
    fwrite(STDERR, "Es una clave nueva de 32 bytes. Pegala en config.php (seguridad → claves) y guardá una copia fuera del servidor.\n");
    exit(0);
}
if (!in_array($modo, ['--estado', '--recifrar'], true)) {
    fwrite(STDERR, "Uso: php privado/scripts/rotar_clave_maestra.php --generar | --estado | --recifrar\n");
    exit(2);
}
$activa = clave_activa_id();
if ($activa === null) {
    fwrite(STDERR, "No hay una clave activa válida en config.php (seguridad.claves + seguridad.clave_activa).\n");
    exit(1);
}

$filas = sin_filtro('rotación de clave: recorrer todas las credenciales cifradas', fn() => filas('SELECT usuario_id, clave, valor FROM usuario_config WHERE cifrado = 1'));
$totp = sin_filtro('rotación de clave: secretos 2FA', fn() => filas('SELECT id, totp_secreto FROM usuarios WHERE totp_secreto IS NOT NULL'));
$conteo = [];
$ilegibles = [];
foreach ($filas as $f) {
    $info = cifrado_info((string) $f['valor']);
    $clave = $info ? ($info['formato'][1] === '2' ? $info['kid'] : 'formato viejo') : 'desconocido';
    $conteo[$clave] = ($conteo[$clave] ?? 0) + 1;
    if (descifrar((string) $f['valor'], $f['usuario_id'] . '|' . $f['clave']) === null) {
        $ilegibles[] = 'usuario ' . $f['usuario_id'] . ' · ' . $f['clave'];
    }
}
foreach ($totp as $t) {
    $info = cifrado_info((string) $t['totp_secreto']);
    $clave = $info ? ($info['formato'][1] === '2' ? $info['kid'] : 'formato viejo') : 'desconocido';
    $conteo[$clave] = ($conteo[$clave] ?? 0) + 1;
    if (descifrar((string) $t['totp_secreto'], 'totp|' . $t['id']) === null) {
        $ilegibles[] = 'usuario ' . $t['id'] . ' · secreto 2FA';
    }
}
echo 'Clave activa: ' . $activa . ' · valores cifrados: ' . (count($filas) + count($totp)) . "\n";
foreach ($conteo as $k => $n) {
    echo "  $n con " . ($k === $activa ? "la clave activa ($k)" : $k) . "\n";
}
if ($ilegibles) {
    fwrite(STDERR, "ATENCIÓN: no se pueden leer con las claves configuradas (¿falta una clave vieja en config.php?):\n  " . implode("\n  ", $ilegibles) . "\n");
    if ($modo === '--recifrar') {
        fwrite(STDERR, "No se recifra nada hasta que se puedan leer todos.\n");
        exit(1);
    }
}
if ($modo === '--estado') {
    $pendientes = count($filas) + count($totp) - ($conteo[$activa] ?? 0);
    echo $pendientes === 0 ? "Todo está cifrado con la clave activa: ya podés sacar las claves viejas de config.php.\n" : "Quedan $pendientes valores con otra clave o formato: corré --recifrar.\n";
    exit($ilegibles ? 1 : 0);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $n = 0;
    foreach ($filas as $f) {
        $plano = descifrar((string) $f['valor'], $f['usuario_id'] . '|' . $f['clave']);
        $nuevo = cifrar((string) $plano, $f['usuario_id'] . '|' . $f['clave']);
        if (descifrar($nuevo, $f['usuario_id'] . '|' . $f['clave']) !== $plano) {
            throw new RuntimeException('La verificación de ida y vuelta falló: no se cambia nada.');
        }
        sin_filtro('rotación de clave', fn() => q('UPDATE usuario_config SET valor = ? WHERE usuario_id = ? AND clave = ?', [$nuevo, $f['usuario_id'], $f['clave']]));
        $n++;
    }
    foreach ($totp as $t) {
        $plano = descifrar((string) $t['totp_secreto'], 'totp|' . $t['id']);
        $nuevo = cifrar((string) $plano, 'totp|' . $t['id']);
        if (descifrar($nuevo, 'totp|' . $t['id']) !== $plano) {
            throw new RuntimeException('La verificación de ida y vuelta falló: no se cambia nada.');
        }
        q('UPDATE usuarios SET totp_secreto = ? WHERE id = ?', [$nuevo, $t['id']]);
        $n++;
    }
    $pdo->commit();
    echo "Listo: $n valores recifrados con la clave $activa. Corré --estado para confirmar.\n";
} catch (Throwable $ex) {
    $pdo->rollBack();
    fwrite(STDERR, 'ERROR: ' . ($ex instanceof RuntimeException ? $ex->getMessage() : get_class($ex)) . " — no se cambió nada.\n");
    exit(1);
}
