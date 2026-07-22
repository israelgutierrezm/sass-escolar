<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\SituacionAsignaturaGrupo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    /**
     * Abre una o varias materias de golpe.
     *
     * Se reciben en lote porque abrir un grupo es cargar el semestre completo:
     * hacerlo de una en una son diez viajes al servidor y diez recargas para
     * una sola decisión del usuario.
     */
    public function store(Request $request, Grupo $grupo): RedirectResponse
    {
        $datos = $request->validate([
            'plan_materia_ids' => ['required', 'array', 'min:1'],
            'plan_materia_ids.*' => ['integer', Rule::exists('plan_materias', 'id')->whereNull('deleted_at')],
        ], [
            'plan_materia_ids.required' => 'Elige al menos una materia.',
            'plan_materia_ids.min' => 'Elige al menos una materia.',
        ], ['plan_materia_ids' => 'materias']);

        $pedidas = array_values(array_unique(array_map('intval', $datos['plan_materia_ids'])));

        $yaAbiertas = AsignaturaGrupo::query()
            ->where('grupo_id', $grupo->id)
            ->whereIn('plan_materia_id', $pedidas)
            ->pluck('plan_materia_id')
            ->all();

        $nuevas = array_values(array_diff($pedidas, $yaAbiertas));

        if ($nuevas === []) {
            throw ValidationException::withMessages([
                'plan_materia_ids' => count($pedidas) === 1
                    ? 'Esa materia ya está abierta en este grupo.'
                    : 'Todas las materias elegidas ya están abiertas en este grupo.',
            ]);
        }

        $activa = SituacionAsignaturaGrupo::query()->where('clave', 'activa')->value('id');

        DB::transaction(function () use ($nuevas, $grupo, $activa): void {
            foreach ($nuevas as $planMateriaId) {
                AsignaturaGrupo::create([
                    'grupo_id' => $grupo->id,
                    'plan_materia_id' => $planMateriaId,
                    'situacion_id' => $activa,
                ]);
            }
        });

        $mensaje = count($nuevas) === 1
            ? 'Materia abierta en el grupo.'
            : count($nuevas).' materias abiertas en el grupo.';

        // Si venían repetidas se dice, en vez de fingir que se abrieron todas.
        if ($yaAbiertas !== []) {
            return back()->with('advertencia', $mensaje.' '.count($yaAbiertas).' ya estaban abiertas y se omitieron.');
        }

        return back()->with('exito', $mensaje);
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
