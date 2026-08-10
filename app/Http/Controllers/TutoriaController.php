<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AccesoBitacoraTutoria;
use App\Models\ControlEscolar\SesionTutoria;
use App\Models\ControlEscolar\Tutoria;
use App\Models\Identidad\Persona;
use App\Services\EstadoDelAlumno;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        /*
         * Cuántas sesiones lleva cada tutoría y de cuándo es la última.
         *
         * Es lo que convierte la lista en una herramienta de trabajo: sin esto,
         * el tutor ve a quién va MAL pero no a quién lleva sin ver. Y son cosas
         * distintas —el que va bien y hace tres meses que no se sienta con él
         * es precisamente el que se le puede estar escapando—.
         *
         * Una consulta agregada por todas sus tutorías, no una por tutorado.
         */
        $sesiones = DB::table('sesiones_tutoria')
            ->whereIn('tutoria_id', $tutorias->pluck('id'))
            ->whereNull('deleted_at')
            ->groupBy('tutoria_id')
            ->select('tutoria_id', DB::raw('COUNT(*) as cuantas'), DB::raw('MAX(fecha) as ultima'))
            ->get()
            ->keyBy('tutoria_id');

        $tutorados = $tutorias
            ->filter(fn (Tutoria $t) => $t->alumno !== null)
            ->map(function (Tutoria $t) use ($sesiones) {
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
                    'sesiones' => (int) ($sesiones->get($t->id)->cuantas ?? 0),
                    'ultima_sesion' => $sesiones->get($t->id)->ultima ?? null,
                    /*
                     * Cuántos días desde la última. Se calcula aquí y no en la
                     * pantalla porque «hace mucho» es una regla, no un formato:
                     * si mañana la escuela decide que son 45 días, se cambia en
                     * un sitio.
                     */
                    'dias_sin_sesion' => isset($sesiones->get($t->id)->ultima)
                        ? (int) now()->startOfDay()->diffInDays($sesiones->get($t->id)->ultima, false) * -1
                        : null,
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
                // Nunca vistos o vistos hace más de dos meses: el que se
                // escapa sin hacer ruido.
                'sin_ver' => $ordenados->filter(
                    fn (array $t) => $t['sesiones'] === 0 || ($t['dias_sin_sesion'] ?? 0) > 60
                )->count(),
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

        /*
         * También se registra cuando la abre su propio tutor.
         *
         * Podría parecer vigilancia inútil —son sus notas, sobre su tutorado—,
         * pero sin esto la auditoría sólo responde «quién de la administración
         * lo abrió», no «quién lo vio». Y el caso que hay que poder reconstruir
         * es el segundo: una sesión que circula por la escuela pudo salir tanto
         * de coordinación como del propio portal del tutor, en su sesión
         * abierta y sin bloquear.
         */
        $this->registrarConsulta($request, $alumno);

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
                    'confidencial' => $s->confidencial,
                ]),
            'catalogos' => [
                'motivos' => collect(SesionTutoria::MOTIVOS)->map(fn ($t, $v) => ['valor' => $v, 'texto' => $t])->values(),
                'modalidades' => collect(SesionTutoria::MODALIDADES)->map(fn ($t, $v) => ['valor' => $v, 'texto' => $t])->values(),
            ],
            /*
             * Quién ha abierto esta bitácora, incluido el propio tutor.
             *
             * La transparencia va en las dos direcciones: coordinación ve al
             * tutor y el tutor ve a coordinación. Enseñárselo sólo a uno haría
             * de la auditoría una herramienta de vigilancia hacia abajo, que es
             * justo lo contrario de lo que debe ser.
             */
            'consultas' => AccesoBitacoraTutoria::query()
                ->where('alumno_persona_id', $alumno->id)
                ->with('persona')
                ->orderByDesc('creado_en')
                ->limit(20)
                ->get()
                // Se salta el acceso que se acaba de registrar: el propio.
                ->skip(1)
                ->map(fn (AccesoBitacoraTutoria $a) => [
                    'quien' => $a->persona?->nombreCompleto() ?? 'Alguien',
                    'cuando' => $a->creado_en?->toDateTimeString(),
                ])
                ->values(),
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
            'confidencial' => ['boolean'],
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
     * Corrige la marca de confidencial de una sesión ya anotada.
     *
     * ── Por qué se puede cambiar ───────────────────────────────────────────
     * La casilla se palomea o se olvida en el momento, con el alumno todavía
     * enfrente. Sin forma de corregirlo, un descuido deja una sesión ordinaria
     * escondida de coordinación —o, peor, una delicada a la vista de todos— y
     * la única salida sería borrarla y volverla a escribir, perdiendo su fecha
     * real.
     *
     * ── Sólo su autor ─────────────────────────────────────────────────────
     * Es él quien sabe si lo que anotó era delicado. Que coordinación pudiera
     * desmarcar lo confidencial vaciaría la figura: bastaría con quitarle el
     * candado a lo que se quisiera leer.
     *
     * ── Y sólo la marca ───────────────────────────────────────────────────
     * El texto no se toca. Lo anotado es su testimonio de lo que ocurrió; poder
     * reescribirlo después convertiría la bitácora en un documento negociable.
     * Quién hizo el cambio y cuándo queda en `updated_by` por el trait de
     * auditoría.
     */
    public function marcarConfidencial(Request $request, Persona $alumno, SesionTutoria $sesion): RedirectResponse
    {
        $tutoria = $this->miTutoriaCon($request, $alumno);

        // La sesión tiene que ser de ESA tutoría: sin esto, un id de otra
        // conversación en la URL bastaría para destapar la de un colega.
        if ((int) $sesion->tutoria_id !== (int) $tutoria->id) {
            throw new AccessDeniedHttpException('Esa sesión no es de esta tutoría.');
        }

        $confidencial = $request->boolean('confidencial');

        $sesion->update(['confidencial' => $confidencial]);

        return back(303)->with(
            'exito',
            $confidencial
                ? 'La sesión quedó marcada como confidencial: control escolar verá que ocurrió, pero no lo que anotaste.'
                : 'La sesión dejó de ser confidencial: quien tenga permiso podrá leerla.',
        );
    }

    /**
     * Deja constancia de que alguien abrió esta bitácora.
     *
     * Se cuenta la CONSULTA, no el contenido. Y sin `confidenciales_ocultas`
     * porque para su autor no hay nada reservado: la columna dice cuánto NO se
     * le mostró a quien miró, y a él se le muestra todo.
     */
    private function registrarConsulta(Request $request, Persona $alumno): void
    {
        AccesoBitacoraTutoria::create([
            'persona_id' => $request->user()?->persona_id,
            'alumno_persona_id' => $alumno->id,
            'sesiones_vistas' => SesionTutoria::query()
                ->whereIn('tutoria_id', Tutoria::query()
                    ->where('alumno_persona_id', $alumno->id)
                    ->pluck('id'))
                ->count(),
            'confidenciales_ocultas' => 0,
            'ip' => $request->ip(),
            'creado_en' => now(),
        ]);
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
