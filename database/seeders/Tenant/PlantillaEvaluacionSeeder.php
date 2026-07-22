<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Academico\PlantillaComponente;
use App\Models\Academico\PlantillaEvaluacion;
use Illuminate\Database\Seeder;

/**
 * Plantillas de evaluación de arranque.
 *
 * No son un catálogo cerrado: la escuela las edita, las borra o crea las suyas.
 * Se siembran tres porque son los criterios que aparecen una y otra vez, y
 * porque una pantalla vacía no enseña que los rubros pueden colgar de un
 * parcial o ir directo al curso.
 *
 * Idempotente por clave: si la escuela ya las ajustó, no se le pisan.
 */
class PlantillaEvaluacionSeeder extends Seeder
{
    /** @var array<string, array{nombre: string, descripcion: string, rubros: array<int, array{componente: string, parcial: int|null, porcentaje: float}>}> */
    private const PLANTILLAS = [
        'tres_parciales' => [
            'nombre' => 'Tres parciales con asistencia',
            'descripcion' => 'Dos parciales de 25% (asistencia + examen) y un cierre de 50% con examen final y participación.',
            'rubros' => [
                ['componente' => 'asistencia_p1', 'parcial' => 1, 'porcentaje' => 10],
                ['componente' => 'examen_p1', 'parcial' => 1, 'porcentaje' => 15],
                ['componente' => 'asistencia_p2', 'parcial' => 2, 'porcentaje' => 10],
                ['componente' => 'examen_p2', 'parcial' => 2, 'porcentaje' => 15],
                ['componente' => 'examen_final', 'parcial' => 3, 'porcentaje' => 30],
                ['componente' => 'participacion', 'parcial' => 3, 'porcentaje' => 20],
            ],
        ],
        'dos_parciales' => [
            'nombre' => 'Dos parciales',
            'descripcion' => 'Dos cortes de 50%, cada uno con asistencia y examen.',
            'rubros' => [
                ['componente' => 'asistencia_p1', 'parcial' => 1, 'porcentaje' => 20],
                ['componente' => 'examen_p1', 'parcial' => 1, 'porcentaje' => 30],
                ['componente' => 'asistencia_p2', 'parcial' => 2, 'porcentaje' => 20],
                ['componente' => 'examen_p2', 'parcial' => 2, 'porcentaje' => 30],
            ],
        ],
        'directo_curso' => [
            'nombre' => 'Directo al curso, sin parciales',
            'descripcion' => 'Sin cortes: los rubros se evalúan sobre el curso completo.',
            'rubros' => [
                ['componente' => 'asistencia', 'parcial' => null, 'porcentaje' => 10],
                ['componente' => 'examen_final', 'parcial' => null, 'porcentaje' => 50],
                ['componente' => 'actividades', 'parcial' => null, 'porcentaje' => 40],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANTILLAS as $clave => $datos) {
            $plantilla = PlantillaEvaluacion::query()->firstOrCreate(
                ['clave' => $clave],
                [
                    'nombre' => $datos['nombre'],
                    'descripcion' => $datos['descripcion'],
                    'activa' => true,
                ],
            );

            // Solo se llenan los rubros si la plantilla acaba de nacer: si la
            // escuela ya movió los porcentajes, volver a sembrar los borraría.
            if ($plantilla->componentes()->exists()) {
                continue;
            }

            foreach (array_values($datos['rubros']) as $orden => $rubro) {
                PlantillaComponente::create([
                    'plantilla_id' => $plantilla->id,
                    'componente' => $rubro['componente'],
                    'parcial' => $rubro['parcial'],
                    'porcentaje' => $rubro['porcentaje'],
                    'orden' => $orden + 1,
                ]);
            }
        }
    }
}
