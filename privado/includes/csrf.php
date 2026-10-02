<?php
/**
 * csrf.php — Protección CSRF: un token por sesión que viaja en cada formulario POST.
 */
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Campo oculto para pegar dentro de cada <form method="post">. */
function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Verifica el token del POST; corta con 403 si no coincide. */
function csrf_verificar(): void
{
    $enviado = (string) ($_POST['csrf'] ?? '');
    if ($enviado === '' || !hash_equals(csrf_token(), $enviado)) {
        http_response_code(403);
        exit('Token de seguridad inválido. Volvé atrás, recargá la página e intentá de nuevo.');
    }
}
