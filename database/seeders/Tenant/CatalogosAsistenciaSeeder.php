<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Asistencia\TipoDispositivoChecador;
use Illuminate\Database\Seeder;

/**
 * Catálogo TENANT-CONFIG del módulo de asistencia. Idempotente por clave.
 */
class CatalogosAsistenciaSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            ['clave' => 'qr', 'nombre' => 'Lector QR'],
            ['clave' => 'biometrico', 'nombre' => 'Biométrico'],
            ['clave' => 'geocerca', 'nombre' => 'Geocerca (app móvil)'],
            ['clave' => 'manual', 'nombre' => 'Captura manual'],
        ];

        foreach ($tipos as $tipo) {
            TipoDispositivoChecador::query()->updateOrCreate(
                ['clave' => $tipo['clave']],
                ['nombre' => $tipo['nombre']],
            );
        }
    }
}
