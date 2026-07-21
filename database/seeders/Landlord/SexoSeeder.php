<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Models\Landlord\Sexo;
use Illuminate\Database\Seeder;

/**
 * Catálogo de sexo biológico/oficial para documentos SEP. Idempotente por clave.
 */
class SexoSeeder extends Seeder
{
    public function run(): void
    {
        $sexos = [
            ['clave' => 'H', 'nombre' => 'Hombre'],
            ['clave' => 'M', 'nombre' => 'Mujer'],
        ];

        foreach ($sexos as $sexo) {
            Sexo::query()->updateOrCreate(
                ['clave' => $sexo['clave']],
                ['nombre' => $sexo['nombre']],
            );
        }
    }
}
