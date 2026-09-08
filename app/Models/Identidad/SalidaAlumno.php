<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * salidas_alumno (TENANT) — el registro de entrega en la puerta.
 *
 * A quién se le entregó el alumno, cuándo (`created_at`), cómo se validó y quién
 * lo procesó (`created_by`, el guardia). Es un HECHO fechado: no se edita.
 */
class SalidaAlumno extends Model
{
    use TieneAuditoria;

    protected $table = 'salidas_alumno';

    public const POR_QR = 'qr';

    public const POR_TUTOR = 'tutor';

    public const A_MANO = 'manual';

    protected $fillable = [
        'alumno_persona_id',
        'recogido_por_persona_id',
        'recogido_nombre',
        'autorizado_id',
        'como',
    ];

    public function alumno(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'alumno_persona_id');
    }

    public function recogidoPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'recogido_por_persona_id');
    }
}
