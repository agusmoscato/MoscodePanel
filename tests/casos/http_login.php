<?php
/**
 * http_login.php — Login, bloqueo por intentos, sesión recordada ("mantener sesión iniciada"), contraseña temporal y
 * salir, de punta a punta por HTTP contra la app real (ver tests/soporte_http.php).
 *
 * Ojo con el límite POR IP (20 fallos en 15 minutos, cualquier usuario): todos los pedidos de las pruebas salen de
 * 127.0.0.1, así que la suite entera tiene que quedar por debajo de eso. Este caso usa 5 fallos de clave + 1 de
 * usuario inexistente, y los borra al terminar.
 */
declare(strict_types=1);

seccion('login correcto: entra al panel y la cookie de sesión es HttpOnly y SameSite=Lax');
$u = nuevo_usuario_con_clave('login', 'Clave-Login-2026');
$nav = new Navegador();
$pantalla = $nav->get('/login');
verificar('GET /login responde 200', 200, $pantalla['codigo']);
verificar_cierto('el formulario de login trae token CSRF', $nav->ultimoCsrf !== '');
$r = $nav->post('/login', ['usuario' => $u['usuario'], 'clave' => $u['clave']]);
verificar_cierto('login correcto redirige al panel (/)', redirige_a($r, '/'));
$cookieSesion = implode(' | ', array_filter($r['cab']['set-cookie'] ?? [], fn($c) => str_starts_with($c, 'panel_sid=')));
verificar_contiene('cookie de sesión HttpOnly', 'HttpOnly', $cookieSesion);
verificar_contiene('cookie de sesión SameSite=Lax', 'SameSite=Lax', $cookieSesion);
verificar('sin "recordarme" no queda cookie panel_recordar', false, isset($nav->cookies()['panel_recordar']));
$panel = $nav->get('/');
verificar('con sesión, / responde 200', 200, $panel['codigo']);
verificar_cierto('sin sesión, / manda al login', redirige_a((new Navegador())->get('/'), '/login'));

seccion('login sin CSRF: 403');
$sinCsrf = new Navegador();
$r = $sinCsrf->pedir('POST', '/login', ['usuario' => $u['usuario'], 'clave' => $u['clave']]);
verificar('POST /login sin token CSRF responde 403', 403, $r['codigo']);

seccion('clave incorrecta y usuario inexistente: mismo mensaje (no revela qué usuarios existen)');
$malo = new Navegador();
$r1 = $malo->login($u['usuario'], 'incorrecta-1');
$r2 = $malo->login('no-existe-' . bin2hex(random_bytes(3)), 'incorrecta-1');
verificar_contiene('clave incorrecta: "Usuario o contraseña incorrectos."', 'Usuario o contraseña incorrectos.', $r1['cuerpo']);
verificar_contiene('usuario inexistente: el mismo mensaje', 'Usuario o contraseña incorrectos.', $r2['cuerpo']);

seccion('bloqueo: 5 fallos del mismo usuario desde la misma IP bloquean, aun con la clave correcta');
for ($i = 2; $i <= 5; $i++) {
    $malo->login($u['usuario'], "incorrecta-$i");
}
$r = $malo->login($u['usuario'], $u['clave']);
verificar_contiene('con la clave correcta, igual dice "Demasiados intentos fallidos"', 'Demasiados intentos fallidos', $r['cuerpo']);
verificar('y no entra (se queda en /login, 200)', 200, $r['codigo']);
$otro = nuevo_usuario_con_clave('login_otro', 'Clave-Otro-2026');
$r = (new Navegador())->login($otro['usuario'], $otro['clave']);
verificar_cierto('otro usuario desde la misma IP entra igual (el bloqueo es del par usuario+IP)', redirige_a($r, '/'));
q("UPDATE login_intentos SET creado_en = DATE_SUB(NOW(), INTERVAL 16 MINUTE) WHERE usuario = ?", [$u['usuario']]);
$r = $malo->login($u['usuario'], $u['clave']);
verificar_cierto('pasados los 15 minutos, la clave correcta vuelve a entrar', redirige_a($r, '/'));
fijar_usuario($u['id']);
verificar_cierto('los fallos quedaron en la actividad del usuario', (int) valor("SELECT COUNT(*) FROM registro_actividad WHERE usuario_id = {U} AND evento = 'login_fallo'") >= 5);

seccion('cuenta desactivada: no entra');
$inactivo = nuevo_usuario_con_clave('login_inactivo', 'Clave-Inactivo-2026', 'usuario', ['activo' => 0]);
$r = (new Navegador())->login($inactivo['usuario'], $inactivo['clave']);
verificar_contiene('usuario desactivado: "Usuario o contraseña incorrectos."', 'Usuario o contraseña incorrectos.', $r['cuerpo']);

seccion('sesión recordada: sobrevive al cierre del navegador, rota el validador y detecta una cookie copiada');
$rec = nuevo_usuario_con_clave('recordar', 'Clave-Recordar-2026');
$cel = new Navegador('Mozilla/5.0 (Linux; Android 14) Mobile');
$cel->login($rec['usuario'], $rec['clave'], true);
$cookie1 = $cel->cookies()['panel_recordar'] ?? '';
verificar_cierto('con "recordarme" queda la cookie panel_recordar (selector:validador)', (bool) preg_match('/^[0-9a-f]+:[0-9a-f]{64}$/', $cookie1));
fijar_usuario($rec['id']);
$fila = fila('SELECT * FROM sesiones_recordar WHERE usuario_id = {U} ORDER BY id DESC LIMIT 1');
[$selector, $validador] = explode(':', $cookie1 . ':');
verificar_cierto('en la base queda el HASH del validador, no el validador', $fila && $fila['validador_hash'] === hash('sha256', $validador) && !str_contains(json_encode($fila), $validador));
$cel->cerrarNavegador();
verificar('después de cerrar el navegador, sin cookie de sesión de PHP', false, isset($cel->cookies()['panel_sid']));
verificar('…sigue la de recordarme', true, isset($cel->cookies()['panel_recordar']));
$r = $cel->get('/');
verificar('al volver, entra directo al panel (200, sin pasar por /login)', 200, $r['codigo']);
$cookie2 = $cel->cookies()['panel_recordar'] ?? '';
verificar_cierto('el validador rotó (la cookie cambió, mismo selector)', $cookie2 !== $cookie1 && str_starts_with($cookie2, $selector . ':'));

// Un ladrón con la cookie VIEJA, pasado el minuto de gracia: se revocan todas las sesiones de esa cuenta
fijar_usuario($rec['id']);
q('UPDATE sesiones_recordar SET rotado_en = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE usuario_id = {U}');
$ladron = new Navegador();
$ladron->ponerCookie('panel_recordar', $cookie1);
$r = $ladron->get('/');
verificar_cierto('la cookie vieja (ya rotada) no entra: va al login', redirige_a($r, '/login'));
verificar('se borraron todos los tokens de recordarme de esa cuenta', 0, (int) valor('SELECT COUNT(*) FROM sesiones_recordar WHERE usuario_id = {U}'));
verificar_cierto('quedó registrado como posible robo', (int) valor("SELECT COUNT(*) FROM registro_actividad WHERE usuario_id = {U} AND evento = 'token_robado'") === 1);
verificar_cierto('y el dueño también queda afuera (su sesión abierta se invalidó)', redirige_a($cel->get('/'), '/login'));

seccion('sesión recordada: cambiar la contraseña invalida los demás dispositivos recordados');
$pc = new Navegador();
$pc->login($rec['usuario'], $rec['clave'], true);
$tablet = new Navegador('Mozilla/5.0 (iPad)');
$tablet->login($rec['usuario'], $rec['clave'], true);
$pc->get('/mi-cuenta');
$pc->post('/acciones/password_cambiar', ['actual' => $rec['clave'], 'nueva' => 'Nueva-Clave-Recordar-77', 'nueva2' => 'Nueva-Clave-Recordar-77']);
verificar('la PC que cambió la clave sigue adentro', 200, $pc->get('/')['codigo']);
$tablet->cerrarNavegador();
verificar_cierto('la tablet (recordada, con la cookie de antes) tiene que volver a ingresar', redirige_a($tablet->get('/'), '/login'));

seccion('contraseña temporal: el admin la asigna, el usuario solo puede cambiarla, y sus sesiones se cierran');
$admin = nuevo_usuario_con_clave('admin_temp', 'Clave-Admin-2026', 'admin');
$emp = nuevo_usuario_con_clave('temporal', 'Clave-Empleado-2026');
$navEmp = new Navegador();
$navEmp->login($emp['usuario'], $emp['clave']);
verificar('el usuario estaba adentro', 200, $navEmp->get('/')['codigo']);
$navAdm = new Navegador();
$navAdm->login($admin['usuario'], $admin['clave']);
$navAdm->get('/usuarios');
$navAdm->post('/acciones/usuario_reset', ['id' => (string) $emp['id'], 'clave' => 'Temporal-123']);
fijar_usuario($emp['id']);
verificar('quedó marcado para cambiarla', 1, (int) valor('SELECT debe_cambiar_clave FROM usuarios WHERE id = ?', [$emp['id']]));
verificar_cierto('la sesión que tenía abierta se cerró', redirige_a($navEmp->get('/'), '/login'));
verificar_cierto('la clave vieja ya no entra', !redirige_a((new Navegador())->login($emp['usuario'], $emp['clave']), '/'));
$navEmp = new Navegador();
$r = $navEmp->login($emp['usuario'], 'Temporal-123');
verificar_cierto('con la temporal entra…', redirige_a($r, '/'));
verificar_cierto('…pero cualquier pantalla lo manda a /cambiar-clave', redirige_a($navEmp->get('/clientes'), '/cambiar-clave'));
verificar_cierto('las exportaciones también', redirige_a($navEmp->get('/exportar/clientes.csv'), '/cambiar-clave'));
$navEmp->get('/cambiar-clave');
$navEmp->post('/acciones/cliente_guardar', ['nombre' => 'No debería crearse']);
fijar_usuario($emp['id']);
verificar('una acción cualquiera no se ejecuta con clave temporal', 0, (int) valor("SELECT COUNT(*) FROM clientes WHERE usuario_id = {U} AND nombre = 'No debería crearse'"));
$navEmp->get('/cambiar-clave');
$navEmp->post('/acciones/password_cambiar', ['actual' => 'Temporal-123', 'nueva' => 'corta', 'nueva2' => 'corta']);
verificar('una clave nueva corta se rechaza (sigue marcada)', 1, (int) valor('SELECT debe_cambiar_clave FROM usuarios WHERE id = ?', [$emp['id']]));
$navEmp->get('/cambiar-clave');
$r = $navEmp->post('/acciones/password_cambiar', ['actual' => 'Temporal-123', 'nueva' => 'Definitiva-Segura-91', 'nueva2' => 'Definitiva-Segura-91']);
verificar_cierto('con una clave válida, pasa al panel', redirige_a($r, '/'));
verificar('ya no está marcada como temporal', 0, (int) valor('SELECT debe_cambiar_clave FROM usuarios WHERE id = ?', [$emp['id']]));
verificar('y el panel abre normal', 200, $navEmp->get('/clientes')['codigo']);
$navNoAdmin = new Navegador();
$navNoAdmin->login($emp['usuario'], 'Definitiva-Segura-91');
$navNoAdmin->get('/');
$r = $navNoAdmin->post('/acciones/usuario_reset', ['id' => (string) $admin['id'], 'clave' => 'Robada-1234']);
verificar('un usuario común no puede resetear la clave de otro (403)', 403, $r['codigo']);

seccion('salir: cierra la sesión y borra la cookie de recordarme');
$navSalir = new Navegador();
$navSalir->login($u['usuario'], $u['clave'], true);
$navSalir->get('/');
$r = $navSalir->post('/salir');
verificar_cierto('POST /salir manda al login', redirige_a($r, '/login'));
verificar('ya no queda cookie de recordarme', false, isset($navSalir->cookies()['panel_recordar']));
verificar_cierto('y / vuelve a pedir login', redirige_a($navSalir->get('/'), '/login'));
verificar_cierto('GET /salir no cierra nada (redirige al panel, no es una acción)', !redirige_a($navAdm->get('/salir'), '/login') && $navAdm->get('/')['codigo'] === 200);

q("DELETE FROM login_intentos WHERE ip = '127.0.0.1'");   // no dejar fallos que cuenten para el límite por IP de los casos siguientes
