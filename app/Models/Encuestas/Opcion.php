<?php

declare(strict_types=1);

namespace App\Models\Encuestas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * encuesta_opciones (TENANT) — una alternativa de una pregunta.
 *
 * `valor` es su peso cuando la opción ordena algo —«siempre» vale más que
 * «nunca»— y es lo que convierte una pregunta de opciones en promediable. Nulo
 * cuando no ordena nada: entonces sólo se cuenta.
 */
class Opcion extends Model
{
    protected $table = 'encuesta_opciones';

    protected $fillable = ['pregunta_id', 'texto', 'valor', 'orden'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2', 'orden' => 'integer'];
    }

    public function pregunta(): BelongsTo
    {
        return $this->belongsTo(Pregunta::class, 'pregunta_id');
    }
}
