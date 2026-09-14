<?php

declare(strict_types=1);

namespace App\Services\ControlEscolar;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AccesoBitacoraTutoria;
use App\Models\ControlEscolar\SesionTutoria;
use App\Models\ControlEscolar\Tutoria;
use App\Models\Identidad\Persona;
use App\Services\EstadoDelAlumno;
use Illuminate\Support\Facades\DB;

/**
 * El seguimiento que un tutor educativo lleva de sus tutorados: a quiénes
 * acompaña, cómo va cada uno y qué se ha hablado (la bitácora).
 *
 * Vive aquí y no en el controlador porque la web (`TutoriaController`) y la app
 * lo comparten. Escrito dos veces, un lado acabaría acotando distinto —el
 * candado es lo único que impide leer la bitácora de un colega—, o midiendo el
 * «sin ver» y el «en riesgo» con otros umbrales.
 *
 * Ve lo ACADÉMICO, no lo financiero: un tutor educativo acompaña el avance; lo
 * que un alumno deba es asunto de su familia y de la escuela.
 */
class SeguimientoDeTutorados
{
    /** Vistos hace más de esto (o nunca) es «se está escapando sin hacer ruido». */
    private const DIAS_SIN_VER = 60;

    public function __construct(private readonly EstadoDelAlumno $estado) {}

    /**
     * Mis tutorados, ordenados por quién necesita atención (no por nombre), con
     * el resumen de cuántos van mal. `dias_sin_sesion` y los umbrales del
     * resumen se calculan aquí: «hace mucho» y «en riesgo» son reglas, no formato.
     *
     * @return array{tutorados: array<int, array<string, mixed>>, resumen: array<string, int>}
     */
    public function panorama(int $tutorId): array
    {
        $tutorias = Tutoria::query()->de($tutorId)->with(['alumno', 'ciclo:id,clave'])->get();

        // Una consulta agregada por todas sus tutorías, no una por tutorado.
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
                $agg = $sesiones->get($t->id);

                return [
                    'id' => $alumno->id,
                    'nombre' => $alumno->nombreCompleto(),
                    'foto' => $alumno->urlFoto(),
                    'programas_academicos' => $this->programasDe($alumno),
                    'ciclo' => $t->ciclo?->clave,
                    'estado' => $this->estado->de($alumno, academico: true, finanzas: false),
                    'sesiones' => (int) ($agg->cuantas ?? 0),
                    'ultima_sesion' => $agg->ultima ?? null,
                    'dias_sin_sesion' => isset($agg->ultima)
                        ? (int) now()->startOfDay()->diffInDays($agg->ultima, false) * -1
                        : null,
                ];
            })
            ->values();

        $ordenados = $tutorados->sortBy([
            fn (array $a, array $b) => ($b['estado']['reprobadas'] ?? 0) <=> ($a['estado']['reprobadas'] ?? 0),
            fn (array $a, array $b) => ($a['estado']['promedio'] ?? 99) <=> ($b['estado']['promedio'] ?? 99),
        ])->values();

        return [
            'tutorados' => $ordenados->all(),
            'resumen' => [
                'total' => $ordenados->count(),
                'reprobando' => $ordenados->filter(fn (array $t) => ($t['estado']['reprobadas'] ?? 0) > 0)->count(),
                'sin_ver' => $ordenados->filter(
                    fn (array $t) => $t['sesiones'] === 0 || ($t['dias_sin_sesion'] ?? 0) > self::DIAS_SIN_VER
                )->count(),
                'en_riesgo' => $ordenados->filter(function (array $t) {
                    $p = $t['estado']['promedio'];

                    return $p !== null && $p >= 6 && $p < 8 && ($t['estado']['reprobadas'] ?? 0) === 0;
                })->count(),
            ],
        ];
    }

    /**
     * La ficha de un tutorado: cómo va y la bitácora de lo hablado, con quién ha
     * abierto esa bitácora (la transparencia va en las dos direcciones). No
     * registra la consulta: eso lo dispara el llamador con `registrarConsulta`.
     *
     * @return array<string, mixed>
     */
    public function ficha(Tutoria $tutoria, Persona $alumno): array
    {
        return [
            'alumno' => [
                'id' => $alumno->id,
                'nombre' => $alumno->nombreCompleto(),
                'foto' => $alumno->urlFoto(),
                'matricula' => $alumno->matriculas()->first()?->matricula,
                'programas_academicos' => $this->programasDe($alumno),
            ],
            'estado' => $this->estado->de($alumno, academico: true, finanzas: false),
            'sesiones' => SesionTutoria::query()
                ->where('tutoria_id', $tutoria->id)
                ->orderByDesc('fecha')->orderByDesc('id')->get()
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
                ])->values()->all(),
            'catalogos' => $this->catalogos(),
            'consultas' => AccesoBitacoraTutoria::query()
                ->where('alumno_persona_id', $alumno->id)
                ->with('persona')
                ->orderByDesc('creado_en')
                ->limit(20)->get()
                ->skip(1) // el acceso que se acaba de registrar es el propio
                ->map(fn (AccesoBitacoraTutoria $a) => [
                    'quien' => $a->persona?->nombreCompleto() ?? 'Alguien',
                    'cuando' => $a->creado_en?->toDateTimeString(),
                ])->values()->all(),
        ];
    }

    /** @return array{motivos: array, modalidades: array} */
    public function catalogos(): array
    {
        return [
            'motivos' => collect(SesionTutoria::MOTIVOS)->map(fn ($t, $v) => ['valor' => $v, 'texto' => $t])->values()->all(),
            'modalidades' => collect(SesionTutoria::MODALIDADES)->map(fn ($t, $v) => ['valor' => $v, 'texto' => $t])->values()->all(),
        ];
    }

    /** Anota una sesión en la bitácora (los datos ya vienen validados). */
    public function registrarSesion(Tutoria $tutoria, array $datos): SesionTutoria
    {
        return SesionTutoria::create([...$datos, 'tutoria_id' => $tutoria->id]);
    }

    /**
     * Corrige SÓLO la marca de confidencial de una sesión —el texto no se toca—,
     * y sólo si la sesión es de ESTA tutoría (un id ajeno destaparía la de un colega).
     */
    public function marcarConfidencial(Tutoria $tutoria, SesionTutoria $sesion, bool $confidencial): void
    {
        AvisoParaElUsuario::si((int) $sesion->tutoria_id !== (int) $tutoria->id, 403, 'Esa sesión no es de esta tutoría.');

        $sesion->update(['confidencial' => $confidencial]);
    }

    /**
     * Deja constancia de que alguien abrió la bitácora —incluido el propio tutor:
     * lo que hay que poder reconstruir es quién la VIO, no sólo quién de la
     * administración—.
     */
    public function registrarConsulta(Persona $alumno, int $personaId, ?string $ip): void
    {
        AccesoBitacoraTutoria::create([
            'persona_id' => $personaId,
            'alumno_persona_id' => $alumno->id,
            'sesiones_vistas' => SesionTutoria::query()
                ->whereIn('tutoria_id', Tutoria::query()->where('alumno_persona_id', $alumno->id)->pluck('id'))
                ->count(),
            'confidenciales_ocultas' => 0, // para su autor no hay nada reservado
            'ip' => $ip,
            'creado_en' => now(),
        ]);
    }

    /**
     * La tutoría vigente entre el tutor y ESE alumno, o 403. Es el candado de
     * toda la pantalla: sin él, cambiar el id dejaría leer y anotar sobre alumnos
     * que no se acompañan.
     */
    public function exigirTutoriaCon(int $tutorId, Persona $alumno): Tutoria
    {
        $tutoria = Tutoria::query()->de($tutorId)->where('alumno_persona_id', $alumno->id)->first();

        return $tutoria ?? AvisoParaElUsuario::lanzar(403, 'Ese alumno no es tu tutorado.');
    }

    /** @return array<int, string> los programas de las matrículas del alumno */
    private function programasDe(Persona $alumno): array
    {
        return $alumno->matriculas()
            ->with('oferta.programaAcademico:id,nombre')
            ->get()
            ->map(fn (MatriculaOferta $m) => $m->oferta?->programaAcademico?->nombre)
            ->filter()
            ->values()
            ->all();
    }
}
