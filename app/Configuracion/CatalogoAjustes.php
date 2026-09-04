<?php

declare(strict_types=1);

namespace App\Configuracion;

/**
 * Las reglas de operación de la escuela, declaradas en un solo lugar.
 *
 * Mismo criterio que `CatalogoPermisos`: el catálogo es CÓDIGO —cada ajuste
 * existe porque hay una línea que lo consulta— y lo configurable son sus
 * VALORES. Un ajuste inventado desde una pantalla no lo leería nadie.
 *
 * Están pensados para fijarse ANTES de que existan registros, que es cuando
 * salen gratis. Después siguen siendo editables —una escuela puede cambiar de
 * criterio a media operación y tiene derecho— pero la pantalla dice cuántos
 * registros ya se hicieron bajo la regla anterior, porque cambiar el límite NO
 * reevalúa lo que ya pasó.
 *
 * Cada límite viene con su ACCIÓN: advertir o bloquear. Es la diferencia entre
 * "la escuela quiere saberlo" y "la escuela no lo permite", y no es la misma
 * decisión en todas: hay quien tolera un cuarto extraordinario con visto bueno
 * de dirección y quien no.
 */
final class CatalogoAjustes
{
    // Alumnos.
    public const TOPE_FILAS_XLSX = 'reportes.tope_filas_xlsx';

    public const MAX_RECURSAMIENTOS = 'alumno.max_recursamientos_por_materia';

    public const ACCION_RECURSAMIENTOS = 'alumno.accion_exceso_recursamientos';

    public const MAX_EXTRAORDINARIOS = 'alumno.max_extraordinarios_por_materia';

    public const ACCION_EXTRAORDINARIOS = 'alumno.accion_exceso_extraordinarios';

    public const MAX_MATERIAS_CICLO = 'alumno.max_materias_por_ciclo';

    public const ACCION_MATERIAS_CICLO = 'alumno.accion_exceso_materias_ciclo';

    public const BLOQUEO_FINANCIERO = 'alumno.bloquea_inscripcion_con_adeudo';

    // Docentes.
    public const MAX_MATERIAS_DOCENTE = 'docente.max_materias_por_ciclo';

    public const EXIGE_CEDULA = 'docente.exige_cedula_para_asignar';

    // Clases en línea.
    public const VIDEO_ANTELACION = 'video.minutos_antes_de_abrir';

    public const VIDEO_PUBLICAR_GRABACIONES = 'video.grabaciones_visibles_al_llegar';

    // Bolsa de trabajo.
    public const BOLSA_AUTOGESTIVA = 'bolsa.postulacion_autogestiva';

    public const TIMBRADO_NOMINA = 'nomina.timbrado_cfdi';

    // Servicio social y prácticas.
    public const PROCESOS_PEDIR_UBICACION = 'procesos.pedir_ubicacion';

    // Permanencia.
    public const PERMANENCIA_AVISOS_DESDE = 'permanencia.avisos_desde_hora';

    public const PERMANENCIA_AVISOS_HASTA = 'permanencia.avisos_hasta_hora';

    public const PERMANENCIA_DIAS_SIN_ASIGNAR = 'permanencia.dias_para_avisar_sin_asignar';

    public const PERMANENCIA_DOCENTE_VE_ALERTAS = 'permanencia.docente_ve_alertas';

    // Caja.
    public const CAJA_EXIGE_SESION = 'caja.exige_sesion_para_efectivo';

    public const CAJA_TOLERANCIA = 'caja.tolerancia_diferencia';

    // Admisiones.
    public const EXIGE_DOCUMENTOS = 'aspirante.exige_documentos_para_convertir';

    public const EXIGE_PAGO = 'aspirante.exige_pago_para_convertir';

    public const ASESOR_QUIEN_REGISTRA = 'aspirante.asesor_se_lo_queda_quien_registra';

    public const ASIGNACION_ASESOR = 'aspirante.asignacion_de_asesor';

    // Familia.
    public const MAYORIA_DE_EDAD = 'familia.mayoria_de_edad';

    public const TUTOR_ENTREGA_DOCUMENTOS = 'familia.tutor_entrega_documentos';

    // Acta (ya existían sueltos; se traen al catálogo).
    public const ACTA_FORMATO_FOLIO = 'acta.formato_folio';

    public const ACTA_AMBITO = 'acta.ambito_consecutivo';

    private const ACCIONES = ['advertir' => 'Solo advertir', 'bloquear' => 'Bloquear'];

    /**
     * Qué hacer con los prospectos que NO se quedó quien los registró.
     *
     * Son dos decisiones distintas y por eso son dos ajustes: que el asesor se
     * quede lo que él mismo trae es una cosa, y qué pasa con todo lo demás —lo
     * que cae por la web, lo que captura recepción— es otra. Metidas en un solo
     * desplegable obligaban a elegir, y lo normal es querer las dos.
     */
    private const REPARTOS = [
        'manual' => 'A mano: alguien lo asigna',
        'secuencial' => 'Por turno entre los asesores del campus',
    ];

    /**
     * @return array<int, Ajuste>
     */
    public static function todos(): array
    {
        return [
            new Ajuste(
                clave: self::TOPE_FILAS_XLSX,
                grupo: 'Reportes',
                etiqueta: 'Tope de filas en Excel',
                descripcion: 'Cuantas filas admite una descarga en .xlsx. No es un capricho: PhpSpreadsheet arma el libro entero en memoria, asi que un archivo muy grande no se hace mas lento, se muere a la mitad.',
                tipo: Ajuste::ENTERO,
                porDefecto: 5000,
                min: 100,
                max: 50000,
                consecuencia: 'Por encima del tope el reporte se niega ANTES de empezar y ofrece el CSV, que no tiene limite porque se escribe fila por fila. Subirlo mucho cambia un aviso claro por un error de memoria a los tres minutos.',
            ),
            new Ajuste(
                clave: self::MAX_RECURSAMIENTOS,
                grupo: 'Alumnos',
                etiqueta: 'Recursamientos por materia',
                descripcion: 'Cuántas veces puede un alumno volver a cursar la MISMA materia de su plan. 0 = sin límite.',
                tipo: Ajuste::ENTERO,
                porDefecto: 2,
                min: 0,
                max: 10,
                consecuencia: 'Se comprueba al inscribir. Bajarlo no da de baja a quien ya está inscrito por encima del nuevo límite.',
            ),
            new Ajuste(
                clave: self::ACCION_RECURSAMIENTOS,
                grupo: 'Alumnos',
                etiqueta: 'Al exceder los recursamientos',
                descripcion: 'Qué hace el sistema cuando se llega al límite.',
                tipo: Ajuste::SELECCION,
                porDefecto: 'bloquear',
                opciones: self::ACCIONES,
            ),
            new Ajuste(
                clave: self::MAX_EXTRAORDINARIOS,
                grupo: 'Alumnos',
                etiqueta: 'Extraordinarios por materia',
                descripcion: 'Cuántas veces puede presentar extraordinario de la misma materia de su plan. 0 = sin límite.',
                tipo: Ajuste::ENTERO,
                porDefecto: 2,
                min: 0,
                max: 10,
                consecuencia: 'Se comprueba al FIRMAR el acta extraordinaria, que es cuando el intento queda asentado.',
            ),
            new Ajuste(
                clave: self::ACCION_EXTRAORDINARIOS,
                grupo: 'Alumnos',
                etiqueta: 'Al exceder los extraordinarios',
                descripcion: 'Qué hace el sistema cuando se llega al límite.',
                tipo: Ajuste::SELECCION,
                porDefecto: 'bloquear',
                opciones: self::ACCIONES,
            ),
            new Ajuste(
                clave: self::MAX_MATERIAS_CICLO,
                grupo: 'Alumnos',
                etiqueta: 'Materias por ciclo',
                descripcion: 'Carga máxima que puede llevar un alumno en un mismo ciclo. 0 = sin límite.',
                tipo: Ajuste::ENTERO,
                porDefecto: 0,
                min: 0,
                max: 20,
            ),
            new Ajuste(
                clave: self::ACCION_MATERIAS_CICLO,
                grupo: 'Alumnos',
                etiqueta: 'Al exceder la carga',
                descripcion: 'Qué hace el sistema cuando se llega al límite de materias.',
                tipo: Ajuste::SELECCION,
                porDefecto: 'advertir',
                opciones: self::ACCIONES,
            ),
            new Ajuste(
                clave: self::BLOQUEO_FINANCIERO,
                grupo: 'Alumnos',
                etiqueta: 'El adeudo impide inscribirse',
                descripcion: 'Si una situación financiera marcada como bloqueante en el catálogo impide inscribir materias. Sin esto, la bandera «bloquea» solo informa.',
                tipo: Ajuste::BOOLEANO,
                porDefecto: false,
                consecuencia: 'Quién queda bloqueado lo decide el catálogo de situaciones de pago, no este interruptor.',
            ),

            new Ajuste(
                clave: self::MAX_MATERIAS_DOCENTE,
                grupo: 'Docentes',
                etiqueta: 'Materias por ciclo',
                descripcion: 'Cuántas materias puede impartir un docente en el mismo ciclo. 0 = sin límite.',
                tipo: Ajuste::ENTERO,
                porDefecto: 0,
                min: 0,
                max: 30,
                consecuencia: 'Se comprueba al asignarlo. No desasigna a quien ya rebasa el nuevo límite.',
            ),
            new Ajuste(
                clave: self::EXIGE_CEDULA,
                grupo: 'Docentes',
                etiqueta: 'Exigir cédula para asignarle materias',
                descripcion: 'Impide poner al frente de un grupo a alguien sin cédula profesional capturada.',
                tipo: Ajuste::BOOLEANO,
                porDefecto: false,
            ),
            new Ajuste(
                clave: self::VIDEO_ANTELACION,
                grupo: 'Clases en línea',
                etiqueta: 'Abrir la clase antes de la hora',
                descripcion: 'Minutos antes del inicio en que al alumno ya le aparece el botón para entrar. '
                    .'Con 0 sólo puede entrar a la hora exacta, y quien llegue puntual encuentra la puerta cerrada.',
                tipo: Ajuste::ENTERO,
                porDefecto: 10,
                min: 0,
                max: 120,
            ),
            new Ajuste(
                clave: self::VIDEO_PUBLICAR_GRABACIONES,
                grupo: 'Clases en línea',
                etiqueta: 'Publicar las grabaciones en cuanto llegan',
                descripcion: 'Encendido, cada grabación que se archiva le aparece sola a los alumnos de esa '
                    .'materia. Apagado, llega oculta y el docente decide cuáles publicar. '
                    .'Sólo afecta a las que lleguen de aquí en adelante: cambiar esto NO publica ni esconde '
                    .'las que ya están.',
                tipo: Ajuste::BOOLEANO,
                /*
                 * Apagado por omisión, y la escuela decide.
                 *
                 * Una clase grabada trae caras y voces de menores, así que el
                 * valor por omisión es el que no publica a nadie sin que alguien
                 * lo pida. Quien quiera lo contrario lo enciende aquí — que es
                 * justo lo que pidió el cliente— y queda dicho que fue una
                 * decisión suya, no un efecto de haber configurado el archivado.
                 */
                porDefecto: false,
            ),
            new Ajuste(
                clave: self::PROCESOS_PEDIR_UBICACION,
                grupo: 'Servicio social y prácticas',
                etiqueta: 'Pedir la ubicación al registrar una jornada',
                descripcion: 'Encendido, la pantalla le pide al alumno permiso del navegador y guarda dónde '
                    .'estaba al capturar sus horas. Apagado —lo normal—, no se guarda ninguna coordenada. '
                    .'NUNCA es obligatoria: quien no dé el permiso registra sus horas igual, así que esto no '
                    .'sirve para exigir presencia, sólo para dejar constancia cuando el alumno quiere darla.',
                tipo: Ajuste::BOOLEANO,
                /*
                 * Apagado por omisión, y por instrucción del cliente.
                 *
                 * Pedirle la ubicación a un estudiante cada vez que registra una
                 * jornada es rastrearlo, y esa decisión no puede ser un efecto
                 * secundario de haber configurado el módulo. Mismo criterio que
                 * la publicación automática de grabaciones.
                 */
                porDefecto: false,
            ),
            new Ajuste(
                clave: self::BOLSA_AUTOGESTIVA,
                grupo: 'Bolsa de trabajo',
                etiqueta: 'Que el alumno se postule solo',
                descripcion: 'Encendido, quien vea las vacantes puede postularse desde su portal. '
                    .'Apagado, las sigue viendo pero la postulación se hace en ventanilla: el botón '
                    .'desaparece y la dirección deja de responder. En los dos casos vinculación puede '
                    .'capturar postulaciones por él.',
                tipo: Ajuste::BOOLEANO,
                /*
                 * Apagado por omisión, por decisión del cliente y con razón.
                 *
                 * Una escuela que acaba de encender el módulo todavía no tiene a
                 * nadie revisando lo que llegue; con esto encendido, la primera
                 * vacante que publique le abre la puerta a toda la matrícula el
                 * mismo día. Encenderlo es un acto deliberado, igual que
                 * encender el módulo.
                 */
                porDefecto: false,
            ),

            new Ajuste(
                clave: self::TIMBRADO_NOMINA,
                grupo: 'Nómina',
                etiqueta: 'Timbrar los recibos de nómina ante el SAT',
                descripcion: 'Apagado, los recibos se calculan y se pagan pero no se timbran. '
                    .'Encendido, aparece el timbrado y el sistema revisa lo que el SAT exige.',
                tipo: Ajuste::BOOLEANO,
                /*
                 * Apagado por omisión, y es la decisión del cliente.
                 *
                 * Una escuela puede llevar su nómina interna sin timbrar —o
                 * timbrar por fuera con su contador— y encender esto sin tener
                 * el registro patronal, el CSD y las claves del SAT capturadas
                 * llenaría la pantalla de errores el día de pago. Encendido, el
                 * validador dice exactamente qué falta ANTES de intentarlo.
                 */
                porDefecto: false,
            ),

            new Ajuste(
                clave: self::CAJA_EXIGE_SESION,
                grupo: 'Caja',
                etiqueta: 'Exigir un turno de caja abierto para cobrar en efectivo',
                descripcion: 'Encendido, no se registra un cobro en efectivo sin turno abierto: ese '
                    .'dinero no aparecería en ningún corte. Apagado, se cobra como hasta ahora y el '
                    .'efectivo queda fuera del arqueo.',
                tipo: Ajuste::BOOLEANO,
                /*
                 * Apagado por omisión, y aquí el lado seguro no es el obvio.
                 *
                 * Encendido en una escuela que todavía no dio de alta sus cajas
                 * BLOQUEA todo el cobro en efectivo desde el primer minuto. Se
                 * enciende cuando ya hay cajas y gente con el permiso de
                 * operarlas, que es lo que la propia pantalla de caja dice.
                 */
                porDefecto: false,
                consecuencia: 'Sin un turno abierto, la ventanilla dejará de poder cobrar en efectivo.',
            ),

            new Ajuste(
                clave: self::CAJA_TOLERANCIA,
                grupo: 'Caja',
                etiqueta: 'Diferencia que se acepta sin autorizar (pesos)',
                descripcion: 'Por debajo de esto el corte cierra solo. Por encima queda esperando que '
                    .'un supervisor explique la diferencia.',
                tipo: Ajuste::ENTERO,
                /*
                 * Cero: en una escuela los importes son exactos y no hay
                 * redondeo de caja, así que cualquier diferencia debería tener
                 * explicación. Quien cobre con redondeo lo sube.
                 */
                porDefecto: 0,
                min: 0,
                max: 1000,
                consecuencia: 'Subirlo deja pasar sin explicación las diferencias por debajo del tope.',
            ),

            new Ajuste(
                clave: self::EXIGE_DOCUMENTOS,
                grupo: 'Admisiones',
                etiqueta: 'Exigir expediente completo para convertir en alumno',
                descripcion: 'No deja generar matrícula si al aspirante le falta algún documento obligatorio aceptado.',
                tipo: Ajuste::BOOLEANO,
                porDefecto: false,
            ),
            new Ajuste(
                clave: self::EXIGE_PAGO,
                grupo: 'Admisiones',
                etiqueta: 'Exigir inscripción pagada para convertir en alumno',
                descripcion: 'No deja generar matrícula mientras el aspirante tenga sin cubrir su cargo de inscripción.',
                tipo: Ajuste::BOOLEANO,
                porDefecto: false,
            ),
            new Ajuste(
                clave: self::ASESOR_QUIEN_REGISTRA,
                grupo: 'Admisiones',
                etiqueta: 'El asesor se queda los prospectos que él registra',
                descripcion: 'Si quien captura al prospecto es un asesor activo, queda como su titular. '
                    .'Es lo natural cuando sale a ferias y trae sus propios contactos: ya habló con él.',
                tipo: Ajuste::BOOLEANO,
                porDefecto: false,
                consecuencia: 'No afecta a los que ya existen ni a los que capture alguien que no sea asesor.',
            ),
            new Ajuste(
                clave: self::ASIGNACION_ASESOR,
                grupo: 'Admisiones',
                etiqueta: 'Y a los demás prospectos nuevos…',
                descripcion: 'Qué pasa con los que NO se quedó quien los registró (el formulario público, '
                    .'recepción). «Por turno» los reparte entre los asesores ACTIVOS del campus del '
                    .'prospecto, cada vez al que menos tenga: parejo y sin detenerse.',
                tipo: Ajuste::SELECCION,
                porDefecto: 'manual',
                opciones: self::REPARTOS,
                consecuencia: 'Aplica a los que se registren de ahora en adelante: no reparte los que ya existen.',
            ),

            /*
             * ── La FRANJA en que se entregan los avisos ────────────────────
             *
             * No es cosmético. Un aviso sobre la situación de una persona
             * fechado a las 3 de la mañana se lee como si la escuela trabajara
             * de noche, y a un alumno le llega un recordatorio sobre su
             * asistencia a una hora en la que no puede hacer nada al respecto.
             * Es el defecto que ya se vio en cobranza con `publicado_desde` en
             * `startOfDay`.
             *
             * El comando está programado a las 07:45, así que en la operación
             * normal la franja no hace nada. Donde muerde es en lo que NO está
             * programado: una corrida manual de madrugada al configurar el
             * módulo, o un recálculo a mano. Ahí el aviso no se descarta —la
             * situación es cierta— sino que se publica al abrir la franja.
             */
            new Ajuste(
                clave: self::PERMANENCIA_AVISOS_DESDE,
                grupo: 'Permanencia',
                etiqueta: 'Hora a partir de la cual se entregan los avisos',
                descripcion: 'Los avisos de este módulo no se publican antes de esta hora. Lo que se '
                    .'levante fuera de la franja espera a que abra, en vez de llegar de madrugada.',
                tipo: Ajuste::ENTERO,
                porDefecto: 7,
                min: 0,
                max: 23,
                consecuencia: 'Sólo afecta a la HORA en que el aviso aparece, nunca a si se levanta: '
                    .'una situación que hoy es cierta se avisa hoy.',
            ),
            new Ajuste(
                clave: self::PERMANENCIA_AVISOS_HASTA,
                grupo: 'Permanencia',
                etiqueta: 'Hora a partir de la cual ya no se entregan',
                descripcion: 'Pasada esta hora, lo que se levante se publica a la mañana siguiente.',
                tipo: Ajuste::ENTERO,
                porDefecto: 21,
                min: 1,
                max: 23,
                consecuencia: 'Si queda por debajo de la hora de apertura, la franja se toma como '
                    .'abierta todo el día: una franja imposible dejaría de avisar para siempre, y eso '
                    .'no se descubre hasta que alguien pregunta por qué nadie se enteró.',
            ),
            new Ajuste(
                clave: self::PERMANENCIA_DIAS_SIN_ASIGNAR,
                grupo: 'Permanencia',
                etiqueta: 'Días que un caso puede estar sin responsable antes de avisar',
                descripcion: 'Un caso abierto que nadie ha tomado en este plazo se le avisa a quien '
                    .'asigna. Es lo único que impide que se quede esperando a que alguien lo mire.',
                tipo: Ajuste::ENTERO,
                porDefecto: 2,
                min: 1,
                max: 30,
                consecuencia: 'Se avisa UNA vez por caso, no todos los días: un recordatorio que llega '
                    .'cada mañana deja de leerse al tercero.',
            ),
            /*
             * ── Que el docente vea las señales de sus grupos ───────────────
             *
             * APAGADO por omisión, y es lo que el pedido pide: la información se
             * comparte «cuando la política institucional lo permita», y eso lo
             * decide la escuela. Encendido por omisión, una escuela recién
             * migrada le estaría enseñando a cada docente lo que el sistema
             * observó de sus alumnos sin que nadie lo hubiera decidido.
             *
             * Apagado, la ruta responde **404** y la entrada no sale en su
             * barra: mismo criterio que la postulación autogestiva de la bolsa.
             */
            new Ajuste(
                clave: self::PERMANENCIA_DOCENTE_VE_ALERTAS,
                grupo: 'Permanencia',
                etiqueta: 'El docente ve las señales de sus grupos',
                descripcion: 'Abre en «Mis materias» una vista con las señales de seguimiento de '
                    .'los alumnos a los que da clase. Sólo las categorías NO sensibles —nunca un '
                    .'adeudo ni una nota reservada— y sólo de sus propias materias.',
                tipo: Ajuste::BOOLEANO,
                porDefecto: false,
                consecuencia: 'Apagarlo esconde la vista de inmediato. El docente necesita además el '
                    .'permiso «Ver las señales de sus grupos», así que encenderlo aquí no se lo da a '
                    .'nadie por sí solo.',
            ),
            new Ajuste(
                clave: self::MAYORIA_DE_EDAD,
                grupo: 'Familia',
                etiqueta: 'Edad en que el alumno se considera mayor de edad',
                descripcion: 'A partir de esta edad el alumno hace sus trámites él mismo y su padre o tutor '
                    .'deja de poder hacerlos en su nombre.',
                tipo: Ajuste::ENTERO,
                /*
                 * 18 es la mayoría de edad en México, y por eso es el valor por
                 * omisión y no una constante: hay escuelas que operan con
                 * alumnado extranjero y programas donde la escuela decide tratar
                 * como menor a quien todavía no cumple 21.
                 */
                porDefecto: 18,
                min: 15,
                max: 21,
                consecuencia: 'Se mira contra la fecha de nacimiento del alumno cada vez, así que subirlo '
                    .'alcanza también a quien ya cumplió años y bajarlo se lo quita de golpe. '
                    .'Lo ya entregado se queda: es del expediente del alumno, no del tutor.',
            ),
            new Ajuste(
                clave: self::TUTOR_ENTREGA_DOCUMENTOS,
                grupo: 'Familia',
                etiqueta: 'El padre o tutor entrega los documentos de su hijo menor',
                descripcion: 'Deja que el tutor suba, descargue y retire desde el portal de la familia los '
                    .'papeles que la escuela le pide AL ALUMNO, mientras el alumno sea menor de edad.',
                tipo: Ajuste::BOOLEANO,
                /*
                 * Encendido por omisión, al revés que los interruptores que
                 * exponen datos de menores.
                 *
                 * Aquí no se le abre nada a un tercero: quien mira es el tutor
                 * legal del menor, que es exactamente quien responde por su
                 * expediente. Y el caso contrario —una secundaria donde el
                 * papeleo lo lleva el padre— es el normal, no la excepción: con
                 * esto apagado por omisión, la escuela que lo necesita descubre
                 * la sección sólo si adivina que existe.
                 */
                porDefecto: true,
                consecuencia: 'Apagado, la sección desaparece del portal de la familia y su dirección responde '
                    .'404. No se borra ni se oculta lo que ya se entregó.',
            ),

            new Ajuste(
                clave: self::ACTA_FORMATO_FOLIO,
                grupo: 'Actas',
                etiqueta: 'Formato del folio',
                descripcion: 'Tokens: {AAAA} {AA} {CAMPUS} {CICLO} y {#####}; el padding lo da la cantidad de #.',
                tipo: Ajuste::TEXTO,
                porDefecto: 'ACT-{AAAA}-{#####}',
                consecuencia: 'Los folios ya emitidos no se rehacen: un acta firmada conserva el suyo para siempre.',
            ),
            new Ajuste(
                clave: self::ACTA_AMBITO,
                grupo: 'Actas',
                etiqueta: 'Cada cuánto reinicia el consecutivo',
                descripcion: 'Ámbito del contador de folios.',
                tipo: Ajuste::SELECCION,
                porDefecto: 'anio',
                opciones: ['global' => 'Nunca', 'anio' => 'Cada año', 'campus' => 'Por campus', 'ciclo' => 'Por ciclo'],
            ),
        ];
    }

    public static function buscar(string $clave): ?Ajuste
    {
        foreach (self::todos() as $ajuste) {
            if ($ajuste->clave === $clave) {
                return $ajuste;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<int, Ajuste>>
     */
    public static function porGrupo(): array
    {
        $grupos = [];

        foreach (self::todos() as $ajuste) {
            $grupos[$ajuste->grupo][] = $ajuste;
        }

        return $grupos;
    }
}
