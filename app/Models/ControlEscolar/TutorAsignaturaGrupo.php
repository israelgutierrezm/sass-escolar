<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * tutor_asignatura_grupo (TENANT) — tutor académico de una materia-grupo.
 */
class TutorAsignaturaGrupo extends Model
{
    use TieneAuditoria;

    protected $table = 'tutor_asignatura_grupo';

    public $incrementing = false;

    protected $primaryKey = 'asignatura_grupo_id';

    protected $fillable = [
        'asignatura_grupo_id',
        'persona_id',
        'puede_ver',
        'puede_calificar',
        'puede_comentar',
    ];

    protected function casts(): array
    {
        return [
            'puede_ver' => 'boolean',
            'puede_calificar' => 'boolean',
            'puede_comentar' => 'boolean',
        ];
    }

    public function asignaturaGrupo(): BelongsTo
    {
        return $this->belongsTo(AsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
