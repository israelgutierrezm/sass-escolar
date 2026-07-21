<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * docentes (TENANT) — rol materializado. PK = persona_id.
 */
class Docente extends Model
{
    use TieneAuditoria;

    /** Alcance de edición de contenido en el LMS (del legacy IMEP). */
    public const EDICION_NINGUNA = 0;
    public const EDICION_SU_GRUPO = 1;
    public const EDICION_TODOS = 2;

    protected $table = 'docentes';

    protected $primaryKey = 'persona_id';

    public $incrementing = false;

    protected $fillable = [
        'persona_id',
        'clave_profesor',
        'cedula_profesional',
        'tipo_docente_id',
        'situacion_id',
        'edicion_contenido',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function tipoDocente(): BelongsTo
    {
        return $this->belongsTo(TipoDocente::class, 'tipo_docente_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionDocente::class, 'situacion_id');
    }

    public function campus(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'campus_docente', 'persona_id', 'campus_id')
            ->withTimestamps();
    }

    /** Materias que imparte, con su tipo (titular/adjunto) en el pivote. */
    public function asignaturasGrupo(): BelongsToMany
    {
        return $this->belongsToMany(
            AsignaturaGrupo::class,
            'docente_asignatura_grupo',
            'persona_id',
            'asignatura_grupo_id'
        )->withPivot('tipo')->withTimestamps();
    }
}
