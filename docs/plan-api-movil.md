# Plan — API para la app móvil (Flutter)

Diseño escrito ANTES de tocar `composer.json`, `bootstrap/app.php` o una tabla,
porque instalar Sanctum y abrir una superficie HTTP nueva es un cambio de fondo y
este proyecto escribe el plan primero para los módulos grandes.

## Qué es, y qué NO es

Una **API REST con tokens** para las apps de **alumno, familia y docente**. NO
reemplaza Inertia ni el back office: el administrativo (727 rutas) seguirá siendo
web. La app cubre lo que se abre desde el teléfono —portal del alumno, portal de
la familia, portal del docente—, que **ya está construido y revisado** como
pantallas Inertia; lo que falta es exponerlo como JSON con autenticación por
token.

Medido antes de empezar (2026-09-07): **948 rutas, cero bajo `api/`**, sin
Sanctum ni tokens, los dos guards de sesión. La app no puede consumir nada hoy.

## Las DOS superficies, y por qué son dos

La escuela se resuelve por **dominio** (`InitializeTenancyByDomain`). Un cliente
móvil no tiene subdominio, así que hay dos preguntas en dos sitios distintos:

1. **«¿A qué escuela pertenezco?»** — la contesta la BD **CENTRAL** (landlord),
   que es la única que conoce el registro de escuelas (`tenants` + `domains`).
   Vive en `routes/api.php` del grupo central, sin tenant inicializado.
2. **«Todo lo demás»** — lo contesta la escuela, con el tenant ya puesto por el
   dominio. Vive en un `routes/api.php` de tenant, detrás de `InitializeTenancyByDomain` + auth de token.

## Autenticación: Sanctum, con los tokens en la BD del TENANT

- **Sanctum, no Passport ni JWT.** Es el estándar de Laravel para tokens de app,
  encaja con el login por correo/CURP que ya existe, y no arrastra un servidor
  OAuth que nadie pidió.
- **La tabla `personal_access_tokens` va en `database/migrations/tenant/`**, no
  en la central. Cada escuela tiene sus usuarios en su propia base, así que sus
  tokens también: **el aislamiento por escuela sale gratis**. Cuando la app
  llama a `escuela.dominio/api/...`, `InitializeTenancyByDomain` pone la conexión
  del tenant ANTES de que el guard de Sanctum busque el token, así que la
  búsqueda cae en la base correcta. La central NO lleva esta tabla.
- **`Usuario` gana el trait `HasApiTokens`.** Es lo único que cambia del modelo.
- **El login REUSA `LoginRequest`**: su limitación por intentos (5 por
  identificador+IP), su resolución por correo o CURP, y su guarda de
  `acceso_configurado`. Escribir un segundo camino de autenticación es como se
  llega a que uno deje de comprobar lo que el otro sí. El endpoint envuelve esa
  lógica y, en vez de abrir sesión, emite un token.
- **Los tokens de la app NO son de sesión web.** `logout` desde la web no mata el
  token del teléfono, y revocar el teléfono no cierra la web. Son vidas
  separadas, como lo son en cualquier app.

### El rol activo SIN sesión

En la web el rol activo vive en la sesión y `EstablecerRolActivo` lo resuelve por
petición. Sin sesión hay que decidir dónde vive para un token. **Decisión:** el
token se emite **para una FACETA concreta** (alumno, docente…), elegida al
emparejar o al hacer login, y esa faceta viaja en el token (`abilities`). Un
alumno que además es docente empareja dos veces —una app en modo alumno, otra en
modo docente— o cambia con un endpoint que **re-emite** el token para la otra
faceta. Así el `Gate::before` del servidor resuelve contra una faceta explícita y
no contra un estado de sesión que no existe. Un token nunca vale para dos oficios
a la vez: es lo que hace que el alcance por asignación (`docente_asignatura_grupo`)
siga colgando de algo real.

## El emparejamiento por QR (lo que pidió el cliente)

El QR **carga la escuela Y deja el teléfono dentro**, estilo WhatsApp Web. Es un
handoff de una sesión web ya autenticada a un token de app, sin teclear la
contraseña en el teléfono.

### El flujo

1. En la **web**, ya con sesión abierta, la persona pulsa «Conectar con la app».
   El servidor crea un **código de emparejamiento** —aleatorio, de un solo uso,
   que **caduca en 2 minutos**— ligado a ESA persona y a la faceta con la que
   está mirando la web, y lo muestra como QR. El QR codifica: el **host** de la
   escuela (`demo.localhost`) + el código.
2. La **app** escanea → saca host y código → llama a `POST /api/emparejar` de esa
   escuela con el código.
3. El servidor comprueba que el código existe, no se usó, no caducó, y **lo
   consume en la misma transacción** (un `UPDATE ... WHERE usado_en IS NULL` cuyo
   número de filas decide si esta petición ganó: dos apps escaneando el mismo QR
   no pueden emparejarse las dos). Emite un **token de Sanctum** para ese usuario
   y esa faceta, y lo devuelve.
4. La **web sondea** `GET /api/emparejamiento/{codigo}/estado` cada par de
   segundos y, cuando el código se consumió, muestra «Vinculado ✓» y retira el
   QR. Se sondea y no se usa websocket: es un intercambio de una vez y unos
   segundos, no un canal permanente.

### Las decisiones de seguridad, y por qué

- **Un solo uso y TTL de 2 minutos.** Un QR filtrado (una foto de la pantalla)
  que valiera para siempre sería una llave a la cuenta. Corto y desechable.
- **Ligado al usuario de la sesión web que lo generó.** El código no dice a quién
  vincula: lo dice la sesión que lo creó. Así una persona no puede generar un
  código que empareje la cuenta de otra.
- **La faceta se congela al generar el código**, no la elige la app: si la app
  pudiera pedir «emparéjame como docente», un alumno se auto-ascendería. La web
  ya sabe con qué faceta está mirando.
- **El código NUNCA es el token.** Lo que viaja en el QR es un vale de un solo
  uso; el token se emite en la respuesta de `emparejar`, sobre HTTPS, y nunca
  aparece en un QR que alguien pueda fotografiar.
- **La web muestra el emparejamiento en curso y deja cancelarlo**: quien ve un
  «vinculado» que no hizo, revoca. Y hay una pantalla de «dispositivos
  vinculados» para cortar un token viejo —es el equivalente de la bitácora de
  accesos—.
- **`emparejar` va con `throttle`**: adivinar un código de un solo uso en dos
  minutos es inviable, pero se cierra la puerta a intentarlo en masa.

### Por qué no se puede desde la pantalla PÚBLICA

El QR que inicia sesión necesita una sesión web YA abierta —es de ella de donde
sale la identidad—. En la pantalla de acceso (sin sesión) sólo cabe el QR que
**carga la escuela** (host + nada más) y el **código manual**; ahí la persona
todavía tiene que hacer login en la app. Los dos QR conviven: el público carga la
escuela, el de dentro de la sesión además vincula la cuenta.

## Resolución de escuela en la CENTRAL (el código manual)

`GET /api/central/escuelas/{codigo}` → `{ host, nombre }`, leyendo `domains` +
`tenants`. **Se consulta por código exacto, no se enumera**: no hay un endpoint
que liste las escuelas, porque eso filtraría el padrón de clientes del SaaS. Un
código que no existe responde 404 sin distinguir «no existe» de «no te toca».

El modelo de dominios **quedó sin definir** (puede haber dominio propio por
escuela, como el `demo.localhost` de hoy), así que este endpoint hace falta de
todos modos: cubre el caso del dominio a medida y no estorba si mañana todo se
estandariza en `algo.acadion.mx`.

## Las rebanadas

| # | Rebanada | Qué entrega |
|---|---|---|
| 1 | **Sanctum + login de tenant** | `install:api` revisado para multi-DB, `personal_access_tokens` en el tenant, `HasApiTokens` en `Usuario`, `POST /api/sesion` (login por correo/CURP → token, reusando `LoginRequest`), `GET /api/yo` (quién soy + mis facetas), `DELETE /api/sesion` (revocar). Con esto la app ya entra por contraseña. |
| 2 | **Resolución de escuela** | `GET /api/central/escuelas/{codigo}` en la central. Con esto el código manual funciona. |
| 3 | **Emparejamiento por QR** | El código de un solo uso, `POST /api/emparejar`, el sondeo de estado, y la pantalla web «Conectar con la app» con su QR y sus dispositivos vinculados. |
| 4+ | **Los endpoints de lectura** | Las versiones JSON de los portales que ya existen (alumno: `/mis-cursos`, `/mi-historial`, `/finanzas`; familia: `/mis-hijos`; docente: `/docencia`). Mecánico y grande: **reusan los servicios de dominio que ya están** (`HistorialDelAlumno`, `EstadoCuenta`, `ContextoAcademico`…), no reimplementan nada. |

Cada rebanada para y pide validación, como el resto del proyecto.

## Trampas del multi-DB con Sanctum, anotadas antes de pisarlas

- **`install:api` publica su migración en `database/migrations/` (la central) y
  toca `bootstrap/app.php`.** La migración hay que MOVERLA a `tenant/` y
  comprobar que no corre contra la central; el cambio de `bootstrap/app.php` hay
  que revisarlo para que el grupo `api` quede DETRÁS de
  `InitializeTenancyByDomain`, o el guard de Sanctum buscaría el token en la base
  equivocada.
- **El guard `sanctum` tiene que resolver DESPUÉS del tenant.** Si el orden de
  middleware deja a Sanctum mirar antes de que el dominio ponga la conexión, todo
  token da 401 «sin base». El orden es: dominio → (auth de token) → rol/faceta.
- **Sanctum añade su propio `EnsureFrontendRequestsAreStateful`** para el modo
  SPA con cookies. La app NO lo usa —es puro token—, así que ese middleware NO
  entra en el grupo de la API móvil: mezclarlo reintroduce el CSRF que se quería
  evitar.
- **Los tokens no se comparten entre escuelas.** Es lo bueno del multi-DB, pero
  significa que un mismo `Usuario` de dos escuelas (no debería pasar, pero) tiene
  tokens en dos bases: nunca se busca un token «en general», siempre con el
  tenant puesto.
