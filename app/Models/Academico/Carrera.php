<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Landlord\NivelEstudio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * carreras (TENANT). `nivelEstudios` resuelve cross-DB contra la landlord.
 */
class Carrera extends Model
{
    use TieneAuditoria;

    protected $table = 'carreras';

    protected $fillable = [
        'identificador',
        'clave',
        'nombre',
        'nivel_estudios_id',
        'clave_sat',
        'objetivo',
        'imagen_url',
    ];

    public function nivelEstudios(): BelongsTo
    {
        return $this->belongsTo(NivelEstudio::class, 'nivel_estudios_id');
    }

    public function planes(): HasMany
    {
        return $this->hasMany(PlanEstudio::class, 'carrera_id');
    }
}
