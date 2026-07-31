<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\ConceptoPlan;
use App\Models\Finanzas\PagoAdeudo;
use App\Models\Finanzas\PlanCobro;
use App\Models\Finanzas\PlanCobroAlumno;
use App\Models\Finanzas\ReglaRecargo;
use App\Models\Landlord\NivelEstudio;
use App\Services\ExpansorColegiaturas;
use App\Services\GeneradorAdeudos;
use App\Services\ResolutorPlanCobro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El esquema de cobro, en dos pasos.
 *
 * **Paso 1 (alcance).** Nombre → ciclo → campus de ese ciclo → carreras que se
 * ofertan en esos campus (con filtro de nivel) → si hay fecha límite y desde
 * cuándo corre la mora → si admite recargos → si vuelve deudor al alumno.
 *
 * **Paso 2 (conceptos).** Las líneas que se van a cobrar. Una colegiatura se
 * captura por RANGO y se expande sola; lo demás son cargos sueltos con su fecha.
 *
 * Se partió en dos porque el paso 2 necesita saber el ciclo y si el plan admite
 * recargos: preguntarlo todo junto obligaba a habilitar y deshabilitar medio
 * formulario mientras se captura.
 */
class PlanCobroController extends Controller
{
    public function index(): Response
    {
        $planes = PlanCobro::query()
            ->with(['ciclo:id,nombre', 'campus:id,nombre', 'carreras:id,nombre'])
            ->withCount([
                'conceptos',
                'asignaciones as asignaciones_count' => fn ($q) => $q->where('estatus', PlanCobroAlumno::ACTIVO),
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (PlanCobro $p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'ciclo' => $p->ciclo?->nombre,
                'campus' => $p->campus->pluck('nombre')->all(),
                'carreras' => $p->carreras->pluck('nombre')->all(),
                'conceptos' => $p->conceptos_count,
                'alumnos' => $p->asignaciones_count,
                'aplica_recargos' => $p->aplica_recargos,
                'afecta_estatus_deudor' => $p->afecta_estatus_deudor,
                'vigente_desde' => $p->vigente_desde?->toDateString(),
                'vigente_hasta' => $p->vigente_hasta?->toDateString(),
                'vigente' => $p->vigente_hasta === null || $p->vigente_hasta->isFuture(),
                // Un plan que ya tocó a alguien no se borra: se le pone fecha de
                // fin. Se calcula aquí para que el botón lo diga antes de
                // intentarlo, en vez de fallar al hacer clic.
                'puede_eliminar' => $p->asignaciones_count === 0 && ! $this->tieneCargos($p),
                'motivo_no_eliminar' => $p->asignaciones_count > 0
                    ? "Está vinculado a {$p->asignaciones_count} alumno(s)"
                    : ($this->tieneCargos($p) ? 'Ya emitió cargos a alumnos' : null),
            ]);

        return Inertia::render('Finanzas/Planes/Index', [
            'planes' => $planes,
        ]);
    }

    /** ¿Este plan ya emitió cargos a alguien? */
    private function tieneCargos(PlanCobro $plan): bool
    {
        return Adeudo::whereIn('concepto_plan_id', $plan->conceptos()->select('id'))->exists();
    }

    /** Paso 1 del wizard. */
    public function create(): Response
    {
        return Inertia::render('Finanzas/Planes/Nuevo', [
            'ciclos' => Ciclo::orderByDesc('fecha_inicio')->get(['id', 'nombre', 'fecha_inicio', 'fecha_fin']),
            'niveles' => NivelEstudio::query()->orderBy('orden')->get(['id', 'nombre']),
        ]);
    }

    /**
     * Campus ligados a un ciclo. Si el ciclo es global (sin pivote), se ofrecen
     * todos: "global" significa que aplica en toda la escuela.
     */
    public function campusDelCiclo(Ciclo $ciclo): JsonResponse
    {
        $campus = $ciclo->campus()->orderBy('nombre')->get(['campus.id', 'campus.nombre']);

        if ($campus->isEmpty()) {
            $campus = Campus::orderBy('nombre')->get(['id', 'nombre']);
        }

        return response()->json($campus);
    }

    /**
     * Carreras realmente ofertadas en esos campus, opcionalmente acotadas por
     * nivel. Ofrecer carreras que no se imparten ahí solo produce planes que no
     * le tocan a nadie.
     */
    public function carrerasDeCampus(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'campus' => ['required', 'array', 'min:1'],
            'campus.*' => ['integer'],
            'nivel_estudios_id' => ['nullable', 'integer'],
        ]);

        $carreraIds = Oferta::query()
            ->whereIn('campus_id', $datos['campus'])
            ->distinct()
            ->pluck('carrera_id');

        $carreras = Carrera::query()
            ->whereIn('id', $carreraIds)
            ->when(
                ! empty($datos['nivel_estudios_id']),
                fn ($q) => $q->where('nivel_estudios_id', $datos['nivel_estudios_id'])
            )
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'clave', 'nivel_estudios_id']);

        return response()->json($carreras);
    }

    /** Guarda el paso 1 y manda al paso 2. */
    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validarAlcance($request);

        $plan = PlanCobro::create($this->camposDelPlan($datos));

        $this->sincronizarAlcance($plan, $datos);

        return redirect()
            ->route('tenant.finanzas.planes.show', $plan)
            ->with('exito', 'Plan creado. Ahora agrégale los conceptos que va a cobrar.');
    }

    /** Paso 2: conceptos, recargos y asignación. */
    public function show(PlanCobro $plan, ResolutorPlanCobro $resolutor): Response
    {
        $plan->load([
            'ciclo:id,nombre,fecha_inicio,fecha_fin',
            'campus:id,nombre',
            'carreras:id,nombre',
            'conceptos.concepto:id,nombre',
        ]);

        return Inertia::render('Finanzas/Planes/Detalle', [
            'plan' => [
                'id' => $plan->id,
                'nombre' => $plan->nombre,
                'ciclo' => $plan->ciclo?->nombre,
                'ciclo_id' => $plan->ciclo_id,
                'ciclo_inicio' => $plan->ciclo?->fecha_inicio?->toDateString(),
                'campus' => $plan->campus->pluck('nombre')->all(),
                'carreras' => $plan->carreras->pluck('nombre')->all(),
                'tiene_fecha_limite' => $plan->tiene_fecha_limite,
                'fecha_limite_modo' => $plan->fecha_limite_modo,
                'aplica_recargos' => $plan->aplica_recargos,
                'afecta_estatus_deudor' => $plan->afecta_estatus_deudor,
                'vigente_desde' => $plan->vigente_desde?->toDateString(),
                'vigente_hasta' => $plan->vigente_hasta?->toDateString(),
            ],
            'conceptos' => $plan->conceptos->map(fn (ConceptoPlan $c) => [
                'id' => $c->id,
                'concepto' => $c->concepto?->nombre,
                'concepto_id' => $c->concepto_id,
                'tipo_pago' => $c->tipo_pago,
                'descripcion' => $c->descripcion,
                'monto' => (float) $c->monto,
                'periodo' => $c->periodoEtiqueta(),
                'fecha_limite' => $c->fecha_limite?->toDateString(),
                'aplica_recargos' => $c->aplica_recargos,
                'grupo' => $c->grupo_colegiatura,
                'emitidos' => Adeudo::where('concepto_plan_id', $c->id)->count(),
            ])->values(),
            'catalogoConceptos' => ConceptoPago::orderBy('nombre')->get(['id', 'clave', 'nombre']),
            'tiposPago' => collect(ConceptoPlan::TIPOS)->map(fn ($t, $v) => ['valor' => $v, 'etiqueta' => $t])->values(),
            'cadencias' => collect(ExpansorColegiaturas::CADENCIAS)->map(fn ($t, $v) => ['valor' => $v, 'etiqueta' => $t])->values(),
            'reglaRecargo' => $plan->reglaRecargoBase(),
            // Excepciones por línea, indexadas por concepto_plan_id para que la
            // UI sepa cuáles ya tienen override sin recorrer nada.
            'overridesRecargo' => $plan->reglasRecargo()
                ->whereNotNull('concepto_plan_id')
                ->get()
                ->keyBy('concepto_plan_id'),
            'asignados' => $plan->asignaciones()->activos()->count(),
            'candidatos' => $resolutor->candidatos($plan)->map(fn ($m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'nombre' => $m->persona?->nombreCompleto(),
                'carrera' => $m->oferta?->carrera?->nombre,
                'campus' => $m->oferta?->campus?->nombre,
            ])->values(),
        ]);
    }

    public function update(Request $request, PlanCobro $plan): RedirectResponse
    {
        $datos = $this->validarAlcance($request);

        $plan->update($this->camposDelPlan($datos));

        $this->sincronizarAlcance($plan, $datos);

        // Si el plan deja de admitir recargos, ninguna línea puede conservarlos.
        if (! $plan->aplica_recargos) {
            $plan->conceptos()->update(['aplica_recargos' => false]);
        }

        return back()->with('exito', 'Plan actualizado.');
    }

    /**
     * Eliminar un plan solo se permite mientras no haya tocado a nadie. Un plan
     * que ya cobró es parte del historial financiero del alumno: si ya no debe
     * usarse se le pone fecha de fin, no se borra.
     */
    public function destroy(PlanCobro $plan): RedirectResponse
    {
        $lineas = $plan->conceptos()->pluck('id');

        // Dinero recibido: es lo más grave, se revisa primero.
        $conPagos = PagoAdeudo::query()
            ->whereIn('adeudo_id', Adeudo::whereIn('concepto_plan_id', $lineas)->select('id'))
            ->exists();

        if ($conPagos) {
            return back()->with('error', 'No se puede eliminar: ya tiene pagos aplicados. Ponle fecha de fin para que deje de aplicar.');
        }

        $emitidos = Adeudo::whereIn('concepto_plan_id', $lineas)->count();

        if ($emitidos > 0) {
            return back()->with('error', "No se puede eliminar: ya emitió {$emitidos} cargo(s) a alumnos. Ponle fecha de fin para que deje de aplicar.");
        }

        $alumnos = $plan->asignaciones()->activos()->count();

        if ($alumnos > 0) {
            return back()->with('error', "No se puede eliminar: está vinculado a {$alumnos} alumno(s). Quítaselos primero.");
        }

        $plan->delete();

        return redirect()->route('tenant.finanzas.planes.index')->with('exito', 'Plan eliminado.');
    }

    // ---------- Conceptos (paso 2) ----------

    /** Un cargo suelto: inscripción, credencial, gastos administrativos… */
    public function guardarConcepto(Request $request, PlanCobro $plan): RedirectResponse
    {
        $datos = $request->validate([
            'concepto_id' => ['required', 'integer', Rule::exists('conceptos_pago', 'id')],
            'tipo_pago' => ['required', Rule::in(array_keys(ConceptoPlan::TIPOS))],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'min:0'],
            'mes_referencia' => ['nullable', 'integer', 'min:1', 'max:12'],
            'anio_referencia' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'fecha_limite' => ['nullable', 'date'],
            'aplica_recargos' => ['boolean'],
        ]);

        ConceptoPlan::create($datos + [
            'plan_cobro_id' => $plan->id,
        ] + [
            // El plan manda: si no admite recargos, la línea tampoco.
            'aplica_recargos' => $plan->aplica_recargos && ($datos['aplica_recargos'] ?? false),
            'fecha_limite' => $plan->tiene_fecha_limite ? ($datos['fecha_limite'] ?? null) : null,
            'orden' => (int) $plan->conceptos()->max('orden') + 1,
        ]);

        return back()->with('exito', 'Concepto agregado.');
    }

    /** Un rango de colegiaturas, que se expande en N líneas. */
    public function guardarColegiaturas(Request $request, PlanCobro $plan, ExpansorColegiaturas $expansor): RedirectResponse
    {
        $datos = $request->validate([
            'concepto_id' => ['required', 'integer', Rule::exists('conceptos_pago', 'id')],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'min:0'],
            'desde' => ['required', 'date'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:60'],
            'cadencia' => ['required', Rule::in(array_keys(ExpansorColegiaturas::CADENCIAS))],
            'dia_limite' => ['nullable', 'integer', 'min:1', 'max:31'],
            'aplica_recargos' => ['boolean'],
        ]);

        $creadas = $expansor->crear($plan, $datos);

        return back()->with('exito', "Se agregaron {$creadas} colegiaturas.");
    }

    public function eliminarConcepto(PlanCobro $plan, ConceptoPlan $concepto): RedirectResponse
    {
        abort_unless($concepto->plan_cobro_id === $plan->id, 404);

        if (Adeudo::where('concepto_plan_id', $concepto->id)->exists()) {
            return back()->with('error', 'No se puede eliminar: ya generó cargos a alumnos.');
        }

        $concepto->delete();

        return back()->with('exito', 'Concepto eliminado.');
    }

    /** Borra un bloque completo de colegiaturas (las que se crearon juntas). */
    public function eliminarGrupo(PlanCobro $plan, string $grupo): RedirectResponse
    {
        $lineas = $plan->conceptos()->where('grupo_colegiatura', $grupo)->pluck('id');

        if (Adeudo::whereIn('concepto_plan_id', $lineas)->exists()) {
            return back()->with('error', 'No se puede eliminar el bloque: ya generó cargos.');
        }

        ConceptoPlan::whereIn('id', $lineas)->delete();

        return back()->with('exito', 'Bloque de colegiaturas eliminado.');
    }

    // ---------- Recargos ----------

    public function guardarReglaRecargo(Request $request, PlanCobro $plan): RedirectResponse
    {
        $datos = $request->validate([
            'modo' => ['required', Rule::in([ReglaRecargo::MODO_MONTO_FIJO, ReglaRecargo::MODO_PORCENTAJE])],
            'valor' => ['required', 'numeric', 'min:0'],
            'frecuencia' => ['required', Rule::in([ReglaRecargo::FRECUENCIA_UNICA, ReglaRecargo::FRECUENCIA_MENSUAL])],
            'dias_gracia' => ['required', 'integer', 'min:0', 'max:90'],
            'tope_monto' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! $plan->aplica_recargos) {
            return back()->with('error', 'Este plan no admite recargos. Actívalos primero en el alcance.');
        }

        ReglaRecargo::updateOrCreate(
            ['plan_cobro_id' => $plan->id, 'concepto_plan_id' => null],
            $datos + ['activo' => true],
        );

        return back()->with('exito', 'Regla de recargo guardada.');
    }

    /**
     * Excepción de recargo para una línea concreta. Existe porque no todo se
     * recarga igual: una escuela puede penalizar fuerte la colegiatura y
     * suavemente la credencial. Sin override, la línea usa la regla del plan.
     */
    public function guardarRecargoConcepto(Request $request, PlanCobro $plan, ConceptoPlan $concepto): RedirectResponse
    {
        abort_unless($concepto->plan_cobro_id === $plan->id, 404);

        $datos = $request->validate([
            'modo' => ['required', Rule::in([ReglaRecargo::MODO_MONTO_FIJO, ReglaRecargo::MODO_PORCENTAJE])],
            'valor' => ['required', 'numeric', 'min:0'],
            'frecuencia' => ['required', Rule::in([ReglaRecargo::FRECUENCIA_UNICA, ReglaRecargo::FRECUENCIA_MENSUAL])],
            'dias_gracia' => ['required', 'integer', 'min:0', 'max:90'],
            'tope_monto' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! $plan->aplica_recargos) {
            return back()->with('error', 'Este plan no admite recargos.');
        }

        if (! $concepto->aplica_recargos) {
            return back()->with('error', 'Ese concepto está marcado sin recargos: actívaselos antes de darle una excepción.');
        }

        ReglaRecargo::updateOrCreate(
            ['plan_cobro_id' => $plan->id, 'concepto_plan_id' => $concepto->id],
            $datos + ['activo' => true],
        );

        return back()->with('exito', 'Excepción de recargo guardada.');
    }

    /** Quita la excepción: la línea vuelve a la regla del plan. */
    public function eliminarRecargoConcepto(PlanCobro $plan, ConceptoPlan $concepto): RedirectResponse
    {
        abort_unless($concepto->plan_cobro_id === $plan->id, 404);

        ReglaRecargo::where('plan_cobro_id', $plan->id)
            ->where('concepto_plan_id', $concepto->id)
            ->delete();

        return back()->with('exito', 'Excepción eliminada: esa línea vuelve a la regla del plan.');
    }

    // ---------- Asignación masiva ----------

    public function asignar(Request $request, PlanCobro $plan, GeneradorAdeudos $generador): RedirectResponse
    {
        $datos = $request->validate([
            'matriculas' => ['required', 'array', 'min:1'],
            'matriculas.*' => ['integer'],
        ]);

        if ($plan->conceptos()->count() === 0) {
            return back()->with('error', 'El plan no cobra nada todavía: agrégale conceptos antes de asignarlo.');
        }

        $r = $generador->asignarPlan($plan, $datos['matriculas']);

        return back()->with(
            'exito',
            "Plan asignado a {$r['asignados']} alumno(s); se generaron {$r['cargos']} cargo(s)."
        );
    }

    public function quitarAsignacion(PlanCobro $plan, PlanCobroAlumno $asignacion): RedirectResponse
    {
        abort_unless($asignacion->plan_cobro_id === $plan->id, 404);

        $asignacion->update(['estatus' => PlanCobroAlumno::CANCELADO]);

        return back()->with('exito', 'Se quitó el plan al alumno. Sus cargos ya emitidos se conservan.');
    }

    // ---------- Apoyos ----------

    /**
     * Campos del plan, ya normalizados.
     *
     * Sin fecha límite no puede haber mora, así que `aplica_recargos` se apaga
     * aunque venga marcado: la UI ya lo deshabilita, pero la regla tiene que
     * vivir en el servidor —si no, un POST directo dejaría un plan que recarga
     * cargos que nunca vencen.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function camposDelPlan(array $datos): array
    {
        $conLimite = (bool) ($datos['tiene_fecha_limite'] ?? true);

        return [
            'nombre' => $datos['nombre'],
            'ciclo_id' => $datos['ciclo_id'],
            'moneda' => 'MXN',
            'tiene_fecha_limite' => $conLimite,
            'fecha_limite_modo' => $datos['fecha_limite_modo'],
            'aplica_recargos' => $conLimite && (bool) ($datos['aplica_recargos'] ?? false),
            'afecta_estatus_deudor' => (bool) ($datos['afecta_estatus_deudor'] ?? false),
            'vigente_desde' => $datos['vigente_desde'],
            'vigente_hasta' => $datos['vigente_hasta'] ?: null,
        ];
    }

    /** @return array<string, mixed> */
    private function validarAlcance(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')],
            'campus' => ['required', 'array', 'min:1'],
            'campus.*' => ['integer', Rule::exists('campus', 'id')],
            'carreras' => ['array'],
            'carreras.*' => ['integer', Rule::exists('carreras', 'id')],
            'tiene_fecha_limite' => ['boolean'],
            'fecha_limite_modo' => ['required', Rule::in([PlanCobro::LIMITE_EXACTA, PlanCobro::LIMITE_DIA_SIGUIENTE])],
            'aplica_recargos' => ['boolean'],
            'afecta_estatus_deudor' => ['boolean'],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
        ]);
    }

    /** @param  array<string, mixed>  $datos */
    private function sincronizarAlcance(PlanCobro $plan, array $datos): void
    {
        $plan->campus()->sync($datos['campus']);

        // Se guarda el nivel de cada carrera para poder reportar por nivel sin
        // volver a cruzar con el catálogo.
        $niveles = Carrera::whereIn('id', $datos['carreras'] ?? [])->pluck('nivel_estudios_id', 'id');

        $plan->carreras()->sync(
            collect($datos['carreras'] ?? [])
                ->mapWithKeys(fn (int $id) => [$id => ['nivel_estudios_id' => $niveles[$id] ?? null]])
                ->all()
        );
    }
}
