<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Http\Controllers\Concerns\AlcanceDelAlumno;
use App\Http\Controllers\Concerns\OperaFinanzasEnLinea;
use App\Http\Controllers\Concerns\VeLaCarteraDelAlumno;
use App\Http\Controllers\Controller;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Inscripcion;
use App\Services\EstadoCuenta;
use App\Services\Finanzas\FinanzasParaApp;
use App\Services\GestorSolicitudFactura;
use App\Services\HistorialDelAlumno;
use App\Services\Lms\CursosDelAlumno;
use App\Services\Pagos\CobroEnLinea;
use App\Services\Pagos\RegistroDeComprobante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Los datos del alumno para la app móvil.
 *
 * ── Una sola verdad, no una segunda para la app ────────────────────────────
 * No calcula nada por su cuenta: cada endpoint reusa el servicio que ya arma
 * ese dato para la web —`CursosDelAlumno`, `HistorialDelAlumno`, `EstadoCuenta`—.
 * El día que una regla cambie (qué cuenta como avance, cómo se promedia, qué
 * saldo se muestra), la web y la app dicen lo mismo porque leen del mismo sitio.
 *
 * ── El alcance sale del TOKEN, no de la URL ────────────────────────────────
 * Como en el portal web, qué inscripciones y matrículas son suyas lo resuelve
 * `AlcanceDelAlumno` contra la persona autenticada. No hay id de alumno que
 * cambiar para asomarse a lo de otro.
 *
 * ── La faceta la fija el middleware `api.faceta:alumno` ─────────────────────
 * De él depende que el `Gate::before` de los `can:` y el ámbito de
 * `EstadoCuenta` (y de `VeLaCarteraDelAlumno`) resuelvan como ALUMNO. Sin ese
 * middleware, un permiso de alumno concedido no tendría rol activo contra el
 * que comprobarse.
 *
 * ── Factura y pago, del mismo trait que la familia ──────────────────────────
 * Solicitar/generar factura, pagar en línea y subir el comprobante viven en
 * `OperaFinanzasEnLinea`, compartido con el portal de la familia —como la web,
 * un solo controlador para los dos—. De quién es la cuenta lo cierra
 * `VeLaCarteraDelAlumno`: para la faceta ALUMNO, sus PROPIAS matrículas.
 */
class AlumnoApiController extends Controller
{
    use AcotaPorCampus;
    use AlcanceDelAlumno;
    use OperaFinanzasEnLinea;
    use VeLaCarteraDelAlumno;

    public function __construct(
        private readonly CursosDelAlumno $cursos,
        private readonly HistorialDelAlumno $historial,
        private readonly EstadoCuenta $estadoCuenta,
        private readonly FinanzasParaApp $finanzasApp,
        private readonly GestorSolicitudFactura $gestorFactura,
        private readonly CobroEnLinea $cobro,
        private readonly RegistroDeComprobante $registroComprobante,
    ) {}

    /** Sus materias, agrupadas por ciclo, con lo que le falta entregar. */
    public function materias(Request $peticion): JsonResponse
    {
        $inscripciones = $this->misInscripciones($peticion)
            ->with(CursosDelAlumno::relacionesListado())
            ->get()
            ->reject(fn (Inscripcion $i) => $i->situacion?->clave === 'baja');

        return response()->json($this->cursos->listado($inscripciones));
    }

    /** Una materia: evaluación, actividades, asistencia, docentes, clases en línea. */
    public function materia(Request $peticion, int $asignaturaGrupo): JsonResponse
    {
        // 403 con su motivo si no es suya: la misma puerta que la web, en el trait.
        $inscripcion = $this->miInscripcionEn($peticion, $asignaturaGrupo, CursosDelAlumno::relacionesDetalle());

        return response()->json($this->cursos->detalle($inscripcion));
    }

    /** Su historial académico, con selector de matrícula para quien tiene varias. */
    public function historial(Request $peticion): JsonResponse
    {
        $matriculas = $this->misMatriculas($peticion);
        $elegida = $this->elegirMatricula($peticion, $matriculas);

        if ($elegida === null) {
            return response()->json([
                'matriculas' => [],
                'matricula' => null,
                'renglones' => [],
                'resumen' => null,
            ]);
        }

        // El PLAN entero (sin lista de columnas): de él salen los denominadores
        // del resumen y la escala del promedio. Es la misma trampa que ya mordió
        // la pantalla web: pedir `plan:id,nombre` deja esas columnas en NULL.
        $elegida->load(['oferta.programaAcademico:id,nombre,nivel_estudios_id', 'oferta.plan', 'oferta.campus:id,nombre']);

        return response()->json([
            'matriculas' => $this->matriculasComoLista($matriculas),
            'matricula' => [
                'id' => $elegida->id,
                'matricula' => $elegida->matricula,
                'programa_academico' => $elegida->oferta?->programaAcademico?->nombre,
                'plan' => $elegida->oferta?->plan?->nombre,
                'campus' => $elegida->oferta?->campus?->nombre,
                'generacion' => $elegida->generacion,
            ],
            // Los mismos que ve control escolar, del mismo servicio.
            'renglones' => $this->historial->renglones($elegida),
            'resumen' => $this->historial->resumen($elegida),
        ]);
    }

    /** Su estado de cuenta: adeudos, pagos, resumen y situación financiera. */
    public function estadoCuenta(Request $peticion): JsonResponse
    {
        $matriculas = $this->misMatriculas($peticion);
        $elegida = $this->elegirMatricula($peticion, $matriculas);

        if ($elegida === null) {
            return response()->json([
                'matriculas' => [],
                'matricula' => null,
                'cuenta' => null,
            ]);
        }

        $elegida->load(['oferta.programaAcademico:id,nombre', 'oferta.campus:id,nombre', 'situacion:id,nombre']);

        // El alumno ve su PROPIA cartera: no hay vínculo que gatear como en la
        // familia. El modo de factura sale de sus permisos y del canal de la
        // escuela; el bloque de pago, de las pasarelas encendidas.
        $modo = $this->finanzasApp->facturaModo($peticion->user());

        return response()->json([
            'matriculas' => $this->matriculasComoLista($matriculas),
            'matricula' => [
                'id' => $elegida->id,
                'matricula' => $elegida->matricula,
                'programa_academico' => $elegida->oferta?->programaAcademico?->nombre,
                'campus' => $elegida->oferta?->campus?->nombre,
                'estatus' => $elegida->estatus,
                'situacion' => $elegida->situacion?->nombre,
            ],
            // El mismo servicio que la pantalla de finanzas y el expediente.
            'cuenta' => $this->estadoCuenta->para($elegida),
            // Factura (facturas, autoservicio, solicitudes) y cuentas para
            // transferencia: el mismo armado que el portal de la familia.
            ...$this->finanzasApp->facturaYCuentas($elegida, $modo !== null),
            'factura_modo' => $modo,
            'pago' => $this->finanzasApp->pago(),
        ]);
    }

    /**
     * La matrícula pedida (`?matricula=`) de entre las SUYAS, o la primera.
     *
     * Se busca dentro de `$matriculas` —que ya salió de su persona—, así que un
     * id ajeno no encuentra pareja y cae a la propia: no hay forma de leer el
     * historial ni la cuenta de otro cambiando el parámetro.
     *
     * @param  Collection<int, MatriculaOferta>  $matriculas
     */
    private function elegirMatricula(Request $peticion, Collection $matriculas): ?MatriculaOferta
    {
        if ($matriculas->isEmpty()) {
            return null;
        }

        return $matriculas->firstWhere('id', (int) $peticion->query('matricula'))
            ?? $matriculas->first();
    }

    /**
     * @param  Collection<int, MatriculaOferta>  $matriculas
     * @return array<int, array<string, mixed>>
     */
    private function matriculasComoLista(Collection $matriculas): array
    {
        return $matriculas
            ->map(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'programa_academico' => $m->oferta?->programaAcademico?->nombre,
            ])
            ->values()
            ->all();
    }
}
