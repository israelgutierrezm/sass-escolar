<?php

declare(strict_types=1);

namespace App\Models\Encuestas;

use App\Enums\TipoPregunta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * encuesta_preguntas (TENANT) — una pregunta del cuestionario.
 *
 * El `tipo` decide qué se puede hacer con la respuesta al cerrar: una escala se
 * promedia, una opción se cuenta, una abierta se lee. Ver `App\Enums\TipoPregunta`.
 */
class Pregunta extends Model
{
    protected $table = 'encuesta_preguntas';

    protected $fillable = ['encuesta_id', 'texto', 'ayuda', 'tipo', 'requerida', 'config', 'orden'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoPregunta::class,
            'requerida' => 'boolean',
            'config' => 'array',
            'orden' => 'integer',
        ];
    }

    public function encuesta(): BelongsTo
    {
        return $this->belongsTo(Encuesta::class, 'encuesta_id');
    }

    public function opciones(): HasMany
    {
        return $this->hasMany(Opcion::class, 'pregunta_id')->orderBy('orden');
    }

    /** Los extremos de la escala: de 1 a N. */
    public function escalaMaxima(): int
    {
        return (int) ($this->config['maximo'] ?? 5);
    }
}
