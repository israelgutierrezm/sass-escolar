<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Finanzas\PasarelaPago;
use App\Support\PasarelasCatalogo;

/**
 * Qué implementación atiende a cada pasarela.
 *
 * ── Las cinco cobran, y ninguna igual que otra ─────────────────────────────
 * Mercado Pago, Conekta y Stripe dan una liga a un checkout suyo donde quien
 * paga elige. **OpenPay** cobra por cargo y hay que decirle el método de
 * antemano, así que la elección ocurre en nuestra pantalla —por eso existe
 * `Pasarela::metodosAElegir`—. **PayPal** entrega una autorización que hay que
 * CAPTURAR después: aprobar no es cobrar.
 *
 * Lo que cada una ofrece también cambia, y no se finge lo contrario: PayPal no
 * da efectivo ni meses sin intereses en México, y su catálogo no tiene opciones
 * que encender. Sirve sobre todo para cobrarle a alguien de fuera del país.
 *
 * Para agregar una nueva: implementar `Pasarela` y añadirla a `IMPLEMENTADAS`.
 * Lo que NO hay que hacer es darla por buena sin ejercitarla: la forma de
 * fallar importa, porque un cobro que parece funcionar y no concilia es dinero
 * perdido que nadie reclama hasta que hay que cuadrar la caja.
 */
class Pasarelas
{
    /** Las que ya cobran de verdad: todas. */
    public const IMPLEMENTADAS = ['mercadopago', 'conekta', 'stripe', 'openpay', 'paypal'];

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
            'openpay' => new PasarelaOpenPay($config),
            'paypal' => new PasarelaPayPal($config),
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
                /*
                 * Con qué hay que elegir ANTES de salir. Vacío en las que
                 * presentan su propio checkout —casi todas—; con opciones en
                 * OpenPay, que cobra por cargo y necesita saberlo de antemano.
                 */
                'metodos' => $this->para($p)->metodosAElegir(),
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
