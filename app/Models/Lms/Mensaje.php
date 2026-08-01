<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * mensajes (TENANT) — un mensaje de un chat de materia.
 *
 * Del autor se guarda la PERSONA y no el usuario: el mensaje sigue teniendo
 * autor aunque su cuenta desaparezca. Mismo criterio que quien pasa lista.
 */
class Mensaje extends Model
{
    use SoftDeletes;

    protected $table = 'mensajes';

    protected $fillable = [
        'conversacion_id',
        'persona_id',
        'cuerpo',
    ];

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(Conversacion::class, 'conversacion_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
