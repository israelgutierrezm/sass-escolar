<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\Alumno;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\RespuestaCampo;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\Admisiones\SituacionAspirante;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Convierte un aspirante en alumno.
 *
 * Es el último paso del embudo de admisión y donde —por fin— se genera la
 * matrícula: antes de esto el aspirante NO tiene una. Todo ocurre en una sola
 * transacción, porque a medio camino quedaría un alumno sin matrícula o una
 * matrícula consumida sin alumno.
 *
 * Cero recaptura: se conserva la MISMA persona, y las respuestas de formulario
 * que dio siendo aspirante se RE-LIGAN a su nueva matricula_oferta, para que no
 * tenga que volver a capturarlas.
 *
 * Lo mismo vale para el dinero: sus adeudos y pagos de aspirante —la ficha, la
 * inscripción— pasan a la matrícula nueva en esta misma transacción. Dejarlos
 * colgando del aspirante partiría el estado de cuenta del alumno en dos.
 */
class ConvertidorAspirante
{
    public function __construct(
        private readonly GeneradorMatricula $generador,
        private readonly ReligadorFinanzas $religador,
    ) {}

    /**
     * @throws RuntimeException si al aspirante le falta algo para convertirse
     */
    public function convertir(Aspirante $aspirante, ?string $generacion = null): MatriculaOferta
    {
        $aspirante->loadMissing(['persona', 'ofertaInteres']);

        $this->validar($aspirante);

        return DB::transaction(function () use ($aspirante, $generacion) {
            $situacionActivo = SituacionAlumno::query()->where('clave', 'activo')->value('id');

            // El rol materializado de alumno: atributos propios, sin duplicar persona.
            Alumno::query()->firstOrCreate(
                ['persona_id' => $aspirante->persona_id],
                ['situacion_id' => $situacionActivo],
            );

            $matricula = MatriculaOferta::create([
                'persona_id' => $aspirante->persona_id,
                'oferta_id' => $aspirante->oferta_interes_id,
                'matricula' => $this->generador->generar($aspirante->ofertaInteres),
                'generacion' => $generacion,
                'fecha_ingreso' => now()->toDateString(),
                'situacion_id' => $situacionActivo,
                'estatus' => 'activo',
            ]);

            $this->religarRespuestas($aspirante, $matricula);
            $this->religador->religar($aspirante, $matricula);

            $aspirante->update([
                'situacion_id' => SituacionAspirante::query()->where('clave', 'inscrito')->value('id'),
                'validado_admin' => true,
            ]);

            return $matricula;
        });
    }

    /**
     * Motivos por los que un aspirante todavía NO puede convertirse. Se
     * devuelven todos juntos para que la interfaz los muestre de una vez.
     *
     * @return array<int, string>
     */
    public function impedimentos(Aspirante $aspirante): array
    {
        $impedimentos = [];

        if ($aspirante->oferta_interes_id === null) {
            $impedimentos[] = 'No tiene una oferta de interés asignada.';
        }

        if (MatriculaOferta::query()
            ->where('persona_id', $aspirante->persona_id)
            ->where('oferta_id', $aspirante->oferta_interes_id)
            ->exists()
        ) {
            $impedimentos[] = 'Esta persona ya está matriculada en esa oferta.';
        }

        if (SituacionAlumno::query()->where('clave', 'activo')->value('id') === null) {
            $impedimentos[] = 'Falta el catálogo de situaciones de alumno.';
        }

        return $impedimentos;
    }

    private function validar(Aspirante $aspirante): void
    {
        $impedimentos = $this->impedimentos($aspirante);

        if ($impedimentos !== []) {
            throw new RuntimeException(implode(' ', $impedimentos));
        }
    }

    /**
     * Las respuestas dadas como aspirante pasan a colgar de la matrícula: es la
     * inscripción a una oferta lo que las contextualiza, no la persona. Si la
     * misma persona entra después a otra oferta, volverá a responder para esa.
     */
    private function religarRespuestas(Aspirante $aspirante, MatriculaOferta $matricula): void
    {
        RespuestaCampo::query()
            ->where('aspirante_id', $aspirante->id)
            ->whereNull('matricula_oferta_id')
            ->update(['matricula_oferta_id' => $matricula->id]);
    }
}
