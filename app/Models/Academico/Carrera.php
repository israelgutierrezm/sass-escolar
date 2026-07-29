<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * carreras (TENANT). `nivelEstudios` apunta al catálogo tenant homónimo.
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
        'objetivo',
        'imagen_url',
    ];

    public function nivelEstudios(): BelongsTo
    {
        return $this->belongsTo(NivelEstudio::class, 'nivel_estudios_id');
    }

    /**
     * `cveCarrera` del título electrónico de la SEP: se reutiliza la `clave` de
     * la carrera (por decisión, no hay columna oficial aparte). El
     * `identificador` es el id interno estable, no la clave oficial.
     */
    public function cveCarrera(): string
    {
        return $this->clave;
    }

    public function planes(): HasMany
    {
        return $this->hasMany(PlanEstudio::class, 'carrera_id');
    }
}
