# Pruebas automáticas

Un solo comando corre todo:

```
bash tests/entorno.sh
```

La primera vez descarga PHP 8.3 y MariaDB 11.4 portables a `tests/.tools/` (no tocan el sistema, no pisan
`privado/config.php`) e inicializa el datadir de MariaDB; las siguientes veces reutiliza todo eso y solo
corre las pruebas. Si ya tenés un MariaDB/MySQL corriendo en el puerto configurado, lo reutiliza y no lo
apaga al final; si lo levanta el script, lo apaga él mismo al terminar.

Si ya tenés PHP 8.1+ con `pdo_mysql` y un MariaDB/MySQL alcanzable (portable o no), no hace falta
`entorno.sh`: alcanza con

```
php tests/run.php
```

## Qué hace cada corrida

1. Conecta al servidor de base de datos (ver variables de entorno abajo) y **borra y vuelve a crear** la
   base de prueba desde `privado/install/install.sql`. Nunca hay datos de una corrida anterior
   contaminando la siguiente — fue justo lo que pasó a mano en la sesión donde se armó esto: un pago de una
   prueba se imputaba al cargo de otra, por cargos viejos que habían quedado de antes.
2. Arma un `config.php` de prueba en `tests/.entorno/privado/` (una carpeta aparte, nunca
   `privado/config.php`) y arranca `privado/includes/bootstrap.php` con ese `config.php` — de ahí en más es
   la aplicación real, no una reimplementación.
3. Corre, en orden alfabético, cada archivo de `tests/casos/*.php`.
4. Imprime un resumen y termina con código de salida 1 si algo falló (para CI).

## Variables de entorno (todas opcionales)

| Variable | Por defecto |
|---|---|
| `MOSCODE_TEST_DB_HOST` | `127.0.0.1` |
| `MOSCODE_TEST_DB_PORT` | `33069` |
| `MOSCODE_TEST_DB_USER` | `root` |
| `MOSCODE_TEST_DB_PASS` | (vacío) |
| `MOSCODE_TEST_DB_NAME` | `moscode_pruebas` |

Si ya tenés tu propio MySQL/MariaDB (local o de otra instalación de prueba), fijalas y corré directamente
`php tests/run.php` — se olvida de `entorno.sh` por completo. La base que nombres se **borra y se vuelve a
crear** en cada corrida: nunca apuntes esto a una base con datos reales.

## Escribir un caso de prueba nuevo

Un archivo en `tests/casos/`, con cualquier nombre que termine en `.php` (se corren todos, en orden
alfabético, dentro del mismo proceso de `tests/run.php`). Adentro tenés disponibles, sin `require` ni
`use`, todo lo de la aplicación (`privado/includes/*.php` ya está cargado) más lo de `tests/soporte.php`:

- `nuevo_usuario_de_prueba(string $prefijo): int` — crea un usuario nuevo y **deja el contexto fijado en
  él** (`fijar_usuario()`). Si después creás un segundo usuario "ajeno" para probar aislamiento entre
  cuentas, acordate de volver a fijar el usuario dueño (`fijar_usuario($usuarioId)`) antes de seguir
  verificando sus datos — si no, las consultas siguientes quedan filtradas por el usuario equivocado y
  **cualquier resultado da `null`, con la prueba pasando por accidente o fallando por una razón que no es
  la real**. Ya pasó una vez armando esta suite; ver el comentario en cualquier `tests/casos/*.php` que
  pruebe aislamiento.
- `nuevo_cliente_de_prueba(string $nombre, array $extra = []): int` — cliente para el usuario en contexto.
- `verificar(string $nombre, $esperado, $real)`, `verificar_cierto(string $nombre, bool $condicion)`,
  `verificar_contiene(string $nombre, string $aguja, string $pajar)` — las aserciones. `seccion(string
  $titulo)` solo ordena la salida, no es una aserción.
- `ejecutar_accion(int $usuarioId, string $accion, array $post = [])` — corre una acción real de
  `privado/acciones/` (la misma que llamaría `panel.php` desde un formulario), **en un subproceso aparte**.
  Hace falta porque toda acción termina con `redirigir()` (`header()` + `exit`), que si se hiciera con un
  `require` directo cortaría TODA la corrida de pruebas, no solo esa aserción. Después de llamarla, volvé a
  consultar la base con `fila()`/`valor()` para ver qué pasó, como lo haría la vista siguiente. Si la acción
  pide `nonce` (formularios de un solo uso) y el `$post` no lo trae, `ejecutar_accion()` genera uno válido
  solo.
- Las tablas de datos de usuario (`TABLAS_USUARIO` en `privado/includes/db.php`) exigen el filtro
  `usuario_id = {U}` también en las consultas de las pruebas: una consulta sin él se bloquea con una
  excepción. Para mirar los datos de otra cuenta, `con_usuario($id, fn() => valor(...))`.

### Pruebas por HTTP (`tests/soporte_http.php`)

Los casos `tests/casos/http_*.php` (y partes de otros) recorren la app real por HTTP, como un navegador: la
primera vez que un caso lo pide se levanta el servidor embebido de PHP (`php -S`, puerto
`MOSCODE_TEST_HTTP_PORT`, 8099 por defecto) sirviendo una **copia** de `privado/` y `public_html/` en
`tests/.entorno/http/` con el `config.php` de prueba, y se apaga solo al terminar la corrida. El router es
`tests/router_http.php` (hace lo mismo que el `.htaccess`). Disponible en los casos:

- `new Navegador()` — un dispositivo con sus propias cookies. `->login($usuario, $clave, $recordar)`,
  `->get($ruta)`, `->post($ruta, $datos)` (agrega solo el `csrf` y el `nonce` de la última página que
  abrió: para un formulario de un solo uso, abrí antes la pantalla del formulario), `->pedir()` para un
  pedido crudo (sin CSRF, con otro cuerpo o cabeceras), `->cookies()`, `->ponerCookie()` y
  `->cerrarNavegador()` (borra las cookies de sesión, como cerrar el navegador). Cada respuesta es
  `['codigo', 'cab' => [nombre en minúsculas => [valores]], 'cuerpo', 'location']`; no sigue
  redirecciones solo, para poder verificar a dónde manda (`redirige_a($r, '/login')`).
- `nuevo_usuario_con_clave($prefijo, $clave, $rol = 'usuario')` — usuario con contraseña conocida para
  entrar por `/login`; deja el contexto fijado en él.
- Si una respuesta da 500, se imprimen las últimas líneas del log de errores de la app servida.

Ojo con el **límite de intentos por IP** (20 fallos en 15 minutos, cualquier usuario): todas las pruebas
salen de 127.0.0.1. Un caso que provoque fallos de login a propósito tiene que borrarlos al terminar
(`DELETE FROM login_intentos WHERE ip = '127.0.0.1'`), como hacen `http_login.php` y `http_2fa.php`.

Lo único que el servidor embebido no reproduce es el `.htaccess` (cabeceras de los archivos estáticos,
redirección a HTTPS, carpetas bloqueadas): para eso está `php privado/scripts/verificar_servidor.php --web`
contra el hosting real.

No hace falta (ni conviene) probar acá lo que ya cubre el auditor estático de aislamiento
(`php privado/scripts/auditar_aislamiento.php`, que no usa esta base) ni lo que es puramente de formato de
texto sin tocar la base (eso va en `tests/casos/mensajes.php`, que no necesita ninguna de las funciones de
arriba).

## Otras verificaciones que no corren con este comando

- **Sintaxis de todo el proyecto**: `for f in $(find privado public_html tests -name '*.php'); do php -l "$f"; done`
- **Aislamiento entre usuarios (estático, sin base)**: `php privado/scripts/auditar_aislamiento.php`
- De la batería de seguridad que describe el README principal, ya corren acá login y bloqueo, recordarme,
  2FA, contraseña temporal, pagos y anulaciones, cuotas, webhook de Mercado Pago (simulado), Redes, cotización
  pendiente, cabeceras/CSP y aislamiento de pantallas, exportaciones y acciones. Siguen siendo manuales:
  SMTP/Telegram, backups y restauración, rotación de la clave maestra, instalador, portal del cliente, crons
  por URL y el comportamiento del `.htaccess` en el hosting.
