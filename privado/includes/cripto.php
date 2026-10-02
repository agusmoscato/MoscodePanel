<?php
/**
 * cripto.php — Cifrado de las credenciales sensibles de cada usuario (SMTP, Telegram, Mercado Pago, secreto del 2FA).
 *
 * Formato "s2:<id de clave>:<base64>" (libsodium, XChaCha20-Poly1305) u "o2:<id de clave>:<base64>" (AES-256-GCM de
 * OpenSSL, si el hosting no tiene sodium). Los dos son cifrados autenticados y llevan un CONTEXTO ("usuario|clave")
 * ligado al dato: copiar el valor cifrado de un usuario a otro, o de una clave a otra, hace fallar el descifrado.
 * También se leen los formatos viejos "s1:" y "o1:" (sin contexto) hasta que se recifren con el script de rotación.
 *
 * Claves maestras (32 bytes en base64) en config.php → seguridad:
 *   'claves'        => ['k1' => '<base64>', 'k2' => '<base64>'],   // todas las que haya que poder LEER
 *   'clave_activa'  => 'k2',                                       // la que se usa para CIFRAR
 * (La forma vieja, 'clave_maestra' => '<base64>', sigue valiendo y es la clave "k1".)
 * Para rotar: agregá la clave nueva, ponela como activa y corré scripts/rotar_clave_maestra.php; cuando termine,
 * borrá la vieja. Nunca se muestra ni se registra una clave en ningún lado.
 *
 * Generar una clave:  php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
 */
declare(strict_types=1);

/** Claves maestras configuradas: id => 32 bytes. */
function claves_maestras(): array
{
    static $claves = null;
    if ($claves === null) {
        $claves = [];
        $lista = conf('seguridad.claves', []);
        if (is_array($lista)) {
            foreach ($lista as $id => $b64) {
                $raw = is_string($b64) ? base64_decode($b64, true) : false;
                if (preg_match('/^[A-Za-z0-9_-]{1,20}$/', (string) $id) && $raw !== false && strlen($raw) === 32) {
                    $claves[(string) $id] = $raw;
                }
            }
        }
        $vieja = (string) conf('seguridad.clave_maestra', '');
        if ($vieja !== '' && !isset($claves['k1'])) {
            $raw = base64_decode($vieja, true);
            if ($raw !== false && strlen($raw) === 32) {
                $claves['k1'] = $raw;
            }
        }
    }
    return $claves;
}

/** Id de la clave con la que se cifra (o null si no hay ninguna válida). */
function clave_activa_id(): ?string
{
    $claves = claves_maestras();
    $id = (string) conf('seguridad.clave_activa', '');
    if ($id !== '') {
        return isset($claves[$id]) ? $id : null;
    }
    return count($claves) === 1 ? (string) array_key_first($claves) : null;      // con varias claves hay que elegir la activa
}

function cripto_disponible(): bool
{
    return clave_activa_id() !== null;
}

/** Cifra un texto ligado a un contexto. Lanza RuntimeException si no hay clave activa. */
function cifrar(string $texto, string $contexto = ''): string
{
    $kid = clave_activa_id();
    if ($kid === null) {
        throw new RuntimeException('Falta la clave maestra activa (seguridad.clave_activa / claves) en config.php: no se pueden guardar credenciales.');
    }
    $k = claves_maestras()[$kid];
    if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return "s2:$kid:" . base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($texto, $contexto, $nonce, $k));
    }
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($texto, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag, $contexto);
    if ($c === false) {
        throw new RuntimeException('No se pudo cifrar (OpenSSL).');
    }
    return "o2:$kid:" . base64_encode($iv . $tag . $c);
}

/** Datos del formato de un valor cifrado (para el script de rotación): ['formato' => 's2', 'kid' => 'k1'] o null. */
function cifrado_info(string $enc): ?array
{
    if (preg_match('/^(s2|o2):([A-Za-z0-9_-]{1,20}):/', $enc, $m)) {
        return ['formato' => $m[1], 'kid' => $m[2]];
    }
    if (preg_match('/^(s1|o1):/', $enc, $m)) {
        return ['formato' => $m[1], 'kid' => null];
    }
    return null;
}

/** Descifra; devuelve null si el dato está dañado, el contexto no coincide o falta la clave. */
function descifrar(string $enc, string $contexto = ''): ?string
{
    $claves = claves_maestras();
    if (!$claves || strlen($enc) < 6) {
        return null;
    }
    if (preg_match('/^(s2|o2):([A-Za-z0-9_-]{1,20}):(.*)$/s', $enc, $m)) {
        $k = $claves[$m[2]] ?? null;
        $raw = base64_decode($m[3], true);
        return ($k !== null && $raw !== false) ? descifrar_raw($m[1], $raw, $k, $contexto) : null;
    }
    if (preg_match('/^(s1|o1):(.*)$/s', $enc, $m)) {      // formato viejo: sin contexto, se prueba con todas las claves
        $raw = base64_decode($m[2], true);
        if ($raw === false) {
            return null;
        }
        foreach ($claves as $k) {
            $r = descifrar_raw($m[1], $raw, $k, null);
            if ($r !== null) {
                return $r;
            }
        }
    }
    return null;
}

function descifrar_raw(string $formato, string $raw, string $k, ?string $contexto): ?string
{
    if ($formato === 's2' && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
        $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($raw) <= $n) {
            return null;
        }
        $r = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $n), (string) $contexto, substr($raw, 0, $n), $k);
        return $r === false ? null : $r;
    }
    if ($formato === 'o2') {
        $r = strlen($raw) > 28 ? openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), (string) $contexto) : false;
        return $r === false ? null : $r;
    }
    if ($formato === 's1' && function_exists('sodium_crypto_secretbox_open')) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($raw) <= $n) {
            return null;
        }
        $r = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $k);
        return $r === false ? null : $r;
    }
    if ($formato === 'o1') {
        $r = strlen($raw) > 28 ? openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)) : false;
        return $r === false ? null : $r;
    }
    return null;
}
