<?php

declare(strict_types=1);

namespace App\Models\Lms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * respuestas (TENANT) — lo que un alumno contestó a un reactivo en un intento.
 *
 * `valor` es JSON porque cada tipo contesta distinto: el id de una opción, una
 * lista de ids, un texto, un número, los pares de un emparejamiento, una
 * coordenada. Guarda la respuesta calificada Y sin calificar: el intento puede
 * quedar a medias y hay que poder recuperarlo tal cual estaba.
 */
class Respuesta extends Model
{
    protected $table = 'respuestas';

    protected $fillable = [
        'intento_id',
        'reactivo_id',
        'valor',
        'puntos',
        'correcta',
        'calificada_por_maquina',
        'comentario',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'array',
            'puntos' => 'decimal:2',
            'correcta' => 'boolean',
        ];
    }

    public function intento(): BelongsTo
    {
        return $this->belongsTo(Intento::class, 'intento_id');
    }

    public function reactivo(): BelongsTo
    {
        return $this->belongsTo(Reactivo::class, 'reactivo_id');
    }

    /** Sigue esperando que el docente la lea. */
    public function pendienteDeRevision(): bool
    {
        return $this->puntos === null;
    }
}
