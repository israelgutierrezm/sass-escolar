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

- **Confirmar autorizaciones** (familia) ✅. La lectura y las dos escrituras
  salen a un servicio compartido `App\Services\Familia\RespuestaAutorizacion`
  (la web delega en él): listar, conceder/negar (mientras el plazo siga abierto)
  y revocar lo en vigor. Los guardas responden 404 —vínculo ajeno o estado que
  no admite el acto—. `GET/PUT/POST /api/v1/familia/autorizaciones[...]` bajo
  `can:ver-mis-hijos`. Flutter: `PantallaAutorizaciones` (con insignia de
  pendientes en el panel). Pruebas: `prueba-api-autorizaciones-familia.php` (9) +
  HTTP real + 3 Flutter.

- **Entregar documentos del hijo** (familia) ✅. La lista de lo pedido, la
  autorización y las escrituras salen a un servicio compartido
  `App\Services\Familia\EntregaDocumentos` (la web —`DocumentosDelHijoController`
  y `PadreController`— delega en él): listar los tipos con lo subido encima,
  subir (multipart), reemplazar y quitar. **Las TRES capas de la web se
  respetan**: el vínculo, el ajuste `familia.tutor_entrega_documentos` y la
  MAYORÍA DE EDAD —un hijo mayor, la escuela sin la función o el vínculo ajeno
  dan el motivo del servidor, sin dejar entregar—. Un ACEPTADO no se pisa ni se
  retira. `GET /api/v1/familia/hijos/{hijo}/documentos`, `POST …/documentos`
  (multipart: `archivo`, `documento_id`) y `DELETE …/documentos/{doc}` bajo
  `can:ver-mis-hijos`. Flutter: `PantallaDocumentosHijo` (tipos con Subir/
  Reemplazar/Quitar vía `file_picker`; el motivo cuando `!puedeEntregar`),
  alcanzada desde la ficha del hijo. Pruebas: `prueba-api-documentos-hijo.php`
  (12) + HTTP real + 3 Flutter.

- **Solicitar factura** (familia) ✅. El autoservicio de factura de la web
  —incluido su portal del padre— YA existía completo, con servicios compartidos
  (`AutoservicioFactura` para leer, `GestorSolicitudFactura` y `EmisorFactura`
  para escribir); esta rebanada lo EXPONE en la API móvil, sin reescribir la
  regla fiscal. La ficha del hijo trae por matrícula `facturas`,
  `solicitudes_factura` y —con el canal abierto— `factura_autoservicio` (qué se
  puede facturar y el perfil), más `factura_modo` (generar > solicitar) arriba.
  Endpoints bajo `familia.*`: `POST facturas/{matricula}/solicitar`, `.../generar`
  (permiso y canal APARTE) y `GET facturas/solicitudes/{solicitud}/cfdi/{tipo}`.
  De quién es la cuenta lo decide `VeLaCarteraDelAlumno` —el mismo trait que la
  web (vínculo + `puede_ver_finanzas`)—; el canal cerrado por la escuela → 404.
  Flutter: `PantallaSolicitarFactura` (elegir pagos + receptor precargado con el
  catálogo del SAT) y la sección de facturación en la ficha del hijo. **La
  DESCARGA del CFDI en la app queda para después** (necesita visor/compartir); la
  API ya la sirve. **De paso, un bug de la web**: `SolicitudFacturaController`
  llamaba a `Factura::estaTimbrada()`, inexistente —500 en la descarga del CFDI
  del autoservicio—; se usa `estaVigente()`. Pruebas:
  `prueba-api-factura-familia.php` (18 verif, 4 mut) + HTTP real + 5 Flutter.

- **Pagar en línea** (familia) ✅. El más delicado, y el que confirmó lo que
  gobierna el diseño móvil: **el webhook concilia el cobro solo, sin depender del
  cliente** (`CobroEnLinea::conciliar`), así que la app sólo INICIA y luego
  RELEE. Todas las pasarelas dan una URL (hosted checkout); ninguna usa SDK
  embebido, así que el reto móvil se reduce a abrir una URL externa
  (`url_launcher`). Se expone lo que la web ya tenía, sobre los mismos servicios:
  la ficha del hijo trae el bloque `pago` (pasarelas, abono mínimo, pago total) y
  por matrícula sus cuentas para transferencia; y dos endpoints bajo `familia.*`:
  `pagos/{matricula}/iniciar` (reusa `CobroEnLinea::iniciar` con las mismas dos
  URLs —retorno del navegador y aviso público del webhook—, devuelve la URL de
  checkout) y `pagos/{matricula}/comprobante` (reusa `RegistroDeComprobante`,
  extraído de `ComprobantePagoController` a un servicio compartido; nace
  PENDIENTE). De quién es la cuenta lo cierra `VeLaCarteraDelAlumno`; el filtro
  de cargos al titular protege el comprobante. Flutter: `PantallaPagar` (elegir
  cargos respetando `pago_total`, abono opcional, pasarela → abre el checkout con
  un `abrirUrlProvider` sustituible en pruebas; OpenPay pide método antes) y
  `PantallaComprobante` (cuentas con la CLABE copiable + subir el comprobante con
  `file_picker`). **OpenPay SPEI queda como borde**: abre la página web de
  instrucciones en vez de una pantalla nativa. **De paso, un bug de la web**:
  `SolicitudFacturaController::descargarCfdi` ya estaba, pero acá no aplica; sí se
  arregló antes en la rebanada de factura. Pruebas:
  `prueba-api-pagos-familia.php` (17 verif, 3 mut) + HTTP real + 4 Flutter.

- **Salida segura** (familia) ✅. Quién puede recoger al hijo. El alta
  (`permitido=true`, con el token del QR que pone el modelo) y la baja de un
  tercero salen a un servicio compartido `App\Services\Familia\AutorizadosParaRecoger`
  (la web —`SalidaSeguraController`— delega en él); la lista efectiva es la de
  `PuedeRecoger`, ya compartida. **La familia SÓLO toca sus terceros
  `permitido=true`**: un bloqueo de custodia (de la escuela) responde 404 y no se
  borra, y un autorizado de otro hijo, 404. `GET/POST familia/hijos/{hijo}/autorizados`
  y `DELETE …/autorizados/{autorizado}` bajo `can:ver-mis-hijos`; el vínculo
  cierra a quién (hijo ajeno → 403), sin gatear por lo financiero ni lo académico.
  Flutter: `PantallaAutorizados` (lista efectiva + terceros con quitar + alta
  inline), alcanzada desde la ficha del hijo. **La descarga/exhibición del QR de
  un tercero queda para después** (necesitaría un render de QR). Pruebas:
  `prueba-api-salida-segura-familia.php` (13 verif, 4 mut) + HTTP real + 4 Flutter.

- **Citas familia–docente** (familia) ✅. La familia pide y cancela citas con los
  docentes del hijo. Todo sobre `GestorDeCitas` —la misma regla que la web—: el
  vínculo (`esHijoDe`), que el docente le dé clase (`daClaseA`), la validez del
  hueco (día de la ventana, rango y múltiplo de la duración), no en el pasado ni
  ya ocupado, y la máquina de estados. De paso se subió `ventanasPorDocente` del
  controlador web al servicio, para que web y app ofrezcan los mismos huecos.
  `GET familia/hijos/{hijo}/citas` (docentes con ventanas, modalidades, citas),
  `POST …/citas` (solicitar) y `POST familia/citas/{cita}/cancelar`, bajo permiso
  propio `can:solicitar-citas`; hijo/cita ajena → 404, cancelar sin ser parte →
  403. La ficha del hijo expone `puede_citas` para gatear la entrada. Flutter:
  `PantallaCitas` (elegir docente → autollena la ventana única, día y hora de los
  huecos calculados, y motivo; citas con estado y cancelar). Pruebas:
  `prueba-api-citas-familia.php` (13 verif, 1 mut) + HTTP real + 5 Flutter.

**Con esto el portal de la FAMILIA queda COMPLETO en la app** —lectura y todos
sus flujos de escritura: hijos, autorizaciones, documentos, factura, pago en
línea, salida segura y citas—.

### Rebanada — Alumno: factura y pago en línea ✅

El estado de cuenta del ALUMNO ofrece ahora pagar en línea y facturar su propia
cuenta, con los MISMOS servicios y pantallas que la familia. Para no duplicar:

- **Servidor**: el payload financiero sale a `App\Services\Finanzas\FinanzasParaApp`
  (facturas, autoservicio, solicitudes, cuentas para transferencia, `factura_modo`
  y el bloque `pago`) y las acciones de escritura a un trait
  `App\Http\Controllers\Concerns\OperaFinanzasEnLinea` (solicitar/generar factura,
  descargar CFDI, iniciar pago, subir comprobante). Los comparten
  `AlumnoApiController` y `PadreApiController` —como la web, un solo controlador
  para los dos—. El estado de cuenta del alumno se enriquece con esos campos y se
  agregan los endpoints bajo `alumno.*`. De quién es la cuenta lo cierra
  `VeLaCarteraDelAlumno`: para la faceta ALUMNO, sus PROPIAS matrículas
  (ALCANCE_PROPIO), así que una matrícula ajena → 403.
- **Flutter**: las pantallas de pago/factura/comprobante se desacoplan del portal
  —toman un `refrescar` (qué invalidar) y un `base` (`/familia` o `/alumno`), y
  `PantallaPagar` toma primitivos en vez de `FinanzasHijo`—, así que el estado de
  cuenta del alumno las reusa apuntando a `/alumno`. `api.pedirFactura/iniciarPago/
  subirComprobante` ganan `base` (default `/familia`).
- Pruebas: `prueba-api-alumno-finanzas.php` (13 verif; la mutación del candado de
  cartera mata 3) + HTTP real (estado-cuenta con factura/pago); 3 pruebas Flutter
  que verifican que el alumno se pega al portal `/alumno`. Las suites de la familia
  y la de lectura del alumno siguen verdes; auditoría del demo sin cambios (69).

Lo que podría venir después: portar más flujos de otras facetas (el docente ya
tiene lectura + pasar lista + capturar calificaciones), cada uno con su rebanada.

## Reglas transversales

El servidor valida permiso y regla (la app oculta, el servidor exige); la regla
de negocio vive en el servidor, NO duplicada en Dart (lección de «no dupliques en
Vue y en la futura app»); nada de datos reales en las pruebas (los tokens de la
prueba de humo se borraron; la auditoría del demo sigue en 69); sin secretos en
logs; la API es versionada.
