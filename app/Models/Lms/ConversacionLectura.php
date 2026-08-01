<?php

declare(strict_types=1);

namespace App\Models\Lms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * conversacion_lecturas (TENANT) — hasta dónde leyó cada quien.
 *
 * Guarda el ID del último mensaje visto y no una fecha: con fecha, el reloj
 * desfasado de una computadora podría marcar como leído lo que nunca se abrió.
 */
class ConversacionLectura extends Model
{
    protected $table = 'conversacion_lecturas';

    protected $fillable = [
        'conversacion_id',
        'persona_id',
        'ultimo_mensaje_id',
    ];

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(Conversacion::class, 'conversacion_id');
    }
}
