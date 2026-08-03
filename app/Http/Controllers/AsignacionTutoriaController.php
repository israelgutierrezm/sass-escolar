<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\Alumno;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Tutoria;
use App\Models\Identidad\Persona;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Repartir los alumnos entre los tutores educativos.
 *
 * ── Por qué es masivo y no de uno en uno ───────────────────────────────────
 * Una tutoría no se asigna sola: se reparte una generación completa al empezar
 * el ciclo. Con una pantalla de alta individual, repartir cien alumnos entre
 * cinco tutores son cien formularios. Aquí se filtra, se palomea y se asigna de
 * un golpe.
 *
 * ── La reasignación es lo normal, no la excepción ──────────────────────────
 * Un alumno ya asignado no da error al volverlo a asignar: cambia de tutor. Es
 * lo que ocurre cuando un tutor se va o cuando la carga queda dispareja, y
 * obligar a quitar antes de poner sería trabajo doble para el caso frecuente.
 * El único caso imposible es que dos personas acompañen al mismo alumno en el
 * mismo ciclo, y eso lo impide la llave única de la tabla.
 */
class AsignacionTutoriaController extends Controller
{
    public function index(Request $request): Response
    {
        $cicloId = $request->integer('ciclo_id') ?: null;

        /*
         * Quién puede tutorar: las personas con el rol de tutor educativo. No
         * cualquier docente —hay escuelas donde tutora el orientador, y hay
         * docentes que no tutoran—, y no se inventa aquí una lista propia:
         * quien reparte los roles ya decidió quiénes son.
         */
        $tutores = Persona::query()
            ->whereIn('id', DB::table('persona_rol')
                ->join('roles', 'roles.id', '=', 'persona_rol.rol_id')
                ->where('roles.name', 'tutor_educativo')
                ->whereNull('persona_rol.deleted_at')
                ->pluck('persona_rol.persona_id'))
            ->get()
            ->map(fn (Persona $p) => [
                'id' => $p->id,
                'nombre' => $p->nombreCompleto(),
                // Cuántos lleva ya: repartir a ciegas es como se producen
                // tutores con cuarenta alumnos y otros con tres.
                'tutorados' => Tutoria::query()
                    ->where('tutor_persona_id', $p->id)
                    ->where('activa', true)
                    ->when($cicloId !== null, fn ($q) => $q->where('ciclo_id', $cicloId))
                    ->count(),
            ])
            ->sortBy('nombre')
            ->values();

        $asignadas = Tutoria::query()
            ->where('activa', true)
            ->when($cicloId !== null, fn ($q) => $q->where('ciclo_id', $cicloId))
            ->with('tutor')
            ->get()
            ->keyBy('alumno_persona_id');

        $alumnos = Alumno::query()
            ->with(['persona', 'matriculas.oferta.carrera:id,nombre'])
            ->get()
            ->map(function (Alumno $a) use ($asignadas) {
                $tutoria = $asignadas->get($a->persona_id);

                return [
                    'id' => $a->persona_id,
                    'nombre' => $a->persona?->nombreCompleto() ?? 'Alumno',
                    'matricula' => $a->matriculas->first()?->matricula,
                    'carrera' => $a->matriculas->first()?->oferta?->carrera?->nombre,
                    'tutor' => $tutoria?->tutor?->nombreCompleto(),
                    'tutoria_id' => $tutoria?->id,
                ];
            })
            /*
             * Los que NO tienen tutor van primero: son los que hay que atender.
             * Ordenar por nombre dejaría el trabajo pendiente repartido por toda
             * la lista.
             */
            ->sortBy(fn (array $a) => [$a['tutor'] === null ? 0 : 1, $a['nombre']])
            ->values();

        return Inertia::render('Escolar/Tutorias', [
            'ciclos' => Ciclo::query()->orderByDesc('id')->get(['id', 'clave'])
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'nombre' => $c->clave]),
            'cicloSeleccionado' => $cicloId,
            'tutores' => $tutores,
            'alumnos' => $alumnos,
            'resumen' => [
                'total' => $alumnos->count(),
                'sin_tutor' => $alumnos->filter(fn (array $a) => $a['tutor'] === null)->count(),
            ],
        ]);
    }

    public function asignar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'tutor_persona_id' => ['required', 'integer', 'exists:personas,id'],
            'ciclo_id' => ['nullable', 'integer', 'exists:ciclos,id'],
            'alumnos' => ['required', 'array', 'min:1'],
            'alumnos.*' => ['integer', 'exists:alumnos,persona_id'],
        ], [], [
            'tutor_persona_id' => 'el tutor',
            'alumnos' => 'los alumnos',
        ]);

        /*
         * Nadie se tutora a sí mismo. Es raro —haría falta ser alumno y tutor a
         * la vez—, pero pasa en escuelas donde el personal estudia un posgrado,
         * y el resultado sería alguien vigilando su propio avance.
         */
        $datos['alumnos'] = array_values(array_filter(
            $datos['alumnos'],
            fn (int $id) => $id !== (int) $datos['tutor_persona_id'],
        ));

        if ($datos['alumnos'] === []) {
            return back(303)->with('error', 'Nadie puede ser su propio tutor.');
        }

        DB::transaction(function () use ($datos) {
            foreach ($datos['alumnos'] as $alumnoId) {
                Tutoria::actualizarOReviver(
                    ['alumno_persona_id' => $alumnoId, 'ciclo_id' => $datos['ciclo_id'] ?? null],
                    ['tutor_persona_id' => $datos['tutor_persona_id'], 'activa' => true],
                );
            }
        });

        $cuantos = count($datos['alumnos']);

        return back(303)->with(
            'exito',
            $cuantos === 1 ? 'Tutoría asignada.' : "{$cuantos} tutorías asignadas.",
        );
    }

    public function quitar(Request $request, Tutoria $tutoria): RedirectResponse
    {
        /*
         * Se desactiva, no se borra: que un alumno tuvo tutor en un ciclo es
         * parte de su expediente, y al revisar un rezago hay que poder saber
         * quién lo acompañaba. El borrado deja la llave única libre para el
         * siguiente, así que el soft delete del trait es justo lo que hace
         * falta.
         */
        $tutoria->delete();

        return back(303)->with('exito', 'Tutoría retirada.');
    }
}
