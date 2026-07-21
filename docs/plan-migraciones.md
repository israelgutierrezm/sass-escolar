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
- [ ] `super_admins` (L) — usuarios de la casa.
- [ ] Catálogos universales (L), read-only para tenants:
  - [ ] `paises`
  - [ ] `entidades_federativas` (FK → paises)
  - [ ] `sexos`
  - [ ] `generos`
  - [ ] `niveles_estudio`

### 0.2 Feature flags y configuración por tenant
- [ ] `modulos` (TC)
- [ ] `modulos_activos` (T, FK → modulos) — PK compuesta.
- [ ] `modulo_config` (T) — clave/valor por módulo.
- [ ] `configuraciones` (T) — clave/valor escalar del tenant.
- [ ] `auditoria` (T) — bitácora transversal (único uso de JSON justificado).

> Infra ya lista: `users`, `cache`, `jobs` (migraciones default de Laravel)
> viven en `database/migrations/tenant/`. `create_permission_tables` (Spatie)
> también en tenant/.

---

## FASE 1 — Núcleo

### Módulo 1 — Identidad
- [ ] `personas` (T) — FK → sexos, generos, paises, entidades_federativas
      (landlord). Índice FULLTEXT (nombre, apellidos, curp).
- [ ] `roles` (TC)
- [ ] `permisos` (TC) — vía Spatie (documentar permisos como seeder).
- [ ] `temas` (TC)
- [ ] `tema_tokens` (TC, FK → temas)
- [ ] `usuarios` (T, FK → personas, roles, temas) — nota: mapea al modelo de
      credenciales; convive con la tabla `users` de Laravel/Spatie.
- [ ] `usuario_tema_override` (T, FK → usuarios)
- [ ] `persona_rol` (T, FK → personas, roles) — PK compuesta, multi-rol.
- [ ] `rol_permiso` (T) — vía Spatie.

### Módulo 2 — Estructura académica
Catálogos TC primero (antes de sus FKs):
- [ ] `tipos_campus` (TC)
- [ ] `tipos_periodo` (TC)
- [ ] `tipos_plan_estudio` (TC)
- [ ] `tipos_asignatura` (TC)
- [ ] `clasificaciones_asignatura` (TC)
- [ ] `areas` (TC)
- [ ] `autorizaciones_reconocimiento` (TC)
- [ ] `turnos` (TC)

Luego las tablas:
- [ ] `campus` (T, FK → tipos_campus, entidades_federativas)
- [ ] `carreras` (T, FK → niveles_estudio)
- [ ] `planes_estudio` (T, FK → carreras, autorizaciones_reconocimiento,
      tipos_periodo)
- [ ] `asignaturas` (T, FK → tipos_asignatura, clasificaciones_asignatura, areas)
- [ ] `plan_materias` (T, FK → planes_estudio, asignaturas) — núcleo curricular.
      Índice único (plan_id, clave_en_plan).
- [ ] `esquema_evaluacion` (T, FK → plan_materias) — ponderación relacional (Σ=100).
- [ ] `seriacion` (T, FK reflexiva → plan_materias) — DAG de prerequisitos.
- [ ] `oferta` (T, FK → carreras, planes_estudio, campus, turnos) — índice único
      (carrera_id, plan_id, campus_id, turno_id).

### Módulo 3 — Formularios dinámicos
Catálogos TC:
- [ ] `tipos_campo` (TC)
- [ ] `formulario_obligatoriedad` (TC)
- [ ] `formulario_visibilidad` (TC)
- [ ] `tipos_antecedente_academico` (TC)

Tablas (la de respuestas se difiere a fase 1/módulo 4 por FK a matricula_oferta):
- [ ] `formularios` (T) — índice único (clave, version).
- [ ] `campos_formulario` (T, FK → formularios, tipos_campo, self campo_padre_id)
- [ ] `opciones_campo` (T, FK → campos_formulario)
- [ ] `formulario_asignacion` (T, FK → formularios) — polimórfico nivel/carrera/oferta/rol.

### Módulo 4 — Matrícula y admisiones (CRM)
Catálogos TC:
- [ ] `situaciones_aspirante` (TC)
- [ ] `situaciones_asesor` (TC)
- [ ] `situaciones_tutor` (TC)
- [ ] `estados_documento` (TC)
- [ ] `etapas_crm` (TC)
- [ ] `situaciones_alumno` (TC)

Tablas:
- [ ] `aspirantes` (T, FK → personas, oferta, campus, situaciones_aspirante, ciclos)
- [ ] `asesores` (T, FK → personas, situaciones_asesor) — PK persona_id.
- [ ] `tutores_crm` (T, FK → personas, situaciones_tutor) — PK persona_id.
- [ ] `aspirante_asesor` (T) — PK compuesta.
- [ ] `aspirante_tutor_crm` (T) — PK compuesta.
- [ ] `campus_asesor` (T), `campus_tutor` (T)
- [ ] `promociones` (T)
- [ ] `aspirante_promocion` (T) — PK compuesta.
- [ ] `documentos_requeridos` (T)
- [ ] `documento_carrera` (T, FK → documentos_requeridos, carreras)
- [ ] `etiquetas_documento` (T), `documento_etiqueta` (T)
- [ ] `expediente_documentos` (T, FK → aspirantes, documentos_requeridos,
      carreras, estados_documento)
- [ ] `reactivos_cleaver` (TC)
- [ ] `cleaver_aspirante` (T, FK → aspirantes, reactivos_cleaver)
- [ ] `alumnos` (T, FK → personas, situaciones_alumno) — PK persona_id.
- [ ] `matricula_oferta` (T, FK → personas, oferta, situaciones_alumno) — "el
      alumno" real. Índice único (persona_id, oferta_id) y (matricula).
- [ ] `expedientes` (T, FK → matricula_oferta)
- [ ] `respuestas_campo` (T, FK → campos_formulario, personas, matricula_oferta,
      aspirantes) — **ahora sí** (cierra la dependencia del Módulo 3).

---

## FASE 2 — Operación escolar

### Módulo 5 — Control escolar
Catálogos TC:
- [ ] `situaciones_ciclo`, `situaciones_grupo`, `situaciones_asignatura_grupo`,
      `situaciones_inscripcion`, `situaciones_docente`, `tipos_docente`,
      `aulas`, `tipos_evaluacion`, `estatus_historial`,
      `situaciones_reprobatoria`, `observaciones_historial` (todas TC).

Tablas:
- [ ] `ciclos` (T, FK → campus, situaciones_ciclo)
- [ ] `grupos` (T, FK → ciclos, campus, planes_estudio, turnos,
      situaciones_grupo, self grupo_origen_id)
- [ ] `asignatura_grupo` (T, FK → grupos, plan_materias, situaciones_asignatura_grupo)
- [ ] `horarios_asignatura_grupo` (T, FK → asignatura_grupo, aulas)
- [ ] `docentes` (T, FK → personas, tipos_docente, situaciones_docente) — PK
      persona_id (rol materializado que faltaba en Fase 1).
- [ ] `campus_docente` (T)
- [ ] `docente_asignatura_grupo` (T, FK → asignatura_grupo, personas) — PK
      compuesta, tipado titular/adjunto.
- [ ] `tutor_asignatura_grupo` (T, FK → asignatura_grupo, personas) — tutor académico.
- [ ] `inscripcion` (T, FK → matricula_oferta, asignatura_grupo, ciclos) — nivel
      único. Índice único (matricula_oferta_id, asignatura_grupo_id).
- [ ] `historial` (T, FK → matricula_oferta, plan_materias, ciclos,
      asignatura_grupo, tipos_evaluacion, estatus_historial,
      situaciones_reprobatoria, observaciones_historial) — kárdex.
- [ ] `equivalencias` (T, FK → matricula_oferta, plan_materias)

### Módulo 6 — Asistencia y reloj checador
Catálogos TC:
- [ ] `tipos_dispositivo_checador` (TC)

Tablas:
- [ ] `dispositivos_checador` (T, FK → campus)
- [ ] `checadas` (T, FK → personas, dispositivos_checador) — índice (persona_id, momento).
- [ ] `asistencia_clase` (T, FK → inscripcion, personas) — índice único (inscripcion_id, fecha).

---

## FASE 3 — Módulos de valor

### Módulo 7 — Finanzas
Catálogos TC:
- [ ] `conceptos_pago` (TC), `situaciones_pago` (TC), `metodos_pago` (TC)

Tablas:
- [ ] `planes_cobro` (T)
- [ ] `reglas_generacion` (T, FK → planes_cobro, conceptos_pago, self concepto_prerequisito_id)
- [ ] `recargos_descuentos` (T)
- [ ] `becas_alumno` (T, FK → matricula_oferta, recargos_descuentos, personas)
- [ ] `adeudos` (T, FK → matricula_oferta, conceptos_pago, reglas_generacion, ciclos)
- [ ] `pagos` (T, FK → matricula_oferta)
- [ ] `pago_adeudo` (T, FK → pagos, adeudos) — PK compuesta.
- [ ] `bitacora_situacion_financiera` (T, FK → matricula_oferta, situaciones_pago)
- [ ] `facturas` (T, FK → matricula_oferta) — CFDI 4.0, append-only.
- [ ] `factura_conceptos` (T, FK → facturas, pagos)

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
