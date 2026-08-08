<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\PasarelaPago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * OpenPay (BBVA) — cargos, uno por forma de pago.
 *
 * ── En qué se diferencia de las demás ──────────────────────────────────────
 * Mercado Pago, Conekta y Stripe dan una liga a un checkout suyo donde quien
 * paga elige entre tarjeta, efectivo o transferencia. **OpenPay no tiene esa
 * pantalla**: se le pide un CARGO y hay que decirle desde el principio de qué
 * tipo es. Por eso implementa `metodosAElegir` y la elección ocurre en nuestra
 * interfaz, antes de salir.
 *
 * Cada método además devuelve algo distinto, y ésa es la parte que no se puede
 * uniformar del todo:
 * - **tarjeta** devuelve una URL a su formulario seguro.
 * - **tienda** devuelve la URL de un recibo imprimible con código de barras.
 * - **SPEI** devuelve la URL de una ficha con CLABE y referencia.
 *
 * Las tres son «a dónde mandar a quien paga», así que encajan en
 * `CobroIniciado`; lo que cambia es que en las dos últimas ahí no se paga: se
 * recoge una instrucción para pagar en otro sitio, y el aviso llega horas o días
 * después.
 *
 * ── Habla en PESOS ─────────────────────────────────────────────────────────
 * Al revés que Conekta y Stripe: aquí el monto va con decimales, tal cual.
 * Mandarle centavos cobraría cien veces de más.
 */
class PasarelaOpenPay implements Pasarela
{
    public function __construct(private readonly PasarelaPago $config) {}

    public function metodosAElegir(): array
    {
        $etiquetas = [
            'tarjeta' => 'Tarjeta de crédito o débito',
            'tienda' => 'Efectivo en tienda',
            'spei' => 'Transferencia SPEI',
        ];

        return collect($this->config->metodosAceptados())
            ->filter(fn (string $m) => isset($etiquetas[$m]))
            ->map(fn (string $m) => ['clave' => $m, 'etiqueta' => $etiquetas[$m]])
            ->values()
            ->all();
    }

    public function iniciar(IntencionCobro $intencion, string $urlRetorno, string $urlAviso, ?string $metodo = null): CobroIniciado
    {
        $metodo ??= 'tarjeta';

        if (! in_array($metodo, array_column($this->metodosAElegir(), 'clave'), true)) {
            throw new RuntimeException('Esa forma de pago no está disponible en OpenPay.');
        }

        $respuesta = $this->cliente()->post($this->url('/charges'), $this->cargo($intencion, $metodo, $urlRetorno));

        if ($respuesta->failed()) {
            throw new RuntimeException($this->porQueFallo($respuesta->json(), $respuesta->status()));
        }

        // `?? []` y no `json()` a secas: una respuesta 200 con cuerpo vacío
        // —o que no sea JSON— haría reventar el reparto con un error de tipos
        // en vez de con algo que se pueda leer.
        $cargo = $respuesta->json() ?? [];

        return new CobroIniciado(
            url: $this->aDondeIr($cargo, $intencion),
            referenciaExterna: (string) ($cargo['id'] ?? ''),
            crudo: $cargo,
        );
    }

    public function interpretarAviso(Request $peticion): ?ResultadoCobro
    {
        $tipo = (string) $peticion->input('type');

        /*
         * `verification` es el aviso que manda al configurar el webhook en su
         * panel: trae un código que hay que copiar allí. No es un cobro y no
         * debe tratarse como tal, pero tampoco es un error.
         */
        if (! str_starts_with($tipo, 'charge.')) {
            return null;
        }

        $cargoId = $peticion->input('transaction.id');

        if (blank($cargoId)) {
            return null;
        }

        return $this->consultarCargo((string) $cargoId);
    }

    public function consultar(IntencionCobro $intencion): ResultadoCobro
    {
        if (blank($intencion->referencia_externa)) {
            return new ResultadoCobro($intencion->id, EstadoCobro::PENDIENTE);
        }

        return $this->consultarCargo($intencion->referencia_externa);
    }

    public function avisoAutentico(Request $peticion, PasarelaPago $config): bool
    {
        /*
         * OpenPay no firma sus avisos: propone proteger la URL con usuario y
         * contraseña propios, que no todas las escuelas van a configurar. Se
         * acepta el aviso y la verdad sale de consultar el cargo con nuestra
         * llave privada, como en Conekta.
         */
        return true;
    }

    // ── Interno ────────────────────────────────────────────────────────────

    /**
     * El cuerpo del cargo, distinto por método.
     *
     * @return array<string, mixed>
     */
    private function cargo(IntencionCobro $intencion, string $metodo, string $urlRetorno): array
    {
        $base = [
            // En PESOS, con decimales. Ver la nota de la clase.
            'amount' => round((float) $intencion->monto, 2),
            'currency' => 'MXN',
            'description' => 'Pago de servicios escolares',
            // Nuestro hilo para reconocer su aviso: OpenPay lo devuelve intacto.
            'order_id' => 'acadion-'.$intencion->id,
        ];

        return match ($metodo) {
            'tienda' => $base + [
                'method' => 'store',
            ],
            'spei' => $base + [
                'method' => 'bank_account',
            ],
            default => $base + [
                'method' => 'card',
                // Con `redirect_url` devuelve su formulario seguro en vez de
                // exigirnos los datos de la tarjeta, que no queremos tocar.
                'redirect_url' => $urlRetorno,
                'use_card_points' => false,
            ] + $this->mesesSiAplican(),
        };
    }

    /**
     * Los meses sin intereses, si se configuraron.
     *
     * OpenPay recibe UN plazo, no una lista, así que va el máximo ofrecido —lo
     * mismo que Mercado Pago—.
     *
     * @return array<string, mixed>
     */
    private function mesesSiAplican(): array
    {
        return $this->config->ofreceMsi()
            ? ['payment_plan' => ['payments' => $this->config->mesesMaximos()]]
            : [];
    }

    /**
     * A dónde se manda a quien paga, según lo que devolvió cada método.
     *
     * ── El SPEI no tiene a dónde ir ────────────────────────────────────────
     * Tarjeta devuelve su formulario y tienda un recibo imprimible, pero la
     * transferencia devuelve DATOS —CLABE, banco, referencia— y ninguna página.
     * Es información que hay que enseñar, así que se manda a una pantalla
     * nuestra que la presenta.
     *
     * Antes esto lanzaba «no devolvió a dónde enviar», que es lo que habría
     * pasado en producción la primera vez que alguien eligiera SPEI: un error
     * genérico en lugar de la CLABE que venía a buscar.
     *
     * @param  array<string, mixed>  $cargo
     */
    private function aDondeIr(array $cargo, IntencionCobro $intencion): string
    {
        $url = $cargo['payment_method']['url'] // tarjeta: su formulario
            ?? $cargo['payment_method']['reference_url'] // tienda: el recibo
            ?? null;

        if (is_string($url) && $url !== '') {
            return $url;
        }

        return route('tenant.pagos.instrucciones', ['intencion' => $intencion->id]);
    }

    private function consultarCargo(string $cargoId): ResultadoCobro
    {
        $respuesta = $this->cliente()->get($this->url("/charges/{$cargoId}"));

        if ($respuesta->failed()) {
            Log::warning('OpenPay no contestó a una consulta de cargo.', [
                'cargo' => $cargoId,
                'respuesta' => $respuesta->json(),
            ]);

            return new ResultadoCobro(null, EstadoCobro::DESCONOCIDO, crudo: $respuesta->json() ?? []);
        }

        $cargo = $respuesta->json();
        $referencia = $cargo['order_id'] ?? '';

        return new ResultadoCobro(
            // «acadion-42» → 42. El prefijo evita chocar con otros sistemas que
            // usen la misma cuenta de OpenPay.
            intencionId: str_starts_with((string) $referencia, 'acadion-')
                ? (int) substr((string) $referencia, 8)
                : null,
            estado: $this->traducir((string) ($cargo['status'] ?? '')),
            monto: isset($cargo['amount']) ? (float) $cargo['amount'] : null,
            transaccionId: isset($cargo['id']) ? (string) $cargo['id'] : null,
            crudo: $cargo,
        );
    }

    private function traducir(string $estado): EstadoCobro
    {
        return match ($estado) {
            'completed' => EstadoCobro::APROBADO,
            /*
             * `in_progress` es lo normal mientras no se paga la ficha o el
             * recibo, y puede durar días. `charge_pending` es el cargo de
             * tienda esperando a que alguien vaya.
             */
            'in_progress', 'charge_pending' => EstadoCobro::PENDIENTE,
            'failed' => EstadoCobro::RECHAZADO,
            'cancelled', 'refunded', 'chargeback_accepted', 'expired' => EstadoCobro::CANCELADO,
            default => EstadoCobro::DESCONOCIDO,
        };
    }

    /**
     * Su URL lleva el comercio dentro, y el ambiente cambia el dominio —a
     * diferencia de Mercado Pago, donde sólo cambia el token—.
     */
    private function url(string $ruta): string
    {
        $comercio = $this->config->credencialesActivas()['merchant_id'] ?? null;

        if (blank($comercio)) {
            throw new RuntimeException('OpenPay no tiene configurado su Merchant ID.');
        }

        $dominio = $this->config->esProduccion()
            ? 'https://api.openpay.mx'
            : 'https://sandbox-api.openpay.mx';

        return "{$dominio}/v1/{$comercio}{$ruta}";
    }

    private function cliente()
    {
        $llave = $this->config->credencialesActivas()['private_key'] ?? null;

        if (blank($llave)) {
            throw new RuntimeException('OpenPay no tiene configurada su llave privada.');
        }

        // Su autenticación es Basic con la llave como usuario y sin contraseña.
        return Http::withBasicAuth($llave, '')
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 300, throw: false);
    }

    /** @param  array<string, mixed>|null  $cuerpo */
    private function porQueFallo(?array $cuerpo, int $estado): string
    {
        $motivo = $cuerpo['description'] ?? $cuerpo['error_code'] ?? null;

        return $motivo
            ? "OpenPay rechazó la solicitud de cobro: {$motivo}"
            : "OpenPay no pudo iniciar el cobro (HTTP {$estado}).";
    }
}
