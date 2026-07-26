<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Admisiones\Alumno;
use App\Models\Admisiones\Aspirante;
use App\Models\ControlEscolar\Docente;
use App\Models\Identidad\Persona;
use App\Services\AprovisionadorAcceso;
use Illuminate\Database\Seeder;

/**
 * Backfill del censo de cuentas: da rol + cuenta a los docentes, alumnos y
 * aspirantes que YA existían antes de que el alta lo hiciera solo.
 *
 * Se apoya en el mismo `AprovisionadorAcceso` que los observers, así que es
 * idempotente: correrlo dos veces no crea cuentas de más ni toca contraseñas.
 * Una persona que sea varias cosas (docente y alumno) queda con una sola cuenta
 * y todos sus roles.
 */
class AprovisionarAccesosSeeder extends Seeder
{
    public function run(): void
    {
        $aprovisionador = app(AprovisionadorAcceso::class);

        $poblaciones = [
            'docente' => Docente::query(),
            'alumno' => Alumno::query(),
            'aspirante' => Aspirante::query(),
        ];

        foreach ($poblaciones as $rolClave => $consulta) {
            $consulta
                ->select('persona_id')
                ->distinct()
                ->pluck('persona_id')
                ->chunk(200)
                ->each(function ($ids) use ($aprovisionador, $rolClave) {
                    foreach (Persona::query()->whereIn('id', $ids)->get() as $persona) {
                        $aprovisionador->paraPersona($persona, $rolClave);
                    }
                });
        }
    }
}
