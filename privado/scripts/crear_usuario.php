<?php
/**
 * crear_usuario.php — Crea un usuario del panel desde la consola (nunca por web).
 *
 *   php privado/scripts/crear_usuario.php --usuario=j.t --nombre="J.T." --rol=usuario --clave="UnaClaveTemporal123"
 *
 * Argumentos:
 *   --usuario=  (obligatorio) 3 a 60 caracteres: letras, números, punto, guion, guion bajo.
 *   --nombre=   nombre a mostrar (si falta, se usa el usuario).
 *   --email=    opcional.
 *   --rol=      "usuario" (por defecto) o "admin".
 *   --clave=    contraseña TEMPORAL (mínimo 8). Si falta, se genera una de 12 caracteres y se muestra acá.
 *
 * La contraseña queda marcada como temporal: al iniciar sesión el usuario está obligado a cambiarla.
 * No pisa un usuario existente. La cuenta arranca con la configuración por defecto, sin credenciales ni clientes.
 *
 * En Hostinger: por SSH, o como Cron Job de una sola vez (hPanel → Avanzado → Cron Jobs) con el comando completo
 * y los argumentos; el resultado (con la contraseña si se generó) se ve en el correo/salida del cron. Borrá el
 * cron después de usarlo. También se puede crear desde la pantalla Usuarios del administrador.
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
    if (preg_match('/^--([a-z]+)=(.*)$/s', $a, $m)) {
        $args[$m[1]] = $m[2];
    } elseif ($a === '--ayuda' || $a === '-h') {
        $args['ayuda'] = '1';
    }
}
if (isset($args['ayuda']) || empty($args['usuario'])) {
    fwrite(STDERR, "Uso: php privado/scripts/crear_usuario.php --usuario=j.t --nombre=\"J.T.\" [--email=...] [--rol=usuario|admin] [--clave=...]\n");
    exit(isset($args['ayuda']) ? 0 : 2);
}

$r = crear_usuario([
    'usuario' => $args['usuario'],
    'nombre' => $args['nombre'] ?? '',
    'email' => $args['email'] ?? '',
    'rol' => $args['rol'] ?? 'usuario',
    'clave' => $args['clave'] ?? '',
]);
if (!$r['ok']) {
    fwrite(STDERR, 'ERROR: ' . $r['error'] . "\n");
    exit(1);
}

echo "Usuario creado: {$args['usuario']} (" . ($args['rol'] ?? 'usuario') . ")\n";
echo ($r['generada'] ? 'Contraseña temporal generada: ' : 'Contraseña temporal: ') . $r['clave'] . "\n";
echo "Tiene que cambiarla al iniciar sesión.\n";
exit(0);
