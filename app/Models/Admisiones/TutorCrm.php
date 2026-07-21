<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * tutores_crm (TENANT) — tutor de admisión. PK = persona_id.
 * No confundir con el tutor académico (Módulo 5) ni el familiar (Módulo 13).
 */
class TutorCrm extends Model
{
    use TieneAuditoria;

    protected $table = 'tutores_crm';

    protected $primaryKey = 'persona_id';

    public $incrementing = false;

    protected $fillable = [
        'persona_id',
        'clave_tutor',
        'situacion_id',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionTutor::class, 'situacion_id');
    }

    public function campus(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'campus_tutor', 'persona_id', 'campus_id')
            ->withTimestamps();
    }

    public function aspirantes(): BelongsToMany
    {
        return $this->belongsToMany(Aspirante::class, 'aspirante_tutor_crm', 'persona_id', 'aspirante_id')
            ->withTimestamps();
    }
}
