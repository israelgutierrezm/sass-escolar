<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\Alumno;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\SesionTutoria;
use App\Models\ControlEscolar\Tutoria;
use App\Models\Identidad\Persona;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Repartir los alumnos entre los tutores educativos.
 *
 * ── Por qué es masivo y no de uno en uno ───────────────────────────────────
 * Una tutoría no se asigna sola: se reparte una generación completa al empezar
 * el ciclo. Con una pantalla de alta individual, repartir cien alumnos entre
 * cinco tutores son cien formularios. Aquí se filtra, se palomea y se asigna de
 * un golpe.
 *
 * ── La reasignación es lo normal, no la excepción ──────────────────────────
 * Un alumno ya asignado no da error al volverlo a asignar: cambia de tutor. Es
 * lo que ocurre cuando un tutor se va o cuando la carga queda dispareja, y
 * obligar a quitar antes de poner sería trabajo doble para el caso frecuente.
 * El único caso imposible es que dos personas acompañen al mismo alumno en el
 * mismo ciclo, y eso lo impide la llave única de la tabla.
 */
class AsignacionTutoriaController extends Controller
{
    public function index(Request $request): Response
    {
        $cicloId = $request->integer('ciclo_id') ?: null;

        /*
         * Quién puede tutorar: las personas con el rol de tutor educativo. No
         * cualquier docente —hay escuelas donde tutora el orientador, y hay
         * docentes que no tutoran—, y no se inventa aquí una lista propia:
         * quien reparte los roles ya decidió quiénes son.
         */
        $tutores = Persona::query()
            ->whereIn('id', DB::table('persona_rol')
                ->join('roles', 'roles.id', '=', 'persona_rol.rol_id')
                ->where('roles.name', 'tutor_educativo')
                ->whereNull('persona_rol.deleted_at')
                ->pluck('persona_rol.persona_id'))
            ->get()
            ->map(fn (Persona $p) => [
                'id' => $p->id,
                'nombre' => $p->nombreCompleto(),
                // Cuántos lleva ya: repartir a ciegas es como se producen
                // tutores con cuarenta alumnos y otros con tres.
                'tutorados' => Tutoria::query()
                    ->where('tutor_persona_id', $p->id)
                    ->where('activa', true)
                    ->when($cicloId !== null, fn ($q) => $q->where('ciclo_id', $cicloId))
                    ->count(),
            ])
            ->sortBy('nombre')
            ->values();

        $asignadas = Tutoria::query()
            ->where('activa', true)
            ->when($cicloId !== null, fn ($q) => $q->where('ciclo_id', $cicloId))
            ->with('tutor')
            ->get()
            ->keyBy('alumno_persona_id');

        /*
         * Las sesiones se cuentan de TODAS sus tutorías, no sólo de la vigente:
         * lo que interesa es si a ese alumno se le da seguimiento, y si lo
         * atendió el tutor del ciclo pasado también cuenta como que se le
         * atendió. Una sola consulta agregada para no hacer una por alumno.
         */
        $sesiones = DB::table('sesiones_tutoria')
            ->join('tutorias', 'tutorias.id', '=', 'sesiones_tutoria.tutoria_id')
            ->whereNull('sesiones_tutoria.deleted_at')
            ->groupBy('tutorias.alumno_persona_id')
            ->select(
                'tutorias.alumno_persona_id',
                DB::raw('COUNT(*) as cuantas'),
                DB::raw('MAX(sesiones_tutoria.fecha) as ultima'),
            )
            ->get()
            ->keyBy('alumno_persona_id');

        $alumnos = Alumno::query()
            ->with(['persona', 'matriculas.oferta.carrera:id,nombre'])
            ->get()
            ->map(function (Alumno $a) use ($asignadas, $sesiones) {
                $tutoria = $asignadas->get($a->persona_id);
                $conteo = $sesiones->get($a->persona_id);

                return [
                    'id' => $a->persona_id,
                    'nombre' => $a->persona?->nombreCompleto() ?? 'Alumno',
                    'matricula' => $a->matriculas->first()?->matricula,
                    'carrera' => $a->matriculas->first()?->oferta?->carrera?->nombre,
                    'tutor' => $tutoria?->tutor?->nombreCompleto(),
                    'tutoria_id' => $tutoria?->id,
                    /*
                     * Cuántas sesiones lleva y de cuándo es la última.
                     *
                     * Es lo que una coordinación de tutorías viene a saber:
                     * asignar tutores no sirve de nada si nadie comprueba que
                     * las sesiones ocurren. Un alumno con tutor desde marzo y
                     * cero sesiones es exactamente el caso que hay que ver, y
                     * sin esta columna no se distingue del que va al corriente.
                     */
                    'sesiones' => $conteo?->cuantas ?? 0,
                    'ultima_sesion' => $conteo?->ultima,
                ];
            })
            /*
             * Los que NO tienen tutor van primero: son los que hay que atender.
             * Ordenar por nombre dejaría el trabajo pendiente repartido por toda
             * la lista.
             */
            ->sortBy(fn (array $a) => [$a['tutor'] === null ? 0 : 1, $a['nombre']])
            ->values();

        return Inertia::render('Escolar/Tutorias', [
            'ciclos' => Ciclo::query()->orderByDesc('id')->get(['id', 'clave'])
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'nombre' => $c->clave]),
            'cicloSeleccionado' => $cicloId,
            'tutores' => $tutores,
            'alumnos' => $alumnos,
            'resumen' => [
                'total' => $alumnos->count(),
                'sin_tutor' => $alumnos->filter(fn (array $a) => $a['tutor'] === null)->count(),
            ],
        ]);
    }

    public function asignar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'tutor_persona_id' => ['required', 'integer', 'exists:personas,id'],
            'ciclo_id' => ['nullable', 'integer', 'exists:ciclos,id'],
            'alumnos' => ['required', 'array', 'min:1'],
            'alumnos.*' => ['integer', 'exists:alumnos,persona_id'],
        ], [], [
            'tutor_persona_id' => 'el tutor',
            'alumnos' => 'los alumnos',
        ]);

        /*
         * Nadie se tutora a sí mismo. Es raro —haría falta ser alumno y tutor a
         * la vez—, pero pasa en escuelas donde el personal estudia un posgrado,
         * y el resultado sería alguien vigilando su propio avance.
         */
        $datos['alumnos'] = array_values(array_filter(
            $datos['alumnos'],
            fn (int $id) => $id !== (int) $datos['tutor_persona_id'],
        ));

        if ($datos['alumnos'] === []) {
            return back(303)->with('error', 'Nadie puede ser su propio tutor.');
        }

        DB::transaction(function () use ($datos) {
            foreach ($datos['alumnos'] as $alumnoId) {
                Tutoria::actualizarOReviver(
                    ['alumno_persona_id' => $alumnoId, 'ciclo_id' => $datos['ciclo_id'] ?? null],
                    ['tutor_persona_id' => $datos['tutor_persona_id'], 'activa' => true],
                );
            }
        });

        $cuantos = count($datos['alumnos']);

        return back(303)->with(
            'exito',
            $cuantos === 1 ? 'Tutoría asignada.' : "{$cuantos} tutorías asignadas.",
        );
    }

    /**
     * La bitácora completa de un alumno, de todos sus tutores.
     *
     * ── Por qué la ve control escolar ──────────────────────────────────────
     * Porque es quien coordina: reparte las tutorías y responde cuando alguien
     * pregunta por qué un alumno se rezagó sin que nadie lo notara. Un tutor ve
     * lo suyo; aquí se ve el seguimiento del alumno completo, incluidas las
     * sesiones de tutores anteriores, que es lo que da continuidad cuando la
     * tutoría cambia de manos.
     *
     * ── Y por eso se lee, no se edita ──────────────────────────────────────
     * Lo que anotó un tutor es su testimonio de lo que ocurrió en esa sesión.
     * Dejar que coordinación lo corrija convertiría la bitácora en un documento
     * negociable, y su valor —el de servir de constancia— depende justo de que
     * no lo sea.
     */
    public function bitacora(Request $request, Persona $alumno): Response
    {
        $tutorias = Tutoria::query()
            ->withTrashed()
            ->where('alumno_persona_id', $alumno->id)
            ->with(['tutor', 'ciclo:id,clave'])
            ->get();

        $sesiones = SesionTutoria::query()
            ->whereIn('tutoria_id', $tutorias->pluck('id'))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->get()
            ->map(function (SesionTutoria $s) use ($tutorias, $request) {
                $tutoria = $tutorias->firstWhere('id', $s->tutoria_id);

                /*
                 * Lo confidencial NO VIAJA.
                 *
                 * Se omite en el servidor en vez de ocultarse con un `v-if`:
                 * una nota sobre violencia en casa escondida en la vista sigue
                 * estando en el JSON de la página, a un clic derecho de
                 * distancia. Sólo su autor —el tutor que la escribió— la ve, y
                 * él la lee desde su propia pantalla.
                 */
                $suya = $tutoria !== null
                    && (int) $tutoria->tutor_persona_id === (int) $request->user()?->persona_id;

                $reservada = $s->confidencial && ! $suya;

                return [
                    'id' => $s->id,
                    'fecha' => $s->fecha?->toDateString(),
                    'modalidad' => SesionTutoria::MODALIDADES[$s->modalidad] ?? $s->modalidad,
                    'motivo' => SesionTutoria::MOTIVOS[$s->motivo] ?? $s->motivo,
                    // Que la sesión OCURRIÓ sí se dice: es parte del seguimiento
                    // y de la constancia de que el tutor hizo su trabajo. Lo que
                    // se reserva es lo que se habló.
                    'confidencial' => $reservada,
                    'tema' => $reservada ? null : $s->tema,
                    'acuerdos' => $reservada ? null : $s->acuerdos,
                    'asistio' => $s->asistio,
                    // Quién la dio: con tutores que cambian entre ciclos, sin
                    // esto la bitácora es una lista de notas sin autor.
                    'tutor' => $tutoria?->tutor?->nombreCompleto(),
                    'ciclo' => $tutoria?->ciclo?->clave,
                ];
            });

        return Inertia::render('Escolar/BitacoraTutoria', [
            'alumno' => [
                'id' => $alumno->id,
                'nombre' => $alumno->nombreCompleto(),
                'matricula' => $alumno->matriculas()->first()?->matricula,
            ],
            'sesiones' => $sesiones,
            'tutores' => $tutorias
                ->map(fn (Tutoria $t) => [
                    'nombre' => $t->tutor?->nombreCompleto(),
                    'ciclo' => $t->ciclo?->clave,
                    'vigente' => $t->deleted_at === null && $t->activa,
                ])
                ->filter(fn (array $t) => $t['nombre'] !== null)
                ->values(),
        ]);
    }

    public function quitar(Request $request, Tutoria $tutoria): RedirectResponse
    {
        /*
         * Se desactiva, no se borra: que un alumno tuvo tutor en un ciclo es
         * parte de su expediente, y al revisar un rezago hay que poder saber
         * quién lo acompañaba. El borrado deja la llave única libre para el
         * siguiente, así que el soft delete del trait es justo lo que hace
         * falta.
         */
        $tutoria->delete();

        return back(303)->with('exito', 'Tutoría retirada.');
    }
}
