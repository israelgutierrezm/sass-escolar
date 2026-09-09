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

### Rebanada 2 — La app: acceso (Flutter)

El primer entregable de la app: proyecto Flutter (sibling `acadion-app`, como
`comandia-app`), con el flujo de acceso completo contra la rebanada 1 —código de
escuela → login → guardar el token de forma segura → `/yo` al arrancar → llevar a
cada faceta a su portal—. Sin pantallas de datos todavía: es el andamio y la
sesión.

### Rebanada 3 — Alumno: datos y pantallas

- **API**: resolver el ROL ACTIVO para la API (hoy `EstablecerRolActivo` es de la
  web) y exponer, gateado por permiso, lo del alumno —mis materias, mi historial,
  mi estado de cuenta, avisos—. Reusa los servicios que ya existen
  (`HistorialDelAlumno`, `EstadoCuenta`, …): una sola verdad, no una segunda para
  la app.
- **Flutter**: panel del alumno + esas pantallas.

### Después — Familia y docente

Cada público, su rebanada de API + pantallas. El back office (administrativo, 727
rutas) NO es una app móvil; lo que sí lo es —alumno, familia, docente— ya está
construido y revisado del lado web.

## Reglas transversales

El servidor valida permiso y regla (la app oculta, el servidor exige); la regla
de negocio vive en el servidor, NO duplicada en Dart (lección de «no dupliques en
Vue y en la futura app»); nada de datos reales en las pruebas (los tokens de la
prueba de humo se borraron; la auditoría del demo sigue en 69); sin secretos en
logs; la API es versionada.
