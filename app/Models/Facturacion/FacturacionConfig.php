<?php

declare(strict_types=1);

namespace App\Models\Facturacion;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * facturacion_config (TENANT) — la configuración única de facturación de la
 * escuela.
 *
 * Las API keys van CIFRADAS (`encrypted`): en la base no se lee la llave, y al
 * frontend solo se manda enmascarada (ver `llaveEnmascarada`). Nunca se
 * escriben completas en logs.
 */
class FacturacionConfig extends Model
{
    use TieneAuditoria;

    protected $table = 'facturacion_config';

    public const AMBIENTE_PRUEBAS = 'pruebas';
    public const AMBIENTE_PRODUCCION = 'produccion';

    /** Valores por defecto en memoria, para que una fila recién creada por
     *  `actual()` ya venga con su ambiente y estado, no con nulos. */
    protected $attributes = [
        'activo' => false,
        'ambiente' => 'pruebas',
        'moneda_default' => 'MXN',
        'exportacion_default' => '01',
        'version_factura' => '4.0',
    ];

    protected $fillable = [
        'activo',
        'ambiente',
        'api_key_pruebas',
        'api_key_produccion',
        'api_key_usuario',
        'organizacion_id',
        'conexion_estado',
        'conexion_mensaje',
        'conexion_probada_en',
        'validado_en',
        'uso_cfdi_default',
        'serie_default',
        'folio_inicial',
        'moneda_default',
        'forma_pago_default',
        'metodo_pago_default',
        'exportacion_default',
        'objeto_impuesto_default',
        'version_factura',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            // Cifrado en reposo: la llave nunca queda en claro en la BD.
            'api_key_pruebas' => 'encrypted',
            'api_key_produccion' => 'encrypted',
            // Secret Admin Key (sk_user_...): administra organizaciones y CSD.
            'api_key_usuario' => 'encrypted',
            'conexion_probada_en' => 'datetime',
            'validado_en' => 'datetime',
        ];
    }

    /** La única fila de configuración; se crea vacía si no existe. */
    public static function actual(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /** ¿Está en producción? */
    public function esProduccion(): bool
    {
        return $this->ambiente === self::AMBIENTE_PRODUCCION;
    }

    /** La API key del ambiente activo (en claro, solo para el servicio). */
    public function apiKeyActiva(): ?string
    {
        return $this->esProduccion() ? $this->api_key_produccion : $this->api_key_pruebas;
    }

    /** La Secret Admin Key de la cuenta (para organizaciones y CSD). */
    public function apiKeyUsuario(): ?string
    {
        return $this->api_key_usuario;
    }

    /**
     * Versión enmascarada de una llave: solo los últimos 4 caracteres, para
     * MOSTRAR sin exponerla. Nunca se manda la llave completa al frontend.
     */
    public static function enmascarar(?string $llave): ?string
    {
        if ($llave === null || $llave === '') {
            return null;
        }

        $fin = substr($llave, -4);

        return '••••••••'.$fin;
    }
}
