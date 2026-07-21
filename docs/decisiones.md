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

### `personas`: FULLTEXT y `curp` único-nullable
- **Decisión:** índice FULLTEXT sobre (nombre, primer_apellido,
  segundo_apellido, curp); `curp` es UNIQUE y NULLable (MySQL permite múltiples
  NULL en índice único). `sexo_id` es NOT NULL (per spec); los demás refs
  landlord son nullable.
- **Razón:** búsqueda de personas como en el legacy IMEP; la CURP es llave
  natural cuando existe pero muchas personas se dan de alta sin ella todavía.
