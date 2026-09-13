<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Academico\PlanEstudio;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\DocumentoDocente;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Familia\Cita;
use App\Models\Familia\DisponibilidadCitaDocente;
use App\Models\Identidad\Usuario;
use App\Services\AsentadorActa;
use App\Services\Asistencia\PaseDeLista;
use App\Services\CalculadoraCalificacion;
use App\Services\CalendarioCaptura;
use App\Services\CapturaDeCalificaciones;
use App\Services\Docencia\DocumentosDelDocente;
use App\Services\Familia\GestorDeCitas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * El portal del DOCENTE para la app móvil.
 *
 * ── Qué se sirve, y qué NO todavía ─────────────────────────────────────────
 * El NÚCLEO de lectura: las materias que imparte y, por cada una, quiénes son
 * sus alumnos. Los flujos de captura del portal web —pasar lista, calificar,
 * asentar el acta— son rebanadas posteriores; aquí no viajan.
 *
 * ── El alcance sale de la ASIGNACIÓN, no del permiso ni de la URL ───────────
 * `ver-mis-materias` deja entrar al portal; qué materias son suyas lo dice
 * `docente_asignatura_grupo`, y el filtro va por `docentes.persona_id` —NO por
 * `personas.id`: es la columna que cuelga de la tabla `docentes`, la trampa que
 * ya mordió en la web—. Una materia ajena responde 403.
 *
 * ── La faceta la fija `api.faceta:docente` ─────────────────────────────────
 * De él depende que el `Gate::before` de `ver-mis-materias` resuelva como
 * DOCENTE. Sin ese middleware, el permiso no tendría rol activo que comprobar.
 */
class DocenteApiController extends Controller
{
    public function __construct(
        private readonly PaseDeLista $pase,
        private readonly AsentadorActa $asentador,
        private readonly CalculadoraCalificacion $calculadora,
        private readonly CalendarioCaptura $calendario,
        private readonly CapturaDeCalificaciones $captura,
        private readonly DocumentosDelDocente $documentos,
        private readonly GestorDeCitas $gestorCitas,
    ) {}

    /** Las materias que imparte, con su grupo, horario y cuántos alumnos. */
    public function materias(Request $peticion): JsonResponse
    {
        $personaId = $this->personaId($peticion);

        $materias = AsignaturaGrupo::query()
            ->with([
                'planMateria.asignatura:id,nombre',
                'planMateria.plan:id,nombre',
                'grupo:id,clave,ciclo_id,campus_id',
                'grupo.ciclo:id,clave,nombre',
                'grupo.campus:id,nombre',
                'horarios.aula:id,nombre',
                'actas',
            ])
            ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $personaId))
            ->get()
            ->map(fn (AsignaturaGrupo $ag) => [
                'id' => $ag->id,
                'materia' => $ag->planMateria?->asignatura?->nombre,
                'plan' => $ag->planMateria?->plan?->nombre,
                'grupo' => $ag->grupo?->clave,
                'campus' => $ag->grupo?->campus?->nombre,
                'ciclo' => $ag->grupo?->ciclo?->clave,
                // Su papel en ESTA materia: el adjunto captura pero no firma.
                'soy' => $ag->docentes->firstWhere('persona_id', $personaId)?->pivot?->tipo,
                'inscritos' => Inscripcion::query()->where('asignatura_grupo_id', $ag->id)->count(),
                'acta_cerrada' => $ag->actas->contains(fn ($a) => $a->situacion === 'cerrada'),
                'horarios' => $this->horarios($ag),
            ])
            ->sortBy('materia')
            ->values()
            ->all();

        return response()->json(['materias' => $materias]);
    }

    /** Una materia mía: su información y el roster de alumnos. */
    public function materia(Request $peticion, AsignaturaGrupo $asignaturaGrupo): JsonResponse
    {
        $personaId = $this->autorizarMateria($peticion, $asignaturaGrupo);

        $asignaturaGrupo->load([
            'planMateria.asignatura:id,nombre',
            'planMateria.plan:id,nombre',
            'grupo.ciclo:id,clave,nombre',
            'grupo.campus:id,nombre',
            'horarios.aula:id,nombre',
            'docentes.persona:id,nombre,primer_apellido,segundo_apellido',
        ]);

        $alumnos = Inscripcion::query()
            ->with([
                'matriculaOferta:id,persona_id,matricula',
                'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
                'situacion:id,clave,nombre',
            ])
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->get()
            ->sortBy(fn (Inscripcion $i) => $i->matriculaOferta?->persona?->nombreCompleto() ?? '')
            ->map(fn (Inscripcion $i) => [
                'matricula' => $i->matriculaOferta?->matricula,
                'nombre' => $i->matriculaOferta?->persona?->nombreCompleto(),
                'situacion' => $i->situacion?->nombre,
                'situacion_clave' => $i->situacion?->clave,
            ])
            ->values()
            ->all();

        return response()->json([
            'materia' => [
                'id' => $asignaturaGrupo->id,
                'nombre' => $asignaturaGrupo->planMateria?->asignatura?->nombre,
                'plan' => $asignaturaGrupo->planMateria?->plan?->nombre,
                'grupo' => $asignaturaGrupo->grupo?->clave,
                'campus' => $asignaturaGrupo->grupo?->campus?->nombre,
                'ciclo' => $asignaturaGrupo->grupo?->ciclo?->clave,
                'soy' => $asignaturaGrupo->docentes->firstWhere('persona_id', $personaId)?->pivot?->tipo,
            ],
            'horarios' => $this->horarios($asignaturaGrupo),
            // Los compañeros que comparten la materia, sin uno mismo.
            'companeros' => $asignaturaGrupo->docentes
                ->reject(fn ($d) => $d->persona_id === $personaId)
                ->map(fn ($d) => ['nombre' => $d->persona?->nombreCompleto(), 'tipo' => $d->pivot->tipo])
                ->values()
                ->all(),
            'alumnos' => $alumnos,
        ]);
    }

    /**
     * La hoja para pasar lista: los alumnos con lo ya marcado ese día.
     *
     * `?fecha=YYYY-MM-DD` (por omisión hoy) y `?modalidad=` eligen la sesión;
     * `modalidades` dice cuáles ofrece la materia (una sola, o teoría y práctica
     * si pasa lista dos veces), y `estatus` los valores que acepta.
     */
    public function asistencia(Request $peticion, AsignaturaGrupo $asignaturaGrupo): JsonResponse
    {
        $this->autorizarMateria($peticion, $asignaturaGrupo);

        $fecha = (string) ($peticion->query('fecha') ?: now()->format('Y-m-d'));
        $modalidad = (string) ($peticion->query('modalidad')
            ?: ($asignaturaGrupo->doble_pase_lista ? 'teorica' : 'unica'));

        return response()->json([
            'fecha' => $fecha,
            'modalidad' => $modalidad,
            'modalidades' => $asignaturaGrupo->doble_pase_lista ? ['teorica', 'practica'] : ['unica'],
            'estatus' => PaseDeLista::ESTATUS,
            'alumnos' => $this->pase->hoja($asignaturaGrupo, $fecha, $modalidad),
        ]);
    }

    /**
     * Guarda la lista de una sesión. La misma escritura que la web —el servicio
     * compartido—: sólo alumnos de esta materia, y repasar el mismo día corrige
     * sin duplicar.
     */
    public function guardarAsistencia(Request $peticion, AsignaturaGrupo $asignaturaGrupo): JsonResponse
    {
        $personaId = $this->autorizarMateria($peticion, $asignaturaGrupo);

        $datos = $peticion->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'modalidad' => ['required', Rule::in(PaseDeLista::MODALIDADES)],
            'asistencias' => ['required', 'array', 'min:1'],
            'asistencias.*.inscripcion_id' => ['required', 'integer'],
            'asistencias.*.estatus' => ['required', Rule::in(PaseDeLista::ESTATUS)],
            'asistencias.*.observacion' => ['nullable', 'string', 'max:300'],
        ], [
            'fecha.before_or_equal' => 'No se puede pasar lista de una clase que todavía no ocurre.',
        ]);

        $guardadas = $this->pase->guardar(
            $asignaturaGrupo,
            $datos['fecha'],
            $datos['modalidad'],
            $datos['asistencias'],
            $personaId,
        );

        return response()->json(['guardadas' => $guardadas]);
    }

    /**
     * La hoja de captura: alumnos × componentes, con lo ya capturado y el final
     * calculado. `componentes` trae el parcial de cada uno; `calendario` dice qué
     * cortes se pueden tocar hoy, y `captura_abierta` si el acta lo permite.
     *
     * Las cifras salen de los mismos servicios que la web —`AsentadorActa`,
     * `CalculadoraCalificacion`, `CalendarioCaptura`—: una sola verdad.
     */
    public function calificaciones(Request $peticion, AsignaturaGrupo $asignaturaGrupo): JsonResponse
    {
        $this->autorizarMateria($peticion, $asignaturaGrupo);

        $asignaturaGrupo->load(['planMateria.asignatura:id,nombre', 'planMateria.plan', 'grupo.ciclo:id,clave']);

        $esquema = $this->asentador->esquema($asignaturaGrupo);
        $plan = $asignaturaGrupo->planMateria?->plan;

        $alumnos = $this->asentador->inscripcionesCalificables($asignaturaGrupo)
            ->map(function (Inscripcion $inscripcion) use ($esquema, $plan) {
                $resultado = $this->calculadora->calcular($inscripcion, $esquema, $plan);

                return [
                    'inscripcion_id' => $inscripcion->id,
                    'matricula' => $inscripcion->matriculaOferta?->matricula,
                    'nombre' => $inscripcion->matriculaOferta?->persona?->nombreCompleto(),
                    // Un objeto componente → nota (null = sin capturar, NO cero).
                    'calificaciones' => (object) $inscripcion->calificaciones
                        ->mapWithKeys(fn (CalificacionComponente $c) => [
                            (string) $c->esquema_evaluacion_id => $c->calificacion === null ? null : (float) $c->calificacion,
                        ])
                        ->all(),
                    'final' => $resultado->final,
                    'completa' => $resultado->completa,
                    'aprobada' => $resultado->aprobada,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'materia' => [
                'id' => $asignaturaGrupo->id,
                'nombre' => $asignaturaGrupo->planMateria?->asignatura?->nombre,
                'grupo' => $asignaturaGrupo->grupo?->clave,
                'ciclo' => $asignaturaGrupo->grupo?->ciclo?->clave,
                'plan' => $plan?->nombre,
            ],
            'escala' => [
                'minima' => $plan?->calificacion_minima,
                'maxima' => $plan?->calificacion_maxima,
                'aprobatoria' => $plan?->calificacion_minima_aprobatoria,
                'decimales' => $plan?->decimales_calificacion,
            ],
            'componentes' => $esquema->map(fn (EsquemaEvaluacion $c) => [
                'id' => $c->id,
                'componente' => $c->componente,
                'parcial' => $c->parcial,
                'porcentaje' => (float) $c->porcentaje,
            ])->values()->all(),
            'calendario' => $this->calendario->estadoPorParcial($asignaturaGrupo, $this->personaId($peticion)),
            'captura_abierta' => $this->captura->capturaAbierta($asignaturaGrupo),
            'alumnos' => $alumnos,
        ]);
    }

    /**
     * Guarda lo capturado. La misma escritura que la web (el servicio
     * compartido): valida contra la escala del plan, sólo pares de esta materia,
     * respeta los cortes del calendario y NULL no es cero.
     */
    public function guardarCalificaciones(Request $peticion, AsignaturaGrupo $asignaturaGrupo): JsonResponse
    {
        $personaId = $this->autorizarMateria($peticion, $asignaturaGrupo);

        if (! $this->captura->capturaAbierta($asignaturaGrupo)) {
            throw ValidationException::withMessages([
                'calificaciones' => 'El acta ya está cerrada. Para cambiar una calificación hay que emitir un acta de corrección.',
            ]);
        }

        $plan = $asignaturaGrupo->planMateria?->plan;
        $minima = (float) ($plan?->calificacion_minima ?? 0);
        $maxima = (float) ($plan?->calificacion_maxima ?? 100);

        $datos = $peticion->validate([
            'calificaciones' => ['present', 'array'],
            'calificaciones.*.inscripcion_id' => ['required', 'integer'],
            'calificaciones.*.esquema_evaluacion_id' => ['required', 'integer'],
            'calificaciones.*.calificacion' => array_merge(['nullable'], PlanEstudio::reglasPara($plan)),
        ], [
            'calificaciones.*.calificacion.min' => "La calificación no puede ser menor que {$minima}.",
            'calificaciones.*.calificacion.max' => "La calificación no puede ser mayor que {$maxima}.",
            'calificaciones.*.calificacion.decimal' => 'Este plan califica '.$plan?->comoSeCalifica().'.',
        ]);

        ['guardadas' => $guardadas, 'rechazados' => $rechazados] = $this->captura->guardar(
            $asignaturaGrupo,
            $datos['calificaciones'],
            $personaId,
        );

        return response()->json(['guardadas' => $guardadas, 'rechazados' => $rechazados]);
    }

    // ── Mi expediente: los documentos que la escuela me pide ────────────────

    /**
     * Los comprobantes de MI expediente y el catálogo del ámbito docente, del
     * servicio compartido con la web (`DocumentosDelDocente`): qué papeles pide
     * la escuela y cuáles ya subí, con su estado de revisión.
     */
    public function documentos(Request $peticion): JsonResponse
    {
        return response()->json($this->documentos->datos($this->personaId($peticion)));
    }

    /**
     * Sube (o reemplaza) un comprobante mío. Multipart: documento_id + archivo.
     * El tipo tiene que ser del ÁMBITO DOCENTE —el id de un documento de otro
     * ámbito no debe acabar en mi expediente—. Re-subir reinicia la revisión.
     */
    public function subirDocumento(Request $peticion): JsonResponse
    {
        $personaId = $this->personaId($peticion);

        $datos = $peticion->validate([
            'documento_id' => [
                'required',
                'integer',
                function (string $atributo, mixed $valor, callable $falla) {
                    $delAmbito = DocumentoRequerido::query()
                        ->delAmbito(DocumentoRequerido::AMBITO_DOCENTE)
                        ->whereKey($valor)
                        ->exists();

                    if (! $delAmbito) {
                        $falla('Ese documento no es de los que la escuela te pide.');
                    }
                },
            ],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'descripcion' => ['nullable', 'string', 'max:100'],
            'vigencia' => ['nullable', 'date', 'after:today'],
        ], [
            'archivo.max' => 'El archivo no puede pasar de 5 MB.',
            'archivo.mimes' => 'Solo se aceptan PDF o imágenes.',
            'vigencia.after' => 'Un documento que ya venció no sirve como comprobante.',
        ]);

        $this->documentos->subir(
            $personaId,
            (int) $datos['documento_id'],
            $peticion->file('archivo'),
            $datos['descripcion'] ?? null,
            $datos['vigencia'] ?? null,
        );

        return response()->json(['ok' => true]);
    }

    /** Retira un comprobante mío (lo aceptado no se retira desde aquí). */
    public function eliminarDocumento(Request $peticion, DocumentoDocente $documento): JsonResponse
    {
        $this->documentos->exigirDelDocente($this->personaId($peticion), $documento);

        $error = $this->documentos->eliminar($documento);
        AvisoParaElUsuario::si($error !== null, 422, (string) $error);

        return response()->json(['ok' => true]);
    }

    // ── Citas con las familias ──────────────────────────────────────────────

    /**
     * Mi agenda: mis ventanas de atención, mis citas (últimas 200) y el catálogo
     * de modalidades. La misma forma que la web —el gestor serializa—.
     */
    public function citas(Request $peticion): JsonResponse
    {
        $docenteId = $this->personaId($peticion);

        $citas = Cita::query()
            ->where('docente_persona_id', $docenteId)
            ->with(['alumno:id,nombre,primer_apellido,segundo_apellido', 'solicitante:id,nombre,primer_apellido,segundo_apellido'])
            ->orderByDesc('inicio')->limit(200)->get()
            ->map(fn (Cita $c) => $this->gestorCitas->serializarCita($c))->values()->all();

        return response()->json([
            'disponibilidad' => $this->gestorCitas->ventanasDe($docenteId)
                ->map(fn (DisponibilidadCitaDocente $d) => $this->gestorCitas->serializarVentana($d))->values()->all(),
            'citas' => $citas,
            'modalidades' => DisponibilidadCitaDocente::MODALIDADES,
        ]);
    }

    /** Agrega una ventana de atención a padres (aparte de la de dar clase). */
    public function agregarDisponibilidad(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'dia_semana' => ['required', 'integer', 'between:1,7'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'modalidad' => ['required', 'in:'.implode(',', array_keys(DisponibilidadCitaDocente::MODALIDADES))],
            'duracion_min' => ['required', 'integer', 'between:5,240'],
            'lugar' => ['nullable', 'string', 'max:200'],
        ]);

        $this->gestorCitas->agregarDisponibilidad($this->personaId($peticion), $datos);

        return response()->json(['ok' => true]);
    }

    /** Quita una ventana mía (ajena → 404). */
    public function quitarDisponibilidad(Request $peticion, DisponibilidadCitaDocente $disponibilidad): JsonResponse
    {
        $this->gestorCitas->quitarDisponibilidad($disponibilidad, $this->personaId($peticion));

        return response()->json(['ok' => true]);
    }

    /** Confirma una cita solicitada (nota y lugar opcionales). Bajo bloqueo revalida el traslape. */
    public function confirmarCita(Request $peticion, Cita $cita): JsonResponse
    {
        $datos = $peticion->validate([
            'respuesta' => ['nullable', 'string', 'max:500'],
            'lugar' => ['nullable', 'string', 'max:200'],
        ]);

        $this->gestorCitas->confirmar($cita, $this->personaId($peticion), $datos['respuesta'] ?? null, $datos['lugar'] ?? null);

        return response()->json(['ok' => true]);
    }

    /** Rechaza una cita solicitada, con motivo (lo único que la familia puede usar para volver a pedir). */
    public function rechazarCita(Request $peticion, Cita $cita): JsonResponse
    {
        $datos = $peticion->validate(['respuesta' => ['required', 'string', 'max:500']], [
            'respuesta.required' => 'El rechazo necesita un motivo: es lo único que la familia puede usar para volver a pedir.',
        ]);

        $this->gestorCitas->rechazar($cita, $this->personaId($peticion), $datos['respuesta']);

        return response()->json(['ok' => true]);
    }

    /** Cancela una cita activa suya, con motivo. Se avisa a la familia. */
    public function cancelarCita(Request $peticion, Cita $cita): JsonResponse
    {
        $datos = $peticion->validate(['respuesta' => ['required', 'string', 'max:500']]);

        $this->gestorCitas->cancelar($cita, $this->personaId($peticion), $datos['respuesta']);

        return response()->json(['ok' => true]);
    }

    /** Marca el desenlace de una cita confirmada YA PASADA (realizada / no_asistio). */
    public function marcarCita(Request $peticion, Cita $cita): JsonResponse
    {
        $datos = $peticion->validate(['estado' => ['required', 'in:'.Cita::REALIZADA.','.Cita::NO_ASISTIO]]);

        $this->gestorCitas->marcar($cita, $this->personaId($peticion), $datos['estado']);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function horarios(AsignaturaGrupo $ag): array
    {
        return $ag->horarios->map(fn ($h) => [
            'dia' => $h->dia_semana,
            'inicio' => substr((string) $h->hora_inicio, 0, 5),
            'fin' => substr((string) $h->hora_fin, 0, 5),
            'aula' => $h->aula?->nombre,
        ])->values()->all();
    }

    /**
     * La persona del usuario. Sin ella no hay a qué acotar, así que se cierra en
     * vez de mostrar todo.
     */
    private function personaId(Request $peticion): int
    {
        /** @var Usuario $usuario */
        $usuario = $peticion->user();

        return $usuario->persona_id
            ?? throw new AccessDeniedHttpException('Tu cuenta no está ligada a una persona.');
    }

    /**
     * Sólo se entra a una materia propia, y se comprueba contra la ASIGNACIÓN
     * —no el permiso—: el permiso dice que puede dar clase, la asignación en
     * qué materia. El filtro va por `docentes.persona_id`.
     */
    private function autorizarMateria(Request $peticion, AsignaturaGrupo $asignaturaGrupo): int
    {
        $personaId = $this->personaId($peticion);

        $esSuya = $asignaturaGrupo->docentes()
            ->where('docentes.persona_id', $personaId)
            ->exists();

        if (! $esSuya) {
            throw new AccessDeniedHttpException('Esa materia no es tuya.');
        }

        return $personaId;
    }
}
