<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Oferta;
use App\Models\Admisiones\Alumno;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\MovimientoEscolar;
use App\Models\Identidad\Persona;
use Illuminate\Support\Collection;
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
    public function __construct(
        private readonly GeneradorMatricula $generador,
        private readonly ReligadorFinanzas $religador,
        private readonly RegistradorMovimientos $movimientos,
    ) {}

    /**
     * @throws RuntimeException si la persona no puede matricularse en esa oferta
     */
    public function matricular(Persona $persona, Oferta $oferta, ?string $generacion = null, ?string $matricula = null): MatriculaOferta
    {
        $impedimentos = $this->impedimentos($persona, $oferta);

        // Alta directa (revalidación): puede traer su boleta/matrícula. Si no,
        // se autogenera. La capturada debe ser única en la escuela.
        if ($matricula !== null && MatriculaOferta::query()->where('matricula', $matricula)->exists()) {
            $impedimentos[] = "La boleta/matrícula «{$matricula}» ya está en uso.";
        }

        if ($impedimentos !== []) {
            throw new RuntimeException(implode(' ', $impedimentos));
        }

        $oferta->loadMissing(['programaAcademico', 'plan', 'campus']);

        return DB::transaction(function () use ($persona, $oferta, $generacion, $matricula) {
            $situacionActivo = SituacionAlumno::query()->where('clave', 'activo')->value('id');

            // El rol materializado; si ya lo tenía por su otro programa académico, se
            // respeta, porque es de la persona y no de cada matrícula.
            Alumno::query()->firstOrCreate(
                ['persona_id' => $persona->id],
                ['situacion_id' => $situacionActivo],
            );

            $matriculaCreada = MatriculaOferta::create([
                'persona_id' => $persona->id,
                'oferta_id' => $oferta->id,
                'matricula' => $matricula ?? $this->generador->generar($oferta),
                'generacion' => $generacion,
                'fecha_ingreso' => now()->toDateString(),
                'situacion_id' => $situacionActivo,
                'estatus' => 'activo',
            ]);

            // Si esta persona pasó por el embudo de admisión de ESTA oferta,
            // lo que pagó entonces es de esta matrícula. Se acota a la oferta:
            // los pagos de otra candidatura suya no le corresponden.
            $this->religador->religarPorOferta($persona->id, $matriculaCreada);

            /*
             * Y el primer movimiento de su trayectoria. Va DENTRO de la misma
             * transacción: una matrícula sin su alta sería una trayectoria que
             * empieza en el aire, y la referencia impide que un reintento deje
             * dos.
             */
            $this->movimientos->alta(
                $matriculaCreada,
                MovimientoEscolar::ORIGEN_MATRICULACION,
                'matriculacion:'.$matriculaCreada->id,
            );

            return $matriculaCreada;
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
     * NO se borra: su historial académico es historia escolar y las actas donde aparece
     * quedarían sin dueño. Una baja es un cambio de estado, no una desaparición.
     *
     * Se pide CUÁL baja porque son dos ejes distintos y el catálogo lo modela:
     * `estatus` es la columna gruesa (activo/egresado/baja) y `situacion_id`
     * dice si fue temporal o definitiva. Bajar sin elegir perdería justo el
     * dato que después se necesita para saber si esa persona puede volver.
     */
    public function darDeBaja(MatriculaOferta $matricula, ?int $situacionId = null): void
    {
        // La anterior se lee ANTES de pisarla: es la mitad del movimiento que
        // el modelo operativo no conserva.
        $anterior = $matricula->situacion_id;
        $nueva = $situacionId ?? $this->primeraSituacionDeBaja() ?? $matricula->situacion_id;

        /*
         * Bajar a quien YA está de baja en la misma situación no es un hecho:
         * es el segundo clic del botón. Antes daba igual —se reescribían dos
         * columnas con lo que ya tenían— y desde que esto deja movimiento sí
         * importa: la trayectoria acabaría con dos bajas idénticas, y la
         * historia escolar no se corrige quitando renglones.
         */
        if ($matricula->estatus === 'baja' && $matricula->situacion_id === $nueva) {
            return;
        }

        DB::transaction(function () use ($matricula, $anterior, $nueva) {
            $matricula->update(['estatus' => 'baja', 'situacion_id' => $nueva]);

            $clave = SituacionAlumno::query()->whereKey($nueva)->value('clave') ?? '';

            /*
             * SIN referencia, a propósito.
             *
             * La referencia existe para que un proceso repetido no duplique, y
             * sólo sirve cuando identifica un HECHO —`conversion:412`—. Aquí
             * iba `baja:{id}:{timestamp}`, que no es ninguna de las dos cosas:
             * un reintento un segundo después la esquiva, y dos bajas legítimas
             * del mismo segundo —de temporal a definitiva— se fundían en una,
             * o sea que la segunda no quedaba registrada. Lo destapó la suite.
             *
             * Lo que impide el doble clic es el guard de arriba, que mira el
             * estado real en vez de la hora.
             */
            $this->movimientos->baja($matricula, $anterior, $nueva, $clave);
        });
    }

    /**
     * Situaciones que representan una baja, para que la interfaz las ofrezca.
     * Se detectan por prefijo de clave y no por una lista fija: cada escuela
     * puede tener las suyas.
     *
     * @return Collection<int, SituacionAlumno>
     */
    public function situacionesDeBaja(): Collection
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

    /**
     * Reactiva una matrícula dada de baja.
     *
     * A la que ya está activa no se le hace nada, por lo mismo que a la baja
     * repetida: reactivar dos veces es un doble clic, no dos reingresos.
     */
    public function reactivar(MatriculaOferta $matricula): void
    {
        if ($matricula->estatus === 'activo') {
            return;
        }

        $anterior = $matricula->situacion_id;
        $situacionActivo = SituacionAlumno::query()->where('clave', 'activo')->value('id');
        $nueva = $situacionActivo ?? $matricula->situacion_id;

        DB::transaction(function () use ($matricula, $anterior, $nueva) {
            $matricula->update(['estatus' => 'activo', 'situacion_id' => $nueva]);

            // Sin referencia, por lo mismo que la baja.
            $this->movimientos->reingreso($matricula, $anterior, $nueva);
        });
    }
}
