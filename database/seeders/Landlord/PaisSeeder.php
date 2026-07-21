<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Models\Landlord\Pais;
use Illuminate\Database\Seeder;

/**
 * Catálogo de países (LANDLORD). Se siembra México (contexto principal) y un
 * ejemplo de extranjero. Idempotente por `clave_iso`.
 */
class PaisSeeder extends Seeder
{
    public function run(): void
    {
        $paises = [
            ['clave_iso' => 'MEX', 'nombre' => 'México'],
            ['clave_iso' => 'USA', 'nombre' => 'Estados Unidos de América'],
        ];

        foreach ($paises as $pais) {
            Pais::query()->updateOrCreate(
                ['clave_iso' => $pais['clave_iso']],
                ['nombre' => $pais['nombre']],
            );
        }
    }
}
