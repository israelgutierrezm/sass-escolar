<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\SituacionCiclo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ciclos escolares.
 *
 * Un ciclo delimita el periodo y —más importante— sus VENTANAS: hasta cuándo
 * se puede inscribir, hasta cuándo hacer altas y bajas, y hasta cuándo capturar
 * calificaciones. Esas fechas son las que gobiernan lo que el sistema deja o no
 * deja hacer en cada momento.
 *
 * `campus_id` en NULL significa ciclo global de la escuela; normalmente cada
 * campus carga los suyos.
 */
class CicloController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('ControlEscolar/Ciclos/Index', [
            'ciclos' => Ciclo::query()
                ->with(['campus:id,nombre', 'situacion:id,nombre'])
                ->withCount('grupos')
                ->orderByDesc('fecha_inicio')
                ->get()
                ->map(fn (Ciclo $ciclo) => [
                    'id' => $ciclo->id,
                    'clave' => $ciclo->clave,
                    'nombre' => $ciclo->nombre,
                    'campus' => $ciclo->campus?->nombre,
                    'situacion' => $ciclo->situacion?->nombre,
                    'fecha_inicio' => $ciclo->fecha_inicio?->toDateString(),
                    'fecha_fin' => $ciclo->fecha_fin?->toDateString(),
                    'inscripcion_abierta' => $ciclo->inscripcionAbierta(),
                    'grupos_count' => $ciclo->grupos_count,
                ]),
            'puedeEditar' => $request->user()->can('abrir-grupos'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ControlEscolar/Ciclos/Formulario', [
            'ciclo' => null,
            ...$this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Ciclo::create($this->validar($request));

        return redirect()->route('tenant.escolar.ciclos.index')->with('exito', 'Ciclo creado.');
    }

    public function edit(Ciclo $ciclo): Response
    {
        return Inertia::render('ControlEscolar/Ciclos/Formulario', [
            'ciclo' => [
                ...$ciclo->only(['id', 'campus_id', 'clave', 'nombre', 'situacion_id']),
                'fecha_inicio' => $ciclo->fecha_inicio?->toDateString(),
                'fecha_fin' => $ciclo->fecha_fin?->toDateString(),
                'inscripcion_desde' => $ciclo->inscripcion_desde?->toDateString(),
                'inscripcion_hasta' => $ciclo->inscripcion_hasta?->toDateString(),
                'altas_bajas_hasta' => $ciclo->altas_bajas_hasta?->toDateString(),
                'captura_calif_hasta' => $ciclo->captura_calif_hasta?->toDateString(),
            ],
            ...$this->catalogos(),
        ]);
    }

    public function update(Request $request, Ciclo $ciclo): RedirectResponse
    {
        $ciclo->update($this->validar($request, $ciclo->id));

        return redirect()->route('tenant.escolar.ciclos.index')->with('exito', 'Ciclo actualizado.');
    }

    /**
     * Un ciclo con grupos no se elimina: de ellos cuelgan inscripciones e
     * historial. Para cerrarlo se cambia su situación.
     */
    public function destroy(Ciclo $ciclo): RedirectResponse
    {
        if ($ciclo->grupos()->exists()) {
            return back()->with('error', 'No se puede eliminar: el ciclo tiene grupos. Ciérralo en su lugar.');
        }

        $ciclo->delete();

        return back()->with('exito', 'Ciclo eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'campus_id' => ['nullable', 'integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            'clave' => [
                'required', 'string', 'max:50',
                Rule::unique('ciclos', 'clave')
                    ->where('campus_id', $request->input('campus_id'))
                    ->ignore($id)
                    ->whereNull('deleted_at'),
            ],
            'nombre' => ['required', 'string', 'max:120'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after:fecha_inicio'],
            'situacion_id' => ['required', 'integer', Rule::exists('situaciones_ciclo', 'id')->whereNull('deleted_at')],
            'inscripcion_desde' => ['nullable', 'date'],
            'inscripcion_hasta' => ['nullable', 'date', 'after_or_equal:inscripcion_desde'],
            'altas_bajas_hasta' => ['nullable', 'date'],
            'captura_calif_hasta' => ['nullable', 'date'],
        ], [
            'fecha_fin.after' => 'El fin del ciclo debe ser posterior a su inicio.',
            'inscripcion_hasta.after_or_equal' => 'El cierre de inscripción no puede ser antes de su apertura.',
            'clave.unique' => 'Ya existe un ciclo con esa clave en ese campus.',
        ], [
            'campus_id' => 'campus',
            'situacion_id' => 'situación',
            'fecha_inicio' => 'fecha de inicio',
            'fecha_fin' => 'fecha de fin',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'situaciones' => SituacionCiclo::query()->orderBy('id')->get(['id', 'nombre']),
        ];
    }
}
