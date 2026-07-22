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
    public const MATRICULA_UNICA = 'alumno.matricula_unica_por_persona';

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

    // Admisiones.
    public const EXIGE_DOCUMENTOS = 'aspirante.exige_documentos_para_convertir';

    public const EXIGE_PAGO = 'aspirante.exige_pago_para_convertir';

    // Acta (ya existían sueltos; se traen al catálogo).
    public const ACTA_FORMATO_FOLIO = 'acta.formato_folio';

    public const ACTA_AMBITO = 'acta.ambito_consecutivo';

    private const ACCIONES = ['advertir' => 'Solo advertir', 'bloquear' => 'Bloquear'];

    /**
     * @return array<int, Ajuste>
     */
    public static function todos(): array
    {
        return [
            new Ajuste(
                clave: self::MATRICULA_UNICA,
                grupo: 'Alumnos',
                etiqueta: 'Una sola matrícula por persona',
                descripcion: 'Si quien cursa dos programas conserva el mismo número de matrícula en ambos, en vez de recibir uno por programa.',
                tipo: Ajuste::BOOLEANO,
                porDefecto: false,
                consecuencia: 'No renumera a nadie: aplica a las matrículas que se generen a partir de ahora.',
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
