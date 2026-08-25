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

    // Admisiones.
    public const EXIGE_DOCUMENTOS = 'aspirante.exige_documentos_para_convertir';

    public const EXIGE_PAGO = 'aspirante.exige_pago_para_convertir';

    public const ASESOR_QUIEN_REGISTRA = 'aspirante.asesor_se_lo_queda_quien_registra';

    public const ASIGNACION_ASESOR = 'aspirante.asignacion_de_asesor';

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
