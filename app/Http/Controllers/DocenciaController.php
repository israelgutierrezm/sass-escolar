<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Services\CalendarioCaptura;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Portal del docente: lo suyo y nada más.
 *
 * Un docente NO es personal administrativo. Antes conservaba el permiso
 * `ver-grupos` y con él le aparecía Control escolar completo —ciclos y grupos
 * de toda la escuela, pantallas pensadas para otro oficio—. Aquí ve
 * únicamente las materias que imparte, sus alumnos y su propio expediente.
 *
 * El alcance no lo da el permiso sino la asignación en
 * `docente_asignatura_grupo`: cada pantalla arranca de ahí, así que no hay
 * forma de llegar a la materia de otro cambiando un id en la URL.
 */
class DocenciaController extends Controller
{
    public function __construct(private readonly CalendarioCaptura $calendario) {}

    /** Mis materias, agrupadas por ciclo. */
    public function index(Request $request): Response
    {
        $personaId = $this->personaId($request);
        $ciclo = $this->cicloSeleccionado($request);

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
            ->when($ciclo !== null, fn ($q) => $q->whereHas('grupo', fn ($g) => $g->where('ciclo_id', $ciclo->id)))
            ->get()
            ->map(fn (AsignaturaGrupo $ag) => [
                'id' => $ag->id,
                'clave_en_plan' => $ag->planMateria?->clave_en_plan,
                'materia' => $ag->planMateria?->asignatura?->nombre,
                'plan' => $ag->planMateria?->plan?->nombre,
                'grupo' => $ag->grupo?->clave,
                'campus' => $ag->grupo?->campus?->nombre,
                'ciclo' => $ag->grupo?->ciclo?->clave,
                // Su papel en ESTA materia: el adjunto captura pero no firma.
                'soy' => $ag->docentes->firstWhere('persona_id', $personaId)?->pivot?->tipo,
                'inscritos' => Inscripcion::query()->where('asignatura_grupo_id', $ag->id)->count(),
                'horarios' => $ag->horarios->map(fn ($h) => [
                    'dia' => $h->dia_semana,
                    'inicio' => substr((string) $h->hora_inicio, 0, 5),
                    'fin' => substr((string) $h->hora_fin, 0, 5),
                    'aula' => $h->aula?->nombre,
                ])->values(),
                'acta_cerrada' => $ag->actas->contains(fn ($a) => $a->situacion === 'cerrada'),
                // Cortes que puede capturar hoy: es lo primero que quiere saber
                // al entrar, y evita que descubra la ventana cerrada al final.
                'cortes_abiertos' => collect($this->calendario->estadoPorParcial($ag, $personaId))
                    ->filter(fn (array $e) => $e['abierto'])
                    ->count(),
                'cortes_totales' => count($this->calendario->estadoPorParcial($ag, $personaId)),
            ])
            ->sortBy(['ciclo', 'grupo', 'clave_en_plan'])
            ->values()
            ->all();

        return Inertia::render('Docencia/Index', [
            'materias' => $materias,
            'ciclos' => Ciclo::query()
                ->orderByDesc('fecha_inicio')
                ->get(['id', 'clave', 'nombre'])
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'etiqueta' => "{$c->clave} — {$c->nombre}"]),
            'cicloId' => $ciclo?->id,
            'puedeCapturar' => $request->user()->can('capturar-calificaciones'),
        ]);
    }

    /** Detalle de UNA materia mía: quiénes son mis alumnos. */
    public function materia(Request $request, AsignaturaGrupo $asignaturaGrupo): Response
    {
        $personaId = $this->autorizarMateria($request, $asignaturaGrupo);

        $asignaturaGrupo->load([
            'planMateria.asignatura',
            'planMateria.plan:id,nombre',
            'grupo.ciclo',
            'grupo.campus:id,nombre',
            'horarios.aula:id,nombre',
            'docentes.persona:id,nombre,primer_apellido,segundo_apellido',
        ]);

        $inscripciones = Inscripcion::query()
            ->with([
                'matriculaOferta:id,persona_id,matricula',
                'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido,email,celular',
                'situacion:id,clave,nombre',
            ])
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->get()
            ->sortBy(fn (Inscripcion $i) => $i->matriculaOferta?->persona?->nombreCompleto() ?? '')
            ->values();

        return Inertia::render('Docencia/Materia', [
            'materia' => [
                'id' => $asignaturaGrupo->id,
                'clave_en_plan' => $asignaturaGrupo->planMateria?->clave_en_plan,
                'nombre' => $asignaturaGrupo->planMateria?->asignatura?->nombre,
                'plan' => $asignaturaGrupo->planMateria?->plan?->nombre,
                'grupo' => $asignaturaGrupo->grupo?->clave,
                'campus' => $asignaturaGrupo->grupo?->campus?->nombre,
                'ciclo' => $asignaturaGrupo->grupo?->ciclo?->clave,
                'soy' => $asignaturaGrupo->docentes->firstWhere('persona_id', $personaId)?->pivot?->tipo,
            ],
            'horarios' => $asignaturaGrupo->horarios->map(fn ($h) => [
                'dia' => $h->dia_semana,
                'inicio' => substr((string) $h->hora_inicio, 0, 5),
                'fin' => substr((string) $h->hora_fin, 0, 5),
                'aula' => $h->aula?->nombre,
            ])->values(),
            'companeros' => $asignaturaGrupo->docentes
                ->reject(fn ($d) => $d->persona_id === $personaId)
                ->map(fn ($d) => ['nombre' => $d->persona?->nombreCompleto(), 'tipo' => $d->pivot->tipo])
                ->values(),
            'alumnos' => $inscripciones->map(fn (Inscripcion $i) => [
                'matricula' => $i->matriculaOferta?->matricula,
                'nombre' => $i->matriculaOferta?->persona?->nombreCompleto(),
                'email' => $i->matriculaOferta?->persona?->email,
                'celular' => $i->matriculaOferta?->persona?->celular,
                'tipo' => $i->tipo,
                'situacion' => $i->situacion?->nombre,
                'de_baja' => $i->situacion?->clave === 'baja',
                'calificacion_final' => $i->calificacion_final,
            ]),
            'calendario' => $this->calendario->estadoPorParcial($asignaturaGrupo, $personaId),
            'puedeCapturar' => $request->user()->can('capturar-calificaciones'),
        ]);
    }

    /**
     * Persona del usuario. Sin ella no hay a qué acotar el alcance, así que se
     * cierra en vez de mostrar todo.
     */
    private function personaId(Request $request): int
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        return $usuario->persona_id
            ?? throw new AccessDeniedHttpException('Tu cuenta no está ligada a una persona.');
    }

    /**
     * Solo se entra a una materia propia. Se comprueba contra la asignación,
     * no contra el permiso: el permiso dice que puede dar clase, la asignación
     * dice en qué materia.
     */
    private function autorizarMateria(Request $request, AsignaturaGrupo $asignaturaGrupo): int
    {
        $personaId = $this->personaId($request);

        // La relación cuelga de la tabla `docentes` (PK persona_id), no de
        // `personas`: es la columna que hay que calificar.
        $esSuya = $asignaturaGrupo->docentes()
            ->where('docentes.persona_id', $personaId)
            ->exists();

        if (! $esSuya) {
            throw new AccessDeniedHttpException('Esa materia no es tuya.');
        }

        return $personaId;
    }

    private function cicloSeleccionado(Request $request): ?Ciclo
    {
        $id = $request->query('ciclo_id');

        return $id === null ? null : Ciclo::find($id);
    }

    /** El registro de docente del usuario, si lo tiene. */
    public static function docenteDe(?int $personaId): ?Docente
    {
        return $personaId === null ? null : Docente::query()->find($personaId);
    }
}
