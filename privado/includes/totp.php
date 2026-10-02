<?php
/**
 * totp.php — Verificación en dos pasos (TOTP, RFC 6238) compatible con Google Authenticator, Authy, Microsoft
 * Authenticator, 1Password, etc. (SHA-1, 6 dígitos, 30 segundos).
 *
 * - El secreto se guarda CIFRADO (cripto.php, ligado al usuario) en usuarios.totp_secreto.
 * - Anti-repetición: un código ya usado (o uno de un paso anterior) no vale otra vez (totp_ultimo_paso).
 * - Códigos de recuperación de un solo uso, guardados como hash SHA-256 (son aleatorios de 50 bits).
 * - El QR se dibuja en el navegador con una librería local (assets/vendor/qrcode): el secreto no sale del panel.
 */
declare(strict_types=1);

const TOTP_PERIODO = 30;
const TOTP_DIGITOS = 6;
const TOTP_VENTANA = 1;                   // se aceptan el paso anterior y el siguiente (desfase de reloj)
const TOTP_CODIGOS_RECUPERACION = 10;
const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_codificar(string $bin): string
{
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $trozo) {
        $out .= BASE32[bindec(str_pad($trozo, 5, '0'))];
    }
    return $out;
}

function base32_decodificar(string $b32): ?string
{
    $b32 = strtoupper(str_replace([' ', '-', '='], '', $b32));
    if ($b32 === '' || !preg_match('/^[A-Z2-7]+$/', $b32)) {
        return null;
    }
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(decbin((int) strpos(BASE32, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

/** Secreto nuevo: 20 bytes aleatorios en base32 (32 caracteres). */
function totp_generar_secreto(): string
{
    return base32_codificar(random_bytes(20));
}

/** Código de 6 dígitos para un paso de tiempo (HOTP con el contador = paso). */
function totp_codigo(string $secretoB32, int $paso): string
{
    $clave = base32_decodificar($secretoB32) ?? '';
    $hmac = hash_hmac('sha1', pack('N*', 0, $paso), $clave, true);
    $off = ord($hmac[19]) & 0x0F;
    $n = ((ord($hmac[$off]) & 0x7F) << 24) | (ord($hmac[$off + 1]) << 16) | (ord($hmac[$off + 2]) << 8) | ord($hmac[$off + 3]);
    return str_pad((string) ($n % (10 ** TOTP_DIGITOS)), TOTP_DIGITOS, '0', STR_PAD_LEFT);
}

/**
 * Verifica un código contra el secreto. Devuelve el paso de tiempo que coincidió (para guardarlo y no aceptarlo de
 * nuevo) o null. Solo acepta pasos mayores que $ultimoPaso. Comparación en tiempo constante.
 */
function totp_verificar(string $secretoB32, string $codigo, int $ultimoPaso = 0, ?int $ahora = null): ?int
{
    $codigo = preg_replace('/\s+/', '', $codigo) ?? '';
    if (!preg_match('/^\d{' . TOTP_DIGITOS . '}$/', $codigo)) {
        return null;
    }
    $actual = intdiv($ahora ?? time(), TOTP_PERIODO);
    $encontrado = null;
    for ($d = -TOTP_VENTANA; $d <= TOTP_VENTANA; $d++) {
        $paso = $actual + $d;
        if (hash_equals(totp_codigo($secretoB32, $paso), $codigo) && $paso > $ultimoPaso) {
            $encontrado = $paso;      // no se corta el ciclo: tiempo parejo
        }
    }
    return $encontrado;
}

/** URI otpauth:// para el QR y la carga manual. */
function totp_uri(string $cuenta, string $secretoB32): string
{
    $emisor = 'Moscode';
    return 'otpauth://totp/' . rawurlencode($emisor . ':' . $cuenta) . '?secret=' . $secretoB32 . '&issuer=' . rawurlencode($emisor)
        . '&algorithm=SHA1&digits=' . TOTP_DIGITOS . '&period=' . TOTP_PERIODO;
}

// ---------------------------------------------------------------------------------------------------------
// Estado del usuario
// ---------------------------------------------------------------------------------------------------------

function totp_activo(int $uid): bool
{
    return (int) valor('SELECT totp_activo FROM usuarios WHERE id = ?', [$uid]) === 1;
}

/** Secreto descifrado del usuario (o null). */
function totp_secreto_usuario(int $uid): ?string
{
    $enc = valor('SELECT totp_secreto FROM usuarios WHERE id = ?', [$uid]);
    return $enc ? descifrar((string) $enc, "totp|$uid") : null;
}

/** Verifica el código TOTP de un usuario con anti-repetición atómica. */
function totp_validar_usuario(int $uid, string $codigo): bool
{
    $secreto = totp_secreto_usuario($uid);
    if ($secreto === null) {
        return false;
    }
    $ultimo = (int) valor('SELECT totp_ultimo_paso FROM usuarios WHERE id = ?', [$uid]);
    $paso = totp_verificar($secreto, $codigo, $ultimo);
    if ($paso === null) {
        return false;
    }
    // UPDATE condicional: dos pedidos con el mismo código no pasan los dos
    return q('UPDATE usuarios SET totp_ultimo_paso = ? WHERE id = ? AND totp_ultimo_paso < ?', [$paso, $uid, $paso])->rowCount() === 1;
}

/** Activa el 2FA si el código de confirmación es correcto. Devuelve los códigos de recuperación (en claro, una sola vez) o null. */
function totp_activar(int $uid, string $secretoB32, string $codigo): ?array
{
    $paso = totp_verificar($secretoB32, $codigo, 0);
    if ($paso === null) {
        return null;
    }
    q('UPDATE usuarios SET totp_secreto = ?, totp_activo = 1, totp_ultimo_paso = ? WHERE id = ?', [cifrar($secretoB32, "totp|$uid"), $paso, $uid]);
    return con_usuario($uid, fn() => totp_generar_codigos($uid));
}

/** Apaga el 2FA y borra el secreto y los códigos de recuperación. */
function totp_desactivar(int $uid): void
{
    q('UPDATE usuarios SET totp_secreto = NULL, totp_activo = 0, totp_ultimo_paso = 0 WHERE id = ?', [$uid]);
    sin_filtro('2FA: borrar los códigos de recuperación de la cuenta', fn() => q('DELETE FROM totp_recuperacion WHERE usuario_id = ?', [$uid]));
}

// ---------------------------------------------------------------------------------------------------------
// Códigos de recuperación
// ---------------------------------------------------------------------------------------------------------

/** Normaliza lo que escribe la persona: mayúsculas y sin guiones ni espacios. */
function codigo_recuperacion_normalizar(string $c): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c) ?? '');
}

/** Genera un juego nuevo (reemplaza el anterior). Corre en el contexto del usuario. Devuelve los códigos en claro. */
function totp_generar_codigos(int $uid): array
{
    q('DELETE FROM totp_recuperacion WHERE usuario_id = {U}');
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $codigos = [];
    for ($i = 0; $i < TOTP_CODIGOS_RECUPERACION; $i++) {
        $c = '';
        for ($j = 0; $j < 10; $j++) {
            $c .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        $codigos[] = substr($c, 0, 5) . '-' . substr($c, 5);
        q('INSERT INTO totp_recuperacion (usuario_id, codigo_hash, creado_en) VALUES ({U}, ?, NOW())', [hash('sha256', $c)]);
    }
    return $codigos;
}

/** Usa un código de recuperación (de un solo uso). Corre en el contexto del usuario. */
function totp_usar_recuperacion(string $codigo): bool
{
    $n = codigo_recuperacion_normalizar($codigo);
    if (strlen($n) !== 10) {
        return false;
    }
    $hash = hash('sha256', $n);
    $f = fila('SELECT id, codigo_hash FROM totp_recuperacion WHERE usuario_id = {U} AND codigo_hash = ? AND usado_en IS NULL', [$hash]);
    if (!$f || !hash_equals((string) $f['codigo_hash'], $hash)) {
        return false;
    }
    return q('UPDATE totp_recuperacion SET usado_en = NOW() WHERE id = ? AND usuario_id = {U} AND usado_en IS NULL', [$f['id']])->rowCount() === 1;
}

function totp_codigos_restantes(): int
{
    return (int) valor('SELECT COUNT(*) FROM totp_recuperacion WHERE usuario_id = {U} AND usado_en IS NULL');
}
