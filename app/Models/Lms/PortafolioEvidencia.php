<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * portafolio_evidencias (TENANT) — una pieza del portafolio de un alumno.
 *
 * Cuelga de la ENTREGA, que es la que ya identifica «el trabajo de esta persona
 * en esta actividad» y la que lleva la calificación. Ver la migración.
 *
 * ── Con borrado lógico, al revés que `entrega_archivos` ────────────────────
 * Un adjunto retirado antes de entregar es corregirse. Una evidencia retirada
 * DESPUÉS de calificar cambia aquello a lo que se refiere la calificación, y eso
 * es historia escolar: se conserva, como los renglones de un acta corregida.
 */
class PortafolioEvidencia extends Model
{
    use TieneAuditoria;

    protected $table = 'portafolio_evidencias';

    protected $fillable = [
        'entrega_id',
        'titulo',
        'descripcion',
        'fecha_evidencia',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'fecha_evidencia' => 'date',
            'orden' => 'integer',
        ];
    }

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(Entrega::class, 'entrega_id');
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(PortafolioArchivo::class, 'evidencia_id');
    }

    /**
     * En el orden que decidió el alumno.
     *
     * Y con `id` de desempate: sin él, dos evidencias con el mismo `orden`
     * —que pasa en cuanto alguien arrastra dos a la vez— salen en el orden que
     * quiera MySQL, y la pantalla las baraja entre recargas.
     */
    public function scopeEnOrden(Builder $query): Builder
    {
        return $query->orderBy('orden')->orderBy('id');
    }
}
