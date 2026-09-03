<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProcesosFormativos;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Lms\Rubrica;
use App\Models\ProcesosFormativos\BitacoraHoras;
use App\Models\ProcesosFormativos\EvaluacionProceso;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\InformeProceso;
use App\Services\ProcesosFormativos\AlcanceDeExpedientes;
use App\Services\ProcesosFormativos\InformesYEvaluaciones;
use App\Services\ProcesosFormativos\RegistradorDeHoras;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El seguimiento del expediente: horas, informes y evaluaciones.
 *
 * ── Las mismas rutas para los DOS oficios ─────────────────────────────────
 * El alumno captura sus horas y entrega sus informes; la escuela las aprueba y
 * los revisa. Lo que separa a uno de otro NO es la ruta sino lo que cada acto
 * comprueba: capturar exige ser dueño del expediente, aprobar exige el permiso.
 * Con dos juegos de rutas, la validación del traslape acabaría escrita dos
 * veces — es la lección de la descarga de adjuntos de entrega, que estaba bajo
 * el permiso de un solo oficio y rebotaba al otro.
 *
 * ── Por eso el grupo NO lleva `can:` ──────────────────────────────────────
 * Una ruta que sirven dos oficios no puede colgar del permiso de uno. Lo
 * resuelve cada método, con el par de siempre: el permiso dice QUÉ, la
 * propiedad dice SOBRE QUIÉN.
 */
class SeguimientoFormativoController extends Controller
{
    public function __construct(
        private readonly RegistradorDeHoras $horas,
        private readonly InformesYEvaluaciones $papeleo,
        private readonly AlcanceDeExpedientes $alcance,
        private readonly Ajustes $ajustes,
    ) {}

    public function capturarHoras(Request $peticion, ExpedienteProceso $expediente): RedirectResponse
    {
        $this->exigirQuePuedaCapturar($peticion, $expediente);

        $datos = $this->validarJornada($peticion);

        $datos['evidencia_ruta'] = $this->guardarEvidencia($peticion, $expediente);

        $this->horas->capturar($expediente, $datos, $peticion->user());

        return back(303)->with('exito', 'Jornada registrada. Cuenta en cuanto alguien la apruebe.');
    }

    public function corregirHoras(Request $peticion, ExpedienteProceso $expediente, BitacoraHoras $jornada): RedirectResponse
    {
        $this->exigirQueSeaDelExpediente($expediente, $jornada);
        $this->exigirQuePuedaCapturar($peticion, $expediente);

        $this->horas->corregir($jornada, $this->validarJornada($peticion), $peticion->user());

        return back(303)->with('exito', 'Jornada corregida. Vuelve a la cola de revisión.');
    }

    /**
     * Aprobar o rechazar. UN método para los dos: son el mismo acto con el
     * mismo permiso y el mismo candado de concurrencia, y partirlo haría que
     * uno de los dos se quedara sin alguna de las comprobaciones.
     */
    public function revisarHoras(Request $peticion, ExpedienteProceso $expediente, BitacoraHoras $jornada): RedirectResponse
    {
        $this->exigirQueSeaDelExpediente($expediente, $jornada);

        $datos = $peticion->validate([
            'aprobada' => ['required', 'boolean'],
            'motivo' => ['nullable', 'string', 'max:1000'],
        ]);

        // `validate` con `boolean` ACEPTA la cadena «0» y la devuelve tal cual,
        // que en PHP es verdadera. Es la trampa que ya se cobró el motor de
        // reportes: se convierte a mano.
        $peticion->boolean('aprobada')
            ? $this->horas->aprobar($jornada, $peticion->user())
            : $this->horas->rechazar($jornada, (string) ($datos['motivo'] ?? ''), $peticion->user());

        return back(303)->with(
            'exito',
            $peticion->boolean('aprobada') ? 'Jornada aprobada: ya cuenta.' : 'Jornada rechazada con su motivo.',
        );
    }

    public function verEvidencia(Request $peticion, ExpedienteProceso $expediente, BitacoraHoras $jornada): StreamedResponse
    {
        $this->exigirQueSeaDelExpediente($expediente, $jornada);
        $this->exigirQueLoAlcance($peticion, $expediente);

        AvisoParaElUsuario::aMenosQue(
            $jornada->evidencia_ruta !== null && Storage::disk('local')->exists($jornada->evidencia_ruta),
            404,
            'Esa jornada no tiene evidencia.',
        );

        return Storage::disk('local')->download($jornada->evidencia_ruta, 'evidencia-'.$jornada->id.'.pdf');
    }

    public function entregarInforme(Request $peticion, ExpedienteProceso $expediente, InformeProceso $informe): RedirectResponse
    {
        AvisoParaElUsuario::aMenosQue(
            (int) $informe->expediente_id === (int) $expediente->id,
            404,
            'Ese informe no es de este expediente.',
        );

        $this->exigirQuePuedaCapturar($peticion, $expediente, exigirEnCurso: false);

        $peticion->validate([
            'archivo' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx'],
        ]);

        $archivo = $peticion->file('archivo');

        $ruta = $archivo->storeAs(
            'procesos-formativos/'.$expediente->id.'/informes',
            Str::uuid()->toString().'.'.$archivo->getClientOriginalExtension(),
            'local',
        );

        $this->papeleo->entregar($informe, $ruta, $archivo->getClientOriginalName());

        return back(303)->with('exito', 'Informe entregado.');
    }

    public function revisarInforme(Request $peticion, ExpedienteProceso $expediente, InformeProceso $informe): RedirectResponse
    {
        AvisoParaElUsuario::aMenosQue(
            (int) $informe->expediente_id === (int) $expediente->id,
            404,
            'Ese informe no es de este expediente.',
        );

        $datos = $peticion->validate([
            'aceptado' => ['required', 'boolean'],
            'retroalimentacion' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->papeleo->revisar(
            $informe,
            $peticion->boolean('aceptado'),
            $datos['retroalimentacion'] ?? null,
            $peticion->user(),
        );

        return back(303)->with('exito', $peticion->boolean('aceptado') ? 'Informe aceptado.' : 'Informe devuelto.');
    }

    public function verInforme(Request $peticion, ExpedienteProceso $expediente, InformeProceso $informe): StreamedResponse
    {
        AvisoParaElUsuario::aMenosQue(
            (int) $informe->expediente_id === (int) $expediente->id,
            404,
            'Ese informe no es de este expediente.',
        );

        $this->exigirQueLoAlcance($peticion, $expediente);

        AvisoParaElUsuario::aMenosQue(
            $informe->archivo_ruta !== null && Storage::disk('local')->exists($informe->archivo_ruta),
            404,
            'Ese informe todavía no se ha entregado.',
        );

        return Storage::disk('local')->download($informe->archivo_ruta, $informe->nombre_original ?? 'informe');
    }

    public function evaluar(Request $peticion, ExpedienteProceso $expediente): RedirectResponse
    {
        $datos = $peticion->validate([
            'origen' => ['required', 'string', Rule::in(array_keys(EvaluacionProceso::ORIGENES))],
            'rubrica_id' => ['nullable', 'integer', 'exists:rubricas,id'],
            'niveles' => ['nullable', 'array'],
            'niveles.*' => ['integer'],
            'comentarios' => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * La AUTOEVALUACIÓN la captura el alumno; las otras dos, la escuela.
         * Sin separarlas, un estudiante capturaría la evaluación de su propio
         * supervisor — y ésa es exactamente la que decide si se libera.
         */
        $datos['origen'] === EvaluacionProceso::ESTUDIANTE
            ? $this->exigirQuePuedaCapturar($peticion, $expediente, exigirEnCurso: false)
            : $this->exigirQueRevise($peticion, $expediente);

        $rubrica = $datos['rubrica_id'] === null
            ? null
            : Rubrica::query()->with('criterios.niveles')->findOrFail($datos['rubrica_id']);

        /*
         * Sólo rúbricas de la ESCUELA. Una propia de un docente es su borrador
         * de trabajo, y evaluar con ella dejaría la evaluación colgando de algo
         * que su dueño puede borrar. Mismo criterio que la plantilla del plan.
         */
        AvisoParaElUsuario::si(
            $rubrica !== null && ! $rubrica->esDePlataforma(),
            422,
            'Esa rúbrica es de un docente. Aquí sólo se evalúa con las de la escuela.',
        );

        $this->papeleo->evaluar(
            $expediente,
            $datos['origen'],
            $rubrica,
            array_map('intval', $datos['niveles'] ?? []),
            $datos['comentarios'] ?? null,
            $peticion->user(),
        );

        return back(303)->with('exito', 'Evaluación guardada.');
    }

    /** @return array<string, mixed> */
    private function validarJornada(Request $peticion): array
    {
        $datos = $peticion->validate([
            'fecha' => ['required', 'date'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i'],
            'minutos_descanso' => ['nullable', 'integer', 'min:0', 'max:600'],
            'actividad' => ['required', 'string', 'min:5', 'max:1000'],
            'modalidad_id' => ['nullable', 'integer', 'exists:modalidades_proceso,id'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        /*
         * La ubicación se DESCARTA si la escuela no la pide.
         *
         * El interruptor gobierna la pantalla, pero una petición a mano puede
         * traerla igual: sin descartarla aquí, apagar el ajuste dejaría de
         * proteger nada. Y descartar es lo correcto, no rechazar — quien manda
         * coordenadas de más no está haciendo daño, sólo mandando algo que no se
         * guarda.
         */
        if (! $this->ajustes->bool(CatalogoAjustes::PROCESOS_PEDIR_UBICACION)) {
            unset($datos['latitud'], $datos['longitud']);
        }

        $datos['fecha'] = substr((string) $datos['fecha'], 0, 10);

        return $datos;
    }

    private function guardarEvidencia(Request $peticion, ExpedienteProceso $expediente): ?string
    {
        if (! $peticion->hasFile('evidencia')) {
            return null;
        }

        $peticion->validate([
            'evidencia' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $archivo = $peticion->file('evidencia');

        return $archivo->storeAs(
            'procesos-formativos/'.$expediente->id.'/horas',
            Str::uuid()->toString().'.'.$archivo->getClientOriginalExtension(),
            'local',
        );
    }

    /**
     * Capturar: o es SUYO, o se tiene el permiso de revisar.
     *
     * Las dos puertas hacen falta. El alumno registra lo suyo desde su portal, y
     * la escuela captura por él cuando llega con su bitácora en papel — que es
     * el caso normal en las organizaciones sin computadora.
     */
    private function exigirQuePuedaCapturar(
        Request $peticion,
        ExpedienteProceso $expediente,
        bool $exigirEnCurso = true,
    ): void {
        $usuario = $peticion->user();

        if ($this->esSuyo($peticion, $expediente)) {
            return;
        }

        AvisoParaElUsuario::aMenosQue(
            $usuario?->can('aprobar-horas-formativas') === true
            || $usuario?->can('revisar-solicitudes-formativas') === true,
            404,
            'Ese expediente no es tuyo.',
        );

        $this->alcance->exigirQueAlcance($expediente, $usuario);
    }

    private function exigirQueRevise(Request $peticion, ExpedienteProceso $expediente): void
    {
        AvisoParaElUsuario::aMenosQue(
            $peticion->user()?->can('revisar-informes-formativos') === true,
            403,
            'Tu rol no puede capturar esa evaluación.',
        );

        $this->alcance->exigirQueAlcance($expediente, $peticion->user());
    }

    /** Ver: su dueño, o quien lo alcance con permiso administrativo. */
    private function exigirQueLoAlcance(Request $peticion, ExpedienteProceso $expediente): void
    {
        if ($this->esSuyo($peticion, $expediente)) {
            return;
        }

        AvisoParaElUsuario::aMenosQue(
            $peticion->user()?->can('ver-procesos-formativos') === true,
            404,
            'Ese expediente no es tuyo.',
        );

        $this->alcance->exigirQueAlcance($expediente, $peticion->user());
    }

    /** Cuelga de una matrícula de quien pide. */
    private function esSuyo(Request $peticion, ExpedienteProceso $expediente): bool
    {
        $expediente->loadMissing('matricula:id,persona_id');

        return (int) $expediente->matricula?->persona_id === (int) $peticion->user()?->persona_id;
    }

    private function exigirQueSeaDelExpediente(ExpedienteProceso $expediente, BitacoraHoras $jornada): void
    {
        /*
         * Las dos ids viajan por la URL, así que se comprueba la PAREJA: con
         * sólo la de la jornada, cualquiera con un expediente propio tendría una
         * puerta lateral a las horas de otro.
         */
        AvisoParaElUsuario::aMenosQue(
            (int) $jornada->expediente_id === (int) $expediente->id,
            404,
            'Esa jornada no es de este expediente.',
        );
    }
}
