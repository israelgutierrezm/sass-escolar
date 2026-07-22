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
    kárdex de la original se dan de baja lógica y se asientan los nuevos. Ambas
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
  firmar una segunda acta ordinaria sobre la misma materia-grupo y el kárdex
  quedaba con el alumno DUPLICADO en la misma materia, sin ningún aviso. La
  captura ya estaba protegida; el cierre no. Caso agregado a la suite de
  regresión (`scripts/prueba-actas.php`, sección 5b).

### Otras reglas del motor de cálculo
- Si los porcentajes del `esquema_evaluacion` no suman 100, NO se calcula nada
  y se reporta el motivo. Vale más una materia sin calificación que un kárdex
  con números que nadie puede reproducir.
- Aprobado lo define `planes_estudio.calificacion_minima_aprobatoria`, no una
  constante: cada plan tiene su escala.
- Un recursamiento se asienta en el kárdex con `tipos_evaluacion` =
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
  cada una tiene su matrícula, su kárdex y su situación. Quien busca en control
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
- **Decisión:** `resumen.promedio` solo promedia los renglones del kárdex con
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
  de alta antes; duplicar la persona rompe el kárdex, los roles y el expediente
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
- **Razón:** firmó actas y su nombre aparece en el kárdex de sus alumnos.
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
- **Razón:** su kárdex es historia escolar y las actas donde aparece quedarían
  sin dueño. Verificado en la suite: se asienta una materia, se da de baja, y el
  kárdex sigue ahí.
- La opción de "eliminar la cargada por error" se descartó por ahora; si se
  retoma, tendría que restringirse a matrículas sin kárdex ni pagos.

### Corregir la identidad alcanza a todas las matrículas
- Ya era así, pero ahora se ve: la pestaña "Carreras" lista todas con su
  estatus, situación, generación y cuántas materias llevan en kárdex, y la
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
