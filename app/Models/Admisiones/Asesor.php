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
 * asesores (TENANT) — rol materializado del CRM. PK = persona_id.
 */
class Asesor extends Model
{
    use TieneAuditoria;

    protected $table = 'asesores';

    protected $primaryKey = 'persona_id';

    public $incrementing = false;

    protected $fillable = [
        'persona_id',
        'clave_asesor',
        'situacion_id',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionAsesor::class, 'situacion_id');
    }

    public function campus(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'campus_asesor', 'persona_id', 'campus_id')
            ->withTimestamps();
    }

    public function aspirantes(): BelongsToMany
    {
        return $this->belongsToMany(Aspirante::class, 'aspirante_asesor', 'persona_id', 'aspirante_id')
            ->withTimestamps();
    }
}
