<?php
/**
 * http_2fa.php — Verificación en dos pasos (TOTP) de punta a punta por HTTP: activarla desde la pantalla (con el
 * secreto que muestra), entrar con código, que un código no se pueda reusar, códigos de recuperación de un solo uso,
 * corte a los 5 códigos incorrectos, y desactivarla.
 *
 * Los códigos se calculan acá con totp_codigo() (el mismo algoritmo que una app de autenticación). La app acepta el
 * paso anterior y el siguiente, y nunca uno ya usado: por eso cada ingreso usa un paso más que el anterior.
 */
declare(strict_types=1);

$u = nuevo_usuario_con_clave('dospasos', 'Clave-DosPasos-2026');
$nav = new Navegador();
$nav->login($u['usuario'], $u['clave']);

seccion('activar: la pantalla muestra el secreto, y con la clave y un código válido queda activa');
$pant = $nav->get('/mi-cuenta/dos-pasos');
verificar('la pantalla de dos pasos responde 200', 200, $pant['codigo']);
preg_match('/secreto-2fa" data-seleccionar>([A-Z2-7 ]+)</', $pant['cuerpo'], $m);
$secreto = str_replace(' ', '', $m[1] ?? '');
verificar_cierto('se ve el secreto en base32 para cargar en la app', strlen($secreto) === 32);
$paso = intdiv(time(), TOTP_PERIODO);

$r = $nav->post('/acciones/dos_pasos_activar', ['clave_actual' => $u['clave'], 'codigo' => totp_codigo($secreto, $paso + 10)]);
fijar_usuario($u['id']);
verificar('con un código incorrecto NO se activa', 0, (int) valor('SELECT totp_activo FROM usuarios WHERE id = ?', [$u['id']]));
$nav->get('/mi-cuenta/dos-pasos');
$nav->post('/acciones/dos_pasos_activar', ['clave_actual' => $u['clave'], 'codigo' => totp_codigo($secreto, $paso)]);
verificar('con la clave y un código correcto se activa', 1, (int) valor('SELECT totp_activo FROM usuarios WHERE id = ?', [$u['id']]));
verificar_cierto('el secreto queda cifrado en la base (no en claro)', !str_contains((string) valor('SELECT totp_secreto FROM usuarios WHERE id = ?', [$u['id']]), $secreto));
$conCodigos = $nav->get('/mi-cuenta/dos-pasos');
preg_match_all('~<li>([A-Z0-9]{5}-[A-Z0-9]{5})</li>~', $conCodigos['cuerpo'], $mm);
$recuperacion = $mm[1];
verificar('se muestran 10 códigos de recuperación', 10, count($recuperacion));
$deNuevo = $nav->get('/mi-cuenta/dos-pasos');
verificar_cierto('…una sola vez (al recargar ya no están)', !str_contains($deNuevo['cuerpo'], $recuperacion[0] ?? 'X'));
verificar('la sesión que activó el 2FA sigue abierta', 200, $nav->get('/')['codigo']);

seccion('ingresar: la clave sola no alcanza, pide el código');
$cel = new Navegador();
$r = $cel->login($u['usuario'], $u['clave']);
verificar_cierto('clave correcta → /login/2fa', redirige_a($r, '/login/2fa'));
verificar_cierto('en ese punto todavía NO hay sesión (/ manda al login)', redirige_a($cel->get('/'), '/login'));
verificar_cierto('/login/2fa sin haber pasado la clave manda al login', redirige_a((new Navegador())->get('/login/2fa'), '/login'));
$cel->get('/login/2fa');
$r = $cel->post('/login/2fa', ['codigo' => '12345']);
verificar_contiene('código incorrecto: "El código no es correcto."', 'El código no es correcto.', $r['cuerpo']);
$codigoBueno = totp_codigo($secreto, $paso + 1);
$r = $cel->post('/login/2fa', ['codigo' => $codigoBueno]);
verificar_cierto('código correcto → al panel', redirige_a($r, '/'));
verificar('ya con sesión', 200, $cel->get('/')['codigo']);

seccion('el mismo código no sirve dos veces (anti-repetición)');
$otro = new Navegador();
$otro->login($u['usuario'], $u['clave']);
$otro->get('/login/2fa');
$r = $otro->post('/login/2fa', ['codigo' => $codigoBueno]);
verificar_contiene('reusar el código recién usado: rechazado', 'El código no es correcto.', $r['cuerpo']);

seccion('código de recuperación: entra una vez, la segunda no');
$r = $otro->post('/login/2fa', ['codigo' => strtolower($recuperacion[0])]);   // en minúsculas: se normaliza
verificar_cierto('con un código de recuperación entra', redirige_a($r, '/'));
fijar_usuario($u['id']);
verificar('quedan 9 códigos sin usar', 9, (int) valor('SELECT COUNT(*) FROM totp_recuperacion WHERE usuario_id = {U} AND usado_en IS NULL'));
$tercero = new Navegador();
$tercero->login($u['usuario'], $u['clave']);
$tercero->get('/login/2fa');
$r = $tercero->post('/login/2fa', ['codigo' => $recuperacion[0]]);
verificar_contiene('el mismo código de recuperación por segunda vez: rechazado', 'El código no es correcto.', $r['cuerpo']);

seccion('5 códigos incorrectos en el mismo ingreso: hay que volver a poner usuario y contraseña');
// Los fallos de arriba también cuentan para el bloqueo usuario+IP (5 en 15 minutos): se limpian para probar el corte de la sesión
q("DELETE FROM login_intentos WHERE usuario LIKE '2fa:%'");
$tercero = new Navegador();
$tercero->login($u['usuario'], $u['clave']);
$tercero->get('/login/2fa');
for ($i = 0; $i < 5; $i++) {
    $r = $tercero->post('/login/2fa', ['codigo' => '00000' . $i]);
}
verificar_cierto('al quinto incorrecto, vuelve a /login', redirige_a($r, '/login'));
verificar_cierto('y /login/2fa ya no deja seguir', redirige_a($tercero->get('/login/2fa'), '/login'));

seccion('desactivar: pide la clave y un código');
$nav->get('/mi-cuenta/dos-pasos');
$nav->post('/acciones/dos_pasos_desactivar', ['clave_actual' => 'no-es-la-clave', 'codigo' => $recuperacion[1]]);
fijar_usuario($u['id']);
verificar('con la clave equivocada no se desactiva', 1, (int) valor('SELECT totp_activo FROM usuarios WHERE id = ?', [$u['id']]));
$nav->get('/mi-cuenta/dos-pasos');
$nav->post('/acciones/dos_pasos_desactivar', ['clave_actual' => $u['clave'], 'codigo' => $recuperacion[1]]);
verificar('con la clave y un código válido se desactiva', 0, (int) valor('SELECT totp_activo FROM usuarios WHERE id = ?', [$u['id']]));
verificar('y se borran los códigos de recuperación', 0, (int) valor('SELECT COUNT(*) FROM totp_recuperacion WHERE usuario_id = {U}'));
$r = (new Navegador())->login($u['usuario'], $u['clave']);
verificar_cierto('sin 2FA, la clave sola vuelve a entrar directo', redirige_a($r, '/'));

q("DELETE FROM login_intentos WHERE ip = '127.0.0.1'");
