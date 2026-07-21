# Especificación de esquema — Sistema escolar SaaS multi-tenant

> Documento de handoff para Claude Code. Define el modelo de datos canónico, capa
> por capa y módulo por módulo. Cada tabla indica columnas, tipos, llaves y la
> **capa** a la que pertenece. Claude Code debe implementar las migraciones Laravel
> siguiendo esta spec sin re-diseñar el dominio.

## Convenciones globales

- **Motor de BD**: MySQL 8 (compatible MariaDB 10.6+). El tipo `JSON` nativo de MySQL 8
  cubre todos los casos jsonb de esta spec (`JSON_EXTRACT`, `->>`, índices sobre columnas
  generadas). Donde la spec dice "jsonb" léase `JSON` de MySQL. **No** se requiere PostgreSQL.
- **REGLA RELACIONAL PURA (decisión del cliente):** esta base es relacional, sin JSON salvo
  UN caso justificado (la bitácora de auditoría, que audita cualquier tabla y por naturaleza
  no puede tener columnas fijas). Todo lo demás —formularios dinámicos, reactivos del LMS,
  temas, configuraciones, alcances, ponderaciones— se modela con tablas hijas: **cada dato es
  una fila que se consulta, filtra y modifica con `UPDATE ... WHERE` directo.** El legacy CUGS
  ya demostró que los formularios dinámicos se pueden hacer 100% relacionales (una fila por
  campo, una fila por respuesta); esta spec sigue ese patrón en todos los módulos. Si en el
  futuro se necesita un dato libre, se crea una tabla `clave/valor`, no un blob JSON.
- **Nunca en JSON:** matrícula, situación, calificación, montos, fechas clave, estatus,
  respuestas de formulario, opciones de reactivo, colores de tema, configuración de módulo.
  Todo eso son columnas o filas.
- **Multi-tenancy**: paquete `stancl/tenancy` (soporta MySQL). **Una base de datos por
  tenant** (escuela). Además una BD **landlord** central.
- **Organización modular**: MySQL no tiene schemas como Postgres; los módulos se organizan
  por convención de prefijo/nombre de tabla (no afecta FKs ni queries).
- **Capas** (cada tabla pertenece a una):
  - `LANDLORD` — vive en la BD central. Compartida por todas las escuelas.
  - `TENANT` — vive en la BD de cada escuela. Datos operativos.
  - `TENANT-CONFIG` — vive en el tenant pero es catálogo configurable por la escuela
    (se siembra con valores por defecto, la escuela puede editar).
- **Columnas de auditoría estándar** (todas las tablas `TENANT` las llevan, no se repiten
  en cada definición abajo):
  `created_at datetime`, `updated_at datetime`, `deleted_at datetime NULL`
  (soft delete), `created_by bigint NULL`, `updated_by bigint NULL`.
- **PK**: `id bigint AUTO_INCREMENT` salvo que se indique PK compuesta.
- **Tipos temporales**: `datetime` para timestamps de aplicación; donde la spec diga
  `timestamptz` léase `datetime` (guardar en UTC por convención de la app).
- **Nombres**: tablas en `snake_case` plural en español; pivotes `a_b`.
- **Estatus**: se prefiere columna `estatus`/`situacion_id` como FK a catálogo, no enteros
  mágicos. El `estatus` genérico "activo/inactivo" del legacy se reemplaza por `deleted_at`
  (soft delete) + catálogos de situación específicos donde aplica.

---

# FASE 0 — Fundación multi-tenant

## 0.1 Capa LANDLORD

### `tenants` (LANDLORD)
La escuela como cliente del SaaS. Reemplaza el esquema legacy de "rama de git por escuela".

| Columna | Tipo | Notas |
|---|---|---|
| id | string PK | UUID o slug (`stancl/tenancy` usa string) |
| nombre | varchar(255) | Razón social / nombre comercial |
| clave | varchar(50) UK | Identificador corto |
| db_name | varchar(100) | Nombre de la BD del tenant |
| plan_saas | varchar(50) | Plan contratado (billing del SaaS) |
| estatus | varchar(30) | activo / suspendido / prueba |
| — | — | Config libre del tenant en tabla `configuraciones` (clave/valor), no en blob |

### `domains` (LANDLORD)
Dominios/subdominios por tenant (lo requiere `stancl/tenancy`).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| domain | varchar(255) UK | `escuela.tudominio.com` o dominio propio |
| tenant_id | string FK → tenants | |

### `super_admins` (LANDLORD)
Usuarios de la casa (tú y tu equipo) que administran todos los tenants.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | varchar(255) | |
| email | varchar(255) UK | |
| password | varchar(255) | |
| rol | varchar(50) | superadmin / soporte / comercial |

### Catálogos universales (LANDLORD)
Se comparten entre todas las escuelas — NO se duplican por tenant. Read-only para
los tenants.

- `paises` — id, clave_iso, nombre.
- `entidades_federativas` — id, pais_id FK, clave, nombre. (Las 32 de México + soporte extranjero.)
- `sexos` — id, clave, nombre. (Catálogo biológico/oficial para documentos SEP.)
- `generos` — id, clave, nombre. (Identidad de género, separado de sexo.)
- `niveles_estudio` — id, clave, nombre, orden. (Bachillerato, Licenciatura, Especialidad,
  Maestría, Doctorado, Diplomado, etc.) **Universal porque la SEP los estandariza.**

> **Regla de clasificación**: un catálogo es LANDLORD solo si su contenido es
> estandarizado nacionalmente y jamás cambia entre escuelas. Todo lo demás es
> TENANT-CONFIG (ver más abajo), porque cada escuela nombra y usa sus estatus a su manera.

## 0.2 Feature flags y configuración por tenant

### `modulos` (TENANT-CONFIG)
Catálogo de módulos encendibles. Se siembra igual en todos los tenants.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| clave | varchar(50) UK | `finanzas`, `lms`, `titulacion`, `nomina`, `bolsa_trabajo`... |
| nombre | varchar(120) | |

### `modulos_activos` (TENANT)
Qué módulos tiene encendidos ESTA escuela.

| Columna | Tipo | Notas |
|---|---|---|
| modulo_id | bigint FK → modulos | PK compuesta (modulo_id) |
| activo | boolean | |
| — | — | Config del módulo en `modulo_config` (modulo_id, clave, valor varchar) — relacional |

### `configuraciones` (TENANT)
Clave/valor para todo lo "encendible/apagable" que no es un módulo completo.
Ejemplo: modo de pago (semanal/mensual/único), si la inscripción autogestiva está
habilitada, branding (logo, colores), etc.

| Columna | Tipo | Notas |
|---|---|---|
| clave | varchar(100) | PK |
| valor | varchar(500) | Valor escalar (texto/número/bool/fecha como string). Un valor = una fila |
| tipo_dato | varchar(20) | string/int/bool/date — para castear en la app |
| descripcion | varchar(255) | |

### `auditoria` (TENANT)
Bitácora transversal. Obligatoria desde el día uno (quién cambió una calificación,
quién condonó un adeudo). Puede implementarse con `owen-it/laravel-auditing`.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| auditable_type | varchar(255) | Modelo afectado |
| auditable_id | bigint | |
| evento | varchar(50) | created/updated/deleted |
| valores_anteriores | JSON | Único uso justificado de JSON: una bitácora genérica audita cualquier tabla, no puede tener columnas fijas |
| valores_nuevos | JSON | Idem |
| usuario_id | bigint NULL | Quién |
| ip | varchar(45) | |
| created_at | timestamptz | |

---

# FASE 1 — Núcleo

## Módulo 1 — Identidad

Principio rector: **persona ≠ rol**. Una persona puede ser aspirante, alumno,
egresado, docente y administrativo **a la vez**, y cada rol se activa/desactiva sin
tocar los datos personales. Confirmado por el legacy IMEP (`inter_persona_usuario_rol`).

### `personas` (TENANT)
Identidad única. Toda persona del sistema vive aquí una sola vez.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| curp | varchar(18) UK NULL | Llave natural. Única cuando presente. Clave para SEP e IDP. |
| rfc | varchar(13) NULL | Para facturación / nómina |
| nombre | varchar(255) | |
| primer_apellido | varchar(255) | |
| segundo_apellido | varchar(255) NULL | |
| fecha_nacimiento | date NULL | |
| sexo_id | bigint FK → sexos (landlord) | |
| genero_id | bigint FK → generos (landlord) NULL | |
| pais_nacimiento_id | bigint FK → paises NULL | |
| entidad_nacimiento_id | bigint FK → entidades_federativas NULL | Requerido para título SEP |
| email | varchar(150) NULL | Personal |
| correo_institucional | varchar(150) NULL | |
| celular | varchar(20) NULL | |
| — | — | (Datos extra libres viven en `respuestas_campo` del Módulo 3, relacional; no hay blob JSON en personas) |

Índice FULLTEXT sobre (nombre, primer_apellido, segundo_apellido, curp) — como en IMEP.

### `usuarios` (TENANT)
Credenciales de acceso. NO toda persona tiene usuario (un padre puede no loguearse aún).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| persona_id | bigint FK → personas | Una persona → a lo más un usuario |
| usuario | varchar(150) UK | |
| email | varchar(150) | Login |
| password | varchar(255) | |
| url_perfil | varchar(255) NULL | |
| conectado | boolean | |
| rol_activo_id | bigint FK → roles NULL | Rol con el que interactúa AHORA (cambiable en tiempo real) |
| tema_id | bigint FK → temas NULL | Tema elegido por el usuario |
| — | — | Redes sociales en tabla hija `persona_redes` (persona_id, red, url) — relacional |

### `temas` (TENANT-CONFIG)
Catálogo de temas visuales que la escuela pone a disposición de sus usuarios. Da el
"sentido de pertenencia": la escuela fija su identidad, y decide cuánto puede el usuario
personalizar encima. El alumno puede elegir entre 2+ temas (Claro, Oscuro, Alto contraste,
tema institucional...).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| clave | varchar(50) UK | `claro`, `oscuro`, `alto_contraste`... |
| nombre | varchar(120) | |
| es_default | boolean | El tema base del tenant |
| permite_override_usuario | boolean | Si el usuario puede ajustar colores encima |

Los colores del tema NO van en JSON: viven en `tema_tokens` (relacional).

### `tema_tokens` (TENANT-CONFIG)  ← un color/token por FILA
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tema_id | bigint FK → temas | |
| token | varchar(60) | `color_primario`, `color_fondo`, `barra_superior`... |
| valor | varchar(40) | Hex u otro valor CSS |

### `usuario_tema_override` (TENANT)  ← ajustes del usuario sobre su tema (relacional)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| usuario_id | bigint FK → usuarios | |
| token | varchar(60) | Qué token sobreescribe |
| valor | varchar(40) | Su valor personal |

**Formato de `tokens`** (se inyectan como CSS custom properties en `:root` en el front Vue):
```json
{
  "barra_superior": "#33417A",
  "barra_lateral": "#33417A",
  "acento": "#7A1737",
  "texto_logo": "claro",
  "densidad": "normal"
}
```

**Formato de `usuarios.preferencias_ui`** (preferencia individual, sobreescribe el tema
donde `permite_override_usuario = true`):
```json
{ "tema_id": 3, "overrides": { "acento": "#0F6E56", "densidad": "compacta" } }
```

> **Cascada de temas** (resolución en el front): tema del sistema (defaults) → tema de la
> escuela (`temas.es_default`, branding del tenant) → tema elegido por el usuario
> (`preferencias_ui.tema_id`) → overrides del usuario (solo si el tema lo permite).
> Cambiar de tema = reescribir variables CSS, sin recarga. El branding a nivel escuela
> (barra superior/lateral, color de texto y logo como en el admin template) lo fija el
> súper admin del tenant y vive tanto aquí como en `configuraciones` (Fase 0).

### `roles` (TENANT-CONFIG)
Catálogo de roles. Semilla base: alumno, docente, administrativo, aspirante, tutor,
asesor, directivo. La escuela puede agregar.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| clave | varchar(50) UK | |
| nombre | varchar(120) | |
| tiempo_sesion | int NULL | Minutos de expiración (del legacy) |

### `persona_rol` (TENANT)
Activación de roles por persona. **Multi-rol simultáneo.**

| Columna | Tipo | Notas |
|---|---|---|
| persona_id | bigint FK → personas | PK compuesta |
| rol_id | bigint FK → roles | PK compuesta |
| activo | boolean | Permite desactivar un rol sin borrar historial |

**Cambio de rol en tiempo real (role switcher).** Cuando una persona tiene ≥2 roles
activos en `persona_rol`, la UI ofrece un botón "Cambiar rol" que abre un modal lateral
(panel derecho) con los roles disponibles. Al elegir uno se actualiza
`usuarios.rol_activo_id` sin cerrar sesión. Implicaciones de backend que Claude Code
debe respetar:
- El `rol_activo_id` gobierna, por request: qué permisos aplican, qué menú/rutas se
  muestran, y qué tema/preferencias cargan. NO es solo estado de front.
- El backend valida SIEMPRE que el `rol_activo_id` esté entre los roles activos de esa
  persona en `persona_rol` (defensa contra manipulación del cliente).
- Un mismo usuario como Superadministrador ve todo; al cambiar a Alumno, el sistema lo
  trata como alumno (ve solo sus ofertas, kárdex, pagos). El cambio es de contexto de
  sesión, no de identidad.
- Middleware sugerido: un `SetActiveRole` que resuelve permisos con `spatie/laravel-permission`
  acotados al `rol_activo_id`.

> Nota: los roles `alumno` y `docente` se "materializan" además en tablas propias
> (ver módulos 4 y 5) porque llevan atributos específicos (matrícula, cédula, etc.).
> `persona_rol` es la verdad sobre "qué es" una persona; las tablas de rol llevan el detalle.

### `permisos` (TENANT-CONFIG) y `rol_permiso` (TENANT)
Sistema de permisos. Recomendación: usar `spatie/laravel-permission` en vez de reinventar
las tablas `cat_privilegios_*` del legacy (que estaban fragmentadas por rol). Si se usa
Spatie, estas dos tablas las genera el paquete; documentar los permisos como seeder.

---

## Módulo 2 — Estructura académica (con datos de titulación)

Este módulo unifica los dos enfoques legacy: el `inter_orden_asignatura` de IMEP
(materia dentro de un orden jerárquico del plan) y los campos ricos de titulación de
academyx. La **asignatura es catálogo**; su vida dentro de un plan (clave, seriación,
ponderación) es una entidad aparte.

### `campus` (TENANT)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| clave | varchar(50) | |
| nombre | varchar(255) | |
| tipo_campus_id | bigint FK → tipos_campus (config) | |
| online | boolean | Un campus puede ser 100% online |
| entidad_id | bigint FK → entidades_federativas NULL | Ubicación |

### `carreras` (TENANT)
Incluye campos SEP que academyx aporta y IMEP no tenía.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| identificador | varchar(50) | ID estable entre migraciones (de academyx) |
| clave | varchar(50) | |
| nombre | varchar(255) | |
| nivel_estudios_id | bigint FK → niveles_estudio (landlord) | |
| clave_sat | varchar(15) NULL | Para CFDI de colegiaturas |
| objetivo | text NULL | |
| imagen_url | varchar(255) NULL | |

### `planes_estudio` (TENANT)
Un plan pertenece a una carrera; una carrera tiene 1..N planes. Campos de titulación
tomados de academyx `tr_plan_estudios`.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| carrera_id | bigint FK → carreras | |
| clave | varchar(50) | |
| abreviacion | varchar(50) NULL | Requerido en título (de academyx) |
| nombre | varchar(255) | |
| rvoe | varchar(100) | Reconocimiento de validez oficial |
| fecha_rvoe | date NULL | |
| autorizacion_reconocimiento_id | bigint FK → autorizaciones_reconocimiento (config) | Tipo de RVOE/autorización — clave para SEP |
| tipo_periodo_id | bigint FK → tipos_periodo (config) | Semestral/cuatrimestral/anual |
| total_periodos | int NULL | |
| calificacion_minima | int | p.ej. 0 |
| calificacion_maxima | int | p.ej. 10 |
| calificacion_minima_aprobatoria | int | p.ej. 6 |
| minimo_creditos | float | Para titularse |
| minimo_asignaturas | int NULL | |
| total_creditos | float | |
| curp_responsable | varchar(18) NULL | Responsable del plan ante SEP |
| clave_matricula | varchar(100) NULL | Regla de generación de matrícula (del legacy) |
| clave_matricula_consecutivo | varchar(100) NULL | |
| vigente | boolean | Un plan viejo y uno nuevo coexisten |

### `asignaturas` (TENANT)
Catálogo puro de materias. La misma asignatura se reutiliza entre planes.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| identificador | varchar(50) | ID estable |
| clave | varchar(50) | Clave "de catálogo" |
| nombre | varchar(255) | |
| creditos | float | |
| tipo_asignatura_id | bigint FK → tipos_asignatura (config) | Obligatoria/optativa base |
| clasificacion_id | bigint FK → clasificaciones_asignatura (config) NULL | De academyx |
| area_id | bigint FK → areas (config) NULL | |
| horas_teoria | int NULL | |
| horas_practica | int NULL | |
| horas_acompanamiento | int NULL | Del legacy IMEP |
| horas_independientes | int NULL | |
| objetivos_desc | text NULL | Descriptores para LMS/programa |
| bibliografia_desc | text NULL | |

### `plan_materias` (TENANT)  ← núcleo del modelo curricular
La asignatura DENTRO de un plan. Reemplaza `inter_orden_asignatura` de IMEP.
**Resuelve el tronco común**: la misma `asignatura_id` puede aparecer en planes
distintos con `clave_en_plan` distinta (asignatura independiente por clave, pero
compartible en grupo).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| plan_id | bigint FK → planes_estudio | |
| asignatura_id | bigint FK → asignaturas | |
| clave_en_plan | varchar(50) | La clave que sale en el acta de ESTE plan |
| periodo | int NULL | Semestre/cuatrimestre sugerido |
| tipo | varchar(30) | obligatoria / optativa / tronco_comun |
| creditos_en_plan | float NULL | Override de créditos si difiere del catálogo |

Índice único (plan_id, clave_en_plan).

### `esquema_evaluacion` (TENANT)  ← cómo se compone la calificación (relacional, no JSON)
Reemplaza el `ponderacion_config` jsonb. Una fila por componente de calificación de una
materia-en-plan (parcial 1, parcial 2, examen final, LMS...). Los porcentajes deben sumar 100.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| plan_materia_id | bigint FK → plan_materias | |
| componente | varchar(60) | parcial_1 / parcial_2 / parcial_3 / final / lms / practicas |
| parcial | smallint NULL | Si aplica a un corte |
| porcentaje | decimal(5,2) | % que aporta al final (validar Σ=100) |
| orden | smallint | |

### `seriacion` (TENANT)  ← el DAG de prerequisitos
Relación reflexiva sobre `plan_materias`. Many-to-many: una materia puede requerir
varias. Tipada (cursada vs aprobada). Corrige el `asignatura_padre_id` de IMEP que
solo soportaba un prerequisito y apuntaba a la asignatura, no al plan-materia.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| plan_materia_id | bigint FK → plan_materias | La materia que tiene requisito |
| requiere_plan_materia_id | bigint FK → plan_materias NULL | La que debe llevarse antes |
| tipo | varchar(20) | cursada / aprobada |
| minimo_creditos | float NULL | Alternativa: "requiere X créditos" en vez de materia puntual |

Índice único (plan_materia_id, requiere_plan_materia_id).

### `oferta` (TENANT)  ← qué se imparte dónde
Combinación carrera+plan+campus que la escuela ofrece. Reemplaza `inter_carrera_campus`
extendiéndola con el plan y la modalidad. Un campus imparte algunas/todas las carreras.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| carrera_id | bigint FK → carreras | |
| plan_id | bigint FK → planes_estudio | |
| campus_id | bigint FK → campus | |
| modalidad | varchar(30) | presencial / online / mixta |
| turno_id | bigint FK → turnos (config) NULL | |
| estatus | varchar(30) | abierta / cerrada |

Índice único (carrera_id, plan_id, campus_id, turno_id).

### Catálogos TENANT-CONFIG de este módulo
Se siembran con defaults, la escuela edita:
- `tipos_campus` — matriz, extensión, online.
- `tipos_periodo` — semestral, cuatrimestral, trimestral, anual, modular.
- `tipos_plan_estudio` — escolarizado, no escolarizado, mixto.
- `tipos_asignatura` — obligatoria, optativa, seminario, taller.
- `clasificaciones_asignatura` — teórica, práctica, teórico-práctica (de academyx).
- `areas` — áreas del conocimiento / academias.
- `autorizaciones_reconocimiento` — tipo de RVOE/incorporación (de academyx, clave SEP).
- `turnos` — matutino, vespertino, mixto, sabatino.

---

## Módulo 3 — Formularios dinámicos (RELACIONAL, sin JSON)

Motor que permite a un administrativo definir qué datos/documentos se piden por
carrera/rol, versionado. El caso rector: el formulario "antecedente académico" se
activa en todas las licenciaturas/maestrías/doctorados; un alumno lo llena al entrar
a la licenciatura, y **lo vuelve a llenar al entrar a la maestría**, quedando ligado a
esa oferta específica. Por eso las respuestas cuelgan de `matricula_oferta` (módulo 4),
no de la persona.

**Diseño 100% relacional** (tomado del legacy CUGS `tr_formulario`/`tr_campo_formulario`/
`inter_campo_aspirante`, que ya lo resuelve sin JSON). Cada campo es una fila, cada
respuesta es una fila. Modificar una respuesta = `UPDATE respuestas_campo SET valor=? WHERE
matricula_oferta_id=? AND campo_id=?`. Consultar/filtrar por un campo = un JOIN normal.

### `formularios` (TENANT)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| clave | varchar(50) | `antecedente_academico`, `datos_generales`... |
| titulo | varchar(150) | |
| instruccion | varchar(255) NULL | |
| icono | varchar(50) NULL | |
| orden | smallint | Orden de presentación (del legacy) |
| porcentaje | smallint NULL | % de avance que representa (del legacy `tr_formulario.porcentaje`) |
| obligatorio | boolean | |
| version | int | Versionado: al cambiar la estructura sube versión |

Índice único (clave, version). El versionado permite que respuestas viejas apunten a la
estructura con que se respondieron sin romperse.

### `campos_formulario` (TENANT)  ← cada pregunta es una FILA (no JSON)
Reemplaza el `schema` jsonb. Del legacy `tr_campo_formulario`: incluye validación por
`regex` + `mensaje_error` y campos condicionales (un campo se muestra según la respuesta
de otro).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| formulario_id | bigint FK → formularios | |
| tipo_campo_id | bigint FK → tipos_campo (config) | texto/numero/select/documento... |
| pregunta | varchar(255) | El label |
| descripcion | varchar(255) NULL | Ayuda |
| obligatorio | boolean | |
| regex | varchar(255) NULL | Validación (del legacy) |
| mensaje_error | varchar(150) NULL | Del legacy |
| orden | smallint | |
| campo_padre_id | bigint FK → campos_formulario NULL | Campo condicional: depende de otro |
| condicional | varchar(100) NULL | Valor del padre que dispara este campo |
| min | decimal(10,2) NULL | Para numéricos |
| max | decimal(10,2) NULL | |
| promueve_a | varchar(60) NULL | Si mapea a una columna real (p.ej. `personas.celular`) — ver nota |

### `opciones_campo` (TENANT)  ← opciones de select/radio, cada una una FILA
Reemplaza el array de opciones del JSON. Del legacy `tr_reactivos_campo`.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| campo_formulario_id | bigint FK → campos_formulario | |
| valor | varchar(100) | Valor guardado |
| etiqueta | varchar(255) | Texto mostrado |
| orden | smallint | |

### `formulario_asignacion` (TENANT)
A qué aplica un formulario. El `aplica_a` polimórfico permite activarlo por nivel,
carrera, oferta o rol de un golpe. (En el legacy es `inter_formulario_carrera`; aquí se
generaliza a nivel/oferta/rol.)

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| formulario_id | bigint FK → formularios | |
| aplica_a_tipo | varchar(30) | nivel / carrera / oferta / rol |
| aplica_a_id | bigint | ID del nivel/carrera/oferta/rol |
| obligatorio | boolean | Override |

Ejemplo: "antecedente en todas las licenciaturas" = una fila `aplica_a_tipo='nivel'`,
`aplica_a_id=<licenciatura>`.

### `respuestas_campo` (TENANT)  ← una respuesta = una FILA (no JSON)
Reemplaza el `respuestas` jsonb. Del legacy `inter_campo_aspirante`. Esta es la tabla que
permite el `UPDATE` directo que el cliente necesita para modificar datos de un alumno rápido.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| campo_formulario_id | bigint FK → campos_formulario | Qué se respondió |
| formulario_version | int | Versión de la estructura al responder |
| persona_id | bigint FK → personas | Quién respondió |
| matricula_oferta_id | bigint FK → matricula_oferta NULL | A qué oferta pertenece (caso maestría) |
| aspirante_id | bigint FK → aspirantes NULL | Si se respondió siendo aspirante (pre-alumno) |
| valor | varchar(500) | El valor respondido (texto/número/fecha/valor de opción) |
| documento_ruta | varchar(500) NULL | Si el campo es tipo documento (S3/Laserfiche) |

Índice (matricula_oferta_id, campo_formulario_id) y (aspirante_id, campo_formulario_id).

> **Promoción a columna real.** Cuando un dato se consulta/edita con frecuencia (celular,
> promedio, CURP), NO se deja solo como respuesta genérica: el campo lleva `promueve_a` y la
> app escribe ADEMÁS en la columna real correspondiente (`personas.celular`,
> `antecedentes_academicos.promedio`). Así los datos calientes viven en columnas tipadas y
> las consultas/updates son directos. `respuestas_campo` queda para los campos realmente
> libres que cada escuela inventa.

### Catálogos TENANT-CONFIG de este módulo
- `tipos_campo` — texto, textarea, número, fecha, select, multiselect, radio, checkbox,
  documento, email, teléfono. (Del legacy `cat_tipo_campo`.) Determina cómo se renderiza y
  valida el campo. Compartido conceptualmente con los reactivos del LMS.
- `formulario_obligatoriedad` — obligatorio, opcional, condicional.
- `formulario_visibilidad` — alumno, admin, ambos.
- `tipos_antecedente_academico` — bachillerato, licenciatura, etc. (de academyx_cyt).

---

## Módulo 4 — Matrícula y admisiones (CRM aspirante → alumno)

La pieza que reconcilia todo: **la unidad matriculable no es "el alumno", es la
inscripción a una oferta**. Una persona con doctorado + diplomado online = dos filas
en `matricula_oferta`. Esto resuelve multi-campus y multi-oferta simultáneos.

El CRM está tomado del legacy CUGS (`tr_aspirante` y su ecosistema), que es un embudo de
admisión completo: proceso por pasos, asesores/tutores asignados, promociones con
descuento, expediente documental y test psicométrico Cleaver (perfil DISC).

### `aspirantes` (TENANT)  ← el CRM, proceso por pasos
Antes de ser alumno. Del legacy `tr_aspirante`: lleva banderas de avance del proceso, para
que el admin vea en qué paso va cada prospecto.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| persona_id | bigint FK → personas | Misma persona; cero recaptura al convertirse |
| oferta_interes_id | bigint FK → oferta NULL | A qué quiere entrar (carrera+plan+campus) |
| campus_id | bigint FK → campus NULL | |
| clave_aspirante | varchar(50) NULL | |
| situacion_id | bigint FK → situaciones_aspirante (config) | prospecto/en proceso/aceptado/rechazado |
| paso | smallint | En qué paso del alta va (del legacy) |
| acepto_terminos | boolean | |
| info_personal_completa | boolean | Bandera de avance |
| cleaver_completo | boolean | Test psicométrico terminado |
| validado_admin | boolean | Un admin revisó y validó el expediente |
| origen | varchar(80) NULL | Campaña, referido, web... |
| ciclo_ingreso_id | bigint FK → ciclos NULL | |

**Conversión aspirante → alumno:** al aceptarse, se crea `matricula_oferta` con la misma
`persona_id`, se activa el rol `alumno`, y las `respuestas_campo` del aspirante se re-ligan
o copian a la `matricula_oferta`. Cero recaptura.

### `asesores` y `tutores_crm` (TENANT)  ← rol materializado del CRM
Del legacy `tr_asesor`/`tr_tutor`. El asesor da seguimiento comercial al aspirante; el tutor
(de admisión) acompaña. Ambos son personas del sistema.

`asesores`: persona_id FK (PK), clave_asesor, situacion_id FK → situaciones_asesor (config).
`tutores_crm`: persona_id FK (PK), clave_tutor, situacion_id FK → situaciones_tutor (config).

Asignación al aspirante (del legacy `inter_asesor_persona`/`inter_tutor_persona`):
`aspirante_asesor`: aspirante_id FK, persona_id FK (el asesor), PK compuesta.
`aspirante_tutor_crm`: aspirante_id FK, persona_id FK (el tutor), PK compuesta.
Un asesor/tutor se liga además a 1..N campus (`campus_asesor`, `campus_tutor`).

### `promociones` (TENANT)  ← del legacy cat_promocion
Descuentos de admisión asignables a aspirantes.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| clave | varchar(50) | |
| nombre | varchar(255) | |
| descripcion | varchar(255) NULL | |
| descuento | int | Porcentaje o monto |
| vigencia | date | |

`aspirante_promocion`: aspirante_id FK, promocion_id FK, PK compuesta (del legacy
`inter_promocion_persona`).

### `documentos_requeridos` (TENANT)  ← catálogo de qué se pide
Del legacy `cat_documento` + `inter_documento_carrera`. Qué documentos exige cada carrera.

`documentos_requeridos`: id, nombre, descripcion, obligatorio bool.
`documento_carrera`: documento_id FK, carrera_id FK — qué documentos pide cada carrera.
`etiquetas_documento` + `documento_etiqueta`: clasificación de documentos (del legacy
`tr_etiquetas`).

### `expediente_documentos` (TENANT)  ← el expediente del aspirante
Del legacy `inter_expediente`. Un documento entregado por el aspirante, con su estado de
revisión. (Se enlaza con Laserfiche vía la ruta.)

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| aspirante_id | bigint FK → aspirantes | |
| documento_id | bigint FK → documentos_requeridos | |
| carrera_id | bigint FK → carreras NULL | |
| descripcion | varchar(100) NULL | |
| url | varchar(500) | S3 o Laserfiche |
| estado_documento_id | bigint FK → estados_documento (config) | pendiente/aceptado/rechazado |
| copia_certificada | boolean | Del legacy |
| documento_fisico | boolean | Si se entregó físico |

### Test psicométrico Cleaver (perfil DISC) — del legacy
El CRM aplica el test Cleaver a los aspirantes. Es relacional puro.

`reactivos_cleaver` (TENANT-CONFIG): id, nombre_reactivo, y las 4 dimensiones DISC como
columnas booleanas — `c`, `d`, `i`, `s` (Cumplimiento, Dominio, Influencia, Estabilidad),
tal cual el legacy `cat_reactivos_cleaver_base`.
`cleaver_aspirante` (TENANT): id, aspirante_id FK, reactivo_cleaver_id FK, respuesta_id
smallint (más/menos). El perfil DISC del aspirante se calcula agregando estas respuestas.

### Encuestas (del legacy) — relacional, reutiliza el motor de formularios
El CRM tiene encuestas configurables (`tr_encuesta`/`tr_campo_encuesta`). Se modelan con el
MISMO motor relacional del Módulo 3 (formularios/campos/opciones/respuestas), marcando el
formulario con un tipo `encuesta`. No se duplica el motor.

### Catálogos TENANT-CONFIG de este módulo
- `situaciones_aspirante` — prospecto, en proceso, aceptado, rechazado, inscrito. (De
  `cat_situacion_aspirante`.)
- `situaciones_asesor`, `situaciones_tutor` — activo, inactivo.
- `estados_documento` — pendiente, aceptado, rechazado. (De `cat_estado_documento`.)
- `etapas_crm` — embudo configurable (si se quiere un pipeline visual además de `paso`).

### `alumnos` (TENANT)  ← rol materializado
Atributos propios del rol alumno. NO duplica datos de persona.

| Columna | Tipo | Notas |
|---|---|---|
| persona_id | bigint FK → personas | PK = persona_id (1:1 con persona en su faceta de alumno) |
| clave_alumno | varchar(50) NULL | |
| cedula_profesional | varchar(30) NULL | Cuando ya titulado |
| situacion_id | bigint FK → situaciones_alumno (config) | activo/baja/egresado/titulado |

### `matricula_oferta` (TENANT)  ← "el alumno" real, por oferta
Reemplaza `inter_alumno_plan` de IMEP. Cada inscripción a una oferta es una fila.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| persona_id | bigint FK → personas | |
| oferta_id | bigint FK → oferta | Trae carrera+plan+campus |
| matricula | varchar(50) | La matrícula/clave del alumno EN esta oferta |
| generacion | varchar(100) NULL | |
| fecha_ingreso | date | |
| situacion_id | bigint FK → situaciones_alumno (config) | Situación en ESTA oferta |
| estatus | varchar(30) | activo / egresado / baja |

Índice único (persona_id, oferta_id). Índice único (matricula) por tenant.

> El rol `alumno` en `persona_rol` se activa por la existencia de ≥1 `matricula_oferta`.
> El historial, las inscripciones, los adeudos y las respuestas de formulario cuelgan
> de `matricula_oferta_id`, nunca de `alumno` a secas.

### `expedientes` (TENANT)
Documentos del alumno por oferta (una vez ya es alumno). Reemplaza `inter_alumno_expediente`
ligándolo a la oferta y a Laserfiche. (El expediente del ASPIRANTE es `expediente_documentos`,
arriba; este es el del alumno ya inscrito.)

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| matricula_oferta_id | bigint FK → matricula_oferta | |
| nombre | varchar(255) | |
| ruta | varchar(500) | S3 o URI de Laserfiche |
| laserfiche_entry_id | varchar(50) NULL | Enganche con Repository API v2 |
| comentario | varchar(500) NULL | |

### Catálogo TENANT-CONFIG adicional
- `situaciones_alumno` — activo, baja temporal, baja definitiva, egresado, titulado,
  condicionado. (De `cat_situacion_alumno` de IMEP, ahora configurable.)

---

## Nota de dependencias para Claude Code

Orden de generación de migraciones (respeta FKs):

```
FASE 0:  tenants, domains, super_admins, catálogos-landlord,
         modulos, modulos_activos, configuraciones, auditoria
FASE 1:  (1) sexos/generos ya en landlord → personas → usuarios → roles →
             persona_rol → permisos
         (2) campus → carreras → planes_estudio → asignaturas → plan_materias →
             seriacion → oferta  (+ catálogos-config del módulo 2 ANTES de sus FKs)
         (3) formularios → formulario_asignacion → (respuestas_formulario va después
             de módulo 4 por la FK a matricula_oferta)
         (4) aspirantes → alumnos → matricula_oferta → expedientes →
             respuestas_formulario (ahora sí)
```

Los catálogos `TENANT-CONFIG` de cada módulo deben migrarse y sembrarse **antes** de
las tablas que los referencian.

---

# FASE 2 — Operación escolar

## Módulo 5 — Control escolar (ciclos, grupos, inscripción, calificaciones, kárdex)

Aterriza las aclaraciones del cliente sobre grupos, recursadores, docente titular/adjunto,
tronco común compartible en grupo, y tipos de calificación. Corrige las dos fricciones
del legacy IMEP: la doble inscripción (`inter_alumno_grupo` + `inter_alumno_asignatura_grupo`)
se colapsa a un nivel único, y el docente-grupo se tipa.

### `ciclos` (TENANT)
Periodo escolar. Una escuela tiene múltiples ciclos abiertos; en su mayoría cada campus
carga los suyos, pero se permite un ciclo compartido (campus_id NULL = global).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| campus_id | bigint FK → campus NULL | NULL = ciclo global de la escuela |
| clave | varchar(50) | p.ej. `2026-2027/1` |
| nombre | varchar(120) | |
| fecha_inicio | date | |
| fecha_fin | date | |
| situacion_id | bigint FK → situaciones_ciclo (config) | planeado/abierto/en_curso/cerrado |
| inicio | date | Inicio del ciclo |
| fin | date | Fin del ciclo |
| inscripcion_desde | date NULL | Ventana de inscripción |
| inscripcion_hasta | date NULL | |
| altas_bajas_hasta | date NULL | Límite de altas/bajas |
| captura_calif_hasta | date NULL | Límite de captura de calificaciones |

### `grupos` (TENANT)
Contenedor de materias en un ciclo. Ligado al ciclo (confirmado por el cliente) y al campus.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| ciclo_id | bigint FK → ciclos | |
| campus_id | bigint FK → campus | |
| plan_id | bigint FK → planes_estudio NULL | Grupo "de plan" cuando aplica |
| clave | varchar(70) | |
| nombre | varchar(200) NULL | |
| cupo | int NULL | |
| turno_id | bigint FK → turnos (config) NULL | |
| situacion_id | bigint FK → situaciones_grupo (config) | |
| grupo_origen_id | bigint FK → grupos NULL | Para grupos derivados/clonados |

### `asignatura_grupo` (TENANT)  ← la materia concreta abierta en el grupo
Reemplaza `inter_asignatura_grupo` de IMEP. La materia se referencia por `plan_materia_id`
(la materia-en-plan, con su clave de plan). **Tronco común compartible**: dos ofertas con
planes distintos abren cada una su `asignatura_grupo` apuntando a la misma asignatura de
catálogo pero distinta `plan_materia`; pueden compartir el mismo `grupo` (misma aula, mismo
docente) conservando su clave de acta.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| grupo_id | bigint FK → grupos | |
| plan_materia_id | bigint FK → plan_materias | La materia-en-plan (trae clave del plan) |
| fecha_inicio | datetime NULL | |
| fecha_fin | datetime NULL | |
| situacion_id | bigint FK → situaciones_asignatura_grupo (config) | |

### `horarios_asignatura_grupo` (TENANT)
Bloques de horario de la materia en el grupo. Necesario para validar choques en la
inscripción autogestiva y alimentar el motor de horarios (OR-Tools).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| asignatura_grupo_id | bigint FK → asignatura_grupo | |
| dia_semana | smallint | 1..7 |
| hora_inicio | time | |
| hora_fin | time | |
| aula_id | bigint FK → aulas (config) NULL | |

### `docente_asignatura_grupo` (TENANT)  ← docente(s) por materia, tipado
Reemplaza `inter_docente_asignatura_grupo` agregando `tipo`. Una materia puede tener 1..N
docentes; el **titular** es quien firma el acta; los **adjuntos** acompañan.

| Columna | Tipo | Notas |
|---|---|---|
| asignatura_grupo_id | bigint FK → asignatura_grupo | PK compuesta |
| persona_id | bigint FK → personas | PK compuesta (el docente, como persona) |
| tipo | varchar(20) | titular / adjunto |

Regla: a lo más un `titular` por `asignatura_grupo` (validar en aplicación).

### `tutor_asignatura_grupo` (TENANT)  ← tutor ACADÉMICO (no familiar)
El tutor que acompaña al docente en lo académico (aclaración del cliente: NO es un padre).
Se ancla al mismo `asignatura_grupo`, separado de los docentes.

| Columna | Tipo | Notas |
|---|---|---|
| asignatura_grupo_id | bigint FK → asignatura_grupo | PK compuesta |
| persona_id | bigint FK → personas | PK compuesta (el tutor) |
| puede_ver | boolean | Ver avance del grupo |
| puede_calificar | boolean | Normalmente false (el tutor acompaña, no califica) |
| puede_comentar | boolean | Dejar observaciones |

### `docentes` (TENANT)  ← rol materializado (faltaba en Fase 1)
Atributos propios del rol docente. NO duplica datos de persona.

| Columna | Tipo | Notas |
|---|---|---|
| persona_id | bigint FK → personas | PK = persona_id |
| clave_profesor | varchar(50) NULL | |
| cedula_profesional | varchar(30) NULL | |
| tipo_docente_id | bigint FK → tipos_docente (config) NULL | |
| situacion_id | bigint FK → situaciones_docente (config) | |
| edicion_contenido | smallint | 0 ninguno / 1 su grupo / 2 todos (de IMEP) |

Un docente puede estar ligado a 1..N campus vía `campus_docente` (pivote persona↔campus).

### `inscripcion` (TENANT)  ← NIVEL ÚNICO canónico
La tabla puente central. Un alumno (vía su `matricula_oferta`) inscrito a UNA
`asignatura_grupo`. "Inscribir a todo el grupo" = crear N filas. "Materia suelta" = 1 fila.
"Recursador" = `tipo = recursamiento`. Resuelve la duplicidad del legacy.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| matricula_oferta_id | bigint FK → matricula_oferta | El alumno EN su oferta |
| asignatura_grupo_id | bigint FK → asignatura_grupo | |
| ciclo_id | bigint FK → ciclos | Denormalizado para consultas por periodo |
| tipo | varchar(20) | ordinaria / recursamiento |
| forma_inscripcion | varchar(20) | autogestiva / administrativa |
| situacion_id | bigint FK → situaciones_inscripcion (config) | inscrito/baja/cursando |
| calificacion_final | decimal(4,2) NULL | Se calcula al cierre desde ponderacion_config |

Índice único (matricula_oferta_id, asignatura_grupo_id).

### `historial` (TENANT)  ← kárdex, con tipos de evaluación
Reemplaza `tr_historial`. Cuelga de `matricula_oferta` (no de alumno genérico), así el
historial de la licenciatura no se mezcla con el de la maestría de la misma persona. El
kárdex es la vista de este historial. Soporta ordinaria/extraordinaria/revalidación/
recursamiento y más (configurable).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| matricula_oferta_id | bigint FK → matricula_oferta | |
| plan_materia_id | bigint FK → plan_materias | La materia-en-plan calificada |
| ciclo_id | bigint FK → ciclos | |
| asignatura_grupo_id | bigint FK → asignatura_grupo NULL | De qué grupo salió (trazabilidad) |
| tipo_evaluacion_id | bigint FK → tipos_evaluacion (config) | ordinaria/extra/revalidación/recursamiento |
| estatus_id | bigint FK → estatus_historial (config) | aprobada/reprobada/en_curso |
| calificacion | decimal(4,2) NULL | |
| situacion_reprobatoria_id | bigint FK → situaciones_reprobatoria (config) NULL | |
| acta_folio | varchar(50) NULL | Folio del acta donde se asentó |
| observacion_id | bigint FK → observaciones_historial (config) NULL | De academyx |

### `equivalencias` (TENANT)  ← revalidaciones de otras escuelas
Para materias reconocidas de procedencia externa (parte del kárdex pero de origen distinto).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| matricula_oferta_id | bigint FK → matricula_oferta | |
| plan_materia_id | bigint FK → plan_materias | La materia del plan que se da por cubierta |
| institucion_procedencia | varchar(255) | |
| calificacion | decimal(4,2) NULL | |
| documento_ruta | text NULL | Dictamen de equivalencia (S3/Laserfiche) |

### Reglas de negocio del módulo (no se ven en el esquema)

**Inscripción autogestiva — validaciones (recorre el DAG de `seriacion`):**
1. La materia pertenece al plan de la `matricula_oferta` del alumno.
2. Seriación satisfecha: para cada fila de `seriacion` de esa `plan_materia`, el
   `requiere_plan_materia_id` debe tener historial aprobado (si `tipo=aprobada`) o al
   menos cursado (si `tipo=cursada`); o cumplir `minimo_creditos`.
3. Cupo del `asignatura_grupo`/`grupo` no excedido.
4. Sin choque de horario con otras inscripciones del mismo ciclo (usa `horarios_asignatura_grupo`).
5. La ventana de inscripción del ciclo está abierta (`ciclos.fechas_config`).

**Cálculo de `calificacion_final`:** al cierre del periodo se computa desde
`plan_materias.ponderacion_config` combinando parciales capturados y (si el módulo LMS
está activo) las ponderaciones del curso LMS ligado al `asignatura_grupo`. El resultado
se escribe en `inscripcion.calificacion_final` y se asienta en `historial`.

**Acta de calificaciones:** el asentamiento lo hace el docente `titular` del
`asignatura_grupo`. Genera `acta_folio` y vuelca a `historial`.

### Catálogos TENANT-CONFIG de este módulo
- `situaciones_ciclo` — planeado, abierto, en curso, cerrado.
- `situaciones_grupo` — abierto, cerrado, cancelado.
- `situaciones_asignatura_grupo` — activa, cerrada.
- `situaciones_inscripcion` — inscrito, cursando, baja.
- `situaciones_docente` — activo, baja, licencia.
- `tipos_docente` — titular, asignatura, invitado (de academyx).
- `aulas` — espacios físicos por campus.
- `tipos_evaluacion` — ordinaria, extraordinaria, revalidación, recursamiento, a título,
  regularización. (Configurable.)
- `estatus_historial` — aprobada, reprobada, en curso, no presentó. (De IMEP + academyx.)
- `situaciones_reprobatoria` — NP, reprobó examen, reprobó por faltas.
- `observaciones_historial` — anotaciones al asentar (de academyx).

---

## Módulo 6 — Asistencia y reloj checador

Cubre las tres poblaciones que el cliente pidió: docentes, administrativos y alumnos.
Se separa el **registro de checada** (fichaje del reloj) de la **asistencia académica**
(presencia a clase, que afecta calificación/faltas).

### `dispositivos_checador` (TENANT)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| campus_id | bigint FK → campus | |
| tipo | varchar(30) | qr / biometrico / geocerca / manual |
| identificador | varchar(120) | Serial o token del dispositivo |
| geocerca_lat | decimal(10,7) NULL | Centro de geocerca |
| geocerca_lng | decimal(10,7) NULL | |
| geocerca_radio_m | int NULL | Radio en metros |
| tolerancia_min | smallint NULL | Minutos de tolerancia |

### `checadas` (TENANT)  ← fichaje de reloj (todas las poblaciones)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| persona_id | bigint FK → personas | Quién checó (cualquier rol) |
| dispositivo_id | bigint FK → dispositivos_checador NULL | |
| tipo_movimiento | varchar(20) | entrada / salida |
| momento | timestamptz | |
| origen | varchar(30) | qr/biometrico/geocerca/manual |
| lat | decimal(9,6) NULL | Si geocerca |
| lng | decimal(9,6) NULL | |

Índice (persona_id, momento).

### `asistencia_clase` (TENANT)  ← presencia académica (solo alumnos)
Cuelga de la `inscripcion`, no de la checada, porque es la asistencia a UNA materia que
afecta faltas/calificación. Reemplaza `tr_inasistencia_clase` de IMEP invirtiendo el modelo
(se registra presencia y ausencia explícita, no solo faltas).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| inscripcion_id | bigint FK → inscripcion | |
| fecha | date | |
| estatus | varchar(20) | presente / ausente / justificada / retardo |
| registrada_por | bigint FK → personas NULL | Docente que pasó lista |
| observacion | varchar(255) NULL | |

Índice único (inscripcion_id, fecha).

> **Nómina (Fase 4)** consumirá `checadas` de docentes/administrativos para asistencia
> laboral. **Control escolar** consume `asistencia_clase` para faltas del alumno.
> Se mantienen separadas a propósito.

### Catálogos TENANT-CONFIG de este módulo
- `tipos_dispositivo_checador` — qr, biométrico, geocerca, manual.

---

# FASE 3 — Módulos de valor

## Módulo 7 — Finanzas (motor configurable, pasarela, CFDI)

Principio rector: **no modelar "colegiatura" ni "pago semanal" como casos**. Se modelan
conceptos abstractos + reglas de generación + planes de cobro asignables. Así "semanal sin
inscripción", "mensual con inscripción" y "pago único de titulación" son solo DATOS, no
código. Esto es lo que permite que una sola base sirva a escuelas con esquemas de cobro
distintos (requisito central del SaaS). El legacy IMEP/academyx casi no tiene finanzas,
así que este módulo es diseño nuevo sin deuda.

### `conceptos_pago` (TENANT-CONFIG)
Catálogo de QUÉ se cobra. Configurable por escuela.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| clave | varchar(50) UK | `colegiatura`, `inscripcion`, `titulacion`, `credencial`, `examen`... |
| nombre | varchar(150) | |
| clave_sat | varchar(15) NULL | ClaveProdServ para CFDI |
| clave_unidad_sat | varchar(10) NULL | Ej. E48 (servicio) |
| gravado | boolean | Si causa IVA |
| tasa_iva | decimal(5,4) NULL | 0.16, 0 exento... |
| cuenta_contable | varchar(50) NULL | |

### `planes_cobro` (TENANT)
CÓMO y CUÁNTO se cobra, asignable por carrera/plan/oferta. Un plan de cobro agrupa reglas.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | varchar(150) | `Licenciatura escolarizada 2026` |
| moneda | varchar(3) | MXN |
| aplica_a_tipo | varchar(30) | carrera / plan / oferta / global |
| aplica_a_id | bigint NULL | ID del target (NULL si global) |
| vigente_desde | date | |
| vigente_hasta | date NULL | |

### `reglas_generacion` (TENANT)  ← el corazón configurable
Cada regla dice: qué concepto, con qué periodicidad, cuánto, y con qué modificadores.
La combinación de reglas de un plan de cobro define el esquema completo de una escuela.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| plan_cobro_id | bigint FK → planes_cobro | |
| concepto_id | bigint FK → conceptos_pago | |
| periodicidad | varchar(20) | unico / semanal / quincenal / mensual / por_ciclo / por_materia |
| monto_base | decimal(10,2) | |
| dia_generacion | smallint NULL | Día del mes/semana en que se genera el adeudo |
| dia_limite | smallint NULL | Día de vencimiento |
| obligatorio | boolean | p.ej. inscripción obligatoria vs opcional |
| num_parcialidades | smallint NULL | Si el concepto se divide en pagos |
| prorratea | boolean | Si se prorratea por fecha de ingreso |
| concepto_prerequisito_id | bigint FK → conceptos_pago NULL | Dependencia entre conceptos |

Ejemplos que quedan como puros datos:
- Escuela A: regla `colegiatura` periodicidad `semanal`, sin regla de `inscripcion`.
- Escuela B: regla `inscripcion` periodicidad `por_ciclo` + regla `colegiatura` `mensual`.
- Titulación: regla `titulacion` periodicidad `unico`, obligatorio, generada al iniciar trámite.

### `recargos_descuentos` (TENANT)  ← modificadores
Recargos por mora y descuentos/becas. Separados de la regla para poder aplicarlos
transversalmente.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tipo | varchar(20) | recargo / descuento / beca |
| nombre | varchar(150) | |
| modo | varchar(20) | porcentaje / monto_fijo |
| valor | decimal(10,4) | |
| dias_gracia | smallint NULL | Días de mora antes de aplicar |
| tope_monto | decimal(12,2) NULL | Tope del recargo |
| requiere_beca | boolean | Si el descuento exige beca vigente |

### `becas_alumno` (TENANT)
Asignación de beca/descuento a una `matricula_oferta` (override por alumno).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| matricula_oferta_id | bigint FK → matricula_oferta | |
| recargo_descuento_id | bigint FK → recargos_descuentos | |
| vigente_desde | date | |
| vigente_hasta | date NULL | |
| autorizado_por | bigint FK → personas NULL | Quién la autorizó (auditoría) |

### `adeudos` (TENANT)  ← lo que el alumno debe
Generado por las reglas. Cuelga de `matricula_oferta` (nunca de alumno genérico), así se
sabe a qué oferta pertenece cada cargo.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| matricula_oferta_id | bigint FK → matricula_oferta | |
| concepto_id | bigint FK → conceptos_pago | |
| regla_id | bigint FK → reglas_generacion NULL | De qué regla salió |
| ciclo_id | bigint FK → ciclos NULL | |
| periodo_etiqueta | varchar(50) NULL | `Marzo 2026`, `Semana 12`... |
| monto | decimal(10,2) | Base |
| monto_recargos | decimal(10,2) | Acumulado por mora |
| monto_descuentos | decimal(10,2) | Aplicado por becas |
| monto_total | decimal(10,2) | Neto a pagar |
| fecha_generacion | date | |
| fecha_vencimiento | date | |
| estatus | varchar(20) | pendiente / parcial / pagado / cancelado / condonado |

Índice (matricula_oferta_id, estatus). Append-only en la práctica: cancelaciones/condonaciones
se registran, no se borran (auditoría).

### `pagos` (TENANT)  ← lo que el alumno pagó
Un pago liquida uno o varios adeudos (relación vía `pago_adeudo`).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| matricula_oferta_id | bigint FK → matricula_oferta | |
| monto | decimal(10,2) | |
| metodo | varchar(30) | efectivo / spei / tarjeta / oxxo |
| referencia | varchar(100) NULL | Referencia bancaria/pasarela |
| pasarela | varchar(30) NULL | conekta / openpay / stripe |
| pasarela_txn_id | varchar(120) NULL | ID de transacción |
| estatus | varchar(20) | pendiente / completado / fallido / reembolsado |
| momento | timestamptz | |

### `pago_adeudo` (TENANT)  ← qué adeudos cubrió cada pago
| Columna | Tipo | Notas |
|---|---|---|
| pago_id | bigint FK → pagos | PK compuesta |
| adeudo_id | bigint FK → adeudos | PK compuesta |
| monto_aplicado | decimal(10,2) | Permite pagos parciales y split |

### `bitacora_situacion_financiera` (TENANT)
Preserva el concepto de `bitacora_cambio_situacion_financiera` de IMEP: historial de cambios
de situación de pago del alumno (para bloqueos por adeudo, etc.).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| matricula_oferta_id | bigint FK → matricula_oferta | |
| situacion_id | bigint FK → situaciones_pago (config) | corriente / moroso / bloqueado |
| motivo | varchar(255) NULL | |
| momento | timestamptz | |

### Facturación CFDI 4.0 (append-only, regulado)

### `facturas` (TENANT)
Metadatos del CFDI. El XML/PDF timbrado va a S3; en BD solo metadatos y UUID. Inmutable
por regulación: correcciones = nota de crédito o cancelación + refactura, nunca UPDATE.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| matricula_oferta_id | bigint FK → matricula_oferta NULL | |
| receptor_rfc | varchar(13) | |
| receptor_razon_social | varchar(255) | |
| receptor_uso_cfdi | varchar(5) | Ej. D10 (colegiaturas), G03 |
| receptor_regimen_fiscal | varchar(5) | |
| receptor_cp | varchar(5) | Domicilio fiscal receptor (CFDI 4.0) |
| subtotal | decimal(12,2) | |
| iva | decimal(12,2) | |
| total | decimal(12,2) | |
| uuid | varchar(36) UK NULL | Folio fiscal (timbrado) |
| pac | varchar(30) | facturama / sw_sapien / finkok |
| estatus | varchar(20) | borrador / timbrada / cancelada |
| xml_ruta | text NULL | S3 |
| pdf_ruta | text NULL | S3 |
| fecha_timbrado | timestamptz NULL | |

### `factura_conceptos` (TENANT)
Renglones del CFDI, ligados a los pagos/adeudos que factura.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| factura_id | bigint FK → facturas | |
| pago_id | bigint FK → pagos NULL | |
| clave_sat | varchar(15) | |
| descripcion | varchar(255) | |
| cantidad | decimal(10,2) | |
| valor_unitario | decimal(12,2) | |
| importe | decimal(12,2) | |

> **Autofacturación vs admin.** `configuraciones` define si el alumno puede autofacturar
> (portal con sus datos fiscales) o si la facturación la hace solo el admin. El PAC se
> integra vía API; el timbrado corre en cola (Horizon) por si el PAC tarda o falla.

### Reglas de negocio del módulo
- **Generación de adeudos**: job programado (Horizon scheduler) recorre `reglas_generacion`
  vigentes por `matricula_oferta` activa y crea `adeudos` según periodicidad. Idempotente
  (no duplica el adeudo de un periodo ya generado).
- **Aplicación de recargos**: job diario marca morosos y acumula `monto_recargos` según
  `recargos_descuentos`.
- **Conciliación de pasarela**: webhook de Conekta/OpenPay actualiza `pagos.estatus` y
  liquida `adeudos` vía `pago_adeudo`.
- **Bloqueo por adeudo**: `bitacora_situacion_financiera` gobierna si un alumno con adeudo
  puede reinscribirse / ver calificaciones (configurable por escuela).

### Catálogos TENANT-CONFIG de este módulo
- `conceptos_pago` (arriba).
- `situaciones_pago` — corriente, moroso, bloqueado, becado. (De `cat_situacion_pago` IMEP.)
- `metodos_pago` — efectivo, SPEI, tarjeta, OXXO. (Mapea a c_FormaPago del SAT.)

---

## Módulo 8 — LMS (banco de reactivos, foros, ponderaciones)

**Módulo crítico del sistema.** LMS propio (no Moodle) para poder ligar ponderaciones
directo al acta de Control Escolar — esa integración LMS↔kárdex es la ventaja competitiva.
El legacy IMEP (`estudyle`) ya es rico aquí: 12 tipos de reactivo, actividades tipadas,
agrupación por parciales/módulos, videoconferencia Zoom y portafolio de evidencias. Esta
spec toma ese vocabulario y lo modela 100% relacional (reactivos con tablas hijas, reutilización limpia por ciclo).

### Dos flujos de autoría (ambos requeridos)

1. **Carga administrativa de oferta completa.** Un administrador (con rol/alcance
   adecuado) carga una oferta ONLINE de inicio a fin: todas las unidades, contenidos,
   actividades y reactivos con sus ponderaciones ya definidas. Los docentes solo califican.
   Estas actividades **se repiten cada ciclo**: se definen una vez como PLANTILLA de curso
   (por `plan_materia`) y se clonan al abrir cada `asignatura_grupo`.
2. **Carga por el docente.** Un docente crea sus propias actividades, exámenes y foros
   sobre su `asignatura_grupo` (complemento en ofertas presenciales). El flag
   `docentes.edicion_contenido` (0 ninguno / 1 su grupo / 2 todos, del legacy) gobierna
   qué puede editar.

> **Alcance por administrador.** La autoría administrativa NO es un permiso plano: usa
> `spatie/laravel-permission` (Módulo 1) con permisos LMS granulares —crear plantilla,
> editar banco global, publicar oferta, calificar, ver reportes— asignables por rol. Un
> "admin de contenido" puede cargar plantillas pero no calificar; un "coordinador" ve
> reportes de todos los grupos. El `rol_activo_id` acota el alcance en cada request.

### `cursos` (TENANT)
Un curso es la instancia LMS de una materia abierta. Se liga al `asignatura_grupo` para
enrolar automáticamente a los inscritos y devolver calificaciones. Puede nacer de una
plantilla (carga administrativa) o crearse vacío (carga del docente).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| asignatura_grupo_id | bigint FK → asignatura_grupo NULL | Curso vivo de un grupo concreto |
| plantilla_plan_materia_id | bigint FK → plan_materias NULL | Plantilla por materia-en-plan (se clona al abrir grupo) |
| titulo | varchar(200) | |
| descripcion | text NULL | |
| es_plantilla | boolean | Plantilla reutilizable vs curso vivo |
| origen_curso_id | bigint FK → cursos NULL | De qué plantilla/curso se clonó (trazabilidad de reutilización) |

### Reutilización por ciclo (requisito explícito del cliente)
Las actividades "se repiten cada ciclo". Mecanismo: la oferta online se define como
`cursos.es_plantilla = true`. Al abrir el `asignatura_grupo` de un nuevo ciclo, un job
**clona** la plantilla completa (unidades, contenidos, actividades, vínculos a reactivos)
a un curso vivo con `origen_curso_id` apuntando a la plantilla. Editar la plantilla NO
afecta cursos ya clonados (cada ciclo queda congelado); se puede re-clonar si se desea
propagar cambios. Esto evita el recapture manual cada periodo.

### `unidades` / `contenidos` (TENANT)
Estructura del curso. Del legacy: las actividades se agrupan además por PARCIAL/MÓDULO
(`cat_modulos`: "Parcial 1/2/3", "Bloque 2"...), lo que alimenta los cortes de calificación.

`unidades`: id, curso_id FK, parcial smallint (1..N, corte de evaluación), titulo, orden.
`contenidos`: id, unidad_id FK, tipo (video/lectura/archivo/enlace/scorm), titulo,
cuerpo (text/HTML), recurso_ruta (S3), orden.

### `actividades` (TENANT)  ← lo evaluable
Tipos tomados del legacy `cat_tipos_actividad`. Cada actividad aporta a un parcial y su
ponderación alimenta `ponderacion_config`.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| curso_id | bigint FK → cursos | |
| unidad_id | bigint FK → unidades NULL | |
| parcial | smallint | Corte al que pertenece |
| tipo_id | bigint FK → tipos_actividad (config) | contenido_lectura / ejercicio_examen / portafolio_evidencias / sqa |
| titulo | varchar(200) | |
| instrucciones | text NULL | |
| metodo_resolver | varchar(20) | individual / equipo (del legacy `cat_metodos_resolver`) |
| dificultad_id | bigint FK → dificultades (config) NULL | Del legacy `cat_dificultad` |
| ponderacion | decimal(5,2) | % que aporta a la calificación del parcial/materia |
| fecha_apertura | timestamptz NULL | |
| fecha_cierre | timestamptz NULL | |
| intentos_max | smallint NULL | |
| aleatoriza_reactivos | boolean | Examen: barajar preguntas |
| num_reactivos_mostrar | smallint NULL | Tomar N de un banco mayor |
| rubrica_id | bigint FK → rubricas NULL | Rúbrica de evaluación (tabla propia) |

### `reactivos` (TENANT)  ← banco de preguntas (12 tipos del legacy)
El `tipo` + sus tablas hijas relacionales (`opciones_reactivo`, `pares_reactivo`) modelan TODOS los tipos que el LMS legacy ya soporta y que el
cliente enfatiza (crucigrama/sopa, ordena palabra, etc.). Comparte vocabulario con el motor
de formularios (Módulo 3). Tipos (de `cat_tipo_pregunta`):

| clave | tipo | descripción |
|---|---|---|
| pa | abierta | Pregunta abierta (texto libre, calif. manual) |
| om | opcion_multiple | Opción múltiple |
| rc | relacion_columnas | Relación de columnas |
| zi | zona_imagenes | Zona/selección de imágenes (hotspot) |
| dd | ordena_dragdrop | Ordena / arrastra y suelta (ordena palabra) |
| qq | pni_qqq | PNI / QQQ (organizador) |
| sq | sqa | SQA / RA-P-RP (organizador de aprendizaje) |
| cc | cuadro_comparativo | Cuadro comparativo |
| mc | mapa_cognitivo | Mapa cognitivo |
| fr | foro | Foro (evaluable) |
| ad | archivo_adjunto | Entrega de archivo |
| — | sopa_letras / crucigrama / verdadero_falso | Ampliaciones del nuevo sistema |

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| banco_id | bigint FK → bancos_reactivos NULL | Agrupador reutilizable |
| tipo | varchar(30) | Uno de los de arriba |
| dificultad_id | bigint FK → dificultades (config) NULL | |
| propietario_persona_id | bigint FK → personas NULL | Autoría (del legacy `tr_propietario_pregunta`) |
| enunciado | text | |
| puntos | decimal(6,2) | |
| retroalimentacion | text NULL | Del legacy `tr_retroalimentacion` |

La estructura de cada tipo se modela con tablas hijas RELACIONALES (mismo enfoque que el
legacy `tr_om_reactivos`, `tr_rc_reactivos`, `tr_zi_reactivos`, `tr_dd_reactivos`...), no
con JSON. Una tabla de opciones cubre la mayoría de tipos; los de pares/matriz usan su
propia hija:

### `opciones_reactivo` (TENANT)  ← opción/elemento de un reactivo (una FILA)
Cubre opcion_multiple, verdadero_falso, zona_imagenes, ordena_dragdrop, sopa/crucigrama
(palabras), y las opciones de cualquier tipo con lista.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| reactivo_id | bigint FK → reactivos | |
| texto | varchar(500) NULL | Texto de la opción/palabra |
| imagen_ruta | varchar(500) NULL | Para zona_imagenes |
| es_correcta | boolean | Marca la(s) correcta(s) |
| orden_correcto | smallint NULL | Para ordena_dragdrop (posición correcta) |
| orden | smallint | Orden de presentación |

### `pares_reactivo` (TENANT)  ← relación de columnas / cuadro comparativo (una FILA por par)
Para relacion_columnas, cuadro_comparativo, mapa_cognitivo.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| reactivo_id | bigint FK → reactivos | |
| lado_a | varchar(300) | Elemento columna A |
| lado_b | varchar(300) | Su pareja correcta en columna B |
| orden | smallint | |

> Autocalificación: el front arma la pregunta desde estas tablas; el backend compara la
> respuesta del alumno contra `es_correcta` / `orden_correcto` / los pares. Todo con queries
> normales, sin parsear JSON.

### `bancos_reactivos` (TENANT)
id, nombre, plan_materia_id FK (banco por materia), propietario_persona_id, es_global bool.
Reutilizable entre cursos y ciclos. Un banco global (admin) o por docente.

### `actividad_reactivos` (TENANT)
Pivote actividad↔reactivo (qué preguntas entran a un examen/cuestionario). Permite exámenes
aleatorios tomando N reactivos de un banco (soporta el examen/cuestionario multi-tipo).

### `entregas` (TENANT)  ← lo que el alumno entrega (cabecera)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| actividad_id | bigint FK → actividades | |
| inscripcion_id | bigint FK → inscripcion | El alumno EN esa materia |
| intento | smallint | |
| archivo_ruta | varchar(500) NULL | Entrega de archivo (tarea/portafolio) |
| calificacion | decimal(6,2) NULL | Auto o manual |
| estatus | varchar(20) | borrador / entregada / calificada |
| retroalimentacion | text NULL | Del docente |
| momento_entrega | datetime NULL | |

### `entrega_respuestas` (TENANT)  ← respuesta a CADA reactivo (una FILA, no JSON)
Del legacy `tr_om_respuesta`, `tr_rc_respuesta`, etc., unificado.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| entrega_id | bigint FK → entregas | |
| reactivo_id | bigint FK → reactivos | |
| opcion_reactivo_id | bigint FK → opciones_reactivo NULL | Opción elegida (om/vf/zi) |
| par_reactivo_id | bigint FK → pares_reactivo NULL | Para relación de columnas |
| valor_texto | varchar(1000) NULL | Para abierta/respuesta corta |
| orden_dado | smallint NULL | Para ordena_dragdrop |
| puntos_obtenidos | decimal(6,2) NULL | Tras calificar |

Índice (entrega_id, reactivo_id). Modificar la calificación de un reactivo = `UPDATE`
directo aquí.

### `portafolio_evidencias` (TENANT)  ← del legacy
Para el tipo de actividad "portafolio de evidencias": colección de archivos del alumno.
`portafolio_evidencias`: id, inscripcion_id FK, actividad_id FK, descripcion varchar.
`portafolio_archivos`: id, portafolio_id FK, ruta varchar(500), descripcion varchar — una
fila por archivo (no un array JSON).

### `foros` y `foro_mensajes` (TENANT)
Foros de discusión (evaluables o no).
`foros`: id, curso_id FK, actividad_id FK NULL (si es calificable), titulo, descripcion.
`foro_mensajes`: id, foro_id FK, persona_id FK, mensaje_padre_id FK NULL (hilos), cuerpo, momento.

### `videoconferencias` (TENANT)  ← del legacy (Zoom integrado)
El LMS legacy integra Zoom. Se generaliza a un proveedor configurable.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| asignatura_grupo_id | bigint FK → asignatura_grupo | |
| proveedor | varchar(30) | zoom / meet / teams |
| meeting_id | varchar(120) | |
| url_join | text | |
| inicio | timestamptz | |
| fin | timestamptz NULL | |
| grabacion_ruta | text NULL | |

Tabla `acceso_videoconferencia` (asistencia a la sesión) registra quién entró (para
asistencia de clases online, se enlaza con `asistencia_clase` del Módulo 6).

### Integración LMS ↔ Control Escolar (la ventaja competitiva)
- Al abrir un `asignatura_grupo` con módulo LMS activo, se crea/clona el `curso`
  (de plantilla si existe) y se enrolan automáticamente los `inscripcion` del grupo.
- Las `actividades.ponderacion` por `parcial` deben sumar coherentemente con
  `plan_materias.ponderacion_config` (validación al publicar el curso).
- Al cierre de cada parcial/periodo, la calificación LMS se combina con parciales y se
  vuelca a `inscripcion.calificacion_final` → `historial` (regla de cálculo, Módulo 5).

### Catálogos TENANT-CONFIG de este módulo
- `tipos_actividad` — contenido/lectura, ejercicio/examen, portafolio de evidencias, SQA.
- `tipos_reactivo` — los 12+ listados arriba (compartidos con formularios).
- `dificultades` — fácil, media, difícil (del legacy `cat_dificultad`).
- `metodos_resolver` — individual, equipo.

---

## Módulo 9 — Titulación y certificación SEP (+ servicio social)

El módulo más delicado y regulado. Genera el Título Electrónico y el Certificado
Electrónico contra los XSD de la SEP, firmados con el certificado del responsable. Toma
la maquinaria de `academyx_cyt`, que es la referencia más completa. **Todo dato aquí es
crítico**: aplica el patrón de revisión humana del IDP antes de generar cualquier XML.

### `responsables_firma` (TENANT)  ← de academyx cat_responsable
Rector/responsables que firman. Guarda los datos del CERTIFICADO de firma electrónica —
esto es lo que sella el XML ante la SEP.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| persona_id | bigint FK → personas NULL | Si es persona del sistema |
| nombre | varchar(150) | |
| apellido_paterno | varchar(100) | |
| apellido_materno | varchar(100) NULL | |
| curp | varchar(18) | |
| cargo_id | bigint FK → cargos (config) | |
| tipo_responsable_id | bigint FK → tipos_responsable (config) | |
| abreviatura_titulo_id | bigint FK → abreviaturas_titulo (config) NULL | |
| cer_titular | varchar(150) | Titular del certificado |
| cer_serial | varchar(100) | Número de serie del certificado |
| cer_vigencia_inicio | date | |
| cer_vigencia_fin | date | |

> El manejo del .cer/.key y contraseña de la e.firma NO se guarda en claro en BD. Se
> referencia por bóveda de secretos / KMS. La BD guarda metadatos (serial, vigencia).

### `tramites_titulacion` (TENANT)
El trámite de un alumno hacia el título. Máquina de estados por `etapa`.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| matricula_oferta_id | bigint FK → matricula_oferta | El alumno EN su oferta |
| modalidad_titulacion_id | bigint FK → modalidades_titulacion (config) | Tesis, EGEL, promedio... |
| etapa_id | bigint FK → etapas_titulacion (config) | Máquina de estados |
| estatus_titulo_id | bigint FK → estatus_titulo (config) | |
| fecha_examen | date NULL | |
| fecha_titulacion | date NULL | |
| clave_servicio_titulacion | varchar(100) NULL | De academyx_cyt |

### `servicio_social` (TENANT)  ← de academyx_cyt
Requisito para titular. Cumplimiento con fundamento legal.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| matricula_oferta_id | bigint FK → matricula_oferta | |
| cumplimiento_id | bigint FK → cumplimientos_servicio_social (config) | |
| fundamento_legal_id | bigint FK → fundamentos_legales_ss (config) | |
| institucion | varchar(255) NULL | Dónde lo prestó |
| horas | int NULL | |
| fecha_inicio | date NULL | |
| fecha_termino | date NULL | |
| constancia_ruta | text NULL | S3/Laserfiche |

### `antecedentes_academicos` (TENANT)  ← de academyx tr_datos_antecedente_academico
Estudio previo (bachillerato para lic, lic para maestría...). Puede llenarse vía el
formulario dinámico "antecedente académico" y quedar ligado por `matricula_oferta`.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| matricula_oferta_id | bigint FK → matricula_oferta | |
| tipo_antecedente_id | bigint FK → tipos_antecedente_academico (config) | |
| institucion_procedencia | varchar(255) | |
| entidad_id | bigint FK → entidades_federativas | |
| fecha_inicio | date NULL | |
| fecha_termino | date NULL | |
| cedula | varchar(100) NULL | |
| promedio | decimal(4,2) NULL | |

### Emisión de documentos electrónicos (Título y Certificado)

### `lotes_documento` (TENANT)  ← de academyx_cyt tr_lote_*
La emisión SEP se hace por lotes (como muestra la UI del cliente: "crear un lote"). Un lote
agrupa N documentos a generar/firmar/enviar.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tipo | varchar(20) | titulo / certificado |
| campus_id | bigint FK → campus NULL | |
| estatus_id | bigint FK → estatus_lote (config) | en_carga / en_espera_firma / procesado |
| responsable_firma_id | bigint FK → responsables_firma NULL | |
| num_capturados | int | Contador (como la UI: #Certificados Capturados) |
| num_procesados | int | #Procesados |

### `documentos_electronicos` (TENANT)
Cada título/certificado individual dentro de un lote.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| lote_id | bigint FK → lotes_documento | |
| matricula_oferta_id | bigint FK → matricula_oferta | |
| tramite_id | bigint FK → tramites_titulacion NULL | |
| tipo | varchar(20) | titulo / certificado |
| folio | varchar(50) NULL | Folio SEP asignado |
| estatus_id | bigint FK → estatus_certificado/titulo (config) | |
| xml_ruta | text NULL | XML generado/firmado (S3) |
| acuse_ruta | text NULL | Acuse SEP |
| lote_id | bigint FK → lotes_documento NULL | Lote de emisión (los datos se leen de las tablas fuente vía FKs, no se duplican en JSON) |

### Reglas de negocio del módulo
- **Generación del XML**: se arma contra el XSD oficial de la SEP (Título Profesional
  Electrónico / Certificado Electrónico) con datos de `personas` (CURP, entidad nacimiento),
  `carreras` (clave DGP/SAT), `planes_estudio` (RVOE, autorización), kárdex e historial,
  antecedentes y servicio social.
- **Firma**: se sella con el certificado de `responsables_firma` (e.firma desde bóveda).
- **Validación previa**: revisión humana obligatoria (patrón IDP) antes de firmar/enviar —
  un dato mal en el XML contamina el trámite ante la SEP.
- **Máquina de estados del lote**: en_carga → en_espera_firma → procesado (coincide con la
  UI del cliente). Cada transición se audita.
- **Lotes externos** (`tr_lote_*_externo` de academyx_cyt): soporte para capturar/cargar
  títulos y certificados de alumnos de generaciones previas no gestionadas en el sistema.

### Catálogos TENANT-CONFIG de este módulo
- `modalidades_titulacion` — tesis, EGEL/CENEVAL, promedio, seminario, práctica profesional.
- `etapas_titulacion`, `etapas_certificacion` — máquinas de estado.
- `estatus_titulo`, `estatus_certificado`, `estatus_lote` — de academyx_cyt.
- `tipos_responsable`, `cargos`, `abreviaturas_titulo` — para responsables de firma.
- `tipos_antecedente_academico` — bachillerato, licenciatura, etc.
- `cumplimientos_servicio_social`, `fundamentos_legales_ss` — de academyx_cyt.
- `titulos_academicos` — catálogo de títulos (Licenciado, Maestro, Doctor...).

---

---

# FASE 4 — Recursos humanos, empleabilidad, movilidad y familia

Cuatro módulos que cierran el alcance del sistema. Todos heredan las convenciones globales
(auditoría `created_at/updated_at/deleted_at/created_by/updated_by`, soft delete, catálogos
TENANT-CONFIG, nombres propios en español — sin arrastrar los nombres del legacy). Diseñados
para escalar: cada uno es encendible/apagable vía `modulos_activos` y no acopla al resto
salvo por FKs limpias a `personas`, `matricula_oferta` y `campus`.

## Módulo 10 — Nómina y recursos humanos docentes

Va más allá del legacy (que no tenía nómina real). Consume las `marcas_reloj` del Módulo 6
(reloj checador) para calcular percepciones por hora/asistencia, y soporta esquemas de pago
configurables por escuela. Núcleo del RH del personal (docentes y administrativos).

### `expedientes_laborales` (TENANT)
El vínculo laboral de una persona con la escuela. Una persona puede tener más de uno
(recontratación, doble plaza) — por eso es tabla, no columnas en persona.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| persona_id | bigint FK → personas | El empleado |
| numero_empleado | varchar(50) | Único por tenant |
| tipo_contrato_id | bigint FK → tipos_contrato (config) | base / honorarios / determinado / por_asignatura |
| regimen_fiscal_id | bigint FK → regimenes_fiscales (config) | Para CFDI de nómina |
| fecha_ingreso | date | |
| fecha_baja | date NULL | |
| motivo_baja_id | bigint FK → motivos_baja_laboral (config) NULL | |
| rfc | varchar(13) NULL | |
| curp | varchar(18) NULL | |
| nss | varchar(15) NULL | Número de seguridad social |
| clabe | varchar(18) NULL | Depósito de nómina |
| situacion_id | bigint FK → situaciones_empleado (config) | activo / licencia / baja |

### `puestos` (TENANT-CONFIG) y `adscripciones` (TENANT)
`puestos`: catálogo configurable (docente, coordinador, control escolar, dirección...).
`adscripciones`: qué puesto ocupa un expediente laboral, en qué campus, desde/hasta. Una
persona puede tener múltiples adscripciones (histórico y simultáneas).

| `adscripciones` | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| expediente_laboral_id | bigint FK → expedientes_laborales | |
| puesto_id | bigint FK → puestos | |
| campus_id | bigint FK → campus | |
| vigente_desde | date | |
| vigente_hasta | date NULL | |
| es_principal | boolean | Adscripción principal para reportes |

### `esquemas_percepcion` (TENANT)  ← motor configurable de pago
Cómo se le paga a un empleado. Configurable (sueldo fijo, por hora, por asignatura impartida,
mixto). El corazón flexible del módulo.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| expediente_laboral_id | bigint FK → expedientes_laborales | |
| modalidad_id | bigint FK → modalidades_percepcion (config) | fijo_mensual / por_hora / por_asignatura / mixto |
| monto_base | decimal(12,2) NULL | Sueldo fijo si aplica |
| tarifa_hora | decimal(10,2) NULL | Para pago por hora (liga a marcas_reloj) |
| tarifa_asignatura | decimal(12,2) NULL | Para pago por materia impartida |
| moneda | varchar(3) | MXN |
| vigente_desde | date | |
| vigente_hasta | date NULL | |

### `conceptos_nomina` (TENANT-CONFIG)  ← percepciones y deducciones
Catálogo configurable: sueldo, bono puntualidad, ISR, IMSS, préstamo, despensa... Cada uno
marcado como percepción o deducción, con su clave SAT para el CFDI de nómina.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| clave | varchar(30) | |
| nombre | varchar(150) | |
| naturaleza | varchar(15) | percepcion / deduccion |
| clave_sat | varchar(10) NULL | Catálogo SAT nómina 1.2 |
| es_gravable | boolean | |
| formula_id | bigint FK → formulas_nomina (config) NULL | Cálculo automático (ver abajo) |

### `periodos_nomina` (TENANT) y `recibos_nomina` (TENANT)
`periodos_nomina`: el corte (quincena/mes), con fechas y estado (abierto/calculado/timbrado/
pagado). Un periodo por campus o global.
`recibos_nomina`: el recibo de UN empleado en UN periodo (cabecera): total percepciones,
total deducciones, neto, UUID del CFDI timbrado, ruta del PDF/XML.
`recibo_conceptos`: cada línea del recibo — una fila por concepto aplicado (percepción o
deducción), con su importe. Relacional, nada de JSON. Aquí es donde un `UPDATE` corrige un
importe puntual.

| `recibo_conceptos` | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| recibo_nomina_id | bigint FK → recibos_nomina | |
| concepto_nomina_id | bigint FK → conceptos_nomina | |
| importe | decimal(12,2) | |
| cantidad | decimal(10,2) NULL | Horas/unidades si aplica |

### Integración con reloj checador (Módulo 6)
Para esquemas por hora o con bono de puntualidad, un job lee `marcas_reloj` del periodo,
calcula horas trabajadas / incidencias, y genera los `recibo_conceptos` correspondientes.
El pago docente por asignatura lee `docente_asignatura_grupo` (materias impartidas en el
periodo). Todo trazable con queries, sin cajas negras.

### Catálogos TENANT-CONFIG del módulo
`tipos_contrato`, `regimenes_fiscales`, `motivos_baja_laboral`, `situaciones_empleado`,
`puestos`, `modalidades_percepcion`, `conceptos_nomina`, `formulas_nomina` (definición
relacional de fórmulas: base, factor, tope — no un blob).

---

## Módulo 11 — Bolsa de trabajo y empleabilidad

Conecta egresados/alumnos con empleadores. Nuevo respecto al legacy. Pensado para escalar a
un portal público de vacantes por escuela, con seguimiento de colocación (indicador clave
para acreditaciones).

### `empresas` (TENANT)
Empleadores registrados. Una empresa puede publicar muchas vacantes.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| razon_social | varchar(255) | |
| rfc | varchar(13) NULL | |
| sector_id | bigint FK → sectores_economicos (config) | |
| tamano_id | bigint FK → tamanos_empresa (config) | micro/pequeña/mediana/grande |
| sitio_web | varchar(255) NULL | |
| persona_contacto_id | bigint FK → personas NULL | Reclutador (también es persona) |
| situacion_id | bigint FK → situaciones_empresa (config) | activa / en revisión / vetada |

`empresa_contactos`: contactos adicionales de la empresa (una fila por contacto).

### `vacantes` (TENANT)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| empresa_id | bigint FK → empresas | |
| titulo | varchar(200) | |
| descripcion | text | |
| modalidad_id | bigint FK → modalidades_trabajo (config) | presencial/remoto/híbrido |
| tipo_jornada_id | bigint FK → tipos_jornada (config) | tiempo completo/medio/prácticas |
| salario_min | decimal(12,2) NULL | |
| salario_max | decimal(12,2) NULL | |
| campus_id | bigint FK → campus NULL | Si la difunde un campus concreto |
| fecha_publicacion | date | |
| fecha_cierre | date NULL | |
| situacion_id | bigint FK → situaciones_vacante (config) | abierta / cerrada / pausada |

`vacante_carreras`: a qué carreras aplica la vacante (pivote, una fila por carrera) — permite
filtrar vacantes por el perfil del alumno.
`vacante_habilidades`: habilidades requeridas (pivote a `habilidades` config) — relacional,
para matching.

### `postulaciones` (TENANT)
La aplicación de una persona a una vacante, con su seguimiento.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| vacante_id | bigint FK → vacantes | |
| persona_id | bigint FK → personas | El postulante (alumno/egresado) |
| matricula_oferta_id | bigint FK → matricula_oferta NULL | Con qué perfil académico se postula |
| cv_ruta | varchar(500) NULL | CV subido (S3) |
| carta_presentacion | text NULL | |
| etapa_id | bigint FK → etapas_postulacion (config) | postulado/revisión/entrevista/oferta/contratado/rechazado |
| fecha_postulacion | datetime | |

`postulacion_bitacora`: historial de cambios de etapa (una fila por movimiento) — para medir
tiempos de colocación.

### `colocaciones` (TENANT)  ← indicador de egleabilidad
Cuando un postulante es contratado. Alimenta reportes de colocación por carrera/generación
(clave para acreditadoras).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| postulacion_id | bigint FK → postulaciones | |
| persona_id | bigint FK → personas | |
| empresa_id | bigint FK → empresas | |
| puesto | varchar(200) | |
| salario | decimal(12,2) NULL | |
| fecha_ingreso | date | |
| relacionado_con_carrera | boolean | Si el empleo corresponde a su formación |

### Catálogos TENANT-CONFIG del módulo
`sectores_economicos`, `tamanos_empresa`, `situaciones_empresa`, `modalidades_trabajo`,
`tipos_jornada`, `situaciones_vacante`, `habilidades`, `etapas_postulacion`.

---

## Módulo 12 — Movilidad e intercambios académicos

Gestiona intercambios entrantes y salientes con instituciones aliadas (nacionales e
internacionales), incluyendo convenios, convocatorias, postulaciones y revalidación de
materias cursadas fuera. Nuevo respecto al legacy y de alto valor para la internacionalización.

### `instituciones_aliadas` (TENANT)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | varchar(255) | |
| pais_id | bigint FK → paises (landlord) | |
| ciudad | varchar(120) NULL | |
| tipo_id | bigint FK → tipos_institucion (config) | universidad/tecnológico/centro |
| sitio_web | varchar(255) NULL | |

### `convenios` (TENANT)
Acuerdo con una institución aliada. Un convenio ampara muchas convocatorias.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| institucion_aliada_id | bigint FK → instituciones_aliadas | |
| tipo_convenio_id | bigint FK → tipos_convenio (config) | movilidad/doble titulación/investigación |
| folio | varchar(50) | |
| vigente_desde | date | |
| vigente_hasta | date NULL | |
| situacion_id | bigint FK → situaciones_convenio (config) | vigente/vencido/suspendido |

`convenio_carreras`: qué carreras cubre el convenio (pivote).

### `convocatorias_movilidad` (TENANT)
Una convocatoria abierta bajo un convenio, con cupos y requisitos.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| convenio_id | bigint FK → convenios | |
| titulo | varchar(200) | |
| direccion_id | bigint FK → direcciones_movilidad (config) | saliente / entrante |
| periodo | varchar(50) | Ciclo destino |
| cupo | smallint | |
| promedio_minimo | decimal(4,2) NULL | Requisito |
| fecha_apertura | date | |
| fecha_cierre | date | |

`convocatoria_requisitos`: requisitos documentales (una fila por requisito, liga a
`documentos_requeridos` del Módulo 4 — se reutiliza, no se duplica).

### `postulaciones_movilidad` (TENANT)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| convocatoria_id | bigint FK → convocatorias_movilidad | |
| matricula_oferta_id | bigint FK → matricula_oferta | El alumno saliente (con su oferta) |
| persona_externa_id | bigint FK → personas NULL | Para entrantes (persona no-alumno del sistema) |
| etapa_id | bigint FK → etapas_movilidad (config) | postulado/evaluación/aceptado/en curso/concluido/rechazado |
| promedio_acreditado | decimal(4,2) NULL | |
| fecha_postulacion | datetime | |

### `estancias` (TENANT) y `revalidaciones` (TENANT)
`estancias`: el periodo efectivo de intercambio de un postulante aceptado (institución,
fechas, estatus). 
`revalidaciones`: materias cursadas fuera y su equivalencia interna. Se apoya en el mecanismo
de `equivalencias` del Módulo 5 pero registra el origen externo.

| `revalidaciones` | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| estancia_id | bigint FK → estancias | |
| materia_externa | varchar(200) | Nombre en la institución destino |
| calificacion_externa | varchar(20) | Tal como la reporta el destino |
| plan_materia_id | bigint FK → plan_materias | La materia interna equivalente |
| calificacion_equivalente | decimal(5,2) | Convertida a la escala propia |
| dictamen_id | bigint FK → dictamenes_revalidacion (config) | aprobada/rechazada/parcial |

Al aprobarse, la revalidación asienta en `historial_academico` (Módulo 5) con bandera de
origen "movilidad". Trazable de punta a punta.

### Catálogos TENANT-CONFIG del módulo
`tipos_institucion`, `tipos_convenio`, `situaciones_convenio`, `direcciones_movilidad`,
`etapas_movilidad`, `dictamenes_revalidacion`.

---

## Módulo 13 — Portal de familiares (padres/tutores)

Capa de **solo lectura** para que padres o tutores familiares sigan el avance del alumno.
Es un rol de visualización, distinto del tutor académico (Módulo 5). Diseño con alcance por
columnas booleanas y exclusión estructural del LMS.

> **Tutor académico ≠ tutor familiar.** El tutor académico (Módulo 5) acompaña a un DOCENTE
> en lo académico y se ancla a `docente_asignatura_grupo`; no tiene parentesco con el alumno.
> El tutor familiar (este módulo) es un padre/tutor que VE información del alumno y nunca
> entra al LMS.

### `vinculos_familiares` (TENANT)
El vínculo entre un familiar (que también es `persona`) y el alumno en una oferta concreta.
Reemplaza el borrador `tutores_familiares` con nombre propio y alcance relacional.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| persona_id | bigint FK → personas | El familiar (también persona del sistema) |
| matricula_oferta_id | bigint FK → matricula_oferta | El alumno EN una oferta específica |
| parentesco_id | bigint FK → parentescos (config) | padre/madre/tutor legal/otro |
| es_contacto_emergencia | boolean | |
| es_responsable_pago | boolean | Si es a quien se le factura/cobra |
| ve_kardex | boolean DEFAULT true | |
| ve_pagos | boolean DEFAULT true | |
| ve_facturas | boolean DEFAULT true | |
| ve_asistencia | boolean DEFAULT true | Asistencia académica del alumno |
| ve_avisos | boolean DEFAULT true | |
| ve_lms | boolean DEFAULT false | SIEMPRE false; el LMS no se expone a familiares |

El alcance son columnas booleanas (consultables/filtrables directo). `ve_lms` es `false` por
defecto y el backend niega el acceso al LMS para este rol **independientemente de la columna**
(defensa en profundidad): no debe existir ruta que lo permita.

### `avisos_familiares` (TENANT)
Comunicados dirigidos a familiares (circulares, recordatorios de pago, citatorios). Una fila
por aviso; su segmentación (a quién va) se resuelve con `aviso_destinatarios` (pivote a
`vinculos_familiares`), relacional.

### `autorizaciones` (TENANT)  ← función nueva de valor
Permisos que un familiar concede o niega (salidas, uso de imagen, actividades). Cada
autorización es una fila con su respuesta y fecha — trazable para efectos legales.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| vinculo_familiar_id | bigint FK → vinculos_familiares | |
| tipo_autorizacion_id | bigint FK → tipos_autorizacion (config) | salida/uso_imagen/excursión... |
| concedida | boolean NULL | NULL = pendiente |
| fecha_respuesta | datetime NULL | |
| comentario | varchar(500) NULL | |

### Catálogos TENANT-CONFIG del módulo
`parentescos`, `tipos_autorizacion`.

---

## Nota de dependencias — Fase 4 (para Claude Code)

Orden de migraciones respetando FKs (las fases 0-3 van primero):

```
FASE 4:
  (10) tipos_contrato, regimenes_fiscales, motivos_baja_laboral, situaciones_empleado,
       puestos, modalidades_percepcion, conceptos_nomina, formulas_nomina  [config]
       → expedientes_laborales → adscripciones → esquemas_percepcion
       → periodos_nomina → recibos_nomina → recibo_conceptos
  (11) sectores_economicos, tamanos_empresa, situaciones_empresa, modalidades_trabajo,
       tipos_jornada, situaciones_vacante, habilidades, etapas_postulacion  [config]
       → empresas → empresa_contactos → vacantes → vacante_carreras, vacante_habilidades
       → postulaciones → postulacion_bitacora → colocaciones
  (12) tipos_institucion, tipos_convenio, situaciones_convenio, direcciones_movilidad,
       etapas_movilidad, dictamenes_revalidacion  [config]
       → instituciones_aliadas → convenios → convenio_carreras
       → convocatorias_movilidad → convocatoria_requisitos → postulaciones_movilidad
       → estancias → revalidaciones
  (13) parentescos, tipos_autorizacion  [config]
       → vinculos_familiares → avisos_familiares → aviso_destinatarios → autorizaciones
```

Todas las tablas TENANT llevan las columnas de auditoría y `deleted_at` (soft delete) de las
convenciones globales. Todos los módulos de Fase 4 se registran en `modulos` y se encienden
por escuela vía `modulos_activos`.
