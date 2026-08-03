<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * accesos_bitacora_tutoria (TENANT) — quién abrió la bitácora de qué alumno.
 *
 * ── Sin auditoría ni borrado suave, a propósito ────────────────────────────
 * `TieneAuditoria` añade `created_by` y `deleted_at`, y aquí las dos sobran: el
 * autor ES el registro, y un rastro de auditoría que se pueda borrar —aunque
 * sea suavemente— deja de servir para lo único que existe. Sólo `creado_en`.
 */
class AccesoBitacoraTutoria extends Model
{
    protected $table = 'accesos_bitacora_tutoria';

    /** Un registro de auditoría no se actualiza nunca. */
    public $timestamps = false;

    protected $fillable = [
        'persona_id',
        'alumno_persona_id',
        'sesiones_vistas',
        'confidenciales_ocultas',
        'ip',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'creado_en' => 'datetime',
            'sesiones_vistas' => 'integer',
            'confidenciales_ocultas' => 'integer',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
