<?php
/**
 * cotizacion_pendiente.php — Una cotización automática que varía más del 20% contra la última aplicada no se usa
 * sola: queda pendiente hasta que un administrador la confirma o la descarta (una devaluación puede ser real, un
 * error de la fuente también). Mientras tanto todos siguen con la anterior.
 *
 * Usa el dólar "tarjeta" (ningún otro caso lo toca) y deja todo resuelto al terminar.
 */
declare(strict_types=1);

$admin = nuevo_usuario_con_clave('cot_admin', 'Clave-CotAdmin-2026', 'admin');
cfg_set('dolar_tipo', 'tarjeta');
$usr = nuevo_usuario_con_clave('cot_usuario', 'Clave-CotUsuario-2026');
cfg_set('dolar_tipo', 'tarjeta');
$valorDe = fn(int $uid) => con_usuario($uid, fn() => cotizacion_valor());

seccion('variaciones normales se aplican solas');
verificar('la primera automática se aplica', 'aplicada', guardar_cotizacion('tarjeta', 1000.0, 'dolarapi'));
verificar('+15%: se aplica', 'aplicada', guardar_cotizacion('tarjeta', 1150.0, 'dolarapi'));
verificar('el usuario ya usa 1150', 1150.0, $valorDe($usr['id']));

seccion('más del 20%: queda pendiente y se sigue usando la anterior');
verificar('+30%: "pendiente"', 'pendiente', guardar_cotizacion('tarjeta', 1500.0, 'dolarapi'));
$pend = fila("SELECT * FROM cotizaciones WHERE tipo = 'tarjeta' AND usuario_id IS NULL AND estado = 'pendiente' ORDER BY id DESC LIMIT 1");
verificar_cierto('quedó la fila pendiente', $pend !== null);
verificar('con la variación calculada (30,43%)', '30.43', $pend['variacion_pct'] ?? null);
verificar('el usuario sigue usando 1150', 1150.0, $valorDe($usr['id']));
verificar('el admin también', 1150.0, $valorDe($admin['id']));
verificar('el mismo valor otra vez (±1%): no se duplica ni se vuelve a avisar', 'pendiente', guardar_cotizacion('tarjeta', 1505.0, 'dolarapi'));
verificar('sigue habiendo una sola pendiente', 1, (int) valor("SELECT COUNT(*) FROM cotizaciones WHERE tipo = 'tarjeta' AND usuario_id IS NULL AND estado = 'pendiente'"));
verificar('una caída de más del 20% también queda pendiente', 'pendiente', guardar_cotizacion('tarjeta', 850.0, 'dolarapi'));
q("UPDATE cotizaciones SET estado = 'descartada' WHERE tipo = 'tarjeta' AND usuario_id IS NULL AND estado = 'pendiente' AND valor_venta = 850");

seccion('una cotización manual (personal) se aplica siempre, sin pasar por la confirmación');
fijar_usuario($usr['id']);
verificar('manual con +100%: "aplicada"', 'aplicada', guardar_cotizacion('tarjeta', 2300.0, 'manual', true));
verificar('la usa solo quien la cargó', 2300.0, $valorDe($usr['id']));
verificar('el resto sigue con la automática (1150)', 1150.0, $valorDe($admin['id']));
q("DELETE FROM cotizaciones WHERE tipo = 'tarjeta' AND usuario_id = ?", [$usr['id']]);

seccion('en la pantalla Dólar: el admin ve Confirmar/Descartar; un usuario común no, y no puede forzarlo');
$navUsr = new Navegador();
$navUsr->login($usr['usuario'], $usr['clave']);
$p = $navUsr->get('/dolar');
verificar_contiene('el usuario ve el aviso de pendiente', 'Cotización pendiente de confirmar', $p['cuerpo']);
verificar_cierto('pero no el botón Confirmar', !str_contains($p['cuerpo'], 'cotizacion_confirmar'));
$r = $navUsr->post('/acciones/cotizacion_confirmar', ['id' => (string) $pend['id']]);
verificar('si igual manda el formulario: 403', 403, $r['codigo']);
verificar('sigue pendiente', 'pendiente', valor('SELECT estado FROM cotizaciones WHERE id = ?', [$pend['id']]));
$navAdm = new Navegador();
$navAdm->login($admin['usuario'], $admin['clave']);
$p = $navAdm->get('/dolar');
verificar_contiene('el admin ve el botón Confirmar', 'cotizacion_confirmar', $p['cuerpo']);

seccion('el admin confirma: desde ahí la usan todos');
$navAdm->post('/acciones/cotizacion_confirmar', ['id' => (string) $pend['id']]);
$c = fila('SELECT estado, resuelta_por FROM cotizaciones WHERE id = ?', [$pend['id']]);
verificar('quedó aplicada, con quién la confirmó', ['aplicada', $admin['id']], [$c['estado'], (int) $c['resuelta_por']]);
verificar('el usuario ahora usa 1500', 1500.0, $valorDe($usr['id']));
$navAdm->get('/dolar');
$navAdm->post('/acciones/cotizacion_confirmar', ['id' => (string) $pend['id']]);
verificar('confirmarla de nuevo no cambia nada', 1500.0, $valorDe($usr['id']));
fijar_usuario($admin['id']);
verificar_cierto('quedó en la actividad', (int) valor("SELECT COUNT(*) FROM registro_actividad WHERE usuario_id = {U} AND evento = 'cotizacion_confirmada'") === 1);

seccion('descartar: se sigue con la anterior');
verificar('+50%: pendiente', 'pendiente', guardar_cotizacion('tarjeta', 2250.0, 'dolarapi'));
$pend2 = (int) valor("SELECT id FROM cotizaciones WHERE tipo = 'tarjeta' AND usuario_id IS NULL AND estado = 'pendiente' ORDER BY id DESC LIMIT 1");
$navAdm->get('/dolar');
$navAdm->post('/acciones/cotizacion_descartar', ['id' => (string) $pend2]);
verificar('quedó descartada', 'descartada', valor('SELECT estado FROM cotizaciones WHERE id = ?', [$pend2]));
verificar('todos siguen con 1500', 1500.0, $valorDe($usr['id']));

seccion('si el mercado vuelve a un valor normal, la pendiente se descarta sola');
verificar('+40%: pendiente', 'pendiente', guardar_cotizacion('tarjeta', 2100.0, 'dolarapi'));
$pend3 = (int) valor("SELECT id FROM cotizaciones WHERE tipo = 'tarjeta' AND usuario_id IS NULL AND estado = 'pendiente' ORDER BY id DESC LIMIT 1");
verificar('+5% contra la aplicada: se aplica', 'aplicada', guardar_cotizacion('tarjeta', 1575.0, 'dolarapi'));
verificar('y la pendiente anterior quedó descartada', 'descartada', valor('SELECT estado FROM cotizaciones WHERE id = ?', [$pend3]));
verificar('no queda ninguna pendiente', 0, (int) valor("SELECT COUNT(*) FROM cotizaciones WHERE usuario_id IS NULL AND estado = 'pendiente'"));
