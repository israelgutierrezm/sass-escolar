<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanMateria;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\EstatusHistorial;
use App\Models\ControlEscolar\Historial;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\ObservacionAsignatura;
use App\Models\ControlEscolar\TipoEvaluacion;
use App\Models\Finanzas\DatosFacturacion;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Support\CatalogosSat;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Sexo;
use App\Rules\CurpValida;
use App\Services\AprovisionadorAcceso;
use App\Services\IdentidadPersona;
use App\Services\MatriculadorOferta;
use App\Models\Academico\Modalidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use App\Services\Suplantador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Alumnos: buscar, consultar el expediente completo y corregir sus datos.
 *
 * El "alumno" es `matricula_oferta`, no la persona: la misma persona puede
 * cursar una licenciatura y una maestría, y cada una tiene su matrícula, su
 * kárdex y su situación. Por eso el listado es de matrículas y no de personas
 * —quien busca a alguien en control escolar busca una matrícula concreta—.
 *
 * Lo que se edita aquí son los datos de IDENTIDAD (que viven en `personas` y
 * alcanzan a todas sus matrículas) y la SITUACIÓN escolar de esta matrícula.
 * La carga de materias se maneja en Inscripciones, que es donde vive esa
 * validación; duplicarla aquí daría dos verdades sobre lo mismo.
 */
class AlumnoController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'carrera_id' => $request->query('carrera_id'),
            'campus_id' => $request->query('campus_id'),
            'situacion_id' => $request->query('situacion_id'),
            'estatus' => $request->query('estatus'),
        ];

        $alumnos = MatriculaOferta::query()
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido,curp,email,celular,foto_url',
                'oferta.carrera:id,nombre',
                'oferta.plan:id,nombre',
                'oferta.campus:id,nombre',
                'situacion:id,nombre',
            ])
            ->when($filtros['busqueda'] !== '', fn ($q) => $this->buscar($q, $filtros['busqueda']))
            ->when($filtros['carrera_id'], fn ($q, $id) => $q->whereHas('oferta', fn ($o) => $o->where('carrera_id', $id)))
            ->when($filtros['campus_id'], fn ($q, $id) => $q->whereHas('oferta', fn ($o) => $o->where('campus_id', $id)))
            ->when($filtros['situacion_id'], fn ($q, $id) => $q->where('situacion_id', $id))
            ->when($filtros['estatus'], fn ($q, $e) => $q->where('estatus', $e))
            ->orderBy('matricula')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'nombre_completo' => $m->persona?->nombreCompleto(),
                'curp' => $m->persona?->curp,
                'email' => $m->persona?->email,
                'foto' => $m->persona?->urlFoto(),
                'carrera' => $m->oferta?->carrera?->nombre,
                'plan' => $m->oferta?->plan?->nombre,
                'campus' => $m->oferta?->campus?->nombre,
                'situacion' => $m->situacion?->nombre,
                'estatus' => $m->estatus,
                'generacion' => $m->generacion,
            ]);

        return Inertia::render('Alumnos/Index', [
            'alumnos' => $alumnos,
            'filtros' => $filtros,
            'carreras' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'situaciones' => SituacionAlumno::query()->orderBy('id')->get(['id', 'nombre']),
            'puedeEditar' => $request->user()->can('editar-alumnos'),
            // Alta directa de alumno (revalidaciones que se saltan admisión).
            'puedeRegistrar' => $request->user()->can('generar-matricula'),
        ]);
    }

    /**
     * Formulario de ALTA DIRECTA de un alumno: para casos que se saltan el
     * embudo de admisión (revalidaciones, traslados). Captura la persona con el
     * bloque de identidad compartido y la matricula en una oferta ya existente.
     */
    public function create(IdentidadPersona $identidad): Response
    {
        $modalidades = Modalidad::query()->pluck('nombre', 'clave');

        return Inertia::render('Alumnos/Registrar', [
            ...$identidad->catalogosDeOrigen(),
            'ofertas' => Oferta::query()
                ->with(['carrera:id,nombre', 'plan:id,nombre,clave', 'campus:id,nombre'])
                ->where('estatus', 'abierta')
                ->get()
                ->map(fn (Oferta $o) => [
                    'id' => $o->id,
                    'campus_id' => $o->campus_id,
                    'etiqueta' => implode(' · ', array_filter([
                        $o->carrera?->nombre,
                        $o->plan?->nombre,
                        $o->campus?->nombre,
                        $modalidades[$o->modalidad] ?? $o->modalidad,
                    ])),
                ])
                ->values(),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    /**
     * Da de alta al alumno: reutiliza/crea la persona por CURP (bloque de
     * identidad) y la matricula en la oferta elegida. La matrícula/boleta se
     * autogenera salvo que se capture una a mano (alumno que ya la trae).
     */
    public function store(Request $request, IdentidadPersona $identidad, MatriculadorOferta $matriculador): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['required', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            'curp' => array_filter(['nullable', 'string', 'max:20', new CurpValida]),
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'genero_id' => ['nullable', 'integer'],
            'entidad_nacimiento_id' => ['nullable', 'integer'],
            'pais_nacimiento_id' => ['nullable', 'integer'],
            'email' => ['required', 'email', 'max:150', function (string $atributo, mixed $valor, \Closure $fallar) use ($identidad, $request) {
                $conflicto = $identidad->correoEnUso($valor, $identidad->existentePorCurp($request->input('curp'))?->id);

                if ($conflicto !== null) {
                    $fallar('Ese correo ya está registrado con otra persona ('.$conflicto->nombreCompleto().'). Usa otro, o captura su CURP para reutilizarla.');
                }
            }],
            'correo_institucional' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],
            'telefono_local' => ['nullable', 'string', 'max:20'],
            // Datos del alumno
            'oferta_id' => ['required', 'integer', Rule::exists('oferta', 'id')->whereNull('deleted_at')],
            'generacion' => ['nullable', 'string', 'max:100'],
            // Boleta/matrícula opcional: si viene, es única; si no, se autogenera.
            'matricula' => ['nullable', 'string', 'max:50', Rule::unique('matricula_oferta', 'matricula')->whereNull('deleted_at')],
        ]);

        $datos['curp'] = filled($datos['curp'] ?? null) ? mb_strtoupper(trim($datos['curp'])) : null;
        $datos['email'] = mb_strtolower(trim($datos['email']));

        try {
            $matricula = DB::transaction(function () use ($datos, $identidad, $matriculador) {
                $persona = $identidad->existentePorCurp($datos['curp']);
                $persona === null
                    ? $persona = Persona::create($identidad->resolver($datos))
                    : $persona->update($identidad->resolver($datos));

                $oferta = Oferta::query()->findOrFail($datos['oferta_id']);

                return $matriculador->matricular($persona, $oferta, $datos['generacion'] ?? null, filled($datos['matricula'] ?? null) ? trim($datos['matricula']) : null);
            });
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('tenant.escolar.alumnos.show', $matricula->id)
            ->with('exito', "Alumno registrado con matrícula {$matricula->matricula}.");
    }

    /** Expediente del alumno: identidad, kárdex y su carga por ciclo. */
    public function show(Request $request, MatriculaOferta $alumno): Response
    {
        $alumno->load([
            'persona.sexo',
            'persona.genero',
            'persona.entidadNacimiento',
            'oferta.carrera',
            'oferta.plan',
            'oferta.campus',
            'oferta.turno',
            'situacion',
        ]);

        $historial = Historial::query()
            ->with([
                'planMateria.asignatura:id,nombre,creditos',
                'ciclo:id,clave',
                'estatus:id,clave,nombre',
                'tipoEvaluacion:id,nombre',
                'observacion:id,nombre',
                'observacionAsignatura:id,nombre',
            ])
            ->where('matricula_oferta_id', $alumno->id)
            ->get()
            ->sortBy([['ciclo.clave', 'asc'], ['planMateria.clave_en_plan', 'asc']])
            ->values();

        $aprobadas = $historial->filter(fn (Historial $h) => $h->estatus?->clave === 'aprobada');

        return Inertia::render('Alumnos/Detalle', [
            'alumno' => [
                'id' => $alumno->id,
                'matricula' => $alumno->matricula,
                'generacion' => $alumno->generacion,
                'periodo_actual' => $alumno->periodo_actual,
                'fecha_ingreso' => $alumno->fecha_ingreso?->toDateString(),
                'estatus' => $alumno->estatus,
                'situacion_id' => $alumno->situacion_id,
                'situacion' => $alumno->situacion?->nombre,
                'carrera' => $alumno->oferta?->carrera?->nombre,
                'plan' => $alumno->oferta?->plan?->nombre,
                'campus' => $alumno->oferta?->campus?->nombre,
                'turno' => $alumno->oferta?->turno?->nombre,
            ],
            'persona' => [
                'id' => $alumno->persona?->id,
                'nombre' => $alumno->persona?->nombre,
                'primer_apellido' => $alumno->persona?->primer_apellido,
                'segundo_apellido' => $alumno->persona?->segundo_apellido,
                'curp' => $alumno->persona?->curp,
                'rfc' => $alumno->persona?->rfc,
                'fecha_nacimiento' => $alumno->persona?->fecha_nacimiento?->toDateString(),
                'sexo_id' => $alumno->persona?->sexo_id,
                'genero_id' => $alumno->persona?->genero_id,
                'email' => $alumno->persona?->email,
                'correo_institucional' => $alumno->persona?->correo_institucional,
                'celular' => $alumno->persona?->celular,
                'foto' => $alumno->persona?->urlFoto(),
                'entidad_nacimiento' => $alumno->persona?->entidadNacimiento?->nombre,
            ],
            // Padres/tutores ligados a este alumno. Cada uno es —o pasa a ser—
            // usuario con rol de padre de familia al vincularlo.
            'tutores' => TutorAlumno::query()
                ->with('tutor:id,nombre,primer_apellido,segundo_apellido,curp,email')
                ->where('alumno_persona_id', $alumno->persona_id)
                ->get()
                ->map(fn (TutorAlumno $v) => [
                    'id' => $v->id,
                    'nombre' => trim(($v->tutor?->nombre ?? '').' '.($v->tutor?->primer_apellido ?? '').' '.($v->tutor?->segundo_apellido ?? '')),
                    'curp' => $v->tutor?->curp,
                    'email' => $v->tutor?->email,
                    'parentesco' => $v->parentesco,
                    'puede_ver_academico' => $v->puede_ver_academico,
                    'puede_ver_finanzas' => $v->puede_ver_finanzas,
                ]),
            // Datos de facturación del alumno: si quiere factura y a nombre de
            // quién (él mismo o un tercero).
            'facturacion' => $this->facturacionDe($alumno->persona_id),
            'catalogosFacturacion' => [
                'usos_cfdi' => CatalogosSat::usosCfdi(),
                'regimenes' => CatalogosSat::regimenesFiscales(),
            ],
            // TODAS las carreras de esta persona, la actual incluida: es el
            // caso que justifica que el alumno sea la matrícula y no la
            // persona, y quien la atiende necesita verlas juntas.
            'carreras' => MatriculaOferta::query()
                ->with(['oferta.carrera:id,nombre', 'oferta.plan:id,nombre', 'oferta.campus:id,nombre', 'situacion:id,nombre'])
                ->withCount('historial')
                ->where('persona_id', $alumno->persona_id)
                ->orderByDesc('fecha_ingreso')
                ->get()
                ->map(fn (MatriculaOferta $m) => [
                    'id' => $m->id,
                    'matricula' => $m->matricula,
                    'carrera' => $m->oferta?->carrera?->nombre,
                    'plan' => $m->oferta?->plan?->nombre,
                    'campus' => $m->oferta?->campus?->nombre,
                    'estatus' => $m->estatus,
                    'situacion' => $m->situacion?->nombre,
                    'fecha_ingreso' => $m->fecha_ingreso?->toDateString(),
                    'generacion' => $m->generacion,
                    'materias_en_kardex' => $m->historial_count,
                    'es_actual' => $m->id === $alumno->id,
                ]),
            // Ofertas donde todavía NO está matriculada: son las que se le
            // pueden agregar. Ofrecer las que ya tiene solo produce un error.
            'ofertasDisponibles' => Oferta::query()
                ->with(['carrera:id,nombre', 'plan:id,nombre', 'campus:id,nombre', 'turno:id,nombre'])
                ->whereNotIn('id', MatriculaOferta::query()
                    ->where('persona_id', $alumno->persona_id)
                    ->pluck('oferta_id'))
                ->get()
                ->map(fn (Oferta $o) => [
                    'id' => $o->id,
                    'etiqueta' => trim(sprintf(
                        '%s · %s%s%s',
                        $o->carrera?->nombre ?? '',
                        $o->plan?->nombre ?? '',
                        $o->campus !== null ? ' · '.$o->campus->nombre : '',
                        $o->turno !== null ? ' · '.$o->turno->nombre : '',
                    )),
                ]),
            'puedeMatricular' => $request->user()->can('generar-matricula'),
            // Para el boton "Ver como": solo tiene sentido si esa persona
            // tiene cuenta con la que entrar.
            'suplantable' => app(Suplantador::class)->datosPara($request, $alumno->persona),
            'situacionesDeBaja' => app(MatriculadorOferta::class)->situacionesDeBaja()
                ->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre]),
            'historial' => $historial->map(fn (Historial $h) => [
                'id' => $h->id,
                'clave_en_plan' => $h->planMateria?->clave_en_plan,
                'materia' => $h->planMateria?->asignatura?->nombre,
                'creditos' => $h->planMateria?->asignatura?->creditos,
                'ciclo' => $h->ciclo?->clave,
                'calificacion' => $h->calificacion,
                'estatus' => $h->estatus?->nombre,
                'estatus_clave' => $h->estatus?->clave,
                'tipo_evaluacion' => $h->tipoEvaluacion?->nombre,
                'acta_folio' => $h->acta_folio,
                'observacion' => $h->observacion?->nombre,
                // Estatus académico oficial SEP (equivalencia, revalidación…).
                'observacion_asignatura' => $h->observacionAsignatura?->nombre,
                // Renglón cargado a mano (sin acta): se puede retirar desde aquí.
                'manual' => $h->acta_id === null,
            ]),
            'resumen' => [
                'materias_cursadas' => $historial->count(),
                'aprobadas' => $aprobadas->count(),
                'reprobadas' => $historial->filter(fn (Historial $h) => $h->estatus?->clave === 'reprobada')->count(),
                'creditos' => round($aprobadas->sum(
                    fn (Historial $h) => (float) ($h->planMateria?->asignatura?->creditos ?? 0)
                ), 2),
                'promedio' => $this->promedio($historial),
                'creditos_del_plan' => $alumno->oferta?->plan?->total_creditos,
            ],
            'carga' => $this->cargaPorCiclo($alumno),
            // Carga manual al historial (equivalencias, revalidaciones, históricos):
            // la malla del plan del alumno + los catálogos de estatus/observación.
            'materiasDelPlan' => PlanMateria::query()
                ->with('asignatura:id,nombre')
                ->where('plan_id', $alumno->oferta?->plan_id)
                ->orderBy('periodo')->orderBy('clave_en_plan')
                ->get()
                ->map(fn (PlanMateria $pm) => [
                    'id' => $pm->id,
                    'etiqueta' => trim(($pm->clave_en_plan ?? '').' · '.($pm->asignatura?->nombre ?? '')),
                ]),
            'estatusHistorial' => EstatusHistorial::query()->orderBy('id')->get(['id', 'nombre']),
            'tiposEvaluacion' => TipoEvaluacion::query()->orderBy('id')->get(['id', 'nombre']),
            'observacionesAsignatura' => ObservacionAsignatura::query()->orderBy('id')->get(['id', 'nombre', 'abreviatura']),
            'ciclos' => Ciclo::query()->orderByDesc('id')->get(['id', 'clave']),
            'puedeCargarHistorial' => $request->user()->can('editar-alumnos'),
            'situaciones' => SituacionAlumno::query()->orderBy('id')->get(['id', 'nombre']),
            'sexos' => Sexo::query()->orderBy('id')->get(['id', 'nombre']),
            'generos' => Genero::query()->orderBy('id')->get(['id', 'nombre']),
            'puedeEditar' => $request->user()->can('editar-alumnos'),
        ]);
    }

    /**
     * Carga una materia directo al historial de este alumno, sin pasar por un
     * acta: para equivalencias, revalidaciones y kárdex histórico de otra
     * institución. Lleva su estatus académico oficial SEP (`observacion_
     * asignatura`) y NO queda ligada a acta (por eso se puede retirar aquí).
     */
    public function agregarHistorial(Request $request, MatriculaOferta $alumno): RedirectResponse
    {
        $alumno->loadMissing('oferta');
        $planId = $alumno->oferta?->plan_id;
        abort_if($planId === null, 404);

        $datos = $request->validate([
            'plan_materia_id' => ['required', 'integer', Rule::exists('plan_materias', 'id')->where('plan_id', $planId)->whereNull('deleted_at')],
            'ciclo_id' => ['nullable', 'integer', Rule::exists('ciclos', 'id')->whereNull('deleted_at')],
            'estatus_id' => ['required', 'integer', Rule::exists('estatus_historial', 'id')],
            'tipo_evaluacion_id' => ['required', 'integer', Rule::exists('tipos_evaluacion', 'id')],
            'observacion_asignatura_id' => ['required', 'integer', Rule::exists('observaciones_asignatura', 'id')],
            'calificacion' => ['nullable', 'numeric', 'min:0'],
        ], [
            'plan_materia_id.exists' => 'La materia no pertenece al plan del alumno.',
        ], [
            'plan_materia_id' => 'materia',
            'estatus_id' => 'estatus',
            'tipo_evaluacion_id' => 'tipo de evaluación',
            'observacion_asignatura_id' => 'observación de asignatura',
        ]);

        Historial::create([
            'matricula_oferta_id' => $alumno->id,
            'plan_materia_id' => $datos['plan_materia_id'],
            'ciclo_id' => $datos['ciclo_id'] ?? null,
            'asignatura_grupo_id' => null,
            'tipo_evaluacion_id' => $datos['tipo_evaluacion_id'],
            'estatus_id' => $datos['estatus_id'],
            'calificacion' => $datos['calificacion'] ?? null,
            'observacion_asignatura_id' => $datos['observacion_asignatura_id'],
        ]);

        return back()->with('exito', 'Materia agregada al historial.');
    }

    /**
     * Retira un renglón del historial. Solo las cargas MANUALES (sin acta): lo
     * asentado por un acta se corrige con un acta de corrección, no borrando el
     * kárdex a mano.
     */
    public function quitarHistorial(MatriculaOferta $alumno, Historial $historial): RedirectResponse
    {
        abort_unless($historial->matricula_oferta_id === $alumno->id, 404);

        if ($historial->acta_id !== null) {
            return back()->with('error', 'Este renglón salió de un acta; corrígelo con un acta de corrección.');
        }

        $historial->delete();

        return back()->with('exito', 'Renglón retirado del historial.');
    }

    /**
     * Corrige la identidad de la persona y la situación de esta matrícula.
     *
     * Van juntos en una pantalla pero se guardan en dos tablas distintas, y eso
     * importa: el nombre corregido alcanza a TODAS las matrículas de la persona
     * —es la misma—, mientras que la situación es de esta inscripción a oferta.
     */
    public function update(Request $request, MatriculaOferta $alumno): RedirectResponse
    {
        $persona = $alumno->persona;

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['required', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            'curp' => ['nullable', 'string', 'size:18', Rule::unique('personas', 'curp')->ignore($persona?->id)->whereNull('deleted_at')],
            'rfc' => ['nullable', 'string', 'max:13'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'sexo_id' => ['required', 'integer'],
            'genero_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:150'],
            'correo_institucional' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],

            'situacion_id' => ['required', 'integer', Rule::exists('situaciones_alumno', 'id')->whereNull('deleted_at')],
            'estatus' => ['required', Rule::in(['activo', 'egresado', 'baja'])],
            'generacion' => ['nullable', 'string', 'max:100'],
            'periodo_actual' => ['nullable', 'integer', 'min:1', 'max:30'],
        ], [
            'curp.size' => 'La CURP tiene 18 caracteres.',
            'curp.unique' => 'Esa CURP ya está registrada en otra persona.',
        ], [
            'sexo_id' => 'sexo',
            'genero_id' => 'género',
            'situacion_id' => 'situación',
        ]);

        DB::transaction(function () use ($alumno, $persona, $datos): void {
            $persona?->update([
                'nombre' => $datos['nombre'],
                'primer_apellido' => $datos['primer_apellido'],
                'segundo_apellido' => $datos['segundo_apellido'] ?? null,
                'curp' => $datos['curp'] ?? null,
                'rfc' => $datos['rfc'] ?? null,
                'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
                'sexo_id' => $datos['sexo_id'],
                'genero_id' => $datos['genero_id'] ?? null,
                'email' => $datos['email'] ?? null,
                'correo_institucional' => $datos['correo_institucional'] ?? null,
                'celular' => $datos['celular'] ?? null,
            ]);

            $alumno->update([
                'situacion_id' => $datos['situacion_id'],
                'estatus' => $datos['estatus'],
                'generacion' => $datos['generacion'] ?? null,
                'periodo_actual' => $datos['periodo_actual'] ?? null,
            ]);
        });

        return back()->with('exito', 'Datos del alumno actualizados.');
    }

    /**
     * Matricula a esta persona en OTRA oferta.
     *
     * Es el camino para la egresada que empieza la maestría o el alumno que
     * suma una segunda licenciatura: gente que la escuela ya conoce y a la que
     * obligar a darse de alta como aspirante sería recapturar.
     *
     * Genera matrícula, así que exige `generar-matricula` y no solo
     * `editar-alumnos`: numerar a un alumno es un acto distinto de corregirle
     * el teléfono.
     */
    public function agregarCarrera(Request $request, MatriculaOferta $alumno, MatriculadorOferta $matriculador): RedirectResponse
    {
        $datos = $request->validate([
            'oferta_id' => ['required', 'integer', Rule::exists('oferta', 'id')->whereNull('deleted_at')],
            'generacion' => ['nullable', 'string', 'max:100'],
        ], [], ['oferta_id' => 'oferta']);

        $oferta = Oferta::findOrFail($datos['oferta_id']);

        try {
            $nueva = $matriculador->matricular($alumno->persona, $oferta, $datos['generacion'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['oferta_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('tenant.escolar.alumnos.show', $nueva->id)
            ->with('exito', "Matrícula {$nueva->matricula} generada.");
    }

    /**
     * Da de baja o reactiva UNA matrícula, sin tocar las otras carreras de la
     * misma persona.
     *
     * No hay opción de eliminar: el kárdex de esa matrícula es historia escolar
     * y las actas donde aparece quedarían sin dueño.
     */
    public function cambiarEstadoCarrera(Request $request, MatriculaOferta $alumno, MatriculaOferta $carrera, MatriculadorOferta $matriculador): RedirectResponse
    {
        // La matrícula a tocar tiene que ser de la MISMA persona del expediente
        // abierto: sin esto, un id en la URL daría de baja a cualquiera.
        abort_unless($carrera->persona_id === $alumno->persona_id, 404);

        $datos = $request->validate([
            'accion' => ['required', Rule::in(['baja', 'reactivar'])],
            // Cuál baja: temporal o definitiva. El catálogo de la escuela
            // decide qué opciones hay.
            'situacion_id' => ['nullable', 'integer', Rule::exists('situaciones_alumno', 'id')->whereNull('deleted_at')],
        ], [], ['situacion_id' => 'tipo de baja']);

        if ($datos['accion'] === 'baja') {
            $matriculador->darDeBaja($carrera, $datos['situacion_id'] ?? null);

            return back()->with('exito', "Matrícula {$carrera->matricula} dada de baja.");
        }

        $matriculador->reactivar($carrera);

        return back()->with('exito', "Matrícula {$carrera->matricula} reactivada.");
    }

    /**
     * Vincula un padre/tutor al alumno. Al hacerlo, esa persona pasa a ser
     * usuario con rol de padre de familia (censo, sin acceso hasta que se le
     * configure una contraseña). Cero recaptura: si la CURP ya existe, se liga
     * esa persona sin duplicarla.
     */
    public function vincularTutor(Request $request, MatriculaOferta $alumno, AprovisionadorAcceso $aprovisionador): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['required', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            'curp' => ['nullable', 'string', 'max:18'],
            'email' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],
            'parentesco' => ['required', Rule::in(['padre', 'madre', 'tutor', 'otro'])],
            'puede_ver_academico' => ['boolean'],
            'puede_ver_finanzas' => ['boolean'],
        ]);

        $curp = strtoupper(trim((string) ($datos['curp'] ?? '')));
        $tutor = $curp !== '' ? Persona::query()->where('curp', $curp)->first() : null;

        if ($tutor !== null && $tutor->id === $alumno->persona_id) {
            return back()->with('error', 'Una persona no puede ser tutora de sí misma.');
        }

        if ($tutor !== null && TutorAlumno::query()
            ->where('tutor_persona_id', $tutor->id)
            ->where('alumno_persona_id', $alumno->persona_id)
            ->exists()
        ) {
            return back()->with('error', 'Ese tutor ya está vinculado a este alumno.');
        }

        DB::transaction(function () use ($datos, $curp, $alumno, $aprovisionador, &$tutor) {
            $tutor ??= Persona::create([
                'nombre' => $datos['nombre'],
                'primer_apellido' => $datos['primer_apellido'],
                'segundo_apellido' => $datos['segundo_apellido'] ?? null,
                'curp' => $curp !== '' ? $curp : null,
                'email' => $datos['email'] ?? null,
                'celular' => $datos['celular'] ?? null,
            ]);

            TutorAlumno::create([
                'tutor_persona_id' => $tutor->id,
                'alumno_persona_id' => $alumno->persona_id,
                'parentesco' => $datos['parentesco'],
                'puede_ver_academico' => $datos['puede_ver_academico'] ?? true,
                'puede_ver_finanzas' => $datos['puede_ver_finanzas'] ?? true,
            ]);

            $aprovisionador->paraPersona($tutor, 'padre_familia');
        });

        return back()->with('exito', 'Padre/tutor vinculado. Ya es usuario del sistema.');
    }

    /**
     * Quita el vínculo. NO borra la cuenta del tutor: puede ser padre de otros
     * alumnos, y su cuenta es historia igual que la de cualquiera.
     */
    public function desvincularTutor(MatriculaOferta $alumno, TutorAlumno $tutor): RedirectResponse
    {
        abort_unless($tutor->alumno_persona_id === $alumno->persona_id, 404);

        $tutor->delete();

        return back()->with('exito', 'Vínculo eliminado.');
    }

    /**
     * Guarda los datos de facturación del alumno: si quiere factura y los datos
     * fiscales del receptor (él mismo o un tercero).
     *
     * Si NO quiere factura, no se exigen los datos fiscales; si SÍ, el RFC, la
     * razón social, el régimen, el CP y el uso de CFDI son obligatorios —sin
     * ellos el CFDI no timbra—.
     */
    public function guardarFacturacion(Request $request, MatriculaOferta $alumno): RedirectResponse
    {
        $quiere = $request->boolean('quiere_factura');

        $reglaSiQuiere = $quiere ? ['required'] : ['nullable'];

        $datos = $request->validate([
            'quiere_factura' => ['boolean'],
            'es_tercero' => ['boolean'],
            'rfc' => [...$reglaSiQuiere, 'string', 'min:12', 'max:13'],
            'razon_social' => [...$reglaSiQuiere, 'string', 'max:255'],
            'regimen_fiscal' => [...$reglaSiQuiere, 'string', 'max:5'],
            'cp' => [...$reglaSiQuiere, 'string', 'size:5'],
            'uso_cfdi' => [...$reglaSiQuiere, 'string', 'max:4'],
            'correo_fiscal' => ['nullable', 'email', 'max:190'],
        ]);

        DatosFacturacion::updateOrCreate(
            ['persona_id' => $alumno->persona_id],
            $datos + ['quiere_factura' => $quiere, 'es_tercero' => $request->boolean('es_tercero')],
        );

        return back()->with('exito', 'Datos de facturación guardados.');
    }

    /**
     * @return array<string, mixed>
     */
    private function facturacionDe(int $personaId): array
    {
        $d = DatosFacturacion::query()->where('persona_id', $personaId)->first();

        return [
            'quiere_factura' => (bool) $d?->quiere_factura,
            'es_tercero' => (bool) $d?->es_tercero,
            'rfc' => $d?->rfc,
            'razon_social' => $d?->razon_social,
            'regimen_fiscal' => $d?->regimen_fiscal,
            'cp' => $d?->cp,
            'uso_cfdi' => $d?->uso_cfdi,
            'correo_fiscal' => $d?->correo_fiscal,
            'tiene_cliente_facturapi' => filled($d?->facturapi_customer_id),
        ];
    }

    /**
     * Búsqueda por matrícula, nombre o CURP.
     *
     * La matrícula se busca aparte porque vive en `matricula_oferta` y no en
     * `personas`, y es lo primero que teclea control escolar. Sobre la persona
     * se usa LIKE y no el índice FULLTEXT: con FULLTEXT, escribir "Her" no
     * encuentra "Hernández" —indexa palabras completas— y la búsqueda de una
     * pantalla se teclea de a poco. Si el volumen lo pide, aquí es donde habría
     * que cambiar a FULLTEXT en modo booleano con comodín.
     *
     * @param  Builder<MatriculaOferta>  $query
     * @return Builder<MatriculaOferta>
     */
    private function buscar(Builder $query, string $termino): Builder
    {
        $like = '%'.str_replace(' ', '%', $termino).'%';

        return $query->where(fn ($q) => $q
            ->where('matricula', 'like', "%{$termino}%")
            ->orWhereHas('persona', fn ($p) => $p
                ->where('curp', 'like', "%{$termino}%")
                ->orWhereRaw("CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) LIKE ?", [$like])));
    }

    /**
     * Materias que lleva por ciclo, de la más reciente hacia atrás.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cargaPorCiclo(MatriculaOferta $alumno): array
    {
        return Inscripcion::query()
            ->with([
                'asignaturaGrupo.planMateria.asignatura:id,nombre',
                'asignaturaGrupo.grupo:id,clave',
                'ciclo:id,clave,nombre,fecha_inicio',
                'situacion:id,clave,nombre',
            ])
            ->where('matricula_oferta_id', $alumno->id)
            ->get()
            ->groupBy(fn (Inscripcion $i) => $i->ciclo?->clave ?? 'sin ciclo')
            ->map(fn ($inscripciones, $clave) => [
                'ciclo' => $clave,
                'materias' => $inscripciones->map(fn (Inscripcion $i) => [
                    'id' => $i->id,
                    'clave_en_plan' => $i->asignaturaGrupo?->planMateria?->clave_en_plan,
                    'materia' => $i->asignaturaGrupo?->planMateria?->asignatura?->nombre,
                    'grupo' => $i->asignaturaGrupo?->grupo?->clave,
                    'tipo' => $i->tipo,
                    'situacion' => $i->situacion?->nombre,
                    'de_baja' => $i->situacion?->clave === 'baja',
                    'calificacion_final' => $i->calificacion_final,
                ])->values(),
            ])
            ->sortByDesc('ciclo')
            ->values()
            ->all();
    }

    /**
     * Promedio de lo calificado. Solo cuenta lo que tiene número: una materia
     * en curso no promedia como cero.
     */
    private function promedio($historial): ?float
    {
        $conCalificacion = $historial->filter(fn (Historial $h) => $h->calificacion !== null);

        if ($conCalificacion->isEmpty()) {
            return null;
        }

        return round((float) $conCalificacion->avg(fn (Historial $h) => (float) $h->calificacion), 2);
    }
}
