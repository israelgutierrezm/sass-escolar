<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * bitacora_accesos (TENANT) — un movimiento de acceso o de seguridad.
 *
 * Registro de auditoría: se escribe, no se edita ni se borra. Ver la migración.
 */
class BitacoraAcceso extends Model
{
    protected $table = 'bitacora_accesos';

    public $timestamps = false;

    public const ENTRADA = 'entrada';

    public const SALIDA = 'salida';

    public const RECUPERACION_SOLICITADA = 'recuperacion_solicitada';

    public const RECUPERACION_COMPLETADA = 'recuperacion_completada';

    public const CREDENCIALES_ENVIADAS = 'credenciales_enviadas';

    protected $fillable = [
        'persona_id',
        'usuario_id',
        'tipo',
        'ip',
        'navegador',
        'equipo',
        'agente',
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

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
