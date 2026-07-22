<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\PlanCobro;
use App\Models\Finanzas\ReglaGeneracion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El motor de cobro, configurado desde pantalla.
 *
 * Un plan de cobro es "a quién se le cobra" (global, una carrera, un plan de
 * estudios o una oferta) y sus reglas son "qué y cada cuándo". La idea de la
 * spec es que "semanal sin inscripción" o "mensual con inscripción" sean DATOS
 * y no ramas del código; esta pantalla es lo que hace que esa promesa se pueda
 * cumplir sin tocar el repositorio.
 */
class PlanCobroController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Finanzas/Planes/Index', [
            'planes' => PlanCobro::query()
                ->withCount('reglas')
                ->orderBy('aplica_a_tipo')
                ->orderByDesc('vigente_desde')
                ->get()
                ->map(fn (PlanCobro $p) => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'moneda' => $p->moneda,
                    'aplica_a_tipo' => $p->aplica_a_tipo,
                    'destinatario' => $this->nombreDelDestinatario($p),
                    'vigente_desde' => $p->vigente_desde?->toDateString(),
                    'vigente_hasta' => $p->vigente_hasta?->toDateString(),
                    'vigente' => $this->estaVigente($p),
                    'reglas_count' => $p->reglas_count,
                ]),
            'destinos' => $this->destinos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $plan = PlanCobro::create($datos);

        return redirect("/finanzas/planes/{$plan->id}")->with('exito', 'Plan de cobro creado. Agrégale sus reglas.');
    }

    public function show(PlanCobro $plan): Response
    {
        $plan->load('reglas.concepto', 'reglas.conceptoPrerequisito');

        return Inertia::render('Finanzas/Planes/Detalle', [
            'plan' => [
                'id' => $plan->id,
                'nombre' => $plan->nombre,
                'moneda' => $plan->moneda,
                'aplica_a_tipo' => $plan->aplica_a_tipo,
                'aplica_a_id' => $plan->aplica_a_id,
                'destinatario' => $this->nombreDelDestinatario($plan),
                'vigente_desde' => $plan->vigente_desde?->toDateString(),
                'vigente_hasta' => $plan->vigente_hasta?->toDateString(),
                'vigente' => $this->estaVigente($plan),
            ],
            'reglas' => $plan->reglas->map(fn (ReglaGeneracion $r) => [
                'id' => $r->id,
                'concepto' => $r->concepto?->nombre,
                'concepto_id' => $r->concepto_id,
                'periodicidad' => $r->periodicidad,
                'monto_base' => (float) $r->monto_base,
                'dia_generacion' => $r->dia_generacion,
                'dia_limite' => $r->dia_limite,
                'obligatorio' => $r->obligatorio,
                'num_parcialidades' => $r->num_parcialidades,
                'prorratea' => $r->prorratea,
                'prerequisito' => $r->conceptoPrerequisito?->nombre,
                'concepto_prerequisito_id' => $r->concepto_prerequisito_id,
                // Cuántos cargos ha emitido ya: es lo que decide si una regla
                // se puede borrar o solo apagar.
                'adeudos' => Adeudo::query()->where('regla_id', $r->id)->count(),
            ])->values(),
            'conceptos' => ConceptoPago::query()->orderBy('nombre')->get(['id', 'clave', 'nombre']),
            'periodicidades' => [
                ['valor' => ReglaGeneracion::PERIODICIDAD_UNICO, 'etiqueta' => 'Único'],
                ['valor' => ReglaGeneracion::PERIODICIDAD_SEMANAL, 'etiqueta' => 'Semanal'],
                ['valor' => ReglaGeneracion::PERIODICIDAD_QUINCENAL, 'etiqueta' => 'Quincenal'],
                ['valor' => ReglaGeneracion::PERIODICIDAD_MENSUAL, 'etiqueta' => 'Mensual'],
                ['valor' => ReglaGeneracion::PERIODICIDAD_POR_CICLO, 'etiqueta' => 'Por ciclo escolar'],
                ['valor' => ReglaGeneracion::PERIODICIDAD_POR_MATERIA, 'etiqueta' => 'Por materia inscrita'],
            ],
            'destinos' => $this->destinos(),
        ]);
    }

    public function update(Request $request, PlanCobro $plan): RedirectResponse
    {
        $plan->update($this->validar($request));

        return back()->with('exito', 'Plan de cobro actualizado.');
    }

    /**
     * Un plan que ya emitió cargos no se borra: sus adeudos apuntan a las
     * reglas que cuelgan de él y perderlos dejaría el estado de cuenta de los
     * alumnos sin explicación de dónde salió cada cargo. Se le pone fecha de
     * fin, que es como se retira un esquema de cobro en la vida real.
     */
    public function destroy(PlanCobro $plan): RedirectResponse
    {
        $emitidos = Adeudo::query()
            ->whereIn('regla_id', $plan->reglas()->pluck('id'))
            ->count();

        if ($emitidos > 0) {
            return back()->with(
                'error',
                "Este plan ya emitió {$emitidos} cargos. No se borra: ponle fecha de fin para retirarlo."
            );
        }

        $plan->delete();

        return redirect('/finanzas/planes')->with('exito', 'Plan de cobro eliminado.');
    }

    public function guardarRegla(Request $request, PlanCobro $plan): RedirectResponse
    {
        $datos = $this->validarRegla($request);

        $plan->reglas()->create($datos);

        return back()->with('exito', 'Regla agregada.');
    }

    public function actualizarRegla(Request $request, PlanCobro $plan, ReglaGeneracion $regla): RedirectResponse
    {
        abort_unless($regla->plan_cobro_id === $plan->id, 404);

        $regla->update($this->validarRegla($request));

        // Cambiar el monto NO reescribe los cargos ya emitidos: un adeudo es lo
        // que se le cobró al alumno ese mes, no una vista de la regla. Se avisa
        // para que nadie espere que el histórico se ajuste solo.
        $emitidos = Adeudo::query()->where('regla_id', $regla->id)->count();

        return $emitidos > 0
            ? back()->with('advertencia', "Regla actualizada. Los {$emitidos} cargos ya emitidos conservan su monto original; el cambio aplica a los siguientes.")
            : back()->with('exito', 'Regla actualizada.');
    }

    public function eliminarRegla(PlanCobro $plan, ReglaGeneracion $regla): RedirectResponse
    {
        abort_unless($regla->plan_cobro_id === $plan->id, 404);

        $emitidos = Adeudo::query()->where('regla_id', $regla->id)->count();

        if ($emitidos > 0) {
            return back()->with(
                'error',
                "Esta regla ya emitió {$emitidos} cargos y no se puede borrar. "
                .'Retira el plan con una fecha de fin si ya no debe cobrar.'
            );
        }

        $regla->delete();

        return back()->with('exito', 'Regla eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request): array
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'moneda' => ['required', 'string', 'size:3'],
            'aplica_a_tipo' => ['required', Rule::in([
                PlanCobro::APLICA_GLOBAL,
                PlanCobro::APLICA_CARRERA,
                PlanCobro::APLICA_PLAN,
                PlanCobro::APLICA_OFERTA,
            ])],
            'aplica_a_id' => ['nullable', 'integer'],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
        ]);

        // Un plan global no apunta a nada; uno acotado tiene que decir a qué.
        // Dejar el id puesto al cambiar a global convertiría el plan en algo
        // que no aplica a nadie y que nadie entendería al leerlo.
        if ($datos['aplica_a_tipo'] === PlanCobro::APLICA_GLOBAL) {
            $datos['aplica_a_id'] = null;
        } elseif (($datos['aplica_a_id'] ?? null) === null) {
            abort(422, 'Un plan acotado necesita a qué carrera, plan u oferta aplica.');
        }

        return $datos;
    }

    /**
     * @return array<string, mixed>
     */
    private function validarRegla(Request $request): array
    {
        return $request->validate([
            'concepto_id' => ['required', Rule::exists('conceptos_pago', 'id')],
            'periodicidad' => ['required', Rule::in([
                ReglaGeneracion::PERIODICIDAD_UNICO,
                ReglaGeneracion::PERIODICIDAD_SEMANAL,
                ReglaGeneracion::PERIODICIDAD_QUINCENAL,
                ReglaGeneracion::PERIODICIDAD_MENSUAL,
                ReglaGeneracion::PERIODICIDAD_POR_CICLO,
                ReglaGeneracion::PERIODICIDAD_POR_MATERIA,
            ])],
            'monto_base' => ['required', 'numeric', 'min:0'],
            // El día se valida contra 31 y no contra el mes: la regla es anual
            // y el generador ya recorta al último día real de cada mes.
            'dia_generacion' => ['nullable', 'integer', 'min:1', 'max:31'],
            'dia_limite' => ['nullable', 'integer', 'min:1', 'max:31'],
            'obligatorio' => ['boolean'],
            'num_parcialidades' => ['nullable', 'integer', 'min:2', 'max:36'],
            'prorratea' => ['boolean'],
            'concepto_prerequisito_id' => ['nullable', Rule::exists('conceptos_pago', 'id')],
        ]);
    }

    /**
     * A quién se le puede acotar un plan. Se mandan las tres listas de una vez
     * porque el selector cambia de contenido al cambiar el tipo, y pedirlas por
     * separado dejaría el desplegable vacío el primer instante.
     *
     * @return array<string, mixed>
     */
    private function destinos(): array
    {
        return [
            'carrera' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre']),
            'plan' => PlanEstudio::query()->with('carrera:id,nombre')->orderBy('nombre')->get()
                ->map(fn (PlanEstudio $p) => [
                    'id' => $p->id,
                    'nombre' => $p->clave.' · '.$p->nombre.' ('.($p->carrera?->nombre ?? '—').')',
                ]),
            'oferta' => Oferta::query()->with(['carrera:id,nombre', 'campus:id,nombre'])->get()
                ->map(fn (Oferta $o) => [
                    'id' => $o->id,
                    'nombre' => ($o->carrera?->nombre ?? '—').' · '.($o->campus?->nombre ?? '—').' · '.$o->modalidad,
                ]),
        ];
    }

    private function nombreDelDestinatario(PlanCobro $plan): string
    {
        if ($plan->aplica_a_tipo === PlanCobro::APLICA_GLOBAL) {
            return 'Toda la escuela';
        }

        $destinatario = $plan->destinatario();

        return $destinatario?->nombre ?? 'No encontrado (#'.$plan->aplica_a_id.')';
    }

    private function estaVigente(PlanCobro $plan): bool
    {
        $hoy = now()->startOfDay();

        return $plan->vigente_desde !== null
            && $plan->vigente_desde->lte($hoy)
            && ($plan->vigente_hasta === null || $plan->vigente_hasta->gte($hoy));
    }
}
