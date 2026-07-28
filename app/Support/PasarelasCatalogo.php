<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Catálogo de pasarelas de pago que la escuela puede habilitar.
 *
 * Aquí vive QUÉ pasarelas existen y QUÉ credenciales necesita cada una para
 * operar. La lógica de cobro no vive aquí (eso es por pasarela y llega después):
 * esto es el registro que alimenta la pantalla de configuración y la regla de
 * "no se puede activar si le faltan datos".
 *
 * Cada campo marcado `requerido` debe estar lleno (en el ambiente activo) para
 * poder encender la pasarela. Los campos son SECRETOS: se guardan cifrados y al
 * frontend solo se manda si están puestos (enmascarados), nunca su valor.
 */
class PasarelasCatalogo
{
    /**
     * @return array<string, array{nombre: string, descripcion: string, color: string, campos: array<string, array{etiqueta: string, requerido: bool, ayuda?: string}>}>
     */
    public static function todas(): array
    {
        return [
            'stripe' => [
                'nombre' => 'Stripe',
                'descripcion' => 'Tarjetas internacionales y locales. Muy usada por su robustez.',
                'color' => '#635BFF',
                'campos' => [
                    'secret_key' => ['etiqueta' => 'Clave secreta (sk_…)', 'requerido' => true, 'ayuda' => 'Del panel de Stripe → Desarrolladores → Claves de API.'],
                    'publishable_key' => ['etiqueta' => 'Clave publicable (pk_…)', 'requerido' => true],
                    'webhook_secret' => ['etiqueta' => 'Secreto del webhook (whsec_…)', 'requerido' => false],
                ],
            ],
            'mercadopago' => [
                'nombre' => 'Mercado Pago',
                'descripcion' => 'La más común en México y Latinoamérica. Tarjetas, SPEI y efectivo.',
                'color' => '#00B1EA',
                'campos' => [
                    'access_token' => ['etiqueta' => 'Access Token', 'requerido' => true, 'ayuda' => 'De tus credenciales en el panel de Mercado Pago.'],
                    'public_key' => ['etiqueta' => 'Public Key', 'requerido' => true],
                ],
            ],
            'paypal' => [
                'nombre' => 'PayPal',
                'descripcion' => 'Pagos con cuenta PayPal y tarjeta. Útil para pagos del extranjero.',
                'color' => '#003087',
                'campos' => [
                    'client_id' => ['etiqueta' => 'Client ID', 'requerido' => true],
                    'client_secret' => ['etiqueta' => 'Client Secret', 'requerido' => true],
                ],
            ],
            'openpay' => [
                'nombre' => 'OpenPay',
                'descripcion' => 'Pasarela mexicana (BBVA). Tarjetas, SPEI y tiendas de conveniencia.',
                'color' => '#3A6DF0',
                'campos' => [
                    'merchant_id' => ['etiqueta' => 'Merchant ID', 'requerido' => true],
                    'private_key' => ['etiqueta' => 'Llave privada (sk_…)', 'requerido' => true],
                    'public_key' => ['etiqueta' => 'Llave pública (pk_…)', 'requerido' => true],
                ],
            ],
        ];
    }

    /** Las claves de las pasarelas conocidas. @return array<int, string> */
    public static function claves(): array
    {
        return array_keys(self::todas());
    }

    public static function existe(string $clave): bool
    {
        return isset(self::todas()[$clave]);
    }

    /**
     * Los nombres de los campos REQUERIDOS de una pasarela.
     *
     * @return array<int, string>
     */
    public static function camposRequeridos(string $clave): array
    {
        $campos = self::todas()[$clave]['campos'] ?? [];

        return array_keys(array_filter($campos, fn (array $c) => $c['requerido']));
    }
}
