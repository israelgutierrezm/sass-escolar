<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use Illuminate\Database\Seeder;

/**
 * Orquesta la siembra de los catálogos universales de la BD central (LANDLORD).
 *
 * Se ejecuta explícitamente contra la conexión central:
 *   php artisan db:seed --class="Database\Seeders\Landlord\LandlordDatabaseSeeder"
 *
 * NO se llama desde DatabaseSeeder (el seeder raíz de tenant) para no contaminar
 * las bases de datos de las escuelas con datos landlord.
 */
class LandlordDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PaisSeeder::class,
            EntidadFederativaSeeder::class,
            SexoSeeder::class,
            GeneroSeeder::class,
            NivelEstudioSeeder::class,
        ]);
    }
}
