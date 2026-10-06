<?php
/**
 * http_actividad.php — Quién ve qué del registro de actividad de seguridad, por HTTP.
 *   - Cada usuario ve el de SU cuenta en Mi cuenta → "Actividad de mi cuenta" (todo lo suyo, incluidos los eventos con
 *     datos, como anular un pago) y nunca el de otra cuenta.
 *   - El administrador ve el de TODAS las cuentas en /actividad (Registro de actividad), pero no los eventos con datos
 *     de clientes (visibilidad 'datos').
 *   - Un usuario común no entra a /actividad (403) ni lo tiene en el menú.
 */
declare(strict_types=1);

seccion('cuentas con actividad propia');
$MARCA = 'ACT' . bin2hex(random_bytes(3));
$admin = nuevo_usuario_con_clave('act_admin', 'Clave-Actividad-2026', 'admin');
$ana = nuevo_usuario_con_clave('act_ana', 'Clave-Actividad-2026');
$beto = nuevo_usuario_con_clave('act_beto', 'Clave-Actividad-2026');
registrar_actividad('clave_cambiada', "$MARCA clave de ana", $ana['id']);
registrar_actividad('dos_pasos_activado', "$MARCA 2fa de ana", $ana['id']);
registrar_actividad('pago_anulado', "$MARCA pago de ana anulado", $ana['id'], null, 'datos');
registrar_actividad('clave_cambiada', "$MARCA clave de beto", $beto['id']);
registrar_actividad('clave_reseteada', "$MARCA reseteo de beto por el admin", $beto['id'], $admin['id']);

$navAna = new Navegador();
$navAna->login($ana['usuario'], $ana['clave']);
$navBeto = new Navegador();
$navBeto->login($beto['usuario'], $beto['clave']);
$navAdmin = new Navegador();
$navAdmin->login($admin['usuario'], $admin['clave']);

seccion('cada usuario ve la actividad de su cuenta en Mi cuenta');
$miAna = $navAna->get('/mi-cuenta')['cuerpo'];
verificar_contiene('Mi cuenta tiene la sección "Actividad de mi cuenta"', 'Actividad de mi cuenta', $miAna);
verificar_contiene('ana: su ingreso (el login de esta prueba)', 'Ingreso correcto', $miAna);
verificar_contiene('ana: su cambio de contraseña', "$MARCA clave de ana", $miAna);
verificar_contiene('ana: su 2FA', "$MARCA 2fa de ana", $miAna);
verificar_contiene('ana: también sus eventos con datos (anular un pago)', "$MARCA pago de ana anulado", $miAna);
verificar('ana: nada de la cuenta de beto', false, str_contains($miAna, "$MARCA clave de beto") || str_contains($miAna, "$MARCA reseteo de beto"));
$miBeto = $navBeto->get('/mi-cuenta')['cuerpo'];
verificar_contiene('beto: su cambio de contraseña', "$MARCA clave de beto", $miBeto);
verificar_contiene('beto: el reseteo, con quién lo hizo', 'por @' . $admin['usuario'] . ' (administrador)', $miBeto);
verificar('beto: nada de la cuenta de ana', false, str_contains($miBeto, "$MARCA clave de ana") || str_contains($miBeto, "$MARCA pago de ana"));

seccion('el admin ve la de todas las cuentas en /actividad (sin eventos con datos de clientes)');
$regAdmin = $navAdmin->get('/actividad');
verificar('admin: /actividad responde 200', 200, $regAdmin['codigo']);
verificar_contiene('admin: el cambio de contraseña de ana', "$MARCA clave de ana", $regAdmin['cuerpo']);
verificar_contiene('admin: el 2FA de ana', "$MARCA 2fa de ana", $regAdmin['cuerpo']);
verificar_contiene('admin: el cambio de beto', "$MARCA clave de beto", $regAdmin['cuerpo']);
verificar_contiene('admin: con la cuenta de cada evento', '<span class="suave mono">@' . $ana['usuario'] . '</span>', $regAdmin['cuerpo']);
verificar('admin: NO ve el pago anulado de ana (es un dato de clientes)', false, str_contains($regAdmin['cuerpo'], "$MARCA pago de ana"));
$soloBeto = $navAdmin->get('/actividad?cuenta=' . $beto['id'])['cuerpo'];
verificar('admin: filtrando por beto, solo lo de beto', [true, false], [str_contains($soloBeto, "$MARCA clave de beto"), str_contains($soloBeto, "$MARCA clave de ana")]);
verificar_contiene('admin: "Registro de actividad" en el menú', 'href="/actividad" title="Registro de actividad"', $regAdmin['cuerpo']);
verificar_contiene('admin: y también su propia actividad en Mi cuenta', 'Actividad de mi cuenta', $navAdmin->get('/mi-cuenta')['cuerpo']);

seccion('un usuario común no entra al registro de todas las cuentas');
$regAna = $navAna->get('/actividad');
verificar('ana: /actividad da 403', 403, $regAna['codigo']);
verificar('ana: y no muestra nada de otras cuentas', false, str_contains($regAna['cuerpo'], $MARCA));
verificar('ana: no tiene "Registro de actividad" en el menú', false, str_contains($miAna, 'href="/actividad"'));
