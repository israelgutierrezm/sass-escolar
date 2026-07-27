<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos_facturacion (TENANT) — los datos fiscales con los que un alumno quiere
 * su factura. El receptor puede ser el alumno o un tercero. Ver la migración.
 */
class DatosFacturacion extends Model
{
    use TieneAuditoria;

    protected $table = 'datos_facturacion';

    protected $attributes = [
        'quiere_factura' => false,
        'es_tercero' => false,
    ];

    protected $fillable = [
        'persona_id',
        'quiere_factura',
        'es_tercero',
        'rfc',
        'razon_social',
        'regimen_fiscal',
        'cp',
        'uso_cfdi',
        'correo_fiscal',
        'facturapi_customer_id',
    ];

    protected function casts(): array
    {
        return [
            'quiere_factura' => 'boolean',
            'es_tercero' => 'boolean',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
