<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\ControlEscolar\CalificacionComponente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cómo se compone la calificación de una materia-en-plan.
 *
 * Relacional, no JSON: una fila por componente (parcial 1, parcial 2, final,
 * LMS, prácticas...). Los porcentajes deben sumar 100; mientras no lo hagan, la
 * calificación final no se puede calcular correctamente al cierre del periodo.
 */
class EsquemaEvaluacionController extends Controller
{
    public function store(Request $request, PlanEstudio $plan, PlanMateria $materia): RedirectResponse
    {
        abort_unless($materia->plan_id === $plan->id, 404);

        $datos = $this->validar($request);

        $this->validarSuma($materia, (float) $datos['porcentaje']);

        EsquemaEvaluacion::create([
            ...$datos,
            'plan_materia_id' => $materia->id,
            'orden' => $datos['orden'] ?? ($materia->esquemaEvaluacion()->max('orden') + 1),
        ]);

        $this->desligarDePlantilla($materia);

        return back()->with('exito', 'Componente agregado.');
    }

    public function update(Request $request, PlanEstudio $plan, PlanMateria $materia, EsquemaEvaluacion $componente): RedirectResponse
    {
        abort_unless($materia->plan_id === $plan->id && $componente->plan_materia_id === $materia->id, 404);

        $datos = $this->validar($request);

        $this->validarSuma($materia, (float) $datos['porcentaje'], $componente->id);

        $componente->update($datos);

        $this->desligarDePlantilla($materia);

        return back()->with('exito', 'Componente actualizado.');
    }

    public function destroy(PlanEstudio $plan, PlanMateria $materia, EsquemaEvaluacion $componente): RedirectResponse
    {
        abort_unless($materia->plan_id === $plan->id && $componente->plan_materia_id === $materia->id, 404);

        $motivo = $componente->motivoParaNoRetirarlo();

        if ($motivo !== null) {
            return back()->with('error', $motivo);
        }

        DB::transaction(function () use ($componente): void {
            /*
             * Las capturas en blanco se van con él.
             *
             * Son filas con `calificacion` en NULL: el docente guardó la hoja
             * sin llegar a este componente. No son calificaciones —por eso no
             * impiden retirarlo— pero dejarlas atrás las convertiría en filas
             * colgando de un componente invisible, que es justo el desorden que
             * esta guarda viene a evitar.
             */
            CalificacionComponente::query()
                ->where('esquema_evaluacion_id', $componente->id)
                ->delete();

            $componente->delete();
        });

        $this->desligarDePlantilla($materia);

        return back()->with('exito', 'Componente eliminado.');
    }

    /**
     * Tocar el esquema a mano desliga la materia de su plantilla.
     *
     * Sin esto, la siguiente re-propagación de la plantilla borraría el ajuste
     * sin avisar. Que la materia se desligue sola es lo que hace segura la
     * regla "editar la plantilla cambia todas": solo alcanza a las que no se
     * han tocado.
     */
    private function desligarDePlantilla(PlanMateria $materia): void
    {
        if ($materia->plantilla_evaluacion_id !== null) {
            $materia->update(['plantilla_evaluacion_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request): array
    {
        return $request->validate([
            'componente' => ['required', 'string', 'max:60'],
            'parcial' => ['nullable', 'integer', 'min:1', 'max:10'],
            'porcentaje' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'porcentaje' => 'porcentaje',
        ]);
    }

    /**
     * El total no puede pasar de 100. Se permite quedarse corto mientras se
     * captura —el esquema se arma por partes— pero nunca excederlo.
     */
    private function validarSuma(PlanMateria $materia, float $porcentaje, ?int $ignorar = null): void
    {
        $acumulado = EsquemaEvaluacion::query()
            ->where('plan_materia_id', $materia->id)
            ->when($ignorar !== null, fn ($q) => $q->whereKeyNot($ignorar))
            ->sum('porcentaje');

        $total = (float) $acumulado + $porcentaje;

        if ($total > 100.0) {
            $disponible = round(100 - (float) $acumulado, 2);

            throw ValidationException::withMessages([
                'porcentaje' => "Con ese porcentaje el esquema sumaría {$total}%. Disponible: {$disponible}%.",
            ]);
        }
    }
}
