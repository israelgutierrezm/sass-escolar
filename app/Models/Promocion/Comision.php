<?php

declare(strict_types=1);

namespace App\Models\Promocion;

use App\Models\Admisiones\Asesor;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * comisiones (TENANT) — lo que la escuela le debe a un promotor.
 *
 * Se devenga al INSCRIBIRSE el aspirante, nunca al capturarlo: se paga por
 * resultado. El monto se CONGELA en ese momento; si la escuela cambia la regla
 * después, lo ya ganado no se recalcula, porque era el trato vigente cuando ese
 * alumno se inscribió.
 *
 * Cancelar no borra: una comisión que se devengó y luego se retiró es un hecho
 * que alguien va a preguntar, y el motivo es la respuesta.
 */
class Comision extends Model
{
    use TieneAuditoria;

    public const ESTATUS_DEVENGADA = 'devengada';

    public const ESTATUS_PAGADA = 'pagada';

    public const ESTATUS_CANCELADA = 'cancelada';

    protected $table = 'comisiones';

    protected $attributes = ['estatus' => self::ESTATUS_DEVENGADA];

    protected $fillable = [
        'persona_id',
        'aspirante_id',
        'matricula_oferta_id',
        'regla_id',
        'monto',
        'estatus',
        'devengada_en',
        'pagada_en',
        'motivo_cancelacion',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'devengada_en' => 'datetime',
            'pagada_en' => 'datetime',
        ];
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(Asesor::class, 'persona_id', 'persona_id');
    }

    public function aspirante(): BelongsTo
    {
        return $this->belongsTo(Aspirante::class);
    }

    public function matriculaOferta(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function regla(): BelongsTo
    {
        return $this->belongsTo(ReglaComision::class, 'regla_id');
    }

    /** Lo que la escuela todavía debe. */
    public function scopePorPagar(Builder $query): Builder
    {
        return $query->where('estatus', self::ESTATUS_DEVENGADA);
    }
}
