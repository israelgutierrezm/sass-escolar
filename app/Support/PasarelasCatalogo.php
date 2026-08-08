<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Catálogo de pasarelas de pago que la escuela puede habilitar.
 *
 * Aquí vive QUÉ pasarelas existen, QUÉ credenciales necesita cada una y QUÉ
 * puede encender de lo que ofrece. La lógica de cobro no vive aquí (eso es por
 * pasarela, en `App\Services\Pagos`): esto es el registro que alimenta la
 * pantalla de configuración.
 *
 * Cada campo marcado `requerido` debe estar lleno (en el ambiente activo) para
 * poder encender la pasarela. Los campos son SECRETOS: se guardan cifrados y al
 * frontend solo se manda si están puestos, nunca su valor.
 *
 * ── Las opciones son lo que cada pasarela ofrece DE VERDAD ─────────────────
 * Meses sin intereses, pago en OXXO, transferencia. No están inventadas ni
 * copiadas entre pasarelas: PayPal no tiene opciones porque en México no cobra
 * en efectivo ni ofrece MSI por su cuenta, y poner el interruptor daría a
 * entender que sí. Encender aquí algo que la pasarela no soporta es prometerle
 * al alumno una forma de pago que no va a encontrar.
 *
 * Dos tipos de opción:
 * - `interruptor`: se acepta o no (tarjeta, efectivo, transferencia).
 * - `meses`: los plazos de MSI que se ofrecen. Vacío = sin meses.
 */
class PasarelasCatalogo
{
    /**
     * @return array<string, array{nombre: string, descripcion: string, color: string, campos: array<string, array{etiqueta: string, requerido: bool, ayuda?: string}>, opciones: array<string, array{etiqueta: string, tipo: string, default: mixed, valores?: array<int, int>, ayuda?: string}>}>
     */
    public static function todas(): array
    {
        return [
            'mercadopago' => [
                'nombre' => 'Mercado Pago',
                'descripcion' => 'La más común en México y Latinoamérica. Tarjetas, SPEI y efectivo.',
                'color' => '#00B1EA',
                'campos' => [
                    'access_token' => ['etiqueta' => 'Access Token', 'requerido' => true, 'ayuda' => 'De tus credenciales en el panel de Mercado Pago.'],
                    'public_key' => ['etiqueta' => 'Public Key', 'requerido' => true],
                    'webhook_secret' => ['etiqueta' => 'Secreto del webhook', 'requerido' => false, 'ayuda' => 'Opcional. Sirve para comprobar que el aviso viene de Mercado Pago.'],
                ],
                'opciones' => [
                    'tarjeta' => ['etiqueta' => 'Tarjeta de crédito y débito', 'tipo' => 'interruptor', 'default' => true],
                    'efectivo' => [
                        'etiqueta' => 'Efectivo en tiendas (OXXO, farmacias y otras)',
                        'tipo' => 'interruptor',
                        'default' => true,
                        'ayuda' => 'El alumno recibe un formato para pagar en tienda. El dinero tarda de unas horas a dos días en confirmarse.',
                    ],
                    'transferencia' => ['etiqueta' => 'Transferencia y dinero en cuenta', 'tipo' => 'interruptor', 'default' => true],
                    'msi' => [
                        'etiqueta' => 'Meses sin intereses',
                        'tipo' => 'meses',
                        'valores' => [3, 6, 9, 12, 18],
                        'default' => [],
                        'ayuda' => 'Los meses los absorbe la escuela como comisión. Mercado Pago exige un monto mínimo por plazo, así que en cargos chicos puede no aparecer la opción.',
                    ],
                ],
            ],

            'conekta' => [
                'nombre' => 'Conekta',
                'descripcion' => 'Pasarela mexicana. Tarjetas con MSI, OXXO y SPEI.',
                'color' => '#0A2540',
                'campos' => [
                    'private_key' => ['etiqueta' => 'Llave privada (key_…)', 'requerido' => true, 'ayuda' => 'Del panel de Conekta → Desarrolladores → API Keys.'],
                    'public_key' => ['etiqueta' => 'Llave pública', 'requerido' => true],
                ],
                'opciones' => [
                    'tarjeta' => ['etiqueta' => 'Tarjeta de crédito y débito', 'tipo' => 'interruptor', 'default' => true],
                    'oxxo' => [
                        'etiqueta' => 'Efectivo en OXXO',
                        'tipo' => 'interruptor',
                        'default' => true,
                        'ayuda' => 'Genera una referencia con fecha de vencimiento. El pago se confirma al día siguiente hábil.',
                    ],
                    'spei' => ['etiqueta' => 'Transferencia SPEI', 'tipo' => 'interruptor', 'default' => true],
                    'msi' => [
                        'etiqueta' => 'Meses sin intereses',
                        'tipo' => 'meses',
                        'valores' => [3, 6, 9, 12],
                        'default' => [],
                        'ayuda' => 'Sólo con tarjetas de crédito participantes. Conekta pide un monto mínimo por plazo.',
                    ],
                ],
            ],

            'stripe' => [
                'nombre' => 'Stripe',
                'descripcion' => 'Tarjetas internacionales y locales. Muy usada por su robustez.',
                'color' => '#635BFF',
                'campos' => [
                    'secret_key' => ['etiqueta' => 'Clave secreta (sk_…)', 'requerido' => true, 'ayuda' => 'Del panel de Stripe → Desarrolladores → Claves de API.'],
                    'publishable_key' => ['etiqueta' => 'Clave publicable (pk_…)', 'requerido' => true],
                    'webhook_secret' => ['etiqueta' => 'Secreto del webhook (whsec_…)', 'requerido' => false],
                ],
                'opciones' => [
                    'tarjeta' => ['etiqueta' => 'Tarjeta de crédito y débito', 'tipo' => 'interruptor', 'default' => true],
                    'oxxo' => ['etiqueta' => 'Efectivo en OXXO', 'tipo' => 'interruptor', 'default' => false],
                    'msi' => [
                        'etiqueta' => 'Meses sin intereses',
                        'tipo' => 'meses',
                        'valores' => [3, 6, 9, 12],
                        'default' => [],
                        'ayuda' => 'Stripe ofrece MSI sólo con tarjetas mexicanas y hay que habilitarlo también en su panel.',
                    ],
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
                'opciones' => [
                    'tarjeta' => ['etiqueta' => 'Tarjeta de crédito y débito', 'tipo' => 'interruptor', 'default' => true],
                    'tienda' => ['etiqueta' => 'Efectivo en tiendas', 'tipo' => 'interruptor', 'default' => true],
                    'spei' => ['etiqueta' => 'Transferencia SPEI', 'tipo' => 'interruptor', 'default' => true],
                    'msi' => [
                        'etiqueta' => 'Meses sin intereses',
                        'tipo' => 'meses',
                        'valores' => [3, 6, 9, 12],
                        'default' => [],
                    ],
                ],
            ],

            'paypal' => [
                'nombre' => 'PayPal',
                'descripcion' => 'Pagos con cuenta PayPal y tarjeta. Útil para pagos del extranjero.',
                'color' => '#003087',
                'campos' => [
                    'client_id' => ['etiqueta' => 'Client ID', 'requerido' => true],
                    'client_secret' => ['etiqueta' => 'Client Secret', 'requerido' => true],
                    'webhook_id' => [
                        'etiqueta' => 'ID del webhook',
                        'requerido' => false,
                        'ayuda' => 'Opcional. Del panel de PayPal → Apps & Credentials → Webhooks. Sirve para comprobar que el aviso viene de PayPal.',
                    ],
                ],
                /*
                 * Sin opciones a propósito: en México PayPal no cobra en
                 * efectivo ni ofrece meses sin intereses por su cuenta —los pone
                 * el banco emisor de la tarjeta, no la pasarela—. Un interruptor
                 * aquí prometería algo que el alumno no va a encontrar.
                 *
                 * Que no tenga nada que encender no la deja fuera: cobra con
                 * tarjeta y con saldo de PayPal, que es lo que hace falta para
                 * cobrarle a alguien de fuera del país.
                 */
                'opciones' => [],
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

    /**
     * Lo que ofrece una pasarela, con sus valores por omisión.
     *
     * @return array<string, array{etiqueta: string, tipo: string, default: mixed, valores?: array<int, int>, ayuda?: string}>
     */
    public static function opciones(string $clave): array
    {
        return self::todas()[$clave]['opciones'] ?? [];
    }

    /**
     * Qué formas de pago (no MSI) puede encender una pasarela.
     *
     * Sirve para la regla de «no se pueden apagar todas»: sin ninguna forma de
     * pago, el cobro se abre y no hay con qué pagarlo.
     *
     * @return array<int, string>
     */
    public static function metodosDe(string $clave): array
    {
        return array_keys(array_filter(
            self::opciones($clave),
            fn (array $o) => $o['tipo'] === 'interruptor',
        ));
    }

    public static function nombreDe(string $clave): string
    {
        return self::todas()[$clave]['nombre'] ?? $clave;
    }
}
