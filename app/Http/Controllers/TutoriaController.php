<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\SesionTutoria;
use App\Models\ControlEscolar\Tutoria;
use App\Models\Identidad\Persona;
use App\Services\EstadoDelAlumno;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * "Mis tutorados": el tutor educativo y los alumnos que acompaña.
 *
 * ── Qué arregla ────────────────────────────────────────────────────────────
 * El rol existía sin pantalla y sin nadie a quien tutorar. Sus permisos
 * —`ver-alumnos`, `ver-kardex`— le abrían el listado de TODA la escuela, no por
 * descuido de quien los asignó sino porque no había vínculo por el cual
 * acotarlo: «sus» alumnos no eran un conjunto que el sistema pudiera nombrar.
 * Ahora lo son, en `tutorias`.
 *
 * ── El alcance lo da la tutoría, no el permiso ─────────────────────────────
 * `ver-mis-tutorados` deja entrar; a QUIÉNES ve lo decide el vínculo, dentro
 * del controlador. Es el mismo criterio del portal del padre y del docente:
 * cambiar un id en la URL choca contra la pertenencia y devuelve 403.
 *
 * ── Y ve lo académico, no lo financiero ────────────────────────────────────
 * Un tutor educativo acompaña el avance: promedio, materias reprobadas, riesgo
 * de rezago. Lo que un alumno deba es asunto de su familia y de la escuela, no
 * de quien le da seguimiento académico, así que el estado de cuenta no se
 * consulta ni viaja.
 */
class TutoriaController extends Controller
{
    public function __construct(private readonly EstadoDelAlumno $estado) {}

    public function misTutorados(Request $request): Response
    {
        $tutorId = $this->miPersonaId($request);

        $tutorias = Tutoria::query()
            ->de($tutorId)
            ->with(['alumno', 'ciclo:id,clave'])
            ->get();

        $tutorados = $tutorias
            ->filter(fn (Tutoria $t) => $t->alumno !== null)
            ->map(function (Tutoria $t) {
                /** @var Persona $alumno */
                $alumno = $t->alumno;

                $carreras = $alumno->matriculas()
                    ->with('oferta.carrera:id,nombre')
                    ->get()
                    ->map(fn (MatriculaOferta $m) => $m->oferta?->carrera?->nombre)
                    ->filter()
                    ->values();

                return [
                    'id' => $alumno->id,
                    'nombre' => $alumno->nombreCompleto(),
                    'foto' => $alumno->urlFoto(),
                    'carreras' => $carreras,
                    'ciclo' => $t->ciclo?->clave,
                    // Sin finanzas: no es asunto suyo.
                    'estado' => $this->estado->de($alumno, academico: true, finanzas: false),
                ];
            })
            ->values();

        /*
         * Ordenados por quién necesita atención, no por nombre.
         *
         * Un tutor con veinte tutorados y una lista alfabética tiene que
         * leerlos todos para encontrar a los tres que van mal, cada vez que
         * entra. El alfabeto sirve para buscar a alguien concreto; esta
         * pantalla es para lo contrario: enterarse de a quién buscar.
         */
        $ordenados = $tutorados->sortBy([
            fn (array $a, array $b) => ($b['estado']['reprobadas'] ?? 0) <=> ($a['estado']['reprobadas'] ?? 0),
            fn (array $a, array $b) => ($a['estado']['promedio'] ?? 99) <=> ($b['estado']['promedio'] ?? 99),
        ])->values();

        return Inertia::render('Tutorias/MisTutorados', [
            'tutorados' => $ordenados,
            /*
             * El resumen se calcula aquí y no en la pantalla: es lo que el tutor
             * viene a saber —«tengo tres en riesgo»— y no debería depender de
             * que la vista sepa qué cuenta como riesgo.
             */
            'resumen' => [
                'total' => $ordenados->count(),
                'reprobando' => $ordenados->filter(fn (array $t) => ($t['estado']['reprobadas'] ?? 0) > 0)->count(),
                // Debajo de 8 sin llegar a reprobar: todavía se puede hacer algo,
                // que es justo cuando una tutoría sirve para algo.
                'en_riesgo' => $ordenados->filter(function (array $t) {
                    $p = $t['estado']['promedio'];

                    return $p !== null && $p >= 6 && $p < 8 && ($t['estado']['reprobadas'] ?? 0) === 0;
                })->count(),
            ],
        ]);
    }

    /**
     * La ficha de UN tutorado: cómo va y qué se ha hablado con él.
     *
     * Ficha propia y no la de control escolar: el tutor ya no tiene
     * `ver-alumnos` —abría el listado de toda la escuela—, así que mandarlo a
     * `/alumnos/{id}` sería mandarlo a un 403. Aquí ve lo suyo.
     */
    public function tutorado(Request $request, Persona $alumno): Response
    {
        $tutoria = $this->miTutoriaCon($request, $alumno);

        return Inertia::render('Tutorias/Tutorado', [
            'alumno' => [
                'id' => $alumno->id,
                'nombre' => $alumno->nombreCompleto(),
                'foto' => $alumno->urlFoto(),
                'matricula' => $alumno->matriculas()->first()?->matricula,
                'carreras' => $alumno->matriculas()
                    ->with('oferta.carrera:id,nombre')
                    ->get()
                    ->map(fn (MatriculaOferta $m) => $m->oferta?->carrera?->nombre)
                    ->filter()
                    ->values(),
            ],
            'estado' => $this->estado->de($alumno, academico: true, finanzas: false),
            'sesiones' => SesionTutoria::query()
                ->where('tutoria_id', $tutoria->id)
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->get()
                ->map(fn (SesionTutoria $s) => [
                    'id' => $s->id,
                    'fecha' => $s->fecha?->toDateString(),
                    'modalidad' => SesionTutoria::MODALIDADES[$s->modalidad] ?? $s->modalidad,
                    'motivo' => SesionTutoria::MOTIVOS[$s->motivo] ?? $s->motivo,
                    'motivo_clave' => $s->motivo,
                    'tema' => $s->tema,
                    'acuerdos' => $s->acuerdos,
                    'asistio' => $s->asistio,
                ]),
            'catalogos' => [
                'motivos' => collect(SesionTutoria::MOTIVOS)->map(fn ($t, $v) => ['valor' => $v, 'texto' => $t])->values(),
                'modalidades' => collect(SesionTutoria::MODALIDADES)->map(fn ($t, $v) => ['valor' => $v, 'texto' => $t])->values(),
            ],
        ]);
    }

    /** Anota una sesión en la bitácora. */
    public function registrarSesion(Request $request, Persona $alumno): RedirectResponse
    {
        $tutoria = $this->miTutoriaCon($request, $alumno);

        $datos = $request->validate([
            // No se anotan sesiones a futuro: la bitácora dice lo que PASÓ.
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'modalidad' => ['required', Rule::in(array_keys(SesionTutoria::MODALIDADES))],
            'motivo' => ['required', Rule::in(array_keys(SesionTutoria::MOTIVOS))],
            'tema' => ['required', 'string', 'max:2000'],
            'acuerdos' => ['nullable', 'string', 'max:2000'],
            'asistio' => ['boolean'],
        ], [
            'fecha.before_or_equal' => 'No puedes anotar una sesión que todavía no ocurre.',
        ], [
            'tema' => 'lo que se habló',
            'acuerdos' => 'los acuerdos',
        ]);

        SesionTutoria::create([...$datos, 'tutoria_id' => $tutoria->id]);

        return back(303)->with('exito', 'Sesión registrada en la bitácora.');
    }

    /**
     * La tutoría vigente entre quien entra y ESE alumno, o 403.
     *
     * Es el candado de toda la pantalla: sin él, cambiar el id en la URL
     * dejaría a un tutor leer —y anotar— sobre alumnos que no acompaña.
     */
    private function miTutoriaCon(Request $request, Persona $alumno): Tutoria
    {
        $tutoria = Tutoria::query()
            ->de($this->miPersonaId($request))
            ->where('alumno_persona_id', $alumno->id)
            ->first();

        if ($tutoria === null) {
            throw new AccessDeniedHttpException('Ese alumno no es tu tutorado.');
        }

        return $tutoria;
    }

    /** La persona del tutor, o 403 si quien entra no tiene una. */
    private function miPersonaId(Request $request): int
    {
        $id = $request->user()?->persona_id;

        if ($id === null) {
            throw new AccessDeniedHttpException('Tu cuenta no está ligada a una persona.');
        }

        return (int) $id;
    }
}
