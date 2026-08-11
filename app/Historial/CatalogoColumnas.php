<?php

declare(strict_types=1);

namespace App\Historial;

/**
 * Qué puede llevar un historial académico impreso: columnas de la tabla y datos
 * del alumno en el encabezado.
 *
 * ── Por qué es un catálogo ────────────────────────────────────────────────
 * Porque entre los historiales reales que sirvieron de referencia —una
 * universidad boliviana, la UNAM, un bachillerato estatal y una universidad
 * peruana— la maqueta cambia poco y las COLUMNAS cambian mucho: unos imprimen
 * créditos, otros la calificación escrita con letra, otros el folio del acta,
 * el grupo o el tipo de evaluación. Enumerarlas aquí, con la manera de
 * resolver cada una, es lo que permite que cada escuela arme el suyo sin que
 * nadie toque código.
 */
class CatalogoColumnas
{
    /**
     * Las columnas de la tabla de materias.
     *
     * `ancho` es una sugerencia en porcentaje para la impresión, y `alineacion`
     * dice cómo se lee: los números a la derecha, los textos a la izquierda.
     *
     * @return array<string, array{etiqueta: string, ayuda: string, ancho: int, alineacion: string}>
     */
    public static function columnas(): array
    {
        return [
            'consecutivo' => [
                'etiqueta' => 'No.',
                'ayuda' => 'Numera los renglones. Útil cuando el documento se cita por número de fila.',
                'ancho' => 4,
                'alineacion' => 'derecha',
            ],
            'clave' => [
                'etiqueta' => 'Clave',
                'ayuda' => 'La clave de la materia en el plan de estudios.',
                'ancho' => 10,
                'alineacion' => 'izquierda',
            ],
            'materia' => [
                'etiqueta' => 'Asignatura',
                'ayuda' => 'El nombre de la materia. Es la única que conviene no quitar.',
                'ancho' => 38,
                'alineacion' => 'izquierda',
            ],
            'calificacion' => [
                'etiqueta' => 'Calificación',
                'ayuda' => 'El número, con los decimales que fije la escala del plan.',
                'ancho' => 9,
                'alineacion' => 'centro',
            ],
            'calificacion_letra' => [
                'etiqueta' => 'Con letra',
                'ayuda' => 'La calificación escrita: «OCHO». Se usa para que no se pueda alterar el número a mano.',
                'ancho' => 10,
                'alineacion' => 'izquierda',
            ],
            'creditos' => [
                'etiqueta' => 'Créditos',
                'ayuda' => 'Los que otorga la materia. Un bachillerato normalmente no los imprime.',
                'ancho' => 7,
                'alineacion' => 'centro',
            ],
            'periodo' => [
                'etiqueta' => 'Periodo',
                'ayuda' => 'El semestre o cuatrimestre del plan al que pertenece la materia.',
                'ancho' => 7,
                'alineacion' => 'centro',
            ],
            'ciclo' => [
                'etiqueta' => 'Ciclo',
                'ayuda' => 'El ciclo escolar en que se cursó: «2024-A».',
                'ancho' => 8,
                'alineacion' => 'centro',
            ],
            'estatus' => [
                'etiqueta' => 'Estatus',
                'ayuda' => 'Aprobada, reprobada, en curso.',
                'ancho' => 10,
                'alineacion' => 'izquierda',
            ],
            'tipo_evaluacion' => [
                'etiqueta' => 'Tipo',
                'ayuda' => 'Ordinario, extraordinario, a título…',
                'ancho' => 10,
                'alineacion' => 'izquierda',
            ],
            'acta_folio' => [
                'etiqueta' => 'Acta',
                'ayuda' => 'El folio del acta que la asentó. Es lo que hace rastreable cada calificación.',
                'ancho' => 10,
                'alineacion' => 'centro',
            ],
            'observacion' => [
                'etiqueta' => 'Observación',
                'ayuda' => 'La observación oficial: equivalencia, revalidación… Se calla cuando es ordinaria.',
                'ancho' => 12,
                'alineacion' => 'izquierda',
            ],
        ];
    }

    /**
     * Los datos del alumno que pueden ir en el encabezado.
     *
     * @return array<string, array{etiqueta: string, ayuda: string}>
     */
    public static function datosDelAlumno(): array
    {
        return [
            'nombre' => ['etiqueta' => 'Nombre', 'ayuda' => 'Nombre y apellidos, como estén capturados.'],
            'matricula' => ['etiqueta' => 'Matrícula', 'ayuda' => 'El número de control de esta inscripción.'],
            'curp' => ['etiqueta' => 'CURP', 'ayuda' => 'Dato personal. Va en los historiales oficiales; piénsalo si el alumno se lo va a descargar.'],
            'carrera' => ['etiqueta' => 'Carrera', 'ayuda' => 'El programa de esta matrícula.'],
            'plan' => ['etiqueta' => 'Plan de estudios', 'ayuda' => 'Cuál plan cursa. Distingue a quien entró con otro mapa curricular.'],
            'campus' => ['etiqueta' => 'Campus', 'ayuda' => 'El plantel de esta inscripción.'],
            'nivel' => ['etiqueta' => 'Nivel de estudios', 'ayuda' => 'Bachillerato, licenciatura, posgrado…'],
            'situacion' => ['etiqueta' => 'Situación', 'ayuda' => 'Si está activa, baja o egresada.'],
            'fecha_emision' => ['etiqueta' => 'Fecha de emisión', 'ayuda' => 'El día en que se imprime. Un historial sin fecha no dice a qué momento corresponde.'],
        ];
    }

    /** Cómo se agrupan las materias. */
    public const AGRUPACIONES = [
        'periodo' => [
            'etiqueta' => 'Por periodo del plan',
            'ayuda' => 'Semestre 1, 2, 3… Se lee el avance del mapa curricular, y una materia recursada cae junto a sus compañeras.',
        ],
        'ciclo' => [
            'etiqueta' => 'Por ciclo escolar',
            'ayuda' => '2024-A, 2024-B… Se lee la historia real de la persona, en el orden en que ocurrió.',
        ],
        'ninguna' => [
            'etiqueta' => 'Sin agrupar',
            'ayuda' => 'Una sola lista corrida. Es lo más compacto si el historial es largo.',
        ],
    ];

    public const PAPELES = ['carta', 'oficio', 'a4'];

    public const ORIENTACIONES = ['vertical', 'horizontal'];

    /** Lo que trae un diseño recién creado: lo que casi todas las escuelas usan. */
    public static function porOmision(): array
    {
        return [
            'campos_alumno' => ['nombre', 'matricula', 'carrera', 'plan', 'fecha_emision'],
            'columnas' => ['consecutivo', 'clave', 'materia', 'calificacion', 'creditos', 'ciclo', 'observacion'],
        ];
    }

    public static function existeColumna(string $clave): bool
    {
        return array_key_exists($clave, self::columnas());
    }

    public static function existeDato(string $clave): bool
    {
        return array_key_exists($clave, self::datosDelAlumno());
    }
}
