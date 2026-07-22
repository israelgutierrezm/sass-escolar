<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\SituacionCiclo;
use App\Models\Identidad\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
 * Un ciclo aplica a 1..N campus (pivote `ciclo_campus`). SIN campus asignado es
 * un ciclo global de la escuela.
 *
 * Todo lo que se ve y se guarda aquí está acotado por el alcance del rol
 * activo: quien administra dos campus no elige entre los cinco de la escuela ni
 * puede tocar ciclos ajenos. El alcance vive en `persona_rol.campus_id`.
 */
class CicloController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('ControlEscolar/Ciclos/Index', [
            'ciclos' => Ciclo::query()
                ->with(['campus:id,nombre', 'situacion:id,nombre'])
                ->withCount('grupos')
                ->delAlcance($this->alcance($request))
                ->orderByDesc('fecha_inicio')
                ->get()
                ->map(fn (Ciclo $ciclo) => [
                    'id' => $ciclo->id,
                    'clave' => $ciclo->clave,
                    'nombre' => $ciclo->nombre,
                    'campus' => $ciclo->campus->pluck('nombre')->all(),
                    'situacion' => $ciclo->situacion?->nombre,
                    'fecha_inicio' => $ciclo->fecha_inicio?->toDateString(),
                    'fecha_fin' => $ciclo->fecha_fin?->toDateString(),
                    'inscripcion_abierta' => $ciclo->inscripcionAbierta(),
                    'grupos_count' => $ciclo->grupos_count,
                ]),
            'puedeEditar' => $request->user()->can('abrir-grupos'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('ControlEscolar/Ciclos/Formulario', [
            'ciclo' => null,
            ...$this->catalogos($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $campus = $this->campusAutorizados($request);

        DB::transaction(function () use ($datos, $campus): void {
            Ciclo::create($datos)->campus()->sync($campus);
        });

        return redirect()->route('tenant.escolar.ciclos.index')->with('exito', 'Ciclo creado.');
    }

    public function edit(Request $request, Ciclo $ciclo): Response
    {
        $this->autorizarCiclo($request, $ciclo);

        /** @var Usuario $usuario */
        $usuario = $request->user();

        // Solo viajan al formulario los campus que este usuario puede tocar. Si
        // se mandaran todos, un administrador acotado abriría el ciclo, no
        // tocaría nada, guardaría, y le rebotaría un "campus fuera de tu
        // alcance" por un valor que él nunca eligió. Los demás se listan aparte
        // como contexto y se preservan solos al guardar.
        $suyos = $ciclo->campus()
            ->get(['campus.id', 'campus.nombre'])
            ->partition(fn ($c) => $usuario->alcanzaCampus($c->id));

        return Inertia::render('ControlEscolar/Ciclos/Formulario', [
            'ciclo' => [
                ...$ciclo->only(['id', 'clave', 'nombre', 'situacion_id']),
                'campus_ids' => $suyos[0]->pluck('id')->all(),
                'campus_ajenos' => $suyos[1]->pluck('nombre')->all(),
                'fecha_inicio' => $ciclo->fecha_inicio?->toDateString(),
                'fecha_fin' => $ciclo->fecha_fin?->toDateString(),
                'inscripcion_desde' => $ciclo->inscripcion_desde?->toDateString(),
                'inscripcion_hasta' => $ciclo->inscripcion_hasta?->toDateString(),
                'altas_bajas_hasta' => $ciclo->altas_bajas_hasta?->toDateString(),
                'captura_calif_hasta' => $ciclo->captura_calif_hasta?->toDateString(),
            ],
            ...$this->catalogos($request),
        ]);
    }

    public function update(Request $request, Ciclo $ciclo): RedirectResponse
    {
        $this->autorizarCiclo($request, $ciclo);

        $datos = $this->validar($request, $ciclo->id);
        $campus = $this->campusAutorizados($request);

        DB::transaction(function () use ($ciclo, $datos, $campus, $request): void {
            $ciclo->update($datos);

            // Un administrador acotado no puede desvincular campus que no ve:
            // se conservan los que quedan fuera de su alcance y solo se
            // sincronizan los suyos. Con alcance global se sincroniza todo.
            $fueraDeAlcance = $this->campusFueraDeAlcance($request, $ciclo);

            $ciclo->campus()->sync([...$campus, ...$fueraDeAlcance]);
        });

        return redirect()->route('tenant.escolar.ciclos.index')->with('exito', 'Ciclo actualizado.');
    }

    /**
     * Un ciclo con grupos no se elimina: de ellos cuelgan inscripciones e
     * historial. Para cerrarlo se cambia su situación.
     */
    public function destroy(Request $request, Ciclo $ciclo): RedirectResponse
    {
        $this->autorizarCiclo($request, $ciclo);

        if ($ciclo->grupos()->exists()) {
            return back()->with('error', 'No se puede eliminar: el ciclo tiene grupos. Ciérralo en su lugar.');
        }

        $ciclo->delete();

        return back()->with('exito', 'Ciclo eliminado.');
    }

    /*
    |--------------------------------------------------------------------------
    | Alcance por campus
    |--------------------------------------------------------------------------
    */

    /** @return array<int, int>|null */
    private function alcance(Request $request): ?array
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        return $usuario->campusVisibles();
    }

    /**
     * Campus enviados, filtrados contra el alcance del usuario. Un id ajeno no
     * se rechaza en silencio: se explica, porque suele ser el síntoma de que
     * alguien está viendo una pantalla que ya no corresponde a su rol.
     *
     * @return array<int, int>
     */
    private function campusAutorizados(Request $request): array
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $enviados = array_map('intval', $request->input('campus_ids', []) ?? []);
        $ajenos = array_values(array_filter($enviados, fn (int $id) => ! $usuario->alcanzaCampus($id)));

        if ($ajenos !== []) {
            throw ValidationException::withMessages([
                'campus_ids' => 'Hay campus seleccionados que están fuera de tu alcance.',
            ]);
        }

        return array_values(array_unique($enviados));
    }

    /**
     * Campus del ciclo que el usuario NO alcanza. Se preservan al guardar.
     *
     * @return array<int, int>
     */
    private function campusFueraDeAlcance(Request $request, Ciclo $ciclo): array
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        if ($usuario->campusVisibles() === null) {
            return [];
        }

        return $ciclo->campus()
            ->pluck('campus.id')
            ->reject(fn (int $id) => $usuario->alcanzaCampus($id))
            ->values()
            ->all();
    }

    /**
     * Un ciclo global lo administra cualquiera con el permiso; uno de campus,
     * solo quien alcance alguno de sus campus.
     */
    private function autorizarCiclo(Request $request, Ciclo $ciclo): void
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        if ($usuario->campusVisibles() === null) {
            return;
        }

        $suyos = $ciclo->campus()->pluck('campus.id')
            ->contains(fn (int $id) => $usuario->alcanzaCampus($id));

        abort_unless($suyos || $ciclo->esGlobal(), 403, 'Ese ciclo no pertenece a tus campus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        $validados = $request->validate([
            // El pivote se sincroniza aparte; aquí solo se valida su forma.
            'campus_ids' => ['present', 'array'],
            'campus_ids.*' => ['integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            'clave' => [
                'required', 'string', 'max:50',
                Rule::unique('ciclos', 'clave')->ignore($id)->whereNull('deleted_at'),
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
            'clave.unique' => 'Ya existe un ciclo con esa clave en la escuela.',
        ], [
            'campus_ids' => 'campus',
            'situacion_id' => 'situación',
            'fecha_inicio' => 'fecha de inicio',
            'fecha_fin' => 'fecha de fin',
        ]);

        unset($validados['campus_ids']);

        return $validados;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(Request $request): array
    {
        $alcance = $this->alcance($request);

        return [
            'campus' => Campus::query()
                ->when($alcance !== null, fn ($q) => $q->whereIn('id', $alcance))
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
            'situaciones' => SituacionCiclo::query()->orderBy('id')->get(['id', 'nombre']),
            'alcanceAcotado' => $alcance !== null,
        ];
    }
}
