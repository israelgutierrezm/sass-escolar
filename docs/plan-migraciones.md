# Plan de migraciones por fase — Acadion

Checklist derivado de `especificacion-esquema.md` (secciones "Nota de
dependencias"). El orden respeta las FKs: cada tabla se crea después de todo lo
que referencia. Los catálogos **TENANT-CONFIG** se migran y siembran **antes**
de las tablas que los usan.

## Convenciones al ir tachando

- `[ ]` pendiente · `[x]` migración creada y probada.
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

### Módulo 3 — Formularios dinámicos  ✅ (salvo respuestas_campo)
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
- [ ] `respuestas_campo` (T) — DIFERIDA: depende de `matricula_oferta` y
      `aspirantes` (Módulo 4), como indica la nota de dependencias.

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
      situaciones_reprobatoria, observaciones_historial) — kárdex.
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
> que firma, inscripción de nivel único, asentamiento de acta al kárdex,
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

### Módulo 7 — Finanzas  ⏳ EN CURSO (7.1 ✅ cerrada)

> Se partió en tres entregas. **Aquí se retoma: entrega 7.2.**

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

Pendiente de 7.2 para cuando exista el scheduler: enganchar
`GeneradorAdeudos::generarParaTodas` y `recalcularCartera` a un job diario. El
servicio ya está listo y es idempotente; solo falta quién lo dispare.

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

Pendiente de 7.3 para cuando haya PAC contratado: escribir el driver real
(implementar `Pac`, registrarlo en `config/cfdi.php`) y llenar
`CFDI_EMISOR_*` en el `.env`. Nada más cambia — ni el job ni el servicio saben
cuál está en uso. Falta también la representación impresa (PDF): hoy se guarda
el que devuelva el PAC, y `PacFalso` no devuelve ninguno.

### Módulo 8 — LMS
Catálogos TC:
- [ ] `tipos_actividad`, `tipos_reactivo`, `dificultades`, `metodos_resolver` (TC)

Tablas:
- [ ] `cursos` (T, FK → asignatura_grupo, plan_materias, self origen_curso_id)
- [ ] `unidades` (T, FK → cursos)
- [ ] `contenidos` (T, FK → unidades)
- [ ] `rubricas` (T) — referida por actividades.
- [ ] `bancos_reactivos` (T, FK → plan_materias, personas)
- [ ] `actividades` (T, FK → cursos, unidades, tipos_actividad, dificultades, rubricas)
- [ ] `reactivos` (T, FK → bancos_reactivos, dificultades, personas)
- [ ] `opciones_reactivo` (T, FK → reactivos)
- [ ] `pares_reactivo` (T, FK → reactivos)
- [ ] `actividad_reactivos` (T, FK → actividades, reactivos)
- [ ] `entregas` (T, FK → actividades, inscripcion)
- [ ] `entrega_respuestas` (T, FK → entregas, reactivos, opciones_reactivo, pares_reactivo)
- [ ] `portafolio_evidencias` (T, FK → inscripcion, actividades)
- [ ] `portafolio_archivos` (T, FK → portafolio_evidencias)
- [ ] `foros` (T, FK → cursos, actividades)
- [ ] `foro_mensajes` (T, FK → foros, personas, self mensaje_padre_id)
- [ ] `videoconferencias` (T, FK → asignatura_grupo)
- [ ] `acceso_videoconferencia` (T, FK → videoconferencias, personas)

### Módulo 9 — Titulación y certificación SEP
Catálogos TC:
- [ ] `modalidades_titulacion`, `etapas_titulacion`, `etapas_certificacion`,
      `estatus_titulo`, `estatus_certificado`, `estatus_lote`,
      `tipos_responsable`, `cargos`, `abreviaturas_titulo`,
      `cumplimientos_servicio_social`, `fundamentos_legales_ss`,
      `titulos_academicos` (todas TC). (`tipos_antecedente_academico` ya en Módulo 3.)

Tablas:
- [ ] `responsables_firma` (T, FK → personas, cargos, tipos_responsable, abreviaturas_titulo)
- [ ] `tramites_titulacion` (T, FK → matricula_oferta, modalidades_titulacion,
      etapas_titulacion, estatus_titulo)
- [ ] `servicio_social` (T, FK → matricula_oferta, cumplimientos_servicio_social,
      fundamentos_legales_ss)
- [ ] `antecedentes_academicos` (T, FK → matricula_oferta,
      tipos_antecedente_academico, entidades_federativas)
- [ ] `lotes_documento` (T, FK → campus, estatus_lote, responsables_firma)
- [ ] `documentos_electronicos` (T, FK → lotes_documento, matricula_oferta,
      tramites_titulacion)

---

## FASE 4 — RH, empleabilidad, movilidad y familia

### Módulo 10 — Nómina y RH
Catálogos TC:
- [ ] `tipos_contrato`, `regimenes_fiscales`, `motivos_baja_laboral`,
      `situaciones_empleado`, `puestos`, `modalidades_percepcion`,
      `conceptos_nomina`, `formulas_nomina` (todas TC).

Tablas:
- [ ] `expedientes_laborales` (T, FK → personas, tipos_contrato,
      regimenes_fiscales, motivos_baja_laboral, situaciones_empleado)
- [ ] `adscripciones` (T, FK → expedientes_laborales, puestos, campus)
- [ ] `esquemas_percepcion` (T, FK → expedientes_laborales, modalidades_percepcion)
- [ ] `periodos_nomina` (T, FK → campus)
- [ ] `recibos_nomina` (T, FK → expedientes_laborales, periodos_nomina)
- [ ] `recibo_conceptos` (T, FK → recibos_nomina, conceptos_nomina)

### Módulo 11 — Bolsa de trabajo
Catálogos TC:
- [ ] `sectores_economicos`, `tamanos_empresa`, `situaciones_empresa`,
      `modalidades_trabajo`, `tipos_jornada`, `situaciones_vacante`,
      `habilidades`, `etapas_postulacion` (todas TC).

Tablas:
- [ ] `empresas` (T, FK → sectores_economicos, tamanos_empresa, personas, situaciones_empresa)
- [ ] `empresa_contactos` (T, FK → empresas)
- [ ] `vacantes` (T, FK → empresas, modalidades_trabajo, tipos_jornada, campus, situaciones_vacante)
- [ ] `vacante_carreras` (T, FK → vacantes, carreras)
- [ ] `vacante_habilidades` (T, FK → vacantes, habilidades)
- [ ] `postulaciones` (T, FK → vacantes, personas, matricula_oferta, etapas_postulacion)
- [ ] `postulacion_bitacora` (T, FK → postulaciones)
- [ ] `colocaciones` (T, FK → postulaciones, personas, empresas)

### Módulo 12 — Movilidad e intercambios
Catálogos TC:
- [ ] `tipos_institucion`, `tipos_convenio`, `situaciones_convenio`,
      `direcciones_movilidad`, `etapas_movilidad`, `dictamenes_revalidacion` (todas TC).

Tablas:
- [ ] `instituciones_aliadas` (T, FK → paises, tipos_institucion)
- [ ] `convenios` (T, FK → instituciones_aliadas, tipos_convenio, situaciones_convenio)
- [ ] `convenio_carreras` (T, FK → convenios, carreras)
- [ ] `convocatorias_movilidad` (T, FK → convenios, direcciones_movilidad)
- [ ] `convocatoria_requisitos` (T, FK → convocatorias_movilidad, documentos_requeridos)
- [ ] `postulaciones_movilidad` (T, FK → convocatorias_movilidad, matricula_oferta,
      personas, etapas_movilidad)
- [ ] `estancias` (T, FK → postulaciones_movilidad)
- [ ] `revalidaciones` (T, FK → estancias, plan_materias, dictamenes_revalidacion)

### Módulo 13 — Portal de familiares
Catálogos TC:
- [ ] `parentescos`, `tipos_autorizacion` (TC)

Tablas:
- [ ] `vinculos_familiares` (T, FK → personas, matricula_oferta, parentescos)
- [ ] `avisos_familiares` (T)
- [ ] `aviso_destinatarios` (T, FK → avisos_familiares, vinculos_familiares)
- [ ] `autorizaciones` (T, FK → vinculos_familiares, tipos_autorizacion)

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

## Suite de integración versionada

`php scripts/prueba-actas.php` — 43 verificaciones contra la BD real del tenant
demo, todo dentro de una transacción con `DB::rollBack()`. Cubre ponderación,
rechazo del acta incompleta, volcado al kárdex, recursamiento, extemporaneidad,
corrección con soft delete de los renglones previos, regresión del doble
asentamiento y 200 folios sin colisión.

No vive en `tests/` porque phpunit corre contra SQLite en memoria y aquí se
prueba lo que SQLite no sabe hacer: `LAST_INSERT_ID` de MySQL, FKs reales e
InnoDB bajo transacción.
