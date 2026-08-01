<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * foro_respuestas (TENANT) — una respuesta dentro de un tema del foro.
 *
 * Anida un solo nivel: se responde al tema o a una respuesta, y ahí para. Un
 * árbol sin fondo se vuelve ilegible en pantalla y nadie lo pidió.
 */
class ForoRespuesta extends Model
{
    use SoftDeletes;

    protected $table = 'foro_respuestas';

    protected $fillable = [
        'foro_tema_id',
        'persona_id',
        'responde_a_id',
        'cuerpo',
    ];

    public function tema(): BelongsTo
    {
        return $this->belongsTo(ForoTema::class, 'foro_tema_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function respondeA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'responde_a_id');
    }
}
