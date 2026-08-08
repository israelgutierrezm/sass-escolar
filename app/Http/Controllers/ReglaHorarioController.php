<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Academico\Campus;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\ReglaHorario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Con qué criterios se arma un horario.
 *
 * Lo que hoy vive en la cabeza de quien lo arma: a qué hora abre la escuela,
 * cuánto dura una clase, cuántas horas seguidas se le pueden cargar a alguien.
 * Escrito, deja de depender de que esa persona esté.
 *
 * ── Una regla base y excepciones ───────────────────────────────────────────
 * La mayoría de las escuelas define una y no vuelve. La que necesita que el
 * campus sabatino abra a las 8 escribe SÓLO esa diferencia, y se resuelve de lo
 * más específico a lo más general —ciclo+campus, campus, ciclo, base—.
 */
class ReglaHorarioController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Horarios/Reglas', [
            'reglas' => ReglaHorario::query()
                ->with(['ciclo:id,clave', 'campus:id,nombre'])
                ->orderByRaw('ciclo_id is null desc, campus_id is null desc')
                ->get()
                ->map(fn (ReglaHorario $r) => [
                    'id' => $r->id,
                    'nombre' => $r->nombre,
                    'ciclo_id' => $r->ciclo_id,
                    'ciclo' => $r->ciclo?->clave,
                    'campus_id' => $r->campus_id,
                    'campus' => $r->campus?->nombre,
                    'dias' => $r->diasLaborales(),
                    'hora_apertura' => substr((string) $r->hora_apertura, 0, 5),
                    'hora_cierre' => substr((string) $r->hora_cierre, 0, 5),
                    'minutos_bloque' => $r->minutos_bloque,
                    'bloques_min_por_sesion' => $r->bloques_min_por_sesion,
                    'bloques_max_por_sesion' => $r->bloques_max_por_sesion,
                    'max_sesiones_por_dia' => $r->max_sesiones_por_dia,
                    'horas_max_dia_docente' => $r->horas_max_dia_docente,
                    'horas_max_semana_docente' => $r->horas_max_semana_docente,
                    'minutos_descanso_docente' => $r->minutos_descanso_docente,
                    'reparto' => $r->reparto,
                    'permite_huecos_grupo' => $r->permite_huecos_grupo,
                    'activa' => $r->activa,
                    // Cuántos bloques caben al día: lo que de verdad determina
                    // si un horario va a poder armarse.
                    'bloques_al_dia' => count($r->bloquesDelDia()),
                ]),
            'ciclos' => Ciclo::query()->orderByDesc('id')->limit(10)->get(['id', 'clave', 'nombre'])
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'nombre' => $c->clave ?? $c->nombre]),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $this->rechazarDuplicada($datos, null);

        ReglaHorario::create($datos);

        return back()->with('exito', 'Regla de horario creada.');
    }

    public function update(Request $request, ReglaHorario $regla): RedirectResponse
    {
        $datos = $this->validar($request);

        $this->rechazarDuplicada($datos, $regla->id);

        $regla->update($datos);

        return back()->with('exito', 'Regla actualizada.');
    }

    public function destroy(ReglaHorario $regla): RedirectResponse
    {
        $regla->delete();

        return back()->with('exito', 'Regla eliminada.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request): array
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'ciclo_id' => ['nullable', 'integer', Rule::exists('ciclos', 'id')->whereNull('deleted_at')],
            'campus_id' => ['nullable', 'integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],

            'dias' => ['required', 'array', 'min:1'],
            'dias.*' => ['integer', 'between:1,7'],
            'hora_apertura' => ['required', 'date_format:H:i'],
            'hora_cierre' => ['required', 'date_format:H:i', 'after:hora_apertura'],
            'minutos_bloque' => ['required', 'integer', 'min:15', 'max:240'],

            'bloques_min_por_sesion' => ['required', 'integer', 'min:1', 'max:8'],
            'bloques_max_por_sesion' => ['required', 'integer', 'min:1', 'max:8', 'gte:bloques_min_por_sesion'],
            'max_sesiones_por_dia' => ['required', 'integer', 'min:1', 'max:5'],

            'horas_max_dia_docente' => ['nullable', 'integer', 'min:1', 'max:24'],
            'horas_max_semana_docente' => ['nullable', 'integer', 'min:1', 'max:80'],
            'minutos_descanso_docente' => ['required', 'integer', 'min:0', 'max:180'],

            'reparto' => ['required', Rule::in(ReglaHorario::REPARTOS)],
            'permite_huecos_grupo' => ['boolean'],
            'activa' => ['boolean'],
        ], [
            'hora_cierre.after' => 'La escuela no puede cerrar antes de abrir.',
            'bloques_max_por_sesion.gte' => 'La sesión más larga no puede ser menor que la más corta.',
            'dias.min' => 'Elige al menos un día con clases.',
        ], [
            'dias' => 'días',
            'hora_apertura' => 'hora de apertura',
            'hora_cierre' => 'hora de cierre',
            'minutos_bloque' => 'duración del bloque',
        ]);

        $this->rechazarJornadaImposible($datos);

        return $datos;
    }

    /**
     * Una jornada donde no cabe ni una clase.
     *
     * Abrir de 7 a 8 con bloques de 90 minutos pasa todas las validaciones de
     * campo y produce cero huecos: el generador no colocaría nada y el motivo
     * —«no hay huecos»— mandaría a revisar la disponibilidad de los docentes,
     * que no tiene nada que ver. Se detiene aquí, donde sí se puede explicar.
     *
     * @param  array<string, mixed>  $datos
     */
    private function rechazarJornadaImposible(array $datos): void
    {
        $minutos = ReglaHorario::minutosEntre($datos['hora_apertura'], $datos['hora_cierre']);
        $caben = intdiv($minutos, (int) $datos['minutos_bloque']);

        AvisoParaElUsuario::si(
            $caben < 1,
            422,
            'Con esa jornada no cabe ni una clase: son '.$minutos.' minutos y cada bloque dura '
                .$datos['minutos_bloque'].'.',
        );

        AvisoParaElUsuario::si(
            $caben < (int) $datos['bloques_max_por_sesion'],
            422,
            "En la jornada caben {$caben} bloques, pero la sesión más larga pide "
                .$datos['bloques_max_por_sesion'].'. Ninguna clase de ese tamaño entraría.',
        );
    }

    /**
     * Dos reglas para el mismo alcance.
     *
     * La base de datos ya lo impide con un índice único, pero el error de MySQL
     * no le dice a nadie qué hacer. Aquí se explica cuál es la que ya existe.
     *
     * @param  array<string, mixed>  $datos
     */
    private function rechazarDuplicada(array $datos, ?int $exceptoId): void
    {
        $existe = ReglaHorario::query()
            ->when($datos['ciclo_id'] ?? null,
                fn ($q, $id) => $q->where('ciclo_id', $id),
                fn ($q) => $q->whereNull('ciclo_id'),
            )
            ->when($datos['campus_id'] ?? null,
                fn ($q, $id) => $q->where('campus_id', $id),
                fn ($q) => $q->whereNull('campus_id'),
            )
            ->when($exceptoId !== null, fn ($q) => $q->whereKeyNot($exceptoId))
            ->first();

        AvisoParaElUsuario::si(
            $existe !== null,
            422,
            "Ya hay una regla para ese alcance: «{$existe?->nombre}». Edítala en vez de crear otra.",
        );
    }
}
