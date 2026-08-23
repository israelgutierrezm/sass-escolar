# Bitácora de decisiones — Acadion

Registro de decisiones de arquitectura y diseño tomadas durante la construcción
del sistema. Cada entrada anota la fecha, el contexto, la decisión y su razón.
Cuando la especificación (`especificacion-esquema.md`) presenta una ambigüedad,
la resolución se documenta aquí antes de implementar.

---

## 2026-07-21 — Fase 0: fundación

### Estructura del repositorio: monolítico Laravel + Inertia
- **Decisión:** Laravel 12 vive en la raíz del repo. El front (Vue 3 + TS) irá
  más adelante vía Inertia + Vite, integrado en el mismo proyecto.
- **Razón:** un solo repo, un solo deploy, misma sesión maneja auth. Es el
  patrón ya probado en el proyecto IDP y facilita la integración con
  `stancl/tenancy`. Se descartó separar `backend/` + `frontend/` (SPA aislada)
  por no justificarse en esta etapa.

### Nombre del paquete Composer: `acadion/saas-escolar`
- **Decisión:** `name` del `composer.json` raíz.
- **Razón:** deja espacio al vendor `acadion` por si a futuro se separan
  paquetes (nómina, LMS, movilidad).

### Identidad de Git: local al repo
- **Decisión:** `user.name` / `user.email` configurados solo en
  `acadion/.git/config` (Israel Gutierrez / yosef.gutierrezm@hotmail.com),
  sin tocar la config global de la máquina.
- **Razón:** la config global heredaba otra identidad (del proyecto IDP) que el
  usuario no quiere usar aquí.

### Multi-tenancy: una base de datos por tenant (multi-database)
- **Decisión:** `stancl/tenancy` v3 en modo multi-database. La BD central
  (landlord) es `acadion_landlord`; cada escuela obtiene su propia BD
  (`tenant<id>`). `central_domains = ['127.0.0.1', 'localhost']`.
- **Razón:** lo exige la spec (convenciones globales). Aísla datos por escuela
  a nivel de base de datos, no de prefijo.

### Migraciones default de Laravel → capa TENANT
- **Decisión:** `users`, `cache` y `jobs` (más `password_reset_tokens` y
  `sessions`, que viajan dentro de `create_users_table`) se movieron de
  `database/migrations/` a `database/migrations/tenant/`.
- **Razón:** en un SaaS multi-tenant estas tablas son por escuela, no de la
  landlord. La landlord solo aloja `tenants`, `domains`, `super_admins` y los
  catálogos universales. Los administradores de la casa (super admins) usarán
  su propia tabla en la landlord (Fase 0).

### Migración de `spatie/laravel-permission` → capa TENANT
- **Decisión:** `create_permission_tables` se movió a
  `database/migrations/tenant/`. El trait `HasRoles` se agregó al modelo
  `App\Models\User`.
- **Razón:** según el Módulo 1 de la spec, `roles`, `permisos` y `rol_permiso`
  son tablas TENANT/TENANT-CONFIG: cada escuela define y nombra sus propios
  roles y permisos. El caché de permisos de Spatie queda aislado por tenant
  gracias al `CacheTenancyBootstrapper` (ya activo).

### Motor InnoDB explícito en `config/database.php`
- **Decisión:** la conexión `mysql` fija `'engine' => 'InnoDB'`.
- **Razón:** WAMP en esta máquina trae `default_storage_engine=MyISAM`, que
  rompe FKs, transacciones y `FOR UPDATE SKIP LOCKED`. Mismo blindaje que el
  proyecto IDP. Síntoma si se reintroduce el bug: error `1071 key too long`.

## 2026-07-21 — Fase 0: bloque landlord (tablas)

### Organización de modelos por capa: `App\Models\Landlord\`
- **Decisión:** los modelos de la capa landlord (SuperAdmin y catálogos
  universales) viven en `app/Models/Landlord/`. El modelo `Tenant` permanece en
  `app/Models/Tenant.php` (ancla de tenancy, referenciado por `config/tenancy.php`).
- **Razón:** con ~121 tablas por venir, agrupar por capa/módulo mantiene el
  árbol navegable. Los modelos de negocio TENANT se organizarán igual por módulo.

### Modelos landlord fijados a la conexión central
- **Decisión:** todo modelo landlord usa el trait
  `Stancl\Tenancy\Database\Concerns\CentralConnection`.
- **Razón:** cuando hay un tenant inicializado, la conexión por defecto apunta a
  la BD de la escuela. Sin fijar la conexión, un catálogo universal se
  consultaría contra la BD equivocada. El trait lo ancla a
  `tenancy.database.central_connection` (= `mysql`).

### Seeders landlord separados de los de tenant
- **Decisión:** los seeders de catálogos universales viven en
  `database/seeders/Landlord/` y se orquestan con `LandlordDatabaseSeeder`, que
  se ejecuta **explícitamente** (`db:seed --class=...LandlordDatabaseSeeder`).
  NO se llaman desde `DatabaseSeeder`.
- **Razón:** `DatabaseSeeder` es el seeder raíz que `stancl/tenancy` corre por
  cada tenant. Meter datos landlord ahí contaminaría cada BD de escuela con
  copias de los catálogos universales, que por diseño son compartidos y viven
  solo en la central.

### `super_admins`: columnas más allá del mínimo de la spec
- **Decisión:** además de `id, nombre, email, password, rol`, la tabla lleva
  `remember_token` y `timestamps`.
- **Razón:** es una tabla de cuentas con login; `remember_token` habilita
  "recordar sesión" y los timestamps dan trazabilidad de alta. No altera el
  modelo de dominio.

### `entidades_federativas.clave`: código RENAPO/CURP de 2 letras
- **Decisión:** la `clave` usa el código de dos letras de RENAPO/CURP (AS, BC,
  DF, ...), con NE para nacidos en el extranjero. Único por `(pais_id, clave)`.
- **Razón:** es la clave que exige el título electrónico SEP y la que permite
  cross-validar la CURP. Se sembraron las 32 entidades + NE bajo México.

## 2026-07-21 — Fase 0.2: configuración por tenant

### Modelos de tenant organizados por módulo
- **Decisión:** los modelos de la capa TENANT se agrupan por módulo de la spec.
  Los de Fase 0.2 (plataforma/configuración) viven en `app/Models/Plataforma/`.
- **Razón:** los modelos landlord se agrupan por capa (`Landlord/`); los de
  tenant, por módulo (`Plataforma/`, luego `Identidad/`, `Academico/`, ...),
  espejando la organización de la spec. Escala mejor con ~110 modelos tenant.

### `auditoria` (bitácora): excepción a la convención de auditoría
- **Decisión:** la tabla `auditoria` NO lleva las columnas estándar de auditoría
  (`updated_at/deleted_at/created_by/updated_by`) ni soft delete; solo
  `created_at`. Su modelo no usa el trait `TieneAuditoria` y desactiva
  `updated_at` con `const UPDATED_AT = null`.
- **Razón:** la spec la define append-only y con solo `created_at`. Una bitácora
  que audita cambios de otras tablas no se audita a sí misma (sería recursivo).
  Ambigüedad detectada frente a la regla "toda tabla TENANT lleva auditoría":
  se resuelve tratándola como la excepción documentada. Es además el único uso
  justificado de columnas JSON (`valores_anteriores`, `valores_nuevos`).

### `DatabaseSeeder` es el seeder raíz de TENANT
- **Decisión:** se reconvirtió `DatabaseSeeder` (quitando el "Test User" del
  scaffolding) para que llame a los seeders de catálogos TENANT-CONFIG
  (`Tenant\ModuloSeeder`, ...). Los seeders de tenant viven en
  `database/seeders/Tenant/`.
- **Razón:** `stancl/tenancy` usa `DatabaseSeeder` como seeder raíz por tenant
  (`tenancy.seeder_parameters`). Debe sembrar solo datos de escuela, nunca los
  catálogos universales (esos van por `LandlordDatabaseSeeder`, aparte).

### `SeedDatabase` habilitado en el pipeline de creación de tenant
- **Decisión:** se activó `Jobs\SeedDatabase` en el `JobPipeline` de
  `TenantCreated` (TenancyServiceProvider). Ahora crear una escuela ejecuta
  CreateDatabase → MigrateDatabase → SeedDatabase de forma síncrona.
- **Razón:** cada nueva escuela debe nacer con su catálogo de módulos ya
  sembrado, sin un paso manual.

### `Tenant` implementa `TenantWithDatabase`
- **Decisión:** `App\Models\Tenant` declara
  `implements Stancl\Tenancy\Contracts\TenantWithDatabase` (además de usar
  `HasDatabase`).
- **Razón:** los jobs de gestión de BD por tenant (CreateDatabase,
  MigrateDatabase, SeedDatabase, DeleteDatabase) exigen ese contrato en su
  firma. Sin él, la creación del tenant falla con TypeError. Es requisito del
  modo multi-database.

## 2026-07-21 — Fase 1, Módulo 1 (Identidad, slice sin auth)

### Referencias a catálogos LANDLORD: sin FK real (cross-database)
- **Decisión:** las columnas de tablas TENANT que apuntan a catálogos landlord
  (`personas.sexo_id`, `genero_id`, `pais_nacimiento_id`,
  `entidad_nacimiento_id`, y futuras) son `unsignedBigInteger` **sin**
  `constrained()`. Las FKs dentro de la misma BD de tenant sí son reales.
- **Razón:** el tenant y la landlord son bases de datos distintas. Una FK
  cruzada hardcodearía el nombre de la BD central y stancl la desaconseja. La
  integridad se valida en la app; las relaciones Eloquent resuelven cross-DB
  porque los modelos landlord usan `CentralConnection` (verificado:
  `persona->sexo` consulta `mysql` mientras la persona vive en `tenant`).

### Módulo 1 partido: identidad-sin-auth ahora, credenciales después
- **Decisión:** de las 7 tablas del Módulo 1 se construyeron ahora solo
  `personas`, `temas`, `tema_tokens`. Se difieren `roles`, `usuarios`,
  `usuario_tema_override` y `persona_rol` a la fase de autenticación.
- **Razón:** (1) el usuario pospuso el auth explícitamente; (2) `usuarios` es la
  tabla de credenciales; (3) **colisión de nombre**: la spec define una tabla de
  dominio `roles` (clave, nombre, tiempo_sesion) pero también recomienda
  spatie/laravel-permission, que YA crea su propia tabla `roles`. Unificar los
  roles de dominio con los de Spatie —o mantenerlos separados con otro nombre—
  es una decisión de auth a tomar con el usuario. Pendiente registrado.

## 2026-07-21 — Aclaraciones del cliente sobre el ciclo del aspirante

Aclaraciones recibidas que afectan módulos ya construidos y por construir.
**Pendientes de decidir** antes de implementar Finanzas (Módulo 7) y el auth.

### La matrícula se genera al final, no antes  ✅ el esquema ya lo cumple
- **Aclaración:** un aspirante/interesado/prospecto NO tiene matrícula. La
  matrícula la genera un administrador como último paso antes de convertirlo
  en alumno.
- **Estado actual:** correcto sin cambios. `aspirantes` solo lleva
  `clave_aspirante` (identificador de CRM); la columna `matricula` vive
  únicamente en `matricula_oferta`, que se crea al momento de la conversión.

> **RESUELTAS el 2026-07-21.** Las tres decisiones se tomaron con el cliente;
> abajo se conserva el análisis original y al final de cada una se anota la
> resolución y su estado de implementación.

### Algoritmo de matrícula configurable por escuela  ✅ RESUELTO E IMPLEMENTADO
- **Aclaración:** cada escuela tiene su propio formato. Ejemplos: año (2 o 4
  dígitos) + clave de carrera o de plan + consecutivo por carrera/plan, o bien
  un consecutivo general. **El algoritmo es distinto en cada escuela.**
- **Lo que ya existe:** `planes_estudio.clave_matricula` y
  `clave_matricula_consecutivo` (previstos por la spec, per-plan).
- **Lo que falta decidir:**
  1. Dónde vive la regla: por plan (como hoy), por carrera, o a nivel escuela
     en `configuraciones`. Probablemente una tabla `reglas_matricula` con
     ámbito (global/carrera/plan) + plantilla de formato.
  2. **Dónde vive el consecutivo y cómo se hace atómico.** Es el punto
     crítico: dos administradores generando matrícula a la vez no deben
     obtener el mismo número. Requiere una tabla de contadores con
     `SELECT ... FOR UPDATE` (o `INSERT ... ON DUPLICATE KEY UPDATE`) dentro
     de la transacción de conversión, nunca un `MAX(matricula)+1`.
  3. El ámbito del consecutivo (por año, por carrera, por plan, global) es
     parte de la regla configurable.
- **RESOLUCIÓN:** regla por escuela con override opcional por carrera o plan.
  - `reglas_matricula` (TENANT-CONFIG): `ambito` global/carrera/plan +
    `ambito_id`, `plantilla` con tokens, `ambito_consecutivo`. Gana la más
    específica: plan → carrera → global.
  - Tokens de plantilla: `{AAAA}` `{AA}` `{CARRERA}` `{PLAN}` `{CAMPUS}` y
    `{####}` (el padding del consecutivo lo da la cantidad de `#`).
  - `ambito_consecutivo`: global | anio | carrera | plan | carrera_anio |
    plan_anio — define cada cuánto reinicia la numeración.
  - `contadores_matricula` + `App\Services\GeneradorMatricula` resuelven el
    consecutivo atómico. Regla por defecto sembrada: `{AAAA}-{####}` por año.
- **Lección aprendida (bug real detectado por la prueba de unicidad):**
  `contadores_matricula` NO debe tener columna `id` AUTO_INCREMENT. El
  incremento atómico usa
  `INSERT ... ON DUPLICATE KEY UPDATE valor = LAST_INSERT_ID(valor + 1)`, y un
  INSERT sobre una tabla con AUTO_INCREMENT **sobreescribe** `LAST_INSERT_ID()`
  con el id de la fila nueva. Con `id` la prueba daba 299 matrículas distintas
  de 300; con `clave` como PK da 500 de 500. Si alguna vez se agrega un
  surrogate id a esa tabla, se reintroduce el bug.

### El aspirante necesita sesión propia  ✅ encaja, sin cambio de esquema
- **Aclaración:** en fase de aspirante ya debe poder entrar al sistema para
  llenar formularios, aceptar reglamentos/lineamientos, cargar documentación y
  eventualmente pagar.
- **Estado actual:** encaja sin cambios. Un aspirante ES una persona, y
  `usuarios.persona_id` (tabla diferida al auth) apunta a `personas`.
- **Input para la fase de auth:** el login NO es de alumnos — es de personas
  con cualquier rol activo, incluido `aspirante`. El `rol_activo_id` gobierna
  qué ve. Esto refuerza mantener `usuarios` colgando de `personas`, no de
  `alumnos`.

### HUECO: el pago de inscripción del aspirante no tiene dónde colgar  ⚠️ PENDIENTE
- **Problema:** en la spec, `adeudos` y `pagos` (Módulo 7) cuelgan de
  `matricula_oferta_id`. Pero si el aspirante paga su inscripción ANTES de ser
  alumno, esa `matricula_oferta` todavía no existe: el pago no tiene ancla.
- **Opciones a evaluar (con el cliente) antes del Módulo 7:**
  1. Hacer `adeudos.matricula_oferta_id` nullable y agregar `aspirante_id`
     nullable, con un CHECK de que exactamente uno esté presente. Al convertir
     al aspirante, se re-ligan los adeudos/pagos a la nueva
     `matricula_oferta`. Preserva la trazabilidad del pago previo.
  2. Crear la `matricula_oferta` en estado "preinscrito" SIN matrícula
     definitiva — obliga a que `matricula` sea nullable, lo que choca con la
     aclaración de que la matrícula se genera al final.
  3. Tabla aparte `pagos_admision` que luego se concilia. Duplica el motor de
     cobro; menos deseable.
- **Recomendación preliminar:** opción 1 — mantiene un solo motor financiero y
  respeta que la matrícula nazca al final.
- **RESOLUCIÓN (opción 1). VINCULANTE al construir el Módulo 7:** no hay nada
  que implementar todavía porque `adeudos` y `pagos` son de la Fase 3. Cuando
  se creen, deben nacer así:
  - `adeudos.matricula_oferta_id` **nullable** + `adeudos.aspirante_id`
    nullable. Exactamente uno de los dos presente (validar en la app; MySQL 8
    permitiría un CHECK, evaluarlo entonces).
  - Lo mismo para `pagos`.
  - La conversión aspirante → alumno **re-liga** adeudos y pagos existentes a
    la nueva `matricula_oferta` dentro de la misma transacción en la que se
    genera la matrícula, conservando la trazabilidad del pago previo.
  - Índices por `aspirante_id` además de por `matricula_oferta_id`.

### HUECO: aceptación de reglamentos con valor legal  ✅ RESUELTO E IMPLEMENTADO
- **Problema:** hoy solo existe `aspirantes.acepto_terminos` (un booleano).
  Para efectos legales normalmente se requiere saber QUÉ documento se aceptó,
  en qué VERSIÓN, CUÁNDO y desde qué IP; y pueden ser varios documentos
  (reglamento, lineamientos, aviso de privacidad LFPDPPP).
- **Propuesta a evaluar:** catálogo `documentos_normativos` (clave, título,
  versión, vigencia, ruta) + tabla `aceptaciones` (persona_id,
  documento_normativo_id, version, fecha, ip). El booleano actual queda como
  atajo de UI, no como la fuente de verdad.
- **RESOLUCIÓN:** implementado tal cual.
  - `documentos_normativos` versionado con unique (clave, version), mismo
    patrón que `formularios`: al cambiar el texto se sube versión, no se muta.
    Scope `vigentes($fecha)` para consultar qué rige en una fecha.
  - `aceptaciones` cuelga de **`personas`** (no de aspirantes ni alumnos): la
    misma persona acepta documentos en distintas etapas y la constancia no debe
    perderse al convertirse en alumno. `version` se **copia** para congelar qué
    texto se aceptó. Guarda `aceptado_en` e `ip`.
  - `Aceptacion::estaVigente()` compara contra la versión actual del documento:
    así se detecta a quién hay que pedirle re-aceptación tras una actualización.
  - Verificado: publicar la v2 de un reglamento no altera las aceptaciones de
    la v1 y el sistema marca la re-aceptación como pendiente.

## 2026-07-21 — Slice de autenticación (cierra el Módulo 1)

### Roles unificados con Spatie, en dos niveles y con jerarquía
- **Aclaración del cliente:** existen roles como administrativo, docente,
  alumno, aspirante, tutor educativo y padre de familia; pero *dentro* de
  administrativo hay roles propios con permisos acotados (director general,
  director de un campus específico, encargado y auxiliar de admisiones,
  encargado y auxiliar de control escolar...).
- **Decisión:** un solo catálogo de roles, sobre la tabla `roles` de Spatie
  extendida con `nombre`, `tiempo_sesion` y `rol_padre_id`. El `name` de Spatie
  guarda la clave, así todo su API sigue operando.
  - **Faceta** = rol sin padre (lo que la persona ES; es lo que agrupa).
  - **Rol funcional** = cuelga de una faceta y HEREDA sus permisos
    (`Rol::permisosEfectivos()` recorre la cadena de ancestros).
  - Se descartó una bandera "es conmutable": la persona conmuta entre los roles
    que tenga asignados; la jerarquía solo hereda permisos y agrupa en la UI.
- **Razón:** la spec dice que los permisos se resuelven "acotados al
  `rol_activo_id`", o sea el rol de dominio ES el que carga permisos. Mantener
  dos catálogos obligaría a sincronizarlos y traducir en cada verificación.

### Alcance del rol por campus
- **Decisión:** `persona_rol` lleva `campus_id` nullable (NULL = alcance
  global) y PK surrogate, porque una persona puede tener el mismo rol en varios
  campus. Unique (persona_id, rol_id, campus_id).
- **Razón:** resuelve "director de un campus específico" sin inventar un rol
  por campus. Caveat: MySQL trata los NULL como distintos, así que el unique no
  impide dos filas globales del mismo par persona-rol; se valida en la app.

### `usuarios` es la tabla de credenciales; se eliminó `users`
- **Decisión:** se creó `usuarios` (spec, Módulo 1) colgando de `personas`, y
  se eliminaron la tabla `users` del scaffolding, el modelo `App\Models\User` y
  su factory. La migración original se renombró a `create_sessions_table` y
  conserva `sessions` y `password_reset_tokens`. `config/auth.php` apunta el
  guard `web` a `App\Models\Identidad\Usuario`.
- **Razón:** un solo concepto de usuario. El login es de PERSONAS con cualquier
  rol activo — un aspirante necesita sesión desde el día uno para llenar
  formularios, aceptar reglamentos y pagar, mucho antes de ser alumno.

### Resolución de permisos vía Gate, no vía HasRoles en el usuario
- **Decisión:** `Usuario` NO usa el trait `HasRoles` de Spatie. Los roles se
  asignan a la PERSONA (`persona_rol`), y un `Gate::before` en
  AppServiceProvider resuelve `can()` contra los permisos efectivos del rol
  activo. Devuelve `null` (no `false`) cuando no concede, para no cortar la
  cadena de policies.
- **Razón:** Spatie asigna roles al modelo autenticable y no conoce la bandera
  `activo` ni el alcance por campus. La verdad sobre qué es una persona vive en
  `persona_rol`; Spatie aporta el catálogo de permisos y el mapeo rol→permiso.
- El middleware `EstablecerRolActivo` (alias `rol.activo`) valida en CADA
  request que el `rol_activo_id` siga entre los roles activos de la persona y,
  si no, lo reasigna. Defensa contra manipulación del cliente.

### HUECO CORREGIDO: la landlord no tenía tablas de infraestructura
- **Problema detectado al sembrar permisos:** mover `cache`, `jobs` y
  `sessions` a la capa tenant (decisión del arranque) dejó a la BD central sin
  ellas. Con `CACHE_STORE=database`, spatie/laravel-permission cachea su tabla
  de permisos y falla con
  `Table 'acadion_landlord.cache' doesn't exist`.
- **Decisión:** la landlord recupera sus propias `cache`, `cache_locks`,
  `jobs`, `job_batches`, `failed_jobs`, `sessions` y `password_reset_tokens`.
- **Razón:** la landlord también es una aplicación real (panel de super
  admins) y necesita caché, colas y sesiones propias. Siguen siendo tablas
  distintas de las de cada tenant, así que el aislamiento se mantiene.

### `personas`: FULLTEXT y `curp` único-nullable
- **Decisión:** índice FULLTEXT sobre (nombre, primer_apellido,
  segundo_apellido, curp); `curp` es UNIQUE y NULLable (MySQL permite múltiples
  NULL en índice único). `sexo_id` es NOT NULL (per spec); los demás refs
  landlord son nullable.
- **Razón:** búsqueda de personas como en el legacy IMEP; la CURP es llave
  natural cuando existe pero muchas personas se dan de alta sin ella todavía.

## 2026-07-21 — Fase 2, cierre: captura de calificaciones y acta

Dos huecos de la spec detectados al implementar. Ambos se consultaron con el
cliente antes de escribir código, según la regla del proyecto.

### HUECO: no había dónde vivieran las calificaciones capturadas  ✅ RESUELTO
- **Problema:** `esquema_evaluacion` define CÓMO se compone la calificación
  (parcial_1 30%, final 40%...) y `inscripcion.calificacion_final` guarda el
  resultado, pero la spec no define ninguna tabla para los valores que el
  docente captura. La regla de negocio dice "combinando parciales capturados"
  sin decir dónde.
- **Opciones evaluadas:** (1) tabla relacional por componente; (2) capturar
  solo la final, dejando `esquema_evaluacion` como documentación; (3) columna
  JSON en `inscripcion`.
- **RESOLUCIÓN (opción 1):** `calificaciones_componente` (TENANT), una fila por
  `inscripcion` × componente del `esquema_evaluacion`, con `capturado_por`
  (persona) y `capturado_en`. Único (inscripcion_id, esquema_evaluacion_id).
  - Se descartó el JSON por coherencia: `esquema_evaluacion` existe justamente
    porque se rechazó el `ponderacion_config` jsonb del legacy.
  - Se descartó capturar solo la final porque no permite recalcular, no deja
    traza de quién puso cada número, y el LMS (Módulo 8) no tendría dónde
    volcar su componente cuando se construya.
  - `calificacion` es NULLable a propósito: **NULL no es cero**. Un componente
    sin capturar deja la calificación INCOMPLETA y bloquea el cierre del acta;
    no se pondera como 0. Un cero es una calificación (no presentó); un NULL es
    que el docente todavía no llega ahí. Cerrar el acta tratándolos igual
    reprobaría alumnos por descuido.

### HUECO: el acta era un varchar, no una entidad  ✅ RESUELTO
- **Problema:** la spec solo previó `historial.acta_folio varchar(50)`. Con eso
  no se sabe quién firmó el acta, cuándo, ni se puede reimprimir o corregir de
  forma controlada. Además el folio necesita un consecutivo sin colisiones, el
  mismo problema que ya resolvió la matrícula.
- **RESOLUCIÓN:** tabla `actas` (TENANT) + `contadores_acta`.
  - `actas`: asignatura_grupo, tipo_evaluacion, folio único, situación
    (abierta/cerrada/cancelada), `cerrada_por` (PERSONA, no usuario: quien firma
    es el docente y su cuenta puede desaparecer), `cerrada_en`, `acta_origen_id`
    y observaciones.
  - `historial.acta_folio` **se conserva** (es el dato de la spec y lo que se
    imprime) y se acompaña de `historial.acta_id` como FK real.
  - `situacion` va como varchar con constantes en el modelo, NO como catálogo
    TENANT-CONFIG: sus tres valores son la máquina de estados del código, no
    algo que una escuela deba renombrar. Mismo criterio que `inscripcion.tipo`.
  - **Corregir no es editar.** Una calificación asentada no se toca: se emite
    un acta de corrección (`acta_origen_id`), y al firmarla los renglones de
    historial académico de la original se dan de baja lógica y se asientan los nuevos. Ambas
    actas quedan. Es lo que ya insinuaba `observaciones_historial` con
    "Corrección de calificación".
  - `contadores_acta` repite el patrón de `contadores_matricula`, **incluida la
    ausencia de `id` AUTO_INCREMENT**: un INSERT sobre una tabla que lo tenga
    sobreescribe LAST_INSERT_ID() y rompe el incremento atómico.
  - El folio se emite al CERRAR, no al abrir: un acta abandonada sin capturar
    no debe quemar un número del consecutivo del archivo. Si la transacción de
    cierre falla, el consecutivo se pierde — un hueco en la numeración es
    preferible a un folio repetido.
  - Formato configurable desde `configuraciones` (`acta.formato_folio`,
    `acta.ambito_consecutivo`) y no con una tabla de reglas propia como la
    matrícula: a diferencia de aquella, que la escuela quiere distinta por
    carrera y plan, el folio del acta es un consecutivo de archivo, uno solo
    para toda la escuela.

### Autorización de la captura: el permiso no basta, hace falta el alcance
- **Decisión:** dos capas. El permiso (`capturar-calificaciones` nuevo,
  `asentar-acta` ya existente) dice QUÉ puede hacer el rol activo; estar dado
  de alta en la tabla `docentes` dice SOBRE QUÉ materias.
  - Docente titular: captura y firma sus materias. Adjunto: captura, no firma.
  - Control escolar (no aparece en `docentes`): captura y firma cualquiera —
    ausencia o baja del docente. El auxiliar captura pero no firma.
- **Razón:** el rol `docente` TIENE `asentar-acta` (firma sus propias actas),
  así que ese permiso no puede distinguir "el docente de esta materia" de
  "control escolar". Sin la segunda capa, cualquier docente calificaría al
  grupo de otro.
- Caso de datos incompletos: si la persona opera con rol `docente` (o un rol
  que desciende de esa faceta) pero le falta el expediente en `docentes`, se le
  acota igual a sus materias. Ante datos inconsistentes se elige restringir de
  más, nunca de menos.

### Una materia se asienta UNA vez
- **Decisión:** `AsentadorActa::impedimentos` rechaza cerrar un acta ordinaria
  si la materia ya tiene otra cerrada del mismo tipo de evaluación. Reasentar
  solo se hace por la vía de la corrección, que sí sustituye lo anterior.
- **Razón (bug real detectado en la prueba por HTTP):** sin esa regla se podía
  firmar una segunda acta ordinaria sobre la misma materia-grupo y el historial académico
  quedaba con el alumno DUPLICADO en la misma materia, sin ningún aviso. La
  captura ya estaba protegida; el cierre no. Caso agregado a la suite de
  regresión (`scripts/prueba-actas.php`, sección 5b).

### Otras reglas del motor de cálculo
- Si los porcentajes del `esquema_evaluacion` no suman 100, NO se calcula nada
  y se reporta el motivo. Vale más una materia sin calificación que un historial académico
  con números que nadie puede reproducir.
- Aprobado lo define `planes_estudio.calificacion_minima_aprobatoria`, no una
  constante: cada plan tiene su escala.
- Un recursamiento se asienta en el historial académico con `tipos_evaluacion` =
  recursamiento aunque el acta del grupo sea la ordinaria.
- Un acta firmada después de `ciclos.captura_calif_hasta` marca el renglón como
  `acta_extemporanea`; no se bloquea (la escuela sabrá por qué se atrasó).
- Los alumnos con inscripción dada de baja NO entran al acta.
- El motivo de reprobación (examen, faltas, no presentó) queda en NULL: el
  sistema no puede deducirlo de un número. Lo asienta control escolar.

### `acadion:usuario-demo` ya no pisa el rol activo
- **Síntoma observado:** el usuario demo aparecía de pronto con
  `rol_activo_id` = encargado_admisiones sin que nadie hubiera conmutado.
- **Causa (reproducida):** el comando hacía `Usuario::updateOrCreate` con
  `'rol_activo_id' => encargado_admisiones` fijo, así que **cada** ejecución
  —cosa que se hace seguido durante el desarrollo— sacaba al usuario del rol
  en el que estaba trabajando, en silencio. No era un fallo del login ni del
  middleware: ambos se verificaron y se comportan como su contrato dice.
- **Decisión:** el comando fija el rol activo **solo al crear** el usuario, o
  cuando el que trae dejó de estar entre sus roles activos. Restablecer la
  CONTRASEÑA sí es su propósito; cambiarle el contexto de trabajo, no. De paso
  reporta los roles reales de la persona en vez de una lista hardcodeada.
- **Verificado:** el rol sobrevive a dos ejecuciones seguidas, y revocando el
  rol activo en `persona_rol` el middleware `EstablecerRolActivo` sigue
  reasignando al siguiente request, como se diseñó.

### Las pruebas de integración se versionan
- **Decisión:** `scripts/prueba-actas.php` entra al repo (43 verificaciones con
  rollback contra el tenant demo).
- **Razón:** phpunit está configurado contra SQLite en memoria y aquí se prueba
  justamente lo que SQLite no sabe hacer: `LAST_INSERT_ID` de MySQL, FKs reales
  e InnoDB bajo transacción. Hasta ahora estos scripts eran efímeros; el bug
  del doble asentamiento apareció por accidente y se habría perdido sin una
  suite que lo fijara.

## 2026-07-21 — Aclaraciones del cliente sobre operación escolar

Seis observaciones al probar la captura. Tres tocaban esquema y se resolvieron
con él antes de escribir código; tres eran de interfaz.

### Un ciclo aplica a VARIOS campus  ✅ RESUELTO E IMPLEMENTADO
- **Aclaración:** una escuela con 5 campus abre el mismo ciclo en 2 o 3, no en
  uno solo ni en todos.
- **Lo que había:** `ciclos.campus_id` (un campus, o NULL = global), con unique
  (campus_id, clave). Para aplicar un ciclo a tres campus había que crear tres
  ciclos con la misma clave, y entonces "2026-2027/1" dejaba de ser UN periodo:
  las inscripciones quedaban repartidas entre ciclos que eran el mismo.
- **RESOLUCIÓN:** pivote `ciclo_campus` (N:M). Se eliminó `ciclos.campus_id` y
  la clave pasa a ser única en toda la escuela, porque el campus ya no forma
  parte de la identidad del ciclo.
  - **Sin filas en el pivote = ciclo global.** Misma semántica que tenía el
    NULL, ahora expresada por ausencia.
  - `scopeDelAlcance` y `scopeParaCampus` incluyen siempre los globales: son de
    la escuela entera, así que son de todos.
  - Migración con backfill (los ciclos existentes conservan su campus) y
    re-ejecutable: MySQL no tiene DDL transaccional, así que cada paso comprueba
    su estado y un fallo a medias no obliga a limpiar a mano. La FK de
    `campus_id` se suelta en su propia sentencia porque se apoya en el índice
    unique que hay que borrar y MySQL no deja soltar ese índice antes.
  - `down()` conserva un solo campus por ciclo (el de menor id): la vuelta atrás
    no puede representar lo multi-campus, y es honesto decirlo.

### "Los campus del administrador" = el alcance de su ROL  ✅ IMPLEMENTADO
- **Aclaración:** un administrador solo debe ver y elegir los campus que tiene
  dados de alta.
- **RESOLUCIÓN:** se usa `persona_rol.campus_id`, que existía desde el slice de
  auth para resolver "director de un campus específico" y no se estaba usando
  para filtrar NADA. Se descartó interpretarlo como "los campus que esa persona
  creó" (`created_by`): si un administrador da de alta un campus y luego lo
  administra otro, el segundo no lo vería.
  - `Usuario::campusVisibles()` devuelve **null** con alcance global y un
    arreglo cuando está acotado. Se distingue null de arreglo vacío a
    propósito: null es "todos", y vacío sería "ninguno", que nunca es lo que se
    quiere decir. `alcanzaCampus()` es el predicado puntual.
  - **Editar no destruye lo que no se ve.** Un administrador acotado que edita
    un ciclo multi-campus solo sincroniza los suyos; los demás se preservan.
    Sin esa regla, guardar desde un campus habría desvinculado los otros.
  - **El formulario solo recibe los campus que el usuario puede tocar** (bug de
    usabilidad detectado al probar por HTTP): si se le mandaban todos, abría el
    ciclo, no tocaba nada, guardaba, y le rebotaba un "campus fuera de tu
    alcance" por un valor que él nunca eligió. Los ajenos se listan aparte como
    contexto de solo lectura.
  - Un id de campus ajeno enviado en el payload se rechaza con mensaje, no en
    silencio: suele delatar una pantalla que ya no corresponde al rol activo.

### Selección múltiple con casillas, no `<select multiple>`
- **Decisión:** componente `CampoCasillas.vue`, con buscador que aparece solo
  cuando la lista pasa de 8 opciones.
- **Razón:** el `<select multiple>` nativo exige Ctrl+clic para marcar varias y
  para deseleccionar —cosa que casi nadie descubre— y no deja ver qué está
  marcado sin desplazarse. Con 4 campus un buscador estorba; con 50 materias es
  indispensable, de ahí el umbral.

## 2026-07-21 — Plantillas de evaluación (bloque 2 de las aclaraciones)

### El esquema por materia no escalaba  ✅ RESUELTO E IMPLEMENTADO
- **Aclaración:** hay ofertas de 2 parciales, otras de 3, otras con rubros que
  van directo al curso ("10% asistencia, 50% examen final, 40% actividades"), y
  a veces se quiere una ponderación equitativa automática.
- **Lo que ya servía sin tocar nada:** `esquema_evaluacion` (componente,
  `parcial` nullable, porcentaje) YA expresa los tres casos. "Parcial 1:
  asistencia 10% + examen 15%" son dos filas con `parcial=1`; "directo al curso"
  son filas con `parcial=null`; 2 o 3 parciales es cuántas filas hay. No hizo
  falta rediseñar nada de eso.
- **Lo que faltaba:** el esquema cuelga de `plan_materias`, así que configurarlo
  obligaba a repetir los mismos porcentajes en las 50 materias de un plan.
- **RESOLUCIÓN:** `plantillas_evaluacion` + `plantilla_componentes`, con
  `planes_estudio.plantilla_evaluacion_id` (criterio por defecto del plan) y
  `plan_materias.plantilla_evaluacion_id` (de qué plantilla salió su esquema).
  - **Los componentes se MATERIALIZAN**, no se leen en vivo. Al aplicar la
    plantilla se copian como filas de `esquema_evaluacion` en cada materia.
    Razón: `calificaciones_componente` apunta a `esquema_evaluacion_id`;
    resolver el esquema en tiempo real obligaría a que una calificación
    apuntara a veces a una tabla y a veces a otra, sin ganar nada.
  - **`plantilla_evaluacion_id` en NULL = esquema propio**, armado a mano. Esas
    materias no se pisan al re-propagar.
  - **Editar el esquema de una materia la desliga sola de su plantilla**
    (`EsquemaEvaluacionController`). Sin esto, la siguiente re-propagación
    borraría el ajuste sin avisar; con esto, la regla "editar la plantilla
    cambia todas" solo alcanza a las que nadie ha tocado.
  - Una plantilla que no suma exactamente 100% **no se puede aplicar**: dejaría
    materias que el motor de calificaciones no sabe calcular.
  - Borrar una plantilla en uso está prohibido (dejaría materias con su esquema
    materializado y sin saber de dónde salió); se desactiva en su lugar.

### Lo capturado nunca se pisa
- **Decisión:** una materia con calificaciones ya capturadas NO se re-aplica.
  Se reporta como bloqueada, con su nombre, y el resto sí se actualiza.
- **Razón:** reemplazar el esquema a media evaluación dejaría huérfano lo
  capturado y movería calificaciones que un docente ya asentó. Se advierte
  ANTES de guardar (la pantalla lista cuáles no se van a tocar) en vez de
  sorprender después.

### Reparto equitativo: el problema no es dividir, es que sume 100
- **Decisión:** `RepartidorPorcentajes` usa el método del resto mayor: reparte
  el piso en centésimas enteras y entrega los centavos sobrantes de uno en uno
  a los primeros rubros.
- **Razón:** 100 entre 3 no da un número exacto de centésimas. Redondear cada
  parte por separado produce 33.33 × 3 = 99.99, y un esquema que no suma 100 es
  precisamente el que el motor rechaza — o sea que el reparto "automático"
  dejaría la materia sin poder calificarse. Con el resto mayor la suma es
  exactamente 100 y la diferencia entre el rubro mayor y el menor nunca pasa de
  0.01. Verificado para 1, 2, 3, 4, 6, 7, 9 y 11 rubros.
- Se trabaja en centésimas enteras, no en flotantes, para que el reparto cuadre
  al centavo.

### HUECO CORREGIDO: los avisos que no eran ni éxito ni error se perdían
- **Problema detectado al probar por HTTP:** `HandleInertiaRequests` solo
  compartía `exito` y `error`. El mensaje más importante de esta feature —"se
  aplicó a 40 materias, 3 no se tocaron porque ya tienen calificaciones"— usaba
  una tercera clave y **desaparecía en silencio**.
- **Decisión:** se agrega `advertencia` a las props compartidas, a la interfaz
  `Flash` y al `AppLayout` (banda ámbar).
- **Razón:** una operación puede terminar bien y aun así tener algo que el
  usuario necesita saber. Forzar ese caso a "éxito" oculta información, y a
  "error" miente sobre lo que pasó.

## 2026-07-21 — Calendario de captura por parcial (bloque 3)

### `ciclos.captura_calif_hasta` no servía para lo que se pedía  ✅ RESUELTO
- **Aclaración:** la captura de cada parcial debe poder activarse y desactivarse
  a demanda, con sus propias fechas, y un administrador debe poder reabrírsela a
  un docente concreto.
- **Lo que había:** UNA sola fecha por ciclo que además **no bloquea nada**:
  solo marca el acta como extemporánea al asentarla. Inútil para una escuela que
  corta el primer parcial en octubre y el segundo en diciembre.
- **RESOLUCIÓN:** `ventanas_captura` (por ciclo y parcial) + `excepciones_captura`.
  - `parcial` en NULL cubre los rubros que van directo al curso, espejando a
    `esquema_evaluacion`.
  - **Sin ventanas configuradas, el ciclo captura libre.** Deliberado: la
    escuela que no quiere gestionar calendario no configura nada, y los ciclos
    que ya existían siguen comportándose igual que antes.
  - **Un corte sin ventana propia, en un ciclo que sí las gestiona, también
    queda abierto.** La escuela configuró unas y no otras; bloquear lo no
    configurado sería adivinar su intención.
  - `activa` apaga y enciende sin borrar, que es como se opera ("ábrele otra vez
    el primer parcial una semana").
  - **Las dos fechas conviven y son cosas distintas**, y así se explica en la
    pantalla: `captura_calif_hasta` marca el acta como extemporánea al
    asentarla; las ventanas impiden capturar. Se mantuvo la primera porque el
    asentador la usa para `observaciones_historial`.

### La excepción es una decisión administrativa y se audita
- **Decisión:** `excepciones_captura` guarda hasta cuándo, el motivo (mínimo 10
  caracteres) y **quién la autorizó**. Se concede por materia; `persona_id` en
  NULL la extiende a cualquier docente de esa materia, que es el caso común
  cuando el titular cambió a media captura.
- **Razón:** reabrir una captura vencida es una decisión que después alguien va
  a cuestionar. Sin autor ni motivo, la pregunta "¿quién le abrió esto?" no
  tiene respuesta. Revocar usa soft delete: la excepción se concedió, y eso no
  deja de ser cierto porque se haya retirado después.
- Una ventana con excepciones colgando no se puede borrar (se llevaría el
  rastro); se desactiva.

### El "por qué no" importa tanto como el "no"
- **Decisión:** `CalendarioCaptura` no devuelve un booleano sino el estado por
  corte con su motivo redactado: "La captura de Primer parcial cerró el
  11/07/2026", "…abre el 26/07", "…está desactivada", "Abierto por excepción
  hasta el 28/07".
- **Razón:** un docente que ve una columna bloqueada sin explicación llama a
  control escolar. La hoja de captura deshabilita esas columnas y muestra el
  motivo; un campo editable que el servidor va a rechazar es peor que uno
  bloqueado.

### Guardar parcialmente en vez de fallar entero
- **Decisión:** si la hoja trae calificaciones de un corte cerrado, se guardan
  las de los cortes abiertos y se advierte de las otras (`flash.advertencia`).
- **Razón:** hacer fallar toda la hoja por una columna cerrada le haría perder
  al docente la captura de las demás. El servidor revalida siempre, porque la
  ventana pudo cerrarse entre que se pintó la pantalla y se envió el formulario.
- Verificado por HTTP: enviando dos calificaciones, una a un corte cerrado y
  otra a uno abierto, la cerrada conservó su valor anterior y la abierta se
  actualizó.

### Permiso propio: `gestionar-ventanas-captura`
- **Decisión:** permiso nuevo, para director general y encargado de control
  escolar. No se reutilizó `abrir-grupos`.
- **Razón:** definir el calendario de captura y reabrírsela a un docente es una
  facultad distinta de abrir grupos, y el proyecto favorece permisos granulares.
  El docente NO lo tiene: se le concede la excepción, no se la otorga él.

## 2026-07-21 — Interfaz de grupos (bloque 4)

Tres cambios de pantalla, sin esquema nuevo.

### Carrera → plan, en cascada
- **Problema:** el formulario de grupo ofrecía UN desplegable con todos los
  planes de la escuela. Con seis carreras de cuatro planes cada una son 24
  opciones, y —caso real reproducido en la demo— dos carreras distintas pueden
  tener un plan llamado igual ("Plan 2026"): en la lista son indistinguibles y
  es fácil atar el grupo a la carrera equivocada.
- **Decisión:** selector de carrera que filtra los planes. La carrera **no se
  persiste**: el grupo sigue guardando solo `plan_id`, porque la carrera ya se
  deduce del plan. Es un filtro de pantalla, y así se rotula.
- Al editar, la carrera se deduce del plan guardado. Cambiar de carrera limpia
  el plan si dejó de pertenecer a ella, en vez de dejar una selección inválida.
- Los planes se listan como "CLAVE · nombre", que es lo que los distingue.

### Apertura de materias: filtro por periodo y selección múltiple
- **Problema:** las materias se abrían de una en una en un desplegable con toda
  la malla. Un plan de nueve semestres trae cincuenta materias y abrir un grupo
  casi siempre significa "las de tercero".
- **Decisión:** `materiasDisponibles` devuelve `periodo` como campo suelto (ya
  no embebido en la etiqueta), la pantalla filtra por él y las materias se
  marcan con casillas. `POST .../materias` recibe `plan_materia_ids` en lote.
- **Las repetidas se omiten y se dicen**, no se fallan: si el lote trae tres ya
  abiertas y una nueva, se abre la nueva y se advierte de las tres. Rechazar el
  lote entero obligaría a rehacer la selección por un dato que el usuario no
  tenía por qué recordar.

### Buscador de docentes con los asignados marcados, no ocultos
- **Problema:** un `<select>` con todos los docentes es impracticable en una
  escuela con doscientos, y volvía a ofrecer a quien ya impartía la materia.
- **Decisión:** componente `CampoBuscador.vue` (selección única con filtro por
  texto). Los docentes ya asignados aparecen **deshabilitados con su papel al
  lado** ("ya es titular"), no desaparecen.
- **Razón de no ocultarlos:** ver el nombre marcado explica por qué no se puede
  elegir; que el nombre no aparezca hace dudar de si esa persona está dada de
  alta como docente, y manda al usuario a buscarla al catálogo.
- El controlador expone `docentes_asignados` (id + tipo) por materia; antes solo
  viajaban los nombres, con los que no se puede comparar.

### Nota sobre los datos de la escuela de prueba
La demo tenía una sola carrera y tres materias, con lo que ninguna de estas tres
pantallas se podía valorar. Se le cargó una segunda carrera (Derecho, con un
plan también llamado "Plan 2026", a propósito) y una malla de catorce materias
en cuatro periodos. Son datos de la BD local, no un seeder del repo.

## 2026-07-21 — El docente no es personal administrativo

### El problema, con datos
El rol `docente` tenía `ver-grupos` y `ver-alumnos`, así que le aparecía Control
escolar entero: ciclos y grupos de TODA la escuela, pantallas pensadas para otro
oficio. `GrupoController::index` tampoco filtraba por pertenencia — cualquier
docente podía abrir el detalle de cualquier grupo. Solo la captura estaba
acotada.

### RESOLUCIÓN: sección "Docencia" propia, no filtros sobre las ajenas
- Al rol `docente` se le quitan `ver-grupos` y `ver-alumnos`. Gana
  `ver-mis-materias` y `editar-mi-expediente`.
- Rutas nuevas fuera de `/escolar`: `/docencia` (mis materias), 
  `/docencia/materias/{ag}` (mis alumnos) y `/docencia/expediente`.
- **La captura se mudó de `/escolar/captura` a `/captura`.** Estaba dentro del
  grupo que exige `ver-grupos`, así que quitarle ese permiso al docente le
  habría cerrado la captura. Vive en su propio prefijo porque la usan los dos
  oficios: el docente sobre lo suyo y control escolar sobre cualquier materia.
- Se descartó "mismo menú, todo filtrado": dejaría al docente dentro de
  pantallas donde casi todo le queda vacío, y cualquier pantalla futura que se
  olvide de filtrar se le abriría por accidente. Lo que no debe ver, no existe
  para él.
- El alcance sigue saliendo de `docente_asignatura_grupo`, no del permiso: cada
  consulta arranca de ahí, así que no se llega a la materia de otro cambiando un
  id en la URL. Verificado: 403 en materia ajena, 403 en su captura.

### BUG ENCONTRADO: el filtro "solo mis materias" nunca se había ejecutado
- `whereHas('docentes', fn ($q) => $q->where('personas.id', ...))` estaba mal:
  la relación cuelga de la tabla `docentes` (PK `persona_id`), no de `personas`.
  La consulta reventaba con `Unknown column 'personas.id'`.
- **Por qué no se había visto:** ese filtro solo corre para docentes, y todas
  las pruebas anteriores se hicieron con un usuario de control escolar, que
  toma la otra rama. El bug estaba en `CapturaCalificacionesController` desde el
  hito de captura y solo apareció al entrar por primera vez como docente real.
- Lección: probar una rama con el rol equivocado no prueba nada. La suite
  `prueba-alcance-docente.php` fija el caso.

### `documentos_docente`: expediente mínimo, no Módulo 10
- **Decisión:** tabla propia que espeja a `expediente_documentos` (el del
  aspirante) y reutiliza sus catálogos: `documentos_requeridos` para el tipo y
  `estados_documento` para la revisión. Son el mismo problema —alguien sube
  comprobantes y otro los valida— y no merecen dos motores.
- **Por qué no `expedientes_laborales`** (Módulo 10, Fase 4): aquello guarda
  contrato, régimen fiscal, puesto y adscripciones, que captura RH y no el
  docente. Adelantarlo metería media Fase 4 fuera de orden.
- Único (persona_id, documento_id): **re-subir reemplaza, no acumula**, y borra
  el archivo anterior del disco. Es lo que espera quien corrige un escaneo malo,
  y evita amontonar datos personales que nadie va a consultar.
- Re-subir **reinicia la revisión a pendiente**: el archivo cambió, así que el
  visto bueno anterior ya no dice nada del nuevo.
- Un documento ya **aceptado no lo borra el docente**: es el comprobante en el
  que la escuela se apoyó para acreditarlo.
- Lo que el docente NO controla y se le muestra de solo lectura: clave de
  profesor, cédula, tipo, situación y campus. Subir un título no es acreditarlo.

## 2026-07-21 — Gestión de alumnos

### El alumno es la MATRÍCULA, no la persona
- **Decisión:** el listado y el expediente cuelgan de `matricula_oferta`, no de
  `personas`. La búsqueda devuelve matrículas.
- **Razón:** la misma persona puede cursar una licenciatura y una maestría, y
  cada una tiene su matrícula, su historial académico y su situación. Quien busca en control
  escolar busca una matrícula concreta, no "a la persona". El expediente lista
  las OTRAS matrículas de esa persona con enlace, que es como se navega entre
  ellas.
- Consecuencia visible en la edición, y por eso se rotula en la pantalla:
  corregir el nombre alcanza a TODAS sus matrículas —es la misma persona—,
  mientras que situación y estatus son de esta inscripción a oferta. Verificado
  en la suite: se cambia el estatus de una y la otra no se entera.

### La carga de materias no se edita aquí
- **Decisión:** el expediente MUESTRA la carga por ciclo pero no deja
  inscribir ni dar de baja; eso sigue en Inscripciones.
- **Razón:** ahí vive `ValidadorInscripcion` con sus seis reglas (seriación,
  cupo, choque de horario, ventana del ciclo). Duplicar la operación aquí daría
  dos caminos para lo mismo y uno de los dos acabaría sin validar.

### Búsqueda con LIKE, no con el índice FULLTEXT
- **Decisión:** se busca por matrícula (que vive en `matricula_oferta`), CURP y
  nombre completo con `CONCAT_WS(...) LIKE`, pese a que `personas` tiene un
  índice FULLTEXT.
- **Razón:** FULLTEXT indexa palabras completas, así que escribir "Her" no
  encuentra "Hernández" — y una caja de búsqueda se teclea de a poco, con
  resultados en vivo. `CONCAT_WS` además permite teclear "nombre apellido"
  juntos, que es como se busca de verdad.
- La colación `utf8mb4_unicode_ci` ignora acentos, así que "Ibanez" encuentra
  "Ibáñez" y "Nuno" encuentra "Ñuño". Verificado, porque nadie teclea acentos
  cuando busca de prisa.
- **Deuda anotada:** con decenas de miles de alumnos el `LIKE '%...%'` deja de
  usar índice. Ahí es donde habría que cambiar a FULLTEXT en modo booleano con
  comodín, o a una columna de búsqueda normalizada.

### El promedio no cuenta lo que no tiene calificación
- **Decisión:** `resumen.promedio` solo promedia los renglones del historial académico con
  número. Una materia en curso no promedia como cero.
- **Razón:** lo contrario haría que el promedio bajara al inscribirse, que es
  exactamente al revés de lo que significa.

## 2026-07-21 — Catálogo de docentes

### El alta reutiliza a la persona
- **Decisión:** dar de alta un docente busca la CURP primero; si existe, se
  reutiliza esa persona y solo se crea el registro `docentes`. Los campos vacíos
  del alta NO pisan lo que ya estaba.
- **Razón:** mismo principio de cero recaptura que en admisiones. Quien entra
  como docente pudo haber sido alumno, ser tutor de alguien o haber estado dado
  de alta antes; duplicar la persona rompe el historial académico, los roles y el expediente
  que ya tuviera.
- `docentes` tiene PK `persona_id`, así que la reutilización es literal: el
  docente ES esa persona.

### La revisión de documentos cierra el ciclo que faltaba
- **Problema:** el portal del docente ya permitía cargar comprobantes, pero
  nadie tenía pantalla para validarlos. Todo se quedaba en "Pendiente" para
  siempre y el expediente no acreditaba nada.
- **Decisión:** en la ficha del docente se acepta o se rechaza cada documento.
  **Rechazar sin observación está prohibido**: se valida en el servidor.
- **Razón:** un rechazo sin motivo obliga al docente a adivinar qué corregir, y
  la observación es justo lo que él ve en su portal. Ciclo verificado de punta a
  punta: sube → rechazo con motivo → lo lee → re-sube → vuelve a pendiente.
- El listado muestra cuántos documentos tiene cada docente **por revisar**: es
  la acción pendiente de control escolar y no debería haber que entrar a cada
  ficha para descubrirla.

### Dar de baja no es borrar
- **Decisión:** un docente con materias asignadas no se elimina; se cambia su
  situación a baja.
- **Razón:** firmó actas y su nombre aparece en el historial académico de sus alumnos.
  Borrarlo dejaría esas actas sin responsable.

### Qué edita cada quien sobre el mismo docente
- Control escolar: clave de profesor, cédula, tipo, situación, campus y alcance
  de edición en el LMS. Son las credenciales que la escuela otorga.
- El docente, desde `/docencia/expediente`: sus datos de identidad y contacto, y
  sus documentos. Lo demás lo ve de solo lectura.
- La frontera es la misma en las dos pantallas y así se rotula en ambas: subir
  un título no es acreditarlo.

## 2026-07-21 — UI transversal de listados (bloque A de la segunda tanda)

### Filtros a demanda, con fichas de lo aplicado
- **Decisión:** componente `PanelFiltros.vue`. Un botón despliega el panel, una
  casilla activa cada filtro y solo entonces aparece su selector. Lo aplicado se
  muestra siempre como fichas con "×", aunque el panel esté cerrado.
- **Razón:** con cuatro o cinco desplegables siempre visibles, el encabezado del
  listado ocupa más pantalla que los resultados, y en la mayoría de las búsquedas
  no se usa ninguno. Las fichas son la otra mitad de la decisión: **un filtro
  activo escondido es la causa clásica del "no aparece el alumno que busco"**.
- Desmarcar la casilla limpia el valor, no solo lo oculta. Dejarlo puesto pero
  invisible mantendría la lista filtrada sin que se vea por qué.

### La foto vive en `personas`, no en `usuarios`
- **Decisión:** `personas.foto_url`, servida por `/personas/{id}/foto` desde el
  disco privado.
- **Razón:** `usuarios.url_perfil` ya existía, pero es el avatar de la CUENTA y
  no todos tienen cuenta: un alumno de primer ingreso, un docente recién dado de
  alta o un tutor pueden no tenerla y aun así su ficha necesita cara. La foto es
  de la persona, igual que su nombre.
- Nunca en `public/`: es un dato personal (LFPDPPP) y se sirve por ruta
  autenticada. Verificado que sin sesión no se alcanza.
- Un solo endpoint para toda la escuela. Quién puede cambiarla: uno mismo
  siempre, y quien administre a esa clase de persona —se comprueba contra lo que
  la persona ES (alumno, docente) y no contra un permiso genérico que no
  distingue a quién—.

### Vista de lista y de cuadrícula
- **Decisión:** `SelectorVista.vue` alterna ambas y recuerda la preferencia POR
  LISTADO en localStorage. `TarjetaPersona.vue` sirve igual a alumnos y docentes.
- **Razón:** lo que cambia entre un alumno y un docente son los datos
  secundarios, no la forma: cara, nombre, identificador y dos líneas de
  contexto. Un componente por rol habría sido el mismo archivo copiado.
- Sin foto se muestran las **iniciales**, no un icono genérico: en una cuadrícula
  de veinte personas, veinte iconos idénticos no distinguen a nadie.

### `Paginacion.vue` extraído
- El mismo bloque estaba copiado en cada listado, y cada copia era una
  oportunidad de que una lista quedara sin paginar y cargara la escuela entera.

### CORRECCIÓN DE HIGIENE: las pruebas no deben mutar el estado compartido
- **Problema:** `prueba-ciclo-campus.php` tomaba `Usuario::first()` —la cuenta
  demo— y le cambiaba el rol activo. Aunque corre en una transacción con
  rollback, el efecto se filtraba a las sesiones abiertas del navegador y dejaba
  a esa cuenta con un rol que nadie eligió. **Tres veces** se diagnosticó un 403
  que era residuo de la propia prueba.
- **Decisión:** la prueba crea su propio usuario y su propia persona.
- **Lección:** una prueba no debe alterar el estado que otros están usando, ni
  siquiera dentro de una transacción.

## 2026-07-21 — Varias carreras de la misma persona (bloque B)

### Matricular a quien YA es alumno de la casa
- **Problema:** `ConvertidorAspirante` cubre la entrada normal, pero no el caso
  de la egresada que empieza la maestría o el alumno que suma una segunda
  licenciatura. Obligarlos a darse de alta como aspirantes sería recapturar a
  alguien que la escuela ya conoce.
- **Decisión:** servicio `MatriculadorOferta`, usado desde el expediente. Usa el
  MISMO `GeneradorMatricula` con su consecutivo atómico: no hay dos formas de
  numerar alumnos.
- El rol materializado `alumnos` se respeta si ya existía: es de la persona, no
  de cada matrícula.
- Se ofrecen solo las ofertas donde NO está matriculada. Ofrecer las que ya
  tiene solo produce un error evitable.
- **Permiso `generar-matricula`, no `editar-alumnos`**: numerar a un alumno es
  un acto distinto de corregirle el teléfono. Se le concedió a
  `encargado_control_escolar` además de admisiones, porque los reingresos y las
  segundas carreras los atiende control escolar; la entrada de aspirantes sigue
  siendo de admisiones.

### Dar de baja pide CUÁL baja
- **Hallazgo al probar:** el servicio buscaba una situación de clave `baja` que
  NO existe. El catálogo de la escuela tiene `baja_temporal` y
  `baja_definitiva`, y la baja se quedaba con la situación anterior ("Activo"),
  dejando una matrícula con estatus `baja` y situación `Activo`.
- **Decisión:** la baja recibe la situación destino y la interfaz la pide.
  `estatus` y `situacion_id` son **dos ejes**: el primero dice que ya no está
  activa, el segundo si fue temporal o definitiva — que es justo el dato que
  después responde "¿puede volver?".
- Las opciones se detectan por prefijo de clave (`baja%`) y no con una lista
  fija: cada escuela define su catálogo.

### No se elimina una matrícula
- **Decisión:** solo baja y reactivación.
- **Razón:** su historial académico es historia escolar y las actas donde aparece quedarían
  sin dueño. Verificado en la suite: se asienta una materia, se da de baja, y el
  historial académico sigue ahí.
- La opción de "eliminar la cargada por error" se descartó por ahora; si se
  retoma, tendría que restringirse a matrículas sin historial académico ni pagos.

### Corregir la identidad alcanza a todas las matrículas
- Ya era así, pero ahora se ve: la pestaña "Carreras" lista todas con su
  estatus, situación, generación y cuántas materias llevan en historial académico, y la
  pantalla rotula qué cambia a una y qué a todas.

## 2026-07-21 — Catálogo de documentos con ámbito (bloque C)

### El catálogo existía pero no tenía pantalla
- **Problema:** `documentos_requeridos` vive en la base desde la Fase 1 y se
  sembraba con un seeder. Para agregar un requisito había que tocar código, y la
  tabla no distinguía destinatario: era el catálogo del aspirante, aunque al
  docente se le pide su acta igual.
- **RESOLUCIÓN:** pantalla en `/documentos` y pivote `documento_ambitos`
  (aspirante / alumno / docente / tutor).

### El ámbito es un pivote, no una columna
- **Decisión:** un documento puede tener varios ámbitos.
- **Razón:** "Acta de nacimiento" es UNA cosa aunque la entreguen aspirantes,
  alumnos y docentes. Con una columna habría que darla de alta tres veces, con
  tres nombres que acabarían divergiendo ("Acta", "Acta de nacimiento", "Acta
  nac.") y tres reportes que no cuadran.
- `ambito` va como varchar con constantes en el modelo, no como catálogo
  TENANT-CONFIG: sus valores son los roles que el sistema conoce, no algo que
  una escuela deba inventar.

### Retirar un requisito ≠ borrarlo
- **Decisión:** quitarle TODOS los ámbitos lo saca de las listas sin borrarlo.
  Borrar está prohibido si alguien ya lo entregó, y la pantalla lo explica.
- **Razón:** los archivos y su historial de revisión cuelgan del tipo. Borrarlo
  dejaría expedientes con documentos huérfanos; la FK lo impide de todos modos,
  pero es mejor explicarlo antes que reventar con un error de base de datos.
- Al crear se exige al menos un ámbito —un documento que no se le pide a nadie
  no tiene por qué nacer—; retirarlo después sí es válido.

### Cada expediente ofrece solo lo que le toca
- El expediente del docente ofrecía el catálogo completo, que era el del
  aspirante: le proponía subir su "certificado de estudios previos" y sus
  "fotografías tamaño infantil". Ahora cada uno filtra por su ámbito.

### Quién valida y quién solo sube
- Ya estaba implementado y aquí se confirma la regla: **quien sube no valida**.
  El docente carga su expediente y control escolar lo acepta o lo rechaza con
  observación; el aspirante carga el suyo y admisiones lo revisa con
  `validar-expediente`. Alumnos y padres, cuando tengan portal, entran en la
  misma categoría: subir sí, dictaminar no.
- `gestionar-documentos` (catálogo) es distinto de `validar-expediente`
  (dictaminar una entrega concreta): definir qué se pide y juzgar lo entregado
  son dos oficios.

### El permiso de LECTURA del catálogo es el mismo que el de escritura
- Se probó primero con `ver-aspirantes` y control escolar recibía 403 pese a
  administrar los documentos de los docentes. Quien no administra el catálogo no
  necesita verlo: los expedientes ya muestran los documentos que a cada quien le
  tocan.

## 2026-07-21 — Suplantación de usuarios (bloque D)

### Para qué, y por qué no basta una vista previa
- **Decisión:** suplantación real. Se entra con la cuenta de la otra persona:
  sus permisos, su rol activo, sus datos.
- **Razón:** sirve para soporte real. Cuando alguien reporta "no me deja
  inscribirme", la única forma de reproducir el problema exacto es ejecutar con
  sus permisos. Un listado de permisos no lo reproduce, y una vista de solo
  lectura deja fuera justo los fallos que dependen de ejecutar algo, que son los
  que se reportan.

### Tres salvaguardas que no son opcionales
1. **Bitácora.** Cada entrada y cada salida quedan en `auditoria` con quién, a
   quién, cuándo y desde qué IP. Sin eso, una acción hecha durante una
   suplantación sería indistinguible de una hecha por la persona misma. El
   registro cuelga del usuario SUPLANTADO porque la pregunta que se hace después
   es "¿quién entró como esta persona?", no al revés.
2. **Banda permanente** en la interfaz, a nivel raíz del layout para que salga
   en todas las pantallas. Quien suplanta tiene que saber en todo momento que no
   es él; olvidarlo es como se firman actas por error.
3. **Sin escalada ni cadenas.** No se puede suplantar a alguien que también
   tenga `suplantar-usuarios` —sería la vía para tomar los permisos de un par
   sin que nadie te los diera— ni suplantar mientras ya se está suplantando.
   Tampoco a una cuenta sin rol activo: no habría nada que ver.

### Volver NO pide permisos
- **Decisión:** `DELETE /suplantar` está fuera de cualquier `can:`.
- **Razón:** mientras se suplanta se tienen los permisos del SUPLANTADO, que
  normalmente son menores. Exigir `suplantar-usuarios` para salir dejaría a la
  persona atrapada en una identidad ajena. El id real vive en la sesión y volver
  solo depende de eso.
- Si la cuenta real desapareció a media suplantación se cierra sesión, en vez de
  dejar a alguien dentro con la identidad de otro.

### Solo dirección general
- `suplantar-usuarios` no se le dio a control escolar pese a que administra
  alumnos y docentes: es la capacidad más delicada del sistema y no hace falta
  para su trabajo diario.
- El botón "Ver como" solo aparece si esa persona tiene cuenta con rol activo, y
  el controlador lo resuelve —no el front—: decidir sobre permisos no es asunto
  de la interfaz.

### Verificado de punta a punta
Entrando como dirección y suplantando a un docente: los permisos pasaron a ser
los suyos (6 en vez de 21), `/escolar/alumnos`, `/escolar/docentes` y
`/documentos` devolvieron 403, `/docencia` devolvió 200, la cadena de
suplantación se bloqueó, y volver restauró la cuenta original. La bitácora
quedó con los dos eventos, con IP y hora.

## 2026-07-22 — Constructor de formularios dinámicos (bloque E)

### El motor llevaba desde la Fase 1 sin interfaz
- **Problema:** formularios versionados, once tipos de campo, opciones, campos
  condicionales y asignación polimórfica existían en la base y NUNCA se pudieron
  usar: para pedir un dato nuevo había que insertar filas a mano.
- **RESOLUCIÓN:** `/formularios` (listado por clave, agrupando versiones) y el
  constructor por formulario.

### Versionar en vez de mutar
- **Decisión:** un formulario con respuestas capturadas se CONGELA. No se le
  agregan, quitan ni cambian campos. Para modificarlo se publica una versión
  nueva que copia campos, opciones y asignaciones.
- **Razón:** las respuestas apuntan a un campo concreto. Cambiar la pregunta sin
  tocar la respuesta haría que el expediente dijera algo que nadie contestó.
  `respuestas_campo.formulario_version` ya guardaba la versión contestada: esta
  regla es la que le da sentido.
- Se valida en CADA acción (crear campo, editarlo, borrarlo, moverlo, tocar sus
  opciones) y no una sola vez, porque cada una entra por su propia ruta.
- La `clave` no se edita: identifica al formulario a través de sus versiones.

### El versionado re-ata los condicionales en una segunda pasada
- Al copiar, un campo puede depender de otro que todavía no existía. Se copian
  primero todos y luego se reasignan los `campo_padre_id` a los equivalentes de
  la versión nueva.
- Sin eso, el hijo de la v2 apuntaría al padre de la v1: el condicional seguiría
  "funcionando" pero contra un campo de otra versión. Fijado en la suite.

### BUG ENCONTRADO: el soft delete no libera el índice único
- **Síntoma:** borrar una versión y volver a versionar devolvía **500** con
  `Duplicate entry 'datos_medicos-2'`.
- **Causa:** `formularios` tiene unique (clave, version) y soft delete. Una
  versión borrada sigue ocupando su número, pero `max('version')` no la cuenta
  porque el modelo filtra los borrados. El siguiente intento chocaba contra una
  fila que ya nadie ve.
- **Arreglo:** el cálculo de la siguiente versión usa `withTrashed()`. Lo mismo
  al comprobar si una clave ya existe.
- **Lección general:** cualquier tabla con soft delete + índice único tiene esta
  trampa. Vale para `documentos_requeridos.nombre` y para las claves de ciclos.

### Salvaguardas del constructor
- **Ciclos en los condicionales:** un campo no puede depender de sí mismo ni de
  un descendiente suyo — ninguno de los dos se mostraría jamás.
- **Condicional sin valor:** si se elige campo padre hay que decir CON QUÉ valor
  se dispara; si no, el campo quedaría mudo.
- **Expresión regular inválida:** se prueba al configurar. Sin eso, el error
  aparecería al capturar cada respuesta y en la pantalla equivocada.
- **Borrar un campo** limpia los condicionales que dependían de él, en vez de
  dejar campos condicionados a algo inexistente.
- **Borrar una opción** que dispara un condicional está prohibido: esa condición
  no volvería a cumplirse nunca y el campo quedaría oculto para siempre.
- **Opciones con el mismo valor** se rechazan: dos opciones indistinguibles en
  la respuesta son un dato perdido. El valor se deriva de la etiqueta si no se
  da, para no obligar a inventarlo en cada una.

---

## Módulo 7 — Finanzas (entrega 7.1 cerrada)

La primera entrega quedó completa: catálogos, motor configurable, núcleo
transaccional (`adeudos`, `pagos`, `pago_adeudo`,
`bitacora_situacion_financiera`), los modelos de todo el módulo, el seeder de
los tres catálogos y la re-ligadura en la conversión.

### `metodos_pago` es tabla, no varchar
- La especificación lo describía como una columna de texto en `pagos`.
- Se hizo catálogo por la regla del proyecto: **todo lo enumerable es tabla**.
  El método de pago necesita además dos atributos que un varchar no puede
  llevar: la `clave_sat` (obligatoria para timbrar el CFDI) y
  `requiere_confirmacion`.
- `requiere_confirmacion` es la diferencia entre cobrar y prometer: un pago en
  ventanilla se da por cobrado al registrarlo; uno por pasarela o transferencia
  no lo está hasta que llega la confirmación. Sin esa bandera, el sistema daría
  por pagado un adeudo con dinero que nunca llegó.

### Los conceptos de pago nacen listos para facturar
- `conceptos_pago` lleva `clave_sat`, `clave_unidad_sat`, `gravado` y
  `tasa_iva` desde la primera migración, aunque el CFDI sea la entrega 7.3.
- Agregarlos después obligaría a rellenar a mano las claves fiscales de
  conceptos que ya tienen adeudos y pagos históricos colgando. Cuestan nada hoy
  y son un desastre retroactivo mañana.

### `planes_cobro.aplica_a_id` es polimórfico sin FK
- Mismo patrón que `formulario_asignacion`: un plan puede aplicar a un nivel,
  una carrera o una oferta. Se guarda el par (tipo, id) indexado, sin FK real,
  porque no hay una sola tabla a la cual apuntar.

### DECISIÓN VINCULANTE para lo que falta: el aspirante ya paga  ✅ IMPLEMENTADA
- Un aspirante paga su ficha e inscripción **antes** de existir como alumno. Si
  `adeudos.matricula_oferta_id` fuera obligatorio, ese dinero no tendría dónde
  registrarse.
- Por eso `adeudos` y `pagos` llevan `matricula_oferta_id` **nullable** y
  `aspirante_id` **nullable**, con exactamente uno de los dos presente
  (validado en la aplicación; con MySQL 8 se puede evaluar un CHECK). Índices
  por ambas columnas.
- La conversión aspirante → alumno **re-liga** sus adeudos y pagos a la nueva
  `matricula_oferta` dentro de **la misma transacción** en la que se genera la
  matrícula. La alternativa —dejarlos colgando del aspirante— parte el estado
  de cuenta del alumno en dos y pierde la trazabilidad del pago de inscripción,
  que es justo el que siempre se reclama.
- **IMPLEMENTADO tal cual.** El CHECK sí se creó: `chk_adeudos_titular` y
  `chk_pagos_titular` con `(matricula_oferta_id IS NOT NULL) + (aspirante_id IS
  NOT NULL) = 1`. Se agregan por `ALTER TABLE` solo cuando el driver es MySQL,
  porque SQLite —el motor de phpunit— no admite añadir constraints después de
  crear la tabla. Que la regla esté en la aplicación no es motivo para dejarla
  fuera de la base: la app la impone donde pasa el código, la base donde pasa
  cualquier cosa.
- La re-ligadura vive en `App\Services\ReligadorFinanzas`, no dentro del
  convertidor, porque hay **dos** caminos a una matrícula nueva y los dos
  necesitan lo mismo: `ConvertidorAspirante` (el aspirante que se convierte) y
  `MatriculadorOferta` (quien ya es alumno de la casa y suma otra carrera,
  habiendo podido pagar su ficha como aspirante de ESA oferta).
- **El re-ligado acota por oferta, no por persona.** `religarPorOferta` busca al
  aspirante de esa misma oferta; los pagos de otra candidatura de la misma
  persona no son de esta matrícula. Verificado en la suite: matricular en la
  segunda oferta mueve el adeudo de su segunda candidatura y no toca los cinco
  de la primera matrícula.
- **Se pone `aspirante_id` en NULL al re-ligar**, no se dejan los dos. Es lo que
  exige el CHECK y además es correcto: el titular del adeudo pasó a ser la
  matrícula, y de qué aspirante venía lo sigue contando `aspirantes.persona_id`.

### `estatus` en varchar con constantes, no catálogo TENANT-CONFIG
- `adeudos.estatus` (pendiente/parcial/pagado/cancelado/condonado) y
  `pagos.estatus` (pendiente/completado/fallido/reembolsado) van como varchar
  con constantes en el modelo.
- **Razón:** son la máquina de estados que el motor de cobro sabe interpretar,
  no algo que una escuela deba renombrar. Mismo criterio que `actas.situacion`
  e `inscripcion.tipo`. Lo que sí es catálogo —porque cada escuela lo define—
  es `situaciones_pago`, con su bandera `bloquea`.

### El default de la migración también va en el modelo
- **Bug real detectado por la suite:** `Adeudo::create([...])` sin `estatus`
  guardaba `pendiente` en la base (el default de la columna) pero devolvía el
  modelo con `estatus` en **NULL**. Todo lo que pregunta por el estatus sobre
  ese objeto —`porCobrar`, `estaVencido`— se equivocaba en silencio sobre un
  renglón que en la base estaba perfectamente bien.
- **Decisión:** `protected $attributes` repite en el modelo los defaults de la
  migración (`estatus`, `monto_recargos`, `monto_descuentos`).
- **Lección general:** un default de columna solo existe para la base. Si el
  modelo recién creado no lo dice, el objeto y la fila discrepan hasta el
  primer `fresh()`, y ese hueco es donde se cuelan los bugs que no revientan.

### `pago_adeudo` es pivote con dato propio, y su borrado lógico se filtra a mano
- `monto_aplicado` es lo que permite el pago parcial (un abono) y el split (un
  depósito que liquida tres mensualidades). Sin esa columna un pago solo podría
  cubrir adeudos completos, que no es como se cobra en una escuela.
- PK compuesta (pago_id, adeudo_id): el mismo pago no se aplica dos veces al
  mismo adeudo; se corrige la fila que ya existe.
- **Trampa:** la tabla lleva `auditoria()` como toda tabla TENANT, o sea soft
  delete, pero `belongsToMany` **no filtra `deleted_at` del pivote solo**. Una
  aplicación retirada seguiría descontando del saldo. Las dos relaciones llevan
  `->wherePivotNull('deleted_at')` explícito. Fijado en la suite.

### El saldo solo cuenta dinero que llegó
- `Adeudo::montoAplicado()` suma únicamente los pagos en estatus `completado`.
- **Razón:** es la contraparte de `metodos_pago.requiere_confirmacion`. Un SPEI
  registrado pero sin confirmar está aplicado al adeudo y aun así el saldo sigue
  completo; al confirmarse se va a cero. Contar lo pendiente daría por liquidado
  un adeudo con dinero que nunca llegó, que es exactamente lo que esa bandera
  existe para evitar.

### La situación financiera vigente es el último renglón de la bitácora
- **Decisión:** no hay columna `situacion_pago_id` en `matricula_oferta`. La
  situación se lee con `BitacoraSituacionFinanciera::vigenteDe()` y se cambia
  con `::registrar()`, que agrega.
- **Razón:** la pregunta que se hace meses después es "¿por qué no se pudo
  reinscribir en marzo?", y eso solo lo responde saber qué situación tenía
  ENTONCES. Con una columna, levantar un bloqueo borraría la razón por la que
  existió. Levantarlo agrega un renglón; el motivo del bloqueo se conserva.

### Las claves del SAT se siembran desde el primer día
- El seeder deja `clave_sat` y `clave_unidad_sat` puestas (86121600 / E48 para
  servicios educativos) aunque el CFDI sea la entrega 7.3, y marca gravado solo
  lo que normalmente lo está (constancias, credenciales, titulación, recargos).
- **Razón:** rellenarlas después obliga a un trabajo manual sobre conceptos que
  ya tienen adeudos y pagos históricos colgando. Hoy cuestan nada. Quedan como
  punto de partida: el contador de cada escuela las confirma antes de timbrar.
- Lo que NO se siembra son montos, planes de cobro ni reglas: son de cada
  escuela y no hay un valor por defecto razonable.

---

## 2026-07-22 — Módulo 7, entrega 7.2: el motor de cobro

### La idempotencia no se confía al código
- **Decisión:** índice único `(matricula_oferta_id, regla_id, periodo_etiqueta)`
  además de la comprobación previa en `GeneradorAdeudos`.
- **Razón:** el generador va a correr como job programado. Dos ejecuciones que
  se traslapen —un reintento de la cola, el administrador que aprieta el botón
  mientras el cron corre— pasan las dos por el SELECT antes de que ninguna
  inserte. Un índice único es lo único que de verdad impide cobrarle dos veces
  la colegiatura de marzo a un alumno. El `QueryException` de duplicado se traga
  a propósito: significa que otra corrida ganó la carrera, que es justo lo que
  el índice existe para resolver.
- Los cargos capturados a mano llevan `regla_id` en NULL y MySQL trata los NULL
  como distintos, así que quedan fuera del índice — lo cual es correcto: una
  reposición de credencial cobrada dos veces son dos cargos legítimos.
- La comprobación previa usa `withTrashed()` por la trampa conocida del
  proyecto: **el soft delete no libera un índice único**. Y es además el
  comportamiento deseado — si alguien canceló marzo, la siguiente corrida no
  debe resucitarlo.

### La etiqueta del periodo es una llave, no una decoración
- `periodo_etiqueta` ("Marzo 2026", "Semana 12 de 2026") es la mitad de la
  llave de idempotencia, así que **tiene que ser estable entre corridas**.
- Por eso los nombres de mes van en un arreglo del propio servicio y NO salen
  del locale: un cambio de configuración del servidor convertiría "Marzo 2026"
  en "March 2026" y la siguiente corrida cobraría marzo otra vez.

### BUG ENCONTRADO: las semanas dependían de la configuración
- **Síntoma:** el mismo rango de cuatro semanas producía CINCO periodos, y dos
  de ellos podían llevar la misma etiqueta.
- **Causa:** `startOfWeek()` sin argumento respeta la configuración de la
  aplicación, que aquí empieza en **domingo**, mientras la etiqueta se calcula
  con `isoWeek()`, que siempre cuenta de lunes. Los límites y el nombre del
  periodo hablaban de semanas distintas.
- **Arreglo:** `startOfWeek(MONDAY)` / `endOfWeek(SUNDAY)` explícitos. Una llave
  de idempotencia no puede depender de un ajuste que alguien cambie mañana.

### BUG ENCONTRADO: el prorrateo nunca prorrateaba
- **Síntoma:** quien ingresaba el 16 de marzo pagaba el mes completo.
- **Causa:** el periodo se construía con `inicio = max(inicio del mes, fecha de
  ingreso)`. Así "del 16 al 31" se creía un mes entero y
  `proporcionDesde()` devolvía siempre 1.
- **Arreglo:** el periodo lleva los límites REALES del mes; el recorte al
  ingreso es asunto del generador, no del calendario.

### BUG ENCONTRADO: Carbon 3 mide en días fraccionarios
- **Síntoma:** con lo anterior arreglado, el prorrateo daba 1 646.87 en vez de
  1 600 — unos pesos de más, todos los meses, en cada alta a media periodicidad.
- **Causa:** `endOfMonth()` cae a las 23:59:59 y `diffInDays` de Carbon 3
  devuelve **flotante**, así que marzo medía 31.99 días y la fracción salía
  17/32 en vez de 16/31.
- **Arreglo:** `proporcionDesde()` normaliza las tres fechas a medianoche y
  castea el diff a entero. Lección: en aritmética de calendario, comparar
  instantes cuando se quieren contar días es un error que no revienta — solo
  cobra de más.

### Un solo criterio de especificidad: el más específico gana
- **Decisión:** `ResolutorPlanCobro` elige oferta → plan de estudios → carrera →
  global, entre los vigentes. Mismo criterio que `reglas_matricula`.
- **Razón:** la escuela define un esquema general y lo excepciona donde hace
  falta ("todos pagan así, salvo la maestría en línea"). Sin la precedencia
  habría que dar de alta un plan de cobro por oferta solo para repetir el mismo
  monto. Si empatan dos del mismo nivel —configuración mal hecha— gana el de
  `vigente_desde` más reciente: es el último que alguien quiso poner en marcha.

### El estatus del adeudo se DERIVA; el del pago lo dicta el método
- `RegistradorPago::actualizarEstatus` calcula pendiente/parcial/pagado a partir
  de lo aplicado. Nunca se captura. Así no puede existir un adeudo "pagado" con
  saldo ni uno "pendiente" ya cubierto.
- El estatus del PAGO no lo elige el capturista: lo dicta
  `metodos_pago.requiere_confirmacion`. Un pago en ventanilla nace cobrado; una
  transferencia nace pendiente y solo confirmarla la vuelve dinero. Dejarlo a
  criterio de quien cobra es exactamente cómo se da por pagado un adeudo con
  dinero que nunca llegó.
- `montoAplicado()` suma **solo pagos completados**, así que un SPEI registrado
  y sin confirmar deja el saldo intacto. Verificado en la suite.
- Cancelado y condonado se respetan: son decisiones administrativas y un pago
  posterior no las revierte solo.

### Revertir un pago no borra su aplicación
- **Decisión:** marcar un pago como fallido o reembolsado reabre los adeudos que
  cubría, pero las filas de `pago_adeudo` se conservan.
- **Razón:** que un pago se haya intentado y rebotado es parte de la historia de
  la cuenta. Borrarlo deja al alumno preguntando por un cargo que la semana
  pasada aparecía cubierto.

### El recargo se calcula sobre el monto BASE
- No sobre el total ya recargado. Capitalizar la mora es otra decisión de
  negocio y sería una que nadie tomó explícitamente.
- `dias_gracia` es el colchón antes de que empiece a correr: casi ninguna
  escuela cobra mora al día siguiente del vencimiento.
- Un adeudo pagado, cancelado o condonado **no se recalcula**: moverle el monto
  a algo ya liquidado descuadraría lo que el alumno pagó contra su recibo.
- Las tres columnas (`monto`, `monto_recargos`, `monto_descuentos`) se conservan
  por separado y la pantalla las desglosa. La pregunta de ventanilla es "¿por
  qué me cobran 2 300 si la colegiatura son 2 000?", y un neto no la responde.

### Los descuentos se acumulan pero nunca pasan del monto
- Dos becas del 60% dejan el adeudo en cero, no en negativo. Un adeudo negativo
  sería un saldo a favor, que es otra cosa y no se inventa aquí.

### El prerrequisito impide emitir, no solo cobrar
- Una regla con `concepto_prerequisito_id` **no genera** hasta que ese concepto
  esté pagado (o condonado).
- **Razón:** cobrarle las colegiaturas del semestre a quien nunca completó su
  inscripción infla la cartera con dinero que la escuela cree tener y no tiene,
  y le llega al alumno como un estado de cuenta que no reconoce.

### Una matrícula de baja deja de devengar
- El generador se detiene y **dice por qué**. Seguir emitiéndole colegiaturas
  obliga a cancelarlas después una por una.

### La situación financiera vive en la bitácora, no en una columna
- Ya estaba decidido en 7.1 y aquí se consume: `EstadoCuenta::estaBloqueada`
  lee la situación vigente, **no el saldo**. Hay escuelas que no bloquean nunca
  y otras que bloquean al primer adeudo; esa decisión vive en el catálogo
  (`situaciones_pago.bloquea`), no en el código.

### `gestionar-planes-cobro`: configurar el cobro no es cobrar
- **Decisión:** permiso nuevo, para dirección general y encargado de finanzas.
  El `auxiliar_finanzas` tiene `registrar-pagos` pero NO este.
- **Razón:** el auxiliar de ventanilla cobra todo el día y no debe poder
  cambiarle el monto de la colegiatura a una carrera entera. Verificado por
  HTTP: 200 en `/finanzas`, 403 en `/finanzas/planes`.

### La cartera se agrega en SQL, no alumno por alumno
- El listado calcula saldo y vencido con una subconsulta agregada y un
  `leftJoinSub`. Recorrer las matrículas pidiéndole el saldo a cada modelo son
  miles de consultas en la pantalla que se abre a diario.
- Los totales salen de la misma agregación **sin el paginado**: de la página
  actual dirían "la cartera son 40 mil pesos" cuando son los 40 mil de los 25
  alumnos que se están viendo.

### (7.2) Editar una regla no reescribe los cargos ya emitidos
- **Decisión:** cambiar el monto aplica a los siguientes; los emitidos
  conservan el suyo, y la pantalla lo avisa.
- **Razón:** un adeudo es lo que se le cobró al alumno ese mes, no una vista en
  vivo de la regla. Una regla que ya emitió cargos tampoco se borra —sus
  adeudos quedarían sin explicación de dónde salieron—: se retira el plan con
  fecha de fin, que es como se retira un esquema de cobro en la vida real.

---

## 2026-07-22 — Módulo 7, entrega 7.3: facturación CFDI 4.0

### Se factura contra PAGOS, no contra adeudos
- **Decisión:** los renglones del CFDI cuelgan de `pagos`, y solo de los
  cobrados.
- **Razón:** el comprobante ampara dinero que entró, no dinero que se espera.
  Facturar un adeudo pendiente emitiría un documento fiscal por algo que el
  alumno todavía no pagó — y si nunca paga, la escuela declaró un ingreso que
  no tuvo. Un pago sin confirmar es una promesa y tampoco se factura;
  verificado en la suite.
- `factura_conceptos.pago_id` es lo que permite responder "¿este pago ya se
  facturó?" sin adivinar por importes, y por tanto lo que impide facturar dos
  veces el mismo dinero.

### El IVA se desglosa por concepto y hacia atrás
- Cada renglón toma `gravado` y `tasa_iva` de su `conceptos_pago`. En una misma
  factura conviven la colegiatura exenta y la constancia gravada; calcular el
  impuesto sobre el total las mezclaría.
- **El pago es el total CON impuesto**, así que la base se obtiene dividiendo
  (`monto / 1.16`), no multiplicando. Al revés, la factura sumaría más de lo
  que se cobró. Verificado: 232 cobrados = 200 de base + 32 de IVA.

### Inmutable, pero el ciclo de vida sí se registra
- **La distinción que sostiene todo el módulo:** los DATOS FISCALES de un CFDI
  timbrado no se tocan —no hay ruta de edición, y `esEditable()` responde por
  el UUID, no por el estatus—, pero cancelar sí escribe `cancelada_en`, el
  motivo del SAT y la relación con su sustituta. Sin esas columnas la
  cancelación no tendría dónde constar y la regla "cancelación + refactura"
  quedaría en el aire.
- Una factura timbrada **no se elimina** aunque esté cancelada: es el respaldo
  de lo que se declaró. Solo se borra un borrador o un intento rechazado, que
  nunca fueron documentos fiscales.
- La descripción y la clave del SAT se **copian** del catálogo al emitir. Si la
  escuela renombra "Colegiatura" a "Cuota mensual", el comprobante ya timbrado
  debe seguir diciendo lo que se timbró. Lo mismo con los datos del receptor:
  se congelan por factura y no se leen de una tabla que puede cambiar.

### TENSIÓN RESUELTA: el orden del SAT chocaba con "no facturar dos veces"
- **El problema, encontrado al escribir la suite:** para cancelar con motivo 01
  hay que citar el UUID de la sustituta, o sea que la sustituta debe existir y
  estar timbrada ANTES de cancelar la original. Pero mientras tanto la original
  sigue viva ocupando esos pagos, y la regla de no refacturar los bloqueaba. El
  motivo 01 era inalcanzable.
- **Decisión:** `EmisorFactura::refacturar()` declara la sustitución AL EMITIR
  (`factura_sustituye_id` se escribe en la nueva, no al cancelar). Una factura
  que ya tiene sustituta viva deja de amparar sus pagos, así que la nueva puede
  tomarlos sin que la vieja desaparezca. El flujo queda en dos pasos
  explícitos: emitir la sustituta → cuando tenga UUID, cancelar la original con
  motivo 01 citándola.
- **Se descartó "cancelar primero y volver a facturar"**: deja a la escuela sin
  ningún comprobante vigente en el hueco entre las dos operaciones, y si el
  segundo timbrado falla, sin ninguno en absoluto.
- Cancelar con motivo 01 valida que la sustituta esté timbrada y que se haya
  emitido para sustituir a ESA factura. Citar una ajena se rechaza.
- El motivo 02 (sin relación) sigue siendo el camino simple: cancela y libera
  los pagos, que vuelven a ser facturables.

### El timbrado va en cola, y el rechazo NO es una excepción
- **Decisión:** `TimbrarFactura` es un job. El PAC es un tercero que puede
  tardar diez segundos o estar caído media hora; timbrar dentro del request
  dejaría al usuario ante una pantalla colgada y un timeout no le diría si el
  comprobante se emitió o no.
- **`ResultadoTimbrado` en vez de excepciones para el rechazo.** Que el SAT
  rechace un comprobante —RFC inexistente, régimen que no corresponde al uso,
  certificado vencido— es una respuesta normal del trámite y hay que
  mostrársela al usuario tal cual. Las excepciones se reservan para lo que sí
  conviene reintentar: que el PAC no conteste.
- Por eso un rechazo **no se reintenta** (la respuesta sería la misma): la
  factura queda en `error` con el motivo, alguien corrige el dato y reemite.
  Los reintentos con espera creciente (60s, 5min, 15min) son solo para la falta
  de respuesta.
- `failed()` marca como `error` lo que se quedó en `timbrando`: sin eso, una
  factura cuyo PAC nunca contestó se quedaría en ese estado para siempre y
  nadie sabría que hay que reintentarla.
- **Defensa contra el doble timbrado:** el job sale de inmediato si la factura
  ya tiene UUID. Emitir dos comprobantes por el mismo cobro obliga a cancelar
  uno ante el SAT, que es un trámite y no un `delete`. Verificado corriendo el
  job dos veces sobre la misma factura.
- El `dispatch` va DENTRO de la transacción a propósito: la cola es `database`
  y su tabla vive en la misma base del tenant, así que si la factura no se
  guarda, el job tampoco existe. Con una cola externa habría que usar
  `afterCommit()`. El job es tenant-aware sin hacer nada gracias al
  `QueueTenancyBootstrapper` ya encendido — por eso viaja con el ID y no con el
  modelo.

### El PAC es una interfaz, y NO se escribió una implementación real
- **Decisión:** `App\Services\Cfdi\Pac` con `PacFalso` como único driver, y el
  proveedor real registrado en `config/cfdi.php` cuando la escuela contrate uno.
- **Razón:** escribir un cliente de Facturama sin credenciales para probarlo
  produciría código que parece funcionar y que nadie ha visto responder. Es la
  clase de deuda que se descubre el día del primer timbrado real. `PacFalso`
  valida lo mismo que rechazaría un PAC en su primera revisión (forma del RFC,
  total mayor que cero, al menos un concepto) para que el camino del error se
  ejercite en desarrollo, que es cuando conviene verlo.
- El PAC es configuración de INSTALACIÓN, no de escuela: todas las escuelas de
  esta instancia timbran por el mismo proveedor, con las credenciales de quien
  opera el SaaS. Por eso vive en `config/` y no en `configuraciones` del tenant.
- Los certificados y el RFC del emisor van en el `.env`: un certificado fiscal
  no debería poder cambiarse desde una pantalla de administración.

### Lo que NO lleva `facturas`, y por qué
- **Serie y folio interno.** En CFDI 4.0 son opcionales y el identificador
  fiscal es el UUID. Un consecutivo propio obligaría a otra tabla de contadores
  —el patrón de `contadores_acta`— para algo que hoy nadie pidió.
- **Datos fiscales del receptor en tabla aparte.** Se capturan por factura y se
  copian. Es lo correcto además de lo simple: si el alumno cambia de régimen el
  año que entra, la factura vieja debe seguir diciendo lo que decía. Lo que sí
  se hace es precargar el formulario con los de su última factura, para no
  obligarlo a recapturar su RFC cada mes.

### El XML y el PDF van al disco privado
- Nunca a `public/`: un CFDI trae RFC, razón social y domicilio fiscal del
  receptor, que son datos personales que la LFPDPPP obliga a proteger. Se
  sirven por ruta autenticada bajo el permiso `facturar`.

### `facturar` es un permiso que casi nadie tiene
- Ni control escolar ni el auxiliar de ventanilla lo tienen: emitir un CFDI es
  un acto fiscal a nombre de la escuela, distinto de cobrar. Solo
  `encargado_finanzas` y dirección general. Verificado por HTTP: el auxiliar
  recibe 403 en `/finanzas/facturas`.

---

## 2026-07-22 — Aclaración del cliente: varias razones sociales por escuela

### El hueco, y por qué era grave
- **Aclaración:** una escuela puede facturar con más de una persona moral. Todo
  bachillerato con una razón social, licenciatura con otra, posgrado con otra;
  y a veces una carrera suelta con la suya.
- **Lo que había:** el emisor era UNO, en `config/cfdi.php`. Con eso, la mitad
  de los CFDI de una escuela así habrían salido a nombre equivocado. No es un
  detalle cosmético: un comprobante con el emisor incorrecto es inválido y
  corregirlo no es un UPDATE, es cancelar ante el SAT y refacturar.

### La asignación va en pivote, no en una columna
- **Decisión:** `emisores_fiscales` + `emisor_asignaciones` (emisor,
  `aplica_a_tipo`, `aplica_a_id`).
- **Razón:** una misma razón social factura VARIAS cosas a la vez —todo
  bachillerato Y además la maestría en derecho—. Con una columna en el emisor
  habría que dar de alta la misma persona moral tres veces, con tres RFC
  iguales y tres juegos de certificados que acabarían divergiendo. Es el mismo
  argumento que ya se usó para `documento_ambitos`.
- `aplica_a_id` sin FK, porque apunta a `carreras` (del tenant) o a
  `niveles_estudio` (de la landlord, que por decisión del proyecto nunca lleva
  FK cruzada).

### Precedencia: carrera → nivel de estudios → global
- Tercera vez que aparece este patrón (`reglas_matricula`, `planes_cobro` y
  ahora esto), y por la misma razón: la escuela dice "todo con la A, salvo
  posgrado, que va con la B" sin repetir la A en cada una de sus veinte
  carreras.
- Se eligió el eje NIVEL y no el campus porque es como el cliente describió el
  problema. Si más adelante un plantel resulta ser otra persona moral, se
  agrega un cuarto tipo — y habrá que decidir entonces quién gana entre campus
  y carrera, que hoy no tiene respuesta obvia.
- Una asignación por tipo+destinatario: dos razones sociales para la misma
  carrera es una ambigüedad que después nadie sabe cómo se resolvió. Se rechaza
  al asignar, diciendo cuál la tiene ya.

### Distinguir "no hay ninguna" de "ninguna aplica"
- **Decisión:** si NO hay razones sociales dadas de alta, se cae a
  `config('cfdi.emisor')` —el emisor único de antes— por compatibilidad. Si SÍ
  las hay pero ninguna cubre esa carrera, se **lanza un error** que nombra la
  carrera.
- **Razón:** son dos situaciones distintas. La primera es una instalación que
  todavía no llega aquí; la segunda es una configuración incompleta, y taparla
  facturando con "la primera que aparezca" emitiría el comprobante a nombre
  equivocado. Vale más que la facturación se detenga con un mensaje claro.
- La pantalla además **lista las carreras sin asignar** antes de que alguien
  intente facturar: descubrirlo ahí es mucho más barato que descubrirlo en
  ventanilla con el alumno enfrente.

### Cada persona moral timbra con SU certificado
- **Decisión del cliente:** sí, cada razón social tiene su propio CSD y sus
  credenciales del PAC. Dejan de vivir en el `.env` y pasan a
  `emisores_fiscales`.
- Los archivos (.cer/.key) van al disco **privado**; las contraseñas y el
  usuario del PAC llevan cast `encrypted`, así que un volcado de la base —o un
  respaldo que acabe donde no debe— no entrega la llave con la que se timbra a
  nombre de la escuela. Además van en `$hidden`: no se serializan al front
  nunca. Verificado en la suite leyendo la columna cruda.
- Un campo de contraseña en blanco significa "no lo cambies", no "bórralo": el
  formulario nunca muestra lo guardado, así que enviarlo vacío es lo normal
  cuando solo se sube un archivo.
- Un emisor sin certificado se puede dar de alta —la escuela captura primero y
  sube los archivos después— pero `puedeTimbrar()` es false y la pantalla lo
  rotula.

### El emisor se congela en la factura, igual que el receptor
- `facturas` gana `emisor_rfc`, `emisor_razon_social`, `emisor_regimen_fiscal`
  y `emisor_cp` copiados, más `emisor_id` como referencia de dónde salieron.
- **Razón:** ya era la regla para el receptor y vale idéntico aquí. Verificado:
  corregir la razón social o quitarle la asignación a la carrera NO altera un
  comprobante ya timbrado.
- Una razón social que ya facturó **no se borra**: sus comprobantes son el
  respaldo de lo que se declaró. Se desactiva, que es como se retira una
  persona moral que dejó de operar.

### `gestionar-emisores`, separado de `facturar`
- Definir con qué persona moral factura cada carrera —y cargar sus
  certificados— es una decisión de dirección que se toma una vez; emitir un
  CFDI se hace a diario. Tercer permiso del módulo con el mismo criterio que
  `gestionar-planes-cobro` frente a `registrar-pagos`. Verificado por HTTP:
  el auxiliar de finanzas recibe 403 en `/finanzas/emisores`.

### Consecuencia en la suite anterior
- `prueba-facturacion` empezó a fallar: facturar ahora exige razón social
  asignada. **No se relajó la regla** —es la correcta— sino que la suite da de
  alta la suya como precondición, que es lo que hará cualquier escuela real
  antes de emitir su primer comprobante.

---

## 2026-07-22 — Roles configurables desde pantalla (entrega A) y menú por oficio (B)

Contexto del cliente, que gobierna estas entregas y las que siguen:
**«que esta plataforma sea mejor y no una imagen de los ejemplos o ideas que
puedo tener»**. O sea: no se implementan sus ejemplos, se implementa el
mecanismo del que sus ejemplos son un caso. Aplicado aquí, significa que los
roles que trae el sistema pasan a ser datos borrables y no la estructura.

### Los ROLES son configurables; los PERMISOS no, y es deliberado
- **Decisión:** la escuela crea, edita y borra roles, y decide qué permisos
  lleva cada uno. **No puede crear permisos.**
- **Razón:** un permiso es una llave que el código consulta (`can:asentar-acta`).
  Uno inventado desde la interfaz no lo comprobaría ninguna ruta: daría la
  sensación de haber restringido algo sin restringir nada, que es peor que no
  ofrecerlo. Lo que la escuela necesita configurar es su ORGANIGRAMA, y eso son
  los roles.
- El catálogo se mudó del seeder a `App\Support\CatalogoPermisos`, con dominio,
  etiqueta y descripción por permiso. Lo consultan dos: el seeder al sembrar y
  la pantalla al pintar las casillas. Atrapado en el seeder, el agrupamiento por
  dominio era invisible para la interfaz, y "gestionar-documentos" frente a
  "validar-expediente" son indistinguibles desde una casilla sin su descripción.

### `roles.protegido`: la diferencia entre configurar y quitarle el piso al código
- **Problema:** `CapturaCalificacionesController` acota al docente comprobando
  que su rol activo sea la faceta `docente` o descienda de ella. Si alguien la
  renombra desde la pantalla, ese filtro deja de aplicar **en silencio** y
  cualquier docente podría calificar al grupo de otro.
- **Decisión:** las seis facetas base se marcan `protegido`. Eso fija su clave y
  su existencia; su nombre visible, su tiempo de sesión y **sus permisos siguen
  siendo configurables**.
- Los roles funcionales (encargado de admisiones, auxiliar de finanzas…) NO se
  protegen: son ejemplos útiles y una escuela debe poder borrarlos si su
  organigrama es otro. Es justo el punto del cliente.

### Salvaguarda contra el auto-encierro
- **Decisión:** no se puede quitar `gestionar-roles` del rol con el que se está
  operando.
- **Razón:** el primer clic de quien explora esta pantalla es despalomear cosas.
  Si se quita esa llave a sí mismo, nadie vuelve a entrar y la única salida es
  re-sembrar a mano contra la base. Se explica y se ofrece la alternativa
  (concedérselo antes a otro rol) en vez de bloquear sin decir por qué.
- Lo mismo al retirarse el propio rol activo: se pide conmutar primero.

### Los permisos heredados se muestran marcados y bloqueados
- **Decisión:** en el detalle de un rol funcional, lo que hereda de su faceta
  aparece palomeado, en gris y no editable, con la nota de dónde cambiarlo.
- **Razón:** ocultarlos haría que la pantalla mintiera — el rol puede cosas que
  no están marcadas—. Mostrarlos editables haría creer que se desmarcan desde
  ahí, cuando viven en el padre.

### Ciclos en la jerarquía
- `Rol::admitePadre()` rechaza colgarse de sí mismo o de un descendiente.
  `ancestros()` ya cortaba el ciclo al calcular permisos, pero la jerarquía
  quedaría describiendo algo que no existe.

### (B) El menú se agrupa por OFICIO, no por pantalla
- **Problema:** Alumnos y Docentes vivían dentro de Control escolar porque el
  primer menú agrupó por lo que compartían técnicamente (todo exigía
  `ver-grupos`), no por el trabajo que representan.
- **Decisión:** Alumnos y Docentes suben a secciones propias, cada una con sus
  opciones. Control escolar se queda con lo que de verdad lo es: ciclos y
  grupos. Se agrega la sección Plataforma para roles y lo que venga de
  configuración.
- **Razón:** administrar alumnos, administrar docentes y abrir ciclos son tres
  oficios distintos, y con frecuencia tres personas distintas. Un menú que los
  mezcla obliga a cada una a pasar por las opciones de las otras dos.
- Consecuencia anotada: las URLs NO cambiaron (`/escolar/alumnos` sigue siendo
  esa). Mover rutas habría roto enlaces guardados y no aporta nada al problema
  real, que era de agrupación visual.

---

## 2026-07-22 — CRM de promoción (entrega C)

### HUECO GRANDE ENCONTRADO: el embudo era un catálogo huérfano
- `etapas_crm` estaba sembrada desde la Fase 1 con seis etapas y **nadie la
  usaba**: `aspirantes` nunca tuvo columna de etapa. El embudo existía como
  catálogo y no como dato, así que no se podía saber en qué punto iba un
  prospecto ni cuántos se caen entre una etapa y la siguiente — que es
  literalmente para lo que sirve un CRM.
- Se agrega `aspirantes.etapa_crm_id` con backfill a la primera etapa: dejarlos
  sin etapa los volvería invisibles en el tablero, que es peor que colocarlos en
  un punto discutible.

### `origen` deja de ser texto libre
- Pasa a catálogo `origenes_aspirante` con bandera `autogestivo`.
- **Razón:** de él dependen dos cosas que no funcionan con texto a mano —
  reportar cuántos llegaron por cada vía, y distinguir al que se registró SOLO
  desde la web (entrega D) del que capturó un promotor. Es además la regla del
  proyecto: todo lo enumerable es tabla.

### `aspirante_asesor.titular`: quién responde y quién cobra
- El pivote de asesores ya existía, pero sin decir cuál de ellos responde por el
  prospecto. Sin titular no se sabe a quién pagarle cuando hay dos asesores
  encima del mismo aspirante, y dos comisiones por el mismo alumno serían pagar
  dos veces por un resultado. Asignar un titular nuevo quita el anterior.

### La comisión se devenga al INSCRIBIRSE (decisión del cliente)
- Se paga por resultado. Devengar al capturar premiaría capturar nombres y
  llenaría el CRM de prospectos basura.
- **El monto se CONGELA al devengarse.** Cambiar la regla después no lo
  recalcula: era el trato vigente cuando ese alumno entró. Verificado en la
  suite subiendo la regla de 10% a 50% y comprobando que lo ganado no se movió.
- `DevengadorComisiones` corre DENTRO de la transacción de conversión, como el
  religador de finanzas: una comisión sin matrícula, o una matrícula sin la
  comisión que le tocaba, descuadran la nómina de promoción.
- **Silencioso por diseño:** sin promotor titular o sin regla vigente no devenga
  y NO falla. La conversión de un alumno no debe romperse porque falte
  configurar comisiones — la mayoría de las escuelas no las usa.
- Índice único (matricula_oferta_id, persona_id): si la conversión se reintenta,
  no se paga dos veces.
- El porcentaje se calcula sobre el monto BASE del adeudo, no sobre el total: si
  al alumno se le dio una beca, el promotor no debería cobrar menos por un
  descuento que no decidió él.
- Una regla en modo porcentaje **exige concepto**: sin él, «10%» no dice de qué
  —¿de la inscripción, de la colegiatura, del año?—. Se valida antes de guardar.

### Alcance del promotor: dos capas, igual que el docente
- El PERMISO dice qué puede hacer; la ASIGNACIÓN en `aspirante_asesor` dice
  sobre quién. Un promotor con `ver-mis-prospectos` no ve los prospectos de
  otro. Lo resuelve `EmbudoAdmision::acotar`, no la ruta.

### BUG ENCONTRADO al probar por HTTP: el 403 imposible de explicar
- **Síntoma:** dirección general recibía 403 en `/promocion` teniendo
  `gestionar-promocion`. La ruta exigía `ver-mis-prospectos`, que no tenía.
- **Lo que NO se hizo:** obligar a la escuela a conceder los dos permisos. Es
  exactamente el tipo de dependencia oculta que produce un rebote inexplicable:
  alguien arma «coordinador de admisiones», palomea «Coordinar promoción», y la
  pantalla lo rechaza sin decirle que además necesitaba otra casilla.
- **Decisión:** un permiso DERIVADO, `entrar-promocion`, definido con
  `Gate::define` y abierto por cualquiera de los dos. No entra al catálogo a
  propósito: no es asignable, se deduce. Uno asignable que nadie puede desmarcar
  sería mentira. El menú del front espeja la regla con un campo `o`.
- Vale como patrón para lo que venga: cuando dos permisos abren la misma puerta,
  la puerta se declara aparte y no se le pide al usuario que adivine.

### El tablero mira el ÚLTIMO seguimiento con fecha, no cualquiera
- "Contactar hoy" toma el último seguimiento con `proximo_contacto` de cada
  prospecto. Si se marcó "llamar el lunes" y el lunes se reagendó al viernes, el
  lunes deja de aparecer. Con cualquier seguimiento, un prospecto bien atendido
  se quedaría en la lista para siempre.

### `exige_proximo_contacto` por tipo de seguimiento
- Una llamada registrada sin siguiente paso es un prospecto que nadie va a
  volver a marcar: es el hoyo clásico de un CRM. Cada escuela decide en qué
  tipos lo exige — una nota interna no lo necesita, una llamada sí.

### La etapa se congela en el seguimiento
- `seguimientos_aspirante.etapa_crm_id` guarda la etapa que tenía el prospecto
  ANTES de moverlo, no la actual. Es lo que permite medir cuánto tardó en
  avanzar. Registrar el contacto y mover de etapa van en una transacción: mover
  sin decir por qué deja un embudo que nadie puede auditar, y es el reclamo
  clásico de "¿quién lo pasó a documentación si nunca contestó?".

### Rol `promotor`
- Nuevo rol funcional bajo `administrativo`: captura prospectos y les da
  seguimiento, pero solo los suyos. NO valida expedientes ni convierte a
  alumno — eso sigue siendo de admisiones. Es un ejemplo borrable, como todos
  los funcionales.

---

## 2026-07-22 — Formulario público embebible (entrega D)

### Va en Blade, no en Inertia
- **Decisión:** el formulario público se sirve con vistas Blade autocontenidas,
  con los estilos en línea, fuera de la SPA.
- **Razón:** se carga dentro de un `<iframe>` en la página de la escuela. Montar
  ahí la SPA administrativa —medio megabyte de JavaScript, más las props
  compartidas de sesión, permisos y tema— para pintarle ocho campos a un anónimo
  arrastraría todo el peso del panel al sitio de la escuela. Además, la vista no
  sabe nada de la sesión, que es exactamente lo que debe saber alguien que no ha
  entrado.
- Lleva `noindex`: un buscador que indexe una convocatoria seguiría mandando
  gente a un formulario muerto cuando cierre.

### Tabla aparte, no columnas en `formularios`
- `formularios_publicos` es la PUBLICACIÓN; `formularios` es el cuestionario.
  Son cosas distintas: el formulario es qué se pregunta —versionado y congelado
  en cuanto alguien contesta—, la publicación es cómo y dónde se ofrece. La
  escuela publica el mismo formulario dos veces —una para la feria, otra para la
  página— y cada publicación mide por separado.
- Apunta a una VERSIÓN concreta. Publicar la v2 es otra publicación, y así las
  respuestas de la v1 siguen queriendo decir lo que decían.

### El token es UUID, no un consecutivo
- Cualquiera en internet puede probar `/p/1`, `/p/2`. Un id adivinable convierte
  un formulario retirado en uno que sigue recibiendo. Se genera solo: uno
  elegido a mano acaba siendo "inscripciones2026", que también se adivina.

### Tres reglas porque los datos los escribe un desconocido
1. **Nunca se sobreescribe una persona existente.** Si la CURP ya está en la
   base se liga el prospecto a esa persona sin tocarle un dato. Un formulario
   anónimo capaz de corregir el nombre o el teléfono de alguien es una forma de
   secuestrar un expediente. Verificado mandando la misma CURP con datos falsos.
2. **La deduplicación es por CURP y SOLO por CURP.** El correo no identifica: es
   trivial teclear el de otro, y ligar por correo metería a un tercero dentro de
   un expediente ajeno. Sin CURP se crea persona nueva y que admisiones
   consolide — un duplicado se arregla; un secuestro de expediente, no.
3. **No se repite el prospecto.** Si esa persona ya tiene solicitud viva para la
   misma oferta, no se crea otra: el reintento se registra como seguimiento, que
   para promoción es señal de interés y no ruido. Sin esto, quien llena el
   formulario cinco veces produce cinco prospectos y cinco llamadas.

### Y dos más sobre las credenciales
- En modo inscripción se crea la cuenta, pero **si la persona ya tenía una no se
  toca**: un formulario anónimo que pudiera reescribir una contraseña sería la
  vía más simple para tomar la cuenta de alguien. Verificado.
- Los ids de campo que llegan en el POST se filtran contra los del formulario
  publicado: uno ajeno colado en el envío ensuciaría las respuestas de otro.

### Anti-abuso sin captcha
- Honeypot (un campo oculto que una persona nunca llena) más `throttle:6,1` por
  IP en el envío. Es un endpoint anónimo que ESCRIBE en la base, o sea el
  candidato perfecto para inundar el CRM.
- Se descartó un captcha: exige contratar un servicio y le cobra fricción al
  visitante legítimo. Si el spam se vuelve un problema real, se agrega entonces.

### La vigencia se revalida al ENVIAR, no solo al pintar
- La campaña pudo cerrarse entre que alguien abrió la pestaña y mandó el
  formulario. Es la misma regla que ya gobierna las ventanas de captura.
- Una convocatoria cerrada NO devuelve 404: el visitante llegó por un enlace
  legítimo y merece saber que cerró, no toparse con un error que parece de la
  escuela.

### `personas.sexo_id` obligó a preguntar el sexo
- Es NOT NULL por decisión de la spec, así que el formulario público lo pregunta
  en vez de inventar un valor por omisión: un dato de identidad no se rellena a
  espaldas de quien lo da. Apareció al correr la suite, no al diseñar.

### Al prospecto autogestivo se le asigna dueño al nacer
- La publicación puede fijar el promotor titular. Un prospecto que llega solo y
  cae en tierra de nadie es al que nadie llama — y además es quien devengará la
  comisión si se inscribe.

### Una publicación que ya recibió gente no se borra
- Se desactiva. Si desaparece se pierde de dónde llegaron esos prospectos, que
  es toda la medición de la campaña.

### Nota sobre las pruebas por HTTP
- Los scripts de humo que hacen peticiones reales NO pueden envolver sus datos
  en una transacción: la petición abre su propia conexión y no ve lo que no está
  confirmado. Se descubrió aquí (404 en tokens que sí existían). El de este
  módulo crea, prueba y borra con precisión lo que creó.

---

## 2026-07-22 — Panel por rol (entrega E)

### El panel es un REGISTRO de tarjetas, no ramas por rol
- **Decisión:** `App\Panel\TarjetaPanel` (interfaz) + `RegistroTarjetas`. Cada
  tarjeta declara su clave, su título, **el permiso que exige**, cómo se pinta y
  cuánto ancho ocupa. El controlador no conoce ninguna tarjeta concreta: le pide
  al registro las que este usuario puede ver.
- **Razón, y es literal el pedido del cliente:** un panel resuelto con
  `if (rol == finanzas)` obliga a tocar código cada vez que la escuela invente
  un puesto. Con el registro, un rol nuevo armado desde `/plataforma/roles`
  obtiene su panel solo, según lo que le hayan palomeado. Verificado en la suite
  creando un «coordinador inventado» y comprobando que recibe justo las tarjetas
  de sus permisos.
- Agregar una tarjeta = agregar una clase y registrarla en
  `AppServiceProvider::registrarTarjetasDelPanel`. El Vue no se toca: sabe
  pintar cuatro formas (`metrica`, `lista`, `barras`, `accesos`) y una tarjeta
  nueva que use una de ellas ya está pintada.

### Una tarjeta se descarta por DOS motivos distintos
1. **No tiene el permiso** — no le toca verla.
2. **Lo tiene, pero la tarjeta devolvió null** — le toca, pero no aplica a él.
- El segundo caso es el importante y no es teórico: control escolar tiene
  `ver-historial-academico` y no es alumno de nada, así que «Mi avance» le saldría vacío.
  Un alumno tiene `ver-adeudos` para lo suyo y no debe ver la cartera de la
  escuela, así que esa tarjeta pide además un permiso de operación
  (`registrar-pagos` o `gestionar-planes-cobro`). Ambos casos están en la suite.

### Cuándo una tarjeta vacía se muestra y cuándo no
- **Cola de trabajo vacía: NO se muestra.** «Contactar hoy» sin pendientes
  desaparece. Una tarjeta que dice "nada" todos los días enseña a ignorarla, y
  el día que sí tenga algo tampoco se mirará.
- **Métrica propia en cero: SÍ se muestra.** «Mi estado de cuenta» en $0 informa
  — "no debes nada" es justo lo que el alumno quiere ver confirmado.
- La distinción salió de un fallo de la suite donde mi expectativa estaba mal,
  no el código: había escrito que el saldo cero debía ocultarse.

### Las barras se miden contra el MAYOR, no contra el total
- Un embudo que arranca con 200 y termina con 3 deja las últimas etapas
  invisibles si se mide contra el total — y son justo las que interesan, porque
  ahí es donde se cae la gente.

### La tarjeta no reimplementa el alcance: se lo pide al servicio
- «Embudo» y «Contactar hoy» llaman a `EmbudoAdmision`, el mismo que usa la
  pantalla de promoción. Si la tarjeta filtrara por su cuenta, el panel y la
  pantalla podrían acabar diciendo números distintos sobre lo mismo.

### «Actividad por hora» se rotula por lo que el dato dice
- Sale de `sessions.last_activity`, así que una sesión abierta a las 8 y usada a
  las 11 cuenta en las 11. Por eso se llama **actividad** y no "accesos":
  llamarle accesos sería afirmar algo que el dato no sostiene.
- Se pintan las 24 horas aunque estén en cero: mostrar solo las horas con
  actividad esconde la forma de la jornada, que es lo que se quiere ver.

### Deuda que este panel deja a la vista
- El **alumno no recibe accesos directos**: todas las entradas del catálogo
  apuntan a pantallas administrativas o de docencia, porque el portal del alumno
  sigue sin existir. La tarjeta se oculta sola (devuelve null), así que no se ve
  rota — pero es el recordatorio de que esa pieza falta.

---

## 2026-07-22 — Reglas de operación configurables (entrega F)

Aclaración del cliente: quiere poder decir cuántos extraordinarios se permiten
de la misma materia, cuántos recursamientos, si se reutiliza la matrícula, y
que **sobre eso funcione todo el sistema con alertas y bloqueos**. Y añade el
matiz que gobierna el diseño: *«todo debería ser probablemente antes para la
mejor función»* — o sea, configurar antes de que existan registros.

### El catálogo es código; los valores, configuración
- `App\Configuracion\CatalogoAjustes` declara cada ajuste con su tipo, rango,
  valor por omisión y **la consecuencia de cambiarlo**. `Ajustes` lee y escribe.
- Mismo criterio que `CatalogoPermisos` y por la misma razón: un ajuste existe
  porque hay una línea que lo consulta. Uno inventado desde la pantalla no lo
  leería nadie. Por eso `obtener()` de una clave fuera del catálogo **lanza
  excepción** en vez de devolver null: null escondería una clave mal escrita.

### Cada límite viene con su ACCIÓN: advertir o bloquear
- No es la misma decisión en todas las escuelas: hay quien no permite el tercer
  recursamiento y quien sí, con visto bueno de dirección. Forzar todo a bloqueo
  obligaría a APAGAR la regla para poder excepcionarla, y entonces nadie se
  enteraría del caso.
- `ValidadorInscripcion` gana `advertencias()` junto a `impedimentos()`. La
  regla se evalúa UNA vez y se reparte según su acción, en vez de duplicar el
  conteo en dos métodos que podrían divergir.

### Dónde se comprueba cada regla, y por qué ahí
- **Recursamientos**: al inscribir. El límite cuenta los intentos ADICIONALES
  —la primera vez es cursar, no recursar—, que es como lo redacta un reglamento.
- **Extraordinarios**: al FIRMAR el acta, no al capturar. El intento queda
  asentado al cerrar, y hasta ese momento el alumno todavía podía no
  presentarse. Vale por MATERIA DEL PLAN, no por grupo: presentar en otro grupo
  la misma materia sigue siendo el mismo intento.
- **Carga del ciclo**: al inscribir.
- **Adeudo**: al inscribir. Aquí se cierra un hueco real — `situaciones_pago`
  tenía la bandera `bloquea` desde la entrega 7.1 y **nadie la consultaba**: solo
  informaba. Ahora el ajuste dice si esa bandera detiene el trámite, pero QUIÉN
  queda bloqueado lo sigue decidiendo el catálogo, no el interruptor.

### Cero significa «sin límite», y se pregunta en un solo lugar
- `Ajustes::hayLimite()` existe para no repetir —y equivocarse en— la
  comparación en cada punto donde se aplica una regla.

### La pantalla dice cuánta operación hay ya hecha
- No bloquea: la escuela puede cambiar de criterio a media operación y tiene
  derecho. Pero configurar en blanco y configurar encima de un ciclo en curso no
  es lo mismo, y quien lo hace merece saber en cuál está.
- El mensaje es explícito en lo que más se malinterpreta: **cambiar un límite no
  reevalúa el pasado**. Quien ya lleva tres recursamientos no se da de baja
  porque hoy el máximo pase a dos.

### Sin caché persistente, y no por descuido
- El `CacheTenancyBootstrapper` de stancl envuelve toda llamada al caché en TAGS
  para aislar por escuela, y el store de esta instalación es `database`, que no
  los soporta: revienta con «This cache store does not support tagging». Se
  descubrió al correr la suite.
- Se resuelve con memoización por petición y `Ajustes` como singleton. Una
  consulta a una tabla de catorce filas por petición no justifica pelearse con
  eso; si el store pasa a Redis, se puede reevaluar.

### Se retira el test Cleaver
- La spec lo previó y el proyecto migró sus tres piezas, pero **el banco de
  reactivos nunca se sembró** —era del legacy y no debía inventarse—, así que el
  test jamás pudo aplicarse. El cliente confirma que aquí no se usa.
- Se ELIMINA en vez de dejarlo apagado: una tabla vacía que nadie va a llenar es
  una promesa falsa, alguien la lee en el esquema y supone que el sistema evalúa
  psicométricamente a sus aspirantes. Y `aspirantes.cleaver_completo` era peor —
  una bandera del progreso del embudo que nunca podía ponerse en true, o sea un
  paso que ningún aspirante podía completar.
- `down()` reconstruye las tres vacías, que es exactamente el estado que tenían:
  la vuelta atrás no puede inventar un banco que nunca existió.
- Lección de higiene: borrar clases obliga a `composer dump-autoload`. Mientras
  el classmap quede viejo, `class_exists()` intenta incluir un fichero borrado y
  revienta. Por eso la suite comprueba el ARCHIVO y no la clase.

---

## 2026-07-22 — Portal del interesado (entrega G)

Dos aclaraciones del cliente que gobiernan el diseño y corrigieron mi plan:

1. *«Los pasos son los mismos para toda la escuela siempre, no varían.»*
2. *«El avance del seguimiento del equipo de promoción en el CRM es totalmente
   aparte… tal vez solo informativamente… y si no, el administrador pueda
   llenarlo.»*

### Los pasos son código, no configuración
- `ProgresoSolicitud` declara tres pasos fijos: datos, documentos, pago.
- **Iba a hacerlos configurables por campaña y el cliente lo corrigió.** Se
  agradece: una tabla de pasos que siempre tiene las mismas tres filas es
  configuración falsa —da a elegir algo que nadie va a cambiar y obliga a
  mantener una pantalla que no aporta—. Lo que sí varía entre escuelas (si el
  expediente y el pago son REQUISITO para convertir) ya vive en
  `CatalogoAjustes`.

### El avance del expediente NO es la etapa del CRM
- **Es la corrección más importante y estuve a punto de equivocarme:** mi plan
  era mover `etapa_crm_id` automáticamente al completar pasos.
- El embudo lo mueve promoción con su criterio. Que alguien haya subido sus
  papeles no significa que esté "documentado": puede faltar validarlos, o el
  promotor puede saber algo que el sistema no. Si el embudo avanzara solo,
  dejaría de reflejar el trabajo del equipo y se volvería un contador de
  formularios.
- El progreso se muestra en la ficha del aspirante como dato **informativo**,
  junto a la etapa, sin tocarla. Verificado en la suite: expediente al 100% y la
  etapa intacta donde la dejó promoción.

### Da igual quién llene qué
- El mismo cálculo sirve si lo capturó el interesado desde `/mi-solicitud` o un
  administrador desde la ficha. Es lo que pidió el cliente («si no, el
  administrador pueda llenarlo») y sale gratis porque el progreso se DERIVA de
  los datos, no se marca a mano.

### Los pasos que no aplican no arrastran el porcentaje
- Sin documentos configurados o sin cargos generados, ese paso queda fuera del
  cálculo. Si contara, una escuela que no cobra ficha dejaría a todos sus
  aspirantes atascados en 66% para siempre.

### Un documento RECHAZADO vuelve a contar como faltante
- Aunque el archivo esté ahí. Quien lo revisó dijo que no sirve, y dar por
  completo ese paso escondería justo lo que hay que corregir.

### INCONSISTENCIA CORREGIDA: al aspirante se le rechazaba sin motivo
- Al docente ya no se le puede —decisión tomada en el hito de su expediente,
  porque un rechazo sin motivo obliga a adivinar qué corregir— pero a
  `expediente_documentos` le faltaba la columna: **al aspirante sí se podía**.
- Nadie lo notó mientras solo un administrador miraba esa pantalla. Se vuelve
  grave ahora que el interesado ve su propio expediente y lee «Rechazado» sin
  más. Se agrega `observaciones` y se exige al rechazar.

### El portal no recibe id por la URL
- `PortalAspiranteController` resuelve SIEMPRE la solicitud de la persona
  autenticada. No hay `/{aspirante}` que cambiar por otro número, así que no
  existe la clase de fallo donde alguien pide el expediente ajeno. La descarga
  de un documento sí valida además que sea suyo.
- Permiso propio `llenar-mi-solicitud`, único de la faceta `aspirante`.
  Verificado por HTTP: el aspirante entra a `/mi-solicitud` y recibe 403 en
  `/aspirantes`; un administrativo recibe 403 en `/mi-solicitud`.

### Lo que el portal NO hace, y hay que decirlo
- **No cobra.** Muestra los cargos y su saldo, pero no hay pasarela: pagar sigue
  siendo presencial o por los medios que la escuela indique. Conectar una
  pasarela es trabajo aparte, y `pagos` ya tiene las columnas (`pasarela`,
  `pasarela_txn_id`) esperándola desde la entrega 7.1.

---

## 2026-07-22 — Peso visual del panel

Observación del cliente: los accesos se ven «muy cuadrados» y la gráfica «ocupa
todo el ancho y mucho a lo alto, robando visibilidad».

### Una serie de 24 puntos va en COLUMNAS, no en barras
- `barras` (horizontales) y `columnas` (verticales) son la misma información con
  forma distinta, y la distinción no es estética: veinticuatro barras
  horizontales son veinticuatro RENGLONES apilados. Medían ~750 px de alto y a
  ancho completo, o sea que se comían la pantalla y empujaban el resto del panel
  fuera de la vista.
- En columnas: 246 px de alto y media anchura. La forma de la jornada se lee de
  un vistazo y queda sitio al lado para otra tarjeta.
- Regla que queda: una serie LARGA (horas, días) va en columnas; una corta con
  etiquetas largas —las etapas del embudo— sigue en barras, porque ahí el texto
  necesita el ancho.
- Las columnas en cero se pintan como marca de 2 px y no como nada: «casi nadie»
  y «nadie» no son lo mismo, y una hora vacía que desaparece rompe la lectura
  del eje. Por eso también hay un alto mínimo del 6% para lo que sí tiene valor.
- Con 24 columnas no caben 24 etiquetas: se rotula cada tercera. Ponerlas todas
  las vuelve ilegibles a todas.

### Los accesos son un mosaico con icono
- Eran rectángulos con solo texto: había que leer los once para encontrar uno, y
  estos botones existen justamente para NO tener que leer.
- Ahora cada acceso declara su propio trazo SVG y se pinta como tarjeta de
  147×89 con el icono en un círculo del color de acento.

### El icono lo declara la TARJETA, no la pantalla
- `TarjetaPanel` gana `icono()`. Es coherente con el resto del registro: quien
  agregue una tarjeta nueva no debería editar el Vue para que se vea como las
  demás.

### `items-start` en el grid
- Sin él, la fila estira TODAS las tarjetas al alto de la más grande: una
  métrica de un solo número quedaba de 246 px con el 60% en blanco, que es
  exactamente el espacio robado del que se quejaba el cliente. Con `items-start`
  bajó a 148 px.
- Se prefiere denso y algo irregular a alineado y vacío.

### Y por fin: se pudo VER en el navegador
- La deuda decía que el navegador embebido no alcanzaba `demo.localhost`. Es
  cierto que **no resuelve por DNS** (`gethostbyname` devuelve el nombre), pero
  los navegadores basados en Chromium mapean `*.localhost` a loopback por su
  cuenta, sin tocar el archivo `hosts`. Se entró con la cuenta demo y se
  verificó el panel renderizado.
- Con una limitación honesta: **las capturas de pantalla se agotan por tiempo**
  en este entorno, así que lo verificado es la GEOMETRÍA medida desde el DOM
  (altos, anchos, radios, presencia de iconos, alturas de cada columna), no una
  mirada humana al render. Es mucho más de lo que había —hasta ahora nada se
  había cargado en un navegador— pero no sustituye a que alguien lo mire.

---

## 2026-07-22 — Un permiso pertenece a una FACETA, y pantalla de usuarios

### El hallazgo del cliente
> «Si edito los permisos de un rol me deja agregarle permisos de roles como
> alumno o docente, lo cual no debería ser… no podría tener un administrador
> general que vea opciones de docente; si es docente tendría que cambiar de rol.»

Tenía razón y era un agujero de diseño, no un detalle de pantalla.

### Por qué importaba
- Si un administrativo puede concederse `ver-mis-materias`, **el conmutador de
  rol deja de tener sentido**: nadie conmuta, porque todo se ve desde el rol
  administrativo. Y toda la separación de oficios que el proyecto construyó —el
  docente en `/docencia`, el aspirante en `/mi-solicitud`— se vuelve decorativa.
- Peor: el alcance de esos permisos NO sale del permiso sino de una asignación
  (`docente_asignatura_grupo`, `aspirante_asesor`, la matrícula propia). Un
  administrativo con `ver-mis-materias` no vería «sus» materias —no tiene—:
  vería una pantalla vacía o, si algún filtro fallara, las de todos. El permiso
  colgaría de una tabla en la que esa persona no está.

### La regla
- `CatalogoPermisos` declara, por permiso, **a qué facetas pertenece**. Un rol
  solo puede recibir permisos de la faceta de la que cuelga.
- Los que aparecen en VARIAS facetas es porque el oficio de verdad se comparte:
  `capturar-calificaciones` y `asentar-acta` son de administrativo Y de docente
  —control escolar captura en nombre del docente ausente, y eso ya era una
  decisión tomada—; `ver-historial-academico` lo consultan cinco perfiles sobre alcances
  distintos.
- El ámbito de un rol es el de su FACETA, no el suyo: un «auxiliar de
  admisiones» hereda el ámbito de `administrativo`.

### Se filtra en el servidor, no en el front
- La pantalla solo ofrece los de su faceta, pero `RolController` vuelve a
  filtrar al guardar y AVISA cuáles ignoró. Un POST se arma a mano; la casilla
  que no existe no es una defensa.

### Una faceta creada por la escuela se trata como administrativa
- No tiene catálogo propio —nadie declaró qué significa—, y las facetas con
  portal propio (docente, alumno, aspirante) son justamente las protegidas. Una
  faceta nueva es, en la práctica, una variante de personal.

### Pantalla de usuarios: `gestionar-usuarios` existía sin dónde ejercerse
- El permiso estaba en el catálogo desde el slice de auth y **no tenía ninguna
  ruta**: crear una cuenta obligaba a tocar la base o a correr el comando de
  demo. Es lo primero que hace falta al poner el sistema en manos de una escuela.
- La cuenta cuelga de una PERSONA y no la reemplaza. Al dar de alta se busca por
  CURP y se reutiliza: quien entra como docente pudo haber sido alumno, y
  duplicarlo rompería su historial académico, sus roles y su expediente. Misma regla de cero
  recaptura que el resto del sistema.
- Los roles se ofrecen **agrupados por faceta**, que es lo que hace evidente que
  dar «Docente» y dar «Encargado de admisiones» son decisiones de distinta
  naturaleza y no dos opciones de la misma lista.
- Tres salvaguardas: no se puede retirar el rol ACTIVO de una cuenta (quedaría
  sin contexto a medio camino), ni su ÚNICO rol (no podría entrar), ni eliminar
  la cuenta — quedarían sin autor las actas que firmó y lo que capturó. Se
  retiran roles o se restablece la contraseña.
- La contraseña actual no se muestra porque no se puede: está hasheada, que es
  como debe estar.

---

## 2026-07-22 — Dirección general lo puede todo DENTRO de su oficio

Petición del cliente: *«reinicia los permisos de director general… en teoría
director general debe tener todos los permisos para poder ver o hacer cualquier
cosa.»*

### La tensión, y cómo se resuelve
- Un turno antes el mismo cliente pidió lo contrario para un caso concreto: *«no
  podría tener un administrador general que vea opciones de docente; si es
  docente tendría que cambiar de rol.»*
- **«Todos» se lee como todos los de SU FACETA**, no los 43 del catálogo. Son 40
  de 43; los tres que quedan fuera son `ver-mis-materias`,
  `editar-mi-expediente` y `llenar-mi-solicitud`.
- No es una interpretación cómoda: darle esos tres **no funcionaría**. Su
  alcance no sale del permiso sino de una asignación —estar en `docentes`,
  tener un aspirante propio—. Dirección general con `ver-mis-materias` no vería
  «sus» materias porque no tiene ninguna: vería una pantalla vacía. Para actuar
  como docente se le da también ese rol y conmuta, que es justo lo que el
  cliente describió.

### La lista se DERIVA, no se escribe
- `PermisoSeeder` marcaba a mano los permisos de dirección general. Una lista a
  mano se queda vieja cada vez que se agrega un permiso — y ya había pasado:
  `ver-mis-prospectos` nunca se le dio, y produjo el 403 inexplicable que se
  parcheó con el permiso derivado `entrar-promocion`.
- Ahora `director_general => TODOS_LOS_DE_SU_FACETA` y el seeder lo expande
  desde `CatalogoPermisos::clavesDe()`. El seeder además FILTRA cualquier
  permiso de otra faceta que alguien escriba en las listas: corre en cada
  escuela y no debe poder sembrar una inconsistencia.

### HALLAZGO: la regla nueva no deshacía lo viejo
- El acotamiento por faceta se aplicó al catálogo, a la pantalla y al guardado,
  pero **lo ya concedido siguió concedido**. En la escuela de prueba, dirección
  general tenía `ver-mis-materias`, `editar-mi-expediente` y
  `llenar-mi-solicitud` otorgados desde la pantalla antes del cambio — o sea,
  exactamente el caso que el cliente reportó seguía vivo en los datos.
- Se agrega una migración de limpieza que retira de TODOS los roles lo que no
  corresponde a su faceta. Usa `revokePermissionTo` y no `syncPermissions`:
  quita lo que sobra sin reescribir lo demás, para no perder nada que la escuela
  hubiera configurado a mano y sí corresponda.
- **Lección:** una regla de autorización nueva tiene tres frentes —el catálogo,
  la escritura y los datos que ya existen—. Atender solo los dos primeros deja
  el agujero abierto justo para quien ya lo había usado.

### HALLAZGO: `coordinador_academia` estaba mal colgado
- Colgaba de la faceta DOCENTE y todos sus permisos eran administrativos
  (catálogo académico, abrir grupos, ver docentes): ninguno es de impartir
  clase. Estaba así desde el `RolSeeder` original y solo se notó al aplicar la
  regla de facetas, que lo delató como inconsistente.
- Pasa a colgar de ADMINISTRATIVO. Coordinar la oferta es trabajo de gestión;
  quien coordina y además da clase tiene los dos roles y conmuta — que es
  precisamente lo que el modelo de facetas quiere que pase.

---

## Listados: filtros, cuadrícula y lo que la paginación destapó

Pedido del cliente: *«Agrega los filtros, vista tabla o cuadrícula en todos los
listados que hagan referencia a persona o varios datos»*, más dos defectos
concretos: las pestañas de control escolar seguían ofreciendo Alumnos y
Docentes después de que subieran a secciones propias, y abrir la ficha de un
alumno reventaba con `Call to undefined method AlumnoController::datosSuplantacion()`.

### La regla del botón «Ver como» vivía en un solo controlador
`AlumnoController` llamaba a un método **privado de `DocenteController`**. No
era un descuido de tipeo: la regla es una sola —¿quién puede suplantar, y a
quién?— y estaba escrita en el sitio equivocado. Se movió a
`Suplantador::datosPara()`, que es donde ya vive el resto de esa decisión. Los
dos controladores la piden al servicio.

### Las pestañas se declaran, y se filtran por permiso
`NavEscolar` traía una lista fija. Ahora cada pantalla declara las suyas y el
componente las filtra por permiso, se oculta si queda una sola y marca como
activa **la coincidencia más larga** (dos rutas que comparten prefijo se
marcaban las dos). El filtro por permiso no es cosmético: la lista fija ofrecía
«Inscripciones» a cualquiera que llegara a control escolar, y quien no tuviera
`inscribir-alumnos` se comía un 403 al hacer clic en una pestaña que el propio
sistema le había pintado.

### Tres piezas reutilizables, no cuatro pantallas parecidas
`PanelFiltros` (filtros a demanda, con fichas de lo aplicado siempre visibles
aunque el panel esté cerrado — un filtro activo escondido es la causa clásica
del «no aparece el alumno que busco»), `SelectorVista` (recuerda la preferencia
en `localStorage` por listado) y `TarjetaPersona`. Se agrega `TarjetaRegistro`
para lo que **no** es persona: un grupo no tiene cara ni iniciales que
reconocer, y lo que se lee de un vistazo son pares dato/valor.

Se aplicaron a **Aspirantes** (5 filtros), **Grupos** (5), **Promoción por
etapa** (3) y **Usuarios** (2). Usuarios se queda sin cuadrícula a propósito:
cada fila se despliega en un panel de administración —asignar roles,
restablecer contraseña— y una tarjeta que solo enlaza a una ficha inexistente
sería un paso de más.

### Grupos devolvía TODOS los grupos, sin paginar
Con dos ciclos sembrados aún se leía; una escuela con años de historia abre esa
pantalla y recibe miles de filas donde busca una. Pasa a `paginate(20)`.

### HALLAZGO: un `or` sin paréntesis anula el filtro anterior
La búsqueda de usuarios era `whereHas(...)->orWhere(...)->orWhere(...)` sin
agrupar. Funcionaba porque era la única condición. Al sumarle los filtros de rol
y campus, el `or` se habría llevado por delante el filtro y la pantalla habría
devuelto cuentas que no cumplían ninguno. Se agrupó en un `where(fn ...)`, y la
suite fija el caso cruzado búsqueda + filtro.

### HALLAZGO: la foto del aspirante nunca se mostraba
El listado comprobaba `persona->foto`, columna que no existe: se llama
`foto_url`. Como PHP devuelve null para un atributo inexistente, la condición
era siempre falsa y jamás se generaba la ruta — sin error visible. Se usa
`Persona::urlFoto()`, que es el único lugar donde esa decisión está escrita.

### HALLAZGO (el más caro): el aspirante dado de alta a mano no existía para el CRM
`RegistradorProspecto` —el camino del formulario público— asigna la primera
etapa del embudo. `AspiranteController::store` **no**. Todo prospecto capturado
por personal quedaba con `etapa_crm_id` en null: no salía en ninguna columna del
embudo, ni en el conteo por etapa, ni ahora en el filtro por etapa. Para
promoción, sencillamente no existía. Se vio porque `prueba-crm` empezó a fallar
con dos aspirantes reales que el cliente había capturado desde la interfaz.

Tres frentes, otra vez: el controlador ya lo asigna al crear; una migración de
backfill mete a los que ya estaban fuera; y la suite fija que un alta manual
nazca dentro del embudo. De paso, el alta manual ofrece ahora el catálogo
`origenes_aspirante` en vez de solo texto libre, para que un prospecto capturado
por promoción se pueda contar junto a los que entran por la web.

### La suite invoca a los controladores
`prueba-listados` es la primera que llama al controlador y lee las props que
manda a Inertia, en vez de reimplementar la consulta. Es a propósito: un `or`
sin paréntesis o un `whereHas` sobre la tabla equivocada no aparecen si la
prueba escribe su propia versión de la consulta.

Detalle que costó un rato: al reenlazar `request` en el contenedor, el
`AuthServiceProvider` vuelve a poner **su** resolutor de usuario y se lleva por
delante el que puso la prueba. Primero se enlaza, después se dice quién eres.

---

## Identidad de la persona: la CURP como fuente, no como cadena opaca

Pedido del cliente (varias cosas, una sola raíz): el alta de aspirante pedía
«sexo» y «género» —lo mismo dos veces—; la CURP debería autollenar fecha y
género en todos los formularios; debía poder escribirse EXTRANJERO; la entidad
de nacimiento debía ofrecer «extranjero» arriba y luego país; el que captura no
debería aceptar los términos por el aspirante; el correo debía ser obligatorio
(es el usuario de acceso) y debía haber un método para no duplicar personas.

Todo eso es una sola decisión de fondo: **la identidad de una persona se
resuelve en un lugar** (`App\Services\IdentidadPersona` + `App\Support\Curp` +
`App\Rules\CurpValida` + el componente `CamposIdentidad.vue`), no seis veces con
seis criterios. Estaba repartida en aspirante, alumno, docente, expediente
docente, usuario y formulario público.

### El sexo se DERIVA; se dejó de preguntar
`personas.sexo_id` pasó a nullable. Sale de la CURP (su posición 11) o del
género cuando es inequívoco —Masculino→H, Femenino→M—; «No binario» y «Prefiere
no decir» dan null a propósito. Se conserva la columna, no se borra: **sexo** es
el dato legal binario que pide la SEP y que el módulo de titulación necesitará;
**género** es autoidentificado, tiene cinco opciones y no sirve para un trámite.
Preguntar los dos era pedir lo mismo dos veces; inventar un sexo para satisfacer
un NOT NULL era peor. Es más honesto un hueco.

### La CURP se lee
`Curp::leer()` valida el dígito verificador —`size:18` aceptaba cualquier ristra
de dieciocho— y extrae fecha, sexo y entidad. Dos sutilezas que cuestan caro si
se ignoran: la **regla del siglo** (la homoclave es dígito para nacidos antes
del 2000 y letra después; sin ella un alumno de 2006 se registra en 1906) y que
`290230` pasa el patrón sin ser un día real, así que se valida con `checkdate`.
El endpoint `POST /identidad/curp` devuelve eso en vivo y el formulario se
autollena al teclear. Lo llenado queda EDITABLE: hay CURP mal emitidas y actas
que las corrigen, y la CURP manda sobre lo tecleado pero solo sobre lo que ella
sabe.

### EXTRANJERO no es una CURP
Es la marca de «no tengo». Guardar el literal en `personas.curp` —que es
UNIQUE— permitiría exactamente UN extranjero en toda la escuela; el segundo
chocaría con un error incomprensible. Se traduce a curp null + entidad «Nacido
en el Extranjero» (clave NE) y entonces —y solo entonces— aparece el país. Con
CURP el país se obvia: tenerla implica registro en México. En el selector,
«Nacido en el extranjero» va arriba junto a «sin especificar», no perdido en la
N entre Nayarit y Nuevo León: es una respuesta de otra naturaleza.

### Duplicados: se avisan, no se bloquean
`POST /identidad/duplicados` busca por CURP, por correo (insensible a
mayúsculas) y por nombre completo + fecha de nacimiento. El nombre SOLO no basta
—hay tocayos, y bloquear por homonimia obligaría a inventar variantes del
nombre para poder capturar—. Se avisa al salir del nombre o del correo, no al
guardar: avisar tras veinte campos llenos es avisar tarde.

### HALLAZGO: el `unique` de la CURP contradecía la reutilización
El alta REUTILIZA a la persona cuando la CURP ya existe —es el principio de cero
recaptura—, pero la validación traía `unique` sobre curp y la rechazaba antes de
llegar ahí. O sea que esa rama del controlador nunca corría: quien intentaba
registrar a un egresado que vuelve por un posgrado se topaba con «ya existe una
persona con esa CURP» y ningún camino para seguir. Reutilizar ES la protección
contra duplicados; rechazar no lo es. El `unique` se quitó del alta y solo
aplica al editar (dos personas distintas no pueden terminar con la misma CURP).

### El correo, y los términos
El correo pasó a obligatorio en aspirantes: es la credencial de su portal, sin
ella hay que perseguirlo por teléfono para darle acceso. Y `acepto_terminos`
dejó de aceptarse desde el alta administrativa: consentir el proceso de admisión
es un acto del interesado; quien captura no puede hacerlo en su nombre. Solo el
portal del aspirante lo escribe.

### Marcado en rojo
`CampoTexto` pinta el borde del control en rojo cuando falla, no solo el mensaje
debajo. En una pantalla de veinte campos, el color en el propio control es lo
que permite encontrar el que falló sin recorrerla entera. (Hubo que poner
`inheritAttrs: false` + `v-bind="$attrs"` para que `@blur` llegue al input y no
al div, donde `blur` no burbujea.)

Suite nueva: `scripts/prueba-identidad.php` (34 checks). Total: 24 suites, 675
verificaciones, verdes.

---

## Toasts, y Finanzas que solo se ve a sí mismo

Dos pedidos pequeños del cliente: un toastr para Vue —mensajes dinámicos tras
cada acción, en vez de la barra fija— y que un alumno en Finanzas no vea un
buscador de CURP/matrícula/nombre, «si un alumno solo puede ser el propio
alumno».

### Toasts con vue-sonner
`<Toaster>` global en `AppLayout`, abajo a la derecha, con `rich-colors` y
`close-button`. Los flash del backend (`->with('exito'|'error'|'advertencia')`)
se disparan como toast; se conservan las mismas tres claves que ya usaba la
barra, así que ningún controlador cambió. Las tres barras fijas se quitaron:
empujaban el contenido y se quedaban clavadas hasta cambiar de pantalla —un
«guardado» seguía visible mientras ya editabas otra cosa—.

**HALLAZGO: el primer toast de cada página se perdía.** El primer intento usó un
watcher `immediate`. Al navegar, el flash llega junto con el montaje del layout,
y el `immediate` corría ANTES de que el `<Toaster>` —hijo del mismo componente—
existiera, así que el aviso se emitía al vacío. Se movió a `onMounted`, que corre
después de que los hijos se montaron; el watcher se queda solo para las visitas
parciales que no remontan el layout. Verificado en el navegador: al guardar un
aspirante aparece «Aspirante actualizado.» abajo a la derecha.

### Finanzas: la misma permission, distinto alcance
`ver-adeudos` la tienen el administrativo de finanzas Y el alumno. Es la misma
permission; lo que cambia es SOBRE QUIÉN, y eso lo dice la FACETA del rol
activo, no el permiso —la regla de dos capas de siempre—. Un rol de faceta
`alumno`/`padre`/`tutor` ve únicamente sus matrículas.

No era solo un buscador de más: **era una fuga**. Con `ver-adeudos`, un alumno
entraba a `/finanzas` y veía la cartera completa de la escuela, con el saldo
total de todos y un buscador para hurgar en cualquiera. Ahora la lista se acota
a sus matrículas, los totales también (`saldosPorMatricula` acepta un
`personaId`), el encabezado dice «Mi saldo / Mis matrículas» y el buscador y los
filtros de cartera desaparecen: sobre una o dos matrículas propias no se busca a
nadie. Y `cuenta()` verifica dueño: sin eso, un alumno cambiaba el id en la URL
y leía el estado de cuenta de cualquier otro.

Suite nueva `prueba-finanzas-alcance` (7 checks): invoca al controlador y
comprueba que el alumno no reciba nada ajeno ni por la lista, ni por los
totales, ni saltando por la URL (403).

### De paso: dos teardown frágiles
- `prueba-finanzas-alcance` fabricaba su alumno reutilizando la persona de la
  primera matrícula sembrada, que ya tenía usuario → chocaba con el índice
  único. Ahora se crea su propia persona y matrícula: no depende de qué haya
  sembrado.
- `prueba-actas` borraba todas las actas con `acta_origen_id` en un solo
  `delete`. Con una cadena de dos correcciones (152←153←154), MySQL podía tocar
  la intermedia antes que la que la referencia y la FK autorreferenciada
  tronaba. Se borra de la más nueva a la más vieja (id descendente = hijo→padre).

Total: 26 suites, 682 verificaciones, verdes.

---

## El menú lateral se filtra por ÁMBITO, no solo por permiso

Queja del cliente: operando como administrativo seguía viendo apartados de
docente. «Aunque lo tenga dado de alta no debo ver las opciones que no son del
rol; para verlas debo cambiar de rol.»

La causa era fina: el menú filtraba cada opción por permiso, y
`capturar-calificaciones` es un permiso COMPARTIDO entre administrativo y
docente —control escolar asienta en nombre del docente ausente, decisión ya
tomada—. Como dirección general lo tiene, la opción «Captura» pasaba el filtro y
con ella asomaba toda la sección «Docencia». El permiso estaba bien concedido; lo
que estaba mal era que una sección de un oficio apareciera en otro.

La regla nueva: la SECCIÓN se filtra por el ÁMBITO del rol activo
(administrativo/docente/alumno/aspirante/tutor/padre), y solo después cada
opción por permiso. El ámbito lo expone el backend en `rol_activo.ambito`
(= `Rol::ambitoDePermisos()`, que además mapea cualquier faceta creada por la
escuela a «administrativo»). Cada sección declara `facetas: string[]`; el Panel
es universal (`null`).

«Captura» se movió a Control escolar para el administrativo, y se quedó en
Docencia para el docente. Es el mismo permiso, distinta puerta según el oficio;
nadie ve las dos a la vez porque las secciones ya no se cruzan de ámbito.

Verificado en el navegador conmutando el rol activo: como dirección general ya
NO aparece «Docencia»; como docente solo se ven «Panel» y «Docencia», ninguna de
admin; como control escolar, «Captura» cuelga de Control escolar. `prueba-roles`
sube a 48 checks y fija que un permiso compartido con docente no cambie el
ámbito administrativo —el que decide la sección—. Total: 26 suites, 683
verificaciones.

---

## Cambio de rol a barra derecha, y «Mi perfil» aparte

El cliente pidió reorganizar la esquina de la cuenta: el cambio de rol enterrado
en el dropdown de perfil debía pasar a un icono que abre una barra lateral
derecha (como Apariencia), y el dropdown de perfil debía quedar para los datos
de la persona —nombre, foto, contraseña— o cerrar sesión.

Es correcto de raíz: conmutar de rol es una acción frecuente y de primer nivel,
no un ajuste de cuenta; mezclarla con «cerrar sesión» y con editar el perfil las
igualaba a todas. Ahora:

- `PanelRoles.vue` —espejo de `PanelTema`— lista los roles disponibles a la
  derecha; el icono solo aparece si hay más de uno (con uno no hay nada que
  elegir). Cada rol muestra su faceta y su alcance (global o acotado a campus).
  Conmutar al rol que ya está activo no reconsulta: evita redibujar todo el
  menú, el tema y los permisos por hacer clic donde ya estás.
- El dropdown de perfil se reduce a identidad + «Mi perfil» + «Cerrar sesión».
- `PerfilController` + `Perfil/Index.vue`: sin id en la URL —siempre la cuenta
  autenticada—, edita nombre y correo, cambia la foto por el endpoint de
  siempre (el mismo de la ficha del alumno) y la contraseña **exigiendo la
  actual**. Esto último no es burocracia: cambiar la contraseña propia sin
  conocer la vigente convertiría una sesión abierta olvidada en un secuestro de
  cuenta.

De paso, el correo del perfil respeta unicidad (es la credencial de acceso) y el
segundo apellido vacío se guarda como null, no como cadena vacía: «sin segundo
apellido» es ausencia del dato, y debe compararse igual que quien nunca lo tuvo.

Verificado en el navegador: el panel lateral conmuta el rol y el menú reacciona
(a Docente queda Panel + Docencia); «Mi perfil» carga con foto, datos y cambio
de contraseña. Suite nueva `prueba-perfil` (7 checks). Total: 27 suites, 690
verificaciones.

Pendiente del mismo pedido, para retomar: al INICIAR sesión, si hay más de un
rol, preguntar con cuál entrar (hoy entra con el último activo).

---

## Académico > Institución (módulo 1 de la revisión de Académico)

Primer bloque de la revisión del cliente sobre el módulo Académico. Apartado
nuevo «Institución»: la persona moral educativa dueña de los campus, con clave,
nombre y logo.

Es un dato de ENCABEZADO, no una entidad con reglas: membreta lo que la escuela
emite y nada más. Por eso `instituciones` es un catálogo simple, y el vínculo
`campus.institucion_id` es informativo —nullable, `nullOnDelete`—, no una
restricción sobre la oferta ni los grupos.

Decisiones:
- **Se siembra una institución por defecto** («PRINCIPAL», con el nombre del
  tenant) en la propia migración, y los campus existentes se enganchan a ella.
  Así la preselección «si solo hay una, va automática» —que pide el cliente para
  el formulario de Campus— arranca con algo que preseleccionar, y ningún
  plantel queda huérfano.
- **El logo se maneja como la foto de una persona**: disco privado + ruta
  autenticada (`/academico/instituciones/{id}/logo`), no archivo público. Un
  logo no es secreto, pero abrir el disco al mundo por un caso que no lo exige
  es abrir de más. Se reutiliza el mismo patrón ya probado.
- **No se borra una institución con campus**: se perdería a qué persona moral
  pertenecen. Primero se reasignan.

Suite nueva `prueba-instituciones` (5 checks): default sembrado, alta, unicidad
de clave, salvaguarda de borrado. Verificado en el navegador: el apartado sale
en la sub-nav de Académico, lista la institución sembrada con sus 3 campus, y el
alta muestra el toast y aparece en la lista.

Pendiente del mismo pedido (módulos siguientes, en orden): Campus (selector de
institución + Entidad Federativa obligatoria, quitar «Nacido en el extranjero»),
Carreras (quitar Objetivo), Plan de estudios (renombres + tooltip), Asignaturas
(Tipo a 4 fijas, Descriptores como catálogo multiselección, 3 imágenes de
diseño), y el módulo Configuración/Catálogos con el desdoble Entidad↔Identidad
Federativa.

---

## Académico > Campus, y el desdoble Entidad ↔ Identidad Federativa (módulo 2)

Segundo bloque de la revisión de Académico. El cliente pidió, en Campus: un
selector de Institución (informativo, preseleccionado si solo hay una); quitar
«Nacido en el extranjero» del selector de entidad —un campus es un LUGAR, no una
persona—; y que la Entidad Federativa sea obligatoria.

### El desdoble, decidido como global (landlord)
El cliente resolvió que Entidad e Identidad Federativa son catálogos GLOBALES
(compartidos entre escuelas, super-admin), y Nivel de estudios por tenant.

Hasta ahora había UN solo catálogo landlord (`entidades_federativas`) que servía
a la vez para lugares (campus) y personas (nacimiento), con el 33 = «Nacido en
el Extranjero» —redacción de persona— aun cuando etiquetara un plantel. Se
desdobló en dos, ambos landlord:
- `entidades_federativas` (LUGARES): el 33 pasa a «Extranjero».
- `identidades_federativas` (PERSONAS, tabla nueva): el 33 es «Nacido en el
  extranjero».

**Clave de la migración: la tabla nueva hereda los MISMOS ids (1..33).** Las
personas ya guardan `entidad_nacimiento_id` en ese rango; al repuntar
`IdentidadPersona` y `Persona::entidadNacimiento()` al catálogo de identidad,
los ids siguen cuadrando y NO hubo que migrar dato alguno de las personas. Las
claves de dos letras (AS…NE) son idénticas en ambos, así que la lectura de la
CURP no se enteró del cambio.

### Campus
- `campus.institucion_id` (del módulo 1) se expone en el formulario como
  selector informativo; con una sola institución se preselecciona —obligar a
  elegir cuando no hay decisión es un clic vacío—.
- `entidad_id` pasa a obligatorio y apunta al catálogo de LUGARES (33 =
  «Extranjero»). Sin `exists` en la validación: el catálogo vive en la landlord
  (otra conexión) y se referencia sin FK, como el resto de refs centrales.
- El listado gana columna Institución.

Verificado en el navegador: en Campus el selector de Entidad muestra
«Extranjero» y ya no «Nacido en el extranjero»; con una sola institución queda
preseleccionada; el listado trae la columna Institución. `prueba-identidad` sube
a 37 checks fijando el desdoble (redacciones distintas del 33, ids compartidos).
Total: 27 suites, 698 verificaciones.

Pendiente de que estos dos catálogos globales tengan pantalla de administración
(super-admin), junto con el módulo Configuración/Catálogos.

---

## Académico > Carreras y Plan de estudios (módulos 3 y 4)

Dos bloques chicos de la revisión, ambos sobre formularios.

**Carreras**: se retiró el campo «Objetivo» del formulario. La columna se
conserva en la BD por si vuelve, pero ya no se captura ni se valida.

**Plan de estudios**: renombres pedidos por el cliente, sin tocar datos ni
lógica —solo la etiqueta que ve el usuario—:
- «Tipo de autorización» → «Autorización o Reconocimiento».
- «Calificación máxima» → «Calificación máxima asignable».
- «Créditos para titularse» → «Créditos para completar la carrera».
- «Créditos totales del plan» conserva su nombre y gana un tooltip: «Total de
  créditos obligatorios + total de créditos optativos».

Para el tooltip se agregó una prop `tooltip` a `CampoTexto`: una ⓘ junto a la
etiqueta con la nota al pasar el cursor. Es reutilizable, no un parche local.

Las 9 opciones del catálogo «Autorización o Reconocimiento» (RVOE Federal/
Estatal, Autorización Federal/Estatal, Acta de Sesión, Acuerdo de Incorporación,
Acuerdo Secretarial SEP, Decreto de Creación, Otro) se alinean en el módulo de
Catálogos, junto con su pantalla de administración: mutar ahora un catálogo que
los planes ya referencian, sin la UI para revisarlo, sería a ciegas.

Verificado en el navegador: Carrera ya no muestra «Objetivo»; Plan de estudios
muestra las tres etiquetas nuevas y el tooltip. Suites de carrera/plan en verde.

---

## Académico > Asignaturas: Tipo fijo, Descriptores como catálogo, imágenes de diseño (módulo 5)

Tres cambios sobre la misma entidad, juntos.

**Tipo a cuatro fijas** — Obligatoria, Optativa, Adicional, Complementaria, y
nada más. El catálogo traía «Seminario» y «Taller»; como no los usaba ninguna
asignatura, se RENOMBRARON a los dos que faltaban en vez de borrarlos y crear
otros: el id se conserva y nada que apuntara ahí se rompe. Es un catálogo fijo,
no de los que la escuela amplía.

**Descriptores de campo libre a catálogo multiselección** — antes eran dos
textos (objetivos/bibliografía); ahora son una selección de casillas contra el
catálogo `descriptores` (Bienvenida, Contenido temático, Actividades de
aprendizaje, Criterios de evaluación; admite más). **Al crear una asignatura
vienen TODOS marcados**, como pidió el cliente; el default lo pone el
controlador cuando el formulario no manda la clave, para que valga también si
alguien crea por API. Las columnas de texto se conservan por si hay algo
capturado, pero el formulario ya no las usa.

**Diseño de asignatura** — tres imágenes por materia (la de la materia, la
miniatura para listados, la portada). Se manejan como la foto de una persona o
el logo de la institución: disco privado + ruta autenticada, una ranura por
imagen (`/asignaturas/{id}/imagen/{materia|miniatura|portada}`). La sección solo
aparece al EDITAR: las imágenes se cuelgan del id de la asignatura ya creada,
así que el alta redirige a la edición con un aviso para subirlas.

Verificado en el navegador: Tipo ofrece las cuatro; al crear, los cuatro
descriptores salen marcados; «Diseño» no aparece en alta y sí en edición con las
tres ranuras. Suite nueva `prueba-asignaturas` (7 checks). Total: 28 suites, 705
verificaciones.

Queda para el módulo de Catálogos la pantalla de administración de Descriptores,
Clasificación y Área (agregar/renombrar), y alinear las 9 opciones de
Autorización o Reconocimiento.

---

## Académico > Configuración / Catálogos — Parte A (módulo 6)

Módulo nuevo: una sola pantalla (`/academico/catalogos`) para administrar los
catálogos simples (clave + nombre) que alimentan los formularios de Académico.

**Genérico, no un controlador por catálogo.** `CatalogoAcademicoController`
tiene un REGISTRO catálogo→modelo; alta, edición y borrado son el mismo código
para los seis. Multiplicar el CRUD por catálogo solo multiplica los lugares
donde arreglar el mismo error. Cada entrada del registro declara además CÓMO
saber si un ítem está en uso —lo que distingue borrar un área que nadie asignó
de una que sostiene veinte asignaturas—; lo que está en uso no se puede
eliminar (el botón se deshabilita y el backend lo rechaza igual, porque un POST
se arma a mano).

Catálogos incluidos, agrupados por dónde se usan como pidió el cliente:
- **Asignaturas**: Clasificación, Área, Descriptores.
- **Plan de estudios**: Autorización o Reconocimiento.
- **Carreras**: Turnos, Modalidades.

Se creó el catálogo **Modalidades** (Presencial, En línea, Mixta) —antes la
modalidad de una oferta era un string suelto— y se ALINEARON las nueve opciones
de **Autorización o Reconocimiento** de forma aditiva: se agregaron las que
faltaban y se conservaron las dos previas («Universidad Autónoma», «Incorporación
a universidad») porque un plan podría estar apuntándolas. Podarlas es justo lo
que esta pantalla permite, ahora que se ve qué está en uso.

**Entidad / Identidad Federativa NO se editan aquí**: son globales (landlord),
compartidas entre escuelas, y las administra el dueño de la plataforma. La
pantalla lo dice explícitamente para que no parezca un olvido.

Suite nueva `prueba-catalogos` (7 checks): agrupación, alta, unicidad de clave,
edición, borrado bloqueado en uso, borrado libre sin uso, catálogo inexistente
rechazado. Verificado en el navegador: los seis catálogos agrupados, alta en
vivo con toast. Total: 29 suites, 712 verificaciones.

**Parte B pendiente** (lo delicado, para retomar): mover Nivel de estudios de
landlord a tenant (con backfill de `carreras.nivel_estudios_id`), la pantalla de
super-admin para Entidad/Identidad Federativa, y ligar la modalidad de la oferta
al catálogo (hoy es string) con selección múltiple de campus/modalidad/turno.

---

## Nivel de estudios: de landlord a tenant (módulo 6, parte B — 1/3)

Primer paso de la parte B. El cliente decidió que Nivel de estudios sea por
ESCUELA, no compartido: un bachillerato no oferta doctorados y cada escuela debe
administrar los suyos desde Configuración / Catálogos. (Entidad e Identidad
Federativa se quedan globales porque son claves oficiales que no deben diverger;
el nivel es oferta, no clave oficial.)

Se movió `niveles_estudio` de la landlord a una tabla tenant, con el mismo truco
del desdoble federativo: **la tabla tenant hereda los MISMOS ids** que traía la
landlord (copiando de ella en la migración, con fallback a la lista estándar de
la SEP por si la central no responde). Las carreras ya guardaban
`nivel_estudios_id` en ese rango, así que al repuntar `Carrera::nivelEstudios()`
al catálogo tenant los ids siguen cuadrando y NO hubo que migrar dato de las
carreras (verificado: 0 carreras quedaron sin nivel resuelto). Ahora que es
tenant (misma conexión), la validación de carrera sí comprueba que el nivel
exista, cosa que con la landlord no se hacía.

Nivel entra al admin de Catálogos como séptimo catálogo. Como tiene `orden`
(progresión académica), el controlador genérico ganó soporte mínimo: lista por
`orden` cuando la tabla lo tiene y asigna el siguiente al crear. Bachillerato se
lista antes que Licenciatura, no por la B.

`prueba-catalogos` sube a 9 checks (nivel por progresión, nivel en uso).
Verificado en el navegador: Nivel aparece ordenado en Catálogos con Licenciatura
«en uso»; el formulario de Carreras carga los niveles del catálogo tenant. Total:
29 suites, 714 verificaciones.

Queda de la parte B: admin super-admin de Entidad/Identidad Federativa, y ligar
la modalidad de la oferta al catálogo con multiselección de campus/modalidad/turno.

---

## Módulo 6 parte B (2/3 y 3/3): globales solo lectura, y Oferta fan-out

### Catálogos globales, solo lectura (decisión del cliente)
Entidad e Identidad Federativa se muestran en la pantalla de Catálogos pero NO
se editan desde una escuela: son datos centrales (landlord) que comparten todas,
y editarlos ahí cambiaría el catálogo de todas a la vez. Se listan plegados
(33+33 registros) con distintivo «solo lectura» y una nota de que los administra
el responsable de la plataforma. Su edición real espera a que exista un panel de
dueño de plataforma (no hay rol super-admin en Acadion hoy; el más alto es
dirección general, que es de una escuela).

### Oferta: alta en lote (fan-out)
El cliente pidió que una carrera pueda ofertarse en varios campus, modalidades y
turnos. En vez de reestructurar `oferta` a muchos-a-muchos —que habría tocado
matriculación, pagos y resolutores fiscales que dependen de `oferta.campus_id`
único—, el alta hace **fan-out**: se eligen los conjuntos y se genera una Oferta
CONCRETA por combinación (campus × modalidad × turno). Cada Oferta sigue con un
solo campus/turno/modalidad, así que nada de lo que depende de eso cambia; solo
se crea en lote. Lo que ya existe se omite y se avisa cuántas. La edición sigue
tocando UNA oferta (selects simples).

La **modalidad** pasó de enum fijo (`presencial/online/mixta`) a referenciar el
catálogo `modalidades` (se guarda su clave; se resuelve el nombre para mostrar).
Y como ahora una carrera puede ofertarse en presencial Y en línea en el mismo
campus+turno, la modalidad entró al índice único de `oferta`
(`carrera+plan+campus+turno+modalidad`). La migración crea el índice nuevo ANTES
de tirar el viejo, porque el viejo sostiene la FK de `carrera_id` y MySQL no deja
quitarlo mientras sea el único que la respalda.

**Y el propósito de todo esto**: al registrar un aspirante, elegir un campus
ahora FILTRA las ofertas a solo las que se imparten ahí (el campus viaja en cada
oferta y el formulario filtra), en vez de ofrecer programas que ese plantel no
tiene. Si la oferta elegida deja de pertenecer al campus, se limpia.

Suite nueva `prueba-oferta-fanout` (6 checks): fan-out por combinación, no
duplica al reejecutar, turno nulo, modalidad validada contra catálogo, plan de
la carrera. Verificado en el navegador: alta con casillas y contador de
combinaciones reactivo; en aspirante, elegir campus baja las ofertas de 3 a 1.
Total: 30 suites, 720 verificaciones.

Con esto se cierra la revisión de Académico. Único pendiente consciente: el
panel de super-admin para editar los catálogos globales (necesita definir dónde
vive la administración de plataforma). Backlog del cliente (CURP como select con
catálogo de Responsables de Titulación) queda sin implementar, como se pidió.

---

## Control Escolar > Ciclos: año + periodo, clave generada, nivel opcional

Revisión del cliente sobre Ciclos.

**La clave dejó de teclearse.** Se capturan por separado Año (4 dígitos) y
Número de periodo (1–4), y la clave se genera de ambos con guión: 2026 + 1 →
«2026-1» (separador confirmado por el cliente; el formato viejo «2026-2027/1» se
respeta en los ciclos existentes vía backfill: se lee el año de los primeros 4
dígitos y el periodo tras el último separador). Como la clave no viaja del
formulario, su unicidad se valida a mano sobre la clave generada.

**Tipo de periodo: no se captura** (el cliente lo retiró; era un malentendido).

**Nivel de estudios opcional en el ciclo.** Si se pone, acota qué grupos caben
dentro. `nivel_estudios_id` nullable con `nullOnDelete`. El formulario muestra
un aviso reactivo de lo que el ciclo restringe (nivel y/o campus) para que quien
lo crea sepa qué está acotando antes de guardar — la restricción real sobre los
grupos se aplica en el formulario del grupo (siguiente módulo).

Campus del ciclo ya era muchos-a-muchos (pivote `ciclo_campus`), así que la
parte de «uno o varios campus» ya estaba; lo nuevo es que ese conjunto acotará
los grupos.

Suite nueva `prueba-ciclo-clave` (7 checks): clave generada con guión, año y
periodo guardados, nivel ligado, clave no duplicable, año de 4 dígitos, periodo
1–4, nivel opcional. Verificado en el navegador: el formulario muestra Año +
Número de periodo con la clave «2026-1» de vista previa, sin campo de clave
manual, y el aviso de restricción al elegir nivel. Total: 31 suites, 727
verificaciones.

---

## Control Escolar > Grupos: semestre, restricción del ciclo y quitar docente

Revisión del cliente sobre Grupos.

**Semestre opcional.** No es obligatorio —hay grupos de tronco común que mezclan
semestres—, pero cuando lo tiene sirve de default: al abrir materias, el filtro
de «Abrir materias» arranca en ese semestre (que es el `periodo` de la malla) en
vez de mostrar los cincuenta reactivos del plan. Comodidad de captura, no regla.

**El ciclo acota los grupos (aplicado en el formulario del grupo, como se
acordó).** Si el ciclo tiene campus, el grupo solo puede ser de esos campus; si
el ciclo se acota a un nivel de estudios, el plan del grupo debe ser de una
carrera de ese nivel. El formulario ofrece solo lo válido (los ciclos viajan con
sus `campus_ids` y su `nivel_estudios_id`, las carreras con su nivel) y el
servidor lo vuelve a exigir —una casilla que no existe no es defensa—. El nivel
del grupo se deriva del plan→carrera; no se agregó columna de nivel al grupo.

**Quitar/cambiar docente.** El backend ya tenía `quitarDocente`; lo que faltaba
era exponerlo. El detalle del grupo ahora lista cada docente asignado con su
tipo (titular/adjunto) y un botón «quitar». «Cambiar» es quitar el equivocado y
asignar el correcto: no hace falta un flujo aparte. Antes solo se veían los
nombres sin forma de retirarlos.

Suite nueva `prueba-grupo-semestre` (5 checks): semestre guardado, campus fuera
del ciclo rechazado, campus del ciclo aceptado, plan de otro nivel rechazado,
plan del nivel aceptado. Verificado en el navegador: el formulario tiene
Semestre; el detalle muestra el docente con su tipo y botón «quitar». Total: 32
suites, 732 verificaciones.

---

## Interfaz: control flotante de tamaño de fuente (por sesión)

El cliente pidió un botón flotante para subir o bajar el tamaño de letra,
guardado «en una variable de sesión, no en base de datos, para que cada sesión
nueva inicie con el tamaño por defecto».

Se implementó en `AppLayout` con `sessionStorage` —no localStorage ni BD—: eso
es exactamente «por sesión de navegador», así que persiste mientras se navega
(la SPA de Inertia no recarga) pero una sesión nueva arranca en 100 % porque el
almacén viene vacío. Es una ayuda momentánea (una pantalla que se ve chica en
tal monitor), no una preferencia que deba perseguir a la persona —para eso está
Apariencia, que sí se guarda—.

Se aplica como `font-size` del elemento raíz en porcentaje (80–140 %, pasos de
10): como la interfaz mide en `rem`, mover la raíz escala todo proporcional. El
control va abajo a la IZQUIERDA para no pelearse con los toasts, que salen abajo
a la derecha.

Verificado en el navegador: A+/A− cambian el tamaño, se guarda en sessionStorage
(no en localStorage), persiste al navegar dentro de la sesión, y hace tope en
80 % y 140 %. Total: 32 suites, 732 verificaciones (el control es solo frontend;
la suite se corrió como regresión y siguió en verde).

Con esto se cierra esta revisión (Ciclos, Grupos e interfaz).

---

## Ciclos: el nivel de estudios pasa de uno a VARIOS

Ajuste pedido por el cliente: al crear un ciclo, el nivel de estudios debía ser
de selección múltiple, igual que el campus, no de uno solo.

`ciclos.nivel_estudios_id` (columna única) se sustituyó por el pivote
`ciclo_nivel`, espejo de `ciclo_campus`; la relación del modelo pasó de
`nivelEstudios()` (belongsTo) a `niveles()` (belongsToMany). El valor único que
cada ciclo tuviera se copió al pivote antes de tirar la columna, así ningún
acotamiento existente se perdió.

En el formulario, el selector de nivel se volvió casillas (`CampoCasillas`, el
mismo componente del campus). La restricción sobre los grupos ahora es de
conjunto: si el ciclo tiene niveles, el plan del grupo debe ser de una carrera
cuyo nivel esté ENTRE ellos (`whereIn`), no igual a uno solo. El aviso del
formulario lista todos los niveles marcados.

Suites actualizadas: `prueba-ciclo-clave` sube a 8 (incluye un caso de varios
niveles a la vez); `prueba-grupo-semestre` crea los ciclos y sincroniza sus
niveles por el pivote. Verificado en el navegador: el formulario ofrece niveles
como casillas y el aviso lista los dos marcados («Bachillerato, Licenciatura»).
Total: 32 suites, 733 verificaciones.

---

## Clave oficial SEP (cveInstitucion / cveCarrera): se reutiliza la clave interna

El título electrónico de la SEP (`SEP_XSDTituloElectronico.xsd`) exige
`cveInstitucion` en el nodo Institución y `cveCarrera` en el nodo Carrera: la
clave OFICIAL de la institución y de la carrera ante la SEP.

Decisión del cliente: NO se agregan columnas oficiales aparte. Se reutiliza la
`clave` que ya se captura en `instituciones` y en `carreras` como la clave
oficial SEP; se asume que la clave que la escuela captura ES la de la SEP.
En carreras, el `identificador` sigue siendo el id interno estable (academyx),
distinto de la clave oficial.

Para que la equivalencia quede explícita y estable de cara al futuro módulo de
titulación, los modelos exponen `Institucion::cveInstitucion()` y
`Carrera::cveCarrera()`, ambos devuelven `->clave`. El generador del XML de
titulación consumirá esos métodos, no la columna directa, por si algún día la
decisión cambiara a una columna dedicada.

En la UI, los formularios de Institución y Carrera etiquetan ese campo como
«Clave oficial (SEP)» / «Clave (SEP)» con ayuda que aclara que es la
cveInstitucion / cveCarrera del título.

---

## Documentos por carrera: era vestigio, se elimina

El formulario de carrera tenía una sección «Documentos de admisión» (pivote
`documento_carrera`). Al revisarlo, ESE pivote NO alimentaba el flujo real: los
documentos que se piden a un aspirante salen de `documento_ambitos` (por
ámbito/etapa), que consumen `ProgresoSolicitud` y `PortalAspiranteController`.
Los checkboxes de la carrera guardaban datos que nadie leía.

Decisión del cliente: la documentación no va en carrera —Académico debe ser
carga de datos generales—. Se elimina el vestigio (sección del formulario,
relaciones `Carrera::documentos()` / `DocumentoRequerido::carreras()`, el seed
de CrearOfertaDemo y la tabla `documento_carrera`).

PENDIENTE: la documentación requerida por PLAN de estudios y su administración
se harán desde Admisiones, sobre el flujo real (`documento_ambitos`), con la
granularidad que se defina (por plan/ámbito). No se creó `documento_plan` ahora
para no dejar otro pivote sin consumir.

## 2026-08-02 — El índice que sostiene una llave foránea (trampa resuelta)

Dos migraciones distintas murieron con el mismo error, con seis días de
diferencia:

```
Cannot drop index '…': needed in a foreign key constraint
```

La primera al separar el pase de lista en teoría y práctica —había que cambiar
el unique `(inscripcion_id, fecha)` por `(inscripcion_id, fecha, modalidad)`—.
La segunda al retirar `tema` de los reactivos, que obligaba a tirar el índice
`(curso_id, tema)`.

**La causa es la misma y no se ve leyendo la migración.** MySQL exige que toda
llave foránea esté cubierta por algún índice, y acepta cualquiera que EMPIECE
por su columna. Un índice compuesto creado para acelerar consultas termina
sosteniendo la foránea sin que nada lo declare. El día que se quiere tocar, la
base se niega.

Las dos veces se resolvió igual —crear el sustituto ANTES de retirar el viejo—
y las dos veces se descubrió fallando, a mitad de una migración ya aplicada
parcialmente.

**Qué se hizo ahora:** `App\Support\IndiceQueSostieneUnaFk`, con `reemplazar()`
y `reponer()` para el rollback. Hace el orden correcto, comprueba antes de
actuar —de modo que un reintento tras un fallo parcial no choque contra su
propio trabajo— y deja el motivo escrito donde se va a leer. La migración de los
reactivos quedó usándolo, como ejemplo vivo. Queda además anotado en el
`CLAUDE.md`, en una sección de trampas al migrar.

**Lo que NO se hizo, a propósito:** indexar todas las llaves foráneas «por si
acaso». Un índice de más se paga en cada escritura, para siempre, a cambio de
evitar un problema que aparece solo cuando se cambia el esquema y se arregla en
dos líneas. En una tabla como `mensajes` sería cobrarle a cada mensaje del chat
el precio de una migración futura. Se paga cuando hace falta, no antes.

## 2026-08-18 — Rúbricas de evaluación: ámbitos, congelamiento y escala

Pedido del cliente: poder calificar una actividad **con rúbrica**, con rúbricas
«de la plataforma» (de cada tenant) y rúbricas «del docente», y poder configurar
en las actividades de un plan en línea que se califican con una y cuál.

La spec preveía `rubricas` referida por `actividades` pero no definía la tabla
(«tabla propia»). Estas son las decisiones que se tomaron al definirla.

### Dos ámbitos en UNA tabla, no dos tablas

`rubricas.ambito` es `plataforma` (sin dueño, la ve y la usa toda la escuela) o
`docente` (con `persona_id`, sólo su dueño). Dos tablas habrían duplicado
`criterios` y `niveles`, y con ellos cada regla: el congelamiento, el cálculo,
el copiado a los grupos.

**La rúbrica de otro docente no se ve ni con `gestionar-rubricas`**, y pedirla
devuelve 404 y no 403 —un 403 ya revelaría que existe—. Una rúbrica propia es un
borrador de trabajo; quien la quiera compartir la publica.

### El máximo no se guarda: se deriva

Un criterio no tiene columna de puntos; su máximo es el nivel más alto, y el
total de la rúbrica es la suma de esos máximos. Una columna podría decir «vale
10» con un nivel máximo de 8 y no habría forma de saber cuál manda. Los puntos
viven en UN solo sitio: el nivel.

### Se congela al primer uso; para cambiarla se DUPLICA

En cuanto una rúbrica califica a alguien, su estructura deja de editarse:
quitarle un criterio dejaría las evaluaciones hechas sumando un total que ya no
cuadra con la suma de sus partes. Es la misma decisión que ya tomaban
`formularios` (se congela con la primera respuesta) y `esquema_evaluacion` (no se
edita con calificaciones capturadas).

El nombre y el interruptor sí se siguen pudiendo cambiar: son de la ficha, no de
la cuenta.

### Por eso la actividad APUNTA a la rúbrica en vez de copiarla

Rompe con `CopiadorDeCurso`, que copia todo lo que se lleva un grupo, y es a
propósito. Copiar la rúbrica por grupo y ciclo partiría el catálogo en cientos
de duplicados y «las rúbricas de la escuela» dejaría de significar algo: nadie
podría corregir una y ver el efecto.

Lo que obligaba a copiar el examen —que editar la plantilla cambie lo que un
grupo está contestando— aquí no aplica, justamente por el congelamiento.

### En la plantilla del plan, sólo las de la escuela

La plantilla se copia a todos los grupos que abran esa materia, en todos los
campus y ciclos. Una rúbrica propia de quien edita el plan acabaría calificando
en grupos que dan otras personas, que ni siquiera pueden verla. En el curso del
docente sí caben las suyas: es su materia y su grupo.

### La rúbrica es una ESCALA, no la nota

Sus puntos se llevan a los de la **actividad**, que es lo que ya pondera dentro
del componente: una rúbrica de 20 en una actividad sobre 10 da 8.5, no 17. Sin
esa conversión una misma rúbrica no se podría reusar en trabajos de distinto
peso, que es justo para lo que sirve tener catálogo.

### Un criterio sin evaluar NO es un cero

Misma regla que ya rige la captura de calificaciones. Lo evaluado se guarda y la
entrega queda **sin calificar** hasta que estén todos los criterios. Si faltara
uno y se promediara igual, el alumno recibiría una nota más baja porque el
docente se distrajo, y nada lo diría.

### Los puntos los pone el servidor

De la petición sólo se cree QUÉ nivel se eligió; cuánto vale se lee de la base, y
se comprueba que el nivel sea **de ese criterio**. `entrega_rubrica.puntos` se
guarda de todas formas —pudiendo leerse del nivel— porque la evaluación es un
hecho fechado y el nivel es catálogo: un renglón que dice «le di 8» no debería
tener que preguntarle a nadie cuánto valía ese 8.

### El alumno la ve ANTES de entregar

Es a lo que sirve. Leer «para el nivel alto hay que sostener la tesis con dos
fuentes» cambia lo que se entrega; leerlo con la nota sólo explica un 7 que ya no
se puede mover. Calificada, se marca el nivel obtenido y los demás se **atenúan**
en vez de esconderse: ver dónde quedó uno respecto de lo que había arriba es la
mitad de la información.

### Dónde vive

`/rubricas`, colgando de la raíz como `/captura`, porque la usan dos oficios.
Entrar lo abre el permiso derivado `usar-rubricas` (`gestionar-rubricas` **o**
`capturar-calificaciones`); lo que se puede hacer dentro lo resuelve el
controlador, porque es alcance y no acceso. Al docente no se le pide permiso
aparte para armarse una rúbrica: eso es parte de calificar.

## 2026-08-19 — Clases en línea: Zoom y Meet no son simétricos

Pedido del cliente: que los docentes levanten sesiones de videoconferencia desde
su materia, con **Zoom** (cargando tantas licencias como haga falta «porque
pueden existir múltiples clases simultáneas») y con **Google Meet** «si es que se
puede, de la misma forma que Zoom». El administrador enciende, apaga y carga
cuentas; al alumno le aparece el botón solo.

### La decisión que gobierna todo: no son la misma cosa

Se puede hacer lo mismo con los dos de cara al docente y al alumno, pero **por
debajo no se parecen**, y fingir que sí producía una función rota:

- En **Zoom**, una licencia de anfitrión sostiene UNA reunión a la vez. Dos
  clases a las 9:00 exigen dos licencias. De ahí sale todo el reparto.
- En **Google Meet** no hay tal límite: no existe API de reuniones, el enlace
  nace de un evento de **Calendar** con `conferenceData`, y una cuenta de
  Workspace puede organizar veinte eventos simultáneos. La «cuenta» ahí no es
  una licencia que se agote: es la identidad que organiza.

Tratarlos igual llevaba a una de dos mentiras: pedirle a la escuela comprar
licencias de Meet que no existen, o dejar que Zoom sobrevenda una licencia y que
la segunda clase eche a la primera de la sala con el grupo dentro.

`ProveedoresVideoCatalogo::unaReunionPorCuenta` es la bandera que los separa, la
lee el asignador, y ante un proveedor desconocido responde `true` —el lado
seguro: como mucho se dirá que no hay cuentas libres—.

**Lo que Meet exige y Zoom no**: Google Workspace (con Gmail personal no se
puede) y una cuenta de servicio con delegación en todo el dominio. Se dice en la
ayuda del campo, porque es la clase de requisito que se descubre a media
configuración y con las credenciales ya pegadas. Y en Meet **no hay enlace de
anfitrión aparte**: todos entran por el mismo y el control lo da ser el
organizador, así que `url_anfitrion` va en null en vez de duplicar el otro.

### La FILA es el apartado de la licencia

Al programar: se inserta la clase SIN enlaces dentro de una transacción que
bloquea las cuentas (`lockForUpdate`), luego se llama al proveedor, y al final se
le ponen los enlaces.

Sin eso hay una carrera real: dos docentes programando a las 9:00 al mismo tiempo
preguntan «¿hay licencia libre?», los dos leen que sí —ninguno ha escrito
todavía— y los dos se llevan la misma. La llamada HTTP queda FUERA del bloqueo:
sostenerlo mientras se espera a Zoom serializaría a toda la escuela detrás de un
servicio que a veces tarda cinco segundos.

Y se limpia en las dos direcciones: si el proveedor falla se suelta el apartado,
y si la sala se creó pero no se pudo guardar se cancela allá. Una reunión
huérfana ocupa la licencia de las 9:00 para siempre y nadie sabe de dónde salió.

### `url_anfitrion` es una credencial, no un enlace

El `start_url` de Zoom entra como anfitrión **sin pedir contraseña**: quien lo
tenga puede silenciar, expulsar y terminar la clase de otro. Por eso lo que se le
manda al alumno lo arma `Videoconferencia::paraElAlumno()` —en el modelo, no en
cada pantalla—: si cada vista tuviera que acordarse de omitirlo, algún día se le
olvidaría a alguna. El `url_join` tampoco viaja mientras la clase no esté
abierta: el enlace de la semana que viene no tiene por qué estar en el HTML de
hoy.

### El traslape se guarda con INICIO y FIN

Y no con una duración. La pregunta al programar es «¿esta licencia está libre
entre las 9 y las 11?»; con una duración habría que calcular el fin de cada
candidata dentro del WHERE. Con dos columnas es una comparación que sostiene el
índice `(cuenta_id, inicio)`, que además es el que cubre su foránea.

Y se comparan las DOS condiciones, no sólo el inicio: una clase de 9 a 11 y otra
de 10 a 10:30 no comparten hora de arranque y chocan igual.

### Efecto colateral: el manejador de excepciones no contemplaba el 422

`AvisoParaElUsuario::lanzar(422, …)` salía como página HTML de error, así que el
mensaje más útil de toda la función —«tus 2 licencias están ocupadas de 9 a 10;
mueve la clase o compra otra»— no llegaba a ninguna parte: el docente pedía la
clase y no pasaba nada.

Se agregó el 422 a la lista, **con la condición de que el motivo sea nuestro**.
`ValidationException` también es 422 y a ésa la maneja Inertia devolviendo los
errores por campo: dejarlo pasar a secas habría hecho que todos los formularios
del sistema perdieran sus mensajes de validación.

## 2026-08-19 — Archivar las grabaciones: dónde, y por qué hace falta

Pedido del cliente: guardar en automático las grabaciones de Zoom y Meet en una
nube, con opciones como Google Drive o Dropbox.

### Tres hechos que gobiernan el diseño

1. **Zoom sólo entrega por API lo que grabó EN LA NUBE.** Si el docente elige
   «grabar en este equipo», el archivo se queda en su computadora y no hay nada
   que traer. La grabación en la nube es de pago y da unos pocos GB por licencia:
   cuando se llenan, Zoom deja de grabar o empieza a borrar lo viejo. Por eso
   archivar no es un lujo — es lo que evita perder el semestre.
2. **Las grabaciones de Meet YA están en Google Drive**, en el Drive de quien
   organizó el evento. Eso cambia el trabajo: si el destino elegido es Drive, no
   hay que copiar nada (sería pagar dos veces el mismo archivo en el mismo
   Drive); con Dropbox o con el disco propio, sí.
3. **Meet no tiene webhook de grabación.** Zoom avisa con `recording.completed`;
   a Google hay que preguntarle. De ahí que haya un webhook para uno y un comando
   periódico para el otro.

### UN destino a la vez

Con dos encendidos habría que decidir qué enlace se le enseña al alumno, se
pagarían dos almacenamientos por el mismo archivo, y el día que uno falle media
cartera de clases estaría en un sitio y media en otro.

Cambiar de destino **no mueve lo ya archivado**: cada grabación guarda a dónde
fue, así que lo viejo se sigue abriendo donde está.

### El destino por omisión es el disco de la escuela

Es el único que no pide cuentas de nadie, y además el único que puede acotar de
verdad quién abre el archivo: la URL la sirve Acadion, que comprueba que quien
pide sea el docente de la materia o un alumno inscrito. Un enlace de Drive o de
Dropbox, una vez creado, lo abre cualquiera que lo tenga.

### La grabación nace INVISIBLE para el alumno

Y se enciende a mano, desde la materia. Una clase grabada trae caras y voces de
menores de edad: publicarla es una decisión sobre datos personales, no un efecto
secundario de que alguien haya configurado el archivado.

### Se descarga a disco por trozos, y el temporal se borra SIEMPRE

`Http::get()->body()` traería el video entero a memoria y tumbaría el proceso. Y
el `finally` que borra el temporal no es higiene: sin él, cada reintento de cada
clase deja medio giga en la partición del servidor y en un semestre tira todo lo
demás que escribe ahí.

### Idempotencia por `(origen, id_externo)`

Zoom reenvía su aviso si no se le contesta rápido —y contestar tarde es fácil si
uno se pone a descargar dentro del webhook—. Sin esa llave única, la misma clase
se archivaría tres veces.

### El webhook comprueba FIRMA, no origen

En los pagos, el aviso sólo dice QUÉ preguntar y la respuesta sale de consultarle
a la pasarela. Aquí no se puede: el cuerpo trae la URL de descarga y esa es la
que se usa. Así que se valida el HMAC que manda Zoom con el token secreto de la
escuela, con ventana de cinco minutos contra reenvíos. **Sin secreto configurado
el aviso se rechaza**: aceptarlo a ciegas convertiría el endpoint en
«descárgame lo que yo diga», que es un servidor haciendo peticiones a donde le
manden.

### Lo que quedó sin conectar, y por qué se dice

La consulta real a la API de Meet (`conferenceRecords.recordings`) necesita un
Workspace con grabación habilitada para probarse. El comando
`clases:recoger-grabaciones` existe, recorre las clases candidatas y **avisa que
esa parte no está conectada** en vez de fingir que revisó: un comando que dice
«listo» sin haber mirado deja a la escuela creyendo que sus clases se guardan.

### Una trampa de Windows que hizo falsa una prueba

`tempnam()` recorta el prefijo a TRES caracteres: «grabacion-» se vuelve
`gra910D.tmp`. La comprobación de «no quedan temporales» buscaba por
`grabacion-*` y no encontraba nunca nada, así que pasaba con el borrado quitado.
Se descubrió mutando a propósito. Ahora compara el directorio antes y después.

### Ampliación (mismo día): que la publicación automática sea configurable

El cliente pidió poder decidir desde la interfaz si las grabaciones se publican
solas. Es el ajuste `video.grabaciones_visibles_al_llegar`, en
`/plataforma/configuracion`, grupo «Clases en línea» —donde se le juntó la
antelación con que se abre el botón, que estaba suelta en «Docentes»—.

**Por omisión sigue apagado.** Lo que cambia es que ahora es una decisión de la
escuela y no del código; el valor por omisión es el que no publica a nadie sin
que alguien lo pida.

**Se lee al ANOTAR la grabación y se copia a la fila**, no al mirarla. Es la
parte que importa: si se leyera en cada consulta, encender el interruptor
publicaría de golpe todo el historial —un semestre de clases con menores
dentro—, y apagarlo escondería lo que un docente ya había decidido publicar.
Con el valor copiado, el ajuste gobierna lo que llega de aquí en adelante y lo
anterior se queda como estaba.

Comprobado mutando las dos formas de romperlo: ignorar el ajuste al anotar, y
leerlo al mirar (que es lo que lo volvería retroactivo). Caen las
comprobaciones que vigilan cada una.


## 2026-08-19 — Conectada la consulta de grabaciones de Meet

Lo que quedaba pendiente de la entrega anterior. Tres decisiones y dos trampas.

### El puente es el CÓDIGO DE REUNIÓN, no el evento

La API de Meet no sabe nada de Calendar, que es lo que Acadion crea para obtener
el enlace. Lo único que las dos partes comparten son las tres sílabas del enlace
—`abc-defg-hij`—, que identifican el espacio. De ahí que se extraiga del
`url_join` en vez de guardarse en una columna: guardado habría dos verdades sobre
la misma reunión y una podría quedarse vieja.

Se filtra por espacio y no por fecha porque un mismo enlace se reusa: filtrar por
hora traería la clase de otro grupo que se dio en ese rato.

### Sólo `FILE_GENERATED`

Google anuncia la grabación desde que empieza a grabar. Antes de ese estado el
archivo no existe, y registrarla dejaría una «pendiente» que nunca se puede
bajar y que el docente ve como un fallo.

### Con destino Drive no se copia nada

Google ya dejó el archivo en el Drive de quien organizó. Copiarlo del mismo Drive
al mismo Drive sería pagar dos veces el mismo archivo y duplicar un video de
menores sin motivo. Se registra ya archivada, apuntando a donde está.

### Y de paso: lo que va en camino no se reencola

`RecolectorDeGrabaciones` sólo reintenta lo FALLIDO. Antes reencolaba todo lo que
no estuviera archivado, así que cada aviso repetido de Zoom —que los reenvía— o
cada pasada del comando ponía a otro trabajador a bajar el mismo video de 600 MB.
De lo pendiente ya se encarga la cola con sus propios reintentos.

### El JWT de Google, en un solo sitio

`App\Services\Google\TokenDeServicio`, con el alcance como parámetro. Estaba
escrito dos veces —Calendar en `ProveedorMeet`, Drive en `DestinoDrive`— y esto
necesitaba una tercera. Tres copias de una firma criptográfica es como se llega a
que una tenga el `sub` mal y falle sólo en el camino que nadie prueba.

Los alcances NO son intercambiables y por eso están declarados aparte: para bajar
lo que Meet grabó hace falta `drive.readonly` y no el `drive.file` que usa el
destino para subir, porque ese archivo no lo creó esta app.

### Dos comprobaciones que parecían buenas y no lo eran

Salieron mutando, y las dos habrían dado falsa tranquilidad:

- «Una grabación aún en curso no se registra» pasaba con la regla del estado
  quitada: lo que la detenía era que el fixture no traía `driveDestination`, no
  el `STARTED`. Ahora lo trae —como manda Google de verdad— y sólo el estado
  puede pararla.
- «Con error de Google no se registra nada» pasaba con el manejo de errores
  quitado, porque un cuerpo de error tampoco trae `conferenceRecords`. Lo que de
  verdad separa «falló» de «no se grabó» es que quede ESCRITO, así que ahora se
  escuchan los avisos del registro.

### Una trampa del entorno y otra de las pruebas

- `openssl_pkey_new` falla en el PHP de WAMP con «configuration file
  routines::no such file»: no encuentra `openssl.cnf`. Se le pasa la ruta del que
  sí existe. Misma familia que la trampa de los certificados raíz.
- **`Http::fake` ACUMULA stubs** y gana el primero que coincide. Un comodín `*`
  en un paso ensombrecía a los de todos los siguientes, y `Http::clearResolvedInstances()`
  no basta —la fábrica es un singleton del contenedor—: hay que reponerla con
  `app()->forgetInstance(Factory::class)`. Sin eso, un paso medía el stub del
  anterior y la prueba afirmaba lo contrario de lo que ocurría.


## 2026-08-20 — Auditoría: lo declarado que nadie usaba

Se buscó, con evidencia y no de memoria, qué está declarado y no se ocupa:
permisos, ajustes, tablas y foráneas con datos rotos.

### Cinco interruptores que no hacían nada

`aspirante.exige_documentos_para_convertir`, `aspirante.exige_pago_para_convertir`,
`docente.exige_cedula_para_asignar`, `docente.max_materias_por_ciclo` y
`alumno.matricula_unica_por_persona` estaban en la pantalla de configuración y
NADIE los leía. Los cuatro primeros ya se aplican; el quinto se retiró.

Esto es peor que una función faltante: una escuela podía encender «exigir
inscripción pagada para convertir en alumno», dar por cerrada la puerta, y
seguir generando matrículas de quien no había pagado. Un interruptor que no hace
lo que dice se confía.

### El que no se podía cumplir

`alumno.matricula_unica_por_persona` prometía que quien cursa dos programas
conserve el MISMO número de matrícula. `matricula_oferta.matricula` tiene índice
ÚNICO: dos filas no pueden compartirlo. Cumplirlo exigiría tirar ese único, y
entonces la matrícula dejaría de identificar una fila —contra la decisión de que
el alumno ES la matrícula—. Se retiró, con su fila de `configuraciones`.

### El permiso sin puerta

`crear-personas` no lo comprobaba ninguna ruta. Una persona nunca se crea sola:
nace dentro del alta de un aspirante, un alumno, un docente, un tutor o un
usuario, y cada una ya tiene su permiso. Se retiró del catálogo y de la base,
comprobando antes que ningún rol lo tuviera asignado — si alguna escuela se lo
hubiera dado, borrarlo le cambiaría un rol por la espalda.

### Dos declaraciones de la misma clave

`acta.formato_folio` y `acta.ambito_consecutivo` vivían en `CatalogoAjustes` y
OTRA VEZ dentro de `GeneradorFolioActa`, cada una con su valor por omisión. Hoy
coincidían, y ese es el problema: dos declaraciones que coinciden por casualidad
se separan el día que alguien cambia una, y entonces la pantalla diría que el
formato es uno y los folios saldrían con otro sin que nada falle.

### La trampa que casi deja la corrección sin efecto

`ProgresoSolicitud::para()` devuelve `['pasos' => …, 'porcentaje' => …]`, no la
lista de pasos. La primera versión indexaba el resumen entero por `clave`, no
encontraba ninguno, y los respaldos `?? true` lo convertían en «cumplido»: la
regla quedaba implementada de mentira, fallando ABIERTA y en silencio.

Y la primera versión de la prueba tampoco lo veía, porque aceptaba las dos
ramas —«o lo detiene o su expediente está completo»—, que pasa pase lo que pase.
Se cazó mutando: quitar la regla entera dejaba la suite en verde.

La corrección fue construir el caso en vez de buscar uno que sirviera: un
aspirante propio, recién creado, sin un solo documento y con un cargo sin pagar.
Con eso, la única razón posible de que pase es que la regla se aplique. Y el
acceso a los pasos ahora revienta si falta uno, porque para un bug de
programación eso es lo correcto — es lo que hace que una prueba lo vea.

### Datos rotos en el demo (no es código)

25 foráneas tienen filas apuntando a registros que ya no existen: `personas`,
`inscripcion`, `matricula_oferta`, `ciclos`, `campus`, `carreras`. MySQL sólo
comprueba las foráneas al ESCRIBIR, así que una resiembra con las comprobaciones
apagadas deja filas envenenadas que viven meses sin dar señales y sólo estorban
el día que alguien toca el esquema —como ya pasó al agregarle una columna a
`actividades`—. Es del demo, no del código: las foráneas están declaradas y la
aplicación escribe por Eloquent.

## 2026-08-21 — Qué cuenta como «capturado», y por qué había que decidirlo una vez

`EsquemaEvaluacion` usa borrado lógico. Eso hacía que la foránea de
`calificaciones_componente` no se disparara nunca, así que retirar un componente
con calificaciones devolvía éxito y las dejaba colgando de una fila invisible.

Lo que lo vuelve grave no es el dato suelto: es que **el esquema pasa a sumar
90 %**, la calificación final deja de poderse calcular, y si alguien agrega otro
componente para volver a llegar a 100, lo que el docente capturó desaparece del
cálculo con la pantalla viéndose normal. Un error ruidoso se arregla; éste no se
nota.

### La decisión: se niega, y se dice por dónde salir

No se borra en cascada. Una calificación capturada es trabajo de una persona y
un hecho fechado: retirarle el componente no puede ser el efecto de un clic en
otra pantalla. El aviso nombra la salida que de verdad existe —vaciar esa celda
en la hoja de captura, que es lo que la vuelve un blanco— en vez de decir
«bórrala primero» sin decir dónde.

Las actividades del LMS cuentan igual: una actividad declara a qué componente
pondera, y quitárselo la deja suelta. Ya hubo que escribir una migración para
reparar tres así.

### Un blanco no es una calificación

Guardar la hoja de captura escribe una fila por alumno, con `calificacion` en
NULL donde el docente no llegó — la regla de siempre, NULL no es cero. Si esas
contaran, **abrir la pantalla una vez congelaría el esquema de la materia para
siempre**, sin que nadie hubiera calificado a nadie y sin nada que lo explicara.

Por eso la pregunta «¿esto ya está capturado?» se responde en UN sitio,
`CalificacionComponente::capturadas()`. De ella cuelgan las dos decisiones que
congelan trabajo ajeno: si un componente se puede retirar y si una plantilla se
puede volver a aplicar. `AplicadorPlantillaEvaluacion` contaba FILAS, así que
llevaba bloqueando materias sin capturas reales.

### Lo que destapó relajarlo

El aplicador borra el esquema viejo con `forceDelete`. Sin llevarse antes los
rastros en blanco, la foránea revienta y la aplicación termina en 500. No se
veía porque esas mismas filas bloqueaban la re-aplicación: se estaba cambiando
un aviso claro por un error de base. Se limpian en la misma transacción.

### Lo que queda sin resolver, a propósito

Ese `forceDelete` dispara el `nullOnDelete` de `actividades`: re-aplicar una
plantilla deja sin componente, en silencio, a las actividades del curso.
Bloquear por eso volvería inservibles las plantillas en cualquier plan con
contenido de LMS. Qué hacer —bloquear, avisar al terminar o remapear por
nombre— es una decisión de producto y no un arreglo, así que se deja anotada en
vez de resolverse por la puerta de atrás.

## 2026-08-21 — El `back(303)` que no hacía falta

La bitácora decía que «un `back()` después de PUT/PATCH/DELETE debe ser
`back(303)`». Con esa regla, un vistazo al código encuentra 154 acciones
«rotas».

Se midió en vez de creerlo: una ruta desechable que sólo devuelve `back()`,
llamada con curl contra el demo. Con la cabecera `X-Inertia` responde **303**;
sin ella, 302. El middleware de `inertiajs/inertia-laravel` hace la conversión,
`HandleInertiaRequests` lo hereda sin tocar `handle()`, y está en el grupo `web`
que usan las rutas de tenant.

El mecanismo de fondo sí es real y por eso la nota no se borra: importa cuando
la petición NO lleva esa cabecera. Hoy no hay ninguna así —todas las llamadas de
`resources/js` fuera del router de Inertia son GET o POST, y en POST el 302 es
correcto—.

Es el mismo tipo de deuda que los cinco pendientes que resultaron estar hechos:
una regla escrita de más que manda a trabajar donde no hay nada roto.


## 2026-08-22 — El panel se llena de datos, no de atajos

«Accesos directos» eran doce recuadros con un icono y una etiqueta: exactamente
lo que ya ofrece el menú lateral. Se le había añadido una cifra a algunos
—«Aspirantes · 12 sin contactar»— justamente para que dijeran algo que el menú
no puede decir, y esa idea era la correcta; lo que estaba mal era el envase.

### Lo que se midió antes de decidir

De los seis roles base del demo, el `administrativo` y el `aspirante` veían UNA
tarjeta, y era ésa. Retirarla sin más les dejaba el panel en blanco. Y de los 74
permisos del catálogo, sólo 9 tenían alguna tarjeta anclada: el panel se veía
soso porque estaba vacío, no porque le faltara diseño.

Así que la decisión no fue «rediseñar los atajos» sino convertir cada atajo que
valía la pena en una tarjeta con su dato, y ancharlo a los oficios que no tenían
ninguna.

### La regla del vacío, aplicada tarjeta por tarjeta

Ya estaba escrita —una cola de trabajo vacía se oculta, una métrica propia en
cero se muestra— pero con trece tarjetas nuevas hubo que decidirla trece veces,
y en dos casos el resultado no es el obvio:

- **Mi solicitud** no se oculta con el avance en 0 %. Es la situación del propio
  aspirante y es lo único que le dice qué sigue; ocultarla lo devolvería al
  panel en blanco justo el día que se registra.
- **Mis tutorados** tampoco se oculta «cuando no hay nada pendiente», porque la
  señal más valiosa del tutor —«a éste no lo he visto en tres meses»— NUNCA
  genera un pendiente. Una tarjeta que desapareciera al no haber urgencias
  escondería precisamente lo que hay que mirar.

### Tres criterios que estaban escritos dos veces

Las tarjetas nuevas necesitaban preguntas que ya tenían respuesta en otro sitio,
y en vez de copiarlas se subieron a un solo lugar:

- `Grupo::scopeConAlumnos` — cuántos alumnos DISTINTOS tiene un grupo (matrículas
  y no renglones de inscripción; el alumno es la matrícula; las bajas no ocupan
  lugar). Vivía dentro de `GrupoController::index`. Copiada, el panel diría 3
  donde la pantalla de grupos dice 17.
- `EmisorFactura::pagosOcupados` — qué pago ya ampara una factura viva. Era
  privado, y es donde está la sutileza de que una cancelada libera sus pagos y
  una en error también.
- `Pago::titular()` — el titular dual. `ComprobantePago` lo tenía y `Pago` no,
  con la misma regla y las mismas columnas. La asimetría se cobró con un
  BadMethodCallException en cuanto se probó con datos sembrados.

### Lo que el frontend lee, que no es lo que el contrato decía

Dos hallazgos al construir, los dos comprobados leyendo el render:

- **`barras` dibuja la barra RELATIVA al mayor de la serie** y escribe `valor`
  crudo a la derecha; `porcentaje` sólo lo lee el bloque a medida de encuestas.
  Poniendo las cabezas en `valor`, un grupo 25/30 y otro 25/100 saldrían con la
  misma barra —lo contrario de «dónde ya no cabe nadie»—. Por eso la ocupación
  va en `valor` y los conteos en la etiqueta, como ya hacía «Continuar donde me
  quedé».
- **El formato de dinero es `moneda`**, no `dinero`: con el otro, la cifra sale
  sin formato y sin símbolo, y sin error.

### Dos pruebas que pasaban por la razón equivocada

`prueba-tarjetas-rol` encendía la clave fija «accesos» y comprobaba que lo
visible fuera un SUBCONJUNTO de ella. Eso también se cumple cuando no queda NADA
visible, así que la prueba seguía en verde con la tarjeta ya borrada — y seguía
en verde con `RegistroTarjetas` mutado para ignorar el apagado por rol. Ahora
busca a alguien que vea tarjetas de verdad, exige que quede exactamente la
encendida, y si nadie en la escuela ve una sola lo reporta en ROJO: un panel que
nadie puede llenar no es un caso a saltarse.

`prueba-panel` comprobaba que el docente viera «sus accesos directos» — tarjeta
que veía todo el mundo, porque no tenía permiso. No decía nada del docente y se
cayó sola al retirarla. Se sustituyó por la otra mitad de la regla: tiene el
permiso de sus materias y aun así no las ve, porque no imparte ninguna.

## 2026-08-22 — Re-aplicar una plantilla: bloquear y avisar

Quedaba anotado como riesgo conocido: el reemplazo del esquema usa `forceDelete`
y eso dispara el `nullOnDelete` de `actividades`, así que re-aplicar una
plantilla dejaba sin componente, en silencio, a las actividades del curso. Se
había dejado sin resolver a propósito porque bloquear volvería inservibles las
plantillas en cualquier plan con contenido de LMS, y eso era decisión de
producto.

**El cliente decidió: bloquea y avisa.** Con dos consecuencias de diseño:

1. **Las dos razones para bloquear se preguntan en UN sitio**
   (`motivoParaNoAplicar`), porque las dos terminan en la misma lista y
   separarlas es como se olvida una al agregar la siguiente.
2. **El aviso lleva el motivo de cada materia, no sólo su nombre.** Se bloquea
   por calificaciones capturadas o por actividades que ponderan en el esquema, y
   la salida de cada una es distinta —vaciar celdas en la hoja de captura, o
   mover las actividades a otro componente—. Una lista de nombres sin motivo no
   se puede accionar. `bloqueadas` pasó de `string[]` a `{materia, motivo}[]`.

Se cuentan las actividades de TODOS los cursos y no sólo las de la plantilla del
plan: `CopiadorDeCurso` copia la actividad al grupo apuntando al MISMO
`esquema_evaluacion_id` —el componente es del plan, no del grupo—, así que mirar
sólo el curso del plan dejaría pasar el reemplazo y desengancharía en silencio
las de todos los grupos abiertos. Lo fija una mutación de la prueba.

Esto no estorba la primera aplicación: una materia sin esquema no tiene nada
colgando. Sólo la re-aplicación sobre trabajo ya hecho, que es cuando hay algo
que perder.

## 2026-08-22 — Bolsa de trabajo: un solo lugar para el contacto de la empresa

La spec del módulo 11 pone un `persona_contacto_id` en `empresas` y ADEMÁS una
tabla `empresa_contactos` con «contactos adicionales». Son dos representaciones
de la misma cosa —con quién se habla en esa empresa— y dejan sin respuesta la
pregunta obvia: si el principal aparece también en la tabla, ¿cuál manda?

Se implementó con UNA sola tabla, `empresa_contactos`, con `es_principal` para
distinguir al de siempre. Es la misma decisión que se tomó en el módulo 13 al no
crear `vinculos_familiares` junto a `tutores_alumno`: dos representaciones de lo
mismo acaban divergiendo, y la que se olvide de actualizar es la que alguien va
a leer.

`persona_id` queda como columna OPCIONAL del contacto, para el reclutador que
además tenga cuenta en el sistema. Obligar a que todos sean `persona` llenaría
el padrón de la escuela con gente que ni estudia ni trabaja ahí, y la mayoría de
los contactos son un nombre y un teléfono.

### La empresa se veta, no se borra

`situaciones_empresa` incluye «vetada» y la pantalla NO tiene botón de eliminar.
Una empresa con la que la escuela no quiere volver a trabajar tiene que dejar de
publicar sin llevarse su historial: las colocaciones son el insumo de los
reportes de acreditación, y borrarla las borra.

Y `scopePublicables` se define excluyendo «vetada» en vez de exigiendo «activa».
Con la exigencia positiva, una escuela que renombrara su catálogo —o agregara
«en convenio»— dejaría de publicar sin entender por qué, y una empresa con la
situación en null desaparecería en silencio. Lo que hay que impedir es lo
vetado, y eso se dice por su nombre.

## 2026-08-23 — El clic en «Entrar» como pase de lista de la clase en línea

`videoconferencias` llevaba desde el 2026-08-19 repartiendo enlaces y nadie
anotaba quién los usaba. Había dos formas de arreglarlo:

1. **Preguntarle al proveedor** por su reporte de participantes. Da minutos de
   permanencia, que es el dato bueno. Cuesta dos APIs más —Zoom y Meet—, un
   Workspace con el que probar la de Google, y un comando que las consulte
   después de cada clase porque Meet no avisa.
2. **Anotar el clic en «Entrar»**. Cuesta un `redirect`.

Se eligió la segunda, por decisión del cliente: «con solo dar clic en el botón
de entrar desde el alumno a la clase se puede saber si entró o no, pues el clic
es una forma de saber que al menos se conectó». Es cierto y es barato, y quien
nunca pulsó el botón desde luego no entró.

**Lo que obliga es a decir qué mide.** La pantalla habla de «se conectaron» y no
de «asistieron», y el registro NO escribe en `asistencias`: se le enseña al
docente mientras pasa lista y él decide. Presentarlo como asistencia haría que
alguien firmara un acta con un dato que el sistema no tiene — no sabemos si se
quedó, si encendió la cámara ni si se durmió.

Si algún día se agrega el reporte del proveedor, cabe en esta misma tabla:
`minutos` sería una columna más, no otra tabla.

### Una fila por persona y clase, no una por clic

La pregunta que se le hace a la tabla es «¿entró?», no «¿cuántas veces le picó?».
Con una fila por clic, contar asistentes exigiría un `DISTINCT` que alguien
olvidará algún día, y una clase con red mala —donde la gente se reconecta seis
veces— saldría con seis veces más «asistencia» que otra.

Las reconexiones no se pierden: `veces` las cuenta y `ultimo_acceso` dice cuándo
fue la última, lo que además distingue a quien estuvo desde el principio de quien
apareció al final.

El `upsert` va con `ON DUPLICATE KEY` y no con «buscar y si no crear»: el doble
clic impaciente manda dos peticiones a la vez, y el par SELECT+INSERT las deja
pasar a las dos — la segunda revienta contra el índice único y le devuelve un
error de base a alguien que sólo quería entrar a clase.

### Y el enlace del proveedor deja de viajar

Efecto secundario que vale por sí solo. Antes el `url_join` llegaba al navegador
y el alumno picaba un `<a>` que se iba derecho a Zoom. Ahora el botón apunta a
`/clases/{clase}/entrar` y el enlace sale sólo del servidor, así que no se
reenvía por WhatsApp a quien no está inscrito.

El de anfitrión —el `start_url` de Zoom, que entra como dueño de la sala sin
pedir contraseña— tampoco viaja ya ni siquiera al docente: la puerta le reconoce
el papel y lo redirige. El docente entra por ahí a propósito, para que su propia
llegada quede anotada: «¿el docente llegó a su clase?» es una de las preguntas
que esta tabla existe para contestar.

## 2026-08-23 — El portafolio de evidencias cuelga de la entrega

La spec pedía `portafolio_evidencias` con `inscripcion_id` + `actividad_id`, que
es exactamente la pareja que `entregas` ya identifica. Hacerlo literal habría
creado DOS filas diciendo «el trabajo de esta alumna en esta actividad», y al
calificar habría que elegir a cuál creerle — el mismo defecto que se evitó en el
módulo 13 al no crear `vinculos_familiares` junto a `tutores_alumno`.

Colgando de `entregas` se hereda todo lo que ya funciona y no hay que
reescribirlo: la calificación, la retroalimentación, la rúbrica
(`entrega_rubrica`), el «entregada tarde», el panel de calificación del docente
y la vista del alumno en el aula. El portafolio aporta las PIEZAS; la entrega
sigue siendo el trabajo.

### En qué se diferencia de una tarea con adjuntos

Es la pregunta que decide si esta tabla tenía razón de existir. Una tarea se
entrega DE UNA VEZ: sus `entrega_archivos` son adjuntos sin nombre propio ni
fecha propia, y sirven todos a lo mismo. Un portafolio se ACUMULA a lo largo del
curso, y cada pieza tiene su título, su descripción y su momento.

**Esa descripción por pieza ES el portafolio**: sin ella sería una carpeta de
archivos, que es justo lo que ya existía y no hacía falta duplicar. Por eso una
evidencia se rechaza si no trae descripción ni archivo, y por eso
`fecha_evidencia` no es `created_at`: una práctica de octubre se captura en
diciembre al armar el portafolio, y ordenar por cuándo se subió contaría la
historia al revés.

### Nace en borrador y se cierra aparte

Agregar una pieza NO es entregar. Darlo por entregado al subir la primera
dejaría al docente calificando un trabajo a medias, así que hay dos gestos
separados y el contenedor se crea con `primeraOReviver`: con
`actualizarOReviver`, sumar una pieza a un portafolio ya entregado lo devolvería
a PENDIENTE y lo sacaría de la cola del docente sin que nadie lo pidiera.

Y calificado no se toca —ni agregando, ni editando, ni quitando—: cambiarlo
dejaría la calificación explicando un trabajo que ya no está. Misma regla del
acta asentada y de la rúbrica congelada. Quitar es borrado LÓGICO, al revés que
`entrega_archivos`: un adjunto retirado antes de entregar es corregirse, una
evidencia retirada después de calificar es historia escolar.

## 2026-08-23 — Por qué el tipo de actividad y el de reactivo NO son catálogo

La spec pide `tipos_actividad` y `tipos_reactivo` como catálogos TENANT-CONFIG y
están implementados como `varchar` respaldados por dos enums de PHP. Al auditar
el plan de migraciones esto quedó anotado como **deuda** contra la regla 4 del
proyecto («configurable, no cableado»). **Se comprobó y la deuda no existe: la
decisión es la correcta y lo que faltaba era escribir el porqué**, que es
exactamente lo que la regla 4 exige cuando algo enumerable se cablea.

**El motivo es que cada valor NO es un dato: es una rama de código.** Medido:
22 ramas por tipo de actividad y 74 por tipo de reactivo. No deciden cómo se
pinta una etiqueta, deciden qué ocurre:

- Una **lectura** no pondera y no lleva rúbrica —dejarle un componente amarrado
  prometería una calificación que nunca va a llegar—; se completa con un botón
  que declara el alumno.
- Un **examen** tampoco lleva rúbrica: lo califica la máquina al entregarse, y
  con las dos cosas habría dos notas para la misma entrega. Vive en su pantalla,
  con su reloj y sus intentos.
- Un **foro** tiene su propio controlador y su entrega son las participaciones.
- Un **portafolio** se acumula pieza a pieza y se cierra aparte.
- En los reactivos, `Ordenamiento` siempre baraja, `Clasificar` lleva categorías
  y `Completar` lleva huecos: la forma de la respuesta y su autocalificación
  salen del tipo.

**Volverlo catálogo sería una promesa falsa.** Una escuela agregaría la fila
«Podcast» y no pasaría nada: no hay rama que la atienda, así que tendría un tipo
en el desplegable que no se puede entregar, ni calificar, ni abrir. Es
literalmente el defecto que este proyecto ya corrigió dos veces —los cinco
interruptores de configuración que nadie leía, y `cierra_el_embudo` que sólo se
dibujaba— y que la propia bitácora resume: **un interruptor que no hace lo que
dice es peor que no tenerlo, porque se confía en él.**

La regla 4 no dice «todo enumerable es tabla»; dice que si se cablea hay que
poder explicar por qué. La prueba de si algo debe ser catálogo es si una fila
nueva HACE algo. En `modalidades_percepcion` sí —sus banderas encienden
componentes del cálculo, y «base más horas» se creó desde la pantalla y
funcionó—. Aquí no.

### `dificultades` y `metodos_resolver` tampoco son deuda

No existen ni como columna. No son una decisión pendiente: son una FUNCIÓN que
nadie ha pedido —armar el examen tomando N reactivos de cada nivel de
dificultad—. `examenes.reactivos_a_presentar` ya elige al azar del banco; el
día que alguien quiera «tres fáciles y dos difíciles», la columna llega con su
lector, como llegaron `clave_sat` y el régimen fiscal en la nómina.

Anotarlas como deuda invitaría a crear columnas sin quien las lea, que es lo que
este proyecto ya tuvo que retirar en dos ocasiones.
