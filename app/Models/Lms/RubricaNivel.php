<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * rubrica_niveles (TENANT) — los grados de logro de un criterio.
 *
 * «Excelente / Suficiente / Insuficiente», cada uno con sus puntos y con la
 * descripción de qué hay que haber hecho para merecerlo. Esa descripción es lo
 * que el alumno lee ANTES de entregar: sin ella la rúbrica sólo reparte números
 * y no dice nada que se pueda accionar.
 */
class RubricaNivel extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    protected $table = 'rubrica_niveles';

    protected $fillable = [
        'criterio_id',
        'titulo',
        'descripcion',
        'puntos',
        'orden',
    ];

    protected function casts(): array
    {
        return ['puntos' => 'decimal:2'];
    }

    public function criterio(): BelongsTo
    {
        return $this->belongsTo(RubricaCriterio::class, 'criterio_id')->withTrashed();
    }
}
