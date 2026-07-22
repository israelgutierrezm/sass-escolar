<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\ExcepcionCaptura;
use App\Models\ControlEscolar\VentanaCaptura;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendario de captura de un ciclo: hasta cuándo puede calificar el docente,
 * parcial por parcial, y a quién se le reabre cuando ya cerró.
 *
 * Sin ventanas configuradas el ciclo captura libre; configurar una es lo que
 * empieza a bloquear. Ver `App\Services\CalendarioCaptura` para la resolución.
 */
class VentanaCapturaController extends Controller
{
    public function index(Request $request, Ciclo $ciclo): Response
    {
        $ciclo->load('campus:id,nombre');

        return Inertia::render('ControlEscolar/Ciclos/Ventanas', [
            'ciclo' => [
                'id' => $ciclo->id,
                'clave' => $ciclo->clave,
                'nombre' => $ciclo->nombre,
                'campus' => $ciclo->campus->pluck('nombre')->all(),
                'captura_calif_hasta' => $ciclo->captura_calif_hasta?->toDateString(),
            ],
            'ventanas' => VentanaCaptura::query()
                ->where('ciclo_id', $ciclo->id)
                ->withCount('excepciones')
                ->orderByRaw('parcial IS NULL, parcial')
                ->get()
                ->map(fn (VentanaCaptura $v) => [
                    'id' => $v->id,
                    'parcial' => $v->parcial,
                    'nombre' => $v->nombre,
                    'etiqueta' => $v->etiqueta(),
                    'desde' => $v->desde->toDateString(),
                    'hasta' => $v->hasta->toDateString(),
                    'activa' => $v->activa,
                    'abierta' => $v->estaAbierta(),
                    'excepciones_count' => $v->excepciones_count,
                ]),
            'excepciones' => ExcepcionCaptura::query()
                ->with([
                    'ventana:id,parcial,nombre',
                    'persona:id,nombre,primer_apellido,segundo_apellido',
                    'autorizadaPor:id,nombre,primer_apellido,segundo_apellido',
                    'asignaturaGrupo.planMateria.asignatura:id,nombre',
                    'asignaturaGrupo.grupo:id,clave',
                ])
                ->whereHas('ventana', fn ($q) => $q->where('ciclo_id', $ciclo->id))
                ->latest('id')
                ->get()
                ->map(fn (ExcepcionCaptura $e) => [
                    'id' => $e->id,
                    'ventana_id' => $e->ventana_id,
                    'corte' => $e->ventana?->etiqueta(),
                    'materia' => $e->asignaturaGrupo?->planMateria?->asignatura?->nombre,
                    'grupo' => $e->asignaturaGrupo?->grupo?->clave,
                    'docente' => $e->persona?->nombreCompleto() ?? 'cualquier docente de la materia',
                    'hasta' => $e->hasta->toDateString(),
                    'vigente' => $e->sigueVigente(),
                    'motivo' => $e->motivo,
                    'autorizada_por' => $e->autorizadaPor?->nombreCompleto(),
                ]),
            'materias' => AsignaturaGrupo::query()
                ->with(['planMateria.asignatura:id,nombre', 'grupo:id,clave,ciclo_id', 'docentes.persona:id,nombre,primer_apellido,segundo_apellido'])
                ->whereHas('grupo', fn ($q) => $q->where('ciclo_id', $ciclo->id))
                ->get()
                ->map(fn (AsignaturaGrupo $ag) => [
                    'id' => $ag->id,
                    'etiqueta' => sprintf(
                        '%s · %s (grupo %s)',
                        $ag->planMateria?->clave_en_plan ?? '',
                        $ag->planMateria?->asignatura?->nombre ?? '',
                        $ag->grupo?->clave ?? '',
                    ),
                    'docentes' => $ag->docentes->map(fn ($d) => [
                        'id' => $d->persona_id,
                        'nombre' => $d->persona?->nombreCompleto(),
                    ])->values(),
                ]),
            'puedeEditar' => $request->user()->can('gestionar-ventanas-captura'),
        ]);
    }

    public function store(Request $request, Ciclo $ciclo): RedirectResponse
    {
        $datos = $this->validar($request, $ciclo);

        VentanaCaptura::create([...$datos, 'ciclo_id' => $ciclo->id]);

        return back()->with('exito', 'Ventana de captura creada.');
    }

    public function update(Request $request, Ciclo $ciclo, VentanaCaptura $ventana): RedirectResponse
    {
        abort_unless($ventana->ciclo_id === $ciclo->id, 404);

        $ventana->update($this->validar($request, $ciclo, $ventana));

        return back()->with('exito', 'Ventana actualizada.');
    }

    /** Apagar y encender sin borrar: es como se opera en la práctica. */
    public function alternar(Ciclo $ciclo, VentanaCaptura $ventana): RedirectResponse
    {
        abort_unless($ventana->ciclo_id === $ciclo->id, 404);

        $ventana->update(['activa' => ! $ventana->activa]);

        return back()->with('exito', $ventana->activa
            ? 'Captura reabierta para '.$ventana->etiqueta().'.'
            : 'Captura cerrada para '.$ventana->etiqueta().'.');
    }

    public function destroy(Ciclo $ciclo, VentanaCaptura $ventana): RedirectResponse
    {
        abort_unless($ventana->ciclo_id === $ciclo->id, 404);

        // Con excepciones colgando, borrarla se llevaría el rastro de a quién
        // se le reabrió y por qué. Se desactiva.
        if ($ventana->excepciones()->exists()) {
            return back()->with('error', 'No se puede eliminar: tiene excepciones concedidas. Desactívala en su lugar.');
        }

        $ventana->delete();

        return back()->with('exito', 'Ventana eliminada.');
    }

    /** Reabre la captura a un docente concreto en una materia concreta. */
    public function conceder(Request $request, Ciclo $ciclo, VentanaCaptura $ventana): RedirectResponse
    {
        abort_unless($ventana->ciclo_id === $ciclo->id, 404);

        $datos = $request->validate([
            'asignatura_grupo_id' => ['required', 'integer', Rule::exists('asignatura_grupo', 'id')->whereNull('deleted_at')],
            'persona_id' => ['nullable', 'integer', Rule::exists('personas', 'id')->whereNull('deleted_at')],
            'hasta' => ['required', 'date', 'after_or_equal:today'],
            'motivo' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'hasta.after_or_equal' => 'La excepción tiene que terminar hoy o después.',
            'motivo.min' => 'Explica por qué se reabre la captura (mínimo 10 caracteres).',
        ], [
            'asignatura_grupo_id' => 'materia',
            'persona_id' => 'docente',
        ]);

        $materia = AsignaturaGrupo::with('grupo')->findOrFail($datos['asignatura_grupo_id']);

        if ($materia->grupo?->ciclo_id !== $ciclo->id) {
            throw ValidationException::withMessages([
                'asignatura_grupo_id' => 'Esa materia no pertenece a este ciclo.',
            ]);
        }

        ExcepcionCaptura::updateOrCreate(
            [
                'ventana_id' => $ventana->id,
                'asignatura_grupo_id' => $materia->id,
                'persona_id' => $datos['persona_id'] ?? null,
            ],
            [
                'hasta' => $datos['hasta'],
                'motivo' => $datos['motivo'],
                'autorizada_por' => $request->user()?->persona_id,
            ],
        );

        return back()->with('exito', 'Captura reabierta.');
    }

    public function revocar(Ciclo $ciclo, VentanaCaptura $ventana, ExcepcionCaptura $excepcion): RedirectResponse
    {
        abort_unless($ventana->ciclo_id === $ciclo->id && $excepcion->ventana_id === $ventana->id, 404);

        // Soft delete: la excepción se concedió y eso no deja de ser cierto
        // porque se haya revocado después.
        $excepcion->delete();

        return back()->with('exito', 'Excepción revocada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, Ciclo $ciclo, ?VentanaCaptura $ventana = null): array
    {
        $datos = $request->validate([
            'parcial' => ['nullable', 'integer', 'min:1', 'max:10'],
            'nombre' => ['nullable', 'string', 'max:80'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'activa' => ['boolean'],
        ], [
            'hasta.after_or_equal' => 'El cierre de la ventana no puede ser antes de su apertura.',
        ]);

        // MySQL considera distintos dos NULL, así que el índice único no impide
        // dos ventanas "sin parcial" en el mismo ciclo. Se valida aquí.
        $duplicada = VentanaCaptura::query()
            ->where('ciclo_id', $ciclo->id)
            ->when($datos['parcial'] === null,
                fn ($q) => $q->whereNull('parcial'),
                fn ($q) => $q->where('parcial', $datos['parcial']),
            )
            ->when($ventana !== null, fn ($q) => $q->whereKeyNot($ventana->id))
            ->exists();

        if ($duplicada) {
            throw ValidationException::withMessages([
                'parcial' => $datos['parcial'] === null
                    ? 'Ya hay una ventana para los rubros sin parcial en este ciclo.'
                    : "Ya hay una ventana para el parcial {$datos['parcial']} en este ciclo.",
            ]);
        }

        return $datos;
    }
}
