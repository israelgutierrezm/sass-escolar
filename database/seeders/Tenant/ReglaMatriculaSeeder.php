<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Admisiones\ReglaMatricula;
use Illuminate\Database\Seeder;

/**
 * Regla de matrícula por defecto (TENANT-CONFIG): año a 4 dígitos + consecutivo
 * de 4 posiciones que se reinicia cada año — p.ej. 2026-0001.
 *
 * Es solo un punto de partida razonable: cada escuela ajusta su plantilla y el
 * ámbito del consecutivo desde la administración, y puede agregar reglas más
 * específicas por carrera o por plan.
 */
class ReglaMatriculaSeeder extends Seeder
{
    public function run(): void
    {
        ReglaMatricula::query()->updateOrCreate(
            ['ambito' => 'global', 'ambito_id' => null],
            [
                'plantilla' => '{AAAA}-{####}',
                'ambito_consecutivo' => 'anio',
                'activo' => true,
            ],
        );
    }
}
