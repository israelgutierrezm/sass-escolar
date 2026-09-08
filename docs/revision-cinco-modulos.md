# Revisión de cinco módulos — matriz de verificación

Verificado en código el 2026-09-07, ANTES de construir. Clasificación:
**A** existe y funciona · **B** existe parcial, completar · **C** existe pero
desconectado · **D** existe con defecto · **E** no existe · **F** no aplica /
cruza algo institucional o SEP.

La SEP queda fuera por instrucción. Lo marcado **E-decisión** no se puede
construir sin una decisión institucional o un servicio de pago (el pedido lo
exige así), y se deja fuera del build hasta esa decisión.

---

## 3. LMS

| Capacidad | Estado | Evidencia | Brecha | Solución | Pruebas |
|---|---|---|---|---|---|
| 3.1 Borrador/publicar/retirar | **A** | `InterruptorVisible.vue`, `Actividad::scopeVisibles`, `AulaController` | — | conservar | — |
| 3.1 Plantilla vs curso impartido | **A** | `Lms/CopiadorDeCurso.php` copia al grupo, no apunta a la plantilla | — | conservar | — |
| 3.1 Evidencia congelada de lo que hizo el alumno | **A** | `esquema_evaluacion` materializado, rúbrica congelada al primer uso, portafolio congela snapshot | — | conservar | — |
| 3.1 Versiones identificables de material/actividad | **B** | hay congelamiento pero no un nº de versión legible del material | no se puede citar «la v2 del material» | agregar `version` incremental a actividad/contenido, como en formularios y reglas | versión sube al editar contenido publicado |
| 3.2 SCORM real (paquete) | **E** | sólo `<iframe>` con sandbox en `HtmlSeguro` para «un SCORM ya producido»; no hay tabla de paquete, manifiesto ni tracking `cmi` | no se sube ni ejecuta un `.zip` SCORM, no se registra avance/score/reanudación | subsistema nuevo: carga validada, manifiesto, runtime aislado, tracking. **Requiere estudiar el estándar (SCORM 1.2/2004) antes** | paquete válido/ inválido, traversal, zip-bomb, reanudar intento |
| 3.3 LTI | **E-decisión** | nada | — | conectar herramientas externas; envía datos de alumno a terceros → **decisión institucional** | — |
| 3.3 QTI (import/export) | **E** | nada | no se importan/exportan reactivos | adaptador para los tipos de `TipoReactivo` compatibles, informando conversiones | round-trip de cada tipo soportado |
| 3.3 xAPI / LRS | **E-decisión** | nada | — | cola de eventos a un LRS configurable → **decisión + servicio externo** | — |
| 3.4 Prerrequisitos ENTRE actividades | **✅ HECHO** (era E) | `actividades.prerequisito_id` (auto-FK); `App\Services\Lms\Prerequisitos` (candado + validación + criterio único de «completada»); gating en aula/entrega/examen/portafolio/foro; remapeo en `CopiadorDeCurso` | — | implementado: bloquea hasta completar el prerrequisito, falla abierto, enforcement en el servidor, ciclo rechazado, motivo visible | `prueba-prerequisitos-actividad.php` (26 verif, 9 mutaciones) |
| 3.4 Liberación por fecha/resultado | **B** | `abre_en`/`cierra_en` existen (ventana temporal); no por resultado de otra actividad | falta gating por finalización/nota | se apoya en 3.4-prerrequisitos | libera al cumplir, sigue bloqueada si no |
| 3.4 Competencias / resultados de aprendizaje | **E** | nada | no hay competencias asociadas a actividad/rúbrica ni seguimiento de logro | catálogo de competencias + mapeo a rúbrica/actividad + logro con evidencia (no promedio) | logro con evidencia ≠ promedio |
| 3.5 Plagio / proctoring | **E-decisión** | nada | — | adaptadores configurables a servicio externo; **no inventar detector**; **decisión + proveedor de pago** | estados pendiente/procesando/disponible/fallido con doble del proveedor |
| 3.6 Móvil / offline | **E** | nada | sin contrato de descarga/borrador/sincronización idempotente | definir contratos backend (idempotencia de entrega, estados de sync); **no construir Flutter** | entrega idempotente no duplica |

## 4. Servicio social y prácticas

| Capacidad | Estado | Evidencia | Brecha | Solución | Pruebas |
|---|---|---|---|---|---|
| Solicitudes, reglas, versiones, docs, horas, evaluaciones, liberación, alertas | **A** | 7 fases documentadas y probadas (`ExpedienteProceso`, `RegistradorDeHoras`, `LiberadorDeExpediente`, `EvaluacionProceso`, `procesos:avisar`) | — | conservar | — |
| 4.1 Supervisor externo (acceso) | **✅ HECHO** (era E) | Faceta `supervisor_externo`; `AccesoSupervisorExterno` (invitar/revocar, vigencia en el contacto); `AlcanceDeExpedientes` consciente de la faceta; portal `/supervision` + admin `/procesos/supervisores`; `PresentadorDeSeguimiento` compartido | — | implementado: ve SÓLO sus asignados con acceso vigente, aprueba horas y revisa informes/evaluación, sin cartera ni calificaciones; revocar corta | `prueba-supervisor-externo.php` (58 verif, 9 mutaciones) + recorrido HTTP por middleware (8/8) |
| 4.2 Reportes: borrador/envío/devolución/aprobación + historial | **A** | `InformeProceso` con estados y devolución con motivo; re-entrega vuelve a «sin revisar» | — | conservar | — |
| 4.2 «Acuse» ≠ «firma digital» | **B** | hay aprobación con auditoría (quién/cuándo), no se llama firma digital | conviene rótulo explícito de que es acuse, no firma | etiqueta en la UI | — |
| 4.2 Geolocalización opcional | **A** | `procesos.pedir_ubicacion` apagado por omisión; servidor descarta coords si no se pide; no cuenta como asistencia | — | conservar | — |
| 4.3 Seguimiento, transiciones, cupos | **A** | `TransicionDeExpediente`, cupo con `lockForUpdate` + CHECK, alertas por plazo | — | conservar | — |
| 4.4 Liberación verificable (folio, snapshot, corrección) | **A** | `LiberadorDeExpediente`, folio atómico, snapshot congelado, corrección que jubila | — | conservar (no es integración SEP) | — |
| 4.5 Evaluación org por alumno + indicadores | **B** | `EvaluacionProceso` con 3 orígenes (supervisor, coordinador, autoevaluación); falta el indicador «¿esta org debe seguir recibiendo?» | no hay tablero que agregue las evaluaciones por organización | reporte/indicador sobre `EvaluacionProceso` agrupado por org | promedio por org, mínimo de muestra |

## 5. Permanencia escolar

| Capacidad | Estado | Evidencia | Brecha | Solución | Pruebas |
|---|---|---|---|---|---|
| Motor, reglas, proveedores, casos, intervenciones, indicadores, notificaciones | **A** | 8 fases documentadas y probadas | — | conservar | — |
| 5.1 Salud operativa (scheduler, última corrida, fuentes) | **B** | `scheduler:estado` reporta permanencia; el tablero dice cuándo corrió; distingue «sin datos»/«motor parado» | falta una PANTALLA única de puesta en marcha que junte: config pendiente, reglas activas, estado de fuentes, última corrida ok/fallida | vista `/permanencia/salud` que agrega lo que ya calculan los servicios (sin lógica nueva de dominio) | refleja motor parado vs sin reglas vs sin datos |
| 5.1 Simular reglas antes de activar | **✅ HECHO** (era E) | `App\Services\Permanencia\SimuladorDeReglas` + `veredictoDe` extraído del motor; botón «Simular sin encender» en el editor de reglas; flash `simulacion` | — | implementado: previsualiza a cuántos marcaría un umbral candidato (dispara / no_dispara / sin_datos + muestra), sin escribir nada, acotado por campus y sin nombres en categorías sensibles | `prueba-simulador-reglas.php` (22 verif, 4 mutaciones) |
| 5.2 Casos: responsable, plazo, escalamiento, cierre, reapertura | **A** | fase 5: `AbridorDeCaso`, SLA, `TransicionDeCaso`, reapertura crea caso nuevo | — | conservar | — |
| 5.3 Calidad: dedup, explicación, versión congelada, calibración | **A** | dedup por columna generada, evidencia explicada, `regla_version_id` congelado, tablero de calibración/efectividad | — | conservar | — |
| 5.4 Privacidad y navegación efectiva (sin pantalla que da 403/404) | **A** | interruptor docente responde **404** apagado; categorías sensibles fuera de la consulta; desgloses < mínimo suprimidos | — | conservar | — |
| 5.4 Eventos para push móvil | **E** | avisos de pantalla existen; no hay evento reutilizable para push | falta contrato de evento push | preparar evento (no infra paralela); se apoya en la futura API móvil | — |

## 6. Finanzas

| Capacidad | Estado | Evidencia | Brecha | Solución | Pruebas |
|---|---|---|---|---|---|
| Cartera, cargos, pagos, becas, descuentos, convenios, caja, bancos, facturación, presupuesto, egresos | **A** | módulo 7 completo + rebanadas 3.x; concurrencia blindada el 2026-09-04 | — | conservar | — |
| 6.1 Cartera muestra egresados/bajas con adeudo | **A** | `FinanzasController::index` parte de `MatriculaOferta::query()` con `leftJoinSub` de saldos; NO filtra por situación activa | — | conservar; verificar en pruebas que no se cae ninguno | egresado con adeudo aparece |
| 6.1 Etiquetas de estado de pago inequívocas | **A** | `PildoraEstado` + estados `pendiente/parcial/pagado/reembolsado/fallido` derivados | — | conservar | — |
| 6.1 Tarjetas móviles de cartera | **E** | la cartera es tabla; falta la vista tarjeta para móvil | — | se resuelve con la API móvil (fuera de esta tarea) | — |
| 6.2 Integración contable (export trazable) | **E** | nada (`contabilidad`, `poliza`, `asiento` no existen) | no hay exportación al sistema contable | contrato de exportación trazable (qué se exportó, id, resultado), sin reconstruir contabilidad | reintento no duplica asientos |
| 6.3 Compras / cuentas por pagar | **E** | nada (proveedores, requisiciones, OC, recepción, CxP) | no existe el ciclo de compra | módulo OPCIONAL desactivable: proveedores→requisición→OC→recepción→obligación→pago, ligado a presupuesto/centro de costo/egreso; sin duplicar el gasto entre factura/obligación/pago | factura+obligación+pago no triplican el egreso |
| 6.3 CFDI recibido: no «válido» por cargar XML | **E** | la facturación es de EMISIÓN; no hay recepción de CFDI de proveedor | — | va con 6.3; mostrar estado real de validación, no «válido» por existir | — |
| 6.4 Integridad, idempotencia, concurrencia | **A** | rebanada de concurrencia (bloqueo pesimista en pagos/facturas/horas), CHECK de titular dual, único de generación | — | conservar | — |

## 7. Familias (padres / tutores)

| Capacidad | Estado | Evidencia | Brecha | Solución | Pruebas |
|---|---|---|---|---|---|
| 7.1 Vínculo: parentesco, responsable de pago, contacto de emergencia | **A** | `parentescos` (catálogo), `TutorAlumno.es_responsable_pago`, `.es_contacto_emergencia` | — | conservar | — |
| 7.1 Permiso separado académico/financiero | **A** | `puede_ver_academico`/`puede_ver_finanzas` gatean de verdad en `PadreController`, `TutorController`, `VeLaCarteraDelAlumno` | — | conservar | — |
| 7.1 Autorización «para recoger», custodia, vigencia/revocación del vínculo | **B** | el vínculo se da de baja lógica; no hay bandera de «autorizado a recoger» ni vigencia por permiso ni restricción de custodia documentada | falta el permiso de recogida y la vigencia/revocación granular | agregar banderas + vigencia al vínculo; se apoya en 7.2 | permiso caduca; custodia restringe |
| 7.2 Salida segura (recoger alumno) | **E** | nada | no existe registro de entrega, personas autorizadas, QR/PIN validado por servidor | subsistema: autorizados, ventana de vigencia, registro de entrega con evidencia, QR/PIN **validado en servidor** y de un solo uso, aviso a responsables | QR reusado se rechaza; captura no basta |
| 7.3 Consentimientos (texto, versión, estados, historial) | **A** | `autorizaciones_de_familiares`, `AutorizacionController`; `concedida` en NULL ≠ negado; una fila por vínculo | — | conservar | — |
| 7.4 Salud y emergencias | **E** | sólo la bandera `es_contacto_emergencia`; no hay alergias, medicamentos, ficha médica, incidentes | no existe la ficha de salud | ficha con permiso restringido y auditoría de acceso; **sin diagnósticos automáticos** | acceso a ficha queda auditado |
| 7.5 Citas familia–docente | **E** | nada | no hay agenda de citas | subsistema: disponibilidad del docente, solicitud, confirmación, prevención de traslapes | dos citas no se enciman |
| 7.5 Avisos por canal/preferencia, con acuse | **B** | avisos de pantalla existen (`Aviso`, `avisos_destinos`); no hay preferencia de canal ni acuse de lectura por canal externo | falta preferencia y acuse externo | se apoya en el motor de avisos existente; canal externo necesita proveedor | dedup con varios roles |

---

## Lo que NO se construye sin una decisión (E-decisión)

- **LTI, xAPI/LRS, plagio y proctoring**: envían datos de alumnos a terceros o
  exigen proveedor de pago. El pedido prohíbe activarlos sin decisión
  institucional. Se dejan documentados, no construidos.
- **SCORM real**: no necesita decisión, pero sí estudiar el estándar primario
  antes de escribir una línea; es un subsistema grande por sí solo.

## Flujos DECISIÓN-FREE, completos y de alto valor (candidatos a construir)

Ordenados por relación valor/aislamiento, todos reutilizan mecanismos que ya
existen y ninguno necesita servicio de pago:

1. **Supervisor externo de servicio social (4.1)** — cierra un flujo que hoy no
   se puede operar: el supervisor no valida nada. Reusa expediente, permisos,
   auditoría y el patrón de invitación por token.
2. **Salida segura / recoger alumno (7.2)** — subsistema autocontenido, reusa
   QR/PIN + auditoría, alto valor en básica/media.
3. **Prerrequisitos entre actividades del LMS (3.4)** — pedagógico, reusa
   actividades/entregas, con prevención de ciclos.
4. **Simulador de reglas de permanencia (5.1)** — calibrar sin generar señales;
   reusa el motor.
5. **Compras / cuentas por pagar (6.3)** — el más grande; módulo opcional.

## Regla que se respeta en todos

Servidor valida permiso y regla (ocultar botón no autoriza); aislamiento por
institución/campus/rol/vínculo; configurable por nivel y modalidad; módulo
opcional apagado no interrumpe a los demás; sin duplicar tablas/permisos/estados;
jobs idempotentes con contexto de tenant; nada de datos reales en pruebas.
