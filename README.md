# Panel de cobros — Moscode (multiusuario)

Panel interno para gestionar clientes, servicios, dominios, cargos, pagos y cotización del dólar.
PHP 8.1+ puro, MySQL (PDO), HTML/CSS/JS vanilla. Sin Composer ni npm.

> Este README cubre las **tres fases** — núcleo (1), automatizaciones (2), reportes, exportar, portal y Mercado Pago (3) — y el rediseño de la interfaz con la app para el celular (al final).

## Estructura

```
privado/                  ← NO público (ideal: fuera de public_html)
  config.php              (lo creás vos a partir de config.example.php)
  includes/               (db, auth, csrf, helpers, cotización, cargos, layout, ui, version…)
  vistas/                 (pantallas)
  acciones/               (handlers POST: guardar, eliminar, registrar pago…)
  cron/                   (scripts para los Cron Jobs)
  install/install.sql     (todas las tablas)
public_html/              ← lo único público
  index.php  .htaccess  offline.html  favicon.*   (index.php es el ÚNICO .php público: controlador frontal)
  assets/css, assets/js, assets/fonts, assets/img
```

`public_html/index.php` busca `privado/` primero **al lado** de `public_html` (`../privado`); si no existe, la busca **adentro** de `public_html/privado`. Así funciona con las dos ubicaciones sin tocar código.

## 1. Crear la base de datos en hPanel

1. hPanel → **Bases de datos → Bases de datos MySQL**.
2. Elegí nombre de base, usuario y contraseña, y creala. Anotá los tres datos (Hostinger les antepone un prefijo tipo `u123456789_`).

## 2. Subir los archivos

Con el **Administrador de archivos** de hPanel o por FTP:

- **Opción recomendada** (carpeta `privado` fuera de la web). En la raíz de tu hosting (donde está `public_html`):
  - subí la carpeta `privado/` **al lado** de `public_html/`;
  - subí el contenido de `public_html/` de este proyecto **dentro** de `public_html/` (o dentro de la carpeta del subdominio, ej. `public_html/panel/`; en ese caso `privado/` va al lado de esa carpeta, un nivel arriba).
- **Opción alternativa** (si hPanel no te deja subir fuera de `public_html`): subí `privado/` **dentro** de `public_html/`. Está protegida con su `.htaccess` (`Require all denied`), pero verificá que `https://tudominio/privado/config.php` te dé error 403.

Activá el **SSL** (hPanel → Seguridad → SSL) y forzá HTTPS.

## 3. Configurar `config.php`

1. En `privado/`, copiá `config.example.php` como `config.php`.
2. Completá los datos de la base (`db`) y generá las dos claves de `seguridad` (la maestra y la de backups; ver "Seguridad" al final). Dejá `cron_token` como está (con "CAMBIAR…"): los crons se programan por consola y la ruta por URL queda desactivada.
3. Las secciones SMTP, Telegram y Mercado Pago se usan en las fases 2 y 3; podés dejarlas como están.

## 4. Correr el instalador

1. Entrá a `https://tudominio/install`.
2. Elegí usuario y contraseña de administrador (mín. 10 caracteres).
3. El instalador crea las tablas y **se borra solo**. Si avisa que no pudo, borrá `privado/paginas/install.php` a mano.

Alternativa: importar `privado/install/install.sql` desde phpMyAdmin (crea las tablas pero no el usuario; por eso conviene usar `install.php`).

## 5. Primer uso

1. Ingresá en `https://tudominio/login`.
2. **Configuración**: cargá tu nombre, alias/CBU y elegí tipo de dólar (por defecto *blue*) y fuente.
3. **Dólar**: apretá *Actualizar ahora* (o cargá un valor a mano).
4. **Clientes → Nuevo cliente**, y desde la ficha agregá servicios y dominios.
5. **Cargos → Generar cargos del mes** crea los cargos mensuales y los de servicios anuales que vencen en los próximos 30 días. Se puede apretar las veces que quieras: no duplica.
6. Desde la ficha, **Registrar pago**.

## 6. Cron Job de la cotización (ver también "Cron Jobs" en la Fase 2)

Ejecutar de lunes a viernes, cada hora:

| Campo | Valor |
|---|---|
| Minuto / Hora / Día / Mes | `0` / `*` / `*` / `*` |
| Día de la semana | `1-5` |
| Comando | `/usr/bin/php /home/USUARIO/domains/TUDOMINIO.com/privado/cron/actualizar_dolar.php` |

Reemplazá la ruta por la real (en el Administrador de archivos aparece arriba). Si tu PHP por defecto no es 8.1+, elegí la versión en hPanel → Avanzado → Configuración de PHP.

## Cómo funciona (resumen para mantenerlo)

- **Cargos**: cada uno tiene una `clave_unica` (`S<servicio>:<AAAA-MM>`, `S<servicio>:<fecha>` o `D<dominio>:<fecha>`) con índice UNIQUE y se inserta con `INSERT IGNORE`; por eso la generación es idempotente.
- **Servicio anual**: el cargo se genera `dias_anticipo` días antes del vencimiento (por servicio, 30 por defecto). Cuando ese cargo queda **pagado completo**, `proximo_vencimiento` pasa a +1 año.
- **Dominio**: *Renovar* suma un año a la fecha y, si lo tildás, crea el cargo con el precio al cliente.
- **Deuda**: no se guarda, se calcula sumando `monto − monto_pagado` de los cargos pendientes/parciales. Lo que no se cobró un mes sigue sumando en los siguientes.
- **Pagos**: imputación automática (cargo más viejo primero) o manual. Si el pago y el cargo están en distinta moneda, se convierte con la cotización guardada en el pago. Lo que sobra queda como "sin imputar".
- **Dólar**: se guarda cada actualización en `cotizaciones` (historial). Si falla, se usa la última y se avisa en el Inicio.
- **Seguridad**: contraseñas con `password_hash`, sesión HttpOnly/SameSite, CSRF en todas las acciones (que solo aceptan POST), todo escapado con `e()`, consultas preparadas y bloqueo de 15 min tras 5 intentos fallidos.

## Limitaciones conocidas de la Fase 1

- Tipo de dólar **Tarjeta**: dolarhoy.com no publica el valor en su HTML; usá la fuente *dolarapi.com* si lo necesitás.
- Si dolarhoy.com cambia su HTML, el scraping falla: el panel sigue con la última cotización, avisa, y podés cargarla a mano o cambiar a dolarapi.com.
- Servicio mensual que empieza a mitad de mes: opción por servicio (empezar el mes siguiente [por defecto], cobrar mes completo o prorratear).
- Los clientes **inactivos** no generan cargos nuevos.


## Actualizar una base ya instalada (migraciones)

Una instalación nueva con `install.php` ya trae todo. Si ya instalaste antes, importá **una sola vez cada migración que no hayas corrido**, en orden, desde phpMyAdmin (pestaña SQL):

| Archivo | Qué agrega |
|---|---|
| `privado/install/migracion_001.sql` | Opciones por servicio: días de anticipo del cargo anual y modo de inicio mensual (cambia la tabla `servicios`). |
| `privado/install/migracion_002.sql` | Fase 2: valores de configuración nuevos (canales de aviso y marca del resumen mensual). No cambia tablas; se puede correr más de una vez sin problema. |
| `privado/install/migracion_003.sql` | Fase 3: datos del link de Mercado Pago en `cargos` (columnas nuevas) y tabla `mp_webhook_log`. **Corré esta solo una vez** (agrega columnas). |
| `privado/install/migracion_004.sql` | Guarda la cotización del día en cada cargo en USD (columna nueva `cargos.cotizacion`) y completa los cargos existentes con la cotización vigente en su fecha de creación (o la actual si no hay historial). **Corré esta solo una vez** (agrega una columna). |

`install.sql` ya incluye todas las migraciones, así que `install.php` siempre instala la versión completa.

---

# Fase 2 — Automatizaciones

## Qué agrega

- **Resumen del primer día hábil del mes**: genera los cargos y te manda cuánto debe cada cliente, con el total. Excluye fines de semana y feriados.
- **Feriados** editables (Configuración → Feriados), con importación desde argentinadatos.com.
- **Avisos de vencimiento** de dominios y servicios anuales (por defecto a 30, 15, 7 días y el mismo día), sin repetir.
- **Notificaciones** por email (SMTP propio, sin librerías) y por Telegram, con registro de todo lo enviado.
- **Mensaje de cobro por WhatsApp** (botón en la ficha del cliente y en la lista de deudores).
- **Ajuste de precios** por porcentaje con vista previa e historial (menú Precios).
- **Servicios por cantidad**: cantidad × precio por unidad (ej. Google Workspace por usuarios, casillas de mail por cuenta). El monto se calcula solo y, si cambia la cantidad, queda en el historial de precios como cualquier otro cambio.
- **Aviso de renovación anual por WhatsApp**: plantilla propia (Configuración → Aviso de renovación anual), separada de la de cobro. Se ofrece en la ficha del cliente y en Vencimientos cuando un servicio anual vence en los próximos 60 días (7 para dominios), y queda registrada la fecha en que se mandó.
- **Cobro automático de dominios**: igual que un servicio anual, el cargo se genera solo con los días de anticipo configurados por dominio; cobrarlo no mueve la fecha de vencimiento (eso lo hace aparte el botón "Renovar", después de renovarlo de verdad en el proveedor).

## Configurar SMTP y Telegram (`privado/config.php`)

**Email (SMTP de Hostinger):** en hPanel → Emails creá una casilla (ej. `avisos@tudominio.com`) y completá en `config.php`:

```php
'smtp' => [
    'host' => 'smtp.hostinger.com', 'puerto' => 465, 'seguridad' => 'ssl',
    'usuario' => 'avisos@tudominio.com', 'clave' => 'LA_CLAVE_DE_LA_CASILLA',
    'desde' => 'avisos@tudominio.com', 'desde_nombre' => 'Panel de cobros',
],
```

Con puerto 587 poné `'seguridad' => 'tls'`. Después, en **Configuración**, cargá "Email donde recibo los avisos" (puede ser tu Gmail).

**Crear el bot de Telegram:**

1. En Telegram abrí **@BotFather** → `/newbot` → elegí nombre y usuario (termina en `bot`). Te da un **token** (`123456:ABC...`).
2. Abrí tu bot nuevo y mandale cualquier mensaje (por ejemplo `hola`). Sin esto el bot no puede escribirte.
3. En el navegador abrí `https://api.telegram.org/botTU_TOKEN/getUpdates` (reemplazá `TU_TOKEN`). En la respuesta buscá `"chat":{"id":123456789`: ese número es tu **chat_id**.
4. Completá en `config.php`: `'telegram' => ['token' => '123456:ABC...', 'chat_id' => '123456789']`.

## Cron Jobs (hPanel → Avanzado → Cron Jobs → "Personalizado")

Reemplazá `USUARIO` y `TUDOMINIO.com` por la ruta real de tu hosting (la ves arriba en el Administrador de archivos; suele ser `/home/uXXXXXXXXX/domains/TUDOMINIO.com/`). La carpeta `privado` es la que subiste al lado de `public_html`.

| Tarea | Frecuencia (min hora día mes día-semana) | Comando exacto |
|---|---|---|
| Cotización del dólar | `0 * * * 1-5` (cada hora, lun-vie) | `/usr/bin/php /home/USUARIO/domains/TUDOMINIO.com/privado/cron/actualizar_dolar.php` |
| Resumen mensual | `0 8 * * *` (todos los días 8:00) | `/usr/bin/php /home/USUARIO/domains/TUDOMINIO.com/privado/cron/resumen_mensual.php` |
| Avisos de vencimiento | `0 9 * * *` (todos los días 9:00) | `/usr/bin/php /home/USUARIO/domains/TUDOMINIO.com/privado/cron/avisos_vencimientos.php` |

- El resumen corre **todos los días** a propósito: el script decide si hoy toca. Actúa solo si hoy es día hábil y el resumen del mes todavía no se envió. Si el primer día hábil falla (o el cron no corrió), **reintenta el siguiente día hábil** hasta que salga.
- Si tu hosting no deja programar PHP por CLI, podés usar la versión por URL: poné en `app.cron_token` un token de **al menos 32 caracteres** y mandalo en la **cabecera** `X-Cron-Token` (ej. `curl -s -H "X-Cron-Token: TU_TOKEN" https://TUDOMINIO.com/cron/avisos_vencimientos`). También se acepta `?token=`, pero ese queda en los logs de acceso del servidor. Por URL no se puede forzar el resumen ni se ven nombres de usuario, y 10 intentos con un token incorrecto bloquean la IP 15 minutos.

## Cómo probar cada automatización (paso a paso)

Las pruebas se hacen desde **Configuración → "Probar automatizaciones"** (o con la URL con token, indicada en cada caso). Antes de empezar: completá `config.php` (SMTP/Telegram), guardá tu email de aviso en Configuración y tené al menos un cliente con un servicio.

**1. Email.** Configuración → *Probar email*. Debe llegar un mensaje "Prueba — Panel de cobros" (revisá spam). Si falla, el panel muestra el error del servidor SMTP (credenciales, puerto, etc.).

**2. Telegram.** Configuración → *Probar Telegram*. Te tiene que llegar el mensaje del bot. Error "chat not found" = no le escribiste primero al bot, o el chat_id está mal.

**3. Feriados.** Configuración → Feriados → *Importar* del año actual. Verificá que aparezcan (los "puentes" están marcados; quitá los que no quieras contar). Podés agregar uno a mano.

**4. Resumen del primer día hábil.**

1. En Configuración → "Probar automatizaciones" mirá la fecha del **primer día hábil** del mes y verificá que coincida con el calendario (fines de semana y feriados excluidos).
2. Apretá **Enviar resumen mensual ahora**. Genera los cargos del mes (si faltan) y envía el resumen por los canales activos. Esta ejecución **forzada no marca el mes como enviado**, así que el cron igual lo enviará el día que corresponda.
3. Por consola: `php resumen_mensual.php --forzar` (muestra el texto enviado). Por URL no se puede forzar: es a propósito, para que un token filtrado no dispare el envío a todos los usuarios.
4. Sin `forzar` se comporta como el cron real: un día no hábil responde "Hoy no es día hábil"; un día hábil con el resumen pendiente lo envía y marca el mes (en Configuración aparece "ya enviado"); si lo corrés de nuevo responde "ya se envió".

**5. Avisos de vencimiento.**

1. Creá un dominio de prueba que venza en 5 días (o un servicio anual con vencimiento en 5 días).
2. Configuración → *Enviar avisos de vencimiento ahora* (o por consola: `php avisos_vencimientos.php`). Llega un mensaje con ese vencimiento (corresponde el escalón de 7 días).
3. Apretalo otra vez: responde "No hay avisos nuevos" (no repite). Cada envío queda en Configuración → *Notificaciones enviadas*.
4. Para repetir la prueba, borrá esas filas de `notificaciones_log` en phpMyAdmin.

Regla de escalones: con 30/15/7/0 y faltando 10 días corresponde el aviso de "15"; cada escalón se manda **una sola vez por vencimiento**. Al renovar el dominio (o cobrar el servicio anual) el vencimiento cambia y vuelve a avisar en el ciclo siguiente.

**6. WhatsApp.**

1. En Configuración revisá la plantilla y cargá tu alias/CBU y tu nombre.
2. Elegí un cliente con **teléfono** y **deuda** (si no tiene cargos, Cargos → *Generar cargos del mes*).
3. En su ficha (o en "Clientes con deuda" del Inicio) aparece el botón **WhatsApp**. Al tocarlo se abre WhatsApp con el mensaje armado: detalle, total y alias.
4. El teléfono se normaliza a formato internacional argentino (ej. `11 5555-1234` → `5491155551234`). Para otros países cargalo con el código de país.

**7. Ajuste de precios.** Menú Precios → elegí clientes y porcentaje → *Ver vista previa* → revisá la tabla → *Aplicar ajuste*. Se actualizan los montos y queda un registro en el historial de precios de cada servicio. Los cargos ya generados no cambian.

## Notas de la Fase 2

- Un resumen o aviso se considera **enviado** si al menos un canal (email o Telegram) lo entregó. Si ninguno lo logra, no se marca y se reintenta (resumen: próximo día hábil; avisos: próxima corrida).
- Los avisos de una corrida van agrupados en **un solo mensaje**.
- Sin feriados cargados para el año, el resumen igual funciona (solo excluye fines de semana) pero lo advierte en el propio mensaje.
- Un token en la URL de `/cron/<tarea>` queda en los logs del servidor: usá la cabecera `X-Cron-Token` o, mejor, la versión por consola.

---

# Fase 3 — Reportes, exportar, portal del cliente y Mercado Pago

## Qué agrega

- **Reportes** (menú Reportes): ingresos por mes y por año (con gráfico), facturado vs cobrado vs pendiente, ranking de clientes por facturación y rentabilidad de dominios.
- **Exportar a CSV** (abre bien en Excel: UTF-8 con BOM, separador `;`, decimales con coma) de clientes, deudores, servicios, dominios, cargos, pagos y cada reporte.
- **PDF por impresión**: botón *Imprimir / PDF* en reportes, listados y en el **Resumen de cuenta** de cada cliente (ficha → *Resumen de cuenta*). El PDF se genera con "Guardar como PDF" del navegador, sin librerías.
- **Portal del cliente** (opcional): link único y secreto por cliente, revocable, donde ve su deuda y sus vencimientos.
- **Mercado Pago** (opcional): link de pago por cargo y cobro automático por webhook.

## Migración de la Fase 3

`install.php` ya instala todo. Si ya tenías la base instalada, importá **una vez** `privado/install/migracion_003.sql` en phpMyAdmin (después de la 001 y la 002). Agrega a `cargos` los datos del link de pago (`mp_cotizacion`, `mp_saldo`, `mp_creado_en`) y la tabla `mp_webhook_log` (registro de webhooks).

Archivos nuevos en `public_html/`: `portal.php`, `webhook_mp.php`, `descargar.php`. Volvé a subir también `privado/` completo.

## Cómo funcionan los reportes (criterios)

- **Ingresos** = pagos recibidos, por fecha de pago. Los pagos en USD se pasan a pesos con la cotización **guardada en cada pago**.
- **Facturado / ranking** = cargos del período (sin anulados). Los cargos en USD se pasan a pesos con el **dólar de hoy** (los cargos no guardan cotización), así que esos números en pesos son una referencia que se mueve con el dólar.
- **Rentabilidad de dominios** = precio al cliente − costo de renovación, por renovación anual, en pesos al dólar de hoy.

## Cómo probar los reportes, el CSV y el PDF

1. Con algunos cargos y pagos cargados, abrí **Reportes**. Verificá que el gráfico de ingresos muestre el mes de tus pagos y que la tabla de abajo coincida. (Si el gráfico no aparece pero las tablas sí, revisá que exista `assets/vendor/chartjs/chart.umd.min.js`.)
2. Cambiá de año con las flechas. Revisá el ranking y la rentabilidad de dominios.
3. Apretá **CSV** en una tabla (o *Clientes → Exportar CSV*). Abrilo en Excel con doble clic: tienen que verse bien las tildes y los montos con coma decimal. Los filtros del listado (búsqueda, estado, período) se respetan en el CSV.
4. **PDF:** en Reportes, *Clientes* o *Cargos* apretá **Imprimir / PDF**; en el diálogo elegí "Guardar como PDF". El menú y los botones no salen en la hoja.
5. **Resumen de cuenta:** ficha de un cliente → *Resumen de cuenta* → *Imprimir / guardar PDF*. Sale con tu nombre, el cliente, la deuda, los cargos pendientes, los últimos pagos y tu alias.

## Portal del cliente

**Activar y probar**

1. **Configuración** → tildá *Activar el portal* → *Guardar*. Verificá que `app.url` en `config.php` sea la URL real (con `https://`), porque se usa para armar los links.
2. Ficha de un cliente → tarjeta **Portal del cliente** → *Generar link del portal*. Aparece un link con un código largo (48 caracteres).
3. Abrí ese link en una **ventana privada** (sin sesión del panel). Tiene que mostrar solo: su saldo, cargos pendientes, vencimientos de dominios y servicios anuales, últimos pagos y tu alias. Nunca notas internas, costos ni otros clientes.
4. **Revocar:** apretá *Revocar* y recargá el link: tiene que dar "no es válido". *Regenerar link* invalida el anterior y crea uno nuevo.
5. Probá un link inventado (cambiá una letra): da el mismo error, y tras 20 intentos inválidos en 15 minutos desde la misma IP se bloquea temporalmente.
6. **Desactivar el portal** en Configuración corta todos los links a la vez.
7. En la plantilla de WhatsApp podés usar la variable `{portal}` para mandar el link junto con el cobro.

## Mercado Pago

### Cómo funciona

1. En la ficha de un cliente, en *Cargos pendientes*, apretás **Generar link** y se crea un link de pago de Mercado Pago para ese cargo (aparece para copiar y pegar en un mensaje). En el portal del cliente aparece el botón **Pagar con Mercado Pago**.
2. Cuando el cliente paga, Mercado Pago avisa al **webhook** del panel. El panel **no se fía del aviso**: consulta ese pago directamente a la API de Mercado Pago con tu access token y, si está *aprobado* y corresponde a un cargo, registra el pago (medio "Mercado Pago") y marca el cargo como pagado. Si era un servicio anual, su vencimiento pasa a +1 año.
3. Cada pago se registra una sola vez aunque Mercado Pago repita la notificación.
4. Mercado Pago cobra en pesos. Un cargo en **USD** se cobra convertido con el dólar vigente al crear el link (queda guardado para imputar el pago exacto). Los links se reutilizan 24 horas si el saldo no cambió.

### Paso 1 — Obtener el access token

1. Entrá a [mercadopago.com.ar/developers](https://www.mercadopago.com.ar/developers) con tu cuenta de Mercado Pago → **Tus integraciones** → **Crear aplicación**.
2. Elegí el producto **Checkout Pro**. Ponele un nombre (ej. "Panel de cobros").
3. Al crear la aplicación, Mercado Pago te da automáticamente las **credenciales de prueba** (Access Token y Public Key). El panel solo necesita el **Access Token** (la Public Key no se usa).
4. Las **credenciales de producción** están en la misma aplicación; para activarlas Mercado Pago te pide completar los datos de tu negocio. Son las que usás para cobrar de verdad.
5. Pegalo en `privado/config.php`:
   ```php
   'mercadopago' => [
       'access_token'   => 'EL_ACCESS_TOKEN',
       'webhook_token'  => 'UN_STRING_ALEATORIO_DE_32_O_MAS_CARACTERES',
       'webhook_secret' => '',   // lo completás en el paso 2
   ],
   ```
   El `webhook_token` lo inventás vos (letras y números, sin espacios). El access token es como una contraseña: no lo compartas ni lo subas a ningún repositorio.
6. Configuración → *Activar links de pago y cobro automático por webhook* → *Guardar* → **Probar conexión con Mercado Pago**. Tiene que mostrar el nombre y el id de la cuenta dueña del token y la URL del webhook.

**Modo prueba o real:** lo define solamente qué access token hay en `config.php` (el de prueba o el de producción). No hay otro interruptor.

### Paso 2 — Configurar el webhook

La URL a usar (también te la muestra Configuración) es:

```
https://TUDOMINIO.com/webhook/mp/TU_TOKEN_DE_USUARIO   (la ves en Configuración)
```

- Cada link de pago que genera el panel ya incluye esa URL como `notification_url`, así que funciona aunque no configures nada más. Requisito: que `app.url` sea `https://...`.
- **Recomendado, además:** en Tus integraciones → tu aplicación → **Webhooks** → **Configurar notificaciones**: pegá la URL de arriba (modo de prueba y/o producción), elegí el evento **Pagos** y guardá. Mercado Pago te muestra una **clave secreta**: copiala en `webhook_secret` de `config.php` y el panel pasará a validar también la firma `x-signature` de cada aviso.
- Mercado Pago espera una respuesta 200 en menos de 22 segundos y reintenta hasta 8 veces si no la recibe.

### Paso 3 — Probar en modo de prueba (sin plata real)

1. En Tus integraciones → **Cuentas de prueba**, creá una cuenta **Vendedor** y una **Comprador** de prueba. Anotá el usuario y la contraseña de cada una.
2. Poné en `config.php` el **Access Token de prueba** de tu aplicación y activá Mercado Pago en Configuración (*Probar conexión* tiene que andar).
3. Creá un cliente de prueba con un servicio (o cargo) en pesos, generá los cargos y en su ficha apretá **Generar link** en ese cargo.
4. Abrí el link en una **ventana de incógnito** e iniciá sesión con la **cuenta compradora de prueba** (no con tu cuenta real ni con la vendedora). Si te pide verificar el email, usá los **últimos 6 dígitos del User ID** de esa cuenta de prueba.
5. Pagá con una tarjeta de prueba: Visa `4509 9535 6623 3704`, CVV `123`, vencimiento `11/30`, titular **APRO** (resultado aprobado) y DNI `12345678`. Otros nombres de titular simulan otros resultados (por ejemplo **OTHE** = rechazado, **CONT** = pendiente).
6. Esperá unos segundos y mirá:
   - **Configuración → Notificaciones enviadas**, abajo: *Webhooks de Mercado Pago recibidos* debe mostrar el pago con resultado **registrado**.
   - La **ficha del cliente**: el cargo pasó a *pagado* y en el historial de pagos aparece el pago con medio "Mercado Pago" y la nota `Mercado Pago #<número>`.
7. **Probar la protección contra duplicados:** reenviá la notificación desde el panel de Webhooks de Mercado Pago (o repetí la llamada); el resultado debe ser **duplicado** y no se registra un segundo pago.
8. **Probar el rechazo de pagos no aprobados:** pagá otro cargo con titular **OTHE**; el webhook queda como **ignorado** (`Estado: rejected`) y el cargo sigue pendiente.
9. Probar la URL a mano: `https://TUDOMINIO.com/webhook/mp/xxxxxxxxxxxxxxxx` con un token inventado → 403; con el token correcto y sin datos → responde "ignorado". Si el panel de Webhooks de Mercado Pago ofrece *simular notificación*, usala: como el pago simulado no existe, queda registrado como **no_encontrado** (es lo esperado y confirma que el webhook es alcanzable).

Si el pago se hizo pero el log de webhooks está vacío, Mercado Pago no pudo llegar a tu URL: revisá que sea `https`, que el token de la URL coincida con `webhook_token`, que `app.url` esté bien y que Mercado Pago tenga cargada la URL (paso 2). Si aparece `firma_invalida`, el `webhook_secret` no corresponde a la aplicación/modo que está enviando los avisos.

### Paso 4 — Pasar a producción

1. En Tus integraciones activá las credenciales de producción de la aplicación.
2. Reemplazá `access_token` en `config.php` por el **Access Token de producción** y, si usás firma, el `webhook_secret` del webhook de **producción**.
3. Configuración → *Probar conexión con Mercado Pago* (debe mostrar tu cuenta real).
4. Hacé un cobro real chico (un cargo de prueba de un monto bajo, pagado por vos con tarjeta o saldo) y verificá que el webhook lo registre; después devolvelo desde Mercado Pago y anulá/ajustá el pago en el panel si hace falta.
5. Recién ahí usá los links con clientes. Los links generados en modo prueba no sirven en producción: usá **Renovar link** en los cargos que ya tuvieran uno.

## Notas de seguridad de la Fase 3

- `/exportar/<tipo>.csv` exige sesión iniciada y solo lee datos.
- El portal usa un token de 192 bits, limita intentos inválidos y envía `Referrer-Policy: no-referrer` para que el link no se filtre a otros sitios. Quien tenga el link ve esa cuenta: mandalo solo al cliente.
- El webhook valida un token en la URL, la firma (si la configurás) y consulta el pago a la API, así que un aviso falso no puede marcar nada como pagado.
- Si un pago de Mercado Pago llega por más que el saldo del cargo, el sobrante queda como "pago sin imputar" (saldo a favor) en la ficha.

---

# Interfaz, app en el celular y versiones

La interfaz es un sistema de diseño propio: **un solo CSS** (`public_html/assets/css/app.css`) con variables CSS (tokens), tema oscuro por defecto y tema claro (el switch está en el menú; la elección se guarda en el navegador y, si no elegiste, se sigue la del sistema). Sin frameworks, sin build.

- **Escritorio (≥ 1024 px):** menú lateral fijo que se puede colapsar a íconos.
- **Celular:** barra inferior (Inicio, Clientes, Cobros, Vencimientos, Más), "Más" abre el menú como drawer, y el botón "+" abre las acciones rápidas (registrar pago, nuevo cliente, servicio o dominio). "Registrar pago" abre una hoja desde abajo: elegís cliente, el monto ya viene sugerido con su deuda, elegís el medio y guardás.
- **Fuentes:** Inter y JetBrains Mono se sirven desde el propio proyecto (`assets/fonts/`, solo los pesos 400/500/600/700, subconjunto latino, licencia OFL). No se hace ningún pedido a Google, y la política de seguridad (CSP) no permite `fonts.googleapis.com` ni `fonts.gstatic.com`.
- **Íconos:** Lucide (licencia ISC), copiados al proyecto como un solo archivo (`assets/img/iconos.svg`).
- **Impresión / PDF:** siempre sale en fondo blanco, aunque tengas el tema oscuro activo.
- **No se carga nada de terceros**: ni fuentes ni Chart.js. Chart.js 4.4.1 (MIT) está copiado en `assets/vendor/chartjs/` con su licencia y se carga solo en Reportes, con `?v=` como el resto. La CSP solo permite scripts, estilos, fuentes e imágenes del propio sitio (`script-src 'self'`).

## Instalar la app en el celular (PWA)

Requisito: el panel tiene que abrirse con **HTTPS** (activá el SSL en hPanel).

**Android (Chrome):**
1. Abrí `https://TUDOMINIO.com/login` e ingresá.
2. Menú ⋮ → **Instalar app** (o **Agregar a la pantalla principal**).
3. Confirmá. Queda el ícono de Moscode (`>_`) en el celular y se abre a pantalla completa.

**iPhone (Safari; tiene que ser Safari, no Chrome):**
1. Abrí `https://TUDOMINIO.com/login` e ingresá.
2. Botón **Compartir** (cuadrado con flecha) → **Agregar a pantalla de inicio**.
3. Dejá el nombre "Moscode" y tocá **Agregar**.

Notas:
- Sin internet se muestra una pantalla de "sin conexión". **Por seguridad no se guarda ninguna página ni dato de clientes en el celular**: el service worker solo guarda esa pantalla estática.
- El portal del cliente (`/portal/<token>`) no es instalable, a propósito.
- Si ya habías instalado una versión anterior, no hace falta desinstalarla: la primera carga reemplaza el service worker viejo.

## Subir una versión nueva (en cada deploy)

Los links a CSS, JS, íconos, manifest y el service worker llevan un número de versión (`app.css?v=2026.10.02-1`). Ese número está **en un solo lugar**:

`privado/includes/version.php`

```php
define('APP_VERSION', '2026.10.02-1');
```

**En cada deploy que cambie CSS, JS, íconos o fuentes:**
1. Cambiá ese número (formato sugerido `AAAA.MM.DD-N`, con N = nro. de deploy del día; ej. `2026.10.15-1`, y si subís dos veces el mismo día `2026.10.15-2`).
2. Subí los archivos, **incluido `version.php`**.
3. Listo. Al abrir la app en el celular:
   - las páginas ya piden los assets con la versión nueva (el HTML no se cachea), así que cargan lo nuevo;
   - el navegador detecta que `/sw.js` cambió, instala el service worker nuevo y borra el caché viejo;
   - si tenías la app abierta con la versión anterior, aparece un aviso **"Hay una versión nueva de la app" → Actualizar**.

**Si te olvidás de subir la versión:** los celulares pueden seguir mostrando el CSS/JS anterior hasta 7 días (es el tiempo de caché configurado en `.htaccess`). Es lo único que hay que acordarse de hacer en cada deploy.

**Si cambiás un archivo de fuente:** tienen nombre fijo y 1 año de caché. Cambiale el nombre al archivo (y la ruta en `app.css`) o, en un caso apurado, forzá la recarga en el navegador.

**Comprobar que anda:** abrí `https://TUDOMINIO.com/sw.js` en el navegador: tiene que mostrar `const VERSION = "…"` con tu número. Y en el código fuente de cualquier pantalla, los `<link>` y `<script>` tienen `?v=` con el mismo número.

## Archivos de esta capa

| Archivo | Para qué |
|---|---|
| `public_html/assets/css/app.css` | Todos los estilos (tokens, componentes, pantallas, impresión) |
| `public_html/assets/js/app.js` | Menú/drawer, hojas, modal de confirmación, avisos (toasts), pestañas, validación por campo, service worker |
| `public_html/assets/js/tema.js` | Tema oscuro/claro sin parpadeo (se carga en el `<head>`) |
| `public_html/assets/fonts/` | Inter y JetBrains Mono (woff2) + licencias |
| `public_html/assets/vendor/chartjs/` | Chart.js 4.4.1 (minificado) + licencia MIT; gráficos de Reportes |
| `public_html/assets/img/` | Logo, íconos de la app (PNG 32/180/192/512 y *maskable*), sprite de íconos |
| `privado/paginas/manifest.php`, `sw.php` (se sirven en `/manifest.webmanifest` y `/sw.js`) | Manifest y service worker generados con la versión central |
| `public_html/offline.html` | Pantalla de "sin conexión" |
| `privado/includes/version.php` | **Versión central** |
| `privado/includes/ui.php` | Ayudas de presentación (íconos, montos, fechas, chips, `asset()`) |


---

# Multiusuario, cuotas y mensaje de WhatsApp nuevo (versión 2026.10.02-2)

## Qué cambia

- **Cada usuario gestiona sus propios clientes**, servicios, dominios, cargos, pagos, planes, avisos y configuración. Nadie ve datos de otro, ni cambiando ids en la URL, ni en CSV, impresión, reportes, portal o webhook. **El administrador tampoco ve clientes ajenos**: solo administra cuentas.
- **Roles**: `admin` y `usuario`. No hay registro público: solo el admin crea, desactiva y resetea cuentas (menú **Usuarios**).
- **Configuración por usuario**: datos propios, alias/CBU, email de aviso, SMTP, Telegram, Mercado Pago, plantilla de WhatsApp, días de aviso, tipo de dólar y tema. Las credenciales (SMTP, Telegram, access token y clave del webhook) se cargan en **Configuración → Canales y credenciales** y se guardan **cifradas**.
- **Globales**: la cotización del dólar (se baja una sola vez; cada usuario elige el tipo; una cotización manual es personal), la fuente de la cotización (la elige el admin en Usuarios) y los feriados (solo los edita el admin).
- **Ventas en cuotas** (menú Cuotas) y **plantilla de WhatsApp nueva** (ver abajo).

## Cómo se garantiza el aislamiento

Toda consulta a una tabla con datos de usuario pasa por `q()`, que **se niega a ejecutarla** si no lleva el filtro `usuario_id = {U}` (y los INSERT, la columna `usuario_id`). Un olvido da un error, nunca una fuga. Las pocas consultas globales legítimas (crons que recorren usuarios, buscar al dueño de un token) están marcadas con `sin_filtro('motivo', …)`.

Auditor estático, para correr antes de cada deploy:

    php privado/scripts/auditar_aislamiento.php

Revisa todo el código y lista los problemas y las consultas globales declaradas (sale con código 1 si algo falla).

## Clave maestra (obligatoria para guardar credenciales)

En `privado/config.php`, bloque `seguridad`:

    php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"

Pegá el resultado en `'seguridad' => ['clave_maestra' => '…']` y **guardá una copia aparte**: si se pierde, cada usuario tiene que volver a cargar sus credenciales. Los bloques `smtp`, `telegram` y `mercadopago` de `config.php` ya no se usan (ver migración).

## Actualizar una instalación existente

1. Backup de la base (phpMyAdmin → Exportar).
2. Subí los archivos nuevos (sin pisar `config.php`) y agregá `seguridad.clave_maestra` a `config.php`.
3. phpMyAdmin → SQL: importá `privado/install/migracion_005.sql` (una sola vez). Tus datos y tu configuración pasan al usuario `amoscato` (o al de id más bajo), que queda como **admin**.
4. Por SSH (o como Cron Job de una sola vez): `php privado/scripts/migrar_005.php`. Copia tus credenciales de `config.php` (cifradas) a tu usuario. Después podés borrar de `config.php` los bloques `smtp`, `telegram` y `mercadopago`.
5. El webhook de Mercado Pago ahora es **por usuario**: la URL personal está en Configuración. La URL vieja (con `mercadopago.webhook_token`) sigue funcionando y llega a tu cuenta.
6. Una instalación nueva con `install.php` ya trae todo (no necesita la migración).

## Crear usuarios

- **Desde la pantalla Usuarios** (admin): "Nuevo usuario". Se muestra una sola vez la contraseña temporal.
- **Por consola**:

      php privado/scripts/crear_usuario.php --usuario=j.t --nombre="J.T." --rol=usuario --clave="UnaClaveTemporal123"

  Si falta `--clave`, genera una de 12 caracteres y la muestra. Argumentos: `--usuario` (obligatorio), `--nombre`, `--email`, `--rol` (`usuario` por defecto o `admin`), `--clave`. No pisa un usuario existente; solo corre por consola (por web responde 403). La cuenta nace con la configuración por defecto, sin credenciales ni clientes.
- **En Hostinger**: por SSH, o en hPanel → Avanzado → **Cron Jobs** como tarea de una sola vez con el comando completo (`/usr/bin/php /home/USUARIO/.../privado/scripts/crear_usuario.php --usuario=… --clave=…`); la salida llega por el mail del cron. Borrá el cron después.

## Contraseñas

- La contraseña de una cuenta nueva o reseteada es **temporal**: al iniciar sesión el panel obliga a cambiarla antes de hacer cualquier otra cosa (mínimo 10 caracteres, se escribe dos veces).
- **Mi cuenta** → cambiar contraseña (actual + nueva ×2). Al cambiarla, o cuando el admin la resetea o desactiva la cuenta, **se cierran las demás sesiones**.
- Se guardan con `password_hash` (nunca en texto plano).

## Crons con varios usuarios

Los mismos tres crons de siempre: ahora recorren a los usuarios activos y cada uno recibe sus avisos y su resumen por **sus** canales, solo con **sus** clientes. El dólar se actualiza una vez para todos (cada tipo en uso). No hay que cambiar los Cron Jobs.

## Portal y Mercado Pago por usuario

El link del portal identifica al cliente y a su dueño: muestra el nombre y el alias de ese usuario, y "Pagar con Mercado Pago" usa el access token de ese usuario. Si el dueño desactiva el portal o su cuenta, el link deja de funcionar. Cada usuario tiene su URL de webhook (Configuración).

## Mensaje de cobro por WhatsApp

Plantilla por defecto:

    Hola {contacto}! Te paso el cobro de {servicios} de este mes para {cliente}{descripcion}:
    {lineas}
    Resto a favor: {saldo_favor}
    Total a abonar: {total_a_abonar}
    Decime cómo preferís pagarlo: efectivo o transferencia. Si es transferencia, el alias es {alias}.
    ¡Gracias!

Variables: `{contacto}` (nombre de pila del contacto, o el nombre del cliente), `{cliente}`, `{servicios}`, `{descripcion}` (" (texto)" solo si hay un único servicio con descripción; campo nuevo opcional en el servicio), `{lineas}` (detalle y total; con USD incluye la cotización), `{saldo_favor}`, `{total_a_abonar}`, `{alias}`, `{yo}`, `{portal}`. Siguen valiendo `{detalle}`, `{total}` y `{empresa}`. Una plantilla que ya habías editado **no se pisa** (y conserva el significado viejo de `{cliente}` hasta que uses `{contacto}`). En Configuración hay un botón **Restaurar plantilla por defecto**. Montos: `AR$ 61.800` (sin decimales si son `,00`), cotización `$1545`.

## Ventas en cuotas

Para cobros únicos (desarrollo de una web, un sistema…). Menú **Cuotas**, o "Nueva venta en cuotas" desde el botón "+" o la ficha del cliente.

- Se carga concepto, descripción, total, moneda, cantidad de cuotas, frecuencia (mensual por defecto) y fecha de la primera. El panel muestra una **vista previa editable**: total ÷ cuotas a 2 decimales, la última absorbe la diferencia (USD 550 en 3 = 183,33 + 183,33 + 183,34); si editás montos, la suma tiene que dar el total.
- "Ya cobré las primeras N cuotas" (con fecha y medio) registra esos pagos e imputa.
- Cada cuota es un **cargo normal** ("Desarrollo web — Cuota 2/3"), con cuenta corriente, pagos parciales y cotización. **Solo es deuda desde su vencimiento**: las futuras figuran como "a vencer" (no suman a la deuda, ni al resumen ni al mensaje de WhatsApp). Un pago automático nunca cubre cuotas futuras; sí se pueden pagar a mano.
- Acciones: **pagar por adelantado** cuotas tildadas, **editar pendientes** (redistribuye el saldo; el total pendiente no cambia) y **cancelar** (las pendientes se anulan; lo cobrado queda).
- Se ven en la ficha del cliente (pestaña Cuotas), en la lista general (progreso, cobrado, saldo, próxima cuota), en Vencimientos y el dashboard (próximos 30 días), en el resumen del primer día hábil y en los avisos. Reportes: los ingresos se cuentan en el mes del **pago**, y hay una tarjeta "Saldo a cobrar en cuotas".

## Pruebas hechas

Con MariaDB real y dos usuarios de prueba (más un admin): aislamiento total en pantallas, acciones, CSV, impresión, reportes, portal, webhook y configuración con ids ajenos en la URL; flujo de contraseña temporal (crear con el script → login → obliga a cambiarla → la nueva funciona, la temporal no); caso real USD 550 en 3 cuotas con la primera pagada; migración 005 sobre una base con datos; regresión de las fases anteriores y auditor estático sin problemas.


---

# URLs limpias y "Mantener sesión iniciada" (versión 2026.10.02-3)

## Qué hay que hacer en Hostinger al actualizar

1. Subí todo de nuevo **sin pisar `privado/config.php`**: `public_html/` (ahora solo tiene `index.php`, `.htaccess`, `assets/`, `offline.html` y los íconos; **borrá del servidor** los viejos `login.php`, `portal.php`, `webhook_mp.php`, `cron.php`, `descargar.php`, `install.php`, `manifest.php` y `sw.php`, que se movieron a `privado/paginas/`) y `privado/` completo.
2. Importá `privado/install/migracion_006.sql` en phpMyAdmin (una vez; crea la tabla de sesiones recordadas). Una instalación nueva ya la trae.
3. **No hay que tocar nada más para que sigan funcionando** los links ya enviados y lo ya configurado: `portal.php?t=…`, `webhook_mp.php?token=…` y `cron.php?tarea=…` siguen respondiendo igual (sin redirección). Conviene, igual, pasar de a poco a las nuevas:
   - **Webhook de Mercado Pago**: la URL nueva es `https://TUDOMINIO.com/webhook/mp/<tu token>` (está en Configuración → Portal y Mercado Pago). Es la misma que la vieja pero más limpia; no hace falta cambiarla ya, pero si la cambiás en el panel de Mercado Pago (Tus integraciones → Webhooks), probala con "Simular notificación".
   - **Cron Jobs** por URL: `https://TUDOMINIO.com/cron/<tarea>?token=…` (los de consola no cambian).
   - Los links del portal que generás desde ahora (y los de WhatsApp con `{portal}`) son `https://TUDOMINIO.com/portal/<token>`; los viejos siguen andando.
4. Los favoritos y links viejos con `index.php?p=…`, `login.php` o `descargar.php?tipo=…` redirigen (301) a la URL nueva.
5. **El `.htaccess` de `public_html/` es obligatorio** (manda todo al controlador frontal, fuerza HTTPS y bloquea cualquier otro `.php`). Hostinger (Apache/LiteSpeed) lo respeta; si tu sitio está en una subcarpeta, el panel arma los links solo (se calcula desde dónde está `index.php`), pero `offline.html` y `app.url` de `config.php` tienen que apuntar a esa carpeta.
6. La carpeta `privado/sesiones/` se crea sola (con su `.htaccess` que la bloquea). Si `privado/` no es escribible, el panel usa la carpeta de sesiones del sistema y la cookie de "recordarme" recrea las que el hosting borre.

> Importante: probé las rutas con el servidor de desarrollo de PHP imitando el `.htaccess` (no hay Apache en esta máquina). Las reglas de reescritura son estándar, pero **la primera vez que lo subas** abrí `/`, `/clientes`, `/login`, un `.php` viejo (`/login.php` → redirige) y `/assets/css/app.css` para confirmar que el hosting las aplica.

## Mapa de URLs

| URL | Qué es |
|---|---|
| `/` · `/login` · `/salir` (POST) | Inicio · ingreso · cerrar sesión |
| `/clientes` · `/clientes/nuevo` · `/clientes/12` · `/clientes/12/editar` · `/clientes/12/resumen` | Clientes (el resumen es imprimible) |
| `/clientes/12/servicios/nuevo` · `/servicios/5/editar` | Servicios |
| `/clientes/12/dominios/nuevo` · `/dominios/8/editar` | Dominios |
| `/clientes/12/cuotas/nueva` · `/cuotas` · `/cuotas/3` · `/cuotas/3/editar` | Ventas en cuotas |
| `/cobros` · `/pagos/nuevo?cliente_id=12` · `/vencimientos` · `/reportes` · `/precios` · `/dolar` | Cobros y gestión |
| `/configuracion` · `/mi-cuenta` · `/feriados` · `/notificaciones` | Configuración y cuenta |
| `/usuarios` · `/usuarios/nuevo` | Solo admin |
| `/exportar/clientes.csv` (y `deudores`, `servicios`, `dominios`, `cargos`, `pagos`, `rep_*`) | CSV (con sesión) |
| `/portal/<token>` · `/portal/<token>/pagar/<cargo>` | Portal del cliente (sin sesión) |
| `/webhook/mp/<token>` · `/cron/<tarea>?token=…` | Webhook de Mercado Pago · crons por URL |
| `/acciones/<nombre>` | Acciones de los formularios (solo POST con token CSRF) |
| `/manifest.webmanifest` · `/sw.js` | PWA |

Las rutas están declaradas en **un solo archivo**: `privado/includes/rutas.php` (vistas con sus `{parámetros}` numéricos, páginas especiales y las URLs viejas que se mantienen). Todos los links del panel se arman con `url('cliente', ['id' => 12])` → `/clientes/12`, `url_accion('cliente_guardar')`, `url_exportar('clientes')`; no hay URLs escritas a mano. Para agregar una pantalla: creá `privado/vistas/<nombre>.php` y agregá su ruta en `RUTAS_VISTAS`.

Ninguna ruta saltea el login, el CSRF ni el filtro por usuario: las vistas y acciones pasan todas por `privado/paginas/panel.php` (que exige sesión, contraseña definitiva y, en las acciones, token CSRF), y las consultas siguen verificadas por `q()`. Una URL que no existe muestra una **404 propia** con el diseño de la app (con el menú si estás logueada). Un `GET` a una acción o un `POST` a una vista da 405.

## Mantener sesión iniciada

Pensado para el celular, sobre todo con la app instalada (PWA): quedás logueada sin volver a poner la contraseña.

- En el login hay una casilla **"Mantener sesión iniciada"**, marcada por defecto.
- Se guarda una cookie `panel_recordar` (HttpOnly, Secure, SameSite=Lax, path `/`) con **selector + validador**; en la base (`sesiones_recordar`) solo queda el **hash** del validador, el dispositivo, la IP, cuándo se creó, el último uso y cuándo vence. Dura **90 días desde el último uso** (se renueva); se cambia con `seguridad.recordar_dias` en `config.php` (1 a 365).
- Cuando la sesión de PHP no existe (cerraste el navegador, la app se reinició, el hosting la borró) o venció por inactividad, la cookie crea una sesión nueva **sin pasar por el login**. El validador **rota en cada uso**; el anterior sigue valiendo 60 segundos para que varios pedidos juntos del celular no se pisen.
- **Cookie robada**: si llega un selector válido con un validador equivocado, se **revocan todas las sesiones de ese usuario** (se borran sus tokens y sube `sesion_version`, que cierra también las sesiones de PHP abiertas) y hay que volver a ingresar con la contraseña. Queda un aviso en `privado/logs/php-error.log`.
- Se revocan todos los tokens al **cambiar la contraseña** (el dispositivo donde la cambiás sigue recordado, con un token nuevo), al **resetearla** el admin y al **desactivar** la cuenta. **Salir** borra el token de ese dispositivo.
- Con una **contraseña temporal** funciona igual: la cookie recrea la sesión, pero el panel te lleva a la pantalla de cambio obligatorio.
- **Mi cuenta → Dispositivos con sesión iniciada**: navegador y sistema, último uso e IP, con un botón para cerrar cada uno (la sesión que ya estaba abierta en ese dispositivo cae en su próximo pedido) y otro para **cerrar todas las demás**.
- Las sesiones de PHP se guardan en `privado/sesiones/` (carpeta propia, bloqueada por web), con `gc_maxlifetime` de 8 h + 1 día. En hosting compartido el recolector de basura de PHP comparte la carpeta del sistema con otros sitios y puede borrar sesiones antes de tiempo; con la carpeta propia no pasa, y si igual pasara, la cookie las recrea.
- El portal del cliente no usa sesión ni cookies: no se ve afectado.

### Instalar la app en el celular (PWA)

- **Android (Chrome)**: abrí `https://TUDOMINIO.com/login`, iniciá sesión con la casilla marcada y usá el menú ⋮ → *Instalar app* (o *Agregar a la pantalla principal*). Se abre sin barra del navegador y mantiene la sesión al cerrarla y volver a abrirla. Requisitos que ya cumple: HTTPS, manifest (`/manifest.webmanifest` con `start_url` `/`, íconos 192/512 y *maskable*) y service worker (`/sw.js`, alcance `/`). Edge verifica que no haya errores de instalabilidad.
- **iPhone (Safari)**: compartir → *Agregar a inicio*. **Ojo: en iPhone la app instalada NO comparte las cookies con Safari**: tenés que **iniciar sesión una vez adentro de la app instalada** (con "Mantener sesión iniciada" marcada); a partir de ahí queda recordada. Si la borrás y la volvés a instalar, hay que iniciar sesión de nuevo.
- Si ya tenías la app instalada de antes, no hace falta reinstalarla: la primera vez que se abra reemplaza el service worker viejo (`/sw.php` sigue respondiendo para eso) y, si las cookies cambiaron, te pide iniciar sesión una vez.

## Pruebas hechas

Con un navegador automatizado (Edge) y MariaDB real: iniciar sesión, **cerrar el navegador por completo y volver a entrar sin login**; **borrar todas las sesiones de PHP del servidor** y seguir adentro; rotación del validador; **robo de token simulado** (cookie vieja fuera de la ventana de gracia → se revocan todas las sesiones, también la de la víctima); revocación al cambiar/resetear la contraseña y al desactivar; cierre de dispositivos desde Mi cuenta; contraseña temporal con cookie; instalabilidad de la PWA (sin errores en Edge), service worker en `/sw.js` y pantalla sin conexión. URLs: las 25 rutas, 301 de las URLs viejas, portal/webhook/cron/service worker/manifest viejos sin redirección, 404 con y sin sesión, acceso directo a `.php` bloqueado, ninguna ruta sin login ni CSRF, y un rastreo de todos los links internos del panel (sin links viejos ni relativos). Auditor de aislamiento y pruebas de aislamiento entre usuarios sin cambios.

---

# Email sin SMTP: "Servidor (mail de PHP)" (versión 2026.10.02-4)

En **Configuración → Notificaciones → Método de envío del email** cada usuario elige:

- **Servidor (mail de PHP)** — por defecto. Usa el envío del propio hosting: **no hace falta crear ni configurar una casilla**, ni usuario ni contraseña. Solo se piden el **email de aviso** (adónde llegan los mensajes) y, opcionalmente, el **remitente**. Si lo dejás vacío es `avisos@` + el dominio de `app.url` (ej. `avisos@tudominio.com`); no necesita existir como casilla, pero conviene que sea de tu dominio.
- **SMTP** — como antes (Configuración → Canales y credenciales). Quien ya tenía SMTP cargado antes de esta versión sigue con SMTP hasta que cambie la opción.

Los mensajes salen con `From`, `Reply-To`, `Date`, `Message-ID`, `Content-Type: text/plain; charset=UTF-8` y el parámetro `-f` con el remitente (el "return-path" coincide con el From). **Probar email** funciona con los dos métodos; si `mail()` devuelve `false` muestra el error con la causa probable (envío desactivado o limitado por el hosting, remitente que no es de tu dominio) en vez de decir que salió bien. Ojo: que `mail()` devuelva `true` solo significa que el hosting aceptó el mensaje; la entrega final depende de Gmail.

## Si llegan a spam (pasa la primera vez)

Los mails enviados por `mail()` desde un hosting compartido suelen caer en **spam** al principio, porque no llevan la reputación de una casilla propia. Para evitarlo en Gmail (es el destino de los avisos):

1. Abrí el primer mensaje que haya caído en **Spam** y tocá **"No es spam"** (o "Informar que no es spam").
2. Creá un filtro: en Gmail, ⚙ → **Ver todos los ajustes** → **Filtros y direcciones bloqueadas** → **Crear un filtro nuevo**. En **De** poné el remitente (por defecto `avisos@tudominio.com`), o en **Asunto** poné `Resumen de cobros`. → **Crear filtro** → tildá **Nunca enviar a Spam** (y, si querés, **Marcar como importante**) → **Crear filtro**.
3. Agregá el remitente a tus **Contactos** de Google: ayuda a que no lo trate como sospechoso.

Para que llegue mejor desde el principio: usá un remitente **de tu propio dominio**, y en hPanel → Correos (o DNS) verificá que el dominio tenga registros **SPF** y **DKIM** (Hostinger los agrega solo en los dominios que administra). Si aun así no llega, usá el método **SMTP** con una casilla del dominio.


---

# Seguridad: auditoría, 2FA, backups y registro de actividad (versión 2026.10.02-5)

## Qué se revisó y qué se corrigió

Se auditó todo el código (autenticación y sesiones, base de datos y aislamiento, salida y navegador, tokens/servidor/configuración). Resumen de lo corregido, con la prueba que lo demuestra:

| Tema | Qué pasaba | Cómo quedó |
|---|---|---|
| **Bloqueo de login** | Cualquiera podía dejar afuera a otro usuario escribiendo su nombre 5 veces | Se bloquea el **par usuario + IP**; 20 fallos desde una IP bloquean esa IP; muchas IP contra una cuenta solo **demoran** la respuesta (nunca la bloquean) |
| **Existencia del usuario** | Un usuario inexistente tardaba el doble (2 bcrypt) | Un solo `password_verify` contra un hash constante |
| **Pagos de Mercado Pago perdidos** | Si el cargo ya estaba pagado o anulado, el pago aprobado se ignoraba (plata sin registrar) o MP reintentaba para siempre | Se registra **sin imputar** (saldo a favor), se avisa y se responde 200. Reembolsos y contracargos avisan para revisar |
| **Montos y fechas** | `1.234,56` se leía como 1,23; `1e999` pasaba; sin tope ni `sql_mode` | Lector estricto de montos, topes por campo, fechas 2000–2100, `sql_mode` estricto |
| **Errores** | Mensajes de SQL (tablas, columnas) llegaban a la pantalla; sin página de error propia | Mensajes genéricos; el detalle va a `privado/logs`; manejador global de errores; `display_errors` apagado desde el primer archivo |
| **Secretos en la sesión** | Los formularios con error guardaban contraseñas y tokens en el archivo de sesión | Se filtran los campos sensibles |
| **Cabeceras** | Faltaban en manifest, service worker, redirecciones y estáticos; sin HSTS | CSP estricta, HSTS (con HTTPS), Permissions-Policy, COOP/CORP, `nosniff`, `no-store` en todo lo dinámico, y las mismas desde `.htaccess` para los estáticos |
| **CSP** | `style-src 'unsafe-inline'` | **Sin `'unsafe-inline'`**: los 63 estilos en línea pasaron a clases y los 5 anchos de barra a `data-ancho` (se aplican por CSSOM, que la CSP permite) |
| **SMTP / `mail()`** | Un usuario podía apuntar el SMTP a una IP interna (SSRF) o inyectar opciones de sendmail por el remitente | Solo dominios públicos y puertos 25/465/587/2525, conexión a la IP ya verificada, TLS 1.2+, remitente con validación estricta |
| **Cotización** | Aceptaba cualquier valor entre 0 y 1.000.000 | Un salto de más del 20% queda **pendiente de confirmar** (ver abajo); el botón de actualizar tiene un límite de uno cada 5 minutos |
| **Instalador** | Cualquier error se tomaba como "instalación nueva" y no había protección contra dos instalaciones a la vez | Marca de instalación en la base, lock, sin detalles de error: no se puede reinstalar aunque el archivo esté |
| **Crons** | Podían correr dos a la vez (avisos duplicados); token en la URL sin límite | `GET_LOCK` por tarea, token por cabecera, tope de intentos, sin forzar por web, sin nombres de usuario en la salida web |
| **Pagos duplicados** | Doble clic = dos pagos, dos planes o un dominio renovado dos años | Formularios de un solo uso y `UPDATE` condicionado; cuotas, cancelación y pagos con transacción y filas bloqueadas |
| **Recordarme** | Una carrera entre pedidos paralelos podía cerrar todas las sesiones por error; sin vida máxima | `UPDATE` condicional, tope absoluto de 180 días |
| **Cifrado** | El dato cifrado no estaba ligado a su dueño; no había forma de rotar la clave | Cifrado con contexto (usuario + campo), varias claves con id, y script de rotación |
| **Portal** | Sin límite en `/pagar`; el destino de la redirección no se validaba | Límite propio y solo se redirige a `https` de Mercado Pago/Mercado Libre |
| **Datos** | Faltaban índices; el auditor no veía JOIN con coma | Índices nuevos (`migracion_007.sql`); el auditor rechaza JOIN con coma |
| **Archivos** | Un SQL con el hash de tu contraseña en `privado/install` | Borrado; `.gitignore` agregado; ejemplos sin claves |

Las pruebas de cada punto están descritas al final de este apartado.

## Anular un pago

En la ficha del cliente → pestaña **Pagos** → **Anular**. Pide un **motivo obligatorio** (5 a 255 caracteres). Al anular:

- el pago se **desimputa** de sus cargos (vuelven a deberse esos montos, y si era la renovación de un servicio anual, el vencimiento vuelve a su lugar);
- el pago **no se borra** (ni sus imputaciones): queda marcado con quién, cuándo y por qué, y deja de contar en saldos y reportes; el CSV de pagos lo incluye como "Anulado";
- queda en tu **actividad de cuenta** (Mi cuenta). El administrador **no** ve estos eventos (tienen datos de clientes);
- un pago de Mercado Pago anulado no se vuelve a registrar si Mercado Pago reenvía el aviso. Anular **no devuelve** la plata en Mercado Pago: si hay que reembolsar, se hace desde tu cuenta de Mercado Pago.

## Cotización del dólar: saltos grandes

La cotización automática se aplica sola si varía **20% o menos** contra la última aplicada. Si varía más (una devaluación puede ser real), queda **pendiente de confirmar**: se sigue usando la anterior, se avisa a los administradores por **sus** canales (email/Telegram) y aparece un aviso en el inicio y en **Dólar**, con los botones **Confirmar** y **Descartar** (solo admin). Si el mercado vuelve a valores normales antes de que decidas, la pendiente se descarta sola. Las cotizaciones que cargás a mano valen solo para tu cuenta.

## Verificación en dos pasos (2FA)

Opcional por usuario, compatible con Google Authenticator, Authy, Microsoft Authenticator y 1Password.

1. **Mi cuenta → Verificación en dos pasos → Activar.** Escaneá el QR (se dibuja en tu navegador con una librería local: el secreto no sale del panel) o cargá la clave a mano. Confirmá con tu contraseña y un código de 6 dígitos.
2. Se muestran **10 códigos de recuperación de un solo uso**: guardalos (gestor de contraseñas o impresos). No se vuelven a mostrar; si los perdés, generás un juego nuevo.
3. Al iniciar sesión, después de la contraseña se pide el código (o un código de recuperación). Cinco códigos incorrectos obligan a empezar de nuevo. Un código ya usado no sirve otra vez.
4. Con **"Mantener sesión iniciada"** el código se pide **solo al iniciar sesión**, no cada vez que abrís el panel.
5. Activar el 2FA cierra tus otras sesiones. Desactivarlo o regenerar los códigos pide contraseña y un código.
6. **Si un usuario perdió el celular**: el admin entra a **Usuarios → Quitar 2FA** (se cierran todas sus sesiones; el usuario puede volver a activarlo).

El secreto se guarda **cifrado** y ligado al usuario, y los códigos de recuperación solo como hash.

## Backups de la base

Un backup diario de **toda la base**, hecho con PHP (no depende de `mysqldump`), comprimido y **cifrado con una clave aparte** (`seguridad.clave_backup`). Sin esa clave el archivo no se puede abrir: se puede guardar o mandar por email sin exponer datos de clientes. Se guardan en `privado/backups/` (bloqueada por web) y se conservan los últimos **14**.

**Configurarlo (una vez):**

1. Generá la clave: `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` y pegala en `config.php → seguridad → clave_backup` (tiene que ser **distinta** de la maestra).
2. **Guardá una copia de esa clave fuera del servidor** (gestor de contraseñas). Sin ella los backups no se pueden restaurar.
3. hPanel → Avanzado → **Cron Jobs** → uno por día, de madrugada: `0 3 * * *` → `/usr/bin/php /home/USUARIO/domains/TUDOMINIO.com/privado/cron/backup.php`.
4. Menú **Backups** (solo admin): ver la lista, **Hacer un backup ahora**, y activar **"Enviarme el backup por email cada semana"** (los domingos, a tu email de aviso, cifrado; si pesa más de 20 MB no se manda).

**Restaurar un backup (paso a paso):**

1. Bajá el archivo `.sql.gz.enc` (hPanel → Administrador de archivos, o el que te llegó por email) y subilo a `privado/backups/` del servidor donde vas a restaurar.
2. Probá primero que se puede leer, sin tocar nada:
   `php privado/scripts/restaurar_backup.php --archivo=privado/backups/moscode-AAAAMMDD-HHMMSS-xxxx.sql.gz.enc --verificar`
3. Si restaurás en **otro servidor o base nueva**: creá la base vacía en hPanel, completá `config.php` (`db`, y las **mismas** `clave_maestra` y `clave_backup`: la maestra tiene que ser la misma para que se lean las credenciales cifradas) y corré:
   `php privado/scripts/restaurar_backup.php --archivo=… --confirmo`
4. Si restaurás **sobre la base actual** (pisa todo con el contenido del backup): agregá `--forzar`. Sin `--confirmo` no hace nada, y sin `--forzar` se niega a tocar una base que ya tiene tablas.
5. Si la clave no está en `config.php` podés pasarla por archivo (`--clave-archivo=ruta`) o por la variable de entorno `MOSCODE_CLAVE_BACKUP`; nunca por la línea de comandos.
6. Entrá al panel y revisá clientes y pagos. Si cambiaste de dominio, actualizá `app.url`.

**Si se pierde la clave de backup:** los backups viejos quedan ilegibles (no hay forma de recuperarlos). Generá una clave nueva, ponela en `config.php` y los backups nuevos ya salen con ella.

## Rotar la clave maestra

La clave maestra cifra las credenciales (SMTP, Telegram, Mercado Pago) y el secreto del 2FA. **Si se pierde**, esos datos no se pueden recuperar: cada usuario vuelve a cargar sus credenciales y a activar el 2FA. Para cambiarla **sin perder nada**:

1. `php privado/scripts/rotar_clave_maestra.php --generar` → imprime una clave nueva.
2. En `config.php → seguridad` poné las dos (sin sacar la vieja) y marcá la nueva como activa:
   `'claves' => ['k1' => '<la vieja>', 'k2' => '<la nueva>'], 'clave_activa' => 'k2'` (si usabas `'clave_maestra'`, esa es `k1`; podés borrar esa línea).
3. `php privado/scripts/rotar_clave_maestra.php --estado` → cuántos valores hay con cada clave.
4. `php privado/scripts/rotar_clave_maestra.php --recifrar` → vuelve a cifrar todo con la activa, en una transacción (o se cambian todos o ninguno; si alguno no se puede leer, no toca nada).
5. `--estado` otra vez: tiene que decir que todo está con la clave activa. **Recién ahí** borrá la vieja de `config.php`.

Nunca imprime claves ni valores. También convierte los valores del formato viejo (sin contexto) al nuevo.

## Registro de actividad de seguridad

- **Mi cuenta → Actividad de mi cuenta**: tus ingresos (correctos y fallidos), cambios de contraseña y de email, 2FA, dispositivos cerrados, cambios de credenciales (solo **qué** cambió, nunca los valores) y las anulaciones de pagos.
- **Actividad** (menú, solo admin): lo mismo de **todas las cuentas**, con filtro por cuenta y por evento, **sin** los eventos que tienen datos de clientes (como anular un pago). Incluye las acciones de administración: altas, bajas, reseteos de contraseña, 2FA quitado, cotizaciones confirmadas y backups.
- Se conserva 1 año (`cron/mantenimiento.php` limpia lo viejo).

## Cron Jobs (resumen con los comandos nuevos)

| Tarea | Frecuencia sugerida | Comando |
|---|---|---|
| Cotización del dólar | `0 * * * 1-5` | `/usr/bin/php /home/USUARIO/domains/TUDOMINIO.com/privado/cron/actualizar_dolar.php` |
| Resumen mensual | `0 8 * * *` | `…/privado/cron/resumen_mensual.php` |
| Avisos de vencimiento | `0 9 * * *` | `…/privado/cron/avisos_vencimientos.php` |
| **Backup de la base** | `0 3 * * *` | `…/privado/cron/backup.php` |
| **Mantenimiento** (limpia registros viejos) | `30 3 * * *` | `…/privado/cron/mantenimiento.php` |

Cada cron toma un bloqueo (`GET_LOCK`): si una corrida se demora y arranca la siguiente, la segunda sale sin hacer nada.

## Permisos recomendados en Hostinger

| Qué | Permiso |
|---|---|
| Carpetas | `755` |
| Archivos (`.php`, `.css`, `.js`…) | `644` |
| `privado/config.php` | **`600`** (si el sitio deja de andar, `640`) |
| `privado/logs/`, `privado/sesiones/`, `privado/backups/` | `700` (o `750`); son las únicas que tienen que ser escribibles |
| Cualquier cosa | **nunca `777`** |

En hPanel: Administrador de archivos → clic derecho sobre el archivo → **Permisos**. Con el layout recomendado (`privado/` **fuera** de `public_html`) nada de `privado/` es alcanzable por web.

## Checklist de seguridad para Hostinger (lo que hacés vos en hPanel)

Corré primero el verificador (por SSH o con un Cron Job de una sola vez) y leé las líneas `PROBLEMA` y `AVISO`: cada una dice qué hacer.

    php privado/scripts/verificar_servidor.php
    php privado/scripts/verificar_servidor.php --web      (prueba también desde afuera que nada privado se vea)

**Antes de abrir el panel al público:**

- [ ] **Subir `.htaccess`.** Es un archivo oculto: en el Administrador de archivos activá "Mostrar archivos ocultos" y confirmá que `public_html/.htaccess` está. *Cómo comprobarlo:* abrí `https://TUDOMINIO.com/login.php` → tiene que redirigir a `/login`. *Si no redirige:* el `.htaccess` no se aplica; volvé a subirlo y revisá que el hosting sea LiteSpeed/Apache.
- [ ] **Forzar HTTPS:** hPanel → Sitios web → Seguridad → **Forzar HTTPS** activado y el SSL activo. *Comprobalo:* `http://TUDOMINIO.com/login` tiene que ir a `https://`. *Si el verificador dice que falta HSTS:* el hosting no informa a PHP que la conexión es HTTPS; escribile a soporte pidiendo que pase `X-Forwarded-Proto` o la variable `HTTPS`.
- [ ] **`privado/` fuera de `public_html`** (lo recomendado). Si quedó adentro, el verificador `--web` tiene que mostrar `OK … responde 403/404` para `/privado/config.php`, `/privado/logs/php-error.log`, `/privado/backups/` y `/privado/sesiones/`. *Si alguno muestra contenido:* sacá `privado/` de `public_html`.
- [ ] **Claves:** generá `clave_maestra` y `clave_backup` (distintas) y guardá una copia de cada una **fuera del servidor**.
- [ ] **Permisos** (tabla de arriba): `config.php` en 600, carpetas escribibles en 700/750.
- [ ] **PHP:** hPanel → Avanzado → **Configuración de PHP**: versión **8.1 o superior**; extensiones `pdo_mysql`, `mbstring`, `curl`, `openssl`, `zlib` (y `sodium` si está); Opciones: **`display_errors = Off`** y **`expose_php = Off`**. *Comprobalo:* el verificador no debe mostrar `AVISO` en esas líneas.
- [ ] **Borrar `privado/paginas/install.php`** si no se borró solo, y `privado/install/crear_usuario_amoscato.sql` si todavía está en el servidor (y **cambiar esa contraseña**).
- [ ] **Cron Jobs** de la tabla de arriba, incluidos **backup** y **mantenimiento**. Dejá `app.cron_token` con "CAMBIAR…" (cron por URL apagado).
- [ ] **2FA** para vos y para cada administrador (Mi cuenta → Verificación en dos pasos).

**Cosas que dependen del hosting y hay que mirar la primera vez** (con qué mirar y qué hacer según el resultado):

| Qué mirar | Cómo | Si da mal |
|---|---|---|
| **IP real de cada visitante** (de eso dependen el límite de intentos y el registro de actividad) | Entrá a **Mi cuenta** desde el celular con datos móviles: arriba dice "tu IP actual para el panel". Compará con la IP que muestra una web tipo "cuál es mi IP". En **Dispositivos con sesión iniciada** cada dispositivo debería tener su IP | Si **todas** las IP son iguales (la del hosting) o no coincide con la tuya: el sitio está detrás de un proxy. Anotá esa IP del proxy en `config.php → app → proxies_confiables => ['IP_DEL_PROXY']` y volvé a mirar. Sin esto, 20 intentos fallidos de cualquiera bloquearían el login para todos |
| **Que `display_errors` esté apagado antes de que cargue el panel** | Abrí `https://TUDOMINIO.com/login.php?x[]=1` y cualquier URL rara: tienen que mostrar la página 404 o de error genérica, nunca texto de PHP | Si ves rutas o mensajes de PHP: `display_errors = Off` en hPanel (arriba) |
| **Que las páginas con datos no queden en el caché del navegador** | Con el verificador `--web`: `Cabecera cache-control` OK. Y a mano: iniciá sesión, entrá a un cliente, **Salir**, y tocá el botón **Atrás** del navegador | Tiene que recargar y mandarte al login. Si muestra los datos del cliente, avisá (puede ser un caché del hosting: pedile a soporte que desactive el caché de página para el dominio) |
| **Que nada se bloquee por la CSP** | Con la sesión iniciada, abrí las herramientas de desarrollo (F12 → Consola) y recorré el menú | No tiene que aparecer ningún `Refused to…` ni `Content Security Policy`. Si aparece, anotá la pantalla y la línea |
| **Modo estricto de la base** | El verificador dice `OK La base trabaja en modo estricto` | El panel lo fija en cada conexión; si dice PROBLEMA, la base no acepta `SET SESSION sql_mode`: avisá |
| **Cabeceras desde afuera** | `verificar_servidor.php --web` | Cada línea `PROBLEMA` trae la acción; la cabecera `Server` del hosting no se puede ocultar desde el panel |

## Para actualizar una instalación existente (esta versión)

1. Backup de la base (phpMyAdmin → Exportar) y de `privado/config.php`.
2. Subí todo sin pisar `config.php`. **Borrá** del servidor `privado/install/crear_usuario_amoscato.sql` (si estaba) y cambiá esa contraseña.
3. phpMyAdmin → SQL: importá `privado/install/migracion_007.sql` (una vez; después de la 006).
4. En `config.php → seguridad` agregá `clave_backup` (ver arriba). Las claves existentes siguen funcionando: los valores cifrados con el formato viejo se leen igual y se pasan al nuevo con `rotar_clave_maestra.php --recifrar` cuando quieras.
5. Si usabas el cron por URL, pasá a la cabecera `X-Cron-Token` con un token de 32+ caracteres (o mejor, a consola).
6. Agregá los Cron Jobs de **backup** y **mantenimiento**.
7. Corré `php privado/scripts/verificar_servidor.php --web` y revisá el resultado.

## Servicios por cantidad, cobro automático de dominios y avisos de renovación (esta actualización)

Si ya tenías el panel instalado, importá en phpMyAdmin, **una sola vez cada una y en orden**:

- `privado/install/migracion_008.sql` (después de la 007): agrega a `servicios` las columnas de cobro por cantidad (`por_cantidad`, `cantidad`, `unidad`, `unidad_singular`, `precio_unidad`, `detalle`) y la fecha del último aviso de renovación enviado (`aviso_renovacion_enviado_en`).
- `privado/install/migracion_009.sql` (después de la 008): agrega a `dominios` los días de anticipo del cargo (`dias_anticipo`, igual que en los servicios anuales) y la fecha del último aviso de renovación enviado (`aviso_renovacion_enviado_en`). No hace falta cargar nada a mano para los dominios existentes: la próxima vez que se generen los cargos (botón "Generar cargos del mes", o el resumen mensual automático) los que ya estén dentro de sus días de anticipo generan su cargo solos.

Una instalación nueva con `install.php` ya trae las dos. Ver "Probar el proyecto" más abajo para correr las pruebas automáticas.

### Cobro de dominios: cómo funciona ahora

Antes, el cargo de renovación de un dominio solo se generaba en el momento de tocar "Renovar" (y solo si se tildaba una casilla "Cobrar" que ya no existe). Si ese clic no pasaba exactamente así — casilla destildada, o la fecha se editaba a mano desde "Editar dominio" en vez de "Renovar" — el cargo nunca se creaba, sin ningún aviso. Ahora cada dominio se cobra igual que un servicio anual:

- Con **precio al cliente mayor a 0**, el cargo se genera solo cuando faltan sus **días de anticipo** (campo nuevo en "Editar dominio", 30 por defecto) para el vencimiento, con el mismo mecanismo idempotente de siempre (no se duplica si se corre de nuevo).
- **Cobrar el cargo no mueve la fecha de vencimiento**: el dominio se renueva aparte, en el proveedor; la fecha solo avanza al tocar **"Renovar"** (después de haberlo renovado de verdad). Si para ese momento ya existe el cargo del período (lo normal, generado por el anticipo), "Renovar" lo usa y no crea otro; si no existe todavía, lo crea ahí mismo.
- Aparece en la deuda del cliente, en el resumen mensual, en el dashboard y en el mensaje de cobro por WhatsApp como "Dominio nombre.com", igual que cualquier otro cargo.
- El botón **"Avisar renovación"** (misma plantilla que los servicios anuales) también está disponible para dominios, en la ficha del cliente y en Vencimientos, cuando faltan 7 días o menos y todavía no se tocó "Renovar" — esté cobrado o no.

## Probar el proyecto (automático)

Un solo comando corre todas las pruebas automáticas (servicios por cantidad, dominios, renovaciones, precios, aislamiento entre usuarios…) contra una base MariaDB real, de punta a punta:

```
bash tests/entorno.sh
```

La primera vez descarga PHP y MariaDB portables a `tests/.tools/` (no tocan el sistema ni el `config.php` real) y prepara la base; las siguientes veces reutiliza todo eso. Cada corrida recrea la base de pruebas desde `install.sql`, así que siempre arranca limpia. Ver `tests/README.md` para los detalles (variables de entorno, cómo agregar un caso de prueba nuevo, qué hacer si ya tenés tu propio MySQL/MariaDB).

## Pruebas de seguridad hechas

Con MariaDB real y un navegador automatizado, 4 grupos de pruebas (más de 300 comprobaciones) además de las de regresión: **cabeceras** de todas las respuestas (incluidas 301, 404, service worker, manifest, CSV y portal), CSP sin `unsafe-inline` y sin estilos ni scripts en línea en 30 pantallas, y sin violaciones de la CSP en el navegador; **login** (nadie bloquea a otro, límites por par/IP, demora, mensaje igual, hash constante, CSRF rotado, política de claves, límite de la "clave actual", email con contraseña); **recordarme** (pedidos paralelos con la misma cookie, tope de 180 días, robo detectado); **secretos fuera de la sesión**; **errores genéricos** (incluida una falla real de la base y el manejador global); **validación** de montos (30 formatos), fechas, largos, emails, teléfonos y dominios; **anular pagos** (desimputa, no borra, registra, otro usuario no puede, admin no lo ve, reportes y CSV, renovación anual); **formularios de un solo uso** (doble envío y dos sesiones a la vez); **Mercado Pago** (cargo abierto, ya pagado, anulado, reembolso, contracargo, otro usuario, montos inválidos, firma con repetición vencida, clave ilegible falla cerrado); **cotización** pendiente (límite exacto de 20%, confirmar, descartar, 403 a no admins, avisos, límite de frecuencia); **SMTP/Telegram/`mail()`** (SSRF, puertos, TLS, inyección en `-f` y cabeceras); **2FA** (vectores del RFC 6238, activar, anti-repetición, 5 intentos, códigos de recuperación de un solo uso, "recordarme", desactivar, admin quita, cifrado ligado al usuario, QR leído con un decodificador independiente); **backups** (cifrado, clave equivocada, un byte alterado, archivo cortado, trozos quitados o reordenados, restauración tabla por tabla en una base nueva, rotación de 14, script de restauración, cron con bloqueo); **rotación de la clave maestra** (con valor en formato viejo y uno ilegible); **crons** (token por cabecera, bloqueo, mantenimiento); **portal** (redirección solo a Mercado Pago, límites); **instalador** (dos instalaciones simultáneas, no reinstalable aunque se vacíe la tabla); **IP real detrás de un proxy**; y **archivos del proyecto sin secretos**. Lo único que no se pudo probar en esta máquina es el comportamiento del Apache/LiteSpeed de Hostinger con el `.htaccess` (se imitó con el servidor de PHP): por eso el checklist de arriba y `verificar_servidor.php --web`.