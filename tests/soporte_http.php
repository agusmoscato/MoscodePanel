<?php
/**
 * soporte_http.php — Pruebas de punta a punta por HTTP: la app real servida por el servidor embebido de PHP
 * (php -S) contra la misma base de prueba, y un "navegador" con cookies (cURL) para recorrerla como lo haría
 * una persona: login, cookies de sesión y de "recordarme", 2FA, formularios con CSRF y nonce, cabeceras.
 *
 * El servidor se levanta solo la primera vez que un caso pide servidor_http() y se apaga al terminar la corrida.
 * Sirve una COPIA de privado/ y public_html/ en tests/.entorno/http/ (rehecha en cada corrida, con el
 * config.php de prueba): la carpeta real del proyecto y su privado/config.php no se tocan.
 *
 * Puerto: MOSCODE_TEST_HTTP_PORT (8099 por defecto).
 */
declare(strict_types=1);

/** Copia $origen en $destino (recursivo), salteando los nombres de $excluir en el primer nivel. */
function copiar_carpeta(string $origen, string $destino, array $excluir = []): void
{
    if (!is_dir($destino) && !mkdir($destino, 0777, true) && !is_dir($destino)) {
        throw new RuntimeException("No se pudo crear $destino");
    }
    foreach (scandir($origen) ?: [] as $nombre) {
        if ($nombre === '.' || $nombre === '..' || in_array($nombre, $excluir, true)) {
            continue;
        }
        $o = $origen . '/' . $nombre;
        $d = $destino . '/' . $nombre;
        is_dir($o) ? copiar_carpeta($o, $d) : copy($o, $d);
    }
}

function borrar_carpeta(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $nombre) {
        if ($nombre !== '.' && $nombre !== '..') {
            $r = $dir . '/' . $nombre;
            is_dir($r) && !is_link($r) ? borrar_carpeta($r) : @unlink($r);
        }
    }
    @rmdir($dir);
}

/** URL base del servidor de prueba (lo levanta si todavía no está corriendo). */
function servidor_http(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $puerto = (int) env_o_defecto('MOSCODE_TEST_HTTP_PORT', '8099');
    $raiz = __DIR__ . '/.entorno/http';
    borrar_carpeta($raiz);
    copiar_carpeta(RAIZ_PROYECTO . '/privado', $raiz . '/privado', ['config.php', 'logs', 'sesiones', 'backups']);
    copiar_carpeta(RAIZ_PROYECTO . '/public_html', $raiz . '/public_html');
    copy(RAIZ_PRIVADA . '/config.php', $raiz . '/privado/config.php');   // el mismo de la corrida (misma clave maestra)

    if (@fsockopen('127.0.0.1', $puerto, $en, $es, 0.5)) {
        throw new RuntimeException("El puerto $puerto ya está en uso: fijá MOSCODE_TEST_HTTP_PORT con otro libre.");
    }
    $log = $raiz . '/servidor.log';
    $proc = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:$puerto", '-t', $raiz . '/public_html', __DIR__ . '/router_http.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('No se pudo arrancar el servidor embebido de PHP.');
    }
    register_shutdown_function(function () use ($proc) {
        proc_terminate($proc);
    });
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $puerto, $en, $es, 0.2)) {
            $base = "http://127.0.0.1:$puerto";
            echo "  (servidor HTTP de prueba en $base)\n";
            return $base;
        }
        usleep(100000);
    }
    throw new RuntimeException("El servidor embebido no respondió en el puerto $puerto. Revisá $log");
}

/** Últimas líneas del log de errores de la app servida (para entender un 500 en una prueba). */
function log_app_http(int $lineas = 15): string
{
    $f = __DIR__ . '/.entorno/http/privado/logs/php-error.log';
    if (!is_file($f)) {
        return '(sin log de errores)';
    }
    return implode("\n", array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], -$lineas));
}

/**
 * Un navegador: guarda sus cookies entre pedidos (motor de cookies de cURL en memoria) y NO sigue redirecciones
 * solo, para poder verificar a dónde manda cada respuesta. Cada instancia es un dispositivo distinto.
 */
final class Navegador
{
    private $ch;
    public string $ultimoCsrf = '';
    public string $ultimoNonce = '';

    public function __construct(private string $ua = 'PruebasMoscode/1.0 (Windows NT 10.0)')
    {
        $this->ch = curl_init();
        curl_setopt($this->ch, CURLOPT_COOKIEFILE, '');     // activa el motor de cookies (en memoria)
    }

    /**
     * Hace un pedido y devuelve ['codigo', 'cab' => [nombre en minúsculas => [valores]], 'cuerpo', 'location'].
     * Si la página trae un csrf / nonce, quedan en $ultimoCsrf / $ultimoNonce para el formulario siguiente.
     */
    public function pedir(string $metodo, string $ruta, ?array $post = null, array $cabeceras = [], ?string $cuerpoCrudo = null): array
    {
        $cab = [];
        $cuerpoEnviar = $cuerpoCrudo ?? ($post !== null ? http_build_query($post) : null);
        if ($cuerpoEnviar !== null) {
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $cuerpoEnviar);   // implica POST
        } else {
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);               // vuelve a GET si el pedido anterior fue un POST
        }
        curl_setopt_array($this->ch, [
            CURLOPT_URL => servidor_http() . $ruta,
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => $this->ua,
            CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_HEADERFUNCTION => function ($ch, $linea) use (&$cab) {
                $partes = explode(':', $linea, 2);
                if (count($partes) === 2) {
                    $cab[strtolower(trim($partes[0]))][] = trim($partes[1]);
                }
                return strlen($linea);
            },
        ]);
        $cuerpo = curl_exec($this->ch);
        if ($cuerpo === false) {
            throw new RuntimeException("$metodo $ruta: " . curl_error($this->ch));
        }
        $codigo = (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
        if ($codigo >= 500) {
            echo "          (HTTP $codigo en $metodo $ruta — log de la app:)\n" . log_app_http() . "\n";
        }
        if (preg_match('/name="csrf" value="([0-9a-f]{64})"/', (string) $cuerpo, $m)) {
            $this->ultimoCsrf = $m[1];
        }
        if (preg_match('/name="nonce" value="([0-9a-f]{32})"/', (string) $cuerpo, $m)) {
            $this->ultimoNonce = $m[1];
        }
        return ['codigo' => $codigo, 'cab' => $cab, 'cuerpo' => (string) $cuerpo, 'location' => $cab['location'][0] ?? ''];
    }

    public function get(string $ruta, array $cabeceras = []): array
    {
        return $this->pedir('GET', $ruta, null, $cabeceras);
    }

    /** POST de formulario. Agrega solo el csrf (y el nonce) de la última página vista, si no vienen en $datos. */
    public function post(string $ruta, array $datos = [], array $cabeceras = []): array
    {
        $datos += ['csrf' => $this->ultimoCsrf];
        if ($this->ultimoNonce !== '') {
            $datos += ['nonce' => $this->ultimoNonce];
        }
        return $this->pedir('POST', $ruta, $datos, $cabeceras);
    }

    /** Abre /login y manda usuario y clave. Devuelve la respuesta del POST. */
    public function login(string $usuario, string $clave, bool $recordar = false): array
    {
        $this->get('/login');
        return $this->post('/login', ['usuario' => $usuario, 'clave' => $clave] + ($recordar ? ['recordar' => '1'] : []));
    }

    /** Cookies actuales: nombre => valor. */
    public function cookies(): array
    {
        $res = [];
        foreach (curl_getinfo($this->ch, CURLINFO_COOKIELIST) ?: [] as $linea) {
            $c = explode("\t", $linea);
            if (count($c) >= 7) {
                $res[$c[5]] = urldecode($c[6]);   // setcookie() codifica el valor (los ":" llegan como %3A)
            }
        }
        return $res;
    }

    /** Pone (o pisa) una cookie a mano, como si el navegador la tuviera guardada. */
    public function ponerCookie(string $nombre, string $valor): void
    {
        // Formato Netscape (dominio, subdominios, ruta, segura, vence, nombre, valor): pisa la que tenga el mismo nombre
        $host = parse_url(servidor_http(), PHP_URL_HOST);
        curl_setopt($this->ch, CURLOPT_COOKIELIST, "$host\tFALSE\t/\tFALSE\t" . (time() + 86400) . "\t$nombre\t" . rawurlencode($valor));
    }

    /** Simula cerrar el navegador: se borran las cookies de sesión (las que no tienen vencimiento). */
    public function cerrarNavegador(): void
    {
        curl_setopt($this->ch, CURLOPT_COOKIELIST, 'SESS');
    }
}

/** Usuario con contraseña conocida (para entrar por /login). Deja el contexto fijado en él, como nuevo_usuario_de_prueba(). */
function nuevo_usuario_con_clave(string $prefijo, string $clave, string $rol = 'usuario', array $extra = []): array
{
    static $n = 0;
    $n++;
    $usuario = $prefijo . $n . bin2hex(random_bytes(2));
    $id = insertar('usuarios', $extra + [
        'usuario' => $usuario, 'password_hash' => password_hash($clave, PASSWORD_DEFAULT),
        'nombre' => $prefijo, 'email' => $usuario . '@pruebas.test', 'rol' => $rol, 'activo' => 1,
        'creado_en' => date('Y-m-d H:i:s'),
    ]);
    fijar_usuario($id);
    return ['id' => $id, 'usuario' => $usuario, 'clave' => $clave];
}

/** ¿La respuesta es una redirección a $ruta (sin importar la query)? */
function redirige_a(array $r, string $ruta): bool
{
    return in_array($r['codigo'], [301, 302, 303], true) && parse_url($r['location'], PHP_URL_PATH) === $ruta;
}
