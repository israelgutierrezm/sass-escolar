<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Tenant\ModuloSeeder;
use Database\Seeders\Tenant\TemaSeeder;
use Illuminate\Database\Seeder;

/**
 * Seeder raíz de TENANT. Se ejecuta en el contexto de cada escuela cuando se
 * crea un tenant (job SeedDatabase del pipeline de stancl/tenancy) y con
 * `php artisan tenants:seed`.
 *
 * Solo debe sembrar datos TENANT/TENANT-CONFIG (catálogos que toda escuela
 * necesita). Los catálogos universales de la BD central los siembra
 * Database\Seeders\Landlord\LandlordDatabaseSeeder, por separado.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ModuloSeeder::class,
            TemaSeeder::class,
        ]);
    }
}
