<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Area;
use App\Models\Academico\Asignatura;
use App\Models\Academico\ClasificacionAsignatura;
use App\Models\Academico\Descriptor;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\TipoAsignatura;
use App\Models\ControlEscolar\AsignaturaGrupo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Malla curricular de un plan: qué asignaturas lo componen y cómo.
 *
 * `plan_materias` es el núcleo del modelo curricular. La asignatura viene del
 * catálogo, pero aquí se define lo que cambia entre planes: la clave que sale
 * en el acta, el periodo sugerido, si es obligatoria u optativa y —si difiere
 * del catálogo— sus créditos.
 *
 * Todo lo demás del sistema cuelga de aquí: los grupos abren una plan_materia,
 * el kárdex registra plan_materias y la seriación se define entre ellas.
 */
class PlanMateriaController extends Controller
{
    public function index(Request $request, PlanEstudio $plan): Response
    {
        $plan->load('carrera:id,nombre');

        $materias = PlanMateria::query()
            ->with(['asignatura:id,clave,nombre,creditos,tipo_asignatura_id', 'asignatura.tipoAsignatura:id,nombre'])
            ->where('plan_id', $plan->id)
            ->orderBy('periodo')
            ->orderBy('clave_en_plan')
            ->get();

        // Los créditos efectivos son los del plan si se sobreescribieron, o los
        // del catálogo si no.
        $creditosCargados = $materias->sum(
            fn (PlanMateria $materia) => $materia->creditos_en_plan ?? $materia->asignatura?->creditos ?? 0
        );

        return Inertia::render('Academico/Planes/Materias', [
            'plan' => [
                'id' => $plan->id,
                'nombre' => $plan->nombre,
                'clave' => $plan->clave,
                'carrera' => $plan->carrera?->nombre,
                'total_periodos' => $plan->total_periodos,
                'total_creditos' => $plan->total_creditos,
                'minimo_creditos' => $plan->minimo_creditos,
            ],
            'materias' => $materias->map(fn (PlanMateria $materia) => [
                'id' => $materia->id,
                'asignatura_id' => $materia->asignatura_id,
                'asignatura' => $materia->asignatura?->nombre,
                'asignatura_clave' => $materia->asignatura?->clave,
                'clave_en_plan' => $materia->clave_en_plan,
                'periodo' => $materia->periodo,
                'tipo' => $materia->tipo,
                'creditos' => $materia->creditos_en_plan ?? $materia->asignatura?->creditos,
                'creditos_sobreescritos' => $materia->creditos_en_plan !== null,
            ]),
            'creditosCargados' => $creditosCargados,
            // Ya no se elige una asignatura existente: se CREA aquí mismo. Por eso
            // se mandan los catálogos para armarla (tipo, área, clasificación).
            'tiposAsignatura' => TipoAsignatura::query()->orderBy('id')->get(['id', 'nombre']),
            'clasificaciones' => ClasificacionAsignatura::query()->orderBy('nombre')->get(['id', 'nombre']),
            'areas' => Area::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    /**
     * Detalle de una materia dentro del plan: sus prerrequisitos y la
     * composición de su calificación.
     */
    public function show(Request $request, PlanEstudio $plan, PlanMateria $materia): Response
    {
        abort_unless($materia->plan_id === $plan->id, 404);

        $materia->load([
            'asignatura.descriptores',
            'seriacion.requiere.asignatura:id,nombre',
            'esquemaEvaluacion',
            'plantillaEvaluacion:id,nombre',
        ]);

        $plan->load('carrera:id,nombre');

        $componentes = $materia->esquemaEvaluacion->sortBy('orden')->values();
        $asig = $materia->asignatura;

        return Inertia::render('Academico/Planes/DetalleMateria', [
            'plan' => ['id' => $plan->id, 'nombre' => $plan->nombre, 'carrera' => $plan->carrera?->nombre],
            'materia' => [
                'id' => $materia->id,
                'clave_en_plan' => $materia->clave_en_plan,
                'asignatura' => $asig?->nombre,
                'asignatura_id' => $materia->asignatura_id,
                'periodo' => $materia->periodo,
                'tipo' => $materia->tipo,
                'creditos_en_plan' => $materia->creditos_en_plan,
                'creditos' => $materia->creditos_en_plan ?? $asig?->creditos,
            ],
            // Datos completos de la asignatura para editarla desde aquí (todo en
            // un solo lugar: datos, descriptores, imágenes, requisitos, evaluación).
            'asignatura' => $asig === null ? null : [
                'id' => $asig->id,
                'identificador' => $asig->identificador,
                'clave' => $asig->clave,
                'nombre' => $asig->nombre,
                'creditos' => $asig->creditos,
                'tipo_asignatura_id' => $asig->tipo_asignatura_id,
                'clasificacion_id' => $asig->clasificacion_id,
                'area_id' => $asig->area_id,
                'horas_teoria' => $asig->horas_teoria,
                'horas_practica' => $asig->horas_practica,
                'horas_acompanamiento' => $asig->horas_acompanamiento,
                'horas_independientes' => $asig->horas_independientes,
                'descriptores' => $asig->descriptores->map(fn (Descriptor $d) => [
                    'descriptor_id' => $d->id,
                    'nombre' => $d->nombre,
                    'contenido' => $d->pivot->contenido,
                ])->values(),
                'imagenes' => $asig->urlsDiseno(),
            ],
            'tiposAsignatura' => TipoAsignatura::query()->orderBy('id')->get(['id', 'nombre']),
            'clasificaciones' => ClasificacionAsignatura::query()->orderBy('nombre')->get(['id', 'nombre']),
            'areas' => Area::query()->orderBy('nombre')->get(['id', 'nombre']),
            'catalogoDescriptores' => Descriptor::query()->orderBy('id')->get(['id', 'nombre']),
            'seriacion' => $materia->seriacion->map(fn ($requisito) => [
                'id' => $requisito->id,
                'tipo' => $requisito->tipo,
                'minimo_creditos' => $requisito->minimo_creditos,
                'requiere' => $requisito->requiere === null ? null : [
                    'clave_en_plan' => $requisito->requiere->clave_en_plan,
                    'nombre' => $requisito->requiere->asignatura?->nombre,
                ],
            ]),
            'componentes' => $componentes->map(fn (EsquemaEvaluacion $componente) => [
                'id' => $componente->id,
                'componente' => $componente->componente,
                'parcial' => $componente->parcial,
                'porcentaje' => (float) $componente->porcentaje,
                'orden' => $componente->orden,
            ]),
            'sumaPorcentajes' => (float) $componentes->sum('porcentaje'),
            // De dónde salió el esquema. NULL significa que se armó a mano y
            // que ninguna re-propagación de plantilla lo va a tocar.
            'plantilla' => $materia->plantillaEvaluacion === null ? null : [
                'id' => $materia->plantillaEvaluacion->id,
                'nombre' => $materia->plantillaEvaluacion->nombre,
            ],
            // Candidatas a requisito: el resto de materias del mismo plan.
            'candidatas' => PlanMateria::query()
                ->with('asignatura:id,nombre')
                ->where('plan_id', $plan->id)
                ->whereKeyNot($materia->id)
                ->orderBy('periodo')
                ->get()
                ->map(fn (PlanMateria $otra) => [
                    'id' => $otra->id,
                    'etiqueta' => sprintf(
                        '%s · %s%s',
                        $otra->clave_en_plan,
                        $otra->asignatura?->nombre ?? '',
                        $otra->periodo !== null ? " (periodo {$otra->periodo})" : '',
                    ),
                ]),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    /**
     * La asignatura se CREA aquí: una materia del plan ya no elige una del
     * catálogo, la da de alta. Ambas cosas (asignatura y su lugar en el plan) van
     * en una transacción para que no quede una asignatura suelta si algo falla.
     */
    public function store(Request $request, PlanEstudio $plan): RedirectResponse
    {
        $datos = $this->validarNueva($request, $plan);

        DB::transaction(function () use ($datos, $plan) {
            $asignatura = Asignatura::create([
                'identificador' => $datos['identificador'],
                'clave' => $datos['clave'],
                'nombre' => $datos['nombre'],
                'creditos' => $datos['creditos'],
                'tipo_asignatura_id' => $datos['tipo_asignatura_id'],
                'clasificacion_id' => $datos['clasificacion_id'] ?? null,
                'area_id' => $datos['area_id'] ?? null,
                'horas_teoria' => $datos['horas_teoria'] ?? null,
                'horas_practica' => $datos['horas_practica'] ?? null,
            ]);

            PlanMateria::create([
                'plan_id' => $plan->id,
                'asignatura_id' => $asignatura->id,
                // La materia en el plan se identifica por la clave de la
                // asignatura (ya única). No hay «clave de acta» aparte.
                'clave_en_plan' => $datos['clave'],
                'periodo' => $datos['periodo'] ?? null,
                'tipo' => $datos['tipo'],
                'creditos_en_plan' => $datos['creditos_en_plan'] ?? null,
            ]);
        });

        return back()->with('exito', 'Asignatura creada y agregada al plan.');
    }

    /**
     * Al editar SOLO se cambia su lugar en el plan (periodo, tipo, clave de acta,
     * créditos). La asignatura en sí —nombre, imágenes, descriptores— se edita en
     * «Asignaturas».
     */
    public function update(Request $request, PlanEstudio $plan, PlanMateria $materia): RedirectResponse
    {
        abort_unless($materia->plan_id === $plan->id, 404);

        $materia->update($this->validarUbicacion($request, $plan, $materia->id));

        return back()->with('exito', 'Ubicación actualizada.');
    }

    /**
     * Editar la ASIGNATURA de la materia (datos + descriptores) desde la ficha
     * del plan, sin salir de ella (por eso `back()`, no como el update de
     * «Asignaturas» que manda al índice). Las imágenes usan los endpoints de
     * asignatura; requisitos y evaluación tienen los suyos.
     */
    public function actualizarAsignatura(Request $request, PlanEstudio $plan, PlanMateria $materia): RedirectResponse
    {
        abort_unless($materia->plan_id === $plan->id, 404);

        $asignatura = $materia->asignatura;
        abort_if($asignatura === null, 404);

        $datos = $request->validate([
            'identificador' => ['required', 'string', 'max:50'],
            'clave' => ['required', 'string', 'max:50', Rule::unique('asignaturas', 'clave')->ignore($asignatura->id)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:255'],
            'creditos' => ['required', 'numeric', 'min:0'],
            'tipo_asignatura_id' => ['required', 'integer', Rule::exists('tipos_asignatura', 'id')->whereNull('deleted_at')],
            'clasificacion_id' => ['nullable', 'integer', Rule::exists('clasificaciones_asignatura', 'id')->whereNull('deleted_at')],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')->whereNull('deleted_at')],
            'horas_teoria' => ['nullable', 'integer', 'min:0'],
            'horas_practica' => ['nullable', 'integer', 'min:0'],
            'horas_acompanamiento' => ['nullable', 'integer', 'min:0'],
            'horas_independientes' => ['nullable', 'integer', 'min:0'],
            'descriptores' => ['array'],
            'descriptores.*.descriptor_id' => ['required', 'integer', Rule::exists('descriptores', 'id')->whereNull('deleted_at')],
            'descriptores.*.contenido' => ['nullable', 'string'],
        ], [], ['tipo_asignatura_id' => 'tipo de asignatura']);

        $asignatura->update(collect($datos)->except('descriptores')->all());

        // La materia en el plan se identifica por la clave de la asignatura;
        // si ésta cambió, la materia la sigue para no quedar desfasada.
        if ($materia->clave_en_plan !== $asignatura->clave) {
            $materia->update(['clave_en_plan' => $asignatura->clave]);
        }

        $sync = collect($datos['descriptores'] ?? [])
            ->mapWithKeys(fn (array $d) => [$d['descriptor_id'] => ['contenido' => $d['contenido'] ?? null]])
            ->all();
        $asignatura->descriptores()->sync($sync);

        return back()->with('exito', 'Datos de la asignatura actualizados.');
    }

    /**
     * Una materia ya abierta en un grupo no se quita del plan: hay alumnos
     * inscritos y calificaciones asentadas contra ella.
     */
    public function destroy(PlanEstudio $plan, PlanMateria $materia): RedirectResponse
    {
        abort_unless($materia->plan_id === $plan->id, 404);

        if (AsignaturaGrupo::query()->where('plan_materia_id', $materia->id)->exists()) {
            return back()->with('error', 'No se puede quitar: la materia ya se abrió en algún grupo.');
        }

        $materia->delete();

        return back()->with('exito', 'Materia retirada del plan.');
    }

    /**
     * Alta: datos de la asignatura NUEVA + su ubicación en el plan.
     *
     * @return array<string, mixed>
     */
    private function validarNueva(Request $request, PlanEstudio $plan): array
    {
        return $request->validate([
            // Asignatura nueva.
            'identificador' => ['required', 'string', 'max:50'],
            'clave' => ['required', 'string', 'max:50', Rule::unique('asignaturas', 'clave')->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:255'],
            'creditos' => ['required', 'numeric', 'min:0'],
            'tipo_asignatura_id' => ['required', 'integer', Rule::exists('tipos_asignatura', 'id')->whereNull('deleted_at')],
            'clasificacion_id' => ['nullable', 'integer', Rule::exists('clasificaciones_asignatura', 'id')->whereNull('deleted_at')],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')->whereNull('deleted_at')],
            'horas_teoria' => ['nullable', 'integer', 'min:0'],
            'horas_practica' => ['nullable', 'integer', 'min:0'],
            // Ubicación en el plan. La materia se identifica por la clave de la
            // asignatura; el periodo puede ser optativo → `tipo`.
            'periodo' => ['nullable', 'integer', 'min:1', 'max:30'],
            'tipo' => ['required', Rule::in(['obligatoria', 'optativa', 'tronco_comun'])],
            'creditos_en_plan' => ['nullable', 'numeric', 'min:0'],
        ], [
            'clave.unique' => 'Ya existe una asignatura con esa clave.',
        ], [
            'tipo_asignatura_id' => 'tipo de asignatura',
            'creditos' => 'créditos',
            'creditos_en_plan' => 'créditos en el plan',
        ]);
    }

    /**
     * Edición: solo la ubicación de la materia en el plan (no la asignatura).
     *
     * @return array<string, mixed>
     */
    private function validarUbicacion(Request $request, PlanEstudio $plan, int $id): array
    {
        return $request->validate([
            'periodo' => ['nullable', 'integer', 'min:1', 'max:30'],
            'tipo' => ['required', Rule::in(['obligatoria', 'optativa', 'tronco_comun'])],
            'creditos_en_plan' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'creditos_en_plan' => 'créditos en el plan',
        ]);
    }
}
