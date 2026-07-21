<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * alumnos (TENANT) — rol materializado. PK = persona_id.
 */
class Alumno extends Model
{
    use TieneAuditoria;

    protected $table = 'alumnos';

    protected $primaryKey = 'persona_id';

    public $incrementing = false;

    protected $fillable = [
        'persona_id',
        'clave_alumno',
        'cedula_profesional',
        'situacion_id',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionAlumno::class, 'situacion_id');
    }

    /** Sus inscripciones a ofertas (puede tener varias simultáneas). */
    public function matriculas(): HasMany
    {
        return $this->hasMany(MatriculaOferta::class, 'persona_id', 'persona_id');
    }
}
