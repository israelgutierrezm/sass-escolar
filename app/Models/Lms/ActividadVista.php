<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\ReviveAlGuardar;
use App\Models\ControlEscolar\Inscripcion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * actividad_vistas (TENANT) — constancia de que el alumno pasó por una actividad.
 *
 * Es lo que da avance a un curso hecho de lecturas: sin esto, quien recorriera
 * diez lecciones seguiría viendo 0%, porque una lectura no se entrega y por
 * tanto no deja rastro en `entregas`.
 *
 * Usa `ReviveAlGuardar` desde el principio: la llave única y el borrado lógico
 * juntos son la combinación que revienta con `updateOrCreate`, y aquí ya se
 * sabe de antemano.
 */
class ActividadVista extends Model
{
    use ReviveAlGuardar;
    use SoftDeletes;

    protected $table = 'actividad_vistas';

    protected $fillable = [
        'actividad_id',
        'inscripcion_id',
        'vista_en',
        'completada_en',
    ];

    protected function casts(): array
    {
        return [
            'vista_en' => 'datetime',
            'completada_en' => 'datetime',
        ];
    }

    /** La declaró terminada, no solo abierta. */
    public function completada(): bool
    {
        return $this->completada_en !== null;
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class, 'actividad_id');
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(Inscripcion::class, 'inscripcion_id');
    }
}
