<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Academico\Campus;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ProcesosFormativos\ReglaProceso;
use App\Models\ProcesosFormativos\ReglaProcesoVersion;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Services\ProcesosFormativos\ResolutorDeRegla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las reglas: qué exige cada programa, con versión histórica.
 *
 * ── Una VERSIÓN con expedientes no se edita ────────────────────────────────
 * Los expedientes la citan y la citan congelada; corregirla les cambiaría el
 * requisito por debajo, a algunos ya liberados. Se crea la siguiente. Es el
 * mismo criterio que el acta asentada, la factura timbrada y la plantilla de
 * evaluación materializada — y aquí, mientras no exista `expedientes_proceso`
 * (fase 4), no hay quien la cite y editar es libre.
 *
 * ── El ALCANCE sí se edita ─────────────────────────────────────────────────
 * Es a quién se le aplica de aquí en adelante, no lo que se le exigió a nadie.
 * Lo que un expediente recuerda es la VERSIÓN.
 */
class ReglaProcesoController extends Controller
{
    public function __construct(private readonly ResolutorDeRegla $resolutor) {}

    public function index(Request $peticion): Response
    {
        $reglas = ReglaProceso::query()
            ->with([
                'tipoProceso:id,nombre',
                'campus:id,nombre',
                'nivel:id,nombre',
                'programaAcademico:id,nombre',
                'plan:id,nombre',
            ])
            ->withCount('versiones')
            ->orderBy('tipo_proceso_id')
            ->get()
            /*
             * Ordenadas de la MÁS específica a la menos, que es como se leen:
             * primero las excepciones y al final la general. Ordenarlas por
             * nombre escondería cuál gana.
             */
            ->sortByDesc(fn (ReglaProceso $r) => [$r->tipo_proceso_id, $r->especificidad()])
            ->map(fn (ReglaProceso $r) => [
                'id' => $r->id,
                'nombre' => $r->nombre,
                'tipo' => $r->tipoProceso?->nombre,
                'tipo_proceso_id' => $r->tipo_proceso_id,
                'alcance' => $r->comoSeLee(),
                'especificidad' => $r->especificidad(),
                'activa' => $r->activa,
                'versiones' => $r->versiones_count,
                'campus_id' => $r->campus_id,
                'nivel_estudios_id' => $r->nivel_estudios_id,
                'programa_academico_id' => $r->programa_academico_id,
                'plan_id' => $r->plan_id,
                'modalidad' => $r->modalidad,
                'generacion_desde' => $r->generacion_desde,
                'generacion_hasta' => $r->generacion_hasta,
                'notas' => $r->notas,
            ])
            ->values();

        return Inertia::render('Procesos/Reglas/Index', [
            'reglas' => $reglas,
            'catalogos' => $this->catalogos(),
            'puedeEditar' => $peticion->user()->can('configurar-procesos-formativos'),
        ]);
    }

    public function show(Request $peticion, ReglaProceso $regla): Response
    {
        $regla->load([
            'tipoProceso:id,nombre',
            'campus:id,nombre',
            'nivel:id,nombre',
            'programaAcademico:id,nombre',
            'plan:id,nombre',
            'versiones.documentos.documento:id,nombre',
            'versiones.materiasPrevias.planMateria.asignatura:id,nombre',
            'versiones.situacionesPermitidas.situacion:id,nombre',
        ]);

        return Inertia::render('Procesos/Reglas/Detalle', [
            'regla' => [
                'id' => $regla->id,
                'nombre' => $regla->nombre,
                'tipo' => $regla->tipoProceso?->nombre,
                'alcance' => $regla->comoSeLee(),
                'activa' => $regla->activa,
                'notas' => $regla->notas,
            ],
            /*
             * Cuál RIGE hoy se le pregunta al resolutor, no se deduce mirando
             * fechas en la pantalla: con tres versiones publicadas, dos pueden
             * estar «en vigor» y sólo una manda. Calculado aquí sería una
             * segunda definición de lo que el motor ya contesta.
             */
            'versiones' => $regla->versiones
                ->sortByDesc('version')
                ->map(fn (ReglaProcesoVersion $v) => $this->versionParaPantalla(
                    $v,
                    $v->id === $this->resolutor->versionVigente($regla)?->id,
                ))
                ->values(),
            'catalogos' => array_merge($this->catalogos(), [
                'documentos' => DocumentoRequerido::query()->orderBy('nombre')->get(['id', 'nombre']),
                'situacionesAlumno' => SituacionAlumno::query()->orderBy('nombre')->get(['id', 'nombre']),
                // Sólo las materias del plan que la regla acota: sin plan, la
                // lista serían todas las del sistema y no significaría nada.
                'materias' => $regla->plan_id === null ? [] : PlanMateria::query()
                    ->where('plan_id', $regla->plan_id)
                    ->with('asignatura:id,nombre')
                    ->get()
                    ->map(fn (PlanMateria $m) => ['id' => $m->id, 'nombre' => $m->asignatura?->nombre ?? 'Materia '.$m->id])
                    ->sortBy('nombre')
                    ->values(),
                'momentos' => ReglaProcesoVersion::MOMENTOS,
            ]),
            'puedeEditar' => $peticion->user()->can('configurar-procesos-formativos'),
        ]);
    }

    /** Alta y edición del ALCANCE. */
    public function guardar(Request $peticion, ?ReglaProceso $regla = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'tipo_proceso_id' => ['required', 'integer', 'exists:tipos_proceso_formativo,id'],
            'campus_id' => ['nullable', 'integer', 'exists:campus,id'],
            'nivel_estudios_id' => ['nullable', 'integer', 'exists:niveles_estudio,id'],
            'programa_academico_id' => ['nullable', 'integer', 'exists:programas_academicos,id'],
            'plan_id' => ['nullable', 'integer', 'exists:planes_estudio,id'],
            'modalidad' => ['nullable', 'string', 'max:30'],
            'generacion_desde' => ['nullable', 'string', 'max:100'],
            'generacion_hasta' => ['nullable', 'string', 'max:100'],
            'activa' => ['boolean'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        $datos['activa'] = $peticion->boolean('activa', true);

        /*
         * El rango al revés es un error de captura que no da error: la regla se
         * guarda y no alcanza a NADIE, y quien la escribió creerá que sí.
         */
        AvisoParaElUsuario::si(
            ($datos['generacion_desde'] ?? null) !== null
            && ($datos['generacion_hasta'] ?? null) !== null
            && $datos['generacion_desde'] > $datos['generacion_hasta'],
            422,
            'El rango de generaciones está al revés: así no alcanzaría a nadie.',
        );

        /*
         * Un PLAN que no es del programa declarado tampoco alcanza a nadie —un
         * alumno tiene un solo plan, y es de su programa—, y el error no se ve:
         * la regla existe y nunca gana.
         */
        if (($datos['plan_id'] ?? null) !== null && ($datos['programa_academico_id'] ?? null) !== null) {
            $plan = PlanEstudio::query()->find($datos['plan_id']);

            AvisoParaElUsuario::si(
                $plan !== null && $plan->programa_academico_id !== (int) $datos['programa_academico_id'],
                422,
                'Ese plan no pertenece al programa que elegiste, así que la regla no alcanzaría a nadie.',
            );
        }

        $regla ??= new ReglaProceso;
        $nueva = ! $regla->exists;
        $regla->fill($datos)->save();

        return $nueva
            ? to_route('tenant.procesos.reglas.ver', $regla)->with(
                'exito',
                'Regla creada. Ahora escribe su primera versión: es la que dice qué exige.',
            )
            : back(303)->with('exito', 'Alcance de la regla actualizado.');
    }

    /**
     * Una VERSIÓN nueva.
     *
     * `version` se calcula aquí y no se teclea: es «la siguiente», y dejarlo
     * capturar permitiría saltarse números o repetirlos —y el único de la base
     * lo rechazaría con un error de SQL en la cara de quien captura—.
     */
    public function crearVersion(Request $peticion, ReglaProceso $regla): RedirectResponse
    {
        $datos = $this->validarVersion($peticion);

        $version = DB::transaction(function () use ($regla, $datos) {
            $siguiente = (int) $regla->versiones()->max('version') + 1;

            return $regla->versiones()->create($datos + ['version' => $siguiente]);
        });

        return back(303)->with('exito', "Versión {$version->version} publicada.");
    }

    /**
     * Editar una versión.
     *
     * Mientras NADIE la cite se puede corregir. En cuanto haya expedientes
     * (fase 4) esto se cerrará y la salida será crear la siguiente: corregirla
     * les cambiaría el requisito por debajo, a algunos ya liberados.
     */
    public function guardarVersion(Request $peticion, ReglaProceso $regla, ReglaProcesoVersion $version): RedirectResponse
    {
        $this->versionEsDeLaRegla($regla, $version);

        $version->update($this->validarVersion($peticion));

        return back(303)->with('exito', 'Versión actualizada.');
    }

    public function agregarDocumento(Request $peticion, ReglaProceso $regla, ReglaProcesoVersion $version): RedirectResponse
    {
        $this->versionEsDeLaRegla($regla, $version);

        $datos = $peticion->validate([
            'documento_id' => ['required', 'integer', 'exists:documentos_requeridos,id'],
            'momento' => ['required', Rule::in(array_keys(ReglaProcesoVersion::MOMENTOS))],
            'obligatorio' => ['boolean'],
            'dias_vigencia' => ['nullable', 'integer', 'min:1'],
        ]);

        $datos['obligatorio'] = $peticion->boolean('obligatorio', true);

        AvisoParaElUsuario::si(
            $version->documentos()
                ->where('documento_id', $datos['documento_id'])
                ->where('momento', $datos['momento'])
                ->exists(),
            422,
            'Ese documento ya se pide en ese momento.',
        );

        $version->documentos()->create($datos);

        return back(303)->with('exito', 'Documento agregado.');
    }

    public function agregarMateria(Request $peticion, ReglaProceso $regla, ReglaProcesoVersion $version): RedirectResponse
    {
        $this->versionEsDeLaRegla($regla, $version);

        $datos = $peticion->validate([
            'plan_materia_id' => ['required', 'integer', 'exists:plan_materias,id'],
        ]);

        /*
         * La materia tiene que ser del PLAN que la regla acota. Si no, la regla
         * exigiría aprobar algo que el alumno no cursa y nadie sería elegible
         * nunca — un impedimento imposible de resolver.
         */
        AvisoParaElUsuario::aMenosQue(
            $regla->plan_id !== null
            && PlanMateria::query()->whereKey($datos['plan_materia_id'])->where('plan_id', $regla->plan_id)->exists(),
            422,
            'Esa materia no es del plan que acota la regla. Exigirla dejaría a todos sin poder cumplirla.',
        );

        AvisoParaElUsuario::si(
            $version->materiasPrevias()->where('plan_materia_id', $datos['plan_materia_id'])->exists(),
            422,
            'Esa materia ya está en la lista.',
        );

        $version->materiasPrevias()->create($datos);

        return back(303)->with('exito', 'Materia agregada.');
    }

    public function agregarSituacion(Request $peticion, ReglaProceso $regla, ReglaProcesoVersion $version): RedirectResponse
    {
        $this->versionEsDeLaRegla($regla, $version);

        $datos = $peticion->validate([
            'situacion_alumno_id' => ['required', 'integer', 'exists:situaciones_alumno,id'],
        ]);

        AvisoParaElUsuario::si(
            $version->situacionesPermitidas()->where('situacion_alumno_id', $datos['situacion_alumno_id'])->exists(),
            422,
            'Esa situación ya está permitida.',
        );

        $version->situacionesPermitidas()->create($datos);

        return back(303)->with('exito', 'Situación permitida.');
    }

    /**
     * Quitar un renglón de una versión.
     *
     * Los tres comparten camino porque son el mismo acto sobre tres listas de
     * la misma versión; escribirlo tres veces es como se llega a que una deje
     * de comprobar que el renglón sea suyo.
     */
    public function quitarRenglon(ReglaProceso $regla, ReglaProcesoVersion $version, string $lista, int $renglon): RedirectResponse
    {
        $this->versionEsDeLaRegla($regla, $version);

        $relacion = match ($lista) {
            'documentos' => $version->documentos(),
            'materias' => $version->materiasPrevias(),
            'situaciones' => $version->situacionesPermitidas(),
            default => AvisoParaElUsuario::lanzar(404, 'Esa lista no existe.'),
        };

        $fila = $relacion->whereKey($renglon)->first();

        AvisoParaElUsuario::aMenosQue($fila !== null, 404, 'Ese renglón no es de esta versión.');

        $fila->delete();

        return back(303)->with('exito', 'Renglón retirado.');
    }

    /** @return array<string, mixed> */
    private function validarVersion(Request $peticion): array
    {
        $datos = $peticion->validate([
            'vigente_desde' => ['required', 'date'],
            'obligatorio' => ['boolean'],
            'horas_requeridas' => ['nullable', 'integer', 'min:1'],
            'tolerancia_horas' => ['nullable', 'integer', 'min:0'],
            'porcentaje_creditos_minimo' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'periodo_minimo' => ['nullable', 'integer', 'min:1'],
            'solicitud_desde' => ['nullable', 'date'],
            'solicitud_hasta' => ['nullable', 'date', 'after_or_equal:solicitud_desde'],
            'plazo_maximo_dias' => ['nullable', 'integer', 'min:1'],
            'max_horas_dia' => ['nullable', 'integer', 'min:1', 'max:24'],
            'max_horas_semana' => ['nullable', 'integer', 'min:1', 'max:168'],
            'exige_seguro' => ['boolean'],
            'exige_convenio_vigente' => ['boolean'],
            'exige_no_adeudo' => ['boolean'],
            'exige_aprobacion_coordinador' => ['boolean'],
            'informes_parciales' => ['nullable', 'integer', 'min:0', 'max:52'],
            'periodicidad_informe_dias' => ['nullable', 'integer', 'min:1'],
            'exige_informe_final' => ['boolean'],
            'exige_evaluacion_supervisor' => ['boolean'],
            'exige_evaluacion_estudiante' => ['boolean'],
            'exige_carta_aceptacion' => ['boolean'],
            'exige_carta_termino' => ['boolean'],
            'emite_constancia' => ['boolean'],
            'cuenta_para_titulacion' => ['boolean'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ], [
            'solicitud_hasta.after_or_equal' => 'La ventana de solicitud no puede cerrar antes de abrir.',
        ]);

        /*
         * Validar NO es convertir: la regla `boolean` acepta la cadena «1» —lo
         * que manda una casilla— y devuelve el valor tal cual. Aquí lo salva el
         * `casts()`, pero se convierte igual para que lo que se guarda sea del
         * tipo que dice ser. Es la trampa que ya se cobró el motor de reportes.
         */
        foreach ([
            'obligatorio', 'exige_seguro', 'exige_convenio_vigente', 'exige_no_adeudo',
            'exige_aprobacion_coordinador', 'exige_informe_final', 'exige_evaluacion_supervisor',
            'exige_evaluacion_estudiante', 'exige_carta_aceptacion', 'exige_carta_termino',
            'emite_constancia', 'cuenta_para_titulacion',
        ] as $bandera) {
            $datos[$bandera] = $peticion->boolean($bandera);
        }

        $datos['tolerancia_horas'] = (int) ($datos['tolerancia_horas'] ?? 0);
        $datos['informes_parciales'] = (int) ($datos['informes_parciales'] ?? 0);

        /*
         * La tolerancia no puede tragarse las horas: con 480 exigidas y 500 de
         * tolerancia, cualquiera quedaría liberado con cero horas y nada
         * fallaría.
         */
        AvisoParaElUsuario::si(
            ($datos['horas_requeridas'] ?? null) !== null
            && $datos['tolerancia_horas'] >= $datos['horas_requeridas'],
            422,
            'La tolerancia no puede ser mayor ni igual que las horas exigidas: dejaría a cualquiera liberado sin horas.',
        );

        /*
         * Pedir informes parciales sin decir cada cuánto deja unas entregas sin
         * fecha límite, que es lo mismo que no pedirlas.
         */
        AvisoParaElUsuario::si(
            $datos['informes_parciales'] > 0 && ($datos['periodicidad_informe_dias'] ?? null) === null,
            422,
            'Si pides informes parciales, di cada cuántos días: sin eso no tendrían fecha límite.',
        );

        return $datos;
    }

    private function versionEsDeLaRegla(ReglaProceso $regla, ReglaProcesoVersion $version): void
    {
        /*
         * Las dos ids viajan por la URL, así que se comprueba la PAREJA. Con
         * sólo la de la versión, cualquiera con una regla propia tendría una
         * puerta lateral a las versiones de otra.
         */
        AvisoParaElUsuario::aMenosQue(
            $version->regla_id === $regla->id,
            404,
            'Esa versión no es de esta regla.',
        );
    }

    /** @return array<string, mixed> */
    private function versionParaPantalla(ReglaProcesoVersion $v, bool $esLaVigente = false): array
    {
        return array_merge($v->only([
            'id', 'version', 'obligatorio', 'horas_requeridas', 'tolerancia_horas',
            'porcentaje_creditos_minimo', 'periodo_minimo', 'plazo_maximo_dias',
            'max_horas_dia', 'max_horas_semana', 'exige_seguro', 'exige_convenio_vigente',
            'exige_no_adeudo', 'exige_aprobacion_coordinador', 'informes_parciales',
            'periodicidad_informe_dias', 'exige_informe_final', 'exige_evaluacion_supervisor',
            'exige_evaluacion_estudiante', 'exige_carta_aceptacion', 'exige_carta_termino',
            'emite_constancia', 'cuenta_para_titulacion', 'notas',
        ]), [
            'vigente_desde' => $v->vigente_desde?->toDateString(),
            'solicitud_desde' => $v->solicitud_desde?->toDateString(),
            'solicitud_hasta' => $v->solicitud_hasta?->toDateString(),
            'horas_minimas' => $v->horasMinimas(),
            'en_vigor' => $v->vigente_desde !== null && $v->vigente_desde->toDateString() <= now()->toDateString(),
            'es_la_vigente' => $esLaVigente,
            'documentos' => $v->documentos->map(fn ($d) => [
                'id' => $d->id,
                'nombre' => $d->documento?->nombre,
                'momento' => $d->momento,
                'momento_texto' => ReglaProcesoVersion::MOMENTOS[$d->momento] ?? $d->momento,
                'obligatorio' => $d->obligatorio,
                'dias_vigencia' => $d->dias_vigencia,
            ])->values(),
            'materias' => $v->materiasPrevias->map(fn ($m) => [
                'id' => $m->id,
                'nombre' => $m->planMateria?->asignatura?->nombre ?? 'Materia '.$m->plan_materia_id,
            ])->values(),
            'situaciones' => $v->situacionesPermitidas->map(fn ($s) => [
                'id' => $s->id,
                'nombre' => $s->situacion?->nombre,
            ])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function catalogos(): array
    {
        return [
            'tiposProceso' => TipoProcesoFormativo::query()->activos()->get(['id', 'nombre']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'niveles' => NivelEstudio::query()->activos()->orderBy('nombre')->get(['id', 'nombre']),
            'programas' => ProgramaAcademico::query()->orderBy('nombre')->get(['id', 'nombre', 'nivel_estudios_id']),
            'planes' => PlanEstudio::query()->orderBy('nombre')->get(['id', 'nombre', 'programa_academico_id']),
            // Las que la escuela usa de verdad, no una lista inventada.
            'modalidades' => DB::table('oferta')->whereNotNull('modalidad')->distinct()->orderBy('modalidad')->pluck('modalidad'),
        ];
    }
}
