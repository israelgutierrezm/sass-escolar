<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Tenant\CatalogosAcademicosSeeder;
use Database\Seeders\Tenant\CatalogosAdmisionesSeeder;
use Database\Seeders\Tenant\CatalogosAsistenciaSeeder;
use Database\Seeders\Tenant\CatalogosControlEscolarSeeder;
use Database\Seeders\Tenant\CatalogosFormulariosSeeder;
use Database\Seeders\Tenant\ModuloSeeder;
use Database\Seeders\Tenant\ReglaMatriculaSeeder;
use Database\Seeders\Tenant\RolSeeder;
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
 *
 * El orden importa donde hay dependencias (los roles antes que cualquier
 * asignación); los catálogos entre sí son independientes.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ModuloSeeder::class,
            TemaSeeder::class,
            CatalogosAcademicosSeeder::class,
            CatalogosFormulariosSeeder::class,
            CatalogosAdmisionesSeeder::class,
            CatalogosControlEscolarSeeder::class,
            CatalogosAsistenciaSeeder::class,
            RolSeeder::class,
            PermisoSeeder::class,
            ReglaMatriculaSeeder::class,
        ]);
    }
}
