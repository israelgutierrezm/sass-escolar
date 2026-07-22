<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Finanzas\Factura;
use App\Services\Cfdi\Pac;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Manda una factura al PAC, fuera del ciclo de la petición.
 *
 * En cola porque el PAC es un tercero: puede tardar diez segundos o estar
 * caído media hora. Timbrar dentro del request dejaría al usuario mirando una
 * pantalla colgada y, peor, un timeout del navegador no le diría si el
 * comprobante se emitió o no.
 *
 * Es **tenant-aware sin hacer nada**: el `QueueTenancyBootstrapper` de stancl
 * serializa el tenant en el job y lo reinicializa al ejecutarlo. Por eso el
 * job viaja con el ID de la factura y no con el modelo — y por eso hay que
 * dejar ese bootstrapper encendido en `config/tenancy.php`.
 *
 * Solo se reintenta lo que tiene sentido reintentar: si el PAC no contesta, se
 * vuelve a intentar más tarde. Un RECHAZO del SAT no se reintenta —la respuesta
 * sería la misma— y se guarda para que alguien corrija el dato y reemita.
 */
class TimbrarFactura implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $facturaId) {}

    public function tries(): int
    {
        return (int) config('cfdi.reintentos', 3);
    }

    /**
     * Espera creciente entre intentos. Un PAC que no contesta suele volver en
     * minutos; reintentar en segundos solo gasta la cola.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('cfdi.espera', [60, 300, 900]);
    }

    public function handle(Pac $pac): void
    {
        $factura = Factura::find($this->facturaId);

        if ($factura === null) {
            return; // la borraron siendo borrador; no hay nada que timbrar
        }

        // Defensa contra el doble timbrado: si ya tiene UUID, otro intento la
        // timbró. Emitir dos comprobantes por el mismo cobro obliga a cancelar
        // uno ante el SAT, que es un trámite y no un `delete`.
        if ($factura->uuid !== null) {
            return;
        }

        $factura->update([
            'estatus' => Factura::ESTATUS_TIMBRANDO,
            'intentos' => $factura->intentos + 1,
        ]);

        $resultado = $pac->timbrar($factura);

        if (! $resultado->exito) {
            // Rechazo del SAT: no se reintenta, se explica. `fail()` saca el
            // job de la cola sin volver a encolarlo.
            $factura->update([
                'estatus' => Factura::ESTATUS_ERROR,
                'ultimo_error' => trim(($resultado->codigo ?? '').' '.($resultado->error ?? '')),
            ]);

            Log::warning('CFDI rechazado por el PAC', [
                'factura' => $factura->id,
                'codigo' => $resultado->codigo,
                'error' => $resultado->error,
            ]);

            return;
        }

        $factura->update([
            'estatus' => Factura::ESTATUS_TIMBRADA,
            'uuid' => $resultado->uuid,
            'pac' => $pac->nombre(),
            'fecha_timbrado' => now(),
            'ultimo_error' => null,
            'xml_ruta' => $this->guardar($factura, $resultado->xml, 'xml'),
            'pdf_ruta' => $this->guardar($factura, $resultado->pdf, 'pdf'),
        ]);
    }

    /**
     * El PAC no contestó (excepción, no rechazo) y se agotaron los intentos.
     * La factura queda en `error` con el motivo: sin esto se quedaría en
     * "timbrando" para siempre y nadie sabría que hay que reintentarla.
     */
    public function failed(?Throwable $e): void
    {
        Factura::where('id', $this->facturaId)
            ->where('estatus', Factura::ESTATUS_TIMBRANDO)
            ->update([
                'estatus' => Factura::ESTATUS_ERROR,
                'ultimo_error' => 'No se pudo contactar al PAC: '.($e?->getMessage() ?? 'sin detalle'),
            ]);
    }

    /**
     * Guarda el XML o el PDF en el disco privado del tenant.
     *
     * Nunca en `public/`: un CFDI trae RFC, razón social y domicilio fiscal del
     * receptor: datos personales que la LFPDPPP obliga a proteger.
     */
    private function guardar(Factura $factura, ?string $contenido, string $extension): ?string
    {
        if ($contenido === null || $contenido === '') {
            return null;
        }

        $ruta = "cfdi/{$factura->id}.{$extension}";

        Storage::disk('local')->put($ruta, $contenido);

        return $ruta;
    }
}
