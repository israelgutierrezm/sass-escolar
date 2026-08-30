<?php

/** Contenido de la especificación — áreas 7 a 9. */

return [

    // ─────────────────────────────────────────────────────────────────────
    [
        'clave' => 'certificacion',
        'titulo' => 'Certificación y titulación ante la SEP',
        'resumen' => 'Convertir el historial de un egresado en un documento oficial: armarlo, validarlo, sellarlo y mandarlo al web service de la SEP.',

        'no_tecnico' => [
            [
                'subtitulo' => 'Se trabaja por lotes',
                'texto' => "Los certificados y los títulos se emiten en lote: se eligen los egresados que van juntos, se valida a cada uno, se arma el documento electrónico, se sella con el certificado digital de la institución y se manda a la SEP.\n"
                    .'El lote es la unidad de trabajo, pero el REENVÍO es por documento y no por lote: el error casi siempre viene del otro lado —una caída de la SEP, una validación suya— y remandar el lote entero duplicaría los trámites que allá ya se aceptaron.',
            ],
            [
                'subtitulo' => 'La validación es lo que de verdad protege',
                'texto' => "Antes de mandar nada, el sistema revisa cada egresado y dice qué le falta, nombrando la materia concreta cuando el problema es de una materia. Descubrirlo por el rechazo de la SEP, sobre cuarenta expedientes, no le sirve a nadie.\n"
                    .'Lo más frecuente es que falte el identificador oficial de un campus, una programa_academico o una asignatura. Antes, si faltaba, el documento caía en silencio a otro número y el validador oficial lo aceptaba —esos campos admiten cualquier texto—: el documento pasaba llevando un número que la SEP nunca asignó. Ahora se detiene antes de firmar.',
            ],
            [
                'subtitulo' => 'Qué número manda cada catálogo',
                'texto' => "Ésta es la parte que más se presta a equivocarse. En unos catálogos el valor oficial es la CLAVE; en otros vive en el campo de identificador y la clave es otra cosa; y en programa_academico, asignatura, plan y campus son datos distintos que no se pueden intercambiar.\n"
                    .'Por eso la elección se hace catálogo por catálogo y está fijada por una prueba. Unificarlo todo «por clave» rompería el timbrado de todas las escuelas.',
            ],
            [
                'subtitulo' => 'Créditos de emisión',
                'texto' => 'Emitir consume créditos, con tres modalidades: prepago, postpago o incluido. Las regeneraciones no vuelven a cobrar, y un egresado repetido dentro del mismo lote no cuenta dos veces.',
            ],
            [
                'subtitulo' => 'Servicio social y modalidad de titulación',
                'texto' => 'El título arrastra información que el historial no tiene: la modalidad por la que se titula, el servicio social con su fundamento legal, y los antecedentes académicos. Todo eso se captura antes de armar el lote.',
            ],
        ],

        'operacion' => [
            [
                'flujo' => 'Emitir un lote de certificados',
                'quien' => 'Con «certificar-alumnos»',
                'pasos' => [
                    'Certificación → Lotes → candidatos: el sistema propone a quienes cumplen.',
                    'Elegir a los que van en el lote y crearlo.',
                    'Validar. Si algo falta, se corrige y se vuelve a validar; el mensaje nombra la materia o el catálogo concreto.',
                    'Firmar el lote con el certificado de sello digital de la institución.',
                    'Enviar al web service de la SEP.',
                    'Revisar el resultado documento por documento. Lo rechazado se reintenta individualmente.',
                ],
            ],
            [
                'flujo' => 'Preparar la titulación de un egresado',
                'quien' => 'Con «titular-alumnos»',
                'pasos' => [
                    'Titulación → Lotes → candidatos.',
                    'Capturar la modalidad de titulación, el servicio social con su fundamento legal y los antecedentes académicos.',
                    'A partir de ahí el recorrido es el mismo: armar el lote, validar, firmar, enviar y reintentar lo que falle.',
                ],
            ],
            [
                'flujo' => 'Configurar los responsables que firman',
                'quien' => 'Con «gestionar-certificacion» o «gestionar-titulacion»',
                'pasos' => [
                    'Certificación → Responsables (o Titulación → Responsables).',
                    'Capturar a cada responsable con su cargo del catálogo oficial.',
                    'Los movimientos quedan registrados: quién firmaba en qué periodo.',
                ],
            ],
        ],

        'tecnico' => [
            [
                'subtitulo' => 'La prueba es el XSD oficial',
                'texto' => 'Los XML validan contra los esquemas oficiales que viven en `resources/certificados/` y `resources/titulos/`. `ValidadorDec::validarLote()` y `ValidadorTitulo::validarMatricula()` los corren, y comprobado contra el demo los dos pasan.',
            ],
            [
                'subtitulo' => 'El sello',
                'texto' => 'SHA-256, con el certificado en DER base64 y no PEM. Las pruebas verifican contra lo que viaja en el XML y comprueban que una cadena alterada invalide la firma.',
            ],
            [
                'subtitulo' => 'La columna del catálogo se elige una por una',
                'texto' => "`ConstructorCertificadoXml::idCatalogo()` decide por catálogo, y `ClavesSepDelCertificadoTest` lo fija.\n"
                    ."Por clave: nivel de estudios, tipo de periodo, tipo de asignatura, tipo de certificación —en éste el id NO coincide con la clave: 1 y 2 contra 79 y 80, así que leer el id sería mandar un número que la SEP no reconoce—.\n"
                    ."Por identificador: `entidades_federativas` (clave = abreviatura RENAPO, identificador = «01»), `cargos`, `modalidades_titulacion` y `fundamentos_legales_servicio_social`, que ni siquiera tienen columna `clave`.\n"
                    .'En programa académico, asignatura, plan y campus, `identificador` y `clave` son datos DISTINTOS y no se pueden intercambiar.',
            ],
            [
                'subtitulo' => 'Lo que falta, y no es de código',
                'texto' => 'La e.firma de la escuela y el WSDL de producción de la SEP. El contrato del web service está cubierto por pruebas contra respuestas fingidas de la forma documentada.',
            ],
        ],

        'tablas' => [
            ['nombre' => 'lotes_certificacion / certificaciones', 'para_que' => 'El lote y cada certificado con su estado y su acuse.'],
            ['nombre' => 'lotes_titulacion / titulaciones', 'para_que' => 'Lo mismo para los títulos.'],
            ['nombre' => 'tipos_certificacion', 'para_que' => 'Catálogo oficial. Su clave es el valor que viaja, y no coincide con el id.'],
            ['nombre' => 'titulos_profesionales / modalidades_titulacion', 'para_que' => 'Catálogos oficiales del título.'],
            ['nombre' => 'titulo_servicio_social / fundamentos_legales_servicio_social', 'para_que' => 'El servicio social del egresado y su fundamento.'],
            ['nombre' => 'titulo_antecedente', 'para_que' => 'Los estudios previos que el título declara.'],
            ['nombre' => 'responsables / tipos_responsable / responsable_movimientos', 'para_que' => 'Quién firma, con qué cargo y desde cuándo.'],
            ['nombre' => 'cargos', 'para_que' => 'Catálogo OFICIAL de la SEP. No confundir con `puestos`, que es el organigrama de la escuela.'],
            ['nombre' => 'certificados_responsable', 'para_que' => 'Los certificados de sello digital con los que se firma.'],
            ['nombre' => 'titulacion_ws_config', 'para_que' => 'La configuración del web service de la SEP.'],
            ['nombre' => 'emision_saldos / emision_compras / emision_consumos', 'para_que' => 'Los créditos de emisión. Viven en la base central.'],
        ],

        'pantallas' => [
            ['ruta' => '/certificacion/lotes', 'que_hace' => 'Lotes de certificados: armar, validar, firmar y enviar.', 'permiso' => 'certificar-alumnos'],
            ['ruta' => '/certificacion/lotes/candidatos', 'que_hace' => 'Quiénes cumplen para certificarse.', 'permiso' => 'certificar-alumnos'],
            ['ruta' => '/certificacion/configuracion/responsables', 'que_hace' => 'Responsables que firman certificados.', 'permiso' => 'gestionar-certificacion'],
            ['ruta' => '/titulacion/lotes', 'que_hace' => 'Lotes de títulos.', 'permiso' => 'titular-alumnos'],
            ['ruta' => '/titulacion/configuracion/web-service', 'que_hace' => 'Configuración del WS de la SEP.', 'permiso' => 'gestionar-titulacion'],
            ['ruta' => '/plataforma/creditos', 'que_hace' => 'Saldo, compras y consumo de créditos de emisión.', 'permiso' => 'certificar-alumnos'],
        ],

        'reglas' => [
            ['regla' => 'La prueba de que el documento está bien es el XSD oficial.', 'porque' => 'Los esquemas viven en el repositorio y se corren antes de firmar; no se valida contra una idea de cómo debería ser.'],
            ['regla' => 'El identificador de campus, programa académico y asignatura es obligatorio para firmar.', 'porque' => 'Si faltaba, el documento caía en silencio a otro número y el XSD lo aceptaba —esos campos son texto libre—: pasaba llevando un número que la SEP nunca asignó.'],
            ['regla' => 'La columna que viaja se elige catálogo por catálogo.', 'porque' => 'En unos el valor oficial es la clave, en otros el identificador, y en programa académico o asignatura son datos distintos. Unificar rompería el timbrado de todas las escuelas.'],
            ['regla' => 'Reenviar es por documento, no por lote.', 'porque' => 'El error suele venir del otro lado, y remandar el lote duplicaría los trámites que allá ya se aceptaron.'],
            ['regla' => '`cargos` es el catálogo oficial de la SEP y `puestos` el organigrama de la escuela.', 'porque' => 'Fundirlos rompería el timbrado de todas las escuelas por ganar una tabla.'],
            ['regla' => 'Una regeneración no vuelve a cobrar crédito.', 'porque' => 'El crédito paga la emisión, no cada intento; y un repetido dentro del lote tampoco cuenta dos veces.'],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────
    [
        'clave' => 'rh',
        'titulo' => 'Nómina, bolsa de trabajo y movilidad',
        'resumen' => 'El vínculo laboral con quien trabaja en la escuela, la colocación de sus egresados y los intercambios académicos.',

        'no_tecnico' => [
            [
                'subtitulo' => 'El expediente laboral',
                'texto' => "Quien trabaja en la escuela tiene un expediente: su número de empleado, su contrato, su puesto, su adscripción a un campus y su cuenta para depositar.\n"
                    ."No reemplaza al catálogo de docentes: aquél es identidad ACADÉMICA —cédula, tipo de docente, de ahí sale a qué materias se le puede asignar— y éste es el vínculo laboral, que también tiene quien nunca da clase. Fundirlos obligaría a inventarle una cédula al personal de intendencia.\n"
                    .'La baja tiene una sola fuente de verdad: la fecha de baja. Y dar de baja cierra las adscripciones abiertas, porque si no, quien renunció seguiría figurando como coordinador de su campus.',
            ],
            [
                'subtitulo' => 'Cuánto se le paga',
                'texto' => "El sueldo va detrás de un permiso PROPIO y en su propia pantalla: quien captura altas y bajas no necesariamente puede ver cuánto gana cada quien.\n"
                    ."La forma de pago se declara por banderas y no por un nombre: una modalidad dice si usa monto base, tarifa por hora o tarifa por asignatura, y el motor suma lo que enciendan. Así «base más horas» se crea desde la pantalla y funciona, sin tocar código.\n"
                    .'Un expediente tiene un solo esquema abierto; abrir uno nuevo cierra el anterior el día antes. El viejo se conserva, que es lo que permite explicar un recibo de hace un año.',
            ],
            [
                'subtitulo' => 'El recibo',
                'texto' => "El recibo se MATERIALIZA: sus renglones guardan el importe calculado, no una referencia al sueldo de hoy. Un documento que se recalcula al mirarlo cambia de contenido cuando alguien actualiza un dato, y un recibo es un hecho fechado que hay que poder explicar en cinco años.\n"
                    ."Una entrada del reloj sin salida no se paga, y se REPORTA. Contarla hasta el fin del día paga horas no trabajadas y nadie lo reclama nunca; ignorarla en silencio le paga de menos sin que sepa por qué. Decirlo es lo único que se puede corregir antes de pagar.\n"
                    .'El timbrado del CFDI de nómina se enciende desde configuración. Apagado, no se puede; encendido, valida antes de mandar nada y dice qué falta y dónde se captura.',
            ],
            [
                'subtitulo' => 'Bolsa de trabajo',
                'texto' => "La escuela registra empleadores y sus vacantes, y los egresados se postulan —o los captura vinculación por ventanilla, según lo que la escuela permita—.\n"
                    ."Lo que se mide al final es la EMPLEABILIDAD: cuántos egresados están colocados. Por eso una colocación no siempre viene de una postulación: un egresado consigue trabajo por su cuenta y la escuela se entera al darle seguimiento, y ése es el dato que piden las acreditadoras.\n"
                    .'Se cuenta por matrícula y sin repetir: quien egresó de dos programas académicos reporta en las dos, y quien cambió de trabajo dos veces sigue siendo un egresado colocado.',
            ],
            [
                'subtitulo' => 'Movilidad',
                'texto' => "Convenios con otras instituciones, convocatorias, postulaciones y estancias. Al volver, lo cursado allá se revalida y se asienta en el historial.\n"
                    .'Esa revalidación entra al historial como cualquier otra materia, con su tipo de evaluación de revalidación y la observación oficial que la SEP reconoce — así viaja en el certificado. Una bandera propia habría dejado el dato fuera del documento oficial.',
            ],
        ],

        'operacion' => [
            [
                'flujo' => 'Dar de alta a un empleado y fijarle sueldo',
                'quien' => 'RH con «gestionar-rh»; el sueldo con «gestionar-percepciones»',
                'pasos' => [
                    'RH → Empleados → alta, reutilizando la persona si ya existe.',
                    'Capturar su número de empleado —se captura, no se genera: una escuela que llega de otro sistema ya trae los suyos impresos—.',
                    'Registrar su contrato, su puesto y su adscripción.',
                    'Desde la ficha, entrar a Percepciones (permiso aparte) y abrir su esquema: modalidad y las cifras que esa modalidad exija.',
                    'Un aumento se hace abriendo un esquema nuevo; el anterior se cierra solo el día antes.',
                ],
            ],
            [
                'flujo' => 'Calcular y timbrar una nómina',
                'quien' => 'Con «gestionar-percepciones»',
                'pasos' => [
                    'RH → Nómina → nuevo periodo, con sus fechas.',
                    'Calcular. El sistema resuelve el sueldo vigente AL FIN DEL PERIODO, no a hoy.',
                    'Revisar lo reportado: entradas del reloj sin salida, empleados sin sueldo fijado.',
                    'Ajustar a mano lo que haga falta. Recalcular avisa de cuántos renglones capturados a mano se va a llevar.',
                    'Si el timbrado está encendido: validar —el validador dice qué falta y dónde se captura— y timbrar recibo por recibo.',
                    'Un recibo ya timbrado no se recalcula ni se le tocan renglones.',
                ],
            ],
            [
                'flujo' => 'Registrar una colocación',
                'quien' => 'Vinculación, con «gestionar-bolsa-trabajo»',
                'pasos' => [
                    'Bolsa → Colocaciones → nueva.',
                    'Elegir al egresado. Si viene de una postulación, ligarla; si no, se captura sola.',
                    'Capturar la empresa, el puesto, la fecha de ingreso y si el empleo se relaciona con su programa académico —que admite «no se sabe», porque afirmar que no lo es sin haber preguntado es una afirmación que nadie hizo—.',
                    'El indicador de empleabilidad se recalcula solo.',
                ],
            ],
            [
                'flujo' => 'Revalidar lo cursado en una estancia',
                'quien' => 'Con «gestionar-movilidad»',
                'pasos' => [
                    'Movilidad → la estancia, ya concluida.',
                    'Elegir la materia del plan que se va a revalidar. No se ofrecen las ya aprobadas.',
                    'Capturar la calificación equivalente y lo que dijo la institución de destino.',
                    'Asentar. Entra al historial con tipo de evaluación «revalidación» y su observación oficial, sin folio de acta porque no sale de un cierre de materia.',
                ],
            ],
        ],

        'tecnico' => [
            [
                'subtitulo' => 'El sueldo se resuelve por fecha',
                'texto' => "`ExpedienteLaboral::esquemaEn()` devuelve el esquema vigente en una fecha. La nómina pregunta por el FIN DEL PERIODO: recalcular una quincena vieja tiene que seguir dando lo de entonces, y preguntar por «el abierto» le aplicaría el aumento de la semana pasada.\n"
                    .'Las banderas de `modalidades_percepcion` exigen su dato y mayor que cero: un esquema por horas con la tarifa en blanco pagaría cero y el recibo saldría sin un solo error — el defecto que no se descubre hasta el día de pago.',
            ],
            [
                'subtitulo' => 'A quién se le paga lo dice una bandera',
                'texto' => "`situaciones_empleado.entra_a_nomina`, no la clave. Licencia sin goce sigue contratado y no cobra; comisión sí cobra. Preguntar por `clave = 'activo'` se equivoca en los dos casos, y ninguno se notaría hasta el día de pago.\n"
                    .'`situaciones_empleado` no siembra ninguna situación de baja, porque «baja» tiene una sola fuente de verdad: `fecha_baja`. Con las dos cosas, un expediente podría decir «activo» con fecha de baja puesta.',
            ],
            [
                'subtitulo' => 'Lo que el ISR no hace',
                'texto' => '`formulas_nomina` es porcentaje sobre una base, con tope. El ISR NO se calcula con eso, y es deliberado: sale de la tarifa por rangos del artículo 96 más el subsidio al empleo. Sembrar una fórmula con un porcentaje inventado daría un número que parece bueno, que alguien enteraría al SAT y que nadie descubriría hasta la primera revisión.',
            ],
            [
                'subtitulo' => 'Bolsa: dos banderas de catálogo',
                'texto' => "`etapas_postulacion.marca_colocacion` y `.es_final`, independientes —«Rechazado» cierra y no coloca—; y `situaciones_alumno.cuenta_como_egresado`, que es el DENOMINADOR del indicador.\n"
                    ."La etapa «contratado» y la colocación son el mismo hecho: `Postulador::mover` se niega a entrar a una etapa que coloca si la colocación no existe. Sin ese candado la pantalla diría «contratado» y el reporte contaría cero.\n"
                    .'Deshacer una colocación usa `forceDelete`, no borrado lógico: el único de la tabla es sobre `postulacion_id` a secas y MySQL no distingue una fila dada de baja.',
            ],
            [
                'subtitulo' => 'Movilidad: sin columna de origen',
                'texto' => "El asiento de una revalidación NO necesitó una bandera nueva: `tipos_evaluacion` ya traía `revalidacion` desde la fase 2, y `observaciones_asignatura` —catálogo de la SEP— ya traía «REVALIDACIÓN DE ESTUDIOS», que es el valor que viaja en el certificado.\n"
                    .'Va sin acta a propósito: sale de un dictamen, no de un cierre de materia. Y sólo se revalida sobre materias no aprobadas, porque el historial toma el mejor intento y un segundo asiento regalaría los créditos.',
            ],
        ],

        'tablas' => [
            ['nombre' => 'expedientes_laborales', 'para_que' => 'El vínculo laboral. Complementa a `docentes`, no lo reemplaza.'],
            ['nombre' => 'puestos / adscripciones', 'para_que' => 'El organigrama de la escuela y quién ocupa qué, desde cuándo.'],
            ['nombre' => 'situaciones_empleado', 'para_que' => 'Matices de quien sigue contratado. `entra_a_nomina` decide quién cobra.'],
            ['nombre' => 'modalidades_percepcion', 'para_que' => 'Formas de pago declaradas por banderas, no por nombre.'],
            ['nombre' => 'esquemas_percepcion', 'para_que' => 'Cuánto gana alguien y desde cuándo. Uno abierto por expediente.'],
            ['nombre' => 'conceptos_nomina / formulas_nomina', 'para_que' => 'Percepciones y deducciones, y los cálculos porcentuales.'],
            ['nombre' => 'periodos_nomina / recibos_nomina / recibo_conceptos', 'para_que' => 'El periodo y el recibo materializado, renglón por renglón.'],
            ['nombre' => 'empresas / empresa_contactos', 'para_que' => 'Los empleadores y con quién se habla. Una empresa se veta, no se borra.'],
            ['nombre' => 'vacantes / habilidades', 'para_que' => 'Las plazas publicadas. Sin programas académicos señaladas, la vacante es para todas.'],
            ['nombre' => 'postulaciones / postulacion_bitacora', 'para_que' => 'Quién se postuló y cómo avanzó. La bitácora existe para medir tiempos.'],
            ['nombre' => 'colocaciones', 'para_que' => 'El hecho de haber sido contratado. `postulacion_id` es nullable a propósito.'],
            ['nombre' => 'convenios / convocatorias_movilidad', 'para_que' => 'Con quién hay acuerdo y qué se convoca.'],
            ['nombre' => 'postulaciones_movilidad / estancias', 'para_que' => 'Quién va, a dónde y cuándo. Titular dual con CHECK, como los adeudos.'],
            ['nombre' => 'revalidaciones / dictamenes_revalidacion', 'para_que' => 'Lo cursado fuera que se reconoce aquí.'],
        ],

        'pantallas' => [
            ['ruta' => '/rh/empleados', 'que_hace' => 'Expedientes laborales, altas, bajas y adscripciones.', 'permiso' => 'gestionar-rh'],
            ['ruta' => '/rh/nomina', 'que_hace' => 'Periodos, cálculo, recibos y timbrado.', 'permiso' => 'gestionar-percepciones'],
            ['ruta' => '/rh/catalogos-nomina', 'que_hace' => 'Conceptos, modalidades y fórmulas.', 'permiso' => 'gestionar-percepciones'],
            ['ruta' => '/bolsa/empresas', 'que_hace' => 'Padrón de empleadores.', 'permiso' => 'gestionar-bolsa-trabajo'],
            ['ruta' => '/bolsa/vacantes', 'que_hace' => 'Publicación de plazas.', 'permiso' => 'gestionar-bolsa-trabajo'],
            ['ruta' => '/bolsa/colocaciones', 'que_hace' => 'Registro de contrataciones.', 'permiso' => 'gestionar-bolsa-trabajo'],
            ['ruta' => '/bolsa/empleabilidad', 'que_hace' => 'El indicador, con sus filtros y su desglose.', 'permiso' => 'gestionar-bolsa-trabajo'],
            ['ruta' => '/mis-vacantes', 'que_hace' => 'El tablero del alumno. Postularse solo depende de un interruptor.', 'permiso' => 'ver-vacantes'],
            ['ruta' => '/movilidad/convenios', 'que_hace' => 'Convenios con instituciones aliadas.', 'permiso' => 'gestionar-movilidad'],
            ['ruta' => '/movilidad/convocatorias', 'que_hace' => 'Convocatorias, postulaciones, estancias y revalidaciones.', 'permiso' => 'gestionar-movilidad'],
        ],

        'reglas' => [
            ['regla' => 'El expediente laboral complementa a `docentes`, no lo reemplaza.', 'porque' => 'Aquél es identidad académica; éste, el vínculo laboral, que también tiene quien nunca da clase.'],
            ['regla' => 'El sueldo va detrás de un permiso propio y en su propia ruta.', 'porque' => 'Es el dato más sensible del sistema, y esconder la sección con un `v-if` no es una defensa.'],
            ['regla' => 'La modalidad de pago se lee por sus banderas, no por su clave.', 'porque' => 'Así «base más horas» se crea desde la pantalla y funciona. Un catálogo cuyos valores el código reconoce por nombre no es configurable.'],
            ['regla' => 'El recibo se materializa.', 'porque' => 'Un documento que se recalcula al mirarlo cambia de contenido cuando alguien actualiza un dato de hoy.'],
            ['regla' => 'Una entrada del reloj sin salida no se paga, y se reporta.', 'porque' => 'Pagarla regala horas y nadie lo reclama; ignorarla en silencio paga de menos sin explicar por qué.'],
            ['regla' => 'Quién cobra lo dice `entra_a_nomina`, no la clave de la situación.', 'porque' => 'Licencia sin goce sigue contratado y no cobra; comisión sí cobra. Ninguno de los dos errores se nota hasta el día de pago.'],
            ['regla' => 'El ISR no se calcula con `formulas_nomina`.', 'porque' => 'Sale de la tarifa por rangos y el subsidio al empleo. Una fórmula plana daría un número que alguien enteraría al SAT.'],
            ['regla' => 'Una colocación no siempre viene de una postulación.', 'porque' => 'Obligándolo, el indicador contestaría «a cuántos colocó nuestra bolsa» y no «cuántos egresados están colocados», que es lo que se presume.'],
            ['regla' => 'La empleabilidad se cuenta por matrícula y con DISTINCT.', 'porque' => 'Cada programa reporta lo suyo, y quien cambió de trabajo dos veces sigue siendo un egresado colocado. Sin distinct el porcentaje puede pasar del 100 %.'],
            ['regla' => 'La revalidación no necesitó una columna de origen.', 'porque' => 'El tipo de evaluación y la observación oficial ya existían, y son las que viajan en el certificado. Una bandera propia habría dejado el dato fuera del documento.'],
            ['regla' => 'No se revalida lo ya aprobado.', 'porque' => 'El historial toma el mejor intento por materia: un segundo asiento regalaría los créditos.'],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────
    [
        'clave' => 'servicios',
        'titulo' => 'Familia, disciplina, comunicación y reportes',
        'resumen' => 'Lo que conecta a la escuela con las familias, lo que registra la conducta, lo que comunica, y lo que permite sacar cualquier dato en una hoja de cálculo.',

        'no_tecnico' => [
            [
                'subtitulo' => 'El portal de la familia',
                'texto' => "Los padres y tutores entran con su propia cuenta y ven a sus hijos: cómo van, qué deben, qué avisos hay. Cada vínculo declara el parentesco y si esa persona es contacto de emergencia o responsable de pago.\n"
                    ."El tutor también tiene expediente propio: la escuela le pide sus papeles —su identificación, su comprobante de domicilio— y él los entrega desde su portal. Son SUS papeles, no los de su hijo.\n"
                    .'Y las autorizaciones: para una salida, para el uso de imagen. Se piden por VÍNCULO y no por alumno, porque quien autoriza es una persona concreta y su respuesta es suya. Un alumno con padre y madre recibe dos, y la escuela ve «respondió uno de dos» en vez de un sí del que nadie se hace responsable. Cuántas respuestas hacen falta no lo decide el sistema: depende del trámite.',
            ],
            [
                'subtitulo' => 'Conducta',
                'texto' => "Incidencias y sanciones, colgadas de la MATRÍCULA: quien estudia dos programas_academicos tiene su conducta separada por programa.\n"
                    ."El docente levanta incidencias de SUS alumnos —el alcance lo da su asignación, no el permiso—, y control escolar sanciona. Una sanción puede citar las incidencias que la originaron, y sólo las del mismo alumno.\n"
                    .'Si una sanción tiene vigencia lo dice su tipo: una suspensión pide fechas, una amonestación no. El padre lo ve en su portal, sólo de lectura; el alumno no.',
            ],
            [
                'subtitulo' => 'Calendario, avisos y encuestas',
                'texto' => "El calendario escolar guarda feriados y eventos, y a cada uno se le dice a QUIÉN va: a toda la escuela, a un rol, a un campus, a una programa_academico, a un grupo, a una materia, o a alumnos señalados uno por uno. Los destinos se SUMAN: «campus norte» más «grupo A» son los del norte y además el grupo A, porque exigir cumplir todos dejaría casi cualquier aviso sin público.\n"
                    ."Hay además un modificador «y a sus familias», que no señala a nadie por sí solo: extiende a los tutores lo que los demás destinos ya dijeron.\n"
                    .'Los avisos usan la misma segmentación, con adjuntos y acuse de lectura. Y las encuestas —de evaluación docente, por ejemplo— tienen un umbral de anonimato: por debajo de cierto número de respuestas no se muestran resultados, para que la siguiente encuesta se conteste con sinceridad.',
            ],
            [
                'subtitulo' => 'Recursos digitales, trámites y credencial',
                'texto' => "La recursos_digitales digital publica enlaces y recursos que el alumno consulta. Los trámites —constancias, cartas— se piden desde el portal del alumno y se atienden desde el mostrador.\n"
                    .'La credencial virtual se diseña por ROL: el gafete del alumno trae matrícula y programa_academico, el del docente no. Cada quien descarga la suya, y quien estudia dos programas_academicos tiene dos. El QR lleva una DIRECCIÓN y no los datos: un código que cargue el nombre dentro no verifica nada, porque cualquiera genera uno que diga lo que quiera.',
            ],
            [
                'subtitulo' => 'Reportes',
                'texto' => "Reportes por área, con filtros, columnas elegibles y descarga en Excel o CSV. Hoy son 34 reportes sobre 14 fuentes de datos, repartidos en nueve áreas que la escuela puede renombrar y reorganizar.\n"
                    ."Una vista guardada conserva filtros, columnas y orden con un nombre, y se puede compartir. Y se puede PROGRAMAR: que llegue por correo cada lunes.\n"
                    .'Lo importante de la seguridad: un reporte programado corre con el ROL GUARDADO en la programación. De madrugada no hay sesión abierta, así que correrlo en global sería mandarle la escuela entera a quien sólo ve un plantel, todos los lunes, sin que nadie lo mirara.',
            ],
        ],

        'operacion' => [
            [
                'flujo' => 'Vincular a un padre con su hijo y darle acceso',
                'quien' => 'Con «editar-tutores»',
                'pasos' => [
                    'Padres y tutores → Directorio → alta, reutilizando la persona si ya existe.',
                    'Vincularlo con el alumno, indicando el parentesco y si es contacto de emergencia o responsable de pago.',
                    'Darle cuenta desde Plataforma → Usuarios, con el rol de padre de familia.',
                    'Desde ese momento entra a «Mis hijos».',
                ],
            ],
            [
                'flujo' => 'Pedir una autorización a las familias',
                'quien' => 'Con «gestionar-autorizaciones»',
                'pasos' => [
                    'Plataforma → Autorizaciones → nueva.',
                    'Capturar el título, el detalle y la fecha límite.',
                    'Elegir a los alumnos. El sistema crea una autorización por VÍNCULO.',
                    'Al emitir se reporta, por su nombre, a los alumnos que no tienen familiares vinculados: es el caso que arruina el trámite.',
                    'Las familias contestan desde «Mis hijos». Sin contestar no cuenta como negada.',
                ],
            ],
            [
                'flujo' => 'Publicar un aviso a un público concreto',
                'quien' => 'Con «gestionar-avisos»',
                'pasos' => [
                    'Plataforma → Avisos → nuevo.',
                    'Escribir el aviso y adjuntar lo que haga falta.',
                    'Agregar destinos. Se suman entre sí.',
                    'Si además debe llegarles a las familias, marcar el modificador. No vale como único destino.',
                    'Publicar. Cada quien lo ve en «Mis avisos», y queda el acuse de quién lo leyó.',
                ],
            ],
            [
                'flujo' => 'Sacar un reporte y programarlo',
                'quien' => 'Con «ver-reportes»',
                'pasos' => [
                    'Reportes → elegir el área y el reporte.',
                    'Poner los filtros y elegir columnas. Se puede ordenar pulsando la cabecera.',
                    'Descargar en Excel o CSV, o guardar la vista con un nombre.',
                    'Desde la vista guardada, programar el envío: a qué hora, qué días y a quién.',
                    'Un destinatario sin el permiso del reporte se descarta y se anota, con su nombre y su razón.',
                ],
            ],
        ],

        'tecnico' => [
            [
                'subtitulo' => 'Segmentación',
                'texto' => "`eventos_calendario` dice qué y cuándo; `evento_destinos` dice a quién. `destino_id` NO lleva foránea porque apunta a tablas distintas según el tipo — es lo que permite agregar «por turno» sin migrar; a cambio, lo que apunta a algo borrado se muestra como «ya no existe».\n"
                    ."`ContextoAcademico` resuelve dónde está parada una persona; `AgendaDeUsuario` contesta «¿esto es para mí?» filtrando en SQL contra el índice (tipo, destino_id).\n"
                    ."Los roles salen de `persona_rol` y no del rol activo: un aviso para docentes no puede desaparecer porque alguien conmutó de rol.\n"
                    .'El modificador «familias» es lo ÚNICO que se cruza en vez de sumarse: hace falta el modificador Y que algún hijo encaje. Con un OR, cualquier aviso con el modificador llegaría a todos los padres de la escuela.',
            ],
            [
                'subtitulo' => 'El motor de reportes',
                'texto' => "Un reporte es una DEFINICIÓN, no una consulta: `FuenteDeReporte` declara columnas y filtros de un dominio, y cada `DefinicionReporte` es una pregunta concreta sobre esa fuente.\n"
                    ."La autorización la vuelve a resolver el EJECUTOR en cada camino —pantalla, XLSX y CSV—, no la pantalla que armó la petición. Es lo que hace que una vista compartida comparta la configuración y no los datos.\n"
                    ."El recorte por campus tiene cinco formas, porque no todas las tablas tienen `campus_id`; `SIN_CAMPUS` lanza 403 en vez de devolver todo.\n"
                    .'El CSV se escribe renglón por renglón contra `php://output`; el XLSX usa PhpSpreadsheet y por eso lleva tope de filas. `TextoDeCelda` neutraliza lo que Excel tomaría por fórmula.',
            ],
            [
                'subtitulo' => 'Dos reglas de MySQL que costaron caro',
                'texto' => "El recorrido por lotes usa keyset con tuplas. MySQL ordena los NULL primero en ASC y al final en DESC, así que la rama del cursor tiene que mirar la dirección. Y `(3,2) > (null,1)` no es falso: es NULL, y una condición NULL descarta la fila.\n"
                    .'Además, MySQL acepta un alias de SELECT en el `ORDER BY` y NO en el `WHERE`: un agregado que se ordena tiene que entrar por `leftJoinSub`, o la pantalla ordena bien y la exportación revienta.',
            ],
            [
                'subtitulo' => 'Credencial',
                'texto' => "Se diseña por rol, con las cajas arrastrables guardadas en PORCENTAJE para que el mapa sobreviva a cambiar el tamaño. El fondo del editor lo dibuja el servidor: imitarlo con CSS habría acomodado las cajas respecto de algo que no existe.\n"
                    .'La emisión se registra en `credenciales` con uuid, porque la dirección tiene que ser estable —se imprime— y no adivinable. Firmar la URL con `APP_KEY` habría evitado la tabla y dejaría inservible toda credencial impresa el día que se rote la llave.',
            ],
        ],

        'tablas' => [
            ['nombre' => 'tutores_alumno / parentescos', 'para_que' => 'El vínculo familiar y qué es esa persona del alumno.'],
            ['nombre' => 'documentos_tutor', 'para_que' => 'Los papeles del propio tutor. Cuelgan de la persona, no del vínculo.'],
            ['nombre' => 'autorizaciones / tipos_autorizacion', 'para_que' => 'Consentimientos por vínculo. `concedida` en NULL es «no ha contestado», no «no».'],
            ['nombre' => 'incidencias / sanciones', 'para_que' => 'La conducta, colgada de la matrícula.'],
            ['nombre' => 'tipos_incidencia / tipos_sancion', 'para_que' => 'Catálogos con banderas de comportamiento: nivel, y si la sanción tiene vigencia.'],
            ['nombre' => 'eventos_calendario / evento_destinos', 'para_que' => 'Qué pasa y a quién le toca. Los destinos se suman.'],
            ['nombre' => 'avisos / avisos_destinos / avisos_lecturas', 'para_que' => 'Comunicados, su público y su acuse.'],
            ['nombre' => 'encuestas / aplicaciones_encuesta / encuesta_respuestas', 'para_que' => 'Cuestionarios aplicados y lo contestado, con umbral de anonimato.'],
            ['nombre' => 'recursos_digitales', 'para_que' => 'Los recursos que publica la escuela.'],
            ['nombre' => 'servicios / solicitudes_servicio', 'para_que' => 'Trámites del alumno y su atención.'],
            ['nombre' => 'credenciales / credenciales_rol', 'para_que' => 'El diseño del gafete por rol y cada emisión con su uuid.'],
            ['nombre' => 'areas_reporte / ubicaciones_reporte', 'para_que' => 'Cómo llama la escuela a sus áreas y dónde puso cada reporte.'],
            ['nombre' => 'vistas_reporte / reportes_favoritos', 'para_que' => 'Filtros y columnas guardados, compartidos o no.'],
            ['nombre' => 'programaciones_reporte / destinatarios_reporte', 'para_que' => 'El envío por correo y a quién.'],
            ['nombre' => 'ejecuciones_reporte', 'para_que' => 'Qué se pidió, quién y cuándo. Nunca lo que salió.'],
            ['nombre' => 'efemerides', 'para_que' => 'Qué se conmemora hoy. Se cataloga, no se consume de una API.'],
        ],

        'pantallas' => [
            ['ruta' => '/mis-hijos', 'que_hace' => 'El portal de la familia.', 'permiso' => 'ver-mis-hijos'],
            ['ruta' => '/mis-hijos/expediente', 'que_hace' => 'Los papeles del propio tutor.', 'permiso' => 'editar-mi-expediente-tutor'],
            ['ruta' => '/padres-tutores', 'que_hace' => 'Directorio de padres y tutores.', 'permiso' => 'ver-tutores'],
            ['ruta' => '/plataforma/autorizaciones', 'que_hace' => 'Emitir y seguir consentimientos.', 'permiso' => 'gestionar-autorizaciones'],
            ['ruta' => '/escolar/incidencias', 'que_hace' => 'Incidencias de conducta.', 'permiso' => 'gestionar-incidencias'],
            ['ruta' => '/escolar/sanciones', 'que_hace' => 'Sanciones, con las incidencias que las originaron.', 'permiso' => 'gestionar-sanciones'],
            ['ruta' => '/docencia/incidencias', 'que_hace' => 'El docente levanta las de sus alumnos.', 'permiso' => 'levantar-incidencia'],
            ['ruta' => '/plataforma/calendario', 'que_hace' => 'Feriados y eventos, con su público.', 'permiso' => 'gestionar-calendario'],
            ['ruta' => '/plataforma/avisos', 'que_hace' => 'Comunicados segmentados, con acuse.', 'permiso' => 'gestionar-avisos'],
            ['ruta' => '/encuestas/aplicaciones', 'que_hace' => 'Aplicación de encuestas y resultados.', 'permiso' => 'gestionar-encuestas'],
            ['ruta' => '/escolar/recursos-digitales', 'que_hace' => 'Administración de la recursos digitales.', 'permiso' => 'gestionar-recursos-digitales'],
            ['ruta' => '/escolar/servicios', 'que_hace' => 'El mostrador de trámites.', 'permiso' => 'atender-servicios'],
            ['ruta' => '/plataforma/configuraciones/credencial', 'que_hace' => 'Diseñador de la credencial por rol.', 'permiso' => 'gestionar-credenciales'],
            ['ruta' => '/reportes', 'que_hace' => 'Reportes por área, con filtros, totales y agrupados.', 'permiso' => 'ver-reportes'],
            ['ruta' => '/reportes/programaciones', 'que_hace' => 'Envío por correo de una vista guardada.', 'permiso' => 'ver-reportes'],
            ['ruta' => '/reportes/bitacora', 'que_hace' => 'Quién pidió qué reporte y con qué filtros.', 'permiso' => 'ver-reportes + auditar-reportes'],
        ],

        'reglas' => [
            ['regla' => 'Una autorización se pide por VÍNCULO, no por alumno.', 'porque' => 'Quien autoriza es una persona concreta. Con una por alumno, un sí no tendría quién se hiciera responsable.'],
            ['regla' => '`concedida` en NULL es «no ha contestado» y no cuenta como negada.', 'porque' => 'La diferencia es legal, no cosmética. Una vencida sin contestar sigue pendiente, que es otra información.'],
            ['regla' => 'Los destinos de un aviso se suman, no se cruzan.', 'porque' => 'Exigir cumplir todos dejaría casi cualquier aviso sin público: nadie es a la vez «todos los docentes» y «el grupo A».'],
            ['regla' => '«Y a sus familias» es un modificador, y es lo único que se cruza.', 'porque' => 'No señala a nadie por sí solo: extiende a los tutores lo que los demás destinos dijeron. Con un OR llegaría a todos los padres de la escuela.'],
            ['regla' => 'El público de un aviso sale de todos los roles de la persona, no del activo.', 'porque' => 'Un aviso para docentes no puede desaparecer porque alguien conmutó de rol para revisar otra cosa.'],
            ['regla' => 'La conducta cuelga de la matrícula.', 'porque' => 'Quien estudia dos programas académicos tiene su conducta separada por programa, igual que su historial.'],
            ['regla' => 'Si una sanción tiene vigencia lo dice su tipo.', 'porque' => 'Una suspensión pide fechas y una amonestación no; cambiar de tipo no puede conservar fechas que ya no significan nada.'],
            ['regla' => 'Un reporte programado corre con el rol GUARDADO.', 'porque' => 'De madrugada no hay sesión, así que no hay rol activo del que sacar el alcance. En global le mandaría la escuela entera a quien sólo ve un plantel.'],
            ['regla' => 'La bitácora de reportes guarda lo que se PIDIÓ, nunca lo que salió.', 'porque' => 'Quien audita ve que alguien exportó la cartera del campus norte, no la cartera.'],
            ['regla' => 'El QR de la credencial lleva una dirección, no los datos.', 'porque' => 'Un código que cargue el nombre dentro no verifica nada: cualquiera genera uno que diga lo que quiera.'],
            ['regla' => 'Las encuestas tienen umbral de anonimato.', 'porque' => 'Por debajo de cierto número de respuestas se identifica a quien contestó, y la siguiente ya nadie la contesta con sinceridad.'],
        ],
    ],
];
