<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\Seriacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Prerrequisitos entre materias de un plan: el DAG de seriación.
 *
 * Una materia puede exigir varias otras (tipada: basta con haberla CURSADO, o
 * hay que haberla APROBADO), o bien exigir un mínimo de créditos acumulados en
 * lugar de una materia puntual.
 *
 * Lo que valida la inscripción autogestiva más adelante se apoya en esto, así
 * que la integridad del grafo importa: un ciclo dejaría materias imposibles de
 * cursar para siempre.
 */
class SeriacionController extends Controller
{
    public function store(Request $request, PlanEstudio $plan, PlanMateria $materia): RedirectResponse
    {
        abort_unless($materia->plan_id === $plan->id, 404);

        $datos = $request->validate([
            'requiere_plan_materia_id' => ['nullable', 'integer'],
            'tipo' => ['required', Rule::in(['cursada', 'aprobada'])],
            'minimo_creditos' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'requiere_plan_materia_id' => 'materia requisito',
            'minimo_creditos' => 'mínimo de créditos',
        ]);

        // Un requisito es o una materia concreta o un mínimo de créditos.
        if ($datos['requiere_plan_materia_id'] === null && ($datos['minimo_creditos'] ?? null) === null) {
            throw ValidationException::withMessages([
                'requiere_plan_materia_id' => 'Indica una materia requisito o un mínimo de créditos.',
            ]);
        }

        if ($datos['requiere_plan_materia_id'] !== null) {
            $this->validarRequisito($plan, $materia, (int) $datos['requiere_plan_materia_id']);
        }

        Seriacion::create([
            ...$datos,
            'plan_materia_id' => $materia->id,
        ]);

        return back()->with('exito', 'Requisito agregado.');
    }

    public function destroy(PlanEstudio $plan, PlanMateria $materia, Seriacion $seriacion): RedirectResponse
    {
        abort_unless($materia->plan_id === $plan->id && $seriacion->plan_materia_id === $materia->id, 404);

        $seriacion->delete();

        return back()->with('exito', 'Requisito eliminado.');
    }

    /**
     * Tres reglas que el esquema no puede imponer por sí solo.
     */
    private function validarRequisito(PlanEstudio $plan, PlanMateria $materia, int $requisitoId): void
    {
        if ($requisitoId === $materia->id) {
            throw ValidationException::withMessages([
                'requiere_plan_materia_id' => 'Una materia no puede ser requisito de sí misma.',
            ]);
        }

        $requisito = PlanMateria::find($requisitoId);

        if ($requisito === null || $requisito->plan_id !== $plan->id) {
            throw ValidationException::withMessages([
                'requiere_plan_materia_id' => 'El requisito debe ser una materia del mismo plan.',
            ]);
        }

        if ($this->generariaCiclo($materia->id, $requisitoId)) {
            throw ValidationException::withMessages([
                'requiere_plan_materia_id' => 'Ese requisito crearía un ciclo: la materia requisito depende, directa o indirectamente, de esta.',
            ]);
        }
    }

    /**
     * ¿Agregar "materia requiere requisito" cerraría un ciclo?
     *
     * Se recorre hacia arriba la cadena de requisitos del REQUISITO. Si en el
     * camino se llega a la propia materia, el grafo dejaría de ser acíclico.
     * Se lleva registro de visitados para no colgarse si ya existiera un ciclo.
     */
    private function generariaCiclo(int $materiaId, int $requisitoId): bool
    {
        $pendientes = [$requisitoId];
        $visitados = [];

        while ($pendientes !== []) {
            $actual = array_pop($pendientes);

            if ($actual === $materiaId) {
                return true;
            }

            if (isset($visitados[$actual])) {
                continue;
            }

            $visitados[$actual] = true;

            $ancestros = Seriacion::query()
                ->where('plan_materia_id', $actual)
                ->whereNotNull('requiere_plan_materia_id')
                ->pluck('requiere_plan_materia_id')
                ->all();

            $pendientes = [...$pendientes, ...$ancestros];
        }

        return false;
    }
}
