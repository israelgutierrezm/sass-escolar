<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Http\Controllers\Concerns\AlcanceDelAlumno;
use App\Http\Controllers\Concerns\OperaFinanzasEnLinea;
use App\Http\Controllers\Concerns\VeLaCarteraDelAlumno;
use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\DocumentoAlumno;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Services\ControlEscolar\DocumentosDelAlumno;
use App\Services\EstadoCuenta;
use App\Services\Finanzas\FinanzasParaApp;
use App\Services\GestorSolicitudFactura;
use App\Services\HistorialDelAlumno;
use App\Services\Lms\CursosDelAlumno;
use App\Services\Lms\EntregaDeActividad;
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
        private readonly DocumentosDelAlumno $documentosAlumno,
        private readonly EntregaDeActividad $entregas,
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

    // ── Mi expediente: los documentos que la escuela me pide ────────────────

    /**
     * Los comprobantes de MI expediente y el catálogo del ámbito alumno, del
     * servicio compartido con la web (`DocumentosDelAlumno`). Si el tutor entregó
     * alguno por mí, viaja «lo entregó …».
     */
    public function documentos(Request $peticion): JsonResponse
    {
        return response()->json($this->documentosAlumno->datos($this->personaDe($peticion)));
    }

    /**
     * Sube (o reemplaza) un comprobante mío. Multipart: documento_id + archivo.
     * El tipo debe ser del ÁMBITO ALUMNO. Re-subir reinicia la revisión.
     */
    public function subirDocumento(Request $peticion): JsonResponse
    {
        $personaId = $this->personaDe($peticion);

        $datos = $peticion->validate([
            'documento_id' => [
                'required',
                'integer',
                function (string $atributo, mixed $valor, callable $falla) {
                    $delAmbito = DocumentoRequerido::query()
                        ->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)
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

        $this->documentosAlumno->subir(
            $personaId,
            (int) $datos['documento_id'],
            $peticion->file('archivo'),
            $datos['descripcion'] ?? null,
            $datos['vigencia'] ?? null,
        );

        return response()->json(['ok' => true]);
    }

    /** Retira un comprobante mío (lo aceptado no se retira desde aquí). */
    public function eliminarDocumento(Request $peticion, DocumentoAlumno $documento): JsonResponse
    {
        $this->documentosAlumno->exigirDelAlumno($this->personaDe($peticion), $documento);

        $error = $this->documentosAlumno->eliminar($documento);
        AvisoParaElUsuario::si($error !== null, 422, (string) $error);

        return response()->json(['ok' => true]);
    }

    // ── Aula: entregar y marcar lecturas ────────────────────────────────────

    /**
     * Entrega (o reentrega) una actividad. Multipart: `contenido` y/o `archivos[]`.
     * La regla vive en `EntregaDeActividad` (la misma que la web): candado del
     * prerrequisito, sólo lo que se entrega y mientras esté abierto, reentregar
     * reemplaza. Devuelve si quedó marcada como fuera de tiempo.
     */
    public function entregarActividad(Request $peticion, Actividad $actividad): JsonResponse
    {
        $inscripcion = $this->miInscripcionParaActividad($peticion, $actividad);

        $datos = $peticion->validate([
            'contenido' => ['nullable', 'string', 'max:20000'],
            'archivos' => ['nullable', 'array', 'max:5'],
            'archivos.*' => ['file', 'max:20480'],
        ], [], ['contenido' => 'respuesta']);

        ['error' => $error, 'entrega' => $entrega] = $this->entregas->entregar(
            $actividad,
            $inscripcion,
            $datos['contenido'] ?? null,
            $peticion->file('archivos', []),
        );

        AvisoParaElUsuario::si($error !== null, 422, (string) $error);

        return response()->json(['ok' => true, 'tarde' => $entrega?->tarde ?? false]);
    }

    /** «Ya la terminé» sobre una LECTURA (una tarea se completa entregándola). */
    public function completarActividad(Request $peticion, Actividad $actividad): JsonResponse
    {
        $inscripcion = $this->miInscripcionParaActividad($peticion, $actividad);

        $error = $this->entregas->completarLectura($actividad, $inscripcion);
        AvisoParaElUsuario::si($error !== null, 422, (string) $error);

        return response()->json(['ok' => true]);
    }

    /** Deshacer el «ya la terminé». */
    public function descompletarActividad(Request $peticion, Actividad $actividad): JsonResponse
    {
        $inscripcion = $this->miInscripcionParaActividad($peticion, $actividad);

        $this->entregas->descompletarLectura($actividad, $inscripcion);

        return response()->json(['ok' => true]);
    }

    /**
     * Mi inscripción en la materia de esa actividad. Si no la curso, 403 con la
     * misma respuesta que si no existiera: probar ids no revela qué actividades hay.
     */
    private function miInscripcionParaActividad(Request $peticion, Actividad $actividad): Inscripcion
    {
        $agId = $actividad->curso?->asignatura_grupo_id;

        $inscripcion = $agId === null ? null : Inscripcion::query()
            ->where('asignatura_grupo_id', $agId)
            ->whereIn('matricula_oferta_id', $this->misMatriculas($peticion)->pluck('id'))
            ->first();

        return $inscripcion ?? AvisoParaElUsuario::lanzar(403, 'Esa actividad no es de una materia que curses.');
    }

    /** La persona autenticada. Sin ella no hay expediente que mostrar. */
    private function personaDe(Request $peticion): int
    {
        return (int) ($peticion->user()->persona_id
            ?? AvisoParaElUsuario::lanzar(403, 'Tu cuenta no está ligada a una persona.'));
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
