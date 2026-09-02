<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * beca_alumno_evidencias (TENANT) — con qué se sostiene esta beca.
 *
 * Cuelga de la beca OTORGADA y no de la persona: el estudio socioeconómico de
 * este ciclo sostiene ESTA decisión, y el del ciclo que viene será otro.
 * Colgándolo de la persona, renovar heredaría la evidencia vieja sin que nadie
 * la revisara.
 */
class BecaAlumnoEvidencia extends Model
{
    use TieneAuditoria;

    protected $table = 'beca_alumno_evidencias';

    protected $fillable = ['beca_alumno_id', 'nombre', 'archivo_ruta', 'notas'];

    public function becaAlumno(): BelongsTo
    {
        return $this->belongsTo(BecaAlumno::class, 'beca_alumno_id');
    }
}
