<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Models\Landlord\NivelEstudio;
use Illuminate\Database\Seeder;

/**
 * Niveles de estudio estandarizados por la SEP. `orden` define la progresión
 * académica. Idempotente por clave.
 */
class NivelEstudioSeeder extends Seeder
{
    public function run(): void
    {
        $niveles = [
            ['clave' => 'bachillerato', 'nombre' => 'Bachillerato', 'orden' => 1],
            ['clave' => 'tecnico_superior', 'nombre' => 'Técnico Superior Universitario', 'orden' => 2],
            ['clave' => 'licenciatura', 'nombre' => 'Licenciatura', 'orden' => 3],
            ['clave' => 'especialidad', 'nombre' => 'Especialidad', 'orden' => 4],
            ['clave' => 'maestria', 'nombre' => 'Maestría', 'orden' => 5],
            ['clave' => 'doctorado', 'nombre' => 'Doctorado', 'orden' => 6],
            ['clave' => 'diplomado', 'nombre' => 'Diplomado', 'orden' => 7],
        ];

        foreach ($niveles as $nivel) {
            NivelEstudio::query()->updateOrCreate(
                ['clave' => $nivel['clave']],
                ['nombre' => $nivel['nombre'], 'orden' => $nivel['orden']],
            );
        }
    }
}
