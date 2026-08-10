<?php

declare(strict_types=1);

namespace App\Models\Facturacion;

use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * facturacion_eventos (TENANT) — bitácora de cambios de la configuración de
 * facturación: quién guardó, quién probó la conexión, cambios de ambiente,
 * activación/desactivación.
 *
 * En `detalle` NUNCA se guardan llaves/tokens completos: a lo más, enmascarados.
 */
class FacturacionEvento extends Model
{
    protected $table = 'facturacion_eventos';

    public $timestamps = false;

    public const CONFIG_GUARDADA = 'config_guardada';

    public const CONEXION_PROBADA = 'conexion_probada';

    public const AMBIENTE_CAMBIADO = 'ambiente_cambiado';

    public const MODULO_ACTIVADO = 'modulo_activado';

    public const MODULO_DESACTIVADO = 'modulo_desactivado';

    protected $fillable = [
        'usuario_id',
        'tipo',
        'ambiente',
        'resultado',
        'mensaje',
        'detalle',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'detalle' => 'array',
            'creado_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
