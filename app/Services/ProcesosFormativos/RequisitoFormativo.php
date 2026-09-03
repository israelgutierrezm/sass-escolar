<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\LiberacionProceso;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;

/**
 * ¿Este alumno cumplió su servicio social? El lado que PREGUNTA.
 *
 * ── Por qué existe y por qué NADIE lo llama todavía ───────────────────────
 * El pedido del cliente dice dos cosas a la vez: «integra con certificación y
 * titulación» y «NO modifiques esos procesos». Se resuelve construyendo el lado
 * que contesta —esto— y dejando el lado que consume SIN CABLEAR.
 *
 * **No se agrega un `if` a `EstadoCertificacion` ni a `ValidadorTitulo`, ni se
 * toca `titulo_servicio_social`.** Engancharlo es una línea el día que la
 * escuela lo pida; hacerlo ANTES cambiaría el criterio con el que hoy se
 * timbran títulos ante la SEP, sobre expedientes que ninguna escuela ha llenado
 * todavía. Un módulo recién construido no puede empezar a bloquear titulaciones
 * el primer día.
 *
 * ── Lo que este servicio NO hace, y es deliberado ─────────────────────────
 * **Nunca marca a nadie como liberado.** Sólo lee. Alcanzar las horas quita un
 * impedimento; liberar es un acto con permiso, folio y snapshot que vive en
 * {@see LiberadorDeExpediente}.
 *
 * ── Y todo lo responde por CLAVE de tipo, no por id ───────────────────────
 * `titulacion` no puede conocer los ids de los tipos de proceso de cada
 * escuela. La clave es lo que un consumidor externo puede escribir —
 * `'servicio_social'`— y lo que sobrevive a que la escuela reordene su
 * catálogo.
 */
class RequisitoFormativo
{
    public function __construct(private readonly LiberadorDeExpediente $liberador) {}

    /**
     * ¿El programa de este alumno EXIGE este proceso para titularse?
     *
     * Dos condiciones y las dos hacen falta: que la regla aplicable lo declare
     * `obligatorio` —le toca hacerlo— y que además `cuenta_para_titulacion`
     * —que su falta impida el título—. Son cosas distintas: un servicio social
     * puede ser obligatorio para el programa y no ser lo que detiene un
     * trámite, si la escuela lo lleva por fuera.
     */
    public function exigeElPlan(MatriculaOferta $matricula, string $tipoClave): bool
    {
        $version = $this->versionAplicable($matricula, $tipoClave);

        return $version?->obligatorio === true && $version?->cuenta_para_titulacion === true;
    }

    /**
     * El expediente LIBERADO que satisface el requisito, si lo hay.
     *
     * Sólo el liberado: uno en curso no satisface nada, y devolverlo dejaría al
     * consumidor decidiendo por su cuenta qué estado vale — que es exactamente
     * la lógica que este servicio existe para no repetir.
     */
    public function expedienteQueLoSatisface(MatriculaOferta $matricula, string $tipoClave): ?ExpedienteProceso
    {
        $tipo = $this->tipo($tipoClave);

        if ($tipo === null) {
            return null;
        }

        return ExpedienteProceso::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('tipo_proceso_id', $tipo->id)
            ->where('estado', EstadoExpediente::Liberado->value)
            ->latest('id')
            ->first();
    }

    public function estaLiberado(MatriculaOferta $matricula, string $tipoClave): bool
    {
        return $this->expedienteQueLoSatisface($matricula, $tipoClave) !== null;
    }

    /**
     * El folio y los datos de la constancia, para imprimirlos en otro documento.
     *
     * Salen del SNAPSHOT congelado y no de las relaciones vivas: un título que
     * cite el servicio social de hace tres años tiene que decir lo que decía
     * aquella constancia, no lo que dirían los datos de hoy.
     *
     * @return array<string, mixed>|null
     */
    public function constanciaDe(MatriculaOferta $matricula, string $tipoClave): ?array
    {
        $expediente = $this->expedienteQueLoSatisface($matricula, $tipoClave);

        if ($expediente === null) {
            return null;
        }

        $liberacion = $this->liberador->vigenteDe($expediente);

        if ($liberacion === null) {
            return null;
        }

        return [
            'folio' => $liberacion->folio,
            'liberado_en' => $liberacion->liberado_en?->toDateString(),
            'horas' => $liberacion->horas_acreditadas,
            'organizacion' => $liberacion->delSnapshot('organizacion.razon_social'),
            'regla' => $liberacion->delSnapshot('regla.nombre'),
            'regla_version' => $liberacion->delSnapshot('regla.version'),
            'expediente_id' => $expediente->id,
        ];
    }

    /**
     * Qué le falta para que este requisito esté cumplido.
     *
     * Devuelve la LISTA y no un booleano, por lo de siempre: «no puedes
     * titularte» manda a la gente a ventanilla, y «te faltan 40 horas y el
     * informe final» se resuelve. Vacío significa cumplido —o que no se le
     * exige—.
     *
     * @return array<int, string>
     */
    public function impedimentos(MatriculaOferta $matricula, string $tipoClave): array
    {
        if (! $this->exigeElPlan($matricula, $tipoClave)) {
            return [];
        }

        if ($this->estaLiberado($matricula, $tipoClave)) {
            return [];
        }

        $tipo = $this->tipo($tipoClave);
        $nombre = $tipo?->nombre ?? $tipoClave;

        $expediente = ExpedienteProceso::query()
            ->vivos()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('tipo_proceso_id', $tipo?->id)
            ->latest('id')
            ->first();

        /*
         * Sin expediente abierto se dice con esas palabras. «No cumple» sobre
         * alguien que ni siquiera ha empezado no le dice qué hacer; «no has
         * empezado tu servicio social» sí.
         */
        if ($expediente === null) {
            return ['Su programa exige «'.$nombre.'» y todavía no ha abierto su expediente.'];
        }

        return array_merge(
            ['«'.$nombre.'» está en «'.$expediente->estado->etiqueta().'» y aún no se ha liberado.'],
            $this->liberador->impedimentos($expediente),
        );
    }

    /**
     * La versión de la regla que hoy le aplica, o null.
     *
     * Se pregunta a través del expediente cuando lo hay —su regla está
     * CONGELADA y es la que se le aplicó— y al resolutor cuando no. Al revés,
     * un alumno con expediente abierto se mediría contra una configuración que
     * cambió después de que él empezara.
     */
    private function versionAplicable(MatriculaOferta $matricula, string $tipoClave)
    {
        $tipo = $this->tipo($tipoClave);

        if ($tipo === null) {
            return null;
        }

        $expediente = ExpedienteProceso::query()
            ->vivos()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('tipo_proceso_id', $tipo->id)
            ->with('reglaVersion')
            ->latest('id')
            ->first();

        if ($expediente?->reglaVersion !== null) {
            return $expediente->reglaVersion;
        }

        return app(ResolutorDeRegla::class)->resolver($matricula, $tipo)['version'];
    }

    private function tipo(string $clave): ?TipoProcesoFormativo
    {
        return TipoProcesoFormativo::query()->where('clave', $clave)->first();
    }
}
