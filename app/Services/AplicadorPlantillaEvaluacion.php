<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\PlantillaComponente;
use App\Models\Academico\PlantillaEvaluacion;
use App\Models\ControlEscolar\CalificacionComponente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Aplica una plantilla de evaluación a las materias de un plan.
 *
 * Los componentes se MATERIALIZAN como filas de `esquema_evaluacion`, no se
 * leen en vivo desde la plantilla. Es a propósito: `calificaciones_componente`
 * apunta a `esquema_evaluacion_id`, y resolver el esquema en tiempo real
 * obligaría a un segundo camino —calificaciones que apuntan a veces a una tabla
 * y a veces a otra— para no ganar nada.
 *
 * La regla que gobierna todo lo demás: **una materia con calificaciones ya
 * capturadas NO se re-aplica**. Cambiarle el criterio a media evaluación
 * dejaría huérfano lo capturado y movería calificaciones que un docente ya
 * asentó. Esas materias se reportan como bloqueadas y se explican; no se tocan
 * ni se fallan en silencio.
 */
class AplicadorPlantillaEvaluacion
{
    public function __construct(private readonly RepartidorPorcentajes $repartidor) {}

    /**
     * Aplica la plantilla a una materia, reemplazando su esquema.
     *
     * @throws RuntimeException si la materia ya tiene calificaciones capturadas
     *                          o si la plantilla no suma 100%.
     */
    public function aplicarAMateria(PlantillaEvaluacion $plantilla, PlanMateria $materia): void
    {
        if (! $plantilla->estaCompleta()) {
            throw new RuntimeException(
                sprintf('La plantilla "%s" suma %s%% y debe sumar 100%% para poder aplicarse.',
                    $plantilla->nombre, $this->formatear($plantilla->sumaPorcentajes()))
            );
        }

        if ($this->tieneCapturas($materia)) {
            throw new RuntimeException(
                'La materia ya tiene calificaciones capturadas: su esquema no se puede reemplazar.'
            );
        }

        DB::transaction(function () use ($plantilla, $materia): void {
            EsquemaEvaluacion::query()->where('plan_materia_id', $materia->id)->forceDelete();

            foreach ($plantilla->componentes as $componente) {
                EsquemaEvaluacion::create([
                    'plan_materia_id' => $materia->id,
                    'componente' => $componente->componente,
                    'parcial' => $componente->parcial,
                    'porcentaje' => $componente->porcentaje,
                    'orden' => $componente->orden,
                ]);
            }

            $materia->update(['plantilla_evaluacion_id' => $plantilla->id]);
        });
    }

    /**
     * Aplica la plantilla a todas las materias del plan y la fija como su
     * criterio por defecto.
     *
     * @param  bool  $respetarPersonalizadas  Si true, no toca las materias que
     *                                        armaron su esquema a mano.
     * @return array{aplicadas: int, bloqueadas: array<int, string>, omitidas: int}
     */
    public function aplicarAPlan(PlantillaEvaluacion $plantilla, PlanEstudio $plan, bool $respetarPersonalizadas = true): array
    {
        $materias = PlanMateria::query()
            ->with('asignatura:id,nombre')
            ->where('plan_id', $plan->id)
            ->get();

        $resultado = $this->aplicarALote($plantilla, $materias, $respetarPersonalizadas);

        $plan->update(['plantilla_evaluacion_id' => $plantilla->id]);

        return $resultado;
    }

    /**
     * Re-propaga la plantilla a las materias que la usan. Es lo que hace que
     * editar el criterio una vez lo cambie en todas.
     *
     * @return array{aplicadas: int, bloqueadas: array<int, string>, omitidas: int}
     */
    public function repropagar(PlantillaEvaluacion $plantilla): array
    {
        $materias = PlanMateria::query()
            ->with('asignatura:id,nombre')
            ->where('plantilla_evaluacion_id', $plantilla->id)
            ->get();

        // Aquí no se respetan personalizadas: estas materias YA declararon que
        // siguen esta plantilla. Si alguien editó su esquema a mano, al hacerlo
        // se desligó (plantilla_evaluacion_id quedó en NULL) y no está en esta
        // lista.
        return $this->aplicarALote($plantilla, $materias, respetarPersonalizadas: false);
    }

    /**
     * Materias que no se pudieron re-aplicar por tener calificaciones. Se
     * consulta antes de guardar, para poder advertir en vez de sorprender.
     *
     * @return array<int, string>
     */
    public function materiasBloqueadas(PlantillaEvaluacion $plantilla): array
    {
        return PlanMateria::query()
            ->with('asignatura:id,nombre')
            ->where('plantilla_evaluacion_id', $plantilla->id)
            ->get()
            ->filter(fn (PlanMateria $m) => $this->tieneCapturas($m))
            ->map(fn (PlanMateria $m) => $this->nombrar($m))
            ->values()
            ->all();
    }

    /**
     * Reparte 100% en partes iguales entre los componentes de la plantilla.
     * Reemplaza los porcentajes actuales.
     */
    public function repartirEquitativo(PlantillaEvaluacion $plantilla): void
    {
        $componentes = $plantilla->componentes()->get();
        $porcentajes = $this->repartidor->equitativo($componentes->count());

        DB::transaction(function () use ($componentes, $porcentajes): void {
            foreach ($componentes->values() as $i => $componente) {
                /** @var PlantillaComponente $componente */
                $componente->update(['porcentaje' => $porcentajes[$i]]);
            }
        });
    }

    /**
     * @param  Collection<int, PlanMateria>  $materias
     * @return array{aplicadas: int, bloqueadas: array<int, string>, omitidas: int}
     */
    private function aplicarALote(PlantillaEvaluacion $plantilla, Collection $materias, bool $respetarPersonalizadas): array
    {
        if (! $plantilla->estaCompleta()) {
            throw new RuntimeException(
                sprintf('La plantilla "%s" suma %s%% y debe sumar 100%% para poder aplicarse.',
                    $plantilla->nombre, $this->formatear($plantilla->sumaPorcentajes()))
            );
        }

        $aplicadas = 0;
        $omitidas = 0;
        $bloqueadas = [];

        foreach ($materias as $materia) {
            if ($this->tieneCapturas($materia)) {
                $bloqueadas[] = $this->nombrar($materia);

                continue;
            }

            // Una materia con esquema propio y sin plantilla declarada se armó
            // a mano: se respeta salvo que se pida explícitamente pisarla.
            $personalizada = $materia->plantilla_evaluacion_id === null
                && $materia->esquemaEvaluacion()->exists();

            if ($respetarPersonalizadas && $personalizada) {
                $omitidas++;

                continue;
            }

            $this->aplicarAMateria($plantilla, $materia);
            $aplicadas++;
        }

        return ['aplicadas' => $aplicadas, 'bloqueadas' => $bloqueadas, 'omitidas' => $omitidas];
    }

    /** ¿Algún alumno ya tiene calificación capturada contra el esquema de esta materia? */
    private function tieneCapturas(PlanMateria $materia): bool
    {
        return CalificacionComponente::query()
            ->whereIn(
                'esquema_evaluacion_id',
                EsquemaEvaluacion::query()->where('plan_materia_id', $materia->id)->select('id')
            )
            ->exists();
    }

    private function nombrar(PlanMateria $materia): string
    {
        return trim($materia->clave_en_plan.' '.($materia->asignatura?->nombre ?? ''));
    }

    private function formatear(float $numero): string
    {
        return rtrim(rtrim(number_format($numero, 2, '.', ''), '0'), '.');
    }
}
