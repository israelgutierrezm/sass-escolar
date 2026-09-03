<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\DocumentoExpedienteFormativo;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\ReglaDocumento;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use Illuminate\Support\Facades\DB;

/**
 * El lado del ALUMNO: abrir la solicitud, juntar los papeles y enviarla.
 *
 * ── Por qué es un servicio y no tres métodos del controlador ──────────────
 * Porque las mismas reglas las va a necesitar el mostrador el día que la
 * escuela capture por ventanilla —«abre la solicitud por mí»—, y escritas dos
 * veces una de las dos acabará sin comprobar la elegibilidad. Es exactamente lo
 * que le pasó a la bolsa de trabajo antes de centralizar en `Postulador`.
 *
 * ── La elegibilidad se comprueba DOS veces, y las dos hacen falta ──────────
 * Al ABRIR, para no dejar empezar a quien no puede; y al ENVIAR, porque entre
 * las dos cosas pueden pasar semanas: el alumno pudo reprobar, caer en adeudo o
 * cerrársele la ventana. Comprobando sólo al abrir, la bandeja se llena de
 * solicitudes que ya no cumplen y el rechazo llega después de que alguien
 * perdiera el tiempo revisándolas.
 *
 * ── Y las horas se COPIAN al abrir ────────────────────────────────────────
 * No se leen de la regla al mirarlas: un alumno puede tener una excepción
 * autorizada que le baje el requisito, y leyéndolas de la versión esa excepción
 * no cabría en ningún lado. Mismo criterio que el emisor congelado en la
 * factura.
 */
class SolicitudDelAlumno
{
    public function __construct(
        private readonly ElegibilidadFormativa $elegibilidad,
        private readonly ResolutorDeRegla $resolutor,
        private readonly TransicionDeExpediente $transiciones,
    ) {}

    /**
     * Abre el expediente en borrador.
     *
     * @throws AvisoParaElUsuario 422 con la lista de lo que le falta
     */
    public function abrir(
        MatriculaOferta $matricula,
        TipoProcesoFormativo $tipo,
        ?Usuario $quien,
        ?string $ip = null,
    ): ExpedienteProceso {
        $abierto = ExpedienteProceso::query()
            ->vivos()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('tipo_proceso_id', $tipo->id)
            ->first();

        /*
         * Se dice con palabras en vez de dejar que reviente el único de la
         * base: el índice lo impediría igual, pero con un 1062 en la cara de
         * quien sólo pulsó un botón dos veces.
         */
        AvisoParaElUsuario::si(
            $abierto !== null,
            422,
            'Ya tienes una solicitud de '.$tipo->nombre.' abierta, en «'
            .$abierto?->estado->etiqueta().'».',
        );

        $dictamen = $this->elegibilidad->para($matricula, $tipo);

        AvisoParaElUsuario::aMenosQue(
            $dictamen['elegible'],
            422,
            'Todavía no puedes empezar: '.implode(' ', $dictamen['impedimentos']),
        );

        $version = $dictamen['version'];

        return $this->transiciones->abrir([
            'matricula_oferta_id' => $matricula->id,
            'tipo_proceso_id' => $tipo->id,
            'regla_version_id' => $version->id,
            'horas_requeridas' => $version->horas_requeridas,
        ], $quien, $ip);
    }

    /**
     * La manda a revisión.
     *
     * @throws AvisoParaElUsuario 422 nombrando lo que falta
     */
    public function enviar(ExpedienteProceso $expediente, ?Usuario $quien, ?string $ip = null): ExpedienteProceso
    {
        $expediente->loadMissing('matricula', 'tipoProceso', 'reglaVersion.documentos.documento', 'documentos', 'excepciones');

        /*
         * Se vuelve a comprobar la elegibilidad contra la regla CONGELADA del
         * expediente, no contra la que rija hoy: cambiar la configuración a
         * mitad no puede tumbar una solicitud ya abierta. Por eso se le pasa la
         * versión y no se resuelve otra vez.
         */
        $faltan = $this->impedimentosParaEnviar($expediente);

        AvisoParaElUsuario::aMenosQue(
            $faltan === [],
            422,
            'No se puede enviar todavía: '.implode(' ', $faltan),
        );

        return DB::transaction(function () use ($expediente, $quien, $ip) {
            $movido = $this->transiciones->mover(
                $expediente,
                EstadoExpediente::Solicitado,
                $quien,
                null,
                $ip,
                ['fecha_solicitud' => now()->toDateString()],
            );

            return $movido;
        });
    }

    public function cancelar(
        ExpedienteProceso $expediente,
        string $motivo,
        ?Usuario $quien,
        ?string $ip = null,
    ): ExpedienteProceso {
        return $this->transiciones->mover(
            $expediente,
            EstadoExpediente::Cancelado,
            $quien,
            $motivo,
            $ip,
        );
    }

    /**
     * Qué le falta para poder enviarla.
     *
     * Devuelve la LISTA, como la elegibilidad: «no se puede enviar» manda a la
     * gente a ventanilla, y «te falta el comprobante de seguro» se resuelve.
     *
     * @return array<int, string>
     */
    public function impedimentosParaEnviar(ExpedienteProceso $expediente): array
    {
        $faltan = [];

        foreach ($this->documentosQueFaltan($expediente) as $nombre) {
            $faltan[] = 'Falta subir «'.$nombre.'».';
        }

        /*
         * Y la elegibilidad OTRA VEZ, con la regla congelada. Lo que pudo
         * cambiar desde que abrió el borrador —una materia reprobada, un
         * adeudo— tiene que detenerlo aquí, no en la bandeja de quien revisa.
         */
        if ($expediente->matricula !== null && $expediente->tipoProceso !== null) {
            $dictamen = $this->elegibilidad->paraVersion(
                $expediente->matricula,
                $expediente->reglaVersion,
                $expediente->excepciones->pluck('requisito')->all(),
            );

            foreach ($dictamen['impedimentos'] as $impedimento) {
                $faltan[] = $impedimento;
            }
        }

        return $faltan;
    }

    /**
     * Los documentos OBLIGATORIOS del momento «solicitud» que aún no subió.
     *
     * Sólo los de ese momento: la carta de aceptación no existe hasta que hay
     * organización, y la de término hasta el final. Pedirlo todo al principio
     * frenaría el trámite por un papel que todavía no puede existir.
     *
     * @return array<int, string>
     */
    public function documentosQueFaltan(ExpedienteProceso $expediente, string $momento = 'solicitud'): array
    {
        $expediente->loadMissing('reglaVersion.documentos.documento', 'documentos', 'excepciones');

        if ($expediente->excepcionDe('documentos') !== null) {
            return [];
        }

        $subidos = $expediente->documentos
            ->filter(fn (DocumentoExpedienteFormativo $d) => $d->momento === $momento && $d->ruta !== null)
            ->pluck('documento_id')
            ->all();

        return $expediente->reglaVersion?->documentos
            ->filter(fn (ReglaDocumento $r) => $r->momento === $momento && $r->obligatorio)
            ->reject(fn (ReglaDocumento $r) => in_array($r->documento_id, $subidos, true))
            ->map(fn (ReglaDocumento $r) => $r->documento?->nombre ?? 'documento #'.$r->documento_id)
            ->values()
            ->all() ?? [];
    }

    /**
     * La papelería del expediente: lo que su regla PIDE, con lo subido encima.
     *
     * Las dos mitades hacen falta. Sólo lo subido deja al alumno sin saber qué
     * le piden —y con el desplegable de subir vacío el primer día—; sólo lo
     * pedido esconde lo que ya entregó. Un renglón sin `documento_id` no
     * existe: se identifica por el par (documento, momento), que es la llave
     * del único.
     *
     * @return array<int, array<string, mixed>>
     */
    public function papeleria(ExpedienteProceso $expediente): array
    {
        $expediente->loadMissing('reglaVersion.documentos.documento', 'documentos.documento', 'documentos.estado');

        $subidos = $expediente->documentos->keyBy(
            fn (DocumentoExpedienteFormativo $d) => $d->documento_id.'|'.$d->momento,
        );

        $renglones = [];

        foreach ($expediente->reglaVersion?->documentos ?? [] as $pedido) {
            $llave = $pedido->documento_id.'|'.$pedido->momento;
            $subido = $subidos->get($llave);
            $subidos->forget($llave);

            $renglones[] = $this->renglon(
                $pedido->documento?->nombre ?? 'documento #'.$pedido->documento_id,
                $pedido->documento_id,
                $pedido->momento,
                $pedido->obligatorio,
                $subido,
            );
        }

        /*
         * Y lo que subió y su regla no pide: pasa cuando la escuela cambia los
         * requisitos después. Se enseña igual —el alumno lo entregó y tiene
         * derecho a verlo—, marcado como no exigido.
         */
        foreach ($subidos as $suelto) {
            $renglones[] = $this->renglon(
                $suelto->documento?->nombre ?? 'documento #'.$suelto->documento_id,
                $suelto->documento_id,
                $suelto->momento,
                false,
                $suelto,
            );
        }

        return $renglones;
    }

    /** @return array<string, mixed> */
    private function renglon(
        string $nombre,
        int $documentoId,
        string $momento,
        bool $obligatorio,
        ?DocumentoExpedienteFormativo $subido,
    ): array {
        return [
            'id' => $subido?->id,
            'documento_id' => $documentoId,
            'nombre' => $nombre,
            'momento' => $momento,
            'obligatorio' => $obligatorio,
            'entregado' => $subido?->ruta !== null,
            'nombre_original' => $subido?->nombre_original,
            'estado' => $subido?->estado?->nombre,
            'estado_clave' => $subido?->estado?->clave,
            'observaciones' => $subido?->observaciones,
        ];
    }

    /**
     * Guarda o reemplaza un papel del expediente.
     *
     * Reemplazar y no acumular: el único de la base es sobre
     * `(expediente, documento, momento)`, así que re-subirlo corrige en vez de
     * dejar dos versiones del mismo papel sin decir cuál vale. Y el estado
     * vuelve a NULL —sin revisar—: un documento reemplazado después de haber
     * sido aceptado no puede seguir diciendo «aceptado» sobre un archivo que
     * nadie miró.
     */
    public function guardarDocumento(
        ExpedienteProceso $expediente,
        int $documentoId,
        string $momento,
        string $ruta,
        ?string $nombreOriginal = null,
    ): DocumentoExpedienteFormativo {
        return DB::transaction(function () use ($expediente, $documentoId, $momento, $ruta, $nombreOriginal) {
            $fila = $expediente->documentos()->firstOrNew([
                'documento_id' => $documentoId,
                'momento' => $momento,
            ]);

            $fila->fill([
                'ruta' => $ruta,
                'nombre_original' => $nombreOriginal,
                'estado_documento_id' => null,
                'observaciones' => null,
            ])->save();

            return $fila;
        });
    }
}
