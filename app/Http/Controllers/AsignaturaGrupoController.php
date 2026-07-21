<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\SituacionAsignaturaGrupo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Apertura de materias dentro de un grupo y asignación de sus docentes.
 *
 * Abrir una materia es lo que la vuelve inscribible: hasta que existe una
 * `asignatura_grupo`, la materia solo es parte del plan, no algo que se pueda
 * cursar este ciclo.
 */
class AsignaturaGrupoController extends Controller
{
    public function store(Request $request, Grupo $grupo): RedirectResponse
    {
        $datos = $request->validate([
            'plan_materia_id' => ['required', 'integer', Rule::exists('plan_materias', 'id')->whereNull('deleted_at')],
        ], [], ['plan_materia_id' => 'materia']);

        $duplicada = AsignaturaGrupo::query()
            ->where('grupo_id', $grupo->id)
            ->where('plan_materia_id', $datos['plan_materia_id'])
            ->exists();

        if ($duplicada) {
            throw ValidationException::withMessages([
                'plan_materia_id' => 'Esa materia ya está abierta en este grupo.',
            ]);
        }

        AsignaturaGrupo::create([
            'grupo_id' => $grupo->id,
            'plan_materia_id' => $datos['plan_materia_id'],
            'situacion_id' => SituacionAsignaturaGrupo::query()->where('clave', 'activa')->value('id'),
        ]);

        return back()->with('exito', 'Materia abierta en el grupo.');
    }

    /**
     * Asigna un docente a la materia. La spec fija una regla que el esquema no
     * puede imponer —MySQL no admite índices únicos parciales—: a lo más UN
     * titular por materia, porque es quien firma el acta.
     */
    public function asignarDocente(Request $request, Grupo $grupo, AsignaturaGrupo $asignatura): RedirectResponse
    {
        abort_unless($asignatura->grupo_id === $grupo->id, 404);

        $datos = $request->validate([
            'persona_id' => ['required', 'integer', Rule::exists('docentes', 'persona_id')],
            'tipo' => ['required', Rule::in(['titular', 'adjunto'])],
        ], [], ['persona_id' => 'docente']);

        if ($datos['tipo'] === 'titular') {
            $otroTitular = $asignatura->docentes()
                ->wherePivot('tipo', 'titular')
                ->where('docentes.persona_id', '!=', $datos['persona_id'])
                ->exists();

            if ($otroTitular) {
                throw ValidationException::withMessages([
                    'persona_id' => 'La materia ya tiene un titular. Quítalo antes de asignar otro.',
                ]);
            }
        }

        $asignatura->docentes()->syncWithoutDetaching([
            $datos['persona_id'] => ['tipo' => $datos['tipo']],
        ]);

        return back()->with('exito', 'Docente asignado.');
    }

    public function quitarDocente(Grupo $grupo, AsignaturaGrupo $asignatura, int $personaId): RedirectResponse
    {
        abort_unless($asignatura->grupo_id === $grupo->id, 404);

        $asignatura->docentes()->detach($personaId);

        return back()->with('exito', 'Docente retirado.');
    }

    /**
     * Una materia con alumnos inscritos no se cierra borrándola: se les perdería
     * la inscripción y, si ya hay calificaciones, el acta.
     */
    public function destroy(Grupo $grupo, AsignaturaGrupo $asignatura): RedirectResponse
    {
        abort_unless($asignatura->grupo_id === $grupo->id, 404);

        if (Inscripcion::query()->where('asignatura_grupo_id', $asignatura->id)->exists()) {
            return back()->with('error', 'No se puede quitar: hay alumnos inscritos en esa materia.');
        }

        $asignatura->delete();

        return back()->with('exito', 'Materia retirada del grupo.');
    }
}
