<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Plataforma\Efemeride;
use Illuminate\Database\Seeder;

/**
 * Las efemérides que trae el sistema de fábrica.
 *
 * ── Criterio: pocas y ciertas ──────────────────────────────────────────────
 * Esto se lee en la pantalla de cada alumno, así que sólo entran fechas de las
 * que no hay duda —conmemoraciones cívicas mexicanas y días internacionales de
 * la ONU/UNESCO reconocidos—. Una lista larga con dos fechas mal puestas hace
 * más daño que una lista corta: nadie vuelve a creerle.
 *
 * Lo demás lo agrega cada escuela: el aniversario del plantel, la semana
 * cultural, la fiesta del santo patrono. Por eso la tabla vive en el tenant y
 * es editable.
 *
 * Se siembra con `updateOrCreate` sobre mes+día+título: correr el seeder dos
 * veces no duplica, y quien haya editado el texto de una no lo pierde… salvo
 * que sea la misma fecha y el mismo título, en cuyo caso se refresca.
 */
class EfemerideSeeder extends Seeder
{
    /**
     * mes, día, título, tipo, año del hecho, descripción.
     *
     * @var array<int, array{0: int, 1: int, 2: string, 3: string, 4: ?int, 5: ?string}>
     */
    private const EFEMERIDES = [
        // ── Cívicas mexicanas ──
        [2, 5, 'Promulgación de la Constitución', Efemeride::CIVICA, 1917, 'Se promulgó en Querétaro la Constitución Política de los Estados Unidos Mexicanos.'],
        [2, 24, 'Día de la Bandera', Efemeride::CIVICA, 1821, 'Conmemora el Plan de Iguala y la primera bandera trigarante.'],
        [3, 21, 'Natalicio de Benito Juárez', Efemeride::CIVICA, 1806, 'Nació en San Pablo Guelatao, Oaxaca.'],
        [4, 30, 'Día del Niño', Efemeride::CIVICA, null, null],
        [5, 1, 'Día del Trabajo', Efemeride::CIVICA, null, null],
        [5, 5, 'Batalla de Puebla', Efemeride::CIVICA, 1862, 'El ejército mexicano venció a las tropas francesas en Puebla.'],
        [5, 10, 'Día de las Madres', Efemeride::CIVICA, null, null],
        [5, 15, 'Día del Maestro', Efemeride::CIVICA, null, 'Se celebra en México desde 1918.'],
        [9, 15, 'Grito de Independencia', Efemeride::CIVICA, 1810, 'Noche del Grito; se conmemora el inicio de la lucha de Independencia.'],
        [9, 16, 'Independencia de México', Efemeride::CIVICA, 1810, 'Miguel Hidalgo inició la lucha de Independencia en Dolores.'],
        [11, 2, 'Día de Muertos', Efemeride::CIVICA, null, 'Patrimonio Cultural Inmaterial de la Humanidad (UNESCO).'],
        [11, 20, 'Revolución Mexicana', Efemeride::CIVICA, 1910, 'Inicio de la Revolución convocada por Francisco I. Madero.'],
        [12, 12, 'Día de la Virgen de Guadalupe', Efemeride::CIVICA, null, null],

        // ── Internacionales relevantes para una escuela ──
        [1, 27, 'Día de Conmemoración del Holocausto', Efemeride::INTERNACIONAL, null, 'Designado por la ONU en memoria de las víctimas.'],
        [2, 11, 'Día de la Mujer y la Niña en la Ciencia', Efemeride::INTERNACIONAL, null, 'Proclamado por la ONU.'],
        [2, 21, 'Día de la Lengua Materna', Efemeride::INTERNACIONAL, null, 'Proclamado por la UNESCO.'],
        [3, 8, 'Día Internacional de la Mujer', Efemeride::INTERNACIONAL, null, null],
        [3, 22, 'Día Mundial del Agua', Efemeride::INTERNACIONAL, null, null],
        [4, 2, 'Día de Concienciación sobre el Autismo', Efemeride::INTERNACIONAL, null, null],
        [4, 22, 'Día de la Tierra', Efemeride::INTERNACIONAL, null, null],
        [4, 23, 'Día Mundial del Libro', Efemeride::INTERNACIONAL, null, 'Y del Derecho de Autor. Proclamado por la UNESCO.'],
        [6, 5, 'Día Mundial del Medio Ambiente', Efemeride::INTERNACIONAL, null, null],
        [9, 8, 'Día Internacional de la Alfabetización', Efemeride::INTERNACIONAL, null, 'Proclamado por la UNESCO.'],
        [10, 5, 'Día Mundial de los Docentes', Efemeride::INTERNACIONAL, null, 'Proclamado por la UNESCO.'],
        [10, 24, 'Día de las Naciones Unidas', Efemeride::INTERNACIONAL, null, null],
        [11, 16, 'Día Internacional para la Tolerancia', Efemeride::INTERNACIONAL, null, 'Proclamado por la UNESCO.'],
        [12, 3, 'Día de las Personas con Discapacidad', Efemeride::INTERNACIONAL, null, null],
        [12, 10, 'Día de los Derechos Humanos', Efemeride::INTERNACIONAL, null, 'Aniversario de la Declaración Universal de 1948.'],
    ];

    public function run(): void
    {
        foreach (self::EFEMERIDES as [$mes, $dia, $titulo, $tipo, $anio, $descripcion]) {
            Efemeride::updateOrCreate(
                ['mes' => $mes, 'dia' => $dia, 'titulo' => $titulo],
                [
                    'tipo' => $tipo,
                    'anio_origen' => $anio,
                    'descripcion' => $descripcion,
                    'activa' => true,
                ],
            );
        }
    }
}
