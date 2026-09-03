<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\ReglaProceso;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Services\ProcesosFormativos\ElegibilidadFormativa;
use App\Services\ProcesosFormativos\SolicitudDelAlumno;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El portal del alumno: qué proceso le toca, si ya puede y QUÉ LE FALTA.
 *
 * ── La ruta NO lleva id ────────────────────────────────────────────────────
 * La persona sale de la sesión, así que no existe pedir el expediente de otro.
 * Quien estudia dos programas elige entre SUS matrículas, y la elección se
 * busca dentro de esa misma lista: un id ajeno no encuentra pareja y cae a la
 * propia. Es el mismo camino que `/mi-historial` y `/mi-credencial`.
 *
 * ── Y el titular es la MATRÍCULA ───────────────────────────────────────────
 * Quien estudia dos carreras hace dos servicios sociales, con reglas que pueden
 * ser distintas. Por persona, las dos se mezclarían y el porcentaje de créditos
 * saldría de un promedio que no es de ninguna.
 *
 * ── Se enseñan los DOS lados ───────────────────────────────────────────────
 * Lo que falta y lo que ya se cumple. A un alumno al que sólo se le dice lo que
 * le falta no le consta que el sistema haya mirado lo demás — y la primera
 * reacción es ir a ventanilla a preguntar, que es lo que esta pantalla viene a
 * evitar.
 */
class MiProcesoFormativoController extends Controller
{
    public function __construct(
        private readonly ElegibilidadFormativa $elegibilidad,
        private readonly SolicitudDelAlumno $solicitudes,
    ) {}

    public function index(Request $peticion): Response
    {
        $usuario = $peticion->user();

        $matriculas = $this->misMatriculas((int) $usuario->persona_id);

        /*
         * La matrícula pedida se busca DENTRO de las suyas. Un id ajeno no
         * encuentra pareja y cae a la primera propia: no hay 403 que dé pistas
         * ni forma de mirar lo de otro.
         */
        $elegida = $matriculas->firstWhere('id', (int) $peticion->integer('matricula'))
            ?? $matriculas->first();

        return Inertia::render('Procesos/MiProceso', [
            'matriculas' => $matriculas->map(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'programa' => $m->oferta?->programaAcademico?->nombre,
                'campus' => $m->oferta?->campus?->nombre,
            ])->values(),

            'elegida' => $elegida?->id,

            'procesos' => $elegida === null ? [] : $this->procesosDe($elegida),
        ]);
    }

    /**
     * Un dictamen por cada tipo de proceso que la escuela tiene encendido.
     *
     * Se listan TODOS y no sólo los que le aplican: un alumno que no ve
     * «prácticas profesionales» no sabe si es que no le tocan o si el sistema
     * las perdió. Los que no tienen regla lo dicen con esas palabras.
     *
     * @return array<int, array<string, mixed>>
     */
    private function procesosDe(MatriculaOferta $matricula): array
    {
        return TipoProcesoFormativo::query()
            ->activos()
            ->get()
            ->map(function (TipoProcesoFormativo $tipo) use ($matricula) {
                $dictamen = $this->elegibilidad->para($matricula, $tipo);

                return [
                    'tipo' => $tipo->nombre,
                    'tipo_id' => $tipo->id,
                    'elegible' => $dictamen['elegible'],
                    'obligatorio' => $dictamen['obligatorio'],
                    'impedimentos' => $dictamen['impedimentos'],
                    'cumplidos' => $dictamen['cumplidos'],
                    'avance' => $dictamen['avance'],
                    // Qué regla se aplicó y por qué: sin esto, «no soy elegible»
                    // no se puede discutir con nadie.
                    'regla' => $dictamen['regla'] instanceof ReglaProceso
                        ? ['nombre' => $dictamen['regla']->nombre, 'alcance' => $dictamen['regla']->comoSeLee()]
                        : null,
                    'version' => $dictamen['version']?->version,
                    'horas_requeridas' => $dictamen['version']?->horas_requeridas,
                    'expediente' => $this->expedienteParaPantalla($matricula, $tipo),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Abre el expediente en BORRADOR.
     *
     * No se envía todavía: el alumno tiene que poder juntar sus papeles sin
     * que el reloj de la revisión empiece a correr, y sin que la escuela vea en
     * su bandeja una solicitud a medias.
     */
    public function abrir(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'matricula' => ['required', 'integer'],
            'tipo_proceso_id' => ['required', 'integer', 'exists:tipos_proceso_formativo,id'],
        ]);

        $matricula = $this->matriculaPropia($peticion, (int) $datos['matricula']);

        $tipo = TipoProcesoFormativo::query()->activos()->findOrFail($datos['tipo_proceso_id']);

        $expediente = $this->solicitudes->abrir($matricula, $tipo, $peticion->user(), $peticion->ip());

        return back(303)->with(
            'exito',
            'Se abrió tu solicitud de '.$tipo->nombre.'. Súbele lo que te pide y envíala cuando esté lista.',
        );
    }

    /**
     * La manda a revisión.
     *
     * Aquí es donde se vuelve a comprobar la ELEGIBILIDAD: entre abrir el
     * borrador y enviarlo pueden pasar semanas, y el alumno pudo reprobar, caer
     * en adeudo o cerrarse la ventana. Comprobarlo sólo al abrir dejaría entrar
     * a la bandeja solicitudes que ya no cumplen, y el rechazo llegaría después
     * de que alguien perdiera el tiempo revisándolas.
     */
    public function enviar(Request $peticion, ExpedienteProceso $expediente): RedirectResponse
    {
        $this->exigirQueSeaSuyo($peticion, $expediente);

        $this->solicitudes->enviar($expediente, $peticion->user(), $peticion->ip());

        return back(303)->with('exito', 'Solicitud enviada. Servicios escolares la va a revisar.');
    }

    /** Se arrepintió: el borrador se cancela y deja de contar. */
    public function cancelar(Request $peticion, ExpedienteProceso $expediente): RedirectResponse
    {
        $this->exigirQueSeaSuyo($peticion, $expediente);

        $datos = $peticion->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        $this->solicitudes->cancelar($expediente, $datos['motivo'], $peticion->user(), $peticion->ip());

        return back(303)->with('exito', 'Tu solicitud quedó cancelada. Puedes abrir otra cuando quieras.');
    }

    /**
     * Sube un papel al expediente.
     *
     * Va al disco PRIVADO y la descarga se autoriza registro por registro: son
     * datos personales, y guardarlos en `public/` los deja al alcance de
     * cualquiera que adivine la ruta.
     */
    public function subirDocumento(Request $peticion, ExpedienteProceso $expediente): RedirectResponse
    {
        $this->exigirQueSeaSuyo($peticion, $expediente);

        $datos = $peticion->validate([
            /*
             * El documento tiene que ser del ÁMBITO de este proceso. Sin
             * acotarlo, el id de un papel de aspirante pasaría y acabaría en un
             * expediente donde nadie lo pidió y nadie lo va a revisar. Es el
             * mismo defecto que se corrigió en el expediente del tutor.
             */
            'documento_id' => [
                'required', 'integer',
                function (string $campo, mixed $valor, callable $falla) {
                    DocumentoRequerido::query()
                        ->delAmbito(DocumentoRequerido::AMBITO_PROCESO_FORMATIVO)
                        ->whereKey($valor)
                        ->exists()
                        || $falla('Ese documento no es de los que se piden para este proceso.');
                },
            ],
            'momento' => ['required', 'string', 'in:solicitud,durante,liberacion'],
            'archivo' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $archivo = $peticion->file('archivo');

        /*
         * Al disco `local`, que es el PRIVADO de este proyecto: `public/` sirve
         * los archivos sin preguntar quién los pide, y esto son datos
         * personales. No existe ningún disco llamado «privado» —el nombre se
         * pregunta, no se adivina, igual que el de una tabla—; los 120 usos del
         * sistema van todos a `local`.
         *
         * El nombre en disco es un uuid: con el original, dos alumnos que suban
         * «acta.pdf» chocarían, y con un consecutivo se podrían enumerar.
         */
        $ruta = $archivo->storeAs(
            'procesos-formativos/'.$expediente->id,
            Str::uuid()->toString().'.'.$archivo->getClientOriginalExtension(),
            'local',
        );

        $this->solicitudes->guardarDocumento(
            $expediente,
            (int) $datos['documento_id'],
            $datos['momento'],
            $ruta,
            $archivo->getClientOriginalName(),
        );

        return back(303)->with('exito', 'Documento subido.');
    }

    /**
     * La descarga, comprobando la PAREJA (expediente, documento).
     *
     * Con sólo el id del documento, cualquiera con un expediente propio pediría
     * el papel de otro poniendo el suyo en el primer hueco. Es la lección del
     * portal del tutor, comprobada ahí por HTTP.
     */
    public function verDocumento(Request $peticion, ExpedienteProceso $expediente, int $documento): StreamedResponse
    {
        $this->exigirQueSeaSuyo($peticion, $expediente);

        $fila = $expediente->documentos()->whereKey($documento)->first();

        AvisoParaElUsuario::aMenosQue(
            $fila?->ruta !== null && Storage::disk('local')->exists($fila->ruta),
            404,
            'Ese documento no existe.',
        );

        return Storage::disk('local')->download($fila->ruta, $fila->nombre_original ?? 'documento');
    }

    /**
     * El expediente vivo de esta matrícula en este tipo, si lo hay.
     *
     * @return array<string, mixed>|null
     */
    private function expedienteParaPantalla(MatriculaOferta $matricula, TipoProcesoFormativo $tipo): ?array
    {
        $expediente = ExpedienteProceso::query()
            ->vivos()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('tipo_proceso_id', $tipo->id)
            ->with(['documentos.documento:id,nombre', 'documentos.estado:id,clave,nombre', 'organizacion:id,razon_social,nombre_comercial'])
            ->first();

        if ($expediente === null) {
            return null;
        }

        return [
            'id' => $expediente->id,
            'estado' => $expediente->estado->value,
            'estado_texto' => $expediente->estado->etiqueta(),
            'estado_color' => $expediente->estado->color(),
            'motivo_estado' => $expediente->motivo_estado,
            'organizacion' => $expediente->organizacion?->comoSeLeConoce(),
            'fecha_inicio' => $expediente->fecha_inicio?->toDateString(),
            'fecha_fin_programada' => $expediente->fecha_fin_programada?->toDateString(),
            'horas_requeridas' => $expediente->horas_requeridas,
            // El alumno sólo manda cuando está armando su solicitud. Con el
            // expediente ya en revisión, dejarle tocar los papeles dejaría al
            // revisor mirando algo distinto de lo que abrió.
            'puede_editar' => in_array($expediente->estado, [
                EstadoExpediente::Borrador,
                EstadoExpediente::RequiereCorreccion,
            ], true),
            /*
             * La lista es lo que su REGLA le pide, con lo que ya subió puesto
             * encima. Enseñando sólo lo subido, el desplegable de «subir» sale
             * vacío el primer día y el alumno no tiene contra qué cargar nada;
             * enseñando sólo lo pedido, lo ya entregado desaparecería.
             */
            'documentos' => $this->solicitudes->papeleria($expediente),
            'faltantes' => $this->solicitudes->documentosQueFaltan($expediente),
        ];
    }

    /**
     * La matrícula pedida, SIEMPRE de entre las suyas.
     *
     * Aquí sí se lanza 404 y no se cae a la propia, al revés que en el listado:
     * abrir un expediente es un acto con consecuencias, y hacerlo «sobre la
     * primera que tenga» le abriría un trámite que no pidió.
     */
    private function matriculaPropia(Request $peticion, int $id): MatriculaOferta
    {
        $suya = $this->misMatriculas((int) $peticion->user()->persona_id)->firstWhere('id', $id);

        AvisoParaElUsuario::aMenosQue($suya !== null, 404, 'Esa matrícula no es tuya.');

        return $suya;
    }

    /** El expediente tiene que colgar de una matrícula SUYA. */
    private function exigirQueSeaSuyo(Request $peticion, ExpedienteProceso $expediente): void
    {
        $suyas = $this->misMatriculas((int) $peticion->user()->persona_id)->pluck('id');

        AvisoParaElUsuario::aMenosQue(
            $suyas->contains($expediente->matricula_oferta_id),
            404,
            'Ese expediente no es tuyo.',
        );
    }

    /** @return Collection<int, MatriculaOferta> */
    private function misMatriculas(int $personaId): Collection
    {
        return MatriculaOferta::query()
            ->where('persona_id', $personaId)
            ->with([
                'oferta.programaAcademico:id,nombre',
                'oferta.campus:id,nombre',
                'oferta.plan:id,nombre,total_creditos,programa_academico_id',
                'situacion:id,nombre',
            ])
            ->orderBy('id')
            ->get();
    }
}
