<?php

declare(strict_types=1);

namespace App\Models\Plataforma;

use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * avisos_lecturas (TENANT) — quién vio o confirmó un aviso.
 *
 * `visto_en` es «ya lo cerré»; `confirmado_en` es «declaro que lo leí». Se
 * guardan aparte porque son cosas distintas: lo segundo es lo que la escuela
 * puede necesitar demostrar el día que alguien diga que nunca se enteró.
 */
class AvisoLectura extends Model
{
    protected $table = 'avisos_lecturas';

    protected $fillable = ['aviso_id', 'persona_id', 'visto_en', 'confirmado_en'];

    protected function casts(): array
    {
        return [
            'visto_en' => 'datetime',
            'confirmado_en' => 'datetime',
        ];
    }

    public function aviso(): BelongsTo
    {
        return $this->belongsTo(Aviso::class, 'aviso_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
