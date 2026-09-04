# Alertas tempranas, intervención y permanencia escolar

Diseño funcional y técnico, escrito **antes** de tocar una tabla.

> **Qué es y qué NO es.** Esto es **detección explicable de señales de riesgo y
> apoyo a la intervención humana**. No predice deserción: aplica reglas que una
> persona escribió, con umbrales que una persona eligió, y enseña por qué se
> disparó cada una. Cualquier pantalla, correo o documento que salga de aquí
> tiene que poder decirse con esas palabras. Si alguna vez alguien lo describe
> como «el sistema que predice quién va a abandonar», está describiendo otra
> cosa.

---

## 1. INVENTARIO DE FUENTES

Medido contra la escuela demo el 2026-09-03. La columna que importa no es si la
tabla existe, sino **qué tan de fiar es lo que hay dentro**, porque una regla
sobre un dato flojo produce alertas que se descartan — y una cola de alertas que
se descartan enseña a ignorar la cola entera.

### 1.1 Lo CONFIABLE: hechos asentados, fechados y con respaldo

| Fuente | Tabla / servicio | Filas en el demo | Por qué es de fiar |
|---|---|---|---|
| Historial académico | `historial` (1016) vía `HistorialDelAlumno` | 1016 | Lo escribe el cierre de un ACTA con folio. No se edita: se corrige emitiendo otra acta. |
| Promedio y créditos | `HistorialDelAlumno::promedio()` / `resumen()` | — | **Es la única definición del promedio en el sistema.** Mejor intento por materia, con la precisión del plan, por MATRÍCULA. |
| Aprobado / reprobado | `EstatusAcademico` | — | Regla única, derivada de `planes_estudio.calificacion_minima_aprobatoria`. |
| Cartera y mora | `adeudos`, `pagos`, `bitacora_situacion_financiera` | 7 / 4 | Detrás hay CFDI, conciliación bancaria y corte de caja. |
| Quién es deudor | `EvaluadorDeudor` + `situaciones_pago.bloquea` | — | Ya existe y ya lo consulta `ValidadorInscripcion`. |
| Trayectoria administrativa | `movimientos_escolares` | 0 | Inmutable, con `origen` y `referencia`. Vacía hoy, pero autoritativa cuando se escribe. |
| Conducta | `incidencias`, `sanciones` | 1 / 1 | Las captura una persona, con quién las reportó. |
| Servicio social | `expedientes_proceso` + `ElegibilidadFormativa` | 0 | Máquina de estados propia, con bitácora. |
| Expediente documental | `documentos_alumno`, `expediente_documentos`, `PanoramaDocumental` | 0 / 5 | El estado lo pone quien valida, con motivo. |

### 1.2 Lo APROXIMADO: depende de que alguien haya capturado algo

| Fuente | Filas en el demo | El problema, medido |
|---|---|---|
| **Asistencia** (`asistencia_clase`) | **8 filas para 17 inscripciones** | El porcentaje se calcula **sobre lo REGISTRADO, no sobre el calendario**. Un alumno con «100 %» sobre tres sesiones no es un alumno que no ha faltado: es un alumno al que le pasaron lista tres veces. Ya está documentado en `AsistenciaPorMateria`. |
| **Captura parcial** (`calificaciones_componente`) | **1 fila** | NULL no es cero —decisión del proyecto—. Una regla de «va reprobando» leída sobre captura a medias mide qué tan rápido captura el docente, no cómo va el alumno. |
| **LMS** (`entregas` 5, `intentos` 0, `actividad_vistas` 13) | 1 solo curso vivo | Una escuela puede no usar el LMS. Cero entregas no es «no entregó»: es «aquí no se entrega nada». |
| **Días sin ingresar** (`bitacora_accesos`) | 278 filas, **3 personas distintas** | Sólo mide la plataforma. Una escuela presencial donde nadie entra al portal produciría a toda su matrícula «desconectada». |
| **Reloj checador** (`checadas`) | **0 filas** | Nunca se ha usado. **La señal «no ha entrado al plantel» NO está disponible**, y decir que sí lo estaría es prometer algo que no existe. |

### 1.3 La consecuencia de diseño

De aquí salen tres reglas del motor que no son negociables:

1. **Toda regla declara una COBERTURA MÍNIMA.** Una regla de asistencia con
   `min_sesiones: 6` no evalúa a quien tiene tres. No se dispara ni se descarta:
   **no se evalúa**, y eso se dice.
2. **Ausencia de datos no es cumplimiento ni incumplimiento.** El resultado de
   una evaluación tiene tres valores, no dos: `dispara`, `no_dispara` y
   `sin_datos`. La tercera es la que impide que media escuela salga en rojo el
   día que un docente se enferma.
3. **La cobertura viaja al tablero.** Un coordinador que ve «0 alertas de
   asistencia en el campus norte» tiene que poder distinguir «ahí nadie falta»
   de «ahí nadie pasa lista». Sin ese número, la ausencia de alertas se lee como
   ausencia de riesgo, que es el peor error que puede cometer este módulo.

### 1.4 Lo que se EXCLUYE como fuente, a propósito

- **`becas_alumno` y cualquier señal de beca o convenio de descuento.** Es un
  **proxy socioeconómico**. Usarlo subiría el riesgo de quien recibe apoyo por
  el hecho de recibirlo, y eso convierte una política de equidad en una marca.
  Lo prohíbe explícitamente el pedido y lo prohíbe el sentido común.
- **Sexo, nacionalidad, entidad de nacimiento, estado civil, `personas.foto`.**
  No entran a ninguna condición.
- **Encuesta de bienestar.** El pedido la contempla «sólo con consentimiento y
  controles adecuados». Se deja **fuera de la fase 1** y se dice por qué: hoy
  `encuestas` no tiene un mecanismo de consentimiento por sujeto ni una
  clasificación de sensibilidad, y media implementación de eso produce un
  cuestionario sobre salud emocional respondido sin que nadie haya autorizado
  que se lea. Llegará con su consentimiento, o no llegará.
- **`checadas`.** No hay datos. Se documenta como fuente futura.

---

## 2. LO QUE YA EXISTE Y NO SE DUPLICA

Este módulo toca casi todo el sistema. La lista de lo que **pregunta** en vez de
recalcular es la mitad del diseño.

| Ya existe | Se usa así | Por qué no se reescribe |
|---|---|---|
| `HistorialDelAlumno` | Se le pregunta el promedio, los créditos y las aprobadas | Es la única definición del promedio. Este proyecto ya pagó tener **tres** y dar tres números distintos. |
| `EstatusAcademico` | Aprobado / reprobado | Regla única derivada del plan. |
| `EvaluadorDeudor` + `ConvenioDePago` | Quién es deudor y quién tiene convenio | Un convenio firmado ya evita el estatus de moroso. Recalcularlo alertaría a quien ya se puso de acuerdo con la escuela. |
| `Aviso` + `avisos_destinos` + `AlcanceDeDestinos` | **TODA** notificación | Un segundo motor de avisos es lo que este proyecto rechazó al descartar `avisos_familiares`. Ya sabe segmentar por nueve destinos, tiene prioridad, vigencia y constancia de lectura. |
| `AlertasFormativas` | El **patrón** del rastro con único, escrito primero | Su tabla es de expedientes de servicio social; su forma es exactamente la que hace falta. |
| `RecordatorioDeCobranza` | El **patrón** de la escalera con `dias` con signo | Y su lección: el aviso se agrupa por persona, no uno por cargo. |
| `TransicionDeExpediente` | El **patrón** de la puerta única | Mover un caso son cinco cosas a la vez; repartidas por los controladores, la que se olvide no falla. |
| `Recorte` + `FuenteDeReporte` | Los indicadores | Con su alcance por campus, sus permisos por columna y su exportación por lotes. |
| `RegistroTarjetas` + `TarjetaPanel` | Los tableros del panel | Una tarjeta declara su permiso y su módulo. |
| `AcotaPorCampus` / `Usuario::campusVisibles()` | Todo alcance | null ≠ arreglo vacío. |
| `AccesoBitacoraTutoria` | El **patrón** de la bitácora de consulta | Registra la CONSULTA, no el contenido, y **se enseña a quien mira**: saber que la consulta queda firmada es lo que de verdad disuade. |
| `SesionTutoria.confidencial` | El **patrón** de la nota reservada | Con su permiso propio (`ver-bitacoras-tutoria`) separado del de asignar tutorías. |
| `ResolutorDeRegla` (`ProcesosFormativos`) | **NO se reutiliza, y hay que decir por qué** | Ver 5.2: allá gana UNA regla; aquí evalúan TODAS. |

### 2.1 Lo que hay que EXTRAER porque aparece el tercer consumidor

**El porcentaje de asistencia no es un servicio.** Vive en el SQL de
`AsistenciaPorMateria`, en `DocenciaController` y en `PaseListaController`. Este
módulo sería el cuarto sitio, y la regla del proyecto es clara: al aparecer el
tercer consumidor se extrae. Nace **`App\Services\Asistencia\AsistenciaDelAlumno`**
con las decisiones que hoy están repartidas —cuatro estatus, el denominador son
las sesiones REGISTRADAS, los dados de baja no cuentan— y las tres pantallas
existentes pasan a preguntarle. Sin eso, el día que la escuela decida que tres
retardos son una falta habría que cambiarlo en cuatro sitios y uno se quedaría
atrás.

### 2.2 La tutoría: por qué el caso NO se guarda en `sesiones_tutoria`

`tutorias` + `sesiones_tutoria` es lo más parecido que hay a una intervención, y
aun así no sirve como caso:

- **Cuelga de una TUTORÍA**, es decir de un tutor académico asignado por ciclo.
  Una intervención de permanencia la hace orientación, control escolar o
  finanzas — gente que no es tutora de ese alumno y no va a serlo. Reusarla
  obligaría a inventar asignaciones de tutoría falsas para poder anotar una
  llamada.
- **Es por PERSONA, no por matrícula.** Todo lo demás que este proyecto decidió
  —conducta, historial, cartera, movimientos escolares— cuelga de la matrícula,
  porque quien estudia dos programas tiene dos trayectorias.
- **No tiene responsable, ni SLA, ni estado.** Un caso sí.

Lo que **sí** se hereda es su privacidad: la marca de reservada, el permiso
aparte para leerla y la bitácora de consulta a la vista. Y la tutoría se queda
donde está: `ver-bitacoras-tutoria` sigue gobernando lo suyo, así que quien
coordine permanencia **no** obtiene por esa vía las notas del tutor académico.

---

## 3. RIESGOS DE PRIVACIDAD Y SESGO

Se escriben antes del modelo de datos porque varios de ellos lo determinan.

### 3.1 Privacidad

| Riesgo | Cómo se ataca |
|---|---|
| Un docente ve la deuda de su alumno | Las señales llevan **categoría**, y la visibilidad se decide por categoría, no por caso. Un docente con acceso al caso ve «señal administrativa/financiera pendiente» sin monto, sin concepto y sin fecha de vencimiento. El permiso `ver-alertas-financieras` es lo único que abre el detalle. |
| Una nota personal circula por la escuela | `intervenciones.visibilidad` con tres niveles y permiso propio para el reservado; la nota reservada **no viaja al frontend**, no se omite en el cliente. |
| Nadie sabe quién leyó qué | Bitácora de consulta sobre el caso, con el patrón de tutorías: se registra la consulta y **se enseña a quien mira**. |
| Un correo lleva el dato sensible | Ninguna notificación lleva monto, promedio, diagnóstico ni nota. El aviso dice **qué pasó y dónde entrar**; el dato se ve autenticado. Misma regla que ya sigue la cobranza. |
| La tabla crece para siempre | Retención configurable, con purga semanal, sobre las evaluaciones y la bitácora de consulta. |
| El alumno queda marcado | Las alertas **caducan** y los casos **se cierran**. El riesgo compuesto **decae**. No hay ninguna columna «alumno en riesgo» sobre `matricula_oferta`: el riesgo es una fila fechada, no un atributo de la persona. |
| Una exportación se va por correo | Las exportaciones pasan por el motor de reportes, con su bitácora, y las columnas sensibles llevan `permisoExtra`. |

### 3.2 Sesgo — y el grande no es el que parece

**El sesgo dominante aquí no es demográfico: es de CAPTURA.** Un docente que
pasa lista todos los días produce alertas; uno que no la pasa nunca produce
cero. Un plantel con LMS activo produce señales de desconexión; uno que da clase
sin plataforma no produce ninguna. Leído sin cuidado, el tablero dice que el
campus con mejor captura es el que peor va.

Lo que se hace al respecto:

1. **La cobertura se enseña al lado de la cifra**, siempre: «12 alertas de
   asistencia sobre 340 inscripciones con lista pasada, de 512 vivas».
2. **Los indicadores llevan tamaño mínimo de grupo.** Un desglose por generación
   y programa puede dejar celdas de dos alumnos, y eso identifica a personas
   concretas. Bajo el mínimo se dice «muy pocos para desglosar», no se enseña.
3. **Ninguna regla puede condicionarse por atributo sensible.** No hay ejes de
   sexo, nacionalidad ni beca en el alcance de una regla, y no es un olvido: no
   están y no se van a agregar. Los ejes son académicos y administrativos
   (campus, nivel, modalidad, programa, plan, ciclo, generación, tipo de alumno,
   materia).
4. **El peso lo pone la escuela y queda registrado con su versión**, así que se
   puede auditar por qué una categoría pesa el doble que otra.
5. **La tasa de descarte es un indicador de primera línea.** Una regla cuyas
   alertas se descartan el 80 % de las veces está mal calibrada, y el tablero de
   configuración lo dice **sobre la regla**, no escondido en un reporte.

### 3.3 Las tres prohibiciones duras

Van al código, no sólo al documento:

1. **Ninguna alerta ni ningún caso escribe en `matricula_oferta`, `inscripcion`,
   `historial`, `asistencia_clase`, `adeudos` ni `situaciones_*`.** El motor
   REPORTA. Es el criterio de `ConciliadorCfdi`, `acadion:auditar-datos` y
   `AlertasFormativas`, y aquí es más importante que en ninguno: la situación
   `condicionado` existe en el catálogo y nadie la usa, así que la tentación de
   ponerla sola es real. **Una prueba lo vigila.**
2. **Ningún atributo sensible entra a una condición.** La lista de ejes es
   cerrada y está en código.
3. **Ninguna etiqueta punitiva.** Ni en columnas, ni en catálogos sembrados, ni
   en pantalla. Se dice «requiere revisión», «señal de seguimiento», «riesgo
   académico», «contacto pendiente», «apoyo recomendado». **Una prueba barre las
   cadenas del módulo** contra una lista negra —«moroso», «desertor»,
   «problemático», «reprobado» como sustantivo de persona— porque una regla que
   sólo vive en la prosa se rompe en el tercer commit.

---

## 4. MODELO FUNCIONAL

```
   FUENTE                MOTOR                    TRIAGE                 CASO
   ──────                ─────                    ──────                 ────
 asistencia  ─┐                              ┌─ descartada (con motivo)
 historial    ├─→ proveedor ─→ regla vN ─→ ALERTA                     ┌─ intervención
 LMS          │      │           │           └─ validada ──→ CASO ────┼─ contacto/cita
 finanzas     │      │           │                             │      ├─ acuerdo/tarea
 expediente   │      │           └─ evidencia                  │      └─ nota (visibilidad)
 conducta     │      └─ cobertura                              │
 formativos  ─┘                                                ├─ SLA / escalamiento
                        │                                      │
                        └──→ RIESGO COMPUESTO ←─────────────────┘
                                (por matrícula, fechado, explicable)
                                                               │
                                               resultado ─→ cierre ─→ ¿recurrencia?
```

**Los siete estados de la vida de una señal**, y cada flecha es un acto humano
salvo las que dicen «motor»:

1. **Señal** — el motor evalúa una regla sobre una matrícula en una ventana.
2. **Alerta** — la evaluación cruzó el umbral. Nace `nueva`, con su evidencia.
3. **Triage** — una persona la valida o la descarta, con motivo.
4. **Caso** — una alerta validada abre o se une a un caso.
5. **Intervención** — lo que se hizo, con quién y qué se acordó.
6. **Seguimiento** — la siguiente cita, la tarea, el SLA.
7. **Resultado y cierre** — con motivo, y con la puerta abierta a reapertura.

Y en paralelo, **el motor cierra lo que dejó de ser cierto**: una alerta de
asistencia cuya asistencia se recuperó pasa a `resuelta` con la evidencia de la
mejora. Eso no lo hace una persona porque no es una decisión: es aritmética.

---

## 5. DISEÑO DE REGLAS

### 5.1 Anatomía

Una regla se parte en dos, por la misma razón que las reglas de servicio social:
**a quién alcanza** cambia poco y **qué exige** cambia cada semestre.

- **`reglas_alerta`** — identidad y alcance: nombre, descripción, categoría,
  proveedor, ejes de alcance, exclusiones, activa.
- **`reglas_alerta_versiones`** — lo que se congela: condición, ventana, umbral,
  cobertura mínima, severidad, peso, frecuencia, enfriamiento, SLA, plantilla,
  vigencia, responsable por omisión.

**La alerta guarda `regla_version_id`**, así que se puede contestar «con qué
regla y qué umbral se generó esto» dentro de dos años, aunque la regla haya
cambiado tres veces. Y **cambiar una regla no toca las alertas abiertas**: las
existentes conservan su versión; las nuevas usan la que rige. La que quedó
huérfana porque su versión se retiró se marca **obsoleta**, no resuelta —nadie
arregló nada—.

### 5.2 El alcance NO es el resolutor de servicio social

En `ProcesosFormativos`, `ResolutorDeRegla` elige **una** regla: la más
específica gana y las demás no existen. Aquí es al revés: **todas las reglas que
alcanzan a un alumno se evalúan**, porque «tres faltas seguidas» y «promedio bajo
el umbral» son dos preguntas distintas y las dos pueden ser ciertas.

Por eso no hay resolutor: hay `alcanzaA(matricula)`. Se comparte la forma de los
ejes —lo que se deja en null no acota— y **no** la desambiguación. Escrito con el
resolutor, un alumno recibiría una sola alerta de la regla más específica y las
demás señales desaparecerían sin que nadie lo notara.

**Sí hay una desambiguación, y es otra:** dos VERSIONES de la MISMA regla no
pueden regir a la vez. Ahí sí gana la vigente, y con empate, la más reciente.

### 5.3 Ejes de alcance (lista cerrada)

`campus`, `nivel_estudios`, `programa_academico`, `plan`, `modalidad`, `ciclo`,
`generacion_desde/hasta`, `tipo_alumno` (la situación de la matrícula),
`asignatura` o `categoria_materia`. Lo que se deja en null no acota. Y una lista
de **exclusiones** por matrícula, con motivo y vigencia: un alumno con una
situación conocida —una licencia médica autorizada— no tiene por qué aparecer en
la cola cada lunes.

### 5.4 Condición: por qué NO hay un editor de expresiones

La tentación es una caja donde la escuela escriba `faltas >= 3 AND dias <= 7`.
Se rechaza por lo mismo que se rechazó el campo de SQL en el constructor de
reportes: es una superficie de ejecución que ninguna lista negra cierra, y sobre
todo **no se puede explicar** — una alerta tiene que decir por qué se generó, y
una expresión arbitraria sólo se puede repetir, no explicar.

En su lugar, **cada proveedor declara sus MÉTRICAS** y una regla es
`(métrica, comparador, umbral, ventana)`. La escuela combina lo que el proveedor
ofrece; para una métrica nueva hace falta código, y eso es correcto: una métrica
es una consulta contra el modelo de datos, no un dato.

Ejemplos que se siembran **apagados** (ver 5.6):

| Regla | Métrica | Comparador | Umbral | Ventana | Cobertura mínima |
|---|---|---|---|---|---|
| Faltas consecutivas | `asistencia.faltas_consecutivas` | `>=` | 3 | últimas 4 semanas | 3 sesiones |
| Asistencia baja | `asistencia.porcentaje` | `<` | 80 | ciclo | 6 sesiones |
| Sin entregar | `lms.actividades_vencidas_sin_entrega` | `>=` | 2 | ciclo | 1 actividad vencida |
| Desconexión | `lms.dias_sin_actividad` | `>=` | 7 | 30 días | curso publicado |
| Promedio bajo | `academico.promedio` | `<` | del plan | histórico | 1 materia asentada |
| Materias en riesgo | `academico.reprobadas_ciclo` | `>=` | 2 | ciclo | 1 acta cerrada |
| Documento por vencer | `expediente.dias_para_vencer` | `<=` | 30 | — | documento con vigencia |
| Adeudo vencido | `finanzas.dias_de_atraso` | `>=` | 15 | — | 1 cargo que afecta estatus |

**Ninguno de esos números está en el código.** El umbral del promedio se lee del
plan porque ahí ya vive (`calificacion_minima_aprobatoria`); los demás los pone
la escuela.

### 5.5 Ventana, enfriamiento y deduplicación

Tres cosas distintas que se confunden:

- **Ventana**: sobre qué periodo se mide. `ciclo`, `ultimos_N_dias`,
  `desde_inicio`, `parcial`.
- **Enfriamiento** (`cooldown_dias`): tras cerrarse una alerta de esta regla
  para este alumno, cuántos días no se vuelve a levantar. Impide el rebote de
  una asistencia que oscila alrededor del umbral.
- **Deduplicación**: mientras la alerta siga ABIERTA, la regla no levanta otra.
  Se **actualiza** la que hay —con el valor observado nuevo— en vez de crear una
  segunda. Esto es lo que impide la alerta diaria por la misma causa, y lo
  sostiene un **índice único sobre una columna generada**
  (`clave_dedup = f(matricula, regla, ventana) cuando está abierta`), con la
  lección ya pagada: MySQL da dos NULL por distintos, así que un
  `unique(..., deleted_at)` no sirve.

### 5.6 Las reglas sembradas nacen APAGADAS

Ocho ejemplos, todos inactivos. Encendidas, una escuela recién migrada empieza a
levantar alertas sobre datos a medio cargar el primer día, y a la tercera nadie
las mira. Es el mismo criterio que la escalera de cobranza y que la publicación
automática de grabaciones. **La pantalla lo dice arriba**, porque ocho reglas
escritas se leen como ocho reglas funcionando.

---

## 6. MODELO DE DATOS

Trece tablas. Todas TENANT, todas con `$table->auditoria()` y `TieneAuditoria`
salvo las que se anotan.

### 6.1 Configuración

**`categorias_senal`** (TENANT-CONFIG, con seeder) — `clave`, `nombre`,
`descripcion`, **`sensible`** (bool), **`permiso_detalle`** (nullable), `color`,
`orden`, `activo`.
La bandera `sensible` es lo que separa lo académico de lo financiero **en un solo
sitio**: repartida por las pantallas, la que se olvide filtra el monto. Se
siembran: académica, asistencia, participación, administrativa, financiera
*(sensible, `ver-alertas-financieras`)*, bienestar *(sensible)*, referencia.

**`reglas_alerta`** — `nombre`, `descripcion`, `categoria_id`, `proveedor`
(clave del proveedor de señales), los ejes de 5.3, `activa`, `notas`.

**`reglas_alerta_versiones`** — `regla_id`, `version`, `vigente_desde`,
`vigente_hasta`, `metrica`, `comparador`, `umbral`, `umbral_fuente`
(`fijo` | `plan`), `ventana_tipo`, `ventana_valor`, `cobertura_minima`,
`severidad`, `peso`, `frecuencia` (`diaria`|`semanal`|`por_evento`),
`cooldown_dias`, `sla_horas`, `responsable_rol_id`, `plantilla_aviso`,
`avisa_al_alumno`, `avisa_a_la_escuela`, `notas`.

**`exclusiones_regla`** — `regla_id` (nullable = todas), `matricula_oferta_id`,
`motivo` *(obligatorio)*, `vigente_hasta`, quién la autorizó.

**`tipos_intervencion`** (TENANT-CONFIG) — `clave`, `nombre`, `exige_evidencia`,
`exige_acuerdos`, `exige_proxima_fecha`, `permite_reservada`, `activo`.
Se siembran los trece del pedido. Y las **banderas** son lo que el código lee,
nunca la clave: una escuela que invente «Canalización a servicios de salud» se
comporta como los de fábrica.

**`motivos_cierre_caso`** y **`motivos_descarte`** (TENANT-CONFIG) — con
`cuenta_como_exito` en el primero y `cuenta_como_falso_positivo` en el segundo.
Sin esa bandera, «efectividad de las intervenciones» no se puede calcular sin
cablear una lista de claves.

### 6.2 Operación

**`alertas`** — el corazón.
`matricula_oferta_id`, `regla_id`, `regla_version_id`, `categoria_id`,
`asignatura_grupo_id` (nullable: hay señales por materia y señales por alumno),
`ciclo_id`, `severidad`, `estado_senal` (`activa`|`resuelta`|`obsoleta`),
`estado_triage` (`nueva`|`validada`|`descartada`), `valor_observado`, `umbral`,
`ventana_desde`, `ventana_hasta`, **`evidencia` (JSON)**, `cobertura`,
`primera_vez_en`, `ultima_evaluacion_en`, `resuelta_en`, `motivo_descarte_id`,
`revisada_por`, `revisada_en`, `caso_id` (nullable), `clave_dedup` (generada),
`aviso_id` (nullable).

> **Por qué DOS estados y no la lista de doce del pedido.** El pedido enumera
> `nueva, pendiente_revision, validada, descartada, asignada, contacto_pendiente,
> en_intervencion, en_seguimiento, escalada, resuelta, cerrada, reabierta`. Los
> primeros cuatro son del **triage de una señal**; los ocho restantes describen
> **el trabajo de una persona**, que es el CASO. Fundirlos pondría a una señal
> en estado «en_intervención», y una señal no interviene: es cierta o dejó de
> serlo. Peor: con una sola máquina, cerrar el caso obligaría a mentir sobre la
> señal —que puede seguir siendo cierta— o a dejar el caso abierto para no
> mentir. Separadas, cada una dice la verdad de lo suyo, y el pedido queda
> cubierto entero: los doce estados existen, repartidos donde significan algo.

**`corridas_evaluacion`** — la observabilidad. `iniciada_en`, `terminada_en`,
`disparo` (`programada`|`manual`|`evento`), `matriculas_evaluadas`,
`reglas_evaluadas`, `alertas_creadas`, `alertas_actualizadas`,
`alertas_resueltas`, `sin_datos`, `errores` (JSON), `milisegundos`.
**Una regla que revienta no detiene las demás**: se aísla, se cuenta y su error
queda aquí con el nombre de la regla.

> **Lo que NO se guarda, y hay que decirlo.** No se persiste la evaluación
> negativa. 5 000 alumnos × 20 reglas × 365 días son 36 millones de filas al año
> para almacenar «hoy tampoco». Es reproducible: con la regla, su versión y la
> ventana se vuelve a calcular. Lo que sí queda es el contador por corrida y la
> evidencia de lo que **sí** disparó.

**`riesgo_matricula`** — el compuesto, fechado. `matricula_oferta_id`,
`calculado_en`, `nivel`, `puntaje`, **`desglose` (JSON: categoría → aporte →
alertas que lo forman)**, `nivel_anterior`, `ajustado_por`, `ajuste_motivo`,
`corrida_id`. **Append-only**: cada cálculo es una fila nueva, así que el
histórico existe y «conservar el cálculo anterior» sale gratis.

**`casos_permanencia`** — `matricula_oferta_id`, `campus_id`, `ciclo_id`,
`folio`, `estado`, `prioridad`, `nivel_riesgo_apertura`, `responsable_id`,
`abierto_en`, `sla_vence_en`, `primer_contacto_en`, `cerrado_en`,
`motivo_cierre_id`, `resultado`, `caso_origen_id` (reapertura),
`abierto_por`, `plan_intervencion`.
Su **folio** sale de un contador atómico por año, con la tabla **sin `id`
autoincremental** — la trampa de `contadores_matricula`, ya documentada.

**`caso_alerta`** (pivote) — una alerta cuelga de un caso; un caso reúne varias.
Con su único.

**`caso_equipo`** — `caso_id`, `persona_id`, `rol_en_el_caso`, `desde`, `hasta`.
El responsable es uno; el equipo de apoyo es N, y **ver el caso no es estar en
el equipo**: el alcance por campus y el permiso siguen mandando.

**`intervenciones`** — `caso_id`, `tipo_intervencion_id`, `objetivo`,
`responsable_id`, `fecha`, `canal`, `participantes` (JSON), `acuerdos`,
`proxima_fecha`, `resultado`, `estado`, **`visibilidad`**
(`equipo`|`caso`|`reservada`), `evidencia_ruta`.
La reservada **no viaja al frontend** para quien no tiene
`ver-notas-reservadas`: se filtra en el servidor, porque esconderla con un
`v-if` no es una defensa.

**`tareas_caso`** — `caso_id`, `titulo`, `responsable_id`, `vence_en`,
`completada_en`, `resultado`. Es lo que hace que el SLA tenga a quién reclamarle.

**`transiciones_caso`** — bitácora inmutable: `caso_id`, `estado_origen`,
`estado_destino`, `motivo`, `quien`, `ip`, `momento`. Sin `deleted_at`.

**`accesos_caso`** — la bitácora de consulta, calcada de
`AccesoBitacoraTutoria`: `caso_id`, `persona_id`, `intervenciones_vistas`,
`reservadas_ocultas`, `motivo_acceso` (nullable), `ip`, `creado_en`. Sin
auditoría (es append-only puro) y **con purga**.

### 6.3 Índices

Por lo que de verdad se consulta: `(estado_senal, estado_triage, categoria_id)`,
`(matricula_oferta_id, estado_senal)`, `(regla_id, ultima_evaluacion_en)`,
`clave_dedup` único, y en casos `(estado, responsable_id)` y
`(sla_vence_en)` para la cola de vencidos. **No se indexa una foránea «por si
acaso»** — regla del proyecto.

---

## 7. MÁQUINA DE ESTADOS

Dos, y por lo dicho en 6.2.

### 7.1 Triage de la alerta

```
        ┌───────────────► descartada (motivo obligatorio)
  nueva ┤
        └───────────────► validada ──────► (abre o se une a un CASO)
```
En paralelo, y **sin intervención humana**, el motor mueve `estado_senal`:
`activa` → `resuelta` (dejó de cumplirse: se guarda la evidencia de la mejora) o
`activa` → `obsoleta` (la regla se apagó, cambió de versión, o la matrícula salió
del alcance). Una alerta **descartada** ya no se re-evalúa: descartar es una
afirmación humana y el motor no la contradice; lo que hace el enfriamiento es
impedir que vuelva a nacer al día siguiente.

### 7.2 Ciclo de vida del caso

```
                      ┌──────────► escalado ──┐
                      │                       ▼
 abierto → asignado → contacto_pendiente → en_intervencion ⇄ en_seguimiento
                                                    │              │
                                                    └──────► resuelto → cerrado
                                                                          ┆
                                            (reabrir ⇒ OTRO caso «abierto» ┘
                                             con `caso_origen_id` puesto)
```

**`reabierto` NO es un estado**, y la flecha punteada es a propósito: reabrir no
mueve el caso cerrado, crea uno nuevo. Desde CUALQUIER estado se puede cerrar —un
caso puede terminar en cualquier punto— y desde «contacto pendiente» también: si
no se logra localizar a nadie tras intentarlo, ése es un desenlace real y el
catálogo tiene su motivo. Sin esa arista, esos casos quedarían abiertos para
siempre.

Cada transición pasa por **`TransicionDeCaso`**, una sola puerta, con el molde de
`TransicionDeExpediente`: valida el origen, el permiso y el alcance por campus,
bloquea la fila (`lockForUpdate`), anota la bitácora y todo dentro de una
transacción. **Las notificaciones se emiten DESPUÉS del commit** —
`DB::afterCommit()` —, porque un aviso de un caso que la transacción luego
deshizo es un aviso sobre algo que no pasó.

**La idempotencia tiene dos guardas**, y las dos hacen falta: la de fuera evita
un 403 confuso al re-pulsar; la de dentro, con la fila ya releída y bloqueada, es
la única que detiene la carrera de dos coordinadores con la pantalla abierta.

**Cerrar exige motivo. Reabrir exige motivo.** Y reabrir **crea un caso nuevo**
que apunta al anterior (`caso_origen_id`), en vez de resucitar el cerrado: el
cierre es un hecho fechado con su resultado, y reescribirlo borraría la medición
de recurrencia, que es justo lo que este módulo existe para medir.

---

## 8. MATRIZ DE PERMISOS Y VISIBILIDAD

Trece permisos nuevos, dominio **«Alertas y permanencia»**.

| Permiso | Faceta | Qué abre |
|---|---|---|
| `configurar-reglas-alerta` | administrativo | Reglas, versiones, catálogos, exclusiones |
| `ejecutar-evaluacion-riesgo` | administrativo | Correr el motor a mano y recalcular |
| `ver-alertas` | administrativo | La bandeja y el detalle **sin categorías sensibles** |
| `ver-alertas-financieras` | administrativo | El detalle de la categoría financiera |
| `validar-alertas` | administrativo | Validar y descartar |
| `abrir-casos` | administrativo | Crear el caso desde una alerta validada |
| `asignar-casos` | administrativo | Responsable y equipo |
| `registrar-intervenciones` | administrativo | Intervenciones, tareas y acuerdos |
| `ver-notas-reservadas` | administrativo | La visibilidad `reservada` |
| `escalar-casos` | administrativo | Subir de nivel |
| `cerrar-casos` | administrativo | Cerrar y reabrir |
| `ver-indicadores-permanencia` | administrativo | Tableros e indicadores |
| `ver-alertas-de-mis-grupos` | **docente** | Sólo sus grupos, sólo lo académico |

Y del lado del alumno se **reusa** `ver-mis-cursos`/portal: no se crea un permiso
para que vea sus propios pendientes, porque no hay nada que un alumno tenga que
poder ver de sí mismo y otro alumno no.

### 8.1 Las cuatro capas de visibilidad, y ninguna sobra

1. **Módulo**: `permanencia` en `/plataforma/modulos`. Apagado, 404 y sin menú.
2. **Permiso**: qué actos puede hacer el rol.
3. **Campus**: `Usuario::campusVisibles()` sobre el campus de la OFERTA de la
   matrícula, aplicado en listado, detalle, edición, asignación, comentario,
   descarga, exportación **y acción masiva**. El id viaja por la URL, así que
   filtrar la lista no basta.
4. **Categoría**: `categorias_senal.sensible` + `permiso_detalle`. Es la capa que
   permite que un docente vea que hay una señal administrativa sin ver la deuda.

### 8.2 El docente

**Su alcance NO lo da el permiso, lo da la asignación** (`docente_asignatura_grupo`),
igual que la captura de calificaciones: el permiso dice QUÉ, la asignación dice
SOBRE QUIÉN. Y ve **sólo las categorías no sensibles**, y sólo de los alumnos de
sus grupos.

**Además va detrás de un interruptor institucional**
(`permanencia.docente_ve_alertas`, apagado por omisión), porque el pedido lo
condiciona a «cuando la política institucional lo permita» y esa es una decisión
de la escuela, no del código. Apagado, la ruta responde 404 — mismo criterio que
la postulación autogestiva de la bolsa.

### 8.3 El alumno y la familia

El alumno ve **pendientes y recomendaciones concretas**, nunca un puntaje. Textos
del tipo «Te faltan dos entregas en Cálculo I» y «Tienes una cita el jueves», con
los canales de ayuda. **No ve su nivel de riesgo compuesto**, y eso es una
decisión: un número opaco no le sirve para actuar y sí para desanimarse.

La familia ve lo que ya puede ver por `tutores_alumno.puede_ver_academico` —y lo
financiero sólo con `puede_ver_finanzas`—. **No se inventa un destino nuevo**:
`AlcanceDeDestinos` ya sabe extender a las familias con el modificador
`familiares`.

---

## 9. JOBS, EVENTOS Y COLAS

### 9.1 Por qué comando y no botón

Los ocho ejemplos de regla se vuelven ciertos **sin que nadie haga nada**: pasa
el tiempo, se acumula una falta, vence un documento. No hay ningún punto de la
aplicación desde el que dispararlos. Mismo argumento que `finanzas:conciliar-cfdi`
y `procesos:avisar`.

### 9.2 Las piezas

| Pieza | Cuándo | Qué hace |
|---|---|---|
| `permanencia:evaluar` | diario 05:00 | El barrido completo. Por lotes con `chunkById`, por escuela, aislando cada regla. |
| `permanencia:evaluar --matricula=` | manual | Recalcular a una persona. Es lo que hace «Recalcular» en la ficha. |
| `EvaluarRiesgoMatricula` (job) | por evento | Evaluación incremental. |
| `permanencia:vigilar-sla` | diario 07:45 | Casos con SLA vencido y sin intervención → escalamiento. |
| `permanencia:purgar` | semanal dom 04:30 | Retención de corridas y de la bitácora de consulta. |

**A las 05:00 y no a las 03:00**: después de `finanzas:generar-cargos` (02:45) y
`finanzas:evaluar` (03:00), porque una regla de adeudo leería una cartera a medio
generar. Y antes de los avisos de las 07:00–07:45, para que lo que se notifique
sea de hoy.

**Cola propia** (`permanencia`) para el barrido masivo: compartir la cola con el
timbrado de facturas haría que un recálculo de cinco mil alumnos dejara los CFDI
esperando. El trabajador ya existe —lo levanta el despachador cada minuto— pero
hoy corre `queue:work` **sin `--queue=`**, o sea sólo la cola `default`: hay que
pasarle `--queue=default,permanencia` **en la misma fase que se estrene la cola**,
o los trabajos se encolarían y nadie los tomaría. Ese defecto no falla —la fila se
inserta y ahí se queda—, que es exactamente cómo este proyecto se pasó meses
encolando facturas que nadie timbraba.

### 9.3 Evaluación incremental por evento

Sin `app/Events` en el proyecto todavía, así que **se estrena**: cinco eventos de
dominio, emitidos donde ya hay un servicio único que escribe el hecho.

| Evento | Dónde se emite | Reglas que reevalúa |
|---|---|---|
| `ListaPasada` | `PaseListaController` | asistencia |
| `ActaAsentada` | `AsentadorActa` | académicas |
| `EntregaRegistrada` | el LMS | participación |
| `SituacionFinancieraCambio` | `EvaluadorDeudor` | financieras |
| `MovimientoEscolarRegistrado` | `RegistradorMovimientos` | todas (cambió el alcance) |

**El evento sólo encola**, nunca evalúa en la petición: pasar lista a treinta
alumnos no puede disparar veinte reglas por alumno mientras el docente espera.

**Y el barrido diario NO sobra teniendo eventos**: un documento que vence o un
plazo que llega no producen ningún evento. El barrido es la reconciliación; los
eventos son la reacción rápida. Los dos son idempotentes, así que coincidir no
duplica.

### 9.4 Idempotencia y observabilidad

- **Idempotente por construcción**: la `clave_dedup` con su único es lo que
  decide, no un `SELECT` previo —lo pasan dos corridas simultáneas—.
- **`withoutOverlapping()` con caducidad corta**: la de Laravel por omisión es de
  un día, y un trabajador muerto dejaría la evaluación trabada 24 horas.
- **`scheduler:estado` gana una sección**: antigüedad del último barrido, reglas
  con error en la última corrida y casos con SLA vencido. Un comando de madrugada
  que termina en verde teniendo reglas rotas es cómo esto se queda sin mirar
  durante meses, así que **sale con error si hay reglas rotas**.

---

## 10. PLAN POR FASES

Cada fase es entregable y verificable sola.

| # | Fase | Qué entrega |
|---|---|---|
| ~~**1**~~ | ~~**Cimientos, catálogos y reglas**~~ **HECHA** | Módulo `permanencia` encendido con sección propia, **cuatro** catálogos con seeder —la categoría lleva su bandera `sensible` y el permiso que abre su detalle—, `reglas_alerta` + versiones + exclusiones, `CatalogoMetricas` con doce métricas declaradas, y las ocho reglas de ejemplo **apagadas y sin avisar a nadie**. `scripts/prueba-permanencia-reglas.php`, 63 verificaciones, 28 mutaciones. |
| ~~**2**~~ | ~~**Proveedores y motor**~~ **HECHA** | **Seis** proveedores —no ocho: inscripciones y expedientes se resolvieron dentro de los otros— con su contrato completo, `AsistenciaDelAlumno` extraído con su definición explícita, `alertas` con deduplicación por columna generada, el motor con sus tres resultados y el cierre automático distinguiendo RESUELTA de OBSOLETA, y `permanencia:evaluar` a las 05:00. `scripts/prueba-permanencia-motor.php`, 73 verificaciones, 37 mutaciones. |
| ~~**3**~~ | ~~**Bandeja y triage**~~ **HECHA** | La bandeja con sus cuatro capas de visibilidad —módulo, permiso, campus y categoría—, validar y descartar con motivo del catálogo, descarte en masa que respeta el campus y lo DICE, y la ficha que explica la evidencia, la condición en palabras y **cómo hay que leer el número**. `scripts/prueba-permanencia-bandeja.php`, 52 verificaciones, 23 mutaciones. |
| ~~**4**~~ | ~~**Riesgo compuesto**~~ **HECHA** | El cálculo agrupando por **(categoría, materia)** —que es lo que evita el doble conteo sin igualar «falta en una materia» con «falta en seis»—, niveles configurables con su umbral, desglose que dice qué NO contó y por qué, decaimiento por recálculo, y el ajuste humano que CONSERVA el cálculo. `scripts/prueba-permanencia-riesgo.php`, 49 verificaciones, 25 mutaciones. |
| ~~**5**~~ | ~~**Casos e intervenciones**~~ **HECHA** | La máquina de estados de **ocho** estados en una sola puerta, folio atómico por año, **UNO abierto por matrícula** sostenido por la base, responsable y equipo, intervenciones con **tres niveles de visibilidad filtrados en el servidor**, tareas, plan, cierre con motivo del catálogo, reapertura que crea un caso nuevo, y la bitácora de consulta **enseñada a quien mira**. `scripts/prueba-permanencia-casos.php`, 96 verificaciones, 48 mutaciones. |
| **6** | **SLA, escalamiento y notificaciones** | `permanencia:vigilar-sla`, las plantillas, la deduplicación de avisos, los horarios permitidos y el registro de envío. |
| **7** | **Tableros e indicadores** | Panel de coordinación, tarjetas del panel, y las fuentes de reporte con efectividad, recurrencia y permanencia por cohorte, con tamaño mínimo de grupo. |
| **8** | **Portales** | El docente (sus grupos, tras el interruptor) y el alumno (pendientes concretos, sin puntaje). |

---

## 11. PLAN DE PRUEBAS

Suites en `scripts/` contra la base real con `DB::rollBack()`, y `tests/` para lo
que sea lógica pura. Todo comprobado **mutando**.

**Por fase**, lo que no puede faltar:

- **Aislamiento por escuela**: la evaluación de un tenant no ve ni escribe en
  otro; el job corre dentro del tenant correcto.
- **Alcance por campus**: en listado, detalle, edición, asignación, comentario,
  descarga, exportación y acción masiva. El id viaja por la URL.
- **Separación de permisos**: quien tiene `ver-alertas` y no
  `ver-alertas-financieras` **no recibe el monto en la respuesta**, no lo recibe
  oculto.
- **Versionado**: una alerta abierta conserva su versión cuando la regla cambia;
  la nueva usa la vigente; la huérfana queda **obsoleta** y no «resuelta».
- **Cada fuente**, con su caso de datos ausentes → `sin_datos`, nunca `dispara`
  ni `no_dispara`.
- **Deduplicación y enfriamiento**: dos corridas seguidas no crean dos alertas;
  el rebote alrededor del umbral no la levanta de nuevo dentro del enfriamiento.
- **Cierre y recurrencia**: la señal que mejora se resuelve con su evidencia; la
  que reaparece pasado el enfriamiento abre otra y el caso puede reabrirse.
- **Riesgo compuesto explicable**: reproducible —mismo insumo, mismo resultado—,
  sin doble conteo dentro de una categoría, y con el ajuste humano conservando el
  cálculo anterior.
- **Máquina de estados**: origen inválido rechazado con la lista de destinos
  válidos; permisos; **carrera de dos coordinadores** reproducida con una copia
  del modelo leída antes de mover.
- **Notificaciones idempotentes** y **emitidas después del commit** (una
  transacción que se deshace no deja aviso).
- **Notas reservadas**: no viajan en la respuesta sin el permiso; la bitácora de
  consulta cuenta lo mostrado y lo reservado.
- **Lotes**: el barrido no carga el tenant en memoria; una regla que revienta no
  detiene a las demás y su error queda en la corrida.
- **La prohibición dura**: una prueba que corre el motor completo y comprueba que
  `matricula_oferta`, `inscripcion`, `historial`, `asistencia_clase`, `adeudos` y
  las situaciones **no cambiaron ni una fila**.
- **Lenguaje**: barrido de cadenas del módulo contra la lista negra.

**Los ocho casos límite del pedido**, todos construidos porque el demo no los
tiene:

| Caso | Lo que tiene que pasar |
|---|---|
| Calificación aún no asentada | La regla académica lee `historial`, no captura parcial: **no dispara** |
| El alumno cambió de plan | La regla que acota por plan deja de alcanzarlo → alerta **obsoleta**, no resuelta |
| Dos ciclos simultáneos | La ventana `ciclo` se resuelve por la inscripción, no por «el ciclo abierto» |
| Actividad que aún no vence | No cuenta como «no entregada» |
| Asistencia incompleta | Bajo la cobertura mínima → `sin_datos` |
| Pago pendiente de confirmar | No es un pago: el adeudo sigue vencido, y la evidencia lo dice |
| Usuario de otro campus | 404 en el detalle (no 403: confirmaría que existe) |
| La regla cambia con alertas abiertas | Las abiertas conservan su versión |

---

### Lo que la fase 1 dejó decidido, y no estaba en este plan

1. **`CatalogoMetricas` se declara ANTES que los proveedores.** El plan decía
   que la fase 1 entregaba las reglas de ejemplo, y una regla necesita una
   métrica que exista: sin la declaración, la pantalla ofrecería texto libre y
   guardar una regla con una métrica inventada la dejaría sin poderse calcular
   jamás. Las métricas se declaran ya, los proveedores que las calculan llegan
   en la fase 2, y **una prueba tiene que cruzar las dos listas** —guarda
   ruidosa— para que una métrica sin proveedor falle en rojo en vez de quedarse
   muda.

2. **El proveedor se DERIVA de la métrica.** No se captura: capturado, alguien
   elegiría «asistencia» con una métrica académica y la regla se guardaría sin
   poderse calcular. Lo cazó el barrido de mutaciones —el docblock lo prometía y
   ninguna línea lo hacía—.

3. **La regla nace apagada TAMBIÉN en el modelo**, no sólo en el controlador. Es
   lo contrario de `ReglaProceso`, y la razón es que una regla de alerta activa
   empieza a poner gente en la cola de alguien en la siguiente corrida. El
   default importa el día que otro camino cree una regla.

4. **`CatalogoEditable` gana `editable: false`.** Una bandera que es una
   decisión de SEGURIDAD —qué categoría es reservada— se ve en la lista y no
   aparece en el formulario, y el servidor la descarta aunque llegue en la
   petición: la pantalla que no la ofrece no es una defensa. Es la línea de
   `niveles_estudio.protegido`.

5. **La guarda va ANTES de escribir.** Encender una regla sin versión vigente
   escribía primero y se quejaba después: la regla quedaba encendida y quien la
   pulsó leía «no se puede encender». Un rechazo que no rechaza enseña a no
   creerle a los avisos.

6. **Una suite que afirma sobre lo SEMBRADO tiene que correr el seeder.** Las
   tres mutaciones del seeder sobrevivieron porque la suite leía lo que ya
   estaba en la base: cambiar el seeder para que las reglas nazcan encendidas no
   tumbaba nada, y el defecto habría aparecido en la siguiente escuela migrada,
   con las alertas ya saliendo.

---

### Lo que la fase 2 dejó decidido, y no estaba en este plan

1. **El porcentaje de asistencia ya se calculaba de DOS maneras distintas**, y
   dan números distintos: el reporte suma las justificadas y no los retardos; la
   pantalla del docente suma los retardos y no las justificadas. Para un alumno
   con 6 presentes, 3 justificadas y 1 retardo, el reporte dice 90 % y la
   pantalla 70 %. `AsistenciaDelAlumno` fija la tercera —**todo lo que no es
   falta**, que es la que corresponde a «el derecho se pierde por faltas»— y
   **NO cambia ninguna de las dos existentes**: cambiar un número que una
   escuela ya lee es una decisión suya, no un refactor. Queda anotado para que
   se decida a la vista de las tres cifras.

2. **Los módulos NÚCLEO figuran como apagados**, y eso silenció dos proveedores
   enteros sin un solo error. `asistencia`, `lms`, `finanzas` y
   `control_escolar` están en el catálogo de módulos y **no tienen fila en
   `modulos_activos`**, así que `ModulosDeLaEscuela` —que falla cerrado— dice
   que están apagados; ninguna ruta los gatea con `modulo:`. Declararlos en un
   proveedor lo dejaba sin evaluar y la corrida decía «0 reglas». **Un proveedor
   sólo declara el módulo que alguna ruta gatea de verdad**; lo demás se
   comprueba con los datos, que además es más fino: una escuela puede usar el
   LMS en tres materias y en las demás no.

3. **Los 1016 renglones de historial del demo NO tienen `acta_folio`.** La
   primera versión del proveedor académico filtraba por él —«sólo lo que salió
   de un acta»— y eso dejaba ciega la señal en cualquier escuela que llegue
   migrada de otro sistema, que son casi todas. Se filtra por ESTATUS: lo que
   sigue «en curso» no es un intento fallido, y las revalidaciones y
   equivalencias ya quedan fuera porque ninguna se asienta como reprobada.

4. **Una métrica que un proveedor no conoce REVIENTA.** La primera versión la
   medía como si fuera otra: `ProveedorAsistencia` caía en la rama del
   porcentaje para cualquier clave que no fuera la de las faltas, así que una
   regla mal configurada habría comparado el porcentaje de asistencia contra un
   umbral de promedio y levantado alertas que parecen buenas. Reventar es lo
   correcto porque el motor aísla cada regla y reporta el fallo con su nombre.

5. **No se persiste la evaluación negativa** (ya estaba decidido) **pero sí el
   contador por corrida**, y ahí va también el error POR REGLA con su nombre.
   Con un id habría que cruzarlo contra una tabla, y esto lo lee quien
   administra a las siete de la mañana.

6. **`replicate()` sobre una tabla con columna generada revienta.** Copia
   también la columna, y MySQL responde «The value specified for generated
   column is not allowed». Vale para las cinco tablas de este proyecto que
   tienen una.

---

### Lo que la fase 3 dejó decidido, y no estaba en este plan

1. **La bandeja dice CUÁNDO corrió el motor**, y con un aviso en ámbar si lleva
   más de dos días. Sin ese dato, una cola vacía se lee como ausencia de riesgo,
   que es la peor lectura que este módulo puede inducir. Es el mismo criterio con
   el que la cobertura viaja al lado de la cifra.

2. **La tasa de descarte de 30 días va ARRIBA**, junto a las demás cifras. Una
   cola que se descarta entera no es una cola: es ruido, y quien la mira todos
   los días tiene que verlo antes de acostumbrarse a ignorarla.

3. **Descartar NO mueve el estado de la señal.** Una señal descartada sigue
   siendo cierta —lo que se descartó es que amerite seguimiento—, y moverla a
   «resuelta» diría que la situación mejoró. Son dos ejes y por eso son dos
   columnas.

4. **El descarte en masa hacía falta y dice lo que no pudo.** Una regla mal
   calibrada levanta cuarenta alertas la misma madrugada; descartarlas de una en
   una es cómo se llega a que nadie las descarte. El alcance se comprueba una por
   una contra la misma consulta base, y las que quedan fuera **se cuentan en el
   aviso**: en silencio, quien pulsa creería que descartó las cuarenta.

5. **«Otras señales de esta persona» mira a la PERSONA, no a la matrícula.** La
   primera versión decía una cosa y hacía otra; y como una matrícula tiene un
   solo campus, el recorte ahí no hacía nada —lo enseñó el barrido de mutaciones,
   sobreviviendo—. Mirando a la persona, sus dos programas pueden estar en
   planteles distintos y el recorte pasa a ser necesario.

6. **La CALIDAD de la fuente viaja con la alerta.** «Se lee del historial
   asentado» o «se calcula sobre las sesiones registradas» es lo que impide leer
   un 60 % como si fuera del semestre entero. Sin ese renglón, quien valida
   decide sobre un número que cree entender.

7. **Una comprobación atada a un estado temporal tiene que fallar RUIDOSAMENTE.**
   La suite de la fase 1 afirmaba «ese permiso todavía no existe, así que nadie
   alcanza esta categoría»; la fase 3 lo declaró y la suite se cayó en rojo. Eso
   es lo correcto: la alternativa —una comprobación que se apaga sola— es cómo se
   llega a una suite que no prueba nada.

---

### Lo que la fase 4 dejó decidido, y no estaba en este plan

1. **El grupo de deduplicación es (categoría, MATERIA), no la categoría sola.**
   El plan decía «máximo dentro de una categoría» y eso está mal: perder
   asistencia en seis materias es peor que perderla en una, y el máximo por
   categoría las hace iguales. Dentro de cada (categoría, materia) gana la más
   grave y los grupos se suman, así que dos señales de asistencia de la MISMA
   materia cuentan una vez y de materias distintas cuentan dos.

2. **Los niveles son un CATÁLOGO con umbral configurable**, no un enum. Qué
   puntaje es «alto» depende del tamaño de la matrícula: lo que en mil alumnos es
   una cola manejable, en ciento veinte es media escuela.

3. **El desglose dice lo que NO contó.** Sin eso, quien mire verá tres señales y
   un aporte que sólo explica una, y no habrá forma de saber si faltó algo o si
   se descontó a propósito.

4. **El decaimiento no es una fórmula: es recalcular.** El puntaje sale de las
   alertas abiertas, así que baja solo cuando una se resuelve. Una curva de
   olvido haría que el número cambiara sin que nada hubiera pasado, que es lo
   contrario de explicable. Y **una alerta DESCARTADA tampoco suma**: una persona
   dijo que no amerita, y contarla enseñaría que descartar no sirve de nada.

5. **Se escribe una fila SÓLO cuando algo cambia.** Un renglón por matrícula por
   corrida serían 1.8 millones al año diciendo «sigue igual», y así la tabla es
   la historia de los cambios — que es lo que alguien va a querer leer.

6. **El ajuste no tiene permiso propio**, va con `validar-alertas`: quien puede
   descartar todas las señales de alguien ya puede, de hecho, bajarle el riesgo.
   Un permiso más sin un acto que proteger es una llave que la escuela reparte
   sin saber para qué.

7. **Y un defecto que costó encontrar: un scope con `ORDER BY` envenena la
   consulta que lo use.** `scopeActivos` ordena ascendente; encadenarle un
   `orderByDesc` produce `ORDER BY x ASC, x DESC` —donde gana el primero— y TODO
   puntaje caía en el nivel más bajo, **sin un solo error**. Se usa `reorder()` o
   no se usa el scope.

---

### Lo que la fase 5 dejó decidido, y no estaba en este plan

1. **`AbridorDeCaso` vive APARTE de `TransicionDeCaso`.** Abrir son cuatro cosas
   que las demás transiciones no hacen: numerar de forma atómica, congelar el
   riesgo del momento, atar la señal que lo originó y garantizar que no salgan
   dos. Metidas entre las demás, cualquiera se perdería el día que alguien
   agregue un estado. Es la separación de `LiberadorDeExpediente` frente a
   `TransicionDeExpediente`. Aun así el caso **nace por la puerta de siempre**:
   la apertura anota su renglón en `transiciones_caso` con el origen en NULL —
   sin él, «cuánto tarda un caso en asignarse» no tendría desde cuándo contar.

2. **Abrir sobre quien YA tiene caso NO crea un segundo: le SUMA la señal.**
   Alguien acompañado por su asistencia al que además le sale una señal
   académica no necesita dos expedientes: con dos, las intervenciones se
   reparten y acaban dos personas llamando al mismo alumno. Lo sostiene un
   ÚNICO sobre columna generada (`matricula_si_abierto`), no un `SELECT` previo
   —que dos coordinadores mirando la misma señal pasan los dos—.
   - La lista de estados que ocupan la matrícula está escrita **dos veces**: en
     `EstadoCaso::ocupaLaMatricula()` y en el `CASE` de la columna generada, que
     evalúa MySQL. Una comprobación las cruza: sin quien las compare se separan
     el día que se agregue un estado, y el único empezaría a permitir o impedir
     lo que no debe, **sin fallar**.

3. **OCHO estados y no los doce del pedido.** `nueva`, `pendiente_revision`,
   `validada` y `descartada` son el triage de una SEÑAL y viven en `alertas`.
   Fundir las dos máquinas pondría a una señal en «en intervención» —y una señal
   no interviene: es cierta o dejó de serlo—, y cerrar el caso obligaría a
   mentir sobre la señal o a dejarlo abierto para no mentir.
   - **`reabierto` tampoco es un estado**: reabrir es un caso NUEVO que apunta al
     anterior (`caso_origen_id`). El cierre es un hecho fechado con su resultado,
     y reescribirlo borraría la medición de RECURRENCIA — que es justo lo que
     este módulo existe para medir. Molde del acta de corrección, de la nota de
     crédito y de la liberación formativa.
   - Desde «contacto pendiente» **se puede cerrar**: si no se logra localizar a
     nadie tras intentarlo, ése es un desenlace real y el catálogo tiene su
     motivo. Sin esa arista esos casos quedarían abiertos para siempre y la cola
     dejaría de significar algo.

4. **CINCO permisos y ninguno sobra**, porque son cinco oficios: `abrir-casos`,
   `asignar-casos`, `registrar-intervenciones`, `ver-notas-reservadas`,
   `escalar-casos` y `cerrar-casos`. Con un permiso único, quien captura una
   llamada podría cerrar el caso — y cerrar es la afirmación de que la situación
   se atendió, que es lo que después se cuenta como éxito.

5. **`ver-notas-reservadas` es la pieza de privacidad del módulo, y va aparte de
   registrar.** Lo que hay en una nota reservada son situaciones personales del
   alumno o de su familia; quien captura contactos todos los días no necesita
   leerlas. **Lo que no se alcanza NO VIAJA**: se filtra en el servidor, porque
   esconderlo con un `v-if` deja el dato en la respuesta y basta abrir la
   consola.
   - **Y no toda intervención admite reserva**: lo dice su TIPO
     (`permite_reservada`). Un «seguimiento de asistencia» reservado esconde de
     su propio equipo el dato que el equipo necesita y a cambio no protege nada.
     Ofrecer la casilla en todas la convierte en algo que se palomea por
     costumbre.
   - **Se DICE cuántas quedaron ocultas.** Callarlas haría creer que el caso
     está vacío, y quien lo atiende tiene derecho a saber que hay algo que no ve
     —aunque no pueda leerlo—.
   - **Quien alcanza lo reservado alcanza lo del equipo.** Sin esa segunda rama,
     el rol con el permiso más alto vería las notas reservadas y NO las del
     equipo, que es al revés de lo que espera cualquiera.

6. **Estar en el equipo NO concede acceso.** Eso lo siguen decidiendo el permiso
   y el campus. `caso_equipo` dice quién PARTICIPA; confundirlo convertiría una
   lista de trabajo en un mecanismo de autorización paralelo, y agregar a alguien
   al equipo sería una forma de darle permisos sin pasar por los roles. Lo que sí
   decide es la visibilidad `equipo` de una intervención, que es otra pregunta:
   no «¿puede entrar?» sino «¿esto es suyo?».
   - **El responsable cuenta como equipo aunque no esté en la tabla**: es quien
     lleva el caso, y dejarlo fuera haría que no viera sus propias notas.
   - **Sacar a alguien le pone fecha de SALIDA, no lo borra**: sus notas de
     equipo siguen explicándose por su participación.

7. **El PRIMER CONTACTO se anota desde la intervención, no desde el estado.** Es
   un hecho —«se habló con alguien»— y no un punto del recorrido: escrito dentro
   de `mover()`, pasar a «en intervención» lo marcaría aunque no se haya hablado
   con nadie, y el indicador de «cuánto tardamos» mediría otra cosa. Y **una
   intervención PROGRAMADA no cuenta**: agendar una cita no es haber hablado.

8. **El SLA marca y ordena; no bloquea nada.** `scopeSlaVencido` exige las tres
   condiciones —abierto, con plazo, y SIN contacto—: uno atendido a tiempo no
   está vencido aunque siga abierto, y contarlo llenaría la cola de casos ya
   atendidos. Y el plazo se fija al ASIGNAR **sólo si no lo había**: reescribirlo
   al reasignar movería la meta de sitio y un caso vencido dejaría de estarlo con
   cambiarle el responsable.

9. **El campus se COPIA al abrir, no se lee por relación.** Un alumno que cambia
   de plantel no puede hacer que un caso cerrado desaparezca del reporte del
   plantel donde de verdad se atendió. Y **un caso sin campus lo alcanza
   cualquiera**: esconderlo de todo el mundo lo convertiría en un caso que nadie
   atiende.

10. **La bitácora de consulta se ENSEÑA a quien mira.** Escondida en una tabla
    que sólo consulta un administrador es un trámite forense; a la vista, es lo
    que de verdad disuade. Guarda cuántas intervenciones se mostraron y cuántas
    quedaron reservadas, **nunca su contenido**: una auditoría que copie lo
    vigilado multiplica el problema que intenta resolver.

11. **El motivo del cierre sale del CATÁLOGO, no de un texto.** De su bandera
    `cuenta_como_exito` —con sus tres valores, incluido el NULL de «ni una cosa
    ni otra»— sale si el acompañamiento sirvió. Con texto libre habría que leer
    trescientas frases para saberlo. El texto también se pide, y es otra cosa: la
    explicación.

12. **Lo que sólo se vio MIRANDO**, y ninguna prueba veía: «2 intervenciónes»
    (el plural pierde el acento), «a las 192 h de abrirse» (el indicador se lee
    de un vistazo o no se lee), los SEGUNDOS en cada sello de tiempo, la ficha
    de la señal diciendo «se está atendiendo» sobre un caso CERRADO mientras
    escondía el vivo, «CASO-2026-00001· Cerrado» sin el espacio que Vue condensó,
    «Falta Elige el tipo de intervención» como una sola frase, el desplegable de
    prioridad arrancando en «media» mientras el texto prometía derivarla, y —el
    más caro— **los errores de validación del servidor sin pintar**: se pulsaba,
    la ventana seguía abierta y no pasaba nada.

13. **Un defecto de las fases 1 a 4 que sólo se vio MIRANDO:** las píldoras del
    módulo salían **sin color**. `categorias_senal.color`, `niveles_riesgo.color`
    y `EstadoCaso::color()` guardan un NOMBRE («ambar», «naranja»), y se pasaba
    directo a `PildoraEstado`, que espera un color de CSS. `color: ambar` no es
    válido, así que el navegador lo DESCARTA sin error y la píldora sale con el
    color heredado y sin fondo. Se resolvió con `@/utils/coloresPermanencia`, una
    tabla compartida — copiada en cada pantalla es como se llega a que el «alto»
    de la bandeja sea de otro naranja que el de la ficha.

---

## 12. LIMITACIONES Y SUPUESTOS

Se escriben aquí para que nadie las descubra en una demo:

1. **No predice nada.** Aplica reglas deterministas. Sin machine learning, por
   pedido explícito y porque una primera versión tiene que ser explicable.
2. **La calidad de la señal es la calidad de la captura.** Ver 1.2 y 3.2. En el
   demo, la asistencia son 8 filas para 17 inscripciones y el reloj checador está
   vacío: varias reglas sólo se pueden probar con escenario construido.
3. **No hay señal de «no entró al plantel»**: `checadas` nunca se ha usado.
4. **La encuesta de bienestar queda fuera** hasta que exista consentimiento por
   sujeto (ver 1.4).
5. **El correo no sale**: el driver es `log`. Las notificaciones viven en
   `avisos`, que es lo que la plataforma tiene y lo que el alumno lee al entrar.
6. **La efectividad de una intervención se MIDE por lo que cambió después**
   (asistencia, entregas, promedio), no por lo que el responsable opine. Es un
   indicador con retraso: no hay número el mismo día.
7. **El tamaño mínimo de grupo suprime celdas.** Un desglose por generación y
   programa a veces dirá «muy pocos para desglosar». Es deliberado.
8. **No hay canal SMS ni push.** Y aunque los hubiera, no llevarían el dato.
