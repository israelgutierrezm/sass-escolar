<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\MovimientoEscolar;
use App\Models\ControlEscolar\TipoMovimientoEscolar;
use App\Services\RegistradorMovimientos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La trayectoria administrativa de una matrícula.
 *
 * ── Cuelga del expediente, no de una sección propia ────────────────────────
 * La pregunta «¿qué pasó con este alumno?» se hace desde su expediente, así que
 * los movimientos viven ahí y no en un módulo aparte al que habría que llegar
 * buscando otra vez a la misma persona.
 *
 * ── El alcance es el del expediente ────────────────────────────────────────
 * Se reusa `AcotaPorCampus`: quien no alcanza al alumno tampoco alcanza su
 * trayectoria. Escribir aquí una segunda comprobación sería una segunda verdad
 * sobre lo mismo, y el día que una cambie nadie sabría cuál manda.
 *
 * ── Sin editar ni borrar, a propósito ──────────────────────────────────────
 * No hay `update` ni `destroy`. Un movimiento asentado es historia escolar:
 * para enmendarlo se registra otro que lo corrige y los dos se conservan. Es la
 * misma decisión que el acta de corrección.
 */
class MovimientoEscolarController extends Controller
{
    use AcotaPorCampus;

    public function __construct(private readonly RegistradorMovimientos $registrador) {}

    /**
     * Los movimientos de una matrícula, ya legibles.
     *
     * Se resuelven aquí los nombres —tipo, situación, grupo, ciclo, quién lo
     * registró— y no en la pantalla: la interfaz tiene que poder decir «Grupo
     * 301 A → 301 B» y no «grupo_id 32 → 47».
     */
    public function index(Request $request, MatriculaOferta $matricula): JsonResponse
    {
        $this->autorizarMatricula($request, $matricula);

        $movimientos = MovimientoEscolar::de($matricula->id)
            // Precargado: sin esto son seis consultas por renglón.
            ->with([
                'tipo:id,clave,nombre,color',
                'ciclo:id,clave,nombre',
                'situacionAnterior:id,nombre',
                'situacionNueva:id,nombre',
                'grupoAnterior:id,clave,nombre',
                'grupoNuevo:id,clave,nombre',
                'ofertaAnterior.programaAcademico:id,nombre',
                'ofertaNueva.programaAcademico:id,nombre',
                'registro.persona:id,nombre,primer_apellido,segundo_apellido',
            ])
            ->get()
            ->map(fn (MovimientoEscolar $m) => $this->paraPantalla($m));

        return response()->json(['movimientos' => $movimientos]);
    }

    /**
     * Registra un movimiento a mano.
     *
     * Sólo llegan los campos que el TIPO declara pedir: mandar un grupo en una
     * baja temporal no lo guarda, porque ese dato no significa nada ahí y
     * dejarlo entraría basura en la trayectoria.
     */
    public function store(Request $request, MatriculaOferta $matricula): RedirectResponse
    {
        $this->autorizarMatricula($request, $matricula);

        $datos = $request->validate([
            'tipo_id' => ['required', Rule::exists('tipos_movimiento_escolar', 'id')->where('activo', true)],
            'fecha_efectiva' => ['required', 'date', 'before_or_equal:today'],
            'ciclo_id' => ['nullable', Rule::exists('ciclos', 'id')],
            'situacion_nueva_id' => ['nullable', Rule::exists('situaciones_alumno', 'id')],
            'grupo_anterior_id' => ['nullable', Rule::exists('grupos', 'id')],
            'grupo_nuevo_id' => ['nullable', Rule::exists('grupos', 'id')],
            'periodo_nuevo' => ['nullable', 'integer', 'min:1', 'max:30'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'corrige_movimiento_id' => ['nullable', Rule::exists('movimientos_escolares', 'id')],
        ], [
            'fecha_efectiva.before_or_equal' => 'La fecha del movimiento no puede ser futura.',
        ]);

        $tipo = TipoMovimientoEscolar::findOrFail($datos['tipo_id']);

        AvisoParaElUsuario::si(
            $tipo->solo_automatico,
            422,
            'Ese movimiento lo emite el sistema cuando ocurre; no se captura a mano.',
        );

        /*
         * Corregir es un permiso APARTE de registrar: enmendar un movimiento ya
         * asentado es un acto de excepción, y quien captura todos los días no
         * tiene por qué poder hacerlo.
         */
        AvisoParaElUsuario::si(
            filled($datos['corrige_movimiento_id'] ?? null) && ! $request->user()->can('corregir-movimiento-escolar'),
            403,
            'No tienes permiso para corregir un movimiento ya asentado.',
        );

        $campos = $tipo->campos();

        $this->registrador->registrar($matricula, $tipo->clave, MovimientoEscolar::ORIGEN_MANUAL, null, [
            'fecha_efectiva' => $datos['fecha_efectiva'],
            'ciclo_id' => $campos['ciclo'] ? ($datos['ciclo_id'] ?? null) : null,
            // La ANTERIOR no se pide: se lee de la matrícula, que es la verdad.
            'situacion_anterior_id' => $campos['situacion'] ? $matricula->situacion_id : null,
            'situacion_nueva_id' => $campos['situacion'] ? ($datos['situacion_nueva_id'] ?? null) : null,
            'grupo_anterior_id' => $campos['grupos'] ? ($datos['grupo_anterior_id'] ?? null) : null,
            'grupo_nuevo_id' => $campos['grupos'] ? ($datos['grupo_nuevo_id'] ?? null) : null,
            'periodo_anterior' => $campos['periodo'] ? $matricula->periodo_actual : null,
            'periodo_nuevo' => $campos['periodo'] ? ($datos['periodo_nuevo'] ?? null) : null,
            'motivo' => $datos['motivo'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
            'corrige_movimiento_id' => $datos['corrige_movimiento_id'] ?? null,
        ]);

        return back()->with('exito', 'Movimiento registrado.');
    }

    /** Lo que la pantalla necesita para dibujar el formulario de un tipo. */
    public function catalogos(Request $request, MatriculaOferta $matricula): JsonResponse
    {
        $this->autorizarMatricula($request, $matricula);

        return response()->json([
            'tipos' => TipoMovimientoEscolar::query()->capturables()
                ->get(['id', 'clave', 'nombre', 'descripcion', 'color',
                    'pide_ciclo', 'pide_grupos', 'pide_situacion', 'pide_oferta',
                    'pide_periodo', 'pide_motivo']),

            'ciclos' => Ciclo::query()->orderByDesc('id')->get(['id', 'clave', 'nombre']),

            'situaciones' => SituacionAlumno::query()->orderBy('id')->get(['id', 'clave', 'nombre']),

            /*
             * Los grupos se acotan al campus y al plan de ESTA matrícula: un
             * cambio de grupo dentro de otro programa no significa nada, y
             * ofrecerlos llenaría el desplegable de opciones imposibles.
             */
            'grupos' => Grupo::query()
                ->when($matricula->oferta?->campus_id, fn ($q, $campus) => $q->where('campus_id', $campus))
                ->when($matricula->oferta?->plan_id, fn ($q, $plan) => $q->where('plan_id', $plan))
                ->orderByDesc('ciclo_id')
                ->limit(200)
                ->get(['id', 'clave', 'nombre', 'ciclo_id']),
        ]);
    }

    /** @return array<string, mixed> */
    private function paraPantalla(MovimientoEscolar $m): array
    {
        return [
            'id' => $m->id,
            'tipo' => $m->tipo?->nombre,
            'tipo_clave' => $m->tipo?->clave,
            'color' => $m->tipo?->color ?? 'gris',
            'fecha_efectiva' => $m->fecha_efectiva?->toDateString(),
            'registrado_en' => $m->created_at?->toDateTimeString(),
            'registro' => $m->registro?->persona?->nombreCompleto(),
            'origen' => $m->origen,
            'automatico' => $m->esAutomatico(),
            'ciclo' => $m->ciclo?->clave ?? $m->ciclo?->nombre,
            'motivo' => $m->motivo,
            'observaciones' => $m->observaciones,
            'corrige_movimiento_id' => $m->corrige_movimiento_id,

            /*
             * Los pares «de → a», ya con NOMBRE. La pantalla no tiene que saber
             * de qué tabla salió cada uno; sólo que hubo un antes y un después.
             */
            'cambios' => array_values(array_filter([
                $this->cambio('Situación', $m->situacionAnterior?->nombre, $m->situacionNueva?->nombre),
                $this->cambio('Grupo', $this->nombreGrupo($m->grupoAnterior), $this->nombreGrupo($m->grupoNuevo)),
                $this->cambio('Programa', $m->ofertaAnterior?->programaAcademico?->nombre, $m->ofertaNueva?->programaAcademico?->nombre),
                $this->cambio('Periodo', $m->periodo_anterior, $m->periodo_nuevo),
            ])),
        ];
    }

    /**
     * Un cambio sólo se muestra si de verdad hubo uno.
     *
     * Un alta no tiene situación anterior y no por eso debe pintar «— →
     * Activo»: eso se lee como un dato faltante. Y una corrección que no movió
     * la situación tampoco cambió nada, aunque traiga los dos lados puestos.
     *
     * Las dos cosas las decide UNA comparación. Aquí había además un
     * `blank($antes) && blank($despues)` delante, y la mutación lo destapó como
     * dead code: dos nulos también son iguales, así que la segunda condición ya
     * los descartaba. Una salvaguarda que no salva nada se retira.
     */
    private function cambio(string $etiqueta, string|int|null $antes, string|int|null $despues): ?array
    {
        if ((string) $antes === (string) $despues) {
            return null;
        }

        return ['que' => $etiqueta, 'antes' => $antes === null ? null : (string) $antes, 'despues' => $despues === null ? null : (string) $despues];
    }

    private function nombreGrupo(?Grupo $grupo): ?string
    {
        if ($grupo === null) {
            return null;
        }

        return trim(($grupo->clave ?? '').' '.($grupo->nombre ?? '')) ?: null;
    }
}
