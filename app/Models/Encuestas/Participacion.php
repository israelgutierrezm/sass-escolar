<?php

declare(strict_types=1);

namespace App\Models\Encuestas;

use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * encuesta_participaciones (TENANT) — QUIÉN contestó.
 *
 * Deliberadamente separada de la respuesta y sin llave hacia ella. Es lo que
 * permite las dos cosas a la vez: que una encuesta anónima lo sea de verdad y
 * que aun así se sepa a quién le falta contestar y se le pueda exigir. Con un
 * `persona_id` en la respuesta, el anonimato dependería de que nadie mire la
 * tabla —y eso no es anonimato, es una promesa—.
 */
class Participacion extends Model
{
    protected $table = 'encuesta_participaciones';

    protected $fillable = ['aplicacion_id', 'sujeto_id', 'persona_id', 'respondido_en'];

    protected function casts(): array
    {
        return ['respondido_en' => 'datetime'];
    }

    public function aplicacion(): BelongsTo
    {
        return $this->belongsTo(AplicacionEncuesta::class, 'aplicacion_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
