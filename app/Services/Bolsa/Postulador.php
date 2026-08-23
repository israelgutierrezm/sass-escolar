<?php

declare(strict_types=1);

namespace App\Services\Bolsa;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Bolsa\Colocacion;
use App\Models\Bolsa\EtapaPostulacion;
use App\Models\Bolsa\Postulacion;
use App\Models\Bolsa\PostulacionBitacora;
use App\Models\Bolsa\Vacante;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registrar una postulación y moverla de etapa.
 *
 * ── Los dos caminos pasan por aquí ────────────────────────────────────────
 * El alumno postulándose desde su portal y vinculación capturando por él son la
 * misma operación con distinto autor. Escribirla dos veces es como se llega a
 * que una de las dos deje de anotar la bitácora, y entonces los tiempos de
 * colocación sólo cuentan la mitad de los casos.
 *
 * ── El interruptor SÓLO gobierna el camino autogestivo ────────────────────
 * `bolsa.postulacion_autogestiva` apagado deja al alumno mirar las vacantes y le
 * quita el botón; vinculación sigue capturando. Es lo que el cliente pidió con
 * «forzar en ventanilla», y por eso la comprobación vive en el camino del
 * alumno y no en el registro, que es común a los dos.
 */
class Postulador
{
    public function __construct(private readonly Ajustes $ajustes) {}

    /** ¿La escuela dejó que el alumno se postule solo? */
    public function autogestivaEncendida(): bool
    {
        return $this->ajustes->bool(CatalogoAjustes::BOLSA_AUTOGESTIVA);
    }

    /**
     * Da de alta la postulación y abre su bitácora en la misma transacción.
     *
     * @param  int|null  $capturadaPor  null = la persona se postuló sola
     *
     * @throws RuntimeException si la vacante ya no admite postulaciones o si esa
     *                          persona ya se había postulado
     */
    public function registrar(
        Vacante $vacante,
        int $personaId,
        ?int $matriculaOfertaId = null,
        ?string $cvRuta = null,
        ?string $carta = null,
        ?int $capturadaPor = null,
    ): Postulacion {
        /*
         * Se pregunta por la vacante VIGENTE y no sólo por su situación: eso
         * incluye la fecha de cierre y que la empresa no esté vetada. Sin esto,
         * vinculación podría capturar contra una vacante que el tablero ya no
         * enseña, y el reclutador recibiría a alguien por una plaza cerrada.
         */
        if (! Vacante::query()->vigentes()->whereKey($vacante->id)->exists()) {
            throw new RuntimeException('Esa vacante ya no admite postulaciones.');
        }

        if (Postulacion::query()->where('vacante_id', $vacante->id)->where('persona_id', $personaId)->exists()) {
            throw new RuntimeException('Esa persona ya se había postulado a esta vacante.');
        }

        $inicial = EtapaPostulacion::inicial();

        if ($inicial === null) {
            throw new RuntimeException('No hay etapas de postulación configuradas.');
        }

        return DB::transaction(function () use ($vacante, $personaId, $matriculaOfertaId, $cvRuta, $carta, $capturadaPor, $inicial) {
            $postulacion = Postulacion::create([
                'vacante_id' => $vacante->id,
                'persona_id' => $personaId,
                'matricula_oferta_id' => $matriculaOfertaId,
                'cv_ruta' => $cvRuta,
                'carta_presentacion' => $carta,
                'etapa_id' => $inicial->id,
                'fecha_postulacion' => now(),
                'capturada_por' => $capturadaPor,
            ]);

            /*
             * El alta también deja renglón, con la etapa de origen en null.
             * Sin él, la primera medición —cuánto tardó en pasar de postulado a
             * entrevista— no tendría desde cuándo contar.
             */
            $this->anotar($postulacion, null, $inicial->id, $capturadaPor, 'Alta de la postulación.');

            return $postulacion;
        });
    }

    /**
     * Mueve la postulación a otra etapa, dejando el rastro.
     *
     * Volver a poner la MISMA etapa no anota nada: repetir el movimiento —dos
     * clics, o recargar la pantalla— inflaría la bitácora con renglones de cero
     * días y falsearía los tiempos que esto existe para medir.
     *
     * @throws RuntimeException si la etapa destino declara la contratación y no
     *                          hay colocación registrada
     */
    public function mover(Postulacion $postulacion, int $etapaDestinoId, ?int $movidaPor, ?string $nota = null): Postulacion
    {
        if ((int) $postulacion->etapa_id === $etapaDestinoId) {
            return $postulacion;
        }

        /*
         * A la etapa que coloca no se entra sin registrar la colocación.
         *
         * La etapa y la colocación son el mismo hecho: dejar mover sin lo otro
         * haría que la pantalla dijera «contratado» y el indicador de
         * empleabilidad contara cero, y eso no se descubre hasta que la
         * acreditadora pide el número. El candado vive aquí y no en la pantalla
         * porque ésta es la única puerta por la que se mueve una postulación.
         */
        $destino = EtapaPostulacion::find($etapaDestinoId);

        if ($destino?->marca_colocacion
            && ! Colocacion::query()->where('postulacion_id', $postulacion->id)->exists()) {
            throw new RuntimeException(
                'Para marcarla como contratada hay que registrar la colocación: dónde entró, '
                .'en qué puesto y desde cuándo.'
            );
        }

        return DB::transaction(function () use ($postulacion, $etapaDestinoId, $movidaPor, $nota) {
            $origen = (int) $postulacion->etapa_id;

            $postulacion->update(['etapa_id' => $etapaDestinoId]);
            $this->anotar($postulacion, $origen, $etapaDestinoId, $movidaPor, $nota);

            return $postulacion->refresh();
        });
    }

    private function anotar(Postulacion $postulacion, ?int $origen, int $destino, ?int $quien, ?string $nota): void
    {
        PostulacionBitacora::create([
            'postulacion_id' => $postulacion->id,
            'etapa_origen_id' => $origen,
            'etapa_destino_id' => $destino,
            'movida_por' => $quien,
            'nota' => $nota,
            'momento' => now(),
        ]);
    }
}
