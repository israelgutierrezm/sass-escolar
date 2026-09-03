<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\LiberacionProceso;
use App\Services\ProcesosFormativos\AlcanceDeExpedientes;
use App\Services\ProcesosFormativos\ConstanciaFormativa;
use App\Services\ProcesosFormativos\LiberadorDeExpediente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Liberar un expediente y su constancia.
 *
 * ── Tres actos y tres puertas ─────────────────────────────────────────────
 * Emitir (`liberar-expedientes-formativos`), corregir
 * (`corregir-liberacion-formativa`) y CONSULTAR la constancia, que la abre
 * también el propio alumno. Un `can:` de grupo con el permiso de emitir dejaría
 * al alumno sin poder ver su propio documento.
 */
class LiberacionFormativaController extends Controller
{
    public function __construct(
        private readonly LiberadorDeExpediente $liberador,
        private readonly ConstanciaFormativa $constancia,
        private readonly AlcanceDeExpedientes $alcance,
    ) {}

    public function liberar(Request $peticion, ExpedienteProceso $expediente): RedirectResponse
    {
        $liberacion = $this->liberador->liberar($expediente, $peticion->user(), $peticion->ip());

        return back(303)->with(
            'exito',
            'Liberado con el folio '.$liberacion->folio.'. Su constancia ya se puede descargar.',
        );
    }

    public function corregir(Request $peticion, ExpedienteProceso $expediente, LiberacionProceso $liberacion): RedirectResponse
    {
        $this->exigirQueSeaDelExpediente($expediente, $liberacion);

        $datos = $peticion->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $nueva = $this->liberador->corregir($liberacion, $datos['motivo'], $peticion->user());

        return back(303)->with(
            'exito',
            'El folio '.$liberacion->folio.' quedó sin efecto. El vigente es '.$nueva->folio.'.',
        );
    }

    /**
     * La constancia, en PDF.
     *
     * La abren la escuela y el propio alumno. Una CORREGIDA se sigue pudiendo
     * descargar —hay que poder ver qué decía el papel que circuló— y sale con su
     * marca de agua y su aviso.
     */
    public function constancia(Request $peticion, ExpedienteProceso $expediente, LiberacionProceso $liberacion): Response
    {
        $this->exigirQueSeaDelExpediente($expediente, $liberacion);
        $this->exigirQueLaPuedaVer($peticion, $expediente);

        return $this->constancia->responder($liberacion->load('expediente', 'corrige'));
    }

    private function exigirQueSeaDelExpediente(ExpedienteProceso $expediente, LiberacionProceso $liberacion): void
    {
        /*
         * Las dos ids viajan por la URL, así que se comprueba la PAREJA. Con
         * sólo la de la liberación, cualquiera con un expediente propio tendría
         * una puerta lateral a la constancia de otro.
         */
        AvisoParaElUsuario::aMenosQue(
            (int) $liberacion->expediente_id === (int) $expediente->id,
            404,
            'Esa liberación no es de este expediente.',
        );
    }

    /** Su dueño, o quien lo alcance con permiso administrativo. */
    private function exigirQueLaPuedaVer(Request $peticion, ExpedienteProceso $expediente): void
    {
        $expediente->loadMissing('matricula:id,persona_id');

        if ((int) $expediente->matricula?->persona_id === (int) $peticion->user()?->persona_id) {
            return;
        }

        AvisoParaElUsuario::aMenosQue(
            $peticion->user()?->can('ver-procesos-formativos') === true,
            404,
            'Ese expediente no es tuyo.',
        );

        $this->alcance->exigirQueAlcance($expediente, $peticion->user());
    }
}
