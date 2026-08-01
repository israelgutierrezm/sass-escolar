<?php

declare(strict_types=1);

namespace App\Models\Lms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * reactivo_opciones (TENANT) — una opción de un reactivo.
 *
 * Sirve para tres cosas según el tipo: ser la respuesta a elegir (opción única
 * o múltiple), ser el elemento izquierdo de un emparejamiento —y entonces
 * `pareja` dice con qué va o a qué categoría pertenece—, o ser un paso de una
 * secuencia, donde el que manda es `orden`.
 */
class ReactivoOpcion extends Model
{
    protected $table = 'reactivo_opciones';

    protected $fillable = [
        'reactivo_id',
        'texto',
        'correcta',
        'pareja',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'correcta' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function reactivo(): BelongsTo
    {
        return $this->belongsTo(Reactivo::class, 'reactivo_id');
    }
}
