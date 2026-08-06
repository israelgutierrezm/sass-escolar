<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\Asignatura;
use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * Las materias que PUEDE impartir: su perfil, no su carga de este ciclo.
     *
     * `asignaturasGrupo` dice qué está dando ahora; ésta, de qué sabe. Sin la
     * segunda no se le puede proponer nada al armar un horario ni contestar «¿a
     * quién le doy Cálculo si falta el titular?».
     */
    public function asignaturasQuePuedeImpartir(): BelongsToMany
    {
        return $this->belongsToMany(
            Asignatura::class,
            'asignatura_docente',
            'persona_id',
            'asignatura_id'
        )->withPivot('preferencia')->withTimestamps();
    }

    /** Cuándo puede dar clase. Sin ciclo = su disponibilidad habitual. */
    public function disponibilidad(): HasMany
    {
        return $this->hasMany(DisponibilidadDocente::class, 'persona_id', 'persona_id');
    }

    /** Grados/títulos del docente (CV académico). Cuelgan de la persona. */
    public function titulos(): HasMany
    {
        return $this->hasMany(TituloDocente::class, 'persona_id', 'persona_id');
    }
}
