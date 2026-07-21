<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * oferta (TENANT) — combinación carrera+plan+campus (+ modalidad y turno).
 */
class Oferta extends Model
{
    use TieneAuditoria;

    protected $table = 'oferta';

    protected $fillable = [
        'carrera_id',
        'plan_id',
        'campus_id',
        'modalidad',
        'turno_id',
        'estatus',
    ];

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanEstudio::class, 'plan_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    /** Alumnos matriculados en esta oferta. */
    public function matriculas(): HasMany
    {
        return $this->hasMany(MatriculaOferta::class, 'oferta_id');
    }
}
