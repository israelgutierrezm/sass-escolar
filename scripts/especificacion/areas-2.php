<?php

/** Contenido de la especificación — áreas 4 a 6. */

return [

    // ─────────────────────────────────────────────────────────────────────
    [
        'clave' => 'escolar',
        'titulo' => 'Control escolar y asistencia',
        'resumen' => 'La operación del día: abrir el ciclo, armar grupos, inscribir, pasar lista, capturar calificaciones y cerrar actas.',

        'no_tecnico' => [
            [
                'subtitulo' => 'El ciclo y los grupos',
                'texto' => "Un ciclo escolar aplica a uno o varios campus —al menos a uno— y su clave es única en toda la escuela. Dentro del ciclo se arman los grupos, y dentro de cada grupo se abren las materias que se van a impartir, con su docente y su horario.\n"
                    .'Las materias se pueden abrir en lote por periodo, que es como se trabaja de verdad: al empezar el semestre se abre todo el bloque de una vez y después se ajusta.',
            ],
            [
                'subtitulo' => 'Inscribir con validación',
                'texto' => "Al inscribir a alguien en una materia el sistema revisa varias cosas y explica cuál falla: la seriación del plan, el cupo del grupo, el choque de horario, cuántas veces puede recursar, cuántas materias puede llevar en el ciclo y si tiene un adeudo que bloquea.\n"
                    .'Cada uno de esos límites lo pone la escuela, y además decide si sólo ADVIERTE o si BLOQUEA. No es la misma decisión en todas las instituciones.',
            ],
            [
                'subtitulo' => 'Capturar y cerrar',
                'texto' => "El docente captura por componentes —lo que el esquema de esa materia declare— y la calificación final se calcula sola. Una celda vacía no vale cero: significa que el docente todavía no llegó ahí, y mientras haya celdas vacías el acta no se puede cerrar.\n"
                    ."Cerrar el acta es el acto que convierte números en historia escolar: se emite el folio, se asientan los renglones del historial y ya no se edita. Si hay que corregir un número se emite un ACTA DE CORRECCIÓN, que da de baja los renglones de la original y asienta los nuevos. Las dos actas se conservan, y la original avisa de que ya no tiene efecto.\n"
                    .'La escuela puede definir ventanas de captura por parcial, con excepciones auditadas. Sin ventanas configuradas, el ciclo captura libre: configurar una es lo que empieza a bloquear.',
            ],
            [
                'subtitulo' => 'Asistencia',
                'texto' => "El docente pasa lista desde su materia. La rejilla está recortada al mes, así que muestra dos cifras: las faltas del mes y las del curso — lo que decide el derecho a examen es el acumulado, y antes había que sumarlo mes por mes de memoria.\n"
                    .'Aparte existe el reloj checador, con sus dispositivos y sus registros de entrada y salida. Es el insumo de la nómina por horas.',
            ],
            [
                'subtitulo' => 'Historial académico',
                'texto' => "El historial se agrupa por periodo del PLAN y no por ciclo escolar, porque el plan es el mapa del que se avanza: por ciclo, una materia recursada aparece lejos de sus compañeras de semestre y se pierde la forma del avance.\n"
                    ."Para los totales cuenta el MEJOR intento por materia, no todos. Y el alumno lo consulta él mismo, con la misma cuenta que usa la ventanilla, para que no haya dos promedios distintos según por dónde se pregunte.\n"
                    .'El historial imprimible tiene su propio diseñador: la escuela decide qué lleva el encabezado, qué columnas trae la tabla y en qué orden, cómo se agrupan las materias, el resumen, la leyenda, la firma y el sello.',
            ],
            [
                'subtitulo' => 'Tutorías',
                'texto' => 'El tutor educativo acompaña a un grupo de alumnos: registra sus sesiones y consulta cómo van. Su alcance lo da el vínculo de tutoría, no el permiso, y los accesos a las bitácoras quedan registrados y se purgan solos con el tiempo.',
            ],
        ],

        'operacion' => [
            [
                'flujo' => 'Abrir un ciclo y dejarlo listo para inscribir',
                'quien' => 'Control escolar, con «abrir-grupos»',
                'pasos' => [
                    'Control escolar → Ciclos: alta del ciclo, con sus fechas y al menos un campus.',
                    'Control escolar → Grupos: crear los grupos del ciclo, con su programa académico, plan, campus, turno y cupo.',
                    'Dentro del grupo, abrir las materias del periodo en lote y asignar docente a cada una.',
                    'Opcional: Horarios, para acomodar los bloques y detectar choques.',
                    'Opcional: en el ciclo, definir las ventanas de captura por parcial.',
                ],
            ],
            [
                'flujo' => 'Inscribir a un grupo completo',
                'quien' => 'Con «inscribir-alumnos»',
                'pasos' => [
                    'Control escolar → Inscripción masiva.',
                    'Elegir el grupo y las materias.',
                    'Marcar a los alumnos. El sistema valida cada uno y explica quién no pasa y por qué.',
                    'Lo que sólo advierte se puede continuar; lo que bloquea, no.',
                ],
            ],
            [
                'flujo' => 'Capturar calificaciones y cerrar el acta',
                'quien' => 'El docente de la materia, con «capturar-calificaciones»',
                'pasos' => [
                    'Captura → elegir la materia. Sólo aparecen las propias.',
                    'Capturar por componente. El promedio se calcula en vivo y una celda vacía se ve como pendiente.',
                    'Cuando no falte ninguna, firmar el acta. Se emite el folio y se asienta el historial.',
                    'A partir de ahí la materia queda cerrada. Para corregir, emitir un acta de corrección desde la misma pantalla.',
                    'El acta se puede imprimir; una abierta no, porque su folio todavía no existe.',
                ],
            ],
            [
                'flujo' => 'Pasar lista',
                'quien' => 'El docente, con «pasar-lista»',
                'pasos' => [
                    'Mis materias → la materia → Pasar lista. También se llega directo desde la tarjeta de la materia.',
                    'Marcar la asistencia del día. Se distingue teoría de práctica cuando la materia lo lleva.',
                    'La rejilla muestra las faltas del mes y las acumuladas del curso.',
                ],
            ],
            [
                'flujo' => 'Emitir el historial académico de un alumno',
                'quien' => 'Control escolar, con «ver-historial-academico»',
                'pasos' => [
                    'Alumnos → la ficha del alumno → historial.',
                    'Elegir la matrícula si estudia más de un programa.',
                    'Imprimir. Sale en PDF con el diseño configurado en Control escolar → Configuración → Historial académico.',
                    'El alumno puede descargarlo él mismo si la escuela lo permite; esa copia lleva marca de agua por omisión.',
                ],
            ],
        ],

        'tecnico' => [
            [
                'subtitulo' => 'La cadena de la inscripción',
                'texto' => "`ciclos` → `grupos` → `asignatura_grupo` (la materia abierta) → `inscripcion`. El docente se ata en `docente_asignatura_grupo`, y de ahí sale su alcance: no del permiso.\n"
                    .'`ValidadorInscripcion` devuelve `impedimentos()` y `advertencias()` por separado, porque cada límite configurable trae su acción —advertir o bloquear—.',
            ],
            [
                'subtitulo' => 'Captura y acta',
                'texto' => "`calificaciones_componente` guarda una fila por alumno y componente, con NULL donde el docente no llegó. «Capturada» se define UNA vez, en `CalificacionComponente::capturadas()`: contar FILAS haría que abrir la pantalla una vez congelara la materia, porque guardar escribe fila por alumno.\n"
                    ."`AsentadorActa` cierra: emite folio con `GeneradorFolioActa`, escribe `historial` y bloquea la re-emisión. Una materia se asienta una sola vez; un segundo cierre ordinario duplicaría al alumno en su historial. El acta de corrección apunta a la original con `acta_origen_id` y da de baja lógica sus renglones.\n"
                    .'Por eso la impresión del acta usa `withTrashed()`: sin eso, una original corregida se imprime con folio, firma y CERO alumnos.',
            ],
            [
                'subtitulo' => 'El historial, en un solo sitio',
                'texto' => "`HistorialDelAlumno` concentra tres decisiones de dominio: qué renglones cuentan para los totales (el mejor intento por materia), qué es «en curso» y cómo se promedia con la precisión del plan. Lo usan la ventanilla y el portal del alumno.\n"
                    ."Hay UN promedio oficial y es el de la MATRÍCULA. Llegó a haber tres implementaciones dando tres números distintos para el mismo alumno.\n"
                    .'Trampa medida: cargar `oferta.plan:id,nombre` deja `total_creditos` en NULL y el promedio se redondea con la regla por omisión. No falla ni avisa: sólo dice otro número.',
            ],
            [
                'subtitulo' => 'El historial impreso',
                'texto' => "Se genera en PDF con mpdf (`App\\Historial\\HistorialPdf` sobre `App\\Documentos\\DocumentoPdf`). No es un editor de cajas como la credencial y no por atajo: un historial CRECE, así que no hay coordenada que valga para la fila doscientos. Lo que varía mucho son las columnas.\n"
                    .'Los firmantes son varios (`firmantes_historial`): un historial se firma por control escolar y por la dirección.',
            ],
            [
                'subtitulo' => 'Asistencia',
                'texto' => '`asistencia_clase` para el pase de lista; `checadas` para el reloj —NO se llama `marcas_reloj`, que es como la nombra la spec original—. `dispositivos_checador` y su catálogo de tipos completan el módulo.',
            ],
        ],

        'tablas' => [
            ['nombre' => 'ciclos / ciclo_campus', 'para_que' => 'El periodo escolar y a qué planteles aplica. Al menos uno.'],
            ['nombre' => 'grupos', 'para_que' => 'El grupo: programa académico, plan, campus, turno y cupo.'],
            ['nombre' => 'asignatura_grupo', 'para_que' => 'Una materia abierta en un grupo. Es la unidad sobre la que todo lo demás se apoya.'],
            ['nombre' => 'docente_asignatura_grupo', 'para_que' => 'Qué docente imparte qué materia. De aquí sale su alcance.'],
            ['nombre' => 'inscripcion', 'para_que' => 'Una matrícula cursando una materia abierta. Singular.'],
            ['nombre' => 'esquema_evaluacion', 'para_que' => 'De qué se compone la calificación de esa materia (ver Estructura académica).'],
            ['nombre' => 'calificaciones_componente', 'para_que' => 'Lo capturado. NULL significa «sin capturar», no cero.'],
            ['nombre' => 'actas / contadores_acta', 'para_que' => 'El cierre de una materia y su folio. Un acta de corrección apunta a la original.'],
            ['nombre' => 'historial', 'para_que' => 'Lo asentado: la historia escolar del alumno, renglón por renglón.'],
            ['nombre' => 'ventanas_captura / excepciones_captura', 'para_que' => 'Cuándo se puede capturar, y los permisos extraordinarios con su auditoría.'],
            ['nombre' => 'horarios_asignatura_grupo / reglas_horario', 'para_que' => 'Los bloques de cada materia y las reglas para generarlos.'],
            ['nombre' => 'tutorias / sesiones_tutoria', 'para_que' => 'El acompañamiento y lo que se conversó.'],
            ['nombre' => 'asistencia_clase', 'para_que' => 'El pase de lista.'],
            ['nombre' => 'checadas / dispositivos_checador', 'para_que' => 'El reloj checador. Insumo de la nómina por horas.'],
            ['nombre' => 'disenos_historial / firmantes_historial', 'para_que' => 'Cómo se imprime el historial y quién lo firma.'],
        ],

        'pantallas' => [
            ['ruta' => '/escolar/ciclos', 'que_hace' => 'Ciclos y sus ventanas de captura.', 'permiso' => 'ver-grupos'],
            ['ruta' => '/escolar/grupos', 'que_hace' => 'Grupos, apertura de materias y asignación de docentes.', 'permiso' => 'ver-grupos'],
            ['ruta' => '/escolar/inscripciones/masiva', 'que_hace' => 'Inscribir por grupo, con validación por alumno.', 'permiso' => 'ver-grupos + inscribir-alumnos'],
            ['ruta' => '/escolar/alumnos', 'que_hace' => 'Padrón, expediente, historial y edición.', 'permiso' => 'ver-grupos + ver-alumnos'],
            ['ruta' => '/escolar/docentes', 'que_hace' => 'Catálogo de docentes y revisión de su expediente.', 'permiso' => 'ver-grupos + ver-docentes'],
            ['ruta' => '/escolar/horarios', 'que_hace' => 'Acomodo de bloques y detección de choques.', 'permiso' => 'ver-grupos + editar-horarios'],
            ['ruta' => '/escolar/reglas-horario', 'que_hace' => 'Reglas para la generación automática.', 'permiso' => 'ver-grupos + generar-horarios'],
            ['ruta' => '/escolar/tutorias', 'que_hace' => 'Asignación de tutorías y sus bitácoras.', 'permiso' => 'gestionar-tutorias'],
            ['ruta' => '/captura', 'que_hace' => 'Captura de calificaciones y cierre de actas. Fuera de /escolar porque la usan dos oficios.', 'permiso' => 'capturar-calificaciones'],
            ['ruta' => '/docencia', 'que_hace' => 'El portal del docente: sus materias, con lo que reclama trabajo arriba.', 'permiso' => 'ver-mis-materias'],
            ['ruta' => '/escolar/configuracion/historial', 'que_hace' => 'Diseñador del historial imprimible.', 'permiso' => 'ver-grupos + gestionar-historial'],
            ['ruta' => '/mi-historial', 'que_hace' => 'El historial del propio alumno.', 'permiso' => 'ver-historial-academico'],
        ],

        'reglas' => [
            ['regla' => 'NULL no es cero en la captura.', 'porque' => 'Un cero es una calificación; un NULL es que el docente no llegó ahí. Un componente sin capturar bloquea el cierre en vez de ponderarse como cero.'],
            ['regla' => 'La calificación asentada no se edita.', 'porque' => 'Un acta cerrada es historia escolar. Para cambiar un número se emite un acta de corrección; las dos se conservan.'],
            ['regla' => 'Una materia se asienta una sola vez.', 'porque' => 'Un segundo cierre ordinario duplicaría al alumno en su historial.'],
            ['regla' => 'Sin ventanas de captura configuradas, el ciclo captura libre.', 'porque' => 'Configurar una es lo que empieza a bloquear. `ciclos.captura_calif_hasta` es otra cosa: marca el acta como extemporánea, no bloquea.'],
            ['regla' => 'Un ciclo aplica a N campus y al menos a uno.', 'porque' => 'La clave del ciclo es única en toda la escuela; «sin campus» dejó de significar «global».'],
            ['regla' => 'El alcance del docente sale de la asignación, no del permiso.', 'porque' => 'El rol docente tiene «asentar-acta»; el permiso solo no lo separa de la materia de control escolar.'],
            ['regla' => 'La captura vive en /captura, fuera de /escolar.', 'porque' => 'La usan los dos oficios, y el docente no tiene «ver-grupos».'],
            ['regla' => 'La impresión del acta lee los renglones con `withTrashed()`.', 'porque' => 'Al corregir, los de la original se dan de baja lógica: sin eso, el acta se imprime con folio, firma y cero alumnos.'],
            ['regla' => 'Hay un solo promedio oficial: el de la matrícula.', 'porque' => 'Llegó a haber tres implementaciones dando tres números distintos para el mismo alumno, y ninguna fallaba.'],
            ['regla' => 'El historial se agrupa por periodo del plan, no por ciclo.', 'porque' => 'El plan es el mapa del que se avanza; por ciclo, una materia recursada queda lejos de sus compañeras de semestre.'],
            ['regla' => 'El reloj checador es `checadas`, no `marcas_reloj`.', 'porque' => 'Es como lo nombra la spec original, y el nombre real es otro. Se pregunta, no se adivina.'],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────
    [
        'clave' => 'finanzas',
        'titulo' => 'Finanzas: cobro, pagos y facturación',
        'resumen' => 'Qué debe cada alumno y por qué, cómo se le cobra, por dónde paga y qué comprobante fiscal se le emite.',

        'no_tecnico' => [
            [
                'subtitulo' => 'El plan de cobro y de dónde sale la deuda',
                'texto' => "La escuela arma planes de cobro: qué conceptos se cobran, cuándo vencen y de cuánto son. Un plan puede aplicarse a toda la escuela, a una programa_academico, a un plan de estudios o a una oferta concreta; cuando varios aplican, gana el más específico que esté vigente.\n"
                    .'Los cargos se emiten solos, de madrugada, recorriendo plan por plan. Es un comando aparte del que recalcula recargos, y corre antes: no se puede recargar por mora un cargo que todavía no existe, y esconder un cobro dentro de un comando llamado «evaluar» es como se llega a que nadie sepa de dónde salió un adeudo.',
            ],
            [
                'subtitulo' => 'Becas, descuentos y recargos',
                'texto' => "Las becas se otorgan por alumno y se aplican al generar el cargo. Los recargos por mora se calculan sobre el monto base, con los días de gracia que la escuela defina.\n"
                    .'Todo eso es configurable y se evalúa cada noche, así que la cartera del día siguiente ya refleja quién cayó en mora.',
            ],
            [
                'subtitulo' => 'Cómo paga la gente',
                'texto' => "Hay tres caminos. En ventanilla, alguien con permiso de caja registra el pago. En línea, el alumno paga con tarjeta por una de las cinco pasarelas conectadas. Y por transferencia: el alumno sube su comprobante y alguien de caja lo aprueba o lo rechaza.\n"
                    .'Un pago puede cubrir varios cargos. El estatus del adeudo se DERIVA de lo que se le aplicó, no se teclea; el del pago lo decide si esa forma de pago exige confirmación.',
            ],
            [
                'subtitulo' => 'Facturación',
                'texto' => "Se factura contra PAGOS cobrados, no contra deuda: se factura lo que entró. El IVA se desglosa por concepto y hacia atrás desde lo cobrado.\n"
                    ."Una escuela puede tener varias razones sociales —bachillerato con una, licenciatura con otra— y cada una guarda su propio certificado de sello digital y sus credenciales. Cuál se usa se decide por programa_academico, por nivel de estudios o global, y queda congelada en la factura junto con el receptor.\n"
                    .'Una factura timbrada no se edita: se emite la sustituta y luego se cancela la original con el motivo que el SAT pide para eso.',
            ],
            [
                'subtitulo' => 'Lo que ve el alumno y su familia',
                'texto' => "El alumno entra a su estado de cuenta y ve lo que debe, lo que pagó y a dónde puede pagar. Si estudia dos programas_academicos, ve cada una por separado.\n"
                    .'Ese acceso usa el mismo permiso que el personal de finanzas, pero acotado: sólo alcanza sus propias matrículas. Las pantallas administrativas —la cola de comprobantes, el catálogo de cuentas— exigen además el permiso del oficio que las opera.',
            ],
        ],

        'operacion' => [
            [
                'flujo' => 'Armar un plan de cobro para un ciclo',
                'quien' => 'Con «gestionar-planes-cobro»',
                'pasos' => [
                    'Finanzas → Planes de cobro → nuevo.',
                    'Elegir a qué aplica: global, programa académico, plan o una oferta concreta, y con qué vigencia.',
                    'Agregar los conceptos con su monto y su calendario: único, con parcialidades, semanal, quincenal o mensual.',
                    'Opcional: regla de recargo por concepto, con sus días de gracia.',
                    'Guardar. Los cargos se emitirán en la corrida de las 2:45.',
                ],
            ],
            [
                'flujo' => 'Registrar un pago en ventanilla',
                'quien' => 'Caja, con «registrar-pagos»',
                'pasos' => [
                    'Finanzas → Cartera → abrir la cuenta del alumno.',
                    'Elegir los cargos que cubre y capturar el pago con su método.',
                    'El sistema aplica el pago a los cargos y deriva el estatus de cada uno.',
                    'Si el método exige confirmación, el pago queda pendiente hasta confirmarlo.',
                ],
            ],
            [
                'flujo' => 'Revisar un comprobante de transferencia',
                'quien' => 'Caja, con «registrar-pagos»',
                'pasos' => [
                    'Finanzas → Comprobantes: la cola, con lo más viejo primero.',
                    'Abrir el comprobante y revisar el archivo, el monto y la referencia.',
                    'Aprobar —lo que genera el pago— o rechazar con motivo, que el alumno verá.',
                ],
            ],
            [
                'flujo' => 'Emitir y timbrar una factura',
                'quien' => 'Con «facturar»',
                'pasos' => [
                    'Finanzas → Facturas → emitir, eligiendo la matrícula.',
                    'Elegir los pagos ya cobrados que se van a facturar.',
                    'El sistema resuelve qué razón social corresponde y congela emisor y receptor en la factura.',
                    'El timbrado sale a la cola. Mientras tanto la factura se ve «en la cola, esperando al PAC».',
                    'Si el PAC rechaza, la factura queda en error con el código y el motivo, y se puede reintentar.',
                ],
            ],
        ],

        'tecnico' => [
            [
                'subtitulo' => 'El motor de cobro',
                'texto' => "`ResolutorPlanCobro` elige el plan (oferta → plan → programa_academico → global, el más específico vigente). `PeriodosCobro` calcula el calendario. `GeneradorAdeudos` emite, y es idempotente por índice ÚNICO y no sólo por un SELECT previo.\n"
                    ."El único es `adeudos_generacion_unica` sobre (matricula_oferta_id, concepto_plan_id, periodo_etiqueta), que es la terna por la que pregunta la generación.\n"
                    .'`generarParaTodas` recorre plan por plan —no alumno por alumno— en bloques de 200 con `chunkById`, y AÍSLA cada plan: un plan roto no puede dejar a la escuela entera sin emitir.',
            ],
            [
                'subtitulo' => 'Titular dual',
                'texto' => "`adeudos` y `pagos` tienen titular dual: `matricula_oferta_id` o `aspirante_id`, exactamente uno, con CHECK en MySQL. El aspirante paga antes de tener matrícula.\n"
                    .'`ReligadorFinanzas` los pasa a la matrícula nueva dentro de la transacción de la conversión.',
            ],
            [
                'subtitulo' => 'Pagos en línea',
                'texto' => "`App\\Services\\Pagos` con una interfaz `Pasarela` y cinco implementaciones: Stripe, Conekta, MercadoPago, OpenPay y PayPal, más `PasarelaFalsa`. `config/pagos.php` tiene un modo `fake` que recorre el flujo entero sin credenciales; el default es `real` a propósito, para que a un despliegue que olvide la variable le toque cobrar y no simular.\n"
                    .'El aviso de la pasarela sólo dice QUÉ preguntar: el sistema consulta el estado a la pasarela en vez de creerle al cuerpo del webhook.',
            ],
            [
                'subtitulo' => 'CFDI',
                'texto' => "`facturas` + `factura_conceptos`; `App\\Services\\Cfdi\\Pac` como interfaz, con `PacFalso` y `FacturapiPac`. El timbrado va en cola (`TimbrarFactura`), con reintento sólo de lo que tiene sentido reintentar: un rechazo del SAT no se reintenta porque la respuesta sería la misma.\n"
                    ."`failed()` saca la factura de «timbrando» y la deja en error con el motivo: sin eso se quedaría en ese estado para siempre.\n"
                    .'`emisores_fiscales` + `emisor_asignaciones` resuelven la razón social por programa académico → nivel → global.',
            ],
        ],

        'tablas' => [
            ['nombre' => 'conceptos_pago / situaciones_pago / metodos_pago', 'para_que' => 'Los catálogos del cobro. `situaciones_pago.bloquea` es lo que impide inscribir a un deudor.'],
            ['nombre' => 'planes_cobro / conceptos_plan', 'para_que' => 'Qué se cobra, a quién aplica y con qué calendario.'],
            ['nombre' => 'reglas_recargo / descuentos / becas / becas_alumno', 'para_que' => 'Lo que sube y lo que baja el monto.'],
            ['nombre' => 'adeudos', 'para_que' => 'La deuda emitida. Titular dual: matrícula o aspirante, exactamente uno.'],
            ['nombre' => 'pagos / pago_adeudo', 'para_que' => 'Lo cobrado y a qué cargos se aplicó. Un pago puede cubrir varios.'],
            ['nombre' => 'cuentas_bancarias / comprobantes_pago', 'para_que' => 'A dónde se transfiere y los comprobantes que suben los alumnos.'],
            ['nombre' => 'pasarelas_pago / intenciones_cobro', 'para_que' => 'Las pasarelas configuradas y cada intento de cobro en línea.'],
            ['nombre' => 'facturas / factura_conceptos', 'para_que' => 'El CFDI, con su emisor y receptor congelados.'],
            ['nombre' => 'emisores_fiscales / emisor_asignaciones', 'para_que' => 'Las razones sociales de la escuela y a qué aplica cada una.'],
            ['nombre' => 'datos_facturacion', 'para_que' => 'RFC, régimen y código postal de una persona. Los reusa la nómina.'],
            ['nombre' => 'servicios / solicitudes_servicio', 'para_que' => 'Trámites con costo que el alumno solicita.'],
            ['nombre' => 'bitacora_situacion_financiera', 'para_que' => 'Cómo fue cambiando la situación de cada cuenta.'],
        ],

        'pantallas' => [
            ['ruta' => '/finanzas', 'que_hace' => 'La cartera. El alumno entra aquí y ve sólo la suya.', 'permiso' => 'ver-adeudos'],
            ['ruta' => '/finanzas/planes', 'que_hace' => 'Planes de cobro y sus conceptos.', 'permiso' => 'ver-adeudos + gestionar-planes-cobro'],
            ['ruta' => '/finanzas/becas', 'que_hace' => 'Catálogo de becas y otorgamiento por alumno.', 'permiso' => 'ver-adeudos + gestionar-planes-cobro'],
            ['ruta' => '/finanzas/comprobantes', 'que_hace' => 'Cola de comprobantes de transferencia por revisar.', 'permiso' => 'ver-adeudos + registrar-pagos'],
            ['ruta' => '/finanzas/cuentas-bancarias', 'que_hace' => 'A dónde se puede transferir.', 'permiso' => 'ver-adeudos + ver-cuentas-bancarias'],
            ['ruta' => '/finanzas/facturas', 'que_hace' => 'Emisión, timbrado, refacturación y cancelación.', 'permiso' => 'ver-adeudos + facturar'],
            ['ruta' => '/finanzas/emisores', 'que_hace' => 'Razones sociales, con su sello digital y sus credenciales.', 'permiso' => 'ver-adeudos + gestionar-emisores'],
            ['ruta' => '/plataforma/configuraciones/pasarelas', 'que_hace' => 'Credenciales de las pasarelas de pago.', 'permiso' => 'configurar-facturacion'],
        ],

        'reglas' => [
            ['regla' => 'Se factura contra PAGOS cobrados, no contra deuda.', 'porque' => 'Se factura lo que entró. El IVA se desglosa hacia atrás desde lo cobrado.'],
            ['regla' => 'Generar cargos y evaluar la cartera son comandos distintos, y en ese orden.', 'porque' => 'No se puede recargar por mora un cargo que no existe; y esconder un cobro dentro de «evaluar» hace que nadie sepa de dónde salió un adeudo.'],
            ['regla' => 'Un plan roto no cancela a los demás.', 'porque' => 'Sin aislar cada plan, una sola fila mala dejaba a la escuela entera sin emitir y el reporte decía «ok».'],
            ['regla' => 'La idempotencia se apoya en un índice único, no en un SELECT previo.', 'porque' => 'Dos corridas simultáneas pasan el SELECT las dos. El único es sobre la terna por la que pregunta la generación.'],
            ['regla' => 'El estatus del adeudo se deriva de lo aplicado.', 'porque' => 'Tecleado, se desincroniza del importe cubierto. El del pago sí lo dicta si el método exige confirmación.'],
            ['regla' => 'Una factura timbrada no se edita.', 'porque' => 'Corregirla son dos pasos y en ese orden: primero nace la sustituta, y sólo entonces se cancela la original con motivo 01, porque el SAT pide el folio de la sustituta al cancelar.'],
            ['regla' => 'El emisor se congela en la factura, igual que el receptor.', 'porque' => 'Una escuela puede tener varias razones sociales, y la que aplicaba el día de la emisión no tiene por qué ser la de hoy.'],
            ['regla' => 'El modo por omisión de las pasarelas es `real`.', 'porque' => 'A un despliegue que olvide la variable le toca cobrar, no simular.'],
            ['regla' => '`ver-adeudos` no basta para las pantallas administrativas.', 'porque' => 'Es un permiso de tres facetas —también del alumno y del padre—, así que no distingue de quién es lo que se está mirando.'],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────
    [
        'clave' => 'lms',
        'titulo' => 'Aula virtual, exámenes y clases en línea',
        'resumen' => 'El curso de cada materia: material, actividades, entregas, exámenes, rúbricas, foros, videoconferencias y sus grabaciones.',

        'no_tecnico' => [
            [
                'subtitulo' => 'El curso se arma una vez y se copia a cada grupo',
                'texto' => "En el plan de estudios se prepara el curso de una materia: sus lecciones, sus actividades, sus exámenes. Cuando esa materia se abre en un grupo, el curso se COPIA a ese grupo.\n"
                    .'Se copia y no se apunta: corregir una falta de ortografía en el plan no debe cambiar el examen que un grupo está contestando en ese momento.',
            ],
            [
                'subtitulo' => 'El aula del alumno',
                'texto' => "El alumno recorre su materia como un libro: índice a la izquierda agrupado por parcial, la lección al centro, su avance arriba, y Anterior/Siguiente al pie.\n"
                    ."Eso separa dos preguntas que antes se estorbaban en la misma pantalla: «¿cómo voy?» —calificaciones, asistencia, docentes— se queda en la portada de la materia, y «¿qué sigue?» vive en el aula.\n"
                    .'Una lección se marca completada de una sola forma según lo que sea: lo que se entrega lo declara la entrega, y una lectura la declara el alumno con un botón. Marcarla por sólo abrirla habría llenado la barra de avance de mentiras.',
            ],
            [
                'subtitulo' => 'Entregas, portafolio y exámenes',
                'texto' => "Una actividad puede pedir un archivo, un texto, o un PORTAFOLIO: una colección que el alumno va acumulando a lo largo del curso, pieza por pieza y cada una con su título, su descripción y su fecha. Esa descripción por pieza es lo que hace que sea un portafolio y no una carpeta de archivos.\n"
                    .'Los exámenes se arman de un banco de reactivos, se pueden barajar y se califican solos. Barajar y elegir cuántos presentar son dos decisiones distintas: se puede tomar 20 de 50 sin desordenar las que salieron.',
            ],
            [
                'subtitulo' => 'Rúbricas',
                'texto' => "Calificar un trabajo eligiendo un nivel por criterio, en vez de escribir un número. Las hay de la escuela y las hay de cada docente, y la de otro docente no se ve ni con permiso de gestionar.\n"
                    ."Una rúbrica es una ESCALA, no la nota: sus puntos se llevan a los de la actividad, así que una rúbrica de 20 sobre una actividad de 10 da 8.5 y no 17. Y se congela al primer uso: para cambiarla se duplica.\n"
                    .'El alumno la ve ANTES de entregar; calificada, se le marca el nivel que obtuvo y los demás se atenúan, porque ver dónde quedó respecto de lo de arriba es la mitad de la información.',
            ],
            [
                'subtitulo' => 'Clases en línea',
                'texto' => "El docente programa la sesión desde su materia y al alumno le aparece el botón para entrar. Funciona con Zoom y con Google Meet.\n"
                    ."Los dos no son iguales, y eso gobierna el diseño: una licencia de Zoom sostiene UNA reunión a la vez, así que dos clases a las 9:00 exigen dos licencias; una cuenta de Meet no tiene ese límite. El sistema aparta la licencia antes de llamar al proveedor, para que dos docentes programando a la vez no se lleven la misma.\n"
                    .'Quién entró queda anotado, con sus reconexiones. Se dice «se conectaron» y no «asistieron», porque lo que hay es el clic con la clase abierta: el docente lo ve mientras pasa lista y decide.',
            ],
            [
                'subtitulo' => 'Grabaciones',
                'texto' => "Lo que Zoom o Meet grabó se archiva donde la escuela diga: su propio disco, Drive o Dropbox. Un destino a la vez, porque con dos habría que decidir cuál enlace ve el alumno y se pagaría dos veces el mismo archivo.\n"
                    .'Si se publican solas lo decide la escuela, y por omisión NO: traen caras y voces de menores. El ajuste se lee al anotar la grabación y se copia a la fila, así que cambiar la regla no publica —ni esconde— de golpe lo que ya existía.',
            ],
        ],

        'operacion' => [
            [
                'flujo' => 'Preparar el curso de una materia',
                'quien' => 'Con «editar-catalogo-academico», en el plan; o el docente en su grupo',
                'pasos' => [
                    'Académico → Planes → la materia → Curso.',
                    'Agregar lecciones con su material: texto, video incrustado, imágenes subidas al propio sistema.',
                    'Agregar actividades: entrega, examen, portafolio o lectura, cada una con su ponderación a un componente del esquema.',
                    'Dejarlas ocultas mientras se arman; se publican una por una con el interruptor del ojo.',
                    'Al abrir la materia en un grupo, el curso se copia ahí.',
                ],
            ],
            [
                'flujo' => 'Armar y aplicar un examen',
                'quien' => 'El docente, con «capturar-calificaciones»',
                'pasos' => [
                    'En la materia → Exámenes → nuevo.',
                    'Cargar reactivos al banco, con su tipo y sus opciones.',
                    'Definir cuántos se presentan y si se barajan. Son dos decisiones separadas.',
                    'Publicar el examen con su ventana de aplicación.',
                    'Se califica solo. En el panel de calificación aparece marcado como automático y no se le escribe un número encima.',
                ],
            ],
            [
                'flujo' => 'Calificar entregas',
                'quien' => 'El docente, con «capturar-calificaciones»',
                'pasos' => [
                    'Mis materias → la materia → la actividad.',
                    'El panel se abre al costado con el trabajo y sus archivos a la vista.',
                    'Poner la calificación —o «Máximo»— y la retroalimentación.',
                    '«Guardar y seguir» salta a la siguiente sin calificar: calificar es trabajo en serie.',
                ],
            ],
            [
                'flujo' => 'Programar una clase en línea',
                'quien' => 'El docente; la escuela configura las cuentas con «gestionar-clases-en-linea»',
                'pasos' => [
                    'Plataforma → Clases en línea: registrar las cuentas de Zoom o Meet y sus licencias.',
                    'El docente, desde su materia, programa la sesión con su hora de inicio y fin.',
                    'El sistema aparta la licencia, crea la reunión y guarda los enlaces.',
                    'Al alumno le aparece el botón cuando falta poco para empezar, no antes.',
                    'Todos entran por una puerta propia del sistema, que anota quién entró y luego redirige.',
                ],
            ],
        ],

        'tecnico' => [
            [
                'subtitulo' => 'Copia, no referencia',
                'texto' => '`CopiadorDeCurso` lleva el curso del plan al `asignatura_grupo`. Lo que NO se copia es la rúbrica: la actividad la APUNTA, porque copiarla por grupo y ciclo partiría el catálogo en cientos de duplicados, y lo que obliga a copiar el examen —que editar la plantilla cambiaría lo que un grupo contesta— aquí no aplica.',
            ],
            [
                'subtitulo' => 'HTML de editor',
                'texto' => "`App\\Support\\HtmlSeguro` es lista blanca de etiquetas y atributos para todo HTML que se pinte con `v-html`. El material lo escribe un docente y lo lee cada alumno del grupo: sin sanear, un `<img onerror>` se ejecuta en la sesión de todos.\n"
                    ."El `iframe` sobrevive —es lo que permite incrustar un video o un SCORM— pero con `sandbox` y `referrerpolicy` impuestos por el servidor y sólo sobre `https://`.\n"
                    .'Las imágenes se SUBEN al propio sistema (`imagenes_contenido`), no se enlazan de fuera: un enlace ajeno se cae a media asignatura y le anuncia a ese servidor dónde estudia cada alumno que abre la lección. Su URL pública lleva uuid y no id, porque un id se cuenta.',
            ],
            [
                'subtitulo' => 'Videoconferencias',
                'texto' => "`ProveedoresVideoCatalogo::unaReunionPorCuenta` declara la asimetría entre Zoom y Meet, y ante un proveedor desconocido responde `true`, que es el lado seguro.\n"
                    ."La FILA es el apartado de la licencia: se inserta la clase sin enlaces dentro de una transacción que bloquea las cuentas, después se llama al proveedor —fuera del bloqueo— y al final se ponen los enlaces.\n"
                    .'`url_anfitrion` es una CREDENCIAL: el `start_url` de Zoom entra como dueño de la sala sin pedir contraseña. Ni ése ni el de invitado viajan al navegador; salen del controlador de la puerta, que reconoce el papel de quien pide.',
            ],
            [
                'subtitulo' => 'Grabaciones',
                'texto' => "Idempotente por (origen, id_externo): Zoom reenvía su aviso si no se le contesta rápido. El webhook comprueba FIRMA (HMAC, ventana de 5 minutos) y sin secreto configurado se rechaza — aquí el cuerpo trae la URL que se descarga, así que no vale la defensa de los pagos.\n"
                    ."Meet no tiene webhook: `clases:recoger-grabaciones` pregunta cada hora. Sólo se registra lo que está en `FILE_GENERATED`; antes de ese estado el archivo no existe.\n"
                    .'Con destino Drive no se copia nada: Google ya lo dejó en el Drive de quien organizó.',
            ],
        ],

        'tablas' => [
            ['nombre' => 'cursos / actividades', 'para_que' => 'El curso de una materia y sus lecciones y trabajos.'],
            ['nombre' => 'entregas / entrega_archivos', 'para_que' => 'Lo que entrega el alumno y sus adjuntos.'],
            ['nombre' => 'portafolio_evidencias / portafolio_archivos', 'para_que' => 'La colección que se acumula, con título y fecha por pieza.'],
            ['nombre' => 'examenes / reactivos / reactivo_opciones', 'para_que' => 'El examen y su banco de preguntas.'],
            ['nombre' => 'intentos / respuestas', 'para_que' => 'Cada presentación y lo que se contestó. La respuesta cuelga del intento.'],
            ['nombre' => 'rubricas / rubrica_criterios / rubrica_niveles', 'para_que' => 'La escala de calificación por criterio.'],
            ['nombre' => 'entrega_rubrica', 'para_que' => 'Qué nivel se le dio a cada criterio de una entrega, con sus puntos.'],
            ['nombre' => 'foro_temas / foro_respuestas', 'para_que' => 'Los foros de la materia.'],
            ['nombre' => 'conversaciones / mensajes', 'para_que' => 'Mensajería uno a uno y canal del grupo.'],
            ['nombre' => 'actividad_vistas', 'para_que' => 'Qué abrió el alumno y qué declaró completado.'],
            ['nombre' => 'imagenes_contenido', 'para_que' => 'Las imágenes del material, servidas por el propio sistema con uuid.'],
            ['nombre' => 'videoconferencias / cuentas_videoconferencia', 'para_que' => 'Las clases en línea y las licencias que las sostienen.'],
            ['nombre' => 'accesos_videoconferencia', 'para_que' => 'Quién entró a cada clase, con sus reconexiones.'],
            ['nombre' => 'grabaciones / destinos_grabacion', 'para_que' => 'Lo grabado y a dónde se archivó.'],
        ],

        'pantallas' => [
            ['ruta' => '/mis-cursos', 'que_hace' => 'Las materias del alumno.', 'permiso' => 'ver-mis-cursos'],
            ['ruta' => '/mis-cursos/{materia}/aula', 'que_hace' => 'El aula: la materia recorrida como un libro.', 'permiso' => 'ver-mis-cursos'],
            ['ruta' => '/docencia', 'que_hace' => 'Las materias del docente, con lo que reclama trabajo arriba.', 'permiso' => 'ver-mis-materias'],
            ['ruta' => '/rubricas', 'que_hace' => 'Rúbricas de la escuela y propias. Cuelga de la raíz porque la usan dos oficios.', 'permiso' => 'usar-rubricas'],
            ['ruta' => '/plataforma/clases-en-linea', 'que_hace' => 'Cuentas de Zoom y Meet, licencias y destino de las grabaciones.', 'permiso' => 'gestionar-clases-en-linea'],
        ],

        'reglas' => [
            ['regla' => 'El curso del plan se copia al grupo, no se apunta.', 'porque' => 'Corregir el plan no debe cambiar el examen que un grupo está contestando.'],
            ['regla' => 'La rúbrica es la excepción: se apunta.', 'porque' => 'Copiarla por grupo y ciclo partiría el catálogo en cientos de duplicados, y aquí no aplica el motivo que obliga a copiar el examen.'],
            ['regla' => 'Una rúbrica es una escala, no la nota.', 'porque' => 'Sus puntos se llevan a los de la actividad; si no, no se podría reusar en trabajos de distinto peso.'],
            ['regla' => 'Un criterio sin evaluar no es un cero.', 'porque' => 'Lo evaluado se guarda y la entrega queda sin calificar. Misma regla que la captura de calificaciones.'],
            ['regla' => 'Barajar y elegir cuántos presentar son dos decisiones.', 'porque' => 'Fijar «presentar N» desordenaba el examen aunque el docente hubiera apagado el barajado, y un examen cuyas preguntas se apoyan unas en otras se desordenaba sin que nadie lo pidiera.'],
            ['regla' => 'Todo HTML de editor pasa por la lista blanca.', 'porque' => 'Lo escribe un docente y lo lee cada alumno del grupo: sin sanear, un `<img onerror>` se ejecuta en la sesión de todos.'],
            ['regla' => 'Las imágenes se suben, no se enlazan de fuera.', 'porque' => 'Un enlace ajeno se cae a media asignatura y le anuncia a ese servidor dónde estudia cada alumno que abre la lección.'],
            ['regla' => 'La fila de la clase es el apartado de la licencia.', 'porque' => 'Sin eso, dos docentes programando a la vez se llevan la misma licencia y la segunda clase echa a la primera.'],
            ['regla' => '`url_anfitrion` es una credencial y no viaja al navegador.', 'porque' => 'El `start_url` de Zoom entra como dueño de la sala sin pedir contraseña.'],
            ['regla' => 'Las grabaciones no se publican solas por omisión.', 'porque' => 'Traen caras y voces de menores. El ajuste se copia a la fila al anotarla, para que cambiar la regla no publique de golpe un semestre entero.'],
            ['regla' => 'Se dice «se conectaron», no «asistieron».', 'porque' => 'Lo que hay es el clic con la clase abierta, no permanencia. Ponerlo como asistencia haría que alguien firmara un acta con un dato que el sistema no tiene.'],
        ],
    ],
];
