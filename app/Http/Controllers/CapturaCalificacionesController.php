<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\ControlEscolar\Acta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\Inscripcion;
use App\Services\AsentadorActa;
use App\Services\CalculadoraCalificacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Captura de calificaciones y asentamiento del acta.
 *
 * Cierra la operación diaria: el docente captura por componente, el sistema
 * pondera con el `esquema_evaluacion` del plan, y al firmar el acta las
 * calificaciones se vuelcan al kárdex con folio.
 *
 * La autorización tiene DOS capas y ambas importan:
 *  - el permiso (`capturar-calificaciones`, `asentar-acta`), que dice qué
 *    puede hacer el rol activo;
 *  - la pertenencia, que dice sobre QUÉ materia puede hacerlo. Un docente
 *    solo toca sus propias materias; control escolar toca todas.
 * El permiso solo no basta: sin la segunda capa, cualquier docente calificaría
 * al grupo de otro.
 */
class CapturaCalificacionesController extends Controller
{
    public function __construct(
        private readonly AsentadorActa $asentador,
        private readonly CalculadoraCalificacion $calculadora,
    ) {}

    /** Materias sobre las que este usuario puede capturar, por ciclo. */
    public function index(Request $request): Response
    {
        $ciclo = $this->cicloSeleccionado($request);
        $soloLasMias = $this->esDocente($request);

        $materias = AsignaturaGrupo::query()
            ->with([
                'planMateria.asignatura:id,nombre',
                'grupo:id,clave,ciclo_id,campus_id',
                'grupo.ciclo:id,clave,nombre,captura_calif_hasta',
                'docentes.persona:id,nombre,primer_apellido,segundo_apellido',
                'actas',
            ])
            ->when($ciclo !== null, fn ($q) => $q->whereHas('grupo', fn ($g) => $g->where('ciclo_id', $ciclo->id)))
            // Un docente solo ve lo suyo; control escolar ve todo el ciclo.
            ->when($soloLasMias, fn ($q) => $q->whereHas(
                'docentes',
                fn ($d) => $d->where('personas.id', $this->personaId($request))
            ))
            ->get()
            ->map(fn (AsignaturaGrupo $materia) => [
                'id' => $materia->id,
                'materia' => $materia->planMateria?->asignatura?->nombre,
                'clave_en_plan' => $materia->planMateria?->clave_en_plan,
                'grupo' => $materia->grupo?->clave,
                'ciclo' => $materia->grupo?->ciclo?->clave,
                'titular' => $materia->docentes->firstWhere('pivot.tipo', 'titular')?->persona?->nombreCompleto(),
                'inscritos' => Inscripcion::query()->where('asignatura_grupo_id', $materia->id)->count(),
                'acta' => $this->resumenActa($materia),
            ])
            ->sortBy(['grupo', 'clave_en_plan'])
            ->values()
            ->all();

        return Inertia::render('ControlEscolar/Captura/Index', [
            'ciclos' => Ciclo::query()
                ->orderByDesc('fecha_inicio')
                ->get(['id', 'clave', 'nombre'])
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'etiqueta' => "{$c->clave} — {$c->nombre}"]),
            'cicloId' => $ciclo?->id,
            'materias' => $materias,
            'alcance' => $soloLasMias ? 'propias' : 'todas',
        ]);
    }

    /** La hoja de captura: alumnos × componentes. */
    public function show(Request $request, AsignaturaGrupo $asignaturaGrupo): Response
    {
        $this->autorizarCaptura($request, $asignaturaGrupo);

        $asignaturaGrupo->load([
            'planMateria.asignatura',
            'planMateria.plan',
            'grupo.ciclo',
            'grupo.campus:id,clave,nombre',
            'docentes.persona:id,nombre,primer_apellido,segundo_apellido',
            'actas.cerradaPor:id,nombre,primer_apellido,segundo_apellido',
            'actas.tipoEvaluacion:id,clave,nombre',
        ]);

        $esquema = $this->asentador->esquema($asignaturaGrupo);
        $plan = $asignaturaGrupo->planMateria?->plan;
        $correccion = $this->correccionAbierta($asignaturaGrupo);
        $cerrada = $this->actaCerrada($asignaturaGrupo);
        $capturaAbierta = $correccion !== null || $cerrada === null;

        $alumnos = $this->asentador->inscripcionesCalificables($asignaturaGrupo)
            ->map(function (Inscripcion $inscripcion) use ($esquema, $plan) {
                $resultado = $this->calculadora->calcular($inscripcion, $esquema, $plan);

                return [
                    'inscripcion_id' => $inscripcion->id,
                    'matricula' => $inscripcion->matriculaOferta?->matricula,
                    'nombre' => $inscripcion->matriculaOferta?->persona?->nombreCompleto(),
                    'tipo' => $inscripcion->tipo,
                    'calificaciones' => $inscripcion->calificaciones
                        ->mapWithKeys(fn (CalificacionComponente $c) => [
                            (string) $c->esquema_evaluacion_id => $c->calificacion === null ? null : (float) $c->calificacion,
                        ]),
                    'final' => $resultado->final,
                    'completa' => $resultado->completa,
                    'aprobada' => $resultado->aprobada,
                ];
            })
            ->all();

        return Inertia::render('ControlEscolar/Captura/Hoja', [
            'materia' => [
                'id' => $asignaturaGrupo->id,
                'nombre' => $asignaturaGrupo->planMateria?->asignatura?->nombre,
                'clave_en_plan' => $asignaturaGrupo->planMateria?->clave_en_plan,
                'grupo' => $asignaturaGrupo->grupo?->clave,
                'campus' => $asignaturaGrupo->grupo?->campus?->nombre,
                'ciclo' => $asignaturaGrupo->grupo?->ciclo?->clave,
                'captura_hasta' => $asignaturaGrupo->grupo?->ciclo?->captura_calif_hasta?->toDateString(),
                'plan' => $plan?->nombre,
                'titular' => $asignaturaGrupo->docentes->firstWhere('pivot.tipo', 'titular')?->persona?->nombreCompleto(),
            ],
            'escala' => [
                'minima' => $plan?->calificacion_minima,
                'maxima' => $plan?->calificacion_maxima,
                'aprobatoria' => $plan?->calificacion_minima_aprobatoria,
            ],
            'componentes' => $esquema->map(fn (EsquemaEvaluacion $c) => [
                'id' => $c->id,
                'componente' => $c->componente,
                'parcial' => $c->parcial,
                'porcentaje' => (float) $c->porcentaje,
            ])->all(),
            'alumnos' => $alumnos,
            'actas' => $asignaturaGrupo->actas
                ->sortByDesc('id')
                ->map(fn (Acta $acta) => [
                    'id' => $acta->id,
                    'folio' => $acta->estaCerrada() ? $acta->folio : null,
                    'tipo' => $acta->tipoEvaluacion?->nombre,
                    'situacion' => $acta->situacion,
                    'cerrada_por' => $acta->cerradaPor?->nombreCompleto(),
                    'cerrada_en' => $acta->cerrada_en?->format('d/m/Y H:i'),
                    'es_correccion' => $acta->acta_origen_id !== null,
                    'observaciones' => $acta->observaciones,
                ])->values()->all(),
            'estado' => [
                'captura_abierta' => $capturaAbierta,
                'en_correccion' => $correccion !== null,
                'impedimentos' => $capturaAbierta ? $this->impedimentosDeCierre($asignaturaGrupo, $correccion) : [],
            ],
            'permisos' => [
                'capturar' => $capturaAbierta && $this->puedeCapturar($request, $asignaturaGrupo),
                'cerrar' => $capturaAbierta && $this->puedeCerrar($request, $asignaturaGrupo),
                'corregir' => ! $capturaAbierta && $this->puedeCerrar($request, $asignaturaGrupo),
            ],
        ]);
    }

    /**
     * Guarda lo capturado. Se envía la hoja completa, no celda por celda: el
     * docente califica de corrido y un guardado por tecla saturaría la red y
     * dejaría la hoja a medias si se cae la conexión.
     */
    public function guardar(Request $request, AsignaturaGrupo $asignaturaGrupo): RedirectResponse
    {
        $this->autorizarCaptura($request, $asignaturaGrupo);

        if ($this->correccionAbierta($asignaturaGrupo) === null && $this->actaCerrada($asignaturaGrupo) !== null) {
            throw ValidationException::withMessages([
                'calificaciones' => 'El acta ya está cerrada. Para cambiar una calificación hay que emitir un acta de corrección.',
            ]);
        }

        $plan = $asignaturaGrupo->planMateria?->plan;
        $minima = (float) ($plan?->calificacion_minima ?? 0);
        $maxima = (float) ($plan?->calificacion_maxima ?? 100);

        $datos = $request->validate([
            'calificaciones' => ['present', 'array'],
            'calificaciones.*.inscripcion_id' => ['required', 'integer'],
            'calificaciones.*.esquema_evaluacion_id' => ['required', 'integer'],
            'calificaciones.*.calificacion' => ['nullable', 'numeric', "min:{$minima}", "max:{$maxima}"],
        ], [
            'calificaciones.*.calificacion.min' => "La calificación no puede ser menor que {$minima}.",
            'calificaciones.*.calificacion.max' => "La calificación no puede ser mayor que {$maxima}.",
        ]);

        // Solo se aceptan pares que pertenezcan a ESTA materia-grupo: el id de
        // una inscripción ajena no debe poder colarse por el payload.
        $inscripciones = Inscripcion::query()
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->pluck('id')
            ->flip();

        $componentes = EsquemaEvaluacion::query()
            ->where('plan_materia_id', $asignaturaGrupo->plan_materia_id)
            ->pluck('id')
            ->flip();

        $personaId = $this->personaId($request);
        $guardadas = 0;

        DB::transaction(function () use ($datos, $inscripciones, $componentes, $personaId, &$guardadas): void {
            foreach ($datos['calificaciones'] as $fila) {
                if (! $inscripciones->has($fila['inscripcion_id']) || ! $componentes->has($fila['esquema_evaluacion_id'])) {
                    continue;
                }

                CalificacionComponente::updateOrCreate(
                    [
                        'inscripcion_id' => $fila['inscripcion_id'],
                        'esquema_evaluacion_id' => $fila['esquema_evaluacion_id'],
                    ],
                    [
                        'calificacion' => $fila['calificacion'] ?? null,
                        'capturado_por' => $personaId,
                        'capturado_en' => now(),
                    ],
                );

                $guardadas++;
            }
        });

        return back()->with('exito', "Calificaciones guardadas ({$guardadas}).");
    }

    /** Firma el acta: calcula finales, genera folio y vuelca al kárdex. */
    public function cerrar(Request $request, AsignaturaGrupo $asignaturaGrupo): RedirectResponse
    {
        if (! $this->puedeCerrar($request, $asignaturaGrupo)) {
            throw new AccessDeniedHttpException('Solo el docente titular de la materia o control escolar pueden firmar el acta.');
        }

        $acta = $this->correccionAbierta($asignaturaGrupo)
            ?? $this->asentador->actaDeTrabajo($asignaturaGrupo);

        try {
            $acta = $this->asentador->cerrar($acta, $this->personaId($request));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['acta' => $e->getMessage()]);
        }

        return back()->with('exito', "Acta {$acta->folio} asentada en el kárdex.");
    }

    /**
     * Abre un acta de corrección sobre la cerrada. No edita nada todavía:
     * reabre la captura y, al firmarla, sustituye los renglones de kárdex de
     * la original conservando ambas actas.
     */
    public function corregir(Request $request, AsignaturaGrupo $asignaturaGrupo): RedirectResponse
    {
        if (! $this->puedeCerrar($request, $asignaturaGrupo)) {
            throw new AccessDeniedHttpException('Solo el docente titular de la materia o control escolar pueden emitir una corrección.');
        }

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'motivo.required' => 'Hay que explicar por qué se corrige el acta.',
            'motivo.min' => 'El motivo debe explicar la corrección (mínimo 10 caracteres).',
        ]);

        $cerrada = $this->actaCerrada($asignaturaGrupo);

        if ($cerrada === null) {
            throw ValidationException::withMessages(['motivo' => 'No hay un acta cerrada que corregir.']);
        }

        $this->asentador->abrirCorreccion($cerrada, $datos['motivo']);

        return back()->with('exito', 'Acta de corrección abierta. La captura vuelve a estar disponible.');
    }

    /*
    |--------------------------------------------------------------------------
    | Apoyo
    |--------------------------------------------------------------------------
    */

    private function personaId(Request $request): ?int
    {
        return $request->user()?->persona_id;
    }

    /** La corrección en curso, si la hay. Mientras exista, la captura sigue abierta. */
    private function correccionAbierta(AsignaturaGrupo $asignaturaGrupo): ?Acta
    {
        return Acta::query()
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->where('situacion', Acta::ABIERTA)
            ->whereNotNull('acta_origen_id')
            ->latest('id')
            ->first();
    }

    /** El acta firmada más reciente de la materia. */
    private function actaCerrada(AsignaturaGrupo $asignaturaGrupo): ?Acta
    {
        return Acta::query()
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->where('situacion', Acta::CERRADA)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function impedimentosDeCierre(AsignaturaGrupo $asignaturaGrupo, ?Acta $correccion): array
    {
        // Se evalúa sobre un acta en memoria cuando aún no existe ninguna: la
        // sola visita a la hoja no debe crear filas.
        $acta = $correccion ?? new Acta([
            'asignatura_grupo_id' => $asignaturaGrupo->id,
            'situacion' => Acta::ABIERTA,
        ]);

        $acta->setRelation('asignaturaGrupo', $asignaturaGrupo);

        return $this->asentador->impedimentos($acta);
    }

    /**
     * @return array{estado: string, folio: string|null}
     */
    private function resumenActa(AsignaturaGrupo $materia): array
    {
        $cerrada = $materia->actas->firstWhere('situacion', Acta::CERRADA);
        $correccion = $materia->actas->first(
            fn (Acta $a) => $a->situacion === Acta::ABIERTA && $a->acta_origen_id !== null
        );

        return match (true) {
            $correccion !== null => ['estado' => 'en_correccion', 'folio' => $cerrada?->folio],
            $cerrada !== null => ['estado' => 'cerrada', 'folio' => $cerrada->folio],
            default => ['estado' => 'abierta', 'folio' => null],
        };
    }

    /**
     * ¿Esta persona está dada de alta como docente?
     *
     * Es el discriminador de ALCANCE, y no un permiso, por una razón concreta:
     * el rol `docente` tiene `asentar-acta` —firma sus propias actas—, así que
     * ese permiso no puede distinguir "el docente de esta materia" de "control
     * escolar". Estar en la tabla `docentes` sí: quien imparte clase queda
     * acotado a las materias que le asignaron; el personal administrativo, que
     * no aparece ahí, opera sobre todas.
     */
    private function esDocente(Request $request): bool
    {
        $personaId = $this->personaId($request);

        return $personaId !== null && Docente::query()->whereKey($personaId)->exists();
    }

    /** ¿Imparte esta materia, con cualquier tipo (titular o adjunto)? */
    private function esDocenteDe(Request $request, AsignaturaGrupo $asignaturaGrupo, ?string $tipo = null): bool
    {
        $personaId = $this->personaId($request);

        return $personaId !== null && DB::table('docente_asignatura_grupo')
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->where('persona_id', $personaId)
            ->when($tipo !== null, fn ($q) => $q->where('tipo', $tipo))
            ->exists();
    }

    private function puedeCapturar(Request $request, AsignaturaGrupo $asignaturaGrupo): bool
    {
        if (! $request->user()->can('capturar-calificaciones')) {
            return false;
        }

        // El adjunto también captura: acompaña al titular en la clase.
        return $this->esDocente($request)
            ? $this->esDocenteDe($request, $asignaturaGrupo)
            : true;
    }

    /**
     * Firmar es del TITULAR (regla de la spec): el adjunto captura pero no
     * firma. Control escolar puede firmar en su lugar —ausencia o baja del
     * docente— porque no está acotado a una materia.
     */
    private function puedeCerrar(Request $request, AsignaturaGrupo $asignaturaGrupo): bool
    {
        if (! $request->user()->can('asentar-acta')) {
            return false;
        }

        return $this->esDocente($request)
            ? $this->esDocenteDe($request, $asignaturaGrupo, 'titular')
            : true;
    }

    private function autorizarCaptura(Request $request, AsignaturaGrupo $asignaturaGrupo): void
    {
        if (! $this->puedeCapturar($request, $asignaturaGrupo)) {
            throw new AccessDeniedHttpException('No impartes esta materia.');
        }
    }

    private function cicloSeleccionado(Request $request): ?Ciclo
    {
        $id = $request->query('ciclo_id');

        return $id === null ? null : Ciclo::find($id);
    }
}
