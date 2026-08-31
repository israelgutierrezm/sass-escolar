<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Models\Academico\Campus;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\DocumentoRequerido;
use App\Services\Expedientes\PanoramaDocumental;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Control de documentación»: el expediente de la escuela visto al revés.
 *
 * ── Contesta la pregunta que la ficha no puede ─────────────────────────────
 * El expediente de una persona dice qué le falta a ÉL. Quien lleva control
 * escolar pregunta «¿a cuántos les falta el acta?» y «¿cuántos comprobantes
 * tengo por revisar?», y para saberlo tenía que abrir las fichas una por una.
 *
 * ── Cuelga de la RAÍZ, no de `/escolar` ────────────────────────────────────
 * Habla de aspirantes, alumnos, docentes y tutores: cuatro oficios. Colgarla de
 * control escolar la pondría detrás de `ver-grupos`, que no tiene nada que ver
 * con revisar papeles —es la lección de la captura de calificaciones y la de la
 * descarga de adjuntos—.
 *
 * ── Y con `validar-expediente`, el permiso que ya existía ──────────────────
 * Es la pantalla de quien valida. Un permiso nuevo sólo para mirar obligaría a
 * cada escuela a descubrirlo, y quien no lo tenga no tiene nada que hacer aquí.
 */
class PanoramaDocumentalController extends Controller
{
    use AcotaPorCampus;

    public function __construct(private readonly PanoramaDocumental $panorama) {}

    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'ambito' => ['nullable', Rule::in(array_keys(DocumentoRequerido::AMBITOS))],
            'documento_id' => ['nullable', 'integer'],
            'estado' => ['nullable', Rule::in(array_keys(PanoramaDocumental::ESTADOS))],
            'campus_id' => ['nullable', 'integer'],
            'programa_academico_id' => ['nullable', 'integer'],
            'solo_activos' => ['nullable'],
        ]);

        $ambito = $filtros['ambito'] ?? DocumentoRequerido::AMBITO_ALUMNO;

        /*
         * VALIDAR NO ES CONVERTIR, y aquí todo llega de la barra de direcciones.
         *
         * La regla `integer` acepta «32» y lo devuelve tal cual, como cadena; y
         * `boolean` acepta «0» y también lo devuelve tal cual, que en PHP es una
         * cadena no vacía y por tanto verdadera. Sin castear, el filtro de
         * campus reventaba con un TypeError —un 500 al pulsar el desplegable— y
         * «sólo activos» no se podía apagar. Es exactamente la trampa que ya se
         * cobró el motor de reportes con las casillas.
         */
        $campusId = isset($filtros['campus_id']) ? (int) $filtros['campus_id'] : null;
        $programaId = isset($filtros['programa_academico_id']) ? (int) $filtros['programa_academico_id'] : null;
        $documentoId = isset($filtros['documento_id']) ? (int) $filtros['documento_id'] : null;
        $soloActivos = ! $request->has('solo_activos') || $request->boolean('solo_activos');

        $paraElServicio = [
            'campus' => $this->campusEnJuego($request, $campusId),
            'programa_academico_id' => $programaId,
            'solo_activos' => $soloActivos,
        ];

        $resumen = $this->panorama->resumen($ambito, $paraElServicio);

        /*
         * El detalle sólo se pide cuando hay un documento elegido: es la
         * consulta cara —recorre el universo entero— y la mayoría de las
         * visitas son para mirar el resumen y decidir por dónde empezar.
         */
        $estado = $filtros['estado'] ?? 'falta';

        $enFoco = collect($resumen['documentos'])->firstWhere('id', $documentoId);

        return Inertia::render('Escolar/Documentacion', [
            'ambito' => $ambito,
            'ambitos' => DocumentoRequerido::AMBITOS,
            'estados' => PanoramaDocumental::ESTADOS,
            'filtros' => [
                'documento_id' => $enFoco !== null ? $documentoId : null,
                'estado' => $estado,
                'campus_id' => $campusId,
                'programa_academico_id' => $programaId,
                'solo_activos' => $soloActivos,
            ],
            'total' => $resumen['total'],
            'documentos' => $resumen['documentos'],
            'enFoco' => $enFoco,
            'personas' => $enFoco !== null
                ? $this->panorama->personas($ambito, (int) $documentoId, $estado, $paraElServicio)
                : null,
            // Sólo los campus que su rol alcanza: ofrecer los demás en el filtro
            // sería ofrecer un filtro que no cambia nada.
            'campus' => Campus::query()
                ->when($this->alcanceCampus($request), fn ($q, array $ids) => $q->whereIn('id', $ids))
                ->orderBy('nombre')->get(['id', 'nombre']),
            'programas' => ProgramaAcademico::query()->orderBy('nombre')->get(['id', 'nombre']),
            'base' => $this->enlaces(),
        ]);
    }

    /**
     * Los campus que de verdad se van a mirar.
     *
     * Se CRUZA el filtro con el alcance del rol: un coordinador acotado al
     * campus norte que escriba otro id en la URL sigue viendo el suyo. Filtrar
     * la lista del desplegable no basta — el id viaja en la dirección.
     *
     * @return array<int, int>|null null = sin acotar (alcance global y sin filtro)
     */
    private function campusEnJuego(Request $request, ?int $elegido): ?array
    {
        $alcance = $this->alcanceCampus($request);

        if ($elegido === null) {
            return $alcance;
        }

        return $alcance === null
            ? [$elegido]
            : array_values(array_intersect($alcance, [$elegido]));
    }

    /**
     * Con qué se abre la ficha de cada ámbito.
     *
     * Va desde el servidor y no escrito en el Vue: son las mismas rutas que
     * usan la bandeja del panel, y tenerlas en dos sitios es como se llega a
     * que una quede vieja tras un renombre —que en este proyecto ya pasó—.
     *
     * @return array<string, string>
     */
    private function enlaces(): array
    {
        return [
            DocumentoRequerido::AMBITO_ASPIRANTE => '/aspirantes',
            DocumentoRequerido::AMBITO_ALUMNO => '/escolar/alumnos',
            DocumentoRequerido::AMBITO_DOCENTE => '/escolar/docentes',
            DocumentoRequerido::AMBITO_TUTOR => '/padres-tutores',
        ];
    }
}
