<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * rubrica_criterios (TENANT) — cada cosa que se mira del trabajo.
 *
 * «Ortografía», «Argumentación», «Puntualidad». Su valor máximo se deriva de sus
 * niveles: no hay columna de puntos aquí, para que no pueda contradecirlos.
 */
class RubricaCriterio extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    protected $table = 'rubrica_criterios';

    protected $fillable = [
        'rubrica_id',
        'titulo',
        'descripcion',
        'orden',
    ];

    public function rubrica(): BelongsTo
    {
        // `withTrashed`: una rúbrica retirada del catálogo sigue explicando las
        // calificaciones que puso.
        return $this->belongsTo(Rubrica::class, 'rubrica_id')->withTrashed();
    }

    public function niveles(): HasMany
    {
        return $this->hasMany(RubricaNivel::class, 'criterio_id')->orderBy('orden')->orderBy('id');
    }

    /** Lo más que se puede sacar aquí: el nivel más alto. */
    public function maximo(): float
    {
        return (float) $this->niveles->max('puntos') ?: 0.0;
    }
}
