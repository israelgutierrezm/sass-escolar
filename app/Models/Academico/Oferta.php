<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * oferta (TENANT) — combinación programa académico+plan+campus que la escuela imparte.
 *
 * Es la unidad a la que se matriculan los alumnos y su llave única. La
 * `modalidad` es un atributo OPCIONAL (no delimita). El turno se administra en
 * los grupos, no en la oferta.
 */
class Oferta extends Model
{
    use TieneAuditoria;

    protected $table = 'oferta';

    protected $fillable = [
        'programa_academico_id',
        'plan_id',
        'campus_id',
        'modalidad',
        'estatus',
    ];

    public function programaAcademico(): BelongsTo
    {
        return $this->belongsTo(ProgramaAcademico::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanEstudio::class, 'plan_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** Alumnos matriculados en esta oferta. */
    public function matriculas(): HasMany
    {
        return $this->hasMany(MatriculaOferta::class, 'oferta_id');
    }
}
