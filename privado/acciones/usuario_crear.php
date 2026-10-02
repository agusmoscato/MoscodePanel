<?php
/** Crea un usuario (solo admin) con contraseña temporal: tiene que cambiarla al iniciar sesión. */
$admin = exigir_admin();
$volver = url('usuario_form');
if ($err = largo_excedido(['El usuario' => [post('usuario'), 60], 'El nombre' => [post('nombre'), 120], 'El email' => [post('email'), 160]])) {
    volver_con_error($err, $volver);
}
$r = crear_usuario([
    'usuario' => post('usuario'), 'nombre' => post('nombre'), 'email' => post('email'),
    'rol' => post('rol', 'usuario'), 'clave' => (string) ($_POST['clave'] ?? ''),
]);
if (!$r['ok']) {
    volver_con_error($r['error'], $volver);
}
registrar_actividad('usuario_creado', 'Cuenta «' . post('usuario') . '» (' . post('rol', 'usuario') . ')', (int) $r['id'], (int) $admin['id']);
$_SESSION['clave_temporal'] = ['usuario' => post('usuario'), 'clave' => $r['clave'], 'tipo' => 'creada'];
flash('ok', 'Usuario creado. Pasale la contraseña temporal: tiene que cambiarla al entrar.');
redirigir(url('usuarios'));
