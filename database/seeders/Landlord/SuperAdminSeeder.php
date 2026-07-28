<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Models\Landlord\SuperAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Super admin de la CASA (landlord): con quien se entra al panel central para
 * administrar las escuelas. Vive en la BD central, no en ninguna escuela.
 *
 * Idempotente por email. La contraseña por defecto es de DEMO —cámbiala en un
 * despliegue real—.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        SuperAdmin::query()->updateOrCreate(
            ['email' => 'admin@acadion.mx'],
            [
                'nombre' => 'Administrador de la casa',
                'password' => Hash::make('password'),
                'rol' => 'superadmin',
            ],
        );
    }
}
