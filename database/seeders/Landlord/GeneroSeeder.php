<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Models\Landlord\Genero;
use Illuminate\Database\Seeder;

/**
 * Catálogo de identidad de género (separado del sexo biológico). Idempotente
 * por clave. Cada escuela puede ajustarlo si lo requiere.
 */
class GeneroSeeder extends Seeder
{
    public function run(): void
    {
        $generos = [
            ['clave' => 'masculino', 'nombre' => 'Masculino'],
            ['clave' => 'femenino', 'nombre' => 'Femenino'],
            ['clave' => 'no_binario', 'nombre' => 'No binario'],
            ['clave' => 'otro', 'nombre' => 'Otro'],
            ['clave' => 'prefiere_no_decir', 'nombre' => 'Prefiere no decir'],
        ];

        foreach ($generos as $genero) {
            Genero::query()->updateOrCreate(
                ['clave' => $genero['clave']],
                ['nombre' => $genero['nombre']],
            );
        }
    }
}
