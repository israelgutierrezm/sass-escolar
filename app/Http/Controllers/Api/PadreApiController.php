<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Disciplina\Incidencia;
use App\Models\Disciplina\Sancion;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Services\EstadoCuenta;
use App\Services\EstadoDelAlumno;
use App\Services\HistorialDelAlumno;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El portal de la FAMILIA para la app móvil.
 *
 * ── Una sola verdad, no una segunda para la app ────────────────────────────
 * No calcula nada por su cuenta: el estado de cada hijo sale de `EstadoDelAlumno`,
 * el promedio y los renglones de `HistorialDelAlumno`, y el saldo de
 * `EstadoCuenta` —los mismos servicios que la web y el portal del alumno—. El
 * día que una regla cambie, la web y la app dicen lo mismo porque leen del
 * mismo sitio.
 *
 * ── Qué se sirve, y qué NO todavía ─────────────────────────────────────────
 * El NÚCLEO de lectura, como hizo el portal del alumno: la lista de hijos con su
 * estado, y por cada hijo lo académico, lo financiero y la conducta. Los flujos
 * INTERACTIVOS del portal web —pagar en línea, solicitar factura, entregar
 * documentos, salida segura, citas— son rebanadas posteriores; aquí no viajan.
 *
 * ── El alcance lo pone el VÍNCULO, no la URL ───────────────────────────────
 * Qué hijo es suyo lo decide `tutores_alumno` (la misma puerta que la web): un
 * id de persona ajeno no está vinculado y responde 403. Y qué le dejó ver la
 * escuela —académico, financiero— sale del pivote del vínculo, no del permiso:
 * el permiso deja entrar al portal, el vínculo decide qué se enseña.
 *
 * ── La faceta la fija `api.faceta:padre_familia` ───────────────────────────
 * De él depende que el `Gate::before` de `ver-conducta-hijo` y el ámbito de
 * `EstadoCuenta` resuelvan como FAMILIA. Sin ese middleware, un permiso de la
 * faceta no tendría rol activo contra el que comprobarse.
 */
class PadreApiController extends Controller
{
    public function __construct(
        private readonly EstadoDelAlumno $estadoDelAlumno,
        private readonly HistorialDelAlumno $historial,
        private readonly EstadoCuenta $estadoCuenta,
    ) {}

    /** Los hijos vinculados, con su estado según lo que la escuela le dejó ver. */
    public function hijos(Request $peticion): JsonResponse
    {
        $persona = $peticion->user()->persona;

        $hijos = $persona->hijos()->get()->map(function (Persona $hijo) {
            $verAcademico = (bool) $hijo->pivot->puede_ver_academico;
            $verFinanzas = (bool) $hijo->pivot->puede_ver_finanzas;

            $programas = $hijo->matriculas()
                ->with('oferta.programaAcademico:id,nombre')
                ->get()
                ->map(fn (MatriculaOferta $m) => $m->oferta?->programaAcademico?->nombre)
                ->filter()
                ->values();

            return [
                'id' => $hijo->id,
                'nombre' => $hijo->nombreCompleto(),
                'parentesco' => Parentesco::nombreDe($hijo->pivot->parentesco_id),
                'programas_academicos' => $programas,
                'puede_ver_academico' => $verAcademico,
                'puede_ver_finanzas' => $verFinanzas,
                // El ESTADO, no sólo el nombre: si debe algo o si va mal. Se
                // respeta lo que la escuela le dejó ver —la señal no existe, en
                // vez de ocultarse en la vista—. Mismo servicio que la web.
                'estado' => $this->estadoDelAlumno->de($hijo, $verAcademico, $verFinanzas),
            ];
        })->values();

        return response()->json(['hijos' => $hijos]);
    }

    /** Un hijo: académico, finanzas y conducta, según los permisos del vínculo. */
    public function hijo(Request $peticion, Persona $hijo): JsonResponse
    {
        $vinculo = TutorAlumno::query()
            ->where('tutor_persona_id', $peticion->user()->persona_id)
            ->where('alumno_persona_id', $hijo->id)
            ->first();

        AvisoParaElUsuario::si($vinculo === null, 403, 'Este alumno no está vinculado a tu cuenta.');

        $matriculas = $hijo->matriculas()
            ->with([
                'oferta.programaAcademico:id,nombre',
                'oferta.plan:id,nombre,total_creditos',
                'oferta.campus:id,nombre',
                'situacion:id,nombre',
            ])
            ->orderByDesc('fecha_ingreso')
            ->get();

        return response()->json([
            'hijo' => [
                'id' => $hijo->id,
                'nombre' => $hijo->nombreCompleto(),
                'curp' => $hijo->curp,
                'parentesco' => $vinculo->parentesco?->nombre,
            ],
            'permisos' => [
                'academico' => (bool) $vinculo->puede_ver_academico,
                'finanzas' => (bool) $vinculo->puede_ver_finanzas,
            ],
            'academico' => $vinculo->puede_ver_academico
                ? $matriculas->map(fn (MatriculaOferta $m) => $this->academicoDe($m))->values()
                : null,
            'finanzas' => $vinculo->puede_ver_finanzas
                ? $matriculas->map(fn (MatriculaOferta $m) => $this->finanzasDe($m))->values()
                : null,
            // La conducta va con el permiso de faceta —no con el vínculo, que
            // distingue académico de financiero pero no disciplina— y sólo si el
            // módulo está encendido.
            'conducta' => ($peticion->user()->can('ver-conducta-hijo') && app(ModulosDeLaEscuela::class)->activo('disciplina'))
                ? $this->conductaDe($matriculas)
                : null,
        ]);
    }

    /**
     * Lo académico de una matrícula, con las cifras del servicio compartido.
     *
     * El promedio y los créditos salen de `HistorialDelAlumno` —el mejor intento
     * por materia, la regla oficial— y no de sumar renglones aquí; los renglones
     * se enseñan todos, porque son historia escolar.
     *
     * @return array<string, mixed>
     */
    private function academicoDe(MatriculaOferta $m): array
    {
        $resumen = $this->historial->resumen($m);

        return [
            'matricula' => $m->matricula,
            'programa_academico' => $m->oferta?->programaAcademico?->nombre,
            'plan' => $m->oferta?->plan?->nombre,
            'estatus' => $m->estatus,
            'promedio' => $resumen['promedio'],
            'creditos' => $resumen['creditos'],
            'creditos_del_plan' => $resumen['creditos_del_plan'],
            'renglones' => $this->historial->renglones($m),
        ];
    }

    /**
     * Lo financiero de una matrícula, con la cuenta del servicio compartido.
     *
     * @return array<string, mixed>
     */
    private function finanzasDe(MatriculaOferta $m): array
    {
        return [
            'matricula_id' => $m->id,
            'matricula' => $m->matricula,
            'programa_academico' => $m->oferta?->programaAcademico?->nombre,
            // El mismo servicio que la pantalla de finanzas y el expediente.
            'cuenta' => $this->estadoCuenta->para($m),
        ];
    }

    /**
     * Incidencias y sanciones de todas las matrículas del hijo. De sólo lectura:
     * la familia CONSULTA, no registra.
     *
     * @param  Collection<int, MatriculaOferta>  $matriculas
     * @return array{incidencias: array<int, mixed>, sanciones: array<int, mixed>}
     */
    private function conductaDe(Collection $matriculas): array
    {
        $ids = $matriculas->pluck('id');

        $incidencias = Incidencia::query()
            ->whereIn('matricula_oferta_id', $ids)
            ->with('tipo:id,nombre,nivel')
            ->orderByDesc('fecha')
            ->limit(50)
            ->get()
            ->map(fn (Incidencia $i) => [
                'id' => $i->id,
                'tipo' => $i->tipo?->nombre,
                'nivel' => $i->tipo?->nivel,
                'fecha' => $i->fecha?->format('Y-m-d'),
                'descripcion' => $i->descripcion,
            ])->all();

        $sanciones = Sancion::query()
            ->whereIn('matricula_oferta_id', $ids)
            ->with('tipo:id,nombre')
            ->orderByDesc('fecha')
            ->limit(50)
            ->get()
            ->map(fn (Sancion $s) => [
                'id' => $s->id,
                'tipo' => $s->tipo?->nombre,
                'fecha' => $s->fecha?->format('Y-m-d'),
                'desde' => $s->desde?->format('Y-m-d'),
                'hasta' => $s->hasta?->format('Y-m-d'),
                'vigente' => $s->vigente(),
                'motivo' => $s->motivo,
            ])->all();

        return ['incidencias' => $incidencias, 'sanciones' => $sanciones];
    }
}
