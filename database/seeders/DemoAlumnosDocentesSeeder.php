<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\ControlEscolar\Docente;
use App\Models\Identidad\Persona;
use App\Services\MatriculadorOferta;
use Illuminate\Database\Seeder;

/**
 * Datos de ejemplo para el tenant demo: alumnos y docentes.
 *
 * - 5 alumnos por carrera (matriculados en una oferta abierta de esa carrera;
 *   si la carrera no tenía oferta, se le crea una mínima).
 * - 3 docentes por campus (ligados solo a ese campus).
 * - 3 docentes ligados a los 3 campus.
 *
 * Es idempotente por convención de correo/clave: correr de nuevo NO duplica.
 */
class DemoAlumnosDocentesSeeder extends Seeder
{
    /** @var string[] */
    private array $nombres = [
        'María', 'José', 'Guadalupe', 'Juan', 'Ana', 'Luis', 'Fernanda', 'Carlos',
        'Sofía', 'Miguel', 'Valeria', 'Diego', 'Regina', 'Emiliano', 'Ximena',
        'Santiago', 'Camila', 'Alejandro', 'Renata', 'Daniel', 'Paulina', 'Ricardo',
        'Andrea', 'Fernando', 'Mariana', 'Eduardo', 'Isabela', 'Rodrigo', 'Natalia', 'Gabriel',
    ];

    /** @var string[] */
    private array $apellidos = [
        'García', 'Martínez', 'López', 'Hernández', 'González', 'Pérez', 'Rodríguez',
        'Sánchez', 'Ramírez', 'Cruz', 'Flores', 'Gómez', 'Morales', 'Vázquez', 'Reyes',
        'Jiménez', 'Torres', 'Díaz', 'Ruiz', 'Mendoza', 'Aguilar', 'Ortiz', 'Castillo',
        'Romero', 'Álvarez', 'Mendez', 'Guerrero', 'Rojas', 'Contreras', 'Luna',
    ];

    public function run(): void
    {
        $matriculador = app(MatriculadorOferta::class);

        $creadosAlumnos = 0;
        $creadosDocentes = 0;

        // ---- Alumnos: 5 por carrera ----
        // Campus sugerido por carrera (solo para crear la oferta que falte).
        $campusPorCarrera = [1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 3, 6 => 3];

        foreach (Carrera::query()->orderBy('id')->get() as $carrera) {
            $oferta = $this->ofertaDe($carrera, $campusPorCarrera[$carrera->id] ?? 1);

            if ($oferta === null) {
                $this->command?->warn("Carrera {$carrera->nombre}: sin plan, se omite.");

                continue;
            }

            for ($i = 1; $i <= 5; $i++) {
                $email = "alumno.c{$carrera->id}.{$i}@demo.mx";

                if (Persona::query()->where('email', $email)->exists()) {
                    continue; // ya sembrado
                }

                $persona = Persona::create([
                    'nombre' => $this->nombre($carrera->id * 7 + $i),
                    'primer_apellido' => $this->apellido($carrera->id * 3 + $i),
                    'segundo_apellido' => $this->apellido($carrera->id + $i * 5),
                    'email' => $email,
                    'celular' => '55'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                ]);

                $matriculador->matricular($persona, $oferta, generacion: (string) now()->year);
                $creadosAlumnos++;
            }
        }

        // ---- Docentes ----
        // 3 por cada campus (ligados solo a ese campus).
        foreach ([1, 2, 3] as $campusId) {
            for ($i = 1; $i <= 3; $i++) {
                if ($this->crearDocente("DOC-C{$campusId}-{$i}", "docente.c{$campusId}.{$i}@demo.mx", [$campusId], $campusId * 4 + $i)) {
                    $creadosDocentes++;
                }
            }
        }

        // 3 ligados a los 3 campus.
        foreach ([1, 2, 3] as $i) {
            if ($this->crearDocente("DOC-MULTI-{$i}", "docente.multi.{$i}@demo.mx", [1, 2, 3], 20 + $i)) {
                $creadosDocentes++;
            }
        }

        $this->command?->info("Alumnos creados: {$creadosAlumnos}. Docentes creados: {$creadosDocentes}.");
    }

    /**
     * Oferta abierta de la carrera; si no hay, se crea una mínima (plan de la
     * carrera + campus sugerido, turno matutino, presencial).
     */
    private function ofertaDe(Carrera $carrera, int $campusId): ?Oferta
    {
        $existente = Oferta::query()
            ->where('carrera_id', $carrera->id)
            ->where('estatus', 'abierta')
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        $plan = PlanEstudio::query()->where('carrera_id', $carrera->id)->first();

        if ($plan === null) {
            return null;
        }

        return Oferta::create([
            'carrera_id' => $carrera->id,
            'plan_id' => $plan->id,
            'campus_id' => $campusId,
            'estatus' => 'abierta',
        ]);
    }

    /**
     * Crea un docente (persona + registro docente + campus). Devuelve false si
     * ya existía (por clave de profesor), para no duplicar al re-correr.
     *
     * @param  int[]  $campusIds
     */
    private function crearDocente(string $clave, string $email, array $campusIds, int $semilla): bool
    {
        if (Docente::query()->where('clave_profesor', $clave)->exists()) {
            return false;
        }

        $persona = Persona::query()->firstOrCreate(
            ['email' => $email],
            [
                'nombre' => $this->nombre($semilla),
                'primer_apellido' => $this->apellido($semilla + 2),
                'segundo_apellido' => $this->apellido($semilla + 9),
                'celular' => '55'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            ],
        );

        $docente = Docente::create([
            'persona_id' => $persona->id,
            'clave_profesor' => $clave,
            'tipo_docente_id' => 1,   // Titular
            'situacion_id' => 1,      // Activo
            'edicion_contenido' => 1,
        ]);

        $docente->campus()->sync($campusIds);

        return true;
    }

    private function nombre(int $semilla): string
    {
        return $this->nombres[$semilla % count($this->nombres)];
    }

    private function apellido(int $semilla): string
    {
        return $this->apellidos[$semilla % count($this->apellidos)];
    }
}
