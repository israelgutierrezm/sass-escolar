<?php

declare(strict_types=1);

namespace App\Services\Nomina;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Nomina\ReciboNomina;
use App\Services\Cfdi\Pac;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Timbra un recibo de nómina ante el SAT, si la escuela lo tiene encendido.
 *
 * ── El interruptor gobierna, y lo dice el cliente ─────────────────────────
 * `nomina.timbrado_cfdi` apagado: no se timbra y la dirección ni siquiera
 * existe. Encendido: se puede, y ANTES se revisa lo que el SAT exige. Muchas
 * escuelas llevan su nómina interna sin timbrar, o timbran por fuera con su
 * contador, y para ésas el botón sólo sería una forma de equivocarse.
 *
 * ── Validar ANTES de mandar, no después ───────────────────────────────────
 * `ValidadorNomina` corre primero y nombra lo que falta con su lugar de
 * captura. Un PAC devolviendo `CFDI40147` sobre cuarenta recibos el día de pago
 * no le sirve a nadie. Es el mismo orden que `ValidadorDec` con los
 * certificados de la SEP: se detiene antes de firmar el lote.
 *
 * ── Un rechazo del SAT NO es una excepción ────────────────────────────────
 * Se guarda en el recibo y se enseña tal cual. Reintentar es del usuario, y por
 * eso el error queda escrito: sin él, la segunda vez se comete el mismo fallo.
 */
class TimbradorNomina
{
    public function __construct(
        private readonly Ajustes $ajustes,
        private readonly ValidadorNomina $validador,
        private readonly Pac $pac,
    ) {}

    public function encendido(): bool
    {
        return $this->ajustes->bool(CatalogoAjustes::TIMBRADO_NOMINA);
    }

    /**
     * @throws RuntimeException si el timbrado está apagado, si el recibo ya
     *                          tiene folio, o si le falta información
     */
    public function timbrar(ReciboNomina $recibo): ReciboNomina
    {
        if (! $this->encendido()) {
            throw new RuntimeException('El timbrado de nómina está apagado para esta escuela.');
        }

        /*
         * Dos veces sería dos comprobantes fiscales del mismo pago. El SAT los
         * aceptaría los dos y quedaría un ingreso duplicado en la declaración
         * del empleado.
         */
        if ($recibo->estaTimbrado()) {
            throw new RuntimeException('Ese recibo ya está timbrado: su folio fiscal es '.$recibo->uuid.'.');
        }

        $faltantes = $this->validador->faltantes($recibo);

        if ($faltantes !== []) {
            throw new RuntimeException(
                'Faltan datos para timbrar: '
                .collect($faltantes)->take(3)->map(fn (array $f) => $f['falta'])->implode(' ')
                .(count($faltantes) > 3 ? ' Y '.(count($faltantes) - 3).' cosa(s) más.' : '')
            );
        }

        $resultado = $this->pac->timbrarNomina($recibo);

        if (! $resultado->exito) {
            // Se escribe el rechazo y NO se lanza: es una respuesta legítima
            // del trámite y hay que enseñarla tal cual.
            $recibo->update([
                'error_timbrado' => trim(($resultado->codigo ?? '').' '.$resultado->error),
                'pac' => $this->pac->nombre(),
            ]);

            return $recibo->refresh();
        }

        $ruta = null;

        if ($resultado->xml !== null) {
            // Disco privado: un CFDI trae RFC, CURP y sueldo de una persona.
            $ruta = "nomina/cfdi/{$recibo->id}-{$resultado->uuid}.xml";
            Storage::disk('local')->put($ruta, $resultado->xml);
        }

        $recibo->update([
            'uuid' => $resultado->uuid,
            'xml_ruta' => $ruta,
            'pac' => $this->pac->nombre(),
            'timbrado_en' => now(),
            'error_timbrado' => null,
        ]);

        return $recibo->refresh();
    }
}
