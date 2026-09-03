# Servicio social, prácticas y estancias profesionales

Plan de diseño e implementación. Se escribió ANTES de tocar una tabla, que es
el paso que el pedido exige: recorrer el repositorio, documentar qué se
reutiliza y presentar el diseño.

**Avance: fases 1 y 2 hechas** (2026-09-03) — el módulo con sus ocho catálogos,
y el padrón de organizaciones con sus contactos, alcances, convenios versionados
y plazas. Las fases 3 a 8 están por construir; §9 lleva el detalle.

---

## 0. RECONOCIMIENTO: qué hay ya, y qué de eso se reutiliza

Lo primero es lo que **NO** hay que volver a construir.

### 0.1 Lo que ya existe y se REUSA tal cual

| Pieza | Dónde | Para qué sirve aquí |
|---|---|---|
| `MatriculaOferta` | `App\Models\Admisiones` | El titular del expediente. Persona + oferta + matrícula + `generacion` + `periodo_actual` + situación. |
| `Oferta` | `App\Models\Academico` | De aquí salen **programa académico, plan, campus y modalidad** — cuatro de los siete ejes de configuración que el pedido enumera. |
| `PlanEstudio` | `App\Models\Academico` | `total_creditos`, `minimo_asignaturas`, `tipo_periodo_id`, `total_periodos`, y el **nivel de estudios** vía su programa. |
| `HistorialDelAlumno` | `App\Services` | `resumen()` ya devuelve créditos aprobados, materias aprobadas y el promedio con la precisión del plan. **La elegibilidad por créditos y por materias se le pregunta a él**, no se recalcula. |
| `AcotaPorCampus` | `App\Http\Controllers\Concerns` | El alcance por campus, con la regla ya escrita: `null` = global, y *filtrar la lista no basta* — hay un método para la consulta y otro para autorizar el registro concreto. |
| `TieneAuditoria` + `$table->auditoria()` | `App\Models\Concerns` | `created_by`/`updated_by` + borrado lógico. Toda tabla del módulo lo lleva. |
| `AvisoParaElUsuario` | `App\Exceptions` | Rechazar con un motivo que la pantalla SÍ muestra. |
| `Aviso` + `avisos_destinos` + `AlcanceDeDestinos` | `App\Models\Plataforma` | Las notificaciones. Ya segmenta por nueve tipos de destino, incluido «personas señaladas una por una» y el modificador «y a sus familias». **No se construye un segundo sistema de avisos.** |
| `DocumentoRequerido` + `documento_ambitos` | `App\Models\Admisiones` | El catálogo de papeles con ámbito. Ver §5.3: se le agrega un ámbito, no se clona. |
| Disco `local` (privado) | `config/filesystems.php` | `storage_path('app/private')`, servido siempre por un controlador que comprueba quién pide. Nunca `public/`. |
| `RegistroReportes` + fuentes | `App\Reportes` | Los trece reportes del pedido son **fuentes y presets**, no pantallas nuevas. |
| `Schedule::command` | `routes/console.php` | Las alertas van como comando programado, con la cola ya atendida por el despachador. |
| `Ajustes` / `CatalogoAjustes` | `App\Configuracion` | Sólo para lo que es **de la escuela entera** (p. ej. si se pide consentimiento de ubicación). Las reglas por programa NO van aquí — ver §3.2. |
| `ResolutorPlanCobro` | `App\Services` | **El molde de resolución por especificidad** que este módulo copia: gana el más específico vigente (oferta → plan → programa → global). |

### 0.2 Lo que existe con un nombre parecido y NO se reutiliza (con su razón)

| Pieza | Por qué NO |
|---|---|
| **`titulo_servicio_social`** (`App\Models\Emision`) | Es la ATESTACIÓN para el XML del título: dos columnas —`cumplio_servicio_social` y `fundamento_legal_ss_id`— que alimentan el nodo `Expedicion`. No es la gestión del servicio social: no tiene horas, ni organización, ni bitácora. **Y no se toca**, por instrucción explícita del pedido. Ver §5.1 para cómo se relacionan sin modificarla. |
| **`empresas`** (`App\Models\Bolsa`) | Es el padrón de **EMPLEADORES** de la bolsa de trabajo: vive detrás del módulo `bolsa_trabajo` —una escuela que lo apague se quedaría sin sus organizaciones receptoras— y su situación incluye «vetada», que es un concepto de contratación. Una receptora de servicio social suele ser una dependencia de gobierno, un hospital, una escuela o una asociación civil, que no son empleadores de ese padrón. **Es el mismo argumento que este proyecto ya escribió** para los convenios de descuento. |
| **`instituciones_aliadas`** + `convenios` (`App\Models\Movilidad`) | Son instituciones **académicas** aliadas para intercambio, detrás del módulo `movilidad`. Su `convenios` sí es el molde de forma —fechas, situación, `documento_ruta`, pivote de programas, `estaVencido()` distinto de `estaVigente()`— y se **copia el patrón**, no la tabla. |
| **`estancias`** (`App\Models\Movilidad`) | Ya existe con ese nombre y es **otra cosa**: el periodo efectivo de un intercambio académico, del que cuelga la revalidación de materias. La «estancia profesional» del pedido es un tipo de proceso formativo. Para no crear dos verdades sobre la palabra, aquí el tipo se llama **estancia profesional** y la tabla no se llama `estancias`. |
| **`solicitudes_servicio`** (`App\Models\Finanzas`) | Es el mostrador de TRÁMITES con costo (constancias, credenciales). Comparte la palabra «solicitud» y nada más. |
| `docs/especificacion-esquema.md` §Módulo 9 | La spec **sí** trae una tabla `servicio_social`, y es la mínima: matrícula, cumplimiento, fundamento legal, institución, horas y fechas. Es la que se implementó como `titulo_servicio_social`. **La spec nunca diseñó un módulo de gestión**, así que esto es dominio nuevo —como lo fueron Disciplina y Movimientos escolares— y no una re-interpretación de la spec. |

### 0.3 Convenciones que este módulo respeta

- Tablas en `snake_case` plural en español; toda tabla del tenant lleva
  `$table->auditoria()` y su modelo el trait `TieneAuditoria`.
- Catálogos TENANT-CONFIG con seeder y **banderas de comportamiento**, nunca
  claves cableadas: la regla se lee por `cuenta_como_liberacion`, no por
  `clave === 'liberado'`.
- Autorización con el `can:` de Laravel, **nunca** el `permission:` de Spatie.
- Un permiso pertenece a una **faceta** (`CatalogoPermisos`), y el alcance
  operativo lo pone la ASIGNACIÓN, no el permiso.
- Sin FK cruzadas tenant → landlord.
- Un estado nuevo se agrega barato **si el motor filtra por lista blanca**
  (lección de `adeudos.en_convenio`).
- El nombre de una tabla se pregunta, no se adivina.

---

## 1. MODELO FUNCIONAL

### 1.1 De qué se trata

Una escuela obliga a sus alumnos a acreditar **procesos formativos fuera del
aula** antes de titularse: servicio social, prácticas profesionales, residencia,
estancia, internado. Cada uno tiene sus propias reglas, su expediente y su
constancia, y **cada escuela las define distinto** — un tecnológico pide 500
horas de residencia con 80 % de créditos; una normal pide servicio social de dos
años; una universidad privada pide 480 horas de prácticas a partir del séptimo
semestre.

Hoy Acadion no tiene nada de esto: sólo la casilla «cumplió servicio social»
que se captura a mano para el título. Quien lleva la oficina de vinculación
trabaja en Excel.

### 1.2 Los cinco actos que el módulo tiene que soportar

1. **Configurar** qué exige cada programa/plan, con versión histórica.
2. **Registrar** con quién se puede hacer (organizaciones, convenios, plazas).
3. **Solicitar y aprobar**: el alumno pide, la escuela revisa y asigna.
4. **Acumular**: horas, informes y evaluaciones, con aprobación.
5. **Liberar**: comprobar todo, emitir un folio único y congelarlo.

### 1.3 Dónde vive en el menú

**Sección propia de primer nivel, NO dentro de «Alumnos».** El pedido lo
permite («a menos de que lo consideres mejor afuera») y aquí es mejor por dos
razones medidas:

- **Tiene tres oficios con facetas distintas.** «Alumnos» es una sección de
  faceta `administrativo`; este módulo necesita además una entrada del ALUMNO
  (`/mi-servicio-social`) y otra del SUPERVISOR EXTERNO. Es exactamente lo que
  obligó a sacar «Mis vacantes» de «Alumnos» cuando se reorganizó la bolsa.
- **Por tamaño.** Son nueve pantallas administrativas. «Alumnos» tiene hoy
  cuatro entradas de primer nivel; meterle un subgrupo de nueve hojas repetiría
  lo que se acaba de corregir en Finanzas.

Etiqueta visible: **«Servicio social y prácticas»** — es como se llama la
oficina. Clave del módulo y del espacio de nombres: `procesos_formativos`,
porque el catálogo de tipos incluye seis más y la etiqueta no puede enumerarlos.

```
Servicio social y prácticas          (faceta administrativa, modulo: procesos_formativos)
├── Expedientes                      /procesos/expedientes
├── Solicitudes                      /procesos/solicitudes
├── Horas por aprobar                /procesos/horas
├── Organizaciones                   /procesos/organizaciones
│   ├── Padrón                       /procesos/organizaciones
│   ├── Convenios                    /procesos/convenios
│   └── Plazas y proyectos           /procesos/plazas
└── Configuración                    /procesos/configuracion
    ├── Tipos de proceso             /procesos/configuracion/tipos
    ├── Reglas por programa          /procesos/configuracion/reglas
    └── Liberaciones                 /procesos/liberaciones

Mi servicio social                   (faceta alumno, mismo módulo)
└── Mi proceso                       /mi-servicio-social

Estudiantes a mi cargo               (faceta supervisor externo — fase 4)
└── Mis practicantes                 /mis-practicantes
```

---

## 2. MÁQUINA DE ESTADOS

### 2.1 Los estados, y por qué son DOCE y no diecisiete

El pedido lista diecisiete «como referencia». Cinco no son estados y
guardarlos crearía una segunda verdad que envejece sola:

| Del pedido | Qué se hace | Por qué |
|---|---|---|
| `no_elegible`, `elegible` | **Se CALCULAN**, no se guardan. | La elegibilidad depende de créditos, materias y adeudos, que cambian solos: guardada, un alumno que aprueba una materia seguiría marcado «no elegible» hasta que algo la recalculara. Es el error del promedio que este proyecto ya corrigió tres veces. Y además: la elegibilidad existe **antes** de que haya expediente, así que no cabe en una columna del expediente. |
| `pendiente_informe_final`, `pendiente_evaluacion`, `pendiente_liberacion` | **Un solo estado `concluido`**, con la lista de impedimentos explicando qué falta. | Los tres significan «terminó el campo y le falta papeleo», y son mutuamente dependientes: quien esté en `pendiente_evaluacion` sigue debiendo el informe si lo entregó tarde. Con tres estados alguien tiene que moverlos en sincronía, y el día que se desincronicen el expediente dirá una cosa y los requisitos otra. `impedimentosParaLiberar()` contesta lo mismo, siempre al día y **con la razón escrita**. |
| `pendiente_asignacion` | Es `aprobado`. | «Aprobado» ya significa «puede hacerlo y todavía no tiene dónde». Dos nombres para un estado se acaban usando como si fueran distintos. |

Quedan **doce**, cada uno con una consecuencia propia:

```
                    ┌─────────────┐
                    │  borrador   │  el alumno arma su solicitud
                    └──────┬──────┘
                           │ enviar
                    ┌──────▼──────┐
              ┌─────┤  solicitado │◄────────┐
              │     └──────┬──────┘         │ corregir y reenviar
              │            │ tomar          │
              │     ┌──────▼──────┐         │
              │     │ en_revision ├─────────┤
              │     └──┬───┬───┬──┘   requiere_correccion
              │        │   │   │
       cancelar        │   │   └──── rechazar ──► rechazado  (terminal)
              │        │   │
              │        │   └──────── aprobar ──►┌──────────┐
              │        │                        │ aprobado │
              │        │                        └────┬─────┘
              │        │                             │ asignar (organización + plaza + fechas)
              │        │                        ┌────▼─────┐
              │        │                        │ asignado │
              │        │                        └────┬─────┘
              │        │                             │ iniciar
              │        │                        ┌────▼─────┐   suspender  ┌────────────┐
              │        │                        │ en_curso ├─────────────►│ suspendido │
              │        │                        └────┬─────┘◄─────────────┴────────────┘
              │        │                             │ concluir        reanudar
              │        │                        ┌────▼──────┐
              │        │                        │ concluido │
              │        │                        └────┬──────┘
              │        │                             │ liberar (atómica, ver §2.4)
              │        │                        ┌────▼─────┐
              └────────┴────────────────────────►│ liberado │  (terminal, inmutable)
                       cancelar                  └──────────┘
                          │
                    ┌─────▼─────┐
                    │ cancelado │  (terminal)
                    └───────────┘
```

### 2.2 Reglas de toda transición

Ninguna se hace con `update()`. Todas pasan por
`App\Services\ProcesosFormativos\TransicionDeExpediente`, que:

1. **Valida el estado anterior** contra una tabla de transiciones permitidas.
   Un destino que no cuelgue del origen se rehúsa con su motivo — no se
   «corrige» al estado más cercano.
2. **Valida el permiso** del acto (no el de la pantalla).
3. **Valida el alcance por campus** del expediente contra el del rol.
4. **Escribe la fila de bitácora** (`expediente_transiciones`): origen,
   destino, usuario, fecha, motivo e IP.
5. **Corre dentro de una transacción**, con `lockForUpdate()` sobre el
   expediente: dos revisores aprobando a la vez no producen dos aprobaciones.
6. **Es idempotente donde puede repetirse**: pedir el estado en el que ya se
   está no hace nada y no anota — como en `Postulador::mover`, donde volver a
   poner la misma etapa inflaba la bitácora con renglones de cero días.
7. **Los eventos y avisos salen DESPUÉS del commit** (`DB::afterCommit`): un
   aviso emitido dentro de la transacción anuncia algo que puede no haber
   ocurrido.

### 2.3 Motivos obligatorios

`rechazado`, `requiere_correccion`, `suspendido` y `cancelado` **exigen
motivo**. Sin él, quien lo recibe no sabe qué corregir y vuelve a mandar lo
mismo — y dentro de un año nadie puede explicar por qué se canceló.

### 2.4 La liberación es aparte

No es «una transición más». Es la única que emite un documento, así que va en
su propio servicio (`LiberadorDeExpediente`) con folio único, snapshot
congelado e inmutabilidad. Ver §5.4.

---

## 3. MODELO DE DATOS

### 3.1 Catálogos (TENANT-CONFIG, con seeder y banderas)

**`tipos_proceso_formativo`** — servicio social, prácticas profesionales,
residencia profesional, estancia profesional, internado, proyecto comunitario,
experiencia profesional, otro. Ocho sembrados, **borrables y ampliables**.

| Columna | Notas |
|---|---|
| `clave`, `nombre`, `descripcion`, `orden`, `activo` | |
| `exige_organizacion` | Un proyecto comunitario propio de la escuela puede no tener receptora. |
| `exige_plaza` | Si el alumno elige de un catálogo publicado o propone. |
| `permite_organizacion_propuesta` | Si el alumno puede registrar la suya. |
| `cuenta_horas` | Una «experiencia profesional» acreditada por constancia no lleva bitácora de horas. |

> **Las banderas, no la clave.** Es la lección de `entra_a_nomina` y
> `cuenta_como_egresado`: preguntar por `clave === 'servicio_social'` funciona
> hoy y deja de funcionar en silencio el día que la escuela edite su catálogo.

Además: `sectores_organizacion`, `tipos_organizacion`, `situaciones_organizacion`,
`tipos_convenio_formativo`, **`situaciones_convenio_formativo`** (que este
esbozo no tenía y hizo falta en la fase 2: la bandera `ampara_asignaciones` es
lo que decide si bajo ese convenio se le puede seguir mandando gente),
`modalidades_proceso` (presencial/mixta/remota) y `tipos_informe`.

### 3.2 La configuración VERSIONADA — el corazón del módulo

**Por qué es una tabla y no `CatalogoAjustes`**: los ajustes son de la escuela
entera y tienen un solo valor; aquí hace falta un valor **por combinación de
programa, plan, campus, modalidad, generación y vigencia**, y hace falta
conservar el histórico. Un ajuste no puede hacer ninguna de las dos cosas.

**`reglas_proceso`** — el ALCANCE de una regla (a quién aplica).

| Columna | Notas |
|---|---|
| `tipo_proceso_id` | Obligatorio: una regla siempre es de un tipo. |
| `campus_id`, `nivel_estudios_id`, `programa_academico_id`, `plan_id` | **Todos nullable.** Null = «cualquiera». |
| `modalidad` | nullable. |
| `generacion_desde`, `generacion_hasta` | nullable. Un plan cambia de reglas por generación sin cambiar de plan. |
| `vigente_desde`, `vigente_hasta` | nullable el segundo. |
| `activa` | |

**`reglas_proceso_versiones`** — el CONTENIDO, versionado.

| Columna | Notas |
|---|---|
| `regla_id`, `version` (int), `vigente_desde` | Único `(regla_id, version)`. |
| `obligatorio` | Obligatorio u optativo para ese programa. |
| `horas_requeridas`, `tolerancia_horas` | |
| `porcentaje_creditos_minimo`, `periodo_minimo` | |
| `dias_ventana_solicitud_desde/hasta` | La ventana en que se puede solicitar. |
| `plazo_maximo_dias` | Desde el inicio hasta que hay que concluir. |
| `max_horas_dia`, `max_horas_semana` | |
| `exige_seguro`, `exige_convenio_vigente`, `exige_no_adeudo` | |
| `exige_aprobacion_coordinador` | |
| `informes_parciales` (int), `periodicidad_informe_dias` | |
| `exige_informe_final`, `exige_evaluacion_supervisor`, `exige_evaluacion_estudiante` | |
| `exige_carta_aceptacion`, `exige_carta_termino`, `emite_constancia` | |
| `cuenta_para_titulacion` | Ver §5.1. |
| `notas` | |

**Tablas hijas de la versión** (lo que es LISTA no cabe en una columna):

- `regla_documentos` → `documento_id`, `momento` (solicitud / durante /
  liberación), `obligatorio`, `dias_vigencia`.
- `regla_materias_previas` → `plan_materia_id`.
- `regla_situaciones_permitidas` → `situacion_alumno_id`.

**Resolución**: `ResolutorDeRegla` devuelve la versión aplicable a una
`MatriculaOferta` y un tipo, con **el más específico vigente** —plan → programa
→ nivel → campus → global—, contando cuántos ejes concretos casan. Es el mismo
criterio que `ResolutorPlanCobro`, escrito una vez.

**Congelamiento**: `expedientes_proceso.regla_version_id` se escribe al ABRIR
el expediente y no se vuelve a mirar. Cambiar la configuración mañana **no
altera** un expediente en curso ni uno liberado. Mismo criterio que
`esquema_evaluacion` materializado, el emisor congelado en la factura y
`factura_iedu`.

### 3.3 Organizaciones y convenios

**`organizaciones_receptoras`**: `razon_social`, `nombre_comercial`, `rfc`
(opcional, único cuando existe), `sector_id`, `tipo_id`, domicilio,
`representante`, `sitio_web`, `situacion_id`, `cupo_total`, `notas`, `activa`.

**`organizacion_contactos`**: nombre, cargo, correo, teléfono, `es_principal`,
`es_supervisor`, `persona_id` **nullable** —sólo si además tiene cuenta—.

**`organizacion_alcances`**: pivote (organización, campus?, programa?, tipo de
proceso?) — «esta organización está autorizada para prácticas de Enfermería en
el campus Norte». Sin filas = autorizada para todo.

**`convenios_formativos`**: `organizacion_id`, `folio`, `tipo_convenio_id`,
`vigente_desde`, `vigente_hasta`, `situacion_id`, `documento_ruta`, `version`,
`convenio_anterior_id`. Versionado: renovar **crea otra fila** que apunta a la
anterior; no se edita la vieja.

- `estaVencido()` (fecha) es distinto de `estaVigente()` (fecha **y**
  situación), como en `Convenio` de movilidad.
- Con convenio vencido **no se asigna**, salvo excepción autorizada y auditada
  (§4.3).

**`plazas_proceso`**: organización, tipo de proceso, nombre, descripción,
actividades, modalidad, ubicación, horario, `cupo`, `cupo_ocupado`,
`fecha_inicio`, `fecha_cierre`, `duracion_estimada_horas`, `apoyo_economico`,
`requisitos`, `responsable`, `situacion`. Pivote `plaza_programas`.

> **El cupo se protege con la BASE, no con un `SELECT` previo.** Dos alumnos
> aceptando la última plaza a la vez pasan los dos un conteo previo. Se hace con
> `lockForUpdate()` sobre la plaza dentro de la transacción de la asignación, y
> además un CHECK `cupo_ocupado <= cupo`. Es la lección del apartado de licencia
> de las clases en línea.

### 3.4 El expediente

**`expedientes_proceso`** — uno por (matrícula, tipo de proceso).

| Columna | Notas |
|---|---|
| `matricula_oferta_id` | **El titular es la MATRÍCULA, no la persona.** Quien estudia dos programas hace dos servicios sociales. |
| `tipo_proceso_id`, `regla_version_id` | La configuración congelada. |
| `estado` | Los doce de §2.1. |
| `organizacion_id`, `plaza_id`, `contacto_supervisor_id` | nullable hasta `asignado`. |
| `responsable_interno_id` | persona del coordinador asignado. |
| `fecha_solicitud`, `fecha_aprobacion`, `fecha_inicio`, `fecha_fin_programada`, `fecha_conclusion` | |
| `horas_requeridas` | **Copiadas de la versión** al abrir: la regla puede tener excepción autorizada para este alumno. |
| `horas_aprobadas` | Derivada y recalculada; nunca se cree lo que manda el navegador. |
| `motivo_estado` | El del último rechazo/suspensión/cancelación. |
| `organizacion_propuesta` | JSON, cuando el alumno propone una que aún no existe. |

Único: `(matricula_oferta_id, tipo_proceso_id)` **sobre los no cancelados** —
con una columna generada, como `reglas_recordatorio_cobranza.dias_si_vive`:
MySQL considera distintos dos NULL, así que un único pelado impediría volver a
solicitar tras una cancelación.

**`expediente_transiciones`**: expediente, estado origen (nullable en el alta),
destino, motivo, `usuario_id`, `ip`, `created_at`. Append-only.

**`expediente_documentos`**: expediente, `documento_id`, `momento`, ruta,
`estado_documento_id`, `vigencia`, observaciones. *(El nombre real se ajusta:
ya existe una `expediente_documentos` de admisiones — ver §7.)*

### 3.5 Horas

**`bitacora_horas`**: expediente, `fecha`, `hora_inicio`, `hora_fin`,
`minutos_descanso`, `minutos_totales` (calculado en el servidor),
`actividad`, `modalidad`, `evidencia_ruta`, `latitud`/`longitud` nullable,
`estado` (`capturada`/`aprobada`/`rechazada`), `capturada_por`, `aprobada_por`,
`aprobada_en`, `motivo_rechazo`.

Reglas, todas del servidor:

- **Sin traslape** con otra fila viva del mismo expediente: se comparan
  `inicio` y `fin`, las dos condiciones —una jornada de 9 a 13 y otra de 10 a 11
  no comparten hora de arranque y chocan igual—.
- Dentro de `[fecha_inicio, fecha_fin_programada]` de la asignación.
- `hora_fin > hora_inicio`, descanso menor que el total, minutos > 0.
- Tope diario y semanal de la versión de la regla.
- **`horas_aprobadas` se recalcula sumando lo APROBADO**, nunca se incrementa:
  un contador que se suma se desincroniza con la primera corrección.
- **Doble aprobación imposible**: el `update` va condicionado a
  `where('estado', 'capturada')`, como la firma de las becas — el guard en
  memoria lo pasan dos peticiones simultáneas.
- Lo rechazado **se conserva** con su motivo.

**Geolocalización**: apagada por omisión. Si la escuela la enciende
(`procesos.pedir_ubicacion`), la pantalla pide consentimiento y las coordenadas
se purgan a los N días por un comando. Nunca obligatoria.

### 3.6 Informes y evaluaciones

- **`informes_proceso`**: expediente, `tipo_informe_id`, `numero`,
  `fecha_limite`, `entregado_en`, `archivo_ruta`, `estado`,
  `retroalimentacion`, `revisado_por`.
- **`evaluaciones_proceso`**: expediente, `origen` (supervisor / estudiante /
  coordinador), `rubrica_id` **nullable reusando `rubricas`**, `puntaje`,
  `respuestas` JSON, `firmada_en`, `archivo_ruta`.

> Las **rúbricas ya existen** (`/rubricas`, ámbito plataforma). La evaluación
> del supervisor apunta a una rúbrica de la escuela en vez de estrenar un
> segundo motor de criterios y niveles.

### 3.7 Liberación

**`liberaciones_proceso`**: expediente, `folio` (único), `liberado_en`,
`liberado_por`, `horas_acreditadas`, `snapshot` JSON, `constancia_ruta`,
`liberacion_corregida_id` nullable, `motivo_correccion`.

- El folio sale de `contadores_liberacion` con incremento atómico y **sin `id`
  autoincremental** — la trampa documentada de `contadores_matricula`.
- El `snapshot` congela: regla aplicada y su versión, horas, organización,
  convenio, documentos, informes y evaluaciones al momento.
- **Corregir NO edita**: se emite una liberación nueva que apunta a la
  anterior, y las dos se conservan. Es el molde del **acta de corrección**.

---

## 4. MATRIZ DE PERMISOS

Dominio `Servicio social y prácticas` en `CatalogoPermisos`.

> **Se declaran POR FASE, no de golpe.** Un permiso sin una ruta que lo
> compruebe se palomea en `/plataforma/roles` creyendo que concede algo, y este
> proyecto ya tuvo que retirar dos así (`ver-personas`, `crear-personas`). La
> fase 1 dejó los dos primeros —`configurar-procesos-formativos` y
> `ver-procesos-formativos`—; el resto llega con la ruta que los lee.

| Permiso | Faceta | Qué abre |
|---|---|---|
| `configurar-procesos-formativos` | administrativo | Tipos, reglas y sus versiones. |
| `gestionar-organizaciones-receptoras` | administrativo | Padrón y contactos. |
| `gestionar-convenios-formativos` | administrativo | Convenios y sus renovaciones. |
| `gestionar-plazas-formativas` | administrativo | Plazas y proyectos. |
| `revisar-solicitudes-formativas` | administrativo | Tomar, pedir corrección, aprobar, rechazar, asignar. |
| `aprobar-excepciones-formativas` | administrativo | Saltarse un requisito, con motivo. **Aparte** del anterior. |
| `revisar-horas-formativas` | administrativo | Ver y rechazar horas. |
| `aprobar-horas-formativas` | administrativo | Aprobarlas. |
| `revisar-informes-formativos` | administrativo | Informes y evaluaciones. |
| `liberar-expedientes-formativos` | administrativo | La liberación. |
| `corregir-liberacion-formativa` | administrativo | La corrección formal. |
| `ver-procesos-formativos` | administrativo | Sólo lectura, para auditoría y dirección. |
| `ver-mi-proceso-formativo` | alumno | Su portal. |
| `ver-proceso-de-mi-hijo` | padre_familia | Sólo lectura, si la escuela lo enciende. |
| `ver-proceso-de-mi-tutorado` | tutor_educativo | Sólo lectura. |
| `supervisar-practicantes` | *supervisor externo* (fase 4) | Sus asignados y nada más. |

**Tres separaciones deliberadas:**

1. **Revisar horas ≠ aprobarlas.** Quien captura en ventanilla no es quien
   valida el cumplimiento.
2. **Aprobar solicitudes ≠ aprobar excepciones.** La excepción es un acto de
   dirección, y con un solo permiso quien revisa a diario podría saltarse
   cualquier requisito.
3. **Liberar ≠ corregir una liberación.** Emitir es rutina; enmendar lo ya
   emitido es excepción, igual que en los movimientos escolares.

**El permiso nunca ignora el alcance.** Todo listado y todo registro concreto
pasan por `AcotaPorCampus`, y además por el **alcance por programa** cuando el
rol lo tenga acotado. El supervisor externo se acota por ASIGNACIÓN
(`expedientes_proceso.contacto_supervisor_id`), no por permiso — misma regla
que el docente con sus materias.

---

## 5. INTEGRACIONES

### 5.1 Con titulación y certificación — **sin tocarlas**

El pedido dice dos cosas a la vez: «integra con certificación y titulación» y
«no modifiques esos procesos». Se resuelve construyendo **el lado que
pregunta** y dejando **el lado que consume sin cablear**:

`App\Services\ProcesosFormativos\RequisitoFormativo` expone:

```php
public function exigeElPlan(MatriculaOferta $m, string $tipoClave): bool;
public function expedienteQueLoSatisface(MatriculaOferta $m, string $tipoClave): ?ExpedienteProceso;
public function estaLiberado(MatriculaOferta $m, string $tipoClave): bool;
public function constanciaDe(MatriculaOferta $m, string $tipoClave): ?array; // folio, fecha, versión de regla
public function impedimentos(MatriculaOferta $m, string $tipoClave): array;
```

- **NO se agrega un `if` a `EstadoCertificacion` ni a `ValidadorTitulo`**, ni se
  toca `titulo_servicio_social`. Queda escrito que engancharlo es una línea el
  día que la escuela lo pida, y que hacerlo **antes** cambiaría el criterio con
  el que hoy se timbran títulos.
- **Nunca se marca liberado por horas.** La liberación es un acto con permiso,
  folio y snapshot; alcanzar las horas sólo quita un impedimento.

### 5.2 Con avisos

Se reusa `Aviso` + `avisos_destinos`. Cada aviso lleva una **clave
idempotente** (`expediente:{id}:{evento}:{referencia}`) con índice único, para
que el comando nocturno no repita el mismo recordatorio cada madrugada.

### 5.3 Con documentos

`DocumentoRequerido` gana el ámbito `proceso_formativo`. **No se clona el
catálogo**: es el mismo papel («comprobante de seguro facultativo») que ya sabe
tener vigencia y estados. Los documentos del expediente van a su propia tabla,
como `documentos_alumno` y `documentos_docente` son tres tablas con la misma
forma y a propósito.

### 5.4 Con finanzas

`exige_no_adeudo` consulta `BitacoraSituacionFinanciera::vigenteDe()` y la
bandera `situaciones_pago.bloquea` — **el mismo camino** que
`ValidadorInscripcion::adeudoBloqueante`, no una consulta nueva a `adeudos`.

### 5.5 Con reportes

Trece reportes = **tres fuentes** (`ExpedientesFormativos`, `HorasFormativas`,
`ConveniosFormativos`) con presets encima. Cada fuente declara su `Recorte` por
campus vía la oferta.

### 5.6 Con el historial académico

La elegibilidad por créditos y materias le pregunta a `HistorialDelAlumno`.
Reimplementarla daría un porcentaje distinto del que el alumno ve en
`/mi-historial`.

---

## 6. MIGRACIONES PROPUESTAS

Nueve migraciones, en este orden (cada una comprueba antes de actuar, **pieza
por pieza y no por bloque** — la lección del CHECK de movilidad):

1. `procesos_formativos_registra_el_modulo` — fila en `modulos` + encendido en
   `modulos_activos`, y el ámbito nuevo de `DocumentoRequerido`.
2. `procesos_formativos_catalogos` — los siete catálogos con su seeder.
3. `procesos_formativos_organizaciones` — organizaciones, contactos, alcances.
4. `procesos_formativos_convenios` — convenios versionados.
5. `procesos_formativos_plazas` — plazas y su pivote de programas, con el CHECK
   de cupo.
6. `procesos_formativos_reglas` — reglas, versiones y sus tres tablas hijas.
7. `procesos_formativos_expedientes` — expedientes, transiciones, documentos,
   con la columna generada del único.
8. `procesos_formativos_horas_informes` — bitácora, informes, evaluaciones.
9. `procesos_formativos_liberaciones` — liberaciones y su contador **sin `id`**.

Índices desde el principio en `(estado, tipo_proceso_id)`,
`(matricula_oferta_id)`, `(organizacion_id, estado)`, `(expediente_id, fecha)` y
`(vigente_hasta)` de convenios. **No se indexa una foránea «por si acaso»**.

---

## 7. CASOS LÍMITE

1. **Dos programas.** Persona con dos matrículas hace dos expedientes
   independientes. Titular = matrícula.
2. **`expediente_documentos` YA EXISTE** (admisiones). La tabla nueva se llama
   `documentos_expediente_formativo`. *El nombre se pregunta, no se adivina.*
3. **Cupo concurrente**: `lockForUpdate()` + CHECK.
4. **Liberación concurrente**: `lockForUpdate()` sobre el expediente + único
   sobre `expediente_id` en `liberaciones_proceso` **vivas**.
5. **Convenio que vence a mitad del proceso**: no interrumpe lo asignado —el
   alumno no tiene la culpa— pero bloquea asignaciones NUEVAS y dispara alerta.
6. **Documento vencido al liberar**: impedimento explicable, no un fallo.
7. **Regla que cambia con expedientes en curso**: no los toca. Los nuevos toman
   la versión nueva.
8. **Sin regla aplicable**: el alumno **no es elegible** y el motivo lo dice —
   «tu programa no tiene configurado el servicio social». Falla CERRADO.
9. **Alumno dado de baja a mitad**: el expediente se suspende; no se cancela
   solo, porque reingresar es normal.
10. **Horas fuera de la asignación** (antes de iniciar o después de concluir):
    se rechazan con su fecha en el mensaje.
11. **Traslape con corrección**: al editar una fila rechazada se revalida el
    traslape contra las vivas, excluyéndose a sí misma.
12. **Excepción autorizada**: se guarda en el expediente con permiso, motivo y
    auditoría; el impedimento desaparece **nombrando quién lo excepcionó**.
13. **Organización propuesta por el alumno**: entra como JSON; sólo al
    autorizarla se crea la fila del padrón. Un padrón que cualquiera engorda
    deja de servir.
14. **Tipo de proceso apagado** con expedientes vivos: se apaga para nuevos, los
    vivos siguen.
15. **Módulo apagado**: 404 en todas las rutas y la sección fuera del menú.

---

## 8. PLAN DE PRUEBAS

Suites en `scripts/` contra la base real con `DB::rollBack()`, y **cada regla
comprobada mutándola**.

| Suite | Qué vigila |
|---|---|
| `prueba-procesos-configuracion` | Resolución por especificidad; congelamiento de versión; una regla nueva no toca lo en curso. |
| `prueba-procesos-elegibilidad` | Motivos concretos; falla cerrado sin regla; créditos vía `HistorialDelAlumno`; adeudo bloqueante. |
| `prueba-procesos-estados` | Las doce transiciones; estados imposibles rehusados; idempotencia; motivo obligatorio; bitácora con IP. |
| `prueba-procesos-horas` | Traslape, límites diario/semanal, fuera de rango, negativas, doble aprobación concurrente, recálculo determinista, rechazadas no cuentan. |
| `prueba-procesos-cupo` | Dos asignaciones simultáneas a la última plaza: una gana. |
| `prueba-procesos-liberacion` | Liberación incompleta rehusada con lista; completa con folio único; concurrente; corrección formal; inmutabilidad. |
| `prueba-procesos-permisos` | Campus, programa, supervisor externo acotado, archivos privados, ids del frontend. |
| `prueba-procesos-titulacion` | La interfaz contesta sin duplicar lógica y **sin tocar** `ValidadorTitulo`. |
| `prueba-procesos-avisos` | Idempotencia por clave; el comando dos veces no duplica. |
| `prueba-procesos-reportes` | Las tres fuentes, paginación y recorte. |

Y en `tests/`: aislamiento de tenant y jobs en el tenant correcto (eso necesita
el `TenantTestCase`, no un script).

---

## 9. IMPLEMENTACIÓN POR FASES

Cada fase es entregable y verificable sola. Ninguna es andamio de la siguiente.

| # | Fase | Qué entrega |
|---|---|---|
| ~~**1**~~ | ~~**Cimientos y catálogos**~~ **HECHA** | Módulo `procesos_formativos` encendido, sección propia en el menú, los siete catálogos con seeder y su pantalla. Comprobado: apagar el módulo lo apaga de verdad; un tipo inventado desde pantalla funciona igual que los de fábrica; lo que algo usa no se borra ni se apaga. `scripts/prueba-procesos-catalogos.php`, 31 verificaciones. |
| ~~**2**~~ | ~~**Organizaciones, convenios y plazas**~~ **HECHA** | Padrón institucional con contactos y alcances, convenios versionados que distinguen vencido de suspendido, plazas con el cupo protegido por CHECK. `scripts/prueba-procesos-organizaciones.php`, 52 verificaciones, 27 mutaciones. |
| ~~**3**~~ | ~~**Reglas versionadas + elegibilidad**~~ **HECHA** | El resolutor con su jerarquía lexicográfica, versiones que congelan lo publicado y `/mi-servicio-social` contestando **qué falta y por qué**, con sus números. Falla cerrado: sin regla nadie es elegible, y lo dice con esas palabras. `scripts/prueba-procesos-reglas.php`, 64 verificaciones, 34 mutaciones. |
| ~~**4**~~ | ~~**Solicitud, revisión y asignación**~~ **HECHA** | La máquina de estados completa en un solo servicio —origen, permiso, alcance, bitácora y bloqueo—, el portal del alumno con sus papeles, la bandeja de revisión, la asignación con el cupo protegido por bloqueo y CHECK, y las excepciones con su motivo y su firma. `scripts/prueba-procesos-expedientes.php`, 80 verificaciones, 42 mutaciones. |
| ~~**5**~~ | ~~**Horas, informes y evaluaciones**~~ **HECHA** | La bitácora con sus siete reglas —traslape, rango, topes diario y semanal, doble revisión imposible— y los minutos calculados por MySQL; informes programados al asignar, con su fecha límite y su retroalimentación; evaluaciones sobre las rúbricas del LMS, con el puntaje calculado por el servidor y lo respondido congelado. `scripts/prueba-procesos-horas.php`, 77 verificaciones, 47 mutaciones. |
| ~~**6**~~ | ~~**Liberación e integración**~~ **HECHA** | Folio atómico por tipo y año, snapshot congelado, constancia PDF con `DocumentoPdf` —marca de agua en la jubilada—, corrección que EMITE otra sin borrar la anterior, y `RequisitoFormativo` como el lado que pregunta, **sin cablear**: `ValidadorTitulo` y `EstadoCertificacion` siguen sin conocerlo, y una prueba lo vigila. `scripts/prueba-procesos-liberacion.php`, 84 verificaciones, 41 mutaciones. |
| **7** | **Alertas y reportes** | El comando programado con claves idempotentes y las tres fuentes de reportes. |
| **8** | **Supervisor externo** *(opcional)* | La faceta nueva y su portal mínimo. Se deja al final porque toca `CatalogoPermisos::FACETAS`, `Rol::ambitoDePermisos`, el menú y dos seeders — seis sitios que `FacetasConsistentesTest` vigila—, y porque hasta aquí el supervisor puede aprobar por ventanilla. |

---

## 10. LO QUE EXPLÍCITAMENTE NO SE HARÁ

1. **Tocar `titulo_servicio_social`, `ValidadorTitulo` ni `EstadoCertificacion`.**
2. **Un segundo padrón de empresas** ni un segundo motor de avisos, de rúbricas
   o de documentos requeridos.
3. **Geolocalización obligatoria.**
4. **Estados calculables guardados** (`elegible`, `pendiente_*`).
5. **Firma electrónica avanzada** de constancias: la escuela firma su PDF como
   firma el historial. Media implementación de e.firma es peor que ninguna.
6. **Portal público para organizaciones** que publiquen plazas solas. Sin
   verificación de identidad, es una puerta abierta al padrón.
