# Plan de migraciones por fase — Acadion

Checklist derivado de `especificacion-esquema.md` (secciones "Nota de
dependencias"). El orden respeta las FKs: cada tabla se crea después de todo lo
que referencia. Los catálogos **TENANT-CONFIG** se migran y siembran **antes**
de las tablas que los usan.

## Convenciones al ir tachando

- `[ ]` pendiente · `[x]` migración creada y probada · `[~]` **resuelto de otra
  forma que la spec**: o con otro nombre, o plegado en otra tabla, o
  deliberadamente no construido. Siempre con el porqué al lado.
- **Este documento se comprueba contra la BASE, no contra la memoria.** Llevaba
  meses diciendo que faltaban módulos enteros ya construidos, y CLAUDE.md tiene
  anotadas cinco veces que una lista así manda a rehacer trabajo hecho. Al
  cerrar un módulo se actualiza aquí, en el mismo commit.
- **Capa:** `L` = LANDLORD (BD central) · `T` = TENANT (BD por escuela) ·
  `TC` = TENANT-CONFIG (tenant, catálogo sembrado con seeder).
- Las migraciones `L` van en `database/migrations/`.
- Las migraciones `T` y `TC` van en `database/migrations/tenant/`.
- Toda tabla `T` y `TC` lleva `$table->auditoria()` (macro) + el trait
  `TieneAuditoria` en su modelo. Las `L` no llevan auditoría.
- Cada `TC` necesita además su **seeder** de valores por defecto.

---

## FASE 0 — Fundación multi-tenant

### 0.1 Landlord
- [x] `tenants` (L) — creada por stancl/tenancy.
- [x] `domains` (L) — creada por stancl/tenancy.
- [x] `super_admins` (L) — usuarios de la casa.
- [x] Catálogos universales (L), read-only para tenants:
  - [x] `paises` — sembrado (MEX, USA).
  - [x] `entidades_federativas` (FK → paises) — 32 + NE (claves CURP).
  - [x] `sexos` — H/M.
  - [x] `generos` — 5 opciones.
  - [x] `niveles_estudio` — 7 niveles con orden.

### 0.2 Feature flags y configuración por tenant
- [x] `modulos` (TC) — sembrado con los 13 módulos (Tenant\ModuloSeeder).
- [x] `modulos_activos` (T, FK → modulos) — PK modulo_id.
- [x] `modulo_config` (T) — clave/valor por módulo, PK (modulo_id, clave).
- [x] `configuraciones` (T) — clave/valor escalar del tenant, PK clave.
- [x] `auditoria` (T) — bitácora transversal append-only (excepción de
      auditoría; único uso de JSON justificado).

> Tenant de prueba `demo` (BD `tenantdemo`, dominio `demo.localhost`) creado y
> validado: 19 tablas InnoDB, 13 módulos sembrados, aislamiento confirmado.
> Pipeline de creación: CreateDatabase → MigrateDatabase → SeedDatabase.

> Infra ya lista: `users`, `cache`, `jobs` (migraciones default de Laravel)
> viven en `database/migrations/tenant/`. `create_permission_tables` (Spatie)
> también en tenant/.

---

## FASE 1 — Núcleo

### Módulo 1 — Identidad
Slice sin auth (hecho):
- [x] `personas` (T) — refs landlord SIN FK real (cross-DB). FULLTEXT
      (nombre, apellidos, curp). Modelo con relaciones cross-DB.
- [x] `temas` (TC) — sembrado (claro/oscuro/alto_contraste).
- [x] `tema_tokens` (TC, FK → temas) — tokens de color por fila.

Slice de auth ✅ COMPLETO:
- [x] `roles` (TC) — resuelta la colisión con Spatie: se UNIFICAN. Se extiende
      la tabla de Spatie con `nombre`, `tiempo_sesion` y `rol_padre_id`
      (jerarquía faceta → rol funcional, con herencia de permisos).
- [x] `usuarios` (T, FK → personas, roles, temas) — tabla de credenciales.
      Se eliminó la tabla `users` del scaffolding; el guard `web` apunta a
      `App\Models\Identidad\Usuario`.
- [x] `usuario_tema_override` (T, FK → usuarios)
- [x] `persona_rol` (T, FK → personas, roles, campus) — multi-rol con bandera
      `activo` y alcance por campus.
- [x] `permisos` / `rol_permiso` — vía Spatie, con `PermisoSeeder` (23 permisos
      en 6 dominios) que los asigna al rol más general gracias a la herencia.

Infraestructura LANDLORD (corrección):
- [x] `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`,
      `password_reset_tokens` en la BD central — faltaban desde el arranque y
      rompían cualquier operación de caché en contexto central.

> Prueba de integración (con rollback): herencia de permisos (encargado de
> admisiones = 3 heredados + 7 propios, sin filtrarse los de finanzas),
> conmutador de rol (mismo usuario, permisos distintos según rol activo),
> alcance por campus (aplica en Norte, no en Sur), rechazo de conmutación a un
> rol no asignado, y reasignación automática por el middleware al revocarle un
> rol.

### Módulo 2 — Estructura académica  ✅ COMPLETO
Catálogos TC (sembrados con CatalogosAcademicosSeeder):
- [x] `tipos_campus` (TC)
- [x] `tipos_periodo` (TC)
- [x] `tipos_plan_estudio` (TC)
- [x] `tipos_asignatura` (TC)
- [x] `clasificaciones_asignatura` (TC)
- [x] `areas` (TC)
- [x] `autorizaciones_reconocimiento` (TC)
- [x] `turnos` (TC)

Tablas:
- [x] `campus` (T, FK real → tipos_campus; entidad_id → landlord sin FK)
- [x] `carreras` (T; nivel_estudios_id → landlord sin FK)
- [x] `planes_estudio` (T, FK → carreras, autorizaciones_reconocimiento,
      tipos_periodo)
- [x] `asignaturas` (T, FK → tipos_asignatura, clasificaciones_asignatura, areas)
- [x] `plan_materias` (T, FK → planes_estudio, asignaturas) — núcleo curricular.
      Índice único (plan_id, clave_en_plan) verificado.
- [x] `esquema_evaluacion` (T, FK → plan_materias) — ponderación relacional (Σ=100).
- [x] `seriacion` (T, FK reflexiva → plan_materias) — DAG de prerequisitos.
- [x] `oferta` (T, FK → carreras, planes_estudio, campus, turnos) — índice único
      (carrera_id, plan_id, campus_id, turno_id).

> Prueba de integración (con rollback) en el tenant demo: cadena completa
> campus→carrera→plan→asignatura→plan_materia→evaluación→seriación→oferta;
> relación cross-DB, seriación reflexiva, Σ%=100 y unique validados.

### Módulo 3 — Formularios dinámicos  ✅ COMPLETO
Catálogos TC (sembrados con CatalogosFormulariosSeeder):
- [x] `tipos_campo` (TC) — 11 tipos del legacy.
- [x] `formulario_obligatoriedad` (TC)
- [x] `formulario_visibilidad` (TC)
- [x] `tipos_antecedente_academico` (TC)

Tablas (la de respuestas se difiere a fase 1/módulo 4 por FK a matricula_oferta):
- [x] `formularios` (T) — índice único (clave, version) verificado.
- [x] `campos_formulario` (T, FK → formularios, tipos_campo, self campo_padre_id)
- [x] `opciones_campo` (T, FK → campos_formulario)
- [x] `formulario_asignacion` (T, FK → formularios) — polimórfico
      nivel/carrera/oferta/rol; `aplica_a_id` sin FK, indexado por par.
- [x] `respuestas_campo` (T) — ya NO está diferida: se creó al cerrar el Módulo
      4, con `matricula_oferta_id` **y** `aspirante_id`, que es la dependencia
      que la tenía esperando.

> Prueba de integración (con rollback): formulario versionado, campo
> condicional con auto-referencia, opciones relacionales, asignación a un
> nivel de la landlord, unique y versionado v2 validados.

### Módulo 4 — Matrícula y admisiones (CRM)  ✅ COMPLETO
Catálogos TC (sembrados con CatalogosAdmisionesSeeder):
- [x] `situaciones_aspirante` (TC)
- [x] `situaciones_asesor` (TC)
- [x] `situaciones_tutor` (TC)
- [x] `estados_documento` (TC)
- [x] `etapas_crm` (TC) — con `orden` (embudo).
- [x] `situaciones_alumno` (TC)

Tablas:
- [x] `aspirantes` (T, FK → personas, oferta, campus, situaciones_aspirante).
      ⚠️ `ciclo_ingreso_id` SIN FK: `ciclos` es del Módulo 5. Falta migración
      de seguimiento que agregue el constraint cuando exista.
- [x] `asesores` (T, FK → personas, situaciones_asesor) — PK persona_id.
- [x] `tutores_crm` (T, FK → personas, situaciones_tutor) — PK persona_id.
- [x] `aspirante_asesor` (T) — PK compuesta.
- [x] `aspirante_tutor_crm` (T) — PK compuesta.
- [x] `campus_asesor` (T), `campus_tutor` (T)
- [x] `promociones` (T)
- [x] `aspirante_promocion` (T) — PK compuesta.
- [x] `documentos_requeridos` (T)
- [x] `documento_carrera` (T, FK → documentos_requeridos, carreras)
- [x] `etiquetas_documento` (T), `documento_etiqueta` (T)
- [x] `expediente_documentos` (T, FK → aspirantes, documentos_requeridos,
      carreras, estados_documento)
- [x] `reactivos_cleaver` (TC) — creada, SIN sembrar (banco real del legacy).
- [x] `cleaver_aspirante` (T, FK → aspirantes, reactivos_cleaver)
- [x] `alumnos` (T, FK → personas, situaciones_alumno) — PK persona_id.
- [x] `matricula_oferta` (T, FK → personas, oferta, situaciones_alumno) — "el
      alumno" real. Únicos (persona_id, oferta_id) y (matricula) verificados.
- [x] `expedientes` (T, FK → matricula_oferta)
- [x] `respuestas_campo` (T, FK → campos_formulario, personas, matricula_oferta,
      aspirantes) — **cierra la dependencia del Módulo 3**.

> Prueba del flujo completo (con rollback) en el tenant demo: aspirante →
> conversión a alumno con la MISMA persona_id (cero recaptura) → segunda
> matrícula en maestría. El caso rector quedó demostrado: el mismo formulario
> respondido dos veces con valores distintos por oferta, y `UPDATE ... WHERE
> matricula_oferta_id AND campo_formulario_id` modificando una respuesta
> puntual. Ambos índices únicos rechazaron los duplicados.

---

## FASE 2 — Operación escolar

### Módulo 5 — Control escolar  ✅ COMPLETO
Catálogos TC (sembrados con CatalogosControlEscolarSeeder, 34 filas):
- [x] `situaciones_ciclo`, `situaciones_grupo`, `situaciones_asignatura_grupo`,
      `situaciones_inscripcion`, `situaciones_docente`, `tipos_docente`,
      `tipos_evaluacion`, `estatus_historial`, `situaciones_reprobatoria`,
      `observaciones_historial` (uniformes).
- [x] `aulas` (TC, FK → campus, con capacidad) — NO se siembra: espacios
      físicos reales de cada escuela.

Tablas:
- [x] `ciclos` (T, FK → campus, situaciones_ciclo) — ventanas de inscripción,
      altas/bajas y captura. `campus_id` NULL = ciclo global.
      ⚠️ La spec duplicaba fecha_inicio/fecha_fin e inicio/fin; se conservó un
      solo par (`fecha_inicio`/`fecha_fin`).
- [x] `grupos` (T, FK → ciclos, campus, planes_estudio, turnos,
      situaciones_grupo, self grupo_origen_id)
- [x] `asignatura_grupo` (T, FK → grupos, plan_materias, situaciones_asignatura_grupo)
- [x] `horarios_asignatura_grupo` (T, FK → asignatura_grupo, aulas)
- [x] `docentes` (T, FK → personas, tipos_docente, situaciones_docente) — PK
      persona_id (rol materializado que faltaba en Fase 1).
- [x] `campus_docente` (T)
- [x] `docente_asignatura_grupo` (T, FK → asignatura_grupo, docentes) — PK
      compuesta, tipado titular/adjunto.
- [x] `tutor_asignatura_grupo` (T, FK → asignatura_grupo, personas) — tutor académico.
- [x] `inscripcion` (T, FK → matricula_oferta, asignatura_grupo, ciclos) — nivel
      único. Índice único (matricula_oferta_id, asignatura_grupo_id) verificado.
- [x] `historial` (T, FK → matricula_oferta, plan_materias, ciclos,
      asignatura_grupo, tipos_evaluacion, estatus_historial,
      situaciones_reprobatoria, observaciones_historial) — historial académico.
- [x] `equivalencias` (T, FK → matricula_oferta, plan_materias)
- [x] **Pendiente del Módulo 4 cerrado**: migración de seguimiento que agrega
      la FK real `aspirantes.ciclo_ingreso_id → ciclos`.

Captura de calificaciones y acta (huecos de la spec, resueltos con el cliente —
ver `docs/decisiones.md`):
- [x] `calificaciones_componente` (T, FK → inscripcion, esquema_evaluacion) —
      lo que el docente captura. Único (inscripcion_id, esquema_evaluacion_id).
      NULL ≠ 0: sin capturar bloquea el cierre, no pondera como cero.
- [x] `actas` (T, FK → asignatura_grupo, tipos_evaluacion, personas, self
      acta_origen_id) — el acta como entidad, no un varchar suelto.
- [x] `contadores_acta` (T) — consecutivo atómico del folio. SIN `id`
      AUTO_INCREMENT, misma lección que `contadores_matricula`.
- [x] `historial.acta_id` (migración de seguimiento) — FK real al acta;
      `acta_folio` se conserva porque es lo que se imprime.

> Prueba de integración (con rollback): ventanas del ciclo, TRONCO COMÚN (un
> mismo grupo abriendo la misma asignatura de catálogo para dos planes, cada
> uno con su clave de acta), detección de choque de horario, docente titular
> que firma, inscripción de nivel único, asentamiento de acta al historial académico,
> SERIACIÓN evaluada contra el historial aprobado, equivalencia externa y
> rechazo de doble inscripción.

### Módulo 6 — Asistencia y reloj checador  ✅ COMPLETO
Catálogos TC:
- [x] `tipos_dispositivo_checador` (TC) — qr, biométrico, geocerca, manual.

Tablas:
- [x] `dispositivos_checador` (T, FK → campus) — con geocerca (lat/lng/radio) y
      tolerancia. Modelo con `dentroDeGeocerca()` (haversine, fail-closed si no
      hay geocerca configurada).
- [x] `checadas` (T, FK → personas, dispositivos_checador) — índice (persona_id, momento).
- [x] `asistencia_clase` (T, FK → inscripcion, personas) — índice único (inscripcion_id, fecha).

> **Separación deliberada** (regla de la spec): `checadas` es presencia laboral
> /de acceso, y la consumirá Nómina (Fase 4) para horas e incidencias;
> `asistencia_clase` es presencia académica por materia y alimenta las faltas
> del alumno. No se mezclan.
>
> Prueba de integración (con rollback): geocerca aceptando a ~30 m y
> rechazando a ~2 km, cálculo de 7.2 h desde entrada/salida, y conteo de
> faltas que excluye correctamente justificadas y retardos.

---

## Estado al cierre de la Fase 2

- **105 tablas** en la BD de tenant, todas InnoDB.
- Tras la Fase 3 parcial y las tandas de interfaz: **119 tablas**.
- Fase 0 ✅ · Fase 1 ✅ (salvo slice de auth del Módulo 1) · Fase 2 ✅
- Pendiente transversal: el slice de credenciales del Módulo 1 (`roles`,
  `usuarios`, `persona_rol`, `usuario_tema_override`) y la reconciliación de
  `roles` con spatie/laravel-permission.

---

## FASE 3 — Módulos de valor

### Módulo 7 — Finanzas  ✅ COMPLETO (7.1, 7.2 y 7.3 cerradas)

> Se partió en tres entregas, las tres cerradas.

Catálogos TC — migrados y **sembrados** (`CatalogosFinanzasSeeder`):
- [x] `conceptos_pago` (TC) — con clave SAT, gravado y tasa de IVA para el CFDI.
- [x] `situaciones_pago` (TC) — con bandera `bloquea` (si impide reinscribirse).
- [x] `metodos_pago` (TC) — con `requiere_confirmacion`: un pago en ventanilla se
      da por cobrado al registrarlo, uno por pasarela no hasta el webhook.
      ⚠️ La spec lo describía como varchar en `pagos`; se hizo catálogo por
      coherencia con el resto del proyecto (ver decisiones.md).

Motor configurable — migrado, **con modelos** en `App\Models\Finanzas\`:
- [x] `planes_cobro` (T) — `aplica_a_id` polimórfico sin FK, como
      `formulario_asignacion`.
- [x] `reglas_generacion` (T, FK → planes_cobro, conceptos_pago, self)
- [x] `recargos_descuentos` (T)
- [x] `becas_alumno` (T, FK → matricula_oferta, recargos_descuentos, personas)

Núcleo transaccional:
- [x] `adeudos` (T) — **DECISIÓN VINCULANTE cumplida**: `matricula_oferta_id`
      NULLABLE + `aspirante_id` nullable, exactamente uno presente, con índices
      por ambos y CHECK `chk_adeudos_titular` en MySQL.
- [x] `pagos` (T) — igual que adeudos. `metodo_pago_id` (FK al catálogo) en vez
      del varchar `metodo` que decía la spec.
- [x] `pago_adeudo` (T, FK → pagos, adeudos) — PK compuesta, con
      `monto_aplicado` para pagos parciales y split.
- [x] `bitacora_situacion_financiera` (T, FK → matricula_oferta, situaciones_pago)
      — append-only; la situación vigente es su último renglón.
- [x] Modelos de todo el módulo (11) + `CatalogosFinanzasSeeder`, ya enganchado
      en `DatabaseSeeder`.
- [x] **Re-ligadura** (`App\Services\ReligadorFinanzas`) en
      `ConvertidorAspirante` y `MatriculadorOferta`, DENTRO de la transacción
      que genera la matrícula. En el segundo se acota por oferta: los pagos de
      otra candidatura de la misma persona no son de esa matrícula.
- [x] Suite `scripts/prueba-finanzas.php` — 47 verificaciones contra la BD real
      con rollback.

**Entrega 7.2** — motor de generación ✅ CERRADA:
- [x] `GeneradorAdeudos` recorre las reglas del plan vigente y crea adeudos por
      periodicidad. **Idempotente por índice único**
      `(matricula_oferta_id, regla_id, periodo_etiqueta)`, no solo por SELECT
      previo: el job programado puede traslaparse consigo mismo.
- [x] `PeriodosCobro` + `PeriodoCobro`: el calendario aislado (único con
      parcialidades, semanal ISO, quincenal, mensual). `por_ciclo` y
      `por_materia` los resuelve el generador, que sí conoce ciclos e
      inscripciones.
- [x] `ResolutorPlanCobro`: gana el más específico vigente
      (oferta → plan → carrera → global).
- [x] `AplicadorRecargosDescuentos`: mora con días de gracia sobre el monto
      base, becas vigentes al generar, recálculo de cartera.
- [x] `RegistradorPago`: aplica a los más vencidos o al orden que elija quien
      cobra, permite parciales y split, deriva el estatus del adeudo, y
      confirma / revierte sin borrar la aplicación.
- [x] `EstadoCuenta`: saldo, vencido, pagado, por confirmar y a favor.
- [x] Pantallas: `/finanzas` (cartera con búsqueda y totales agregados en SQL),
      `/finanzas/cuentas/{matricula}` (estado de cuenta con generación, cobro,
      condonación y bitácora) y `/finanzas/planes` (+ detalle con sus reglas).
- [x] Permiso nuevo `gestionar-planes-cobro`, separado de `registrar-pagos`.
- [x] Suite `scripts/prueba-cobro.php` — 53 verificaciones.

~~Pendiente de 7.2 para cuando exista el scheduler~~ — **hecho**: `routes/console.php`
programa `finanzas:generar-cargos` a las 2:45 y `finanzas:evaluar` después. En
ese orden a propósito: no se puede recargar por mora un cargo que no existe.

**Entrega 7.3** — CFDI 4.0 ✅ CERRADA:
- [x] `facturas` (T, FK → matricula_oferta y self `factura_sustituye_id`).
      Inmutable: no hay ruta de edición para una timbrada. Lleva además del
      mínimo de la spec lo que el flujo exige — `forma_pago_sat`,
      `metodo_pago_sat`, `intentos`, `ultimo_error`, `cancelada_en`,
      `motivo_cancelacion` y la relación de sustitución.
- [x] `factura_conceptos` (T, FK → facturas, pagos) con IVA por renglón.
- [x] Timbrado en cola: job `TimbrarFactura` con reintentos de espera creciente,
      `failed()` que rescata lo colgado en "timbrando" y defensa contra el doble
      timbrado.
- [x] `App\Services\Cfdi\Pac` (interfaz) + `PacFalso` + `ResultadoTimbrado`,
      registrados por `config/cfdi.php`. **Sin implementación real todavía**:
      se agrega la clase del PAC cuando la escuela contrate uno.
- [x] `EmisorFactura`: emisión contra pagos cobrados, desglose de IVA por
      concepto, `refacturar()` (emite la sustituta antes de cancelar) y
      `cancelar()` con los cuatro motivos del SAT.
- [x] Pantallas `/finanzas/facturas` (listado con filtro por estatus), su
      detalle (descargas, reintento, refacturación y cancelación) y
      `/finanzas/facturas/emitir/{matricula}`. Enlazadas desde el estado de
      cuenta.
- [x] Todo bajo el permiso `facturar`, que ni control escolar ni el auxiliar de
      ventanilla tienen.
- [x] Suite `scripts/prueba-facturacion.php` — 47 verificaciones.

Ampliación pedida por el cliente (varias razones sociales) ✅:
- [x] `emisores_fiscales` (T) — RFC, razón social, régimen, CP y las
      credenciales de timbrado (certificado y llave en disco privado;
      contraseñas con cast `encrypted`).
- [x] `emisor_asignaciones` (T, pivote) — `aplica_a_tipo` global/nivel/carrera.
      Una razón social cubre varias cosas a la vez.
- [x] `facturas` gana `emisor_id` + los cuatro campos del emisor COPIADOS.
- [x] `ResolutorEmisorFiscal` con precedencia carrera → nivel → global, y error
      explícito cuando hay emisores pero ninguno cubre esa carrera.
- [x] Pantalla `/finanzas/emisores` con asignaciones, carga de certificados y
      aviso de carreras sin asignar. Permiso `gestionar-emisores`.
- [x] Suite `scripts/prueba-emisores.php` — 24 verificaciones.

Pendiente de 7.3 para cuando haya PAC contratado: escribir el driver real
(implementar `Pac`, registrarlo en `config/cfdi.php`) y llenar
`CFDI_EMISOR_*` en el `.env`. Nada más cambia — ni el job ni el servicio saben
cuál está en uso. Falta también la representación impresa (PDF): hoy se guarda
el que devuelva el PAC, y `PacFalso` no devuelve ninguno.

### Módulo 8 — LMS  ✅ COMPLETO (2026-08-23)

> **Se construyó con OTROS NOMBRES que la spec, y esta lista llevaba meses sin
> reflejarlo.** Un renglón sin tachar aquí decía «falta construir» de cosas
> hechas, que es exactamente lo que ya mandó cinco veces a rehacer trabajo
> existente. Cada desvío va anotado con su equivalente real.

Catálogos TC:
- [~] `tipos_actividad` y `tipos_reactivo` (TC) — **NO se hicieron tabla, y es
      correcto**: son `actividades.tipo` y `reactivos.tipo`, respaldados por dos
      enums. Cada valor no es un dato, es una RAMA DE CÓDIGO —22 y 74 medidas—:
      una lectura no pondera, un examen lo califica la máquina, un foro tiene su
      propio controlador, un portafolio se acumula. Volverlo catálogo sería una
      promesa falsa: la escuela agregaría «Podcast» y no habría rama que lo
      atendiera. La regla 4 no pide que todo enumerable sea tabla, pide poder
      explicar por qué no lo es. Ver `docs/decisiones.md`, 2026-08-23.
- [~] `dificultades` y `metodos_resolver` (TC) — no existen ni como columna, y
      **no son deuda**: son una FUNCIÓN que nadie ha pedido (armar el examen con
      N reactivos de cada nivel). Llegarán con su lector, como `clave_sat`.

Tablas:
- [x] `cursos` (T, FK → asignatura_grupo, plan_materias, self origen_curso_id)
- [~] `unidades` (T) — **no existe**: se plegó en `actividades`, que llevan
      `orden` y su parcial. Un curso es una lista de lecciones, no un árbol de
      dos niveles.
- [~] `contenidos` (T) — **no existe**: es `actividades.contenido`, el HTML del
      editor. Ver «El AULA del alumno» en CLAUDE.md.
- [x] `rubricas` (T) — con `rubrica_criterios`, `rubrica_niveles` y
      `entrega_rubrica`. Dos ámbitos en una tabla (plataforma / docente); el
      máximo de un criterio se deriva de sus niveles y el total, de los máximos.
      Se congela al primer uso y para cambiarla se duplica, que es lo que
      permite que la actividad la APUNTE en vez de copiarla. Ver
      `docs/decisiones.md`, 2026-08-18.
- [~] `bancos_reactivos` (T) — **no existe**: el banco es el curso, y los
      reactivos cuelgan de él con `reactivos.curso_id`. Una tabla intermedia sin
      más atributos que su dueño no aporta nada.
- [x] `actividades` (T, FK → cursos, esquema_evaluacion, rubricas)
- [x] `reactivos` (T, FK → cursos)
- [x] `opciones_reactivo` → se llama **`reactivo_opciones`**.
- [~] `pares_reactivo` (T) — **no existe** como tabla propia.
- [x] `actividad_reactivos` → se llama **`examen_reactivo`**, y cuelga de
      `examenes` (la configuración del examen) y no de la actividad a secas.
- [x] `entregas` (T, FK → actividades, inscripcion)
- [x] `entrega_respuestas` → se llama **`respuestas`**, y cuelga del INTENTO
      (`intento_id`), no de la entrega: un examen admite varios intentos y la
      spec no lo contemplaba.
- [x] `portafolio_evidencias` (T) — cuelga de **`entregas`** y no de
      (inscripcion, actividad) como pedía la spec: es la misma pareja que
      `entregas` ya identifica, y con dos tablas diciendo «el trabajo de esta
      alumna aquí» al calificar habría que elegir a cuál creerle. Colgando de la
      entrega se hereda la calificación, la rúbrica y el «entregada tarde».
- [x] `portafolio_archivos` (T, FK → portafolio_evidencias) — varios por
      evidencia, y una evidencia puede no tener ninguno: una reflexión escrita
      es evidencia legítima.
- [x] `foros` → son **`foro_temas`** (el hilo) y **`foro_respuestas`**, colgando
      de la ACTIVIDAD y no del curso: un foro del LMS es un tipo de actividad.
- [x] `foro_mensajes` → cubierto por `foro_respuestas`.
- [x] `videoconferencias` (T, FK → asignatura_grupo), con
      `integraciones_videoconferencia` (credenciales cifradas por proveedor) y
      `cuentas_videoconferencia` (el pool de anfitriones) que la spec no
      contemplaba. Zoom y Meet NO son simétricos —una licencia de Zoom sostiene
      una reunión a la vez y una cuenta de Meet no tiene ese límite—, y esa
      bandera es la que gobierna el reparto. Ver `docs/decisiones.md`,
      2026-08-19.
- [x] `acceso_videoconferencia` → se llama **`accesos_videoconferencia`**. Una
      fila por persona y clase (no por clic) con `veces` y `ultimo_acceso`. Mide
      el CLIC en «Entrar», no permanencia, y la pantalla lo dice con esas
      palabras. Ver `docs/decisiones.md`, 2026-08-23.
- [x] `grabaciones` + `destinos_grabacion` (T) — el archivado de lo que Zoom o
      Meet graba, a disco propio, Drive o Dropbox. No estaba en la spec: sólo
      preveía `videoconferencias.grabacion_ruta`, que no alcanza porque una
      clase deja varios archivos (video, audio, chat, transcripción). Ver
      `docs/decisiones.md`, 2026-08-19.

**Las tres que faltaban se construyeron el 2026-08-23** (portafolio y accesos a
la clase en línea). Ver CLAUDE.md, «Las tres tablas que le faltaban al Módulo 8».

**Y la «deuda» de los cuatro catálogos se comprobó y NO existía**: dos son ramas
de código disfrazadas de datos y dos son una función que nadie ha pedido. Ver el
detalle arriba y el razonamiento en `docs/decisiones.md`.

### Módulo 9 — Titulación y certificación SEP  ✅ COMPLETO

> Igual que el 8: construido con otros nombres y sin tachar aquí. 17 modelos en
> `App\Models\Emision\`, 38 rutas y sus pantallas.

Catálogos TC:
- [x] `modalidades_titulacion`, `tipos_certificacion`, `tipos_responsable`,
      `cargos`, `titulos_profesionales`,
      `fundamentos_legales_servicio_social` (todas TC).
- [~] `etapas_titulacion`, `etapas_certificacion`, `estatus_titulo`,
      `estatus_certificado`, `estatus_lote` — **no se hicieron catálogo**: son
      columnas de estado en `titulaciones`, `certificaciones` y sus lotes. Un
      trámite ante la SEP tiene los estados que la SEP reconoce y la escuela no
      puede inventarse uno.
- [~] `abreviaturas_titulo` → es `titulos_profesionales`, con `abreviatura` y
      `descripcion` como columnas. Ver CLAUDE.md: las lee el XML del título, y
      el registro genérico las MAPEA en vez de renombrarlas.
- [~] `cumplimientos_servicio_social` — no existe; el cumplimiento vive en
      `titulo_servicio_social`.

Tablas:
- [x] `responsables_firma` → se llama **`responsables`**, con
      `responsable_movimientos` (su historial) y `certificados_responsable`.
- [x] `tramites_titulacion` → se llama **`titulaciones`**, con `titulo_modalidad`,
      `titulo_antecedente` y `titulo_servicio_social` colgando.
- [x] `servicio_social` → se llama **`titulo_servicio_social`**.
- [x] `antecedentes_academicos` → se llama **`titulo_antecedente`**.
- [x] `lotes_documento` → son **DOS**: `lotes_titulacion` y `lotes_certificacion`.
      Un título y un certificado son trámites distintos ante la SEP, con XSD
      distintos; un lote común habría obligado a un discriminador en cada
      consulta.
- [x] `documentos_electronicos` → cubierto por `titulaciones` y
      `certificaciones`, que guardan su XML, su sello y su acuse.
- [x] `titulacion_ws_config` (T) — la configuración del web service de la SEP.
      No estaba en la spec.

**Lo único que falta es de tu lado, no de código**: la **e.firma** de la escuela
y el **WSDL de producción** de la SEP.

---

## FASE 4 — RH, empleabilidad, movilidad y familia  ✅ COMPLETA (2026-08-23)

> Los cuatro módulos cerrados. **La numeración no es una cadena de
> dependencias**: 10, 11, 12 y 13 no dependen entre sí, todos cuelgan de las
> Fases 0-3, así que el orden fue decisión de negocio.

### Módulo 10 — Nómina y RH  ✅ COMPLETO (2026-08-23)
Catálogos TC:
- [x] `tipos_contrato`, `motivos_baja_laboral`, `situaciones_empleado`,
      `puestos`, `modalidades_percepcion`, `conceptos_nomina`,
      `formulas_nomina` (todas TC). `situaciones_empleado` NO siembra ninguna
      situación de baja: «baja» tiene una sola fuente de verdad, `fecha_baja`.
      A quién se le paga lo dice la bandera `entra_a_nomina`, no la clave.
- [~] `regimenes_fiscales` — **no se hizo tabla a propósito**: el RFC, el
      régimen y el código postal del empleado ya viven en `datos_facturacion`,
      que es la que la facturación usa para el receptor. Una tabla propia sería
      una segunda verdad sobre el mismo RFC.

Tablas:
- [x] `expedientes_laborales` (T) — sin RFC ni CURP, que ya están en `personas`.
      El NSS se agregó a `personas` por lo mismo. La CLABE y el banco sí van
      aquí: son «a dónde se deposita ESTE sueldo».
- [x] `adscripciones` (T, FK → expedientes_laborales, puestos, campus) — no
      duplican `persona_rol.campus_id`: aquél acota lo que un usuario PUEDE VER,
      ésta dice qué puesto ocupa en el organigrama y desde cuándo.
- [x] `esquemas_percepcion` (T, FK → expedientes_laborales, modalidades_percepcion)
- [x] `periodos_nomina` (T, FK → campus)
- [x] `recibos_nomina` (T) — con el estado del timbrado por RECIBO (`uuid`,
      `xml_ruta`, `pac`, `timbrado_en`, `error_timbrado`) y no por periodo: el
      SAT puede rechazar uno y aceptar los otros cuarenta.
- [x] `recibo_conceptos` (T, FK → recibos_nomina, conceptos_nomina)

**Lo que NO se hace, y hay que saberlo**: no se construye el XML del complemento
de nómina — lo arma el driver del PAC, y no hay PAC contratado. Con
`CFDI_PAC=falso` el recorrido entero funciona y el folio es de mentiras.

### Módulo 11 — Bolsa de trabajo  ✅ COMPLETO (2026-08-22)
Catálogos TC:
- [x] `sectores_economicos`, `tamanos_empresa`, `situaciones_empresa`,
      `modalidades_trabajo`, `tipos_jornada`, `situaciones_vacante`,
      `habilidades`, `etapas_postulacion` (todas TC). `etapas_postulacion` lleva
      `marca_colocacion` y `es_final`, independientes entre sí: «Rechazado»
      cierra y no coloca.

Tablas:
- [x] `empresas` (T) — se APAGA con «vetada», no se borra: sus colocaciones
      históricas son el insumo de los reportes de acreditación.
- [x] `empresa_contactos` (T, FK → empresas) — UN solo lugar para «con quién se
      habla», con `es_principal`. La spec ponía además un `persona_contacto_id`
      en `empresas`: dos sitios donde buscar al mismo reclutador.
- [x] `vacantes` (T, FK → empresas, modalidades_trabajo, tipos_jornada, campus,
      situaciones_vacante) — dos columnas de sueldo y no una: casi ninguna
      vacante mexicana lo publica, y las que sí publican un rango.
- [x] `vacante_carreras` (T) — vacío = para TODAS las carreras.
- [x] `vacante_habilidades` (T, FK → vacantes, habilidades)
- [x] `postulaciones` (T) — `capturada_por` en null significa que se postuló
      sola; con eso se mide si el portal sirve de algo.
- [x] `postulacion_bitacora` (T) — existe para MEDIR, no para auditar: sin el
      renglón del alta, «cuánto tarda un egresado en colocarse» no tiene desde
      cuándo contar.
- [x] `colocaciones` (T) — **`postulacion_id` es NULLABLE**, al revés de la
      spec: un egresado consigue trabajo por su cuenta y ése es el dato que
      piden las acreditadoras. Obligándolo, el indicador contestaría «a cuántos
      colocó nuestra bolsa» en vez de «cuántos egresados están colocados».

### Módulo 12 — Movilidad e intercambios  ✅ COMPLETO (2026-08-23)
Catálogos TC:
- [x] `tipos_institucion`, `tipos_convenio`, `situaciones_convenio`,
      `etapas_movilidad`, `dictamenes_revalidacion` (todas TC).
- [~] `direcciones_movilidad` — **no se hizo catálogo**: `direccion` es columna.
      Saliente y entrante son dos caminos del código, no dos filas; una fila
      nueva no enseñaría un tercer camino.

Tablas:
- [x] `instituciones_aliadas` (T, FK → paises, tipos_institucion)
- [x] `convenios` (T) — vencido ≠ suspendido, y sin carreras señaladas cubre
      TODAS. Las dos lecciones que ya había dejado la bolsa de trabajo.
- [x] `convenio_carreras` (T, FK → convenios, carreras)
- [x] `convocatorias_movilidad` (T, FK → convenios)
- [x] `convocatoria_requisitos` (T) — REUSA `documentos_requeridos`: una segunda
      lista de papeles sería otro sitio donde configurar «identificación».
- [x] `postulaciones_movilidad` (T) — titular DUAL con CHECK, como `adeudos`.
      Ojo con **MySQL 3823**: una columna que participa en un CHECK no admite
      foránea con acción referencial. El promedio se CALCULA y se CONGELA.
- [x] `estancias` (T, FK → postulaciones_movilidad)
- [x] `revalidaciones` (T, FK → estancias, plan_materias, dictamenes_revalidacion)

**El hallazgo del módulo**: el asiento en el historial NO necesitó una columna
«origen», que es lo que la spec pedía. `tipos_evaluacion` ya traía
`revalidacion` desde la Fase 2 y `observaciones_asignatura` —catálogo de la
SEP— ya traía «REVALIDACIÓN DE ESTUDIOS», que es el valor que viaja en el XML
del certificado. Una columna propia habría dejado el dato FUERA del documento
oficial y habría creado una segunda forma de decir lo mismo.

### Módulo 13 — Portal de familiares  ✅ COMPLETO (2026-08-22)

> **Con el alcance REVISADO.** Buena parte ya estaba construida con otros
> nombres, y hacerlo literal habría creado un segundo vínculo familiar y un
> segundo sistema de avisos.

Catálogos TC:
- [x] `parentescos`, `tipos_autorizacion` (TC). El parentesco era un enumerable
      cableado DOS veces —una lista en el controlador y otro mapa en el Vue— y
      ninguna escuela podía agregar «abuela» sin tocar código.

Tablas:
- [~] `vinculos_familiares` → es **`tutores_alumno`**, que ya existía. Y el
      vínculo se queda POR PERSONA, no por matrícula como pedía la spec:
      decisión del cliente. Ganó `es_contacto_emergencia` y
      `es_responsable_pago`; se retiró `acceso_materia`, que no leía nadie y que
      además debe ser una exclusión ESTRUCTURAL y no una casilla palomeable.
- [~] `avisos_familiares` → es **`avisos`**, que ya segmenta por nueve tipos de
      destino. Un segundo sistema de avisos habría partido en dos la pregunta
      «¿esto es para mí?».
- [~] `aviso_destinatarios` → es **`avisos_destinos`**, con el modificador «y a
      sus familias»: no señala a nadie por sí solo —va sin id—, extiende a los
      tutores lo que los demás destinos ya dijeron. Es lo único del servicio que
      se CRUZA en vez de sumarse.
- [x] `autorizaciones` (T) — una fila por VÍNCULO, no por alumno: quien autoriza
      es una persona concreta y su respuesta es suya. `concedida` en NULL es «no
      ha contestado» y NO cuenta como negada; la diferencia es legal, no
      cosmética.

---

## Notas de ejecución

- Cada módulo se registra en `modulos` (Fase 0) y se enciende por escuela vía
  `modulos_activos`.
- Al terminar cada **módulo** se para para validación antes de seguir (regla de
  trabajo del proyecto).
- Los seeders de catálogos TC se ejecutan con `tenants:seed` tras
  `tenants:migrate`, en el contexto de cada tenant.
- Verificar siempre que las migraciones corran sobre **InnoDB** (no MyISAM):
  las FKs, transacciones y `FOR UPDATE SKIP LOCKED` lo exigen.

---

## Suites de integración versionadas

**82 archivos `scripts/prueba-*.php`**, todos contra la BD real del tenant demo
y dentro de una transacción con `DB::rollBack()`. Se corren de una vez con
`for f in scripts/prueba-*.php; do php "$f"; done`.

**Ojo al barrer con `grep`**: casi todas cierran con `Resultado: N correctas, M
fallidas`, pero cuatro no —`prueba-cache-externo`, `prueba-captura-examen` y
`prueba-mensajes-espanol` dicen `N en verde`, y `prueba-listados` dice
`TODO EN VERDE — N verificaciones`—, así que un barrido que sólo busque
«Resultado:» las reporta como rotas sin estarlo.

La primera fue `prueba-actas.php` (43 verificaciones): ponderación, rechazo del
acta incompleta, volcado al historial académico, recursamiento, extemporaneidad,
corrección con soft delete de los renglones previos, regresión del doble
asentamiento y 200 folios sin colisión.

No vive en `tests/` porque phpunit corre contra SQLite en memoria y aquí se
prueba lo que SQLite no sabe hacer: `LAST_INSERT_ID` de MySQL, FKs reales e
InnoDB bajo transacción.
