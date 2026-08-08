<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\PasarelaPago;
use Illuminate\Http\Request;

/**
 * Una pasarela de mentira, para poder recorrer el cobro entero sin cobrarle a
 * nadie.
 *
 * ── Para qué existe ────────────────────────────────────────────────────────
 * El cobro en línea es un flujo de cuatro saltos —iniciar, pagar fuera, volver,
 * recibir el aviso— y los errores viven en las costuras: el aviso que llega dos
 * veces, el que llega antes que el retorno, el que dice «rechazado» después de
 * que el navegador dijo «gracias por su pago». Sin poder ejercitarlo, esos
 * casos se descubren en producción con dinero de verdad.
 *
 * En vez de pagar fuera, manda a una pantalla propia donde se elige el
 * desenlace. Todo lo demás —la conciliación, el registro, la idempotencia— es
 * exactamente el mismo código que corre con Mercado Pago.
 *
 * ── No puede encenderse por accidente ──────────────────────────────────────
 * Sólo se usa si `config('pagos.modo')` vale `fake`, que en producción no vale.
 * Ver `Pasarelas`.
 */
class PasarelaFalsa implements Pasarela
{
    public function __construct(private readonly string $clave) {}

    public function iniciar(IntencionCobro $intencion, string $urlRetorno, string $urlAviso): CobroIniciado
    {
        return new CobroIniciado(
            url: route('tenant.pagos.simulador', ['intencion' => $intencion->id]),
            referenciaExterna: 'falsa-'.$this->clave.'-'.$intencion->id,
            crudo: ['simulado' => true],
        );
    }

    public function interpretarAviso(Request $peticion): ?ResultadoCobro
    {
        $intencionId = $peticion->input('intencion');

        if (! is_numeric($intencionId)) {
            return null;
        }

        $estado = EstadoCobro::tryFrom((string) $peticion->input('estado')) ?? EstadoCobro::APROBADO;
        $intencion = IntencionCobro::find((int) $intencionId);

        return new ResultadoCobro(
            intencionId: (int) $intencionId,
            estado: $estado,
            // El monto sale de la intención porque aquí no hay banco que diga
            // otra cosa. Con una pasarela real lo dice ella.
            monto: $intencion ? (float) $intencion->monto : null,
            transaccionId: 'sim-'.$intencionId,
            crudo: ['simulado' => true, 'estado' => $estado->value],
        );
    }

    public function consultar(IntencionCobro $intencion): ResultadoCobro
    {
        // Sin banco al que preguntar, lo único honesto es lo que ya se sabe.
        return new ResultadoCobro(
            intencionId: $intencion->id,
            estado: $intencion->estado === IntencionCobro::PAGADA
                ? EstadoCobro::APROBADO
                : EstadoCobro::PENDIENTE,
            monto: (float) $intencion->monto,
            crudo: ['simulado' => true],
        );
    }

    public function avisoAutentico(Request $peticion, PasarelaPago $config): bool
    {
        return true;
    }
}
