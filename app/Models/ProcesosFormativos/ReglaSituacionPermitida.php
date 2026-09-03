<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Admisiones\SituacionAlumno;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * regla_situaciones_permitidas (TENANT) — en qué situación académica se admite.
 *
 * **SIN filas se admite cualquiera.** Con filas, sólo las señaladas. Así una
 * escuela que no quiera mandar a un condicionado lo dice, y no hay que cablear
 * qué situaciones son «buenas»: eso lo decide cada escuela en su catálogo, que
 * además puede tener valores que aquí nadie conoce.
 */
class ReglaSituacionPermitida extends Model
{
    use TieneAuditoria;

    protected $table = 'regla_situaciones_permitidas';

    protected $fillable = ['version_id', 'situacion_alumno_id'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ReglaProcesoVersion::class, 'version_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionAlumno::class, 'situacion_alumno_id');
    }
}
