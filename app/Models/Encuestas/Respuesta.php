<?php

declare(strict_types=1);

namespace App\Models\Encuestas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * encuesta_respuestas (TENANT) — QUÉ se contestó.
 *
 * Sin `persona_id`: ver `Participacion`. Lo que sí guarda es el contexto con el
 * que se van a segmentar los resultados —rol y campus—, elegido de forma que no
 * identifique a nadie por sí solo.
 */
class Respuesta extends Model
{
    protected $table = 'encuesta_respuestas';

    protected $fillable = ['aplicacion_id', 'sujeto_id', 'rol_id', 'campus_id', 'enviada_en'];

    protected function casts(): array
    {
        return ['enviada_en' => 'datetime'];
    }

    public function aplicacion(): BelongsTo
    {
        return $this->belongsTo(AplicacionEncuesta::class, 'aplicacion_id');
    }

    public function sujeto(): BelongsTo
    {
        return $this->belongsTo(Sujeto::class, 'sujeto_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RespuestaItem::class, 'respuesta_id');
    }
}
