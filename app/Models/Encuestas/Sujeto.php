<?php

declare(strict_types=1);

namespace App\Models\Encuestas;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * aplicacion_sujetos (TENANT) — a quién se evalúa.
 *
 * Un docente EN UNA MATERIA, no el docente a secas: el mismo profesor puede dar
 * dos materias y salir bien en una y mal en otra, y promediarlas escondería
 * justo el dato que sirve para actuar.
 */
class Sujeto extends Model
{
    public const TITULAR = 'titular';

    public const ADJUNTO = 'adjunto';

    protected $table = 'aplicacion_sujetos';

    protected $fillable = ['aplicacion_id', 'persona_id', 'asignatura_grupo_id', 'papel'];

    public function aplicacion(): BelongsTo
    {
        return $this->belongsTo(AplicacionEncuesta::class, 'aplicacion_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(AsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    public function respuestas(): HasMany
    {
        return $this->hasMany(Respuesta::class, 'sujeto_id');
    }
}
