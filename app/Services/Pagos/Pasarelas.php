<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Finanzas\PasarelaPago;
use App\Support\PasarelasCatalogo;

/**
 * Qué implementación atiende a cada pasarela.
 *
 * ── Lo que está y lo que no ────────────────────────────────────────────────
 * La pantalla de configuración ofrece cinco pasarelas y hoy cobran de verdad
 * **Mercado Pago**, **Conekta** y **Stripe**. **OpenPay** y **PayPal** se
 * pueden configurar y activar, pero al intentar cobrar dicen que todavía no
 * están, con su nombre y en castellano.
 *
 * Las tres implementadas comparten la misma forma —una liga a un checkout
 * alojado donde quien paga elige entre lo que la escuela encendió—, y por eso
 * encajan en este flujo sin torcerlo.
 *
 * **OpenPay no la tiene**: cobra por cargo y hay que decirle de antemano si es
 * tarjeta, tienda o SPEI, así que exige un paso más de interfaz —elegir el
 * método ANTES de salir— que hoy no existe. Meterla a la fuerza significaría
 * ofrecer sólo tarjeta y llamarlo «OpenPay», que es prometer de menos.
 *
 * **PayPal** sí tiene checkout alojado, pero en México no cobra en efectivo ni
 * da meses sin intereses por su cuenta: cabría, aunque sin nada de lo que hace
 * atractivo el pago en línea aquí.
 *
 * Que falten es a propósito: escribirlas a ciegas —sin credenciales con las que
 * comprobarlas— daría integraciones plausibles y ninguna verificada. La forma
 * de fallar importa: un mensaje claro al configurarla es molesto; un cobro que
 * parece funcionar y no concilia es dinero perdido.
 */
class Pasarelas
{
    /** Las que ya cobran de verdad. */
    public const IMPLEMENTADAS = ['mercadopago', 'conekta', 'stripe'];

    /**
     * La pasarela lista para operar, o un aviso de por qué no se puede.
     */
    public function para(PasarelaPago $config): Pasarela
    {
        AvisoParaElUsuario::aMenosQue(
            PasarelasCatalogo::existe($config->clave),
            404,
            'Esa pasarela de pago no existe.',
        );

        /*
         * El modo `fake` va ANTES de comprobar credenciales: existe justamente
         * para recorrer el cobro sin tenerlas.
         */
        if (config('pagos.modo') === 'fake') {
            return new PasarelaFalsa($config->clave);
        }

        AvisoParaElUsuario::aMenosQue(
            in_array($config->clave, self::IMPLEMENTADAS, true),
            422,
            $this->nombreDe($config->clave).' todavía no está lista para cobrar en línea. '
                .'Por ahora el cobro en línea funciona con Mercado Pago.',
        );

        AvisoParaElUsuario::aMenosQue(
            $config->activa,
            422,
            $this->nombreDe($config->clave).' no está activada.',
        );

        AvisoParaElUsuario::aMenosQue(
            $config->puedeActivar(),
            422,
            'A '.$this->nombreDe($config->clave).' le faltan credenciales en el ambiente '
                .$config->ambiente.', así que no puede cobrar.',
        );

        return match ($config->clave) {
            'mercadopago' => new PasarelaMercadoPago($config),
            'conekta' => new PasarelaConekta($config),
            'stripe' => new PasarelaStripe($config),
            // Inalcanzable: lo impide la comprobación de IMPLEMENTADAS. Está
            // para que agregar una pasarela nueva sin registrarla arriba falle
            // aquí y no devuelva algo a medias.
            default => throw new \LogicException("Pasarela sin implementación: {$config->clave}"),
        };
    }

    /** Las pasarelas que la escuela tiene encendidas Y que sabemos cobrar. */
    public function disponibles(): array
    {
        return PasarelaPago::query()
            ->where('activa', true)
            ->get()
            ->filter(fn (PasarelaPago $p) => $this->cobraDeVerdad($p))
            ->map(fn (PasarelaPago $p) => [
                'clave' => $p->clave,
                'nombre' => $this->nombreDe($p->clave),
                'color' => PasarelasCatalogo::todas()[$p->clave]['color'] ?? null,
                // Que la escuela sepa que aún no cobra dinero real.
                'pruebas' => ! $p->esProduccion(),
                /*
                 * Lo que se puede anunciar antes de pulsar. Los meses sin
                 * intereses y el pago en tienda cambian la decisión de quien va
                 * a pagar, y descubrirlos hasta dentro de la pasarela es
                 * descubrirlos tarde.
                 */
                'meses' => $p->mesesSinIntereses(),
                'efectivo' => $p->aceptaMetodo('efectivo') || $p->aceptaMetodo('oxxo') || $p->aceptaMetodo('tienda'),
            ])
            ->values()
            ->all();
    }

    private function cobraDeVerdad(PasarelaPago $p): bool
    {
        if (config('pagos.modo') === 'fake') {
            return true;
        }

        return in_array($p->clave, self::IMPLEMENTADAS, true) && $p->puedeActivar();
    }

    private function nombreDe(string $clave): string
    {
        return PasarelasCatalogo::todas()[$clave]['nombre'] ?? $clave;
    }
}
