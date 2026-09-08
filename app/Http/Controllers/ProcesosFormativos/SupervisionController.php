<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProcesosFormativos;

use App\Http\Controllers\Controller;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Services\ProcesosFormativos\AlcanceDeExpedientes;
use App\Services\ProcesosFormativos\PresentadorDeSeguimiento;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El portal del SUPERVISOR EXTERNO: sólo sus practicantes, y sólo lo suyo.
 *
 * ── El alcance NO se decide aquí ────────────────────────────────────────────
 * Se delega en {@see AlcanceDeExpedientes}, que ya sabe acotar a un supervisor
 * por sus expedientes asignados con acceso vigente. Escribir el filtro aquí lo
 * dejaría fuera de la comprobación que hacen las ACCIONES —aprobar horas,
 * revisar informes— y el id viaja por la URL: filtrar la lista nunca ha sido
 * una defensa. Es la misma lección que este servicio ya tiene escrita.
 *
 * ── Lo que NO ve, a propósito ───────────────────────────────────────────────
 * Ni cartera, ni calificaciones, ni el expediente de admisión, ni los demás
 * alumnos. La pantalla muestra al practicante, sus horas para aprobar, sus
 * informes para revisar y su evaluación — nada más. Por eso NO reusa la
 * `Detalle` del coordinador, que trae documentos, liberación y transiciones que
 * no son asunto de un externo.
 */
class SupervisionController extends Controller
{
    public function __construct(
        private readonly AlcanceDeExpedientes $alcance,
        private readonly PresentadorDeSeguimiento $seguimiento,
    ) {}

    public function index(Request $peticion): Response
    {
        /** @var Usuario|null $quien */
        $quien = $peticion->user();

        $expedientes = $this->alcance
            ->acotar(
                ExpedienteProceso::query()->with([
                    'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                    'matricula.oferta.programaAcademico:id,nombre',
                    'tipoProceso:id,nombre',
                    'organizacion:id,razon_social,nombre_comercial',
                ]),
                $quien,
            )
            ->orderByDesc('id')
            ->get()
            ->map(fn (ExpedienteProceso $e) => [
                'id' => $e->id,
                'alumno' => $e->matricula?->persona?->nombreCompleto(),
                'programa' => $e->matricula?->oferta?->programaAcademico?->nombre,
                'tipo' => $e->tipoProceso?->nombre,
                'organizacion' => $e->organizacion?->comoSeLeConoce(),
                'estado' => $e->estado->value,
                'estado_texto' => $e->estado->etiqueta(),
                'estado_color' => $e->estado->color(),
                'horas_por_revisar' => $e->horas()->where('estado', 'capturada')->count(),
                'informes_por_revisar' => $e->informes()->whereIn('estado', ['entregado'])->count(),
            ])
            ->values();

        return Inertia::render('Supervision/Index', [
            'expedientes' => $expedientes,
        ]);
    }

    public function show(Request $peticion, ExpedienteProceso $expediente): Response
    {
        /** @var Usuario|null $quien */
        $quien = $peticion->user();

        // La misma puerta que usan las acciones: alcance de supervisor. Un
        // expediente de otro supervisor —o uno con el acceso revocado— responde
        // 403 aquí, antes de cargar un solo dato.
        $this->alcance->exigirQueAlcance($expediente, $quien);

        $expediente->load([
            'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
            'matricula.oferta.programaAcademico:id,nombre',
            'tipoProceso:id,nombre',
            'reglaVersion.regla',
            'organizacion:id,razon_social,nombre_comercial',
            'plaza:id,nombre',
            'modalidad:id,nombre',
            'horas.modalidad:id,nombre',
            'horas.capturadaPor.persona:id,nombre,primer_apellido,segundo_apellido',
            'informes.tipo:id,nombre,es_final',
            'evaluaciones.rubrica:id,nombre',
        ]);

        return Inertia::render('Supervision/Detalle', [
            'expediente' => [
                'id' => $expediente->id,
                'alumno' => $expediente->matricula?->persona?->nombreCompleto(),
                'programa' => $expediente->matricula?->oferta?->programaAcademico?->nombre,
                'tipo' => $expediente->tipoProceso?->nombre,
                'estado' => $expediente->estado->value,
                'estado_texto' => $expediente->estado->etiqueta(),
                'estado_color' => $expediente->estado->color(),
                'organizacion' => $expediente->organizacion?->comoSeLeConoce(),
                'plaza' => $expediente->plaza?->nombre,
                'modalidad' => $expediente->modalidad?->nombre,
                'fecha_inicio' => $expediente->fecha_inicio?->toDateString(),
                'fecha_fin_programada' => $expediente->fecha_fin_programada?->toDateString(),
                'regla' => $expediente->reglaVersion?->regla?->nombre,
                'horas' => $this->seguimiento->horas($expediente),
                'informes' => $this->seguimiento->informes($expediente),
                'evaluaciones' => $this->seguimiento->evaluaciones($expediente),
            ],
            'rubricas' => $this->seguimiento->rubricasParaEvaluar(),
            'origenesEvaluacion' => $this->seguimiento->origenesEvaluacion(),
            'puedeAprobarHoras' => $quien?->can('aprobar-horas-formativas') ?? false,
            'puedeRevisarInformes' => $quien?->can('revisar-informes-formativos') ?? false,
        ]);
    }
}
