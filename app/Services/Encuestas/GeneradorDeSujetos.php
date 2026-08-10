<?php

declare(strict_types=1);

namespace App\Services\Encuestas;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Encuestas\AplicacionEncuesta;
use App\Models\Encuestas\Sujeto;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quiénes van a ser evaluados, resuelto a partir de filtros.
 *
 * ── El problema que resuelve ───────────────────────────────────────────────
 * Una evaluación docente de un ciclo son cuarenta, cien o trescientas encuestas
 * —una por cada docente en cada materia—. Capturarlas a mano es lo que hace que
 * la evaluación se deje de aplicar al segundo semestre. Aquí la escuela dice
 * «el ciclo 2026-1, sólo titulares» y salen todas.
 *
 * ── Un sujeto es un docente EN UNA MATERIA ─────────────────────────────────
 * No el docente a secas. El mismo profesor puede dar dos materias y salir bien
 * en una y mal en otra; promediarlas escondería justo el dato que sirve para
 * hacer algo. Y el alumno tampoco evalúa «a su profesor»: evalúa la clase que
 * recibe, que es lo único que puede juzgar.
 *
 * ── Se puede volver a generar ──────────────────────────────────────────────
 * Añadir un grupo que se abrió tarde no debe borrar lo ya contestado, así que
 * los sujetos se agregan sin tocar los que ya estaban. Quitar los que sobran es
 * una decisión distinta —hay respuestas de por medio— y se hace aparte.
 */
class GeneradorDeSujetos
{
    /**
     * Genera los sujetos de una aplicación docente.
     *
     * @param  array{ciclo?: int|null, grupos?: array<int, int>, materias?: array<int, int>, campus?: int|null, papeles?: array<int, string>}  $filtros
     * @return int Cuántos se agregaron.
     */
    public function generar(AplicacionEncuesta $aplicacion, array $filtros): int
    {
        $papeles = $filtros['papeles'] ?? [Sujeto::TITULAR];

        $materias = AsignaturaGrupo::query()
            ->with(['docentes'])
            ->when($filtros['ciclo'] ?? null, fn (Builder $q, $ciclo) => $q->whereHas('grupo', fn ($g) => $g->where('ciclo_id', $ciclo)))
            ->when($filtros['campus'] ?? null, fn (Builder $q, $campus) => $q->whereHas('grupo', fn ($g) => $g->where('campus_id', $campus)))
            ->when($filtros['grupos'] ?? [], fn (Builder $q, $grupos) => $q->whereIn('grupo_id', $grupos))
            ->when($filtros['materias'] ?? [], fn (Builder $q, $ids) => $q->whereIn('id', $ids))
            ->get();

        $agregados = 0;

        foreach ($materias as $materia) {
            foreach ($materia->docentes as $docente) {
                /*
                 * El papel vive en el pivote de la asignación: el mismo docente
                 * puede ser titular de una materia y adjunto de otra, así que
                 * filtrar por la persona sería filtrar lo que no es.
                 */
                $papel = $docente->pivot->tipo ?? Sujeto::TITULAR;

                if (! in_array($papel, $papeles, true)) {
                    continue;
                }

                // `firstOrCreate` y no `create`: volver a generar tras abrir un
                // grupo nuevo no puede duplicar a quien ya estaba —ni, peor,
                // dejar dos encuestas idénticas al mismo alumno—.
                $sujeto = Sujeto::query()->firstOrCreate([
                    'aplicacion_id' => $aplicacion->id,
                    'persona_id' => $docente->persona_id,
                    'asignatura_grupo_id' => $materia->id,
                ], ['papel' => $papel]);

                if ($sujeto->wasRecentlyCreated) {
                    $agregados++;
                }
            }
        }

        return $agregados;
    }

    /**
     * Cuántos saldrían con esos filtros, sin crear nada.
     *
     * Se enseña ANTES de generar porque la diferencia entre 12 y 300 encuestas
     * cambia la decisión, y descubrirlo después obliga a deshacer algo que ya
     * puede tener respuestas.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function contar(array $filtros): int
    {
        $papeles = $filtros['papeles'] ?? [Sujeto::TITULAR];

        return AsignaturaGrupo::query()
            ->with('docentes')
            ->when($filtros['ciclo'] ?? null, fn (Builder $q, $ciclo) => $q->whereHas('grupo', fn ($g) => $g->where('ciclo_id', $ciclo)))
            ->when($filtros['campus'] ?? null, fn (Builder $q, $campus) => $q->whereHas('grupo', fn ($g) => $g->where('campus_id', $campus)))
            ->when($filtros['grupos'] ?? [], fn (Builder $q, $grupos) => $q->whereIn('grupo_id', $grupos))
            ->when($filtros['materias'] ?? [], fn (Builder $q, $ids) => $q->whereIn('id', $ids))
            ->get()
            ->sum(fn (AsignaturaGrupo $m) => $m->docentes
                ->filter(fn ($d) => in_array($d->pivot->tipo ?? Sujeto::TITULAR, $papeles, true))
                ->count());
    }
}
