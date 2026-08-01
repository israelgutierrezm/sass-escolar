<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * foro_temas (TENANT) — un hilo abierto dentro de un foro.
 *
 * El foro es una ACTIVIDAD, así que el tema cuelga de `actividades`: hereda sus
 * fechas, su ponderación y su amarre al parcial. Participar se califica por el
 * mismo camino que una tarea, sin un segundo sistema de actividades en paralelo.
 */
class ForoTema extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    protected $table = 'foro_temas';

    protected $fillable = [
        'actividad_id',
        'persona_id',
        'titulo',
        'cuerpo',
        'fijado',
        'cerrado',
        'respuestas',
        'ultima_respuesta_en',
    ];

    protected function casts(): array
    {
        return [
            'fijado' => 'boolean',
            'cerrado' => 'boolean',
            'respuestas' => 'integer',
            'ultima_respuesta_en' => 'datetime',
        ];
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class, 'actividad_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function respuestasDelTema(): HasMany
    {
        return $this->hasMany(ForoRespuesta::class, 'foro_tema_id');
    }
}
