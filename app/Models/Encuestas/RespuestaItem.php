<?php

declare(strict_types=1);

namespace App\Models\Encuestas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * encuesta_respuesta_items (TENANT) — lo contestado a UNA pregunta.
 *
 * Una fila por pregunta, salvo en opción múltiple: ahí hay una por cada opción
 * marcada, y contarlas es exactamente lo que se quiere saber.
 */
class RespuestaItem extends Model
{
    protected $table = 'encuesta_respuesta_items';

    protected $fillable = ['respuesta_id', 'pregunta_id', 'opcion_id', 'numero', 'texto'];

    protected function casts(): array
    {
        return ['numero' => 'decimal:2'];
    }

    public function respuesta(): BelongsTo
    {
        return $this->belongsTo(Respuesta::class, 'respuesta_id');
    }

    public function pregunta(): BelongsTo
    {
        return $this->belongsTo(Pregunta::class, 'pregunta_id');
    }

    public function opcion(): BelongsTo
    {
        return $this->belongsTo(Opcion::class, 'opcion_id');
    }
}
