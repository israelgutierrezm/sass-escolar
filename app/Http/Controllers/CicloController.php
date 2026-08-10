<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Academico\Campus;
use App\Models\Academico\NivelEstudio;
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
        $busqueda = trim((string) $request->query('busqueda', ''));
        $situacion = $request->query('situacion');
        $campus = $request->query('campus');
        $nivel = $request->query('nivel');
        $abiertos = $request->query('abiertos') === '1';

        $hoy = now()->toDateString();
        $alcance = $this->alcance($request);

        return Inertia::render('ControlEscolar/Ciclos/Index', [
            'ciclos' => Ciclo::query()
                ->with(['campus:id,nombre', 'situacion:id,nombre', 'niveles:id,nombre'])
                ->withCount('grupos')
                ->delAlcance($alcance)
                ->when($busqueda !== '', fn ($q) => $q->where(fn ($sub) => $sub
                    ->where('clave', 'like', "%{$busqueda}%")
                    ->orWhere('nombre', 'like', "%{$busqueda}%")))
                ->when(filled($situacion), fn ($q) => $q->where('situacion_id', $situacion))
                /*
                 * Un ciclo SIN campus es global —vale para todos—, así que al
                 * filtrar por campus tiene que salir también. Dejarlo fuera
                 * escondería justo los ciclos que aplican a esa sede.
                 */
                ->when(filled($campus), fn ($q) => $q->where(fn ($sub) => $sub
                    ->whereHas('campus', fn ($c) => $c->where('campus.id', $campus))
                    ->orWhereDoesntHave('campus')))
                // Mismo criterio con los niveles: sin niveles = cualquier nivel.
                ->when(filled($nivel), fn ($q) => $q->where(fn ($sub) => $sub
                    ->whereHas('niveles', fn ($n) => $n->where('niveles_estudio.id', $nivel))
                    ->orWhereDoesntHave('niveles')))
                /*
                 * «Con inscripción abierta» en SQL, no en PHP: filtrar después
                 * de paginar daría páginas de tamaño irregular y un total que
                 * no corresponde a lo que se ve. Sin fechas capturadas la
                 * ventana no restringe, así que cuenta como abierta.
                 */
                ->when($abiertos, fn ($q) => $q->where(fn ($sub) => $sub
                    ->where(fn ($s) => $s->whereNull('inscripcion_desde')->orWhereNull('inscripcion_hasta'))
                    ->orWhere(fn ($s) => $s->where('inscripcion_desde', '<=', $hoy)->where('inscripcion_hasta', '>=', $hoy))))
                ->orderByDesc('fecha_inicio')
                ->paginate(10)
                ->withQueryString()
                ->through(fn (Ciclo $ciclo) => [
                    'id' => $ciclo->id,
                    'clave' => $ciclo->clave,
                    'nombre' => $ciclo->nombre,
                    'niveles' => $ciclo->niveles->pluck('nombre')->all(),
                    'campus' => $ciclo->campus->pluck('nombre')->all(),
                    'situacion' => $ciclo->situacion?->nombre,
                    'fecha_inicio' => $ciclo->fecha_inicio?->toDateString(),
                    'fecha_fin' => $ciclo->fecha_fin?->toDateString(),
                    'inscripcion_abierta' => $ciclo->inscripcionAbierta(),
                    'grupos_count' => $ciclo->grupos_count,
                ]),
            'filtros' => [
                'busqueda' => $busqueda,
                'situacion' => $situacion ?? '',
                'campus' => $campus ?? '',
                'nivel' => $nivel ?? '',
                'abiertos' => $abiertos ? '1' : '',
            ],
            /*
             * Las opciones de los desplegables. Los campus van acotados al
             * alcance del usuario: ofrecerle filtrar por una sede que no puede
             * ver le daria un listado vacio sin explicacion.
             */
            'opciones' => [
                'situaciones' => SituacionCiclo::query()->orderBy('nombre')->get(['id', 'nombre'])
                    ->map(fn ($s) => ['valor' => $s->id, 'texto' => $s->nombre]),
                'campus' => Campus::query()
                    ->when(is_array($alcance), fn ($q) => $q->whereIn('id', $alcance))
                    ->orderBy('nombre')->get(['id', 'nombre'])
                    ->map(fn ($c) => ['valor' => $c->id, 'texto' => $c->nombre]),
                'niveles' => NivelEstudio::query()->orderBy('nombre')->get(['id', 'nombre'])
                    ->map(fn ($n) => ['valor' => $n->id, 'texto' => $n->nombre]),
            ],
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
        $niveles = array_map('intval', $request->input('nivel_ids', []) ?? []);

        DB::transaction(function () use ($datos, $campus, $niveles): void {
            $ciclo = Ciclo::create($datos);
            $ciclo->campus()->sync($campus);
            $ciclo->niveles()->sync($niveles);
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
                ...$ciclo->only(['id', 'clave', 'anio', 'numero_periodo', 'nombre', 'situacion_id']),
                'nivel_ids' => $ciclo->niveles()->pluck('niveles_estudio.id')->all(),
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
        $niveles = array_map('intval', $request->input('nivel_ids', []) ?? []);

        DB::transaction(function () use ($ciclo, $datos, $campus, $niveles, $request): void {
            $ciclo->update($datos);

            // Un administrador acotado no puede desvincular campus que no ve:
            // se conservan los que quedan fuera de su alcance y solo se
            // sincronizan los suyos. Con alcance global se sincroniza todo.
            $fueraDeAlcance = $this->campusFueraDeAlcance($request, $ciclo);

            $ciclo->campus()->sync([...$campus, ...$fueraDeAlcance]);
            // Los niveles no tienen alcance por campus: se sincronizan directo.
            $ciclo->niveles()->sync($niveles);
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

        AvisoParaElUsuario::aMenosQue($suyos || $ciclo->esGlobal(), 403, 'Ese ciclo no pertenece a tus campus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        $validados = $request->validate([
            // El campus donde aplica es OBLIGATORIO: al menos uno. El pivote se
            // sincroniza aparte; aquí solo se valida su forma.
            'campus_ids' => ['required', 'array', 'min:1'],
            'campus_ids.*' => ['integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            // La clave ya NO se teclea: se arma de año + periodo.
            'anio' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2100'],
            'numero_periodo' => ['required', 'integer', 'between:1,4'],
            // Niveles opcionales: si hay alguno, acotan los grupos del ciclo a
            // esos niveles. Se sincronizan aparte (como el pivote de campus).
            'nivel_ids' => ['nullable', 'array'],
            'nivel_ids.*' => ['integer', Rule::exists('niveles_estudio', 'id')->whereNull('deleted_at')],
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
            'anio.digits' => 'El año debe tener 4 dígitos.',
            'numero_periodo.between' => 'El número de periodo va del 1 al 4.',
            'campus_ids.required' => 'Marca al menos un campus donde aplica el ciclo.',
            'campus_ids.min' => 'Marca al menos un campus donde aplica el ciclo.',
        ], [
            'campus_ids' => 'campus',
            'situacion_id' => 'situación',
            'fecha_inicio' => 'fecha de inicio',
            'fecha_fin' => 'fecha de fin',
            'numero_periodo' => 'número de periodo',
            'nivel_ids' => 'niveles de estudio',
        ]);

        // La clave se genera aquí: año + guión + periodo (2026-1). Y se valida
        // su unicidad a mano, porque no viene del formulario y no se puede
        // colgar una regla `unique` de un input inexistente.
        $validados['clave'] = $validados['anio'].'-'.$validados['numero_periodo'];

        $repetida = Ciclo::query()
            ->where('clave', $validados['clave'])
            ->when($id !== null, fn ($q) => $q->whereKeyNot($id))
            ->exists();

        if ($repetida) {
            throw ValidationException::withMessages([
                'numero_periodo' => "Ya existe el ciclo {$validados['clave']} en la escuela.",
            ]);
        }

        // El pivote de niveles y el de campus se sincronizan aparte; no son
        // columnas de la tabla.
        unset($validados['campus_ids'], $validados['nivel_ids']);

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
            // Para acotar (opcionalmente) el ciclo a un nivel de estudios.
            'niveles' => NivelEstudio::query()->orderBy('orden')->get(['id', 'nombre']),
            'alcanceAcotado' => $alcance !== null,
        ];
    }
}
