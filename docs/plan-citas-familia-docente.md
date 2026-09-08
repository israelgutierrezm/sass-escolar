# Plan — Citas familia–docente (7.5)

Diseño escrito ANTES de tocar una tabla. Módulo Familia, flujo 7.5 (era **E**: no
existía nada — verificado: no hay `Cita`/`Reunion`/`Entrevista`, el portal del
padre no tiene citas, y `DisponibilidadDocente` es para GENERAR HORARIOS de clase,
no para reunirse con padres).

## Qué es, y qué NO es

Que una familia pida una reunión con el docente de su hijo, el docente la
confirme o la rechace, y no se encimen dos. NO es una videollamada (eso son las
clases en línea), ni la bitácora del tutor educativo (`sesiones_tutoria`, que es
tutor↔alumno y un registro de lo YA ocurrido, no una solicitud con dos partes).

## Lo que se REUSA (no se reinventa)

- **El vínculo familia↔docente se DERIVA, no se guarda.** Quién puede pedirle
  cita a quién sale de `tutores_alumno` (hijo↔familia) cruzado con
  `docente_asignatura_grupo` vía las inscripciones del hijo. Una tabla de
  "familias de un docente" sería un segundo padrón que se separaría del vínculo.
- **Las notificaciones** van por el motor de avisos (`Aviso`, `avisos_destinos`).
  `DestinoEvento::Alumno` casa contra `persona_id`, así que sirve igual para
  avisarle a un docente —el mismo truco del aviso de documento rechazado—.
- **El traslape** se mide con la regla de dos condiciones que ya usan las clases
  en línea (`inicio < otroFin && fin > otroInicio`), y las horas se comparan en
  minutos como en `DisponibilidadDocente::aMinutos`.
- **La máquina de estados** sigue el molde de `seguimientos_aspirante` y de las
  transiciones de permanencia: estados con dueño de la siguiente acción.

## Por qué la disponibilidad de citas es SEPARADA de la de clases

`disponibilidad_docente` dice cuándo el docente puede DAR CLASE; una cita con un
padre ocurre justo cuando NO está en clase. Reusar esa tabla dejaría reservar una
cita a las 9:00 con un docente que a esa hora está frente a un grupo. Son dos
preguntas distintas y por eso son dos tablas.

## El modelo de datos

### `disponibilidad_cita_docente` — las ventanas que el docente ofrece

Semanal y HABITUAL (sin ciclo): las horas de atención a padres no cambian cada
periodo como el horario de clases.

- `persona_id` (FK `docentes.persona_id`)
- `dia_semana` (1 = lunes … 7 = domingo, como en horarios)
- `hora_inicio` / `hora_fin` (time)
- `modalidad` (`presencial` / `en_linea` / `telefonica`) — una ventana, una
  modalidad; el docente agrega varias si atiende de varias formas
- `duracion_min` (default 20) — cuánto dura cada cita dentro de la ventana
- `lugar` (nullable) — «Sala de maestros», o la nota
- auditoría

### `citas_familia_docente` — la cita

- `docente_persona_id`, `alumno_persona_id`, `solicitante_persona_id` (FK
  `personas`) — el docente, el hijo de quien se habla, y el familiar que la pide
- `inicio` / `fin` (datetime) — se calculan de la fecha + hora + `duracion_min`
  de la ventana; el SERVIDOR recalcula `fin`, nunca se cree del cliente
- `modalidad` — heredada de la ventana
- `motivo` (text, requerido) — para qué quiere verse; sin él el docente no sabe
  si es urgente
- `lugar` (nullable)
- `estado` — `solicitada` → `confirmada` / `rechazada` / `cancelada`;
  `confirmada` → `realizada` / `no_asistio` / `cancelada`
- `respuesta` (nullable) — la nota del docente al confirmar o rechazar (puede
  sugerir otra hora)
- auditoría (quién y cuándo, para cada cambio, vía `updated_by`)

## Los estados, y por qué NO hay «reprogramada»

Reprogramar = **rechazar sugiriendo otra hora + volver a pedir**. Un estado de
negociación de dos partes («el docente propone, la familia acepta») duplicaría la
máquina y dejaría citas colgando de quién contesta. Así siempre hay UN dueño
claro de la siguiente acción: la solicitud espera al docente, el rechazo con
sugerencia espera a la familia. Es el criterio del proyecto de no construir
complejidad especulativa.

## La regla: `GestorDeCitas`

Vive en UN sitio; la usan los dos portales.

- **Solicitar** (familia): valida que (1) el hijo es suyo (`tutores_alumno`),
  (2) el docente da clase a su hijo (`docente_asignatura_grupo` vía inscripción),
  (3) la hora cae DENTRO de una ventana del docente para ese día y modalidad,
  (4) no es en el pasado, (5) no se encima con otra cita CONFIRMADA del docente.
  Sin cualquiera de ellas, se rehúsa con su razón.
- **Confirmar** (docente): bajo BLOQUEO del docente (todas sus citas activas
  `lockForUpdate` en la transacción), revalida el traslape con lo confirmado —dos
  confirmaciones simultáneas de horas encimadas no pueden pasar las dos— y pasa a
  `confirmada`. Es el molde del bloqueo del expediente en las horas formativas.
- **Rechazar** (docente): exige motivo (`respuesta`); pasa a `rechazada`.
- **Cancelar** (familia o docente): sólo antes de ocurrir; pasa a `cancelada`
  con motivo.
- **Marcar realizada / no_asistió** (docente): sólo una cita `confirmada` cuya
  hora ya pasó.

Toda transición comprueba el ESTADO de origen y el permiso/vínculo; un
`update(['estado'=>…])` suelto se los salta.

## Quién hace qué

- **La FAMILIA** (`solicitar-citas`, faceta PADRE) pide y cancela citas de SUS
  hijos, con los docentes que le dan clase. En `/mis-hijos/{hijo}/citas`.
- **El DOCENTE** (`gestionar-mis-citas`, faceta DOCENTE) declara sus ventanas de
  atención y responde las solicitudes de SUS alumnos. En `/docencia/citas`. El
  alcance sale de `docente_asignatura_grupo`, no del permiso.

## Notificaciones

- Solicitud → aviso al DOCENTE (destino Alumno = su persona), Importante.
- Confirmación / rechazo / cancelación → aviso al SOLICITANTE (destino Alumno =
  su persona), Importante. Caducan a los pocos días; no es correo.

## Privacidad y alcance

- La familia sólo ve a los docentes que dan clase a su hijo, y sólo pide por un
  hijo suyo; lo ajeno responde 404 (no 403: no confirma que exista).
- El docente sólo ve y responde citas donde ÉL es el docente.
- El `motivo` que escribe la familia lo lee el docente (es para él); la
  `respuesta` del docente la lee la familia.

## Verificación

Sin navegador (el login es de personas y no tecleo contraseñas): suite contra la
BD real con `DB::rollBack()`, mutando las reglas de seguridad y de traslape;
`npm run build`; y el recorrido por HTTP invocando los controladores. Nada de
datos reales ni avisos fuera de la transacción de la prueba.
