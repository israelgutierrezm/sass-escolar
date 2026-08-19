<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\ReviveAlGuardar;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * entrega_rubrica (TENANT) — cómo le fue a una entrega en cada criterio.
 *
 * Es el desglose de la calificación: la nota sigue viviendo en
 * `entregas.calificacion` (es la que promedia), y esto dice de dónde salió.
 *
 * `ReviveAlGuardar` porque recalificar reemplaza el renglón, y el único
 * `(entrega_id, criterio_id)` no ve las filas borradas: la trampa de siempre.
 */
class EntregaRubrica extends Model
{
    use ReviveAlGuardar;
    use SoftDeletes;
    use TieneAuditoria;

    protected $table = 'entrega_rubrica';

    protected $fillable = [
        'entrega_id',
        'criterio_id',
        'nivel_id',
        'puntos',
        'comentario',
    ];

    protected function casts(): array
    {
        return ['puntos' => 'decimal:2'];
    }

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(Entrega::class, 'entrega_id');
    }

    public function criterio(): BelongsTo
    {
        return $this->belongsTo(RubricaCriterio::class, 'criterio_id')->withTrashed();
    }

    public function nivel(): BelongsTo
    {
        return $this->belongsTo(RubricaNivel::class, 'nivel_id')->withTrashed();
    }
}
