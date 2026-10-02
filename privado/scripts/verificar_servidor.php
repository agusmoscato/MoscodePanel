<?php
/**
 * verificar_servidor.php — Revisa el servidor y la instalación (solo por consola). No cambia nada y NO muestra claves.
 *
 *   php privado/scripts/verificar_servidor.php              (revisa el entorno, los permisos y la configuración)
 *   php privado/scripts/verificar_servidor.php --web        (además prueba desde afuera, con app.url, que nada privado se vea)
 *   (para probar otra dirección: --web --url=https://otro-dominio.com)
 *
 * Cada línea dice OK, AVISO o PROBLEMA y, si hace falta, qué hacer. Es el complemento del checklist de seguridad del README.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por consola.');
}
define('SIN_SESION', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

$problemas = 0;
$avisos = 0;
function linea(string $estado, string $texto, string $accion = ''): void
{
    global $problemas, $avisos;
    $estado === 'PROBLEMA' && $problemas++;
    $estado === 'AVISO' && $avisos++;
    echo str_pad($estado, 9) . $texto . ($accion !== '' && $estado !== 'OK' ? "\n          → $accion" : '') . "\n";
}

echo "== PHP ==\n";
linea(version_compare(PHP_VERSION, '8.1.0', '>=') ? 'OK' : 'PROBLEMA', 'Versión de PHP: ' . PHP_VERSION, 'Elegí PHP 8.1 o superior en hPanel → Avanzado → Configuración de PHP.');
foreach (['pdo_mysql' => 'base de datos', 'mbstring' => 'textos', 'curl' => 'dólar, Telegram y Mercado Pago', 'openssl' => 'cifrado y backups', 'zlib' => 'backups comprimidos', 'sodium' => 'cifrado de credenciales (si falta se usa OpenSSL)'] as $ext => $para) {
    $falta = !extension_loaded($ext);
    linea(!$falta ? 'OK' : ($ext === 'sodium' ? 'AVISO' : 'PROBLEMA'), "Extensión $ext ($para)", "Activala en hPanel → Avanzado → Configuración de PHP → Extensiones.");
}
linea(in_array('aes-256-gcm', openssl_get_cipher_methods(), true) ? 'OK' : 'PROBLEMA', 'OpenSSL con AES-256-GCM');
// En consola display_errors puede diferir del de la web: se compara con lo que corresponde
linea(ini_get('expose_php') === '0' || ini_get('expose_php') === '' ? 'OK' : 'AVISO', 'expose_php = ' . var_export(ini_get('expose_php'), true) . ' (la versión de PHP no tiene que mostrarse en las cabeceras)', 'hPanel → Configuración de PHP → Opciones → expose_php = Off. (El panel ya quita la cabecera X-Powered-By.)');
linea(in_array(strtolower((string) ini_get('display_errors')), ['0', '', 'off', 'stderr'], true) ? 'OK' : 'AVISO', 'display_errors = ' . var_export(ini_get('display_errors'), true) . ' (los errores no se muestran al visitante)', 'hPanel → Configuración de PHP → Opciones → display_errors = Off. (El panel ya lo apaga, pero conviene también a nivel del servidor.)');
linea((int) ini_get('session.gc_maxlifetime') >= 1440 ? 'OK' : 'AVISO', 'session.gc_maxlifetime = ' . ini_get('session.gc_maxlifetime') . ' (el panel usa su propia carpeta de sesiones y su propio valor)');
$disabled = (string) ini_get('disable_functions');
linea(!str_contains($disabled, 'mail') ? 'OK' : 'AVISO', 'mail() de PHP ' . (str_contains($disabled, 'mail') ? 'desactivada por el hosting' : 'disponible'), 'Usá el método SMTP para los emails.');

echo "\n== Carpetas y permisos ==\n";
$perm = fn(string $ruta) => file_exists($ruta) ? substr(sprintf('%o', fileperms($ruta)), -3) : null;
foreach (['logs' => true, 'sesiones' => true, 'backups' => true] as $d => $_) {
    $ruta = RAIZ_PRIVADA . '/' . $d;
    if (!is_dir($ruta)) {
        linea('AVISO', "privado/$d no existe todavía (se crea sola al usarse)");
        continue;
    }
    linea(is_writable($ruta) ? 'OK' : 'PROBLEMA', "privado/$d es escribible (permisos " . $perm($ruta) . ')', 'Dale permiso de escritura (755 o 750; 700 si podés) desde el Administrador de archivos.');
    linea(is_file($ruta . '/.htaccess') || $d === 'logs' ? 'OK' : 'AVISO', "privado/$d con su .htaccess de bloqueo", 'Se crea sola; si falta, copiá privado/.htaccess adentro.');
}
$cfg = RAIZ_PRIVADA . '/config.php';
$p = $perm($cfg);
linea($p !== null && (octdec($p) & 0007) === 0 ? 'OK' : 'AVISO', "privado/config.php permisos $p (nadie más que tu usuario tendría que poder leerlo)", 'Administrador de archivos → clic derecho → Permisos → 600 (o 640 si el sitio deja de andar).');
$escribible = [];
foreach (['includes', 'acciones', 'vistas', 'paginas', 'cron', 'scripts', 'install'] as $d) {
    if (is_dir(RAIZ_PRIVADA . '/' . $d) && is_writable(RAIZ_PRIVADA . '/' . $d) && PHP_OS_FAMILY !== 'Windows') { $escribible[] = $d; }
}
linea(!$escribible ? 'OK' : 'AVISO', 'El código (includes, vistas, acciones…) ' . ($escribible ? 'es escribible por el usuario de PHP: ' . implode(', ', $escribible) : 'no es escribible por la web'), 'Es normal en hosting compartido (PHP corre con tu mismo usuario). Mantené 755 en carpetas y 644 en archivos; nunca 777.');
linea(!is_dir(RAIZ_PRIVADA . '/install') || !is_file(RAIZ_PRIVADA . '/install/crear_usuario_amoscato.sql') ? 'OK' : 'PROBLEMA', 'No hay SQL con hashes de contraseña en privado/install', 'Borrá privado/install/crear_usuario_amoscato.sql.');
linea(!is_file(RAIZ_PRIVADA . '/paginas/install.php') ? 'OK' : 'AVISO', is_file(RAIZ_PRIVADA . '/paginas/install.php') ? 'El instalador sigue en privado/paginas/install.php' : 'El instalador ya no está (se borró solo al instalar)', 'Borralo: aunque se niega a reinstalar, no tiene por qué estar.');

echo "\n== Configuración (sin mostrar valores) ==\n";
linea(cripto_disponible() ? 'OK' : 'PROBLEMA', 'Clave maestra de cifrado activa' . (cripto_disponible() ? ' (' . clave_activa_id() . ', ' . count(claves_maestras()) . ' clave(s) configurada(s))' : ''), 'Generala con: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;" y pegala en config.php → seguridad.');
linea(backup_clave() !== null ? 'OK' : 'AVISO', 'Clave de backup (seguridad.clave_backup)', 'Sin ella no hay backups. Generala igual que la maestra, pero DISTINTA.');
if (backup_clave() !== null && cripto_disponible() && hash_equals((string) backup_clave(), (string) claves_maestras()[clave_activa_id()])) {
    linea('PROBLEMA', 'La clave de backup es IGUAL a la maestra', 'Tienen que ser distintas: generá otra para clave_backup.');
}
$cron = (string) conf('app.cron_token', '');
linea($cron === '' || stripos($cron, 'CAMBIAR') === 0 ? 'OK' : (strlen($cron) >= 32 ? 'AVISO' : 'PROBLEMA'),
    $cron === '' || stripos($cron, 'CAMBIAR') === 0 ? 'Cron por URL desactivado (app.cron_token vacío o de ejemplo): bien, usá siempre la consola' : 'Cron por URL ACTIVADO (el token puede aparecer en logs): ' . (strlen($cron) >= 32 ? 'largo suficiente' : 'token de menos de 32 caracteres, la ruta web lo rechaza'),
    'Lo más seguro: dejá app.cron_token como "CAMBIAR…" y programá los crons por consola en hPanel.');
$url = (string) conf('app.url', '');
linea(str_starts_with($url, 'https://') ? 'OK' : 'PROBLEMA', 'app.url es https (' . ($url ?: 'vacía') . ')', 'Poné la dirección completa con https:// en config.php → app → url.');
linea(is_array(conf('app.proxies_confiables', [])) ? 'OK' : 'PROBLEMA', 'app.proxies_confiables (para la IP real detrás de un proxy): ' . (conf('app.proxies_confiables') ? 'configurado' : 'sin configurar (se usa REMOTE_ADDR)'));
try {
    $modo = (string) db()->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    linea(str_contains($modo, 'STRICT_ALL_TABLES') ? 'OK' : 'PROBLEMA', 'La base trabaja en modo estricto (el panel lo fija en cada conexión)');
    $v = (string) db()->query('SELECT VERSION()')->fetchColumn();
    linea('OK', "Base de datos: $v");
    $hay = (int) db()->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1")->fetchColumn();
    linea($hay >= 1 ? 'OK' : 'PROBLEMA', "Administradores activos: $hay");
    $sin2fa = db()->query("SELECT usuario FROM usuarios WHERE rol = 'admin' AND activo = 1 AND totp_activo = 0")->fetchAll(PDO::FETCH_COLUMN);
    linea(!$sin2fa ? 'OK' : 'AVISO', 'Administradores con verificación en dos pasos' . ($sin2fa ? ' — sin activarla: ' . implode(', ', $sin2fa) : ': todos'), 'Mi cuenta → Verificación en dos pasos.');
} catch (Throwable $ex) {
    linea('PROBLEMA', 'No se pudo consultar la base: ' . get_class($ex));
}
$bk = backup_listar();
linea($bk && time() - $bk[0]['fecha'] < 3 * 86400 ? 'OK' : 'AVISO', $bk ? 'Último backup: ' . date('d/m/Y H:i', $bk[0]['fecha']) : 'Todavía no hay backups', 'Programá el Cron Job diario de backup (README → Cron Jobs).');

if (in_array('--web', $argv ?? [], true)) {
    foreach ($argv as $a) {
        if (str_starts_with($a, '--url=')) {
            $url = rtrim(substr($a, 6), '/');
        }
    }
    echo "\n== Desde afuera (" . $url . ") ==\n";
    if (!preg_match('~^https?://~', $url) || !function_exists('curl_init')) {
        linea('AVISO', 'Se omite: hace falta app.url (o --url=) y la extensión curl.');
    } else {
        $pedir = function (string $ruta, bool $seguir = false, string $base = '') use ($url) {
            $ch = curl_init(($base ?: $url) . $ruta);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => $seguir, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'Moscode-verificador']);
            $r = (string) curl_exec($ch);
            $cod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            return [$cod, substr($r, 0, $hs), substr($r, $hs)];
        };
        foreach (['/privado/config.php', '/privado/logs/php-error.log', '/privado/install/install.sql', '/privado/sesiones/', '/privado/backups/', '/config.php', '/includes/db.php', '/paginas/login.php', '/scripts/crear_usuario.php', '/.htaccess'] as $ruta) {
            [$cod, , $cuerpo] = $pedir($ruta);
            $expuesto = $cod === 200 && !str_contains($cuerpo, 'No encontramos esa página') && strlen($cuerpo) > 0 && !str_contains($cuerpo, '<html');
            linea($expuesto ? 'PROBLEMA' : 'OK', "$ruta responde $cod" . ($expuesto ? ' y MUESTRA CONTENIDO' : ''), 'La carpeta privado/ tiene que estar FUERA de public_html o protegida por su .htaccess. Revisá el README → "Dónde va cada carpeta".');
        }
        [$cod, $h] = $pedir('/login.php');
        linea($cod === 301 && stripos($h, 'Location:') !== false ? 'OK' : 'AVISO', "/login.php redirige a /login (código $cod)", 'Si no redirige, el .htaccess de public_html no se está aplicando: revisá que el archivo esté subido (es un archivo oculto) y que el hosting use LiteSpeed/Apache.');
        [$cod, $h] = $pedir('/index.php?p=clientes');
        linea($cod === 301 ? 'OK' : 'AVISO', "/index.php?p=clientes redirige a /clientes (código $cod)");
        $http = 'http://' . preg_replace('~^https?://~', '', $url);
        [$cod, $h] = $pedir('/login', false, $http);
        linea(in_array($cod, [301, 302, 308], true) && stripos($h, 'Location: https://') !== false ? 'OK' : 'PROBLEMA', "http:// redirige a https:// (código $cod)", 'hPanel → Sitios web → Seguridad → activar "Forzar HTTPS", y revisá el .htaccess.');
        [$cod, $h, $cuerpo] = $pedir('/login');
        $mapa = ['content-security-policy' => "style-src 'self'", 'strict-transport-security' => 'max-age', 'x-content-type-options' => 'nosniff', 'x-frame-options' => 'DENY', 'referrer-policy' => '', 'permissions-policy' => '', 'cache-control' => 'no-store'];
        foreach ($mapa as $cab => $valor) {
            $ok = (bool) preg_match('/^' . preg_quote($cab, '/') . ':\s*(.*)$/mi', $h, $m) && ($valor === '' || str_contains($m[1], $valor));
            linea($ok ? 'OK' : 'PROBLEMA', "Cabecera $cab", $cab === 'strict-transport-security' ? 'Si falta solo HSTS, el hosting no informa que la conexión es HTTPS (X-Forwarded-Proto): consultá a soporte de Hostinger.' : 'Revisá que public_html/.htaccess y public_html/index.php estén subidos completos.');
        }
        linea(!preg_match('/^x-powered-by:/mi', $h) ? 'OK' : 'AVISO', 'No se expone X-Powered-By', 'hPanel → Configuración de PHP → expose_php = Off.');
        linea(!preg_match('/^server:\s*\S*\d+\.\d+/mi', $h) ? 'OK' : 'AVISO', 'La cabecera Server no muestra versión', 'No se puede cambiar desde el panel: es del servidor del hosting.');
        [$cod] = $pedir('/webhook/mp/' . str_repeat('a', 32));
        linea($cod === 403 ? 'OK' : 'AVISO', "Un token de webhook inventado da 403 (código $cod)");
        [$cod, , $cuerpo] = $pedir('/cron/avisos_vencimientos');
        linea($cod === 403 ? 'OK' : 'AVISO', "El cron por web sin token da 403 (código $cod)");
        [$cod, , $cuerpo] = $pedir('/install');
        linea(in_array($cod, [403, 404], true) ? 'OK' : 'PROBLEMA', "/install no está disponible (código $cod)", 'Borrá privado/paginas/install.php.');
    }
}
echo "\nResumen: $problemas problema(s), $avisos aviso(s).\n";
exit($problemas > 0 ? 1 : 0);
