# Plan — App móvil (Flutter) y su API

Diseño escrito ANTES de tocar código porque abre un frente nuevo: el sistema era
100% Inertia (sesión + CSRF, escuela por dominio) y **no tenía API**. La app la
pidió el cliente, en Flutter, y el primer público es el **ALUMNO** (decidido con
el cliente 2026-09-08).

## El bloqueador, medido antes de empezar

- **948 rutas y CERO bajo `api/`**; sin Sanctum/Passport/JWT; los guards (`web`,
  `central`) son de SESIÓN.
- Todo es Inertia: los controladores devuelven páginas atadas a un componente
  Vue, tras cookie de sesión y CSRF.
- **La escuela se resuelve por DOMINIO** (`InitializeTenancyByDomain`), y los
  usuarios viven en la BD del TENANT: la central NO indexa correos.

De ahí el orden: **primero la API, después Flutter.** Y la primera pantalla de la
app no es el login: es «¿a qué escuela hablo?».

## Decisiones de arquitectura

1. **Token, no sesión.** La app autentica con Sanctum (`auth:sanctum`), no con la
   cookie de la web. El token vive en `personal_access_tokens` **del TENANT**
   —el login es de personas de la escuela—, así que su tabla la crea una
   migración de `tenant/`. Sanctum v4 no corre su migración solo; ésta es la
   única que crea la tabla, y sólo en el tenant.
2. **Resolución de escuela = CÓDIGO de escuela** (el *slug* del tenant, p. ej.
   `demo`). Es lo único que la arquitectura soporta sin inventar un índice
   central de correos. Un endpoint CENTRAL lo traduce a su dominio
   (`GET /api/v1/escuelas/{codigo}`), público y por código exacto: no lista
   escuelas ni deja enumerarlas. El login, con credenciales, ocurre después
   contra el dominio devuelto.
3. **La regla de CÓMO se encuentra la cuenta vive en UN sitio**
   (`App\Services\Acceso\ResolutorDeCuenta`): correo o CURP, prefiriendo la real
   sobre la de censo, con su mensaje. La comparten el formulario web
   (`LoginRequest`) y la API (`AccesoApiController`). Escrita dos veces, la app
   dejaría entrar por una puerta que la web cierra.
4. **La API del tenant va SIN `web`** (grupo aparte en `routes/tenant.php`): sin
   sesión ni CSRF, con la tenencia por dominio y `PreventAccessFromCentralDomains`.
   El acceso es público (cambia credenciales por token); lo demás exige el token.
5. **Versionada** (`/api/v1`): un cliente móvil publicado no se actualiza a la
   vez que el servidor, así que la superficie tiene que poder crecer sin romper
   a quien todavía corre la versión vieja.

## Las rebanadas

### Rebanada 1 — El cimiento ✅ (2026-09-08)

- Sanctum instalado; `HasApiTokens` en `Usuario`; guard `sanctum` en
  `config/auth.php`; `personal_access_tokens` (tenant).
- **Central**: `GET /api/v1/escuelas/{codigo}` → `{codigo, nombre, dominio}` /
  404.
- **Tenant**: `POST /api/v1/acceso` `{identificador, password, dispositivo?}` →
  `{token, usuario}`; `GET /api/v1/yo` (auth) → `{usuario}`; `POST /api/v1/salir`
  (auth) → revoca SÓLO el token de este dispositivo.
- El `usuario` trae lo que decide la interfaz: **facetas** (alumno, docente,
  padre…), roles y `rol_activo_id`. Los permisos finos viajan con cada pantalla.
- Pruebas: `scripts/prueba-api-acceso.php` (17 verif, 6 mut) por invocación de
  controladores con rollback; y **por HTTP real** contra el servidor (código→
  dominio, login→token, credencial mala→422, `/yo` con y sin token→200/401,
  salir→revoca). El 401 sin token y el 405 de método los pone el middleware de la
  ruta, comprobados por HTTP.

### Rebanada 2 — La app: acceso (Flutter) ✅ (2026-09-08)

Proyecto Flutter en el sibling **`acadion-app`** (repo propio, como
`comandia-app`), con el flujo de acceso completo contra la rebanada 1: código de
escuela → login → token en el almacén seguro → `/yo` al arrancar → routing por
faceta (andamio; el portal del alumno llega en la 3).

- **Stack**: Riverpod (`Notifier`, v3), Dio, flutter_secure_storage. La máquina
  del acceso —cuatro estados: Cargando/SinEscuela/SinSesion/Dentro— vive en
  `lib/core/sesion.dart`; la red en `lib/core/api.dart`.
- **El dominio del tenant no lo resuelve un emulador**: en desarrollo se manda
  todo al host de pruebas llevando el dominio real en la cabecera `Host` (lo que
  el servidor lee), con `--dart-define=DEV_HOST_REWRITE=…`. En producción cada
  dominio se usa tal cual, por HTTPS.
- **La regla de negocio NO se duplica en Dart**: el servidor valida y decide; la
  app pide y muestra.
- Verificado: `flutter analyze` sin issues, **11 pruebas** (la máquina del acceso
  con dobles sin red, y un smoke de la primera pantalla), `flutter build web`
  compila. La API ya estaba probada por HTTP real (rebanada 1). Un extremo-a-
  extremo con la UI viva pide emulador/dispositivo (y Developer Mode para los
  symlinks de plugins en Windows), que queda para cuando haya con qué.

### Rebanada 3 — Alumno: datos y pantallas ✅ (2026-09-09)

**API** (repo `acadion`):

- **El rol activo se RESUELVE para la API.** El `Gate::before` de los `can:` y el
  ámbito de `VeLaCarteraDelAlumno` resuelven contra `rol_activo_id`, que en la
  web mantiene al día `EstablecerRolActivo` —pero corre en el grupo `web` sobre
  `Auth::user()` (sesión), no en la API (token/`sanctum`, grupo aparte)—. El
  middleware **`api.faceta:alumno`** (`OperarComoFaceta`) lo cierra: fija la
  faceta del PORTAL —no «el primer rol válido»: quien es alumno Y administrativo
  vería la cartera de toda la escuela en su estado de cuenta— **en MEMORIA, sin
  persistir** (no le cambia a la web su rol activo). Sin esa faceta, 403.
- **Una sola verdad, no una segunda para la app.** El listado/detalle de
  materias salió de `MisCursosController` a `App\Services\Lms\CursosDelAlumno`,
  que ahora comparten la web (Inertia) y la API (JSON); historial y estado de
  cuenta reusan `HistorialDelAlumno` y `EstadoCuenta`; avisos, `AvisosDeUsuario`.
- Endpoints (bajo `auth:sanctum`): `GET /alumno/materias` (`can:ver-mis-cursos`),
  `GET /alumno/materias/{id}` (403 con su motivo si no es suya),
  `GET /alumno/historial` (`can:ver-historial-academico`),
  `GET /alumno/estado-cuenta` (`can:ver-adeudos`), y —por PERSONA, fuera de la
  faceta— `GET /avisos` + `POST /avisos/{aviso}/confirmar`. La matrícula
  (`?matricula=`) se elige de entre las SUYAS: una ajena cae en la propia.
- **La API responde JSON, nunca Inertia** (`/api/*` en el manejador de
  excepciones), así que un 403/401 sale `{"message": …}`.
- **Trampa del orden de middleware**: `SubstituteBindings` iba ANTES de la
  tenencia; con el binding de `{aviso}` habría resuelto el modelo contra la base
  equivocada. Se puso la tenencia primero.
- Pruebas: `scripts/prueba-api-alumno.php` (19 verif, **6 mutaciones**, todas
  mueren), y el flujo entero por HTTP real contra el servidor (materias/detalle/
  historial/estado-cuenta/avisos → 200; materia ajena → 403; faceta ajena → 403;
  sin token / revocado → 401). Sweep 159 verdes, `npm run build`, auditoría del
  demo sin cambios (69); los tokens de humo se borraron.

**Flutter** (repo `acadion-app`): panel del alumno + cinco pantallas —materias
(por ciclo, con pendientes), materia (evaluación por parcial, asistencia,
actividades), historial (selector de matrícula, resumen, renglones por periodo),
estado de cuenta (saldo/vencido, situación, cargos) y avisos (con confirmar)—.
Modelos tipados en `core/modelos_alumno.dart`; datos por `FutureProvider`
autoDispose; los errores del servidor (un 403 de faceta, un token vencido) se
muestran con su motivo y un botón de reintentar. Verificado: `flutter analyze`
limpio, **19 pruebas** (providers, formato de pesos, y widgets del panel y de
avisos con dobles sin red), `flutter build web` compila.

### Rebanada 4 ✅ — Familia y docente

Cada público, su rebanada de API + pantallas, con el mismo patrón del alumno.

**Familia** (`api.faceta:padre_familia`). `PadreApiController`: la lista de hijos
con su estado (`GET /api/v1/familia/hijos`) y el detalle de cada hijo —académico,
finanzas y conducta— (`GET /api/v1/familia/hijos/{hijo}`). Una sola verdad: el
estado sale de `EstadoDelAlumno`, el promedio y los renglones de
`HistorialDelAlumno`, y el saldo de `EstadoCuenta`. El alcance lo pone el VÍNCULO
(`tutores_alumno`), no la URL: un hijo no vinculado → 403; qué se enseña
—académico y financiero por separado— sale del pivote del vínculo, la conducta
del permiso de faceta y el módulo. Flutter: `PanelFamilia` (hijos con su estado)
y `PantallaHijo` (secciones según el vínculo), reusando `RenglonHistorial` y
`CuentaData` del alumno. Pruebas: `prueba-api-familia.php` (15) + HTTP real +
8 pruebas Flutter.

**Docente** (`api.faceta:docente`). `DocenteApiController`: las materias que
imparte (`GET /api/v1/docente/materias`, con grupo, horario, inscritos y acta) y
el roster de una materia propia (`GET /api/v1/docente/materias/{ag}`, con sus
alumnos y compañeros). El alcance sale de la ASIGNACIÓN —filtro por
`docentes.persona_id`, la trampa documentada—: una materia ajena → 403. Flutter:
`PanelDocente` (materias) y `PantallaMateriaDocente` (roster). Los flujos de
captura —pasar lista, calificar, asentar acta— son rebanadas posteriores; aquí
sólo lectura. Pruebas: `prueba-api-docente.php` (11) + HTTP real + 6 pruebas
Flutter.

El enrutado por faceta cae en el primer portal con contenido (alumno, luego
familia, luego docente). El back office (administrativo, 727 rutas) NO es una app
móvil.

### Lo interactivo (flujos que ESCRIBEN)

Sobre los portales de lectura, los flujos de escritura, uno por rebanada.

- **Pasar lista** (docente) ✅. La escritura vive en un servicio compartido
  `App\Services\Asistencia\PaseDeLista` que usan la web y la API (una sola
  verdad): sólo alumnos de la materia, y repasar el mismo día corrige sin
  duplicar (revive la fila borrada). `GET/POST /api/v1/docente/materias/{ag}/asistencia`
  bajo `can:pasar-lista`. Flutter: `PantallaAsistencia` (fecha, modalidad, marcar
  y guardar). Pruebas: `prueba-pase-de-lista.php` (12) + HTTP real + 3 Flutter.

- **Capturar calificaciones** (docente) ✅. La escritura y el estado del acta
  salen a un servicio compartido `App\Services\CapturaDeCalificaciones` (la web
  delega en él): sólo pares de la materia, respeta los cortes del calendario,
  NULL no es cero, revive la fila borrada. `GET/POST
  /api/v1/docente/materias/{ag}/calificaciones` bajo `can:capturar-calificaciones`
  (materia ajena → 403, fuera de escala → 422, acta cerrada → 422). Flutter:
  `PantallaCalificaciones` (hoja alumnos × componentes, con el final calculado).
  **Cerrar/asentar el acta NO se trae a la app**: es un acto deliberado e
  irreversible que se queda en la web. Pruebas:
  `prueba-captura-calificaciones.php` (13) + HTTP real + 2 Flutter.

Lo que sigue: pagar en línea, solicitar factura, entregar documentos y confirmar
autorizaciones (familia). Cada uno con su rebanada, sobre la API de escritura
que corresponda.

## Reglas transversales

El servidor valida permiso y regla (la app oculta, el servidor exige); la regla
de negocio vive en el servidor, NO duplicada en Dart (lección de «no dupliques en
Vue y en la futura app»); nada de datos reales en las pruebas (los tokens de la
prueba de humo se borraron; la auditoría del demo sigue en 69); sin secretos en
logs; la API es versionada.
