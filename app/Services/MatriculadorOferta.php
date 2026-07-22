<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Oferta;
use App\Models\Admisiones\Alumno;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\Identidad\Persona;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Matricula en una oferta a alguien que YA es alumno de la casa.
 *
 * `ConvertidorAspirante` cubre la entrada normal —aspirante que se convierte—,
 * pero no el caso de quien ya está dentro: la egresada que empieza la maestría,
 * el alumno que suma una segunda licenciatura. Obligarlos a darse de alta como
 * aspirantes para volver a entrar sería recapturar a alguien que la escuela ya
 * conoce.
 *
 * La matrícula se genera con el mismo `GeneradorMatricula` y su consecutivo
 * atómico: no hay dos formas de numerar alumnos.
 */
class MatriculadorOferta
{
    public function __construct(private readonly GeneradorMatricula $generador) {}

    /**
     * @throws RuntimeException si la persona no puede matricularse en esa oferta
     */
    public function matricular(Persona $persona, Oferta $oferta, ?string $generacion = null): MatriculaOferta
    {
        $impedimentos = $this->impedimentos($persona, $oferta);

        if ($impedimentos !== []) {
            throw new RuntimeException(implode(' ', $impedimentos));
        }

        $oferta->loadMissing(['carrera', 'plan', 'campus']);

        return DB::transaction(function () use ($persona, $oferta, $generacion) {
            $situacionActivo = SituacionAlumno::query()->where('clave', 'activo')->value('id');

            // El rol materializado; si ya lo tenía por su otra carrera, se
            // respeta, porque es de la persona y no de cada matrícula.
            Alumno::query()->firstOrCreate(
                ['persona_id' => $persona->id],
                ['situacion_id' => $situacionActivo],
            );

            return MatriculaOferta::create([
                'persona_id' => $persona->id,
                'oferta_id' => $oferta->id,
                'matricula' => $this->generador->generar($oferta),
                'generacion' => $generacion,
                'fecha_ingreso' => now()->toDateString(),
                'situacion_id' => $situacionActivo,
                'estatus' => 'activo',
            ]);
        });
    }

    /**
     * @return array<int, string>
     */
    public function impedimentos(Persona $persona, Oferta $oferta): array
    {
        $impedimentos = [];

        // El índice único (persona_id, oferta_id) lo impide de todos modos;
        // aquí se explica en vez de reventar con un error de base de datos.
        $yaMatriculada = MatriculaOferta::query()
            ->where('persona_id', $persona->id)
            ->where('oferta_id', $oferta->id)
            ->exists();

        if ($yaMatriculada) {
            $impedimentos[] = 'Esta persona ya tiene matrícula en esa oferta.';
        }

        if (SituacionAlumno::query()->where('clave', 'activo')->doesntExist()) {
            $impedimentos[] = 'Falta el catálogo de situaciones de alumno.';
        }

        return $impedimentos;
    }

    /**
     * Da de baja una matrícula sin tocar las demás de esa persona.
     *
     * NO se borra: su kárdex es historia escolar y las actas donde aparece
     * quedarían sin dueño. Una baja es un cambio de estado, no una desaparición.
     *
     * Se pide CUÁL baja porque son dos ejes distintos y el catálogo lo modela:
     * `estatus` es la columna gruesa (activo/egresado/baja) y `situacion_id`
     * dice si fue temporal o definitiva. Bajar sin elegir perdería justo el
     * dato que después se necesita para saber si esa persona puede volver.
     */
    public function darDeBaja(MatriculaOferta $matricula, ?int $situacionId = null): void
    {
        $matricula->update([
            'estatus' => 'baja',
            'situacion_id' => $situacionId ?? $this->primeraSituacionDeBaja() ?? $matricula->situacion_id,
        ]);
    }

    /**
     * Situaciones que representan una baja, para que la interfaz las ofrezca.
     * Se detectan por prefijo de clave y no por una lista fija: cada escuela
     * puede tener las suyas.
     *
     * @return \Illuminate\Support\Collection<int, SituacionAlumno>
     */
    public function situacionesDeBaja(): \Illuminate\Support\Collection
    {
        return SituacionAlumno::query()
            ->where('clave', 'like', 'baja%')
            ->orderBy('id')
            ->get();
    }

    private function primeraSituacionDeBaja(): ?int
    {
        return $this->situacionesDeBaja()->first()?->id;
    }

    /** Reactiva una matrícula dada de baja. */
    public function reactivar(MatriculaOferta $matricula): void
    {
        $situacionActivo = SituacionAlumno::query()->where('clave', 'activo')->value('id');

        $matricula->update([
            'estatus' => 'activo',
            'situacion_id' => $situacionActivo ?? $matricula->situacion_id,
        ]);
    }
}
