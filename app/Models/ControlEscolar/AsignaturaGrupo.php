<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\PlanMateria;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * asignatura_grupo (TENANT) — la materia abierta en un grupo.
 */
class AsignaturaGrupo extends Model
{
    use TieneAuditoria;

    protected $table = 'asignatura_grupo';

    protected $fillable = [
        'grupo_id',
        'plan_materia_id',
        'fecha_inicio',
        'fecha_fin',
        'situacion_id',
        // Materia teórico-práctica: se pasa lista dos veces el mismo día. Lo
        // decide el docente por grupo, no el plan de estudios.
        'doble_pase_lista',
    ];

    protected function casts(): array
    {
        return [
            'doble_pase_lista' => 'boolean',
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
        ];
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function planMateria(): BelongsTo
    {
        return $this->belongsTo(PlanMateria::class, 'plan_materia_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionAsignaturaGrupo::class, 'situacion_id');
    }

    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioAsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    /** Docentes de la materia, con su tipo (titular/adjunto) en el pivote. */
    /**
     * Sus docentes VIGENTES. Los retirados no cuentan.
     *
     * ── El `wherePivotNull` no es cosmético ───────────────────────────────
     * Retirar a un docente de una materia dejó de borrar la fila y pasa a
     * marcarla —el rastro de quién dio qué es historia escolar—. Por esta
     * relación pasan cuatro caminos de AUTORIZACIÓN
     * —`AutorizaMateriaPropia`, `DocenciaController`, `EntrarAClaseController`
     * y `SalaDeMateria`—, así que sin filtrar, a quien se le quitó la materia
     * seguiría entrando a su aula, capturando sus calificaciones y abriendo su
     * clase en línea.
     */
    public function docentes(): BelongsToMany
    {
        return $this->belongsToMany(
            Docente::class,
            'docente_asignatura_grupo',
            'asignatura_grupo_id',
            'persona_id'
        )->withPivot('tipo')->wherePivotNull('deleted_at')->withTimestamps();
    }

    /** Incluidos los retirados: para el historial, nunca para autorizar. */
    public function docentesHistoricos(): BelongsToMany
    {
        return $this->belongsToMany(
            Docente::class,
            'docente_asignatura_grupo',
            'asignatura_grupo_id',
            'persona_id'
        )->withPivot('tipo', 'deleted_at')->withTimestamps();
    }

    /** El docente titular: el único que puede firmar el acta. */
    public function titular(): ?Docente
    {
        return $this->docentes()->wherePivot('tipo', 'titular')->first();
    }

    /** Tutores académicos asignados a la materia. */
    public function tutores(): HasMany
    {
        return $this->hasMany(TutorAsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    public function inscripciones(): HasMany
    {
        return $this->hasMany(Inscripcion::class, 'asignatura_grupo_id');
    }

    /** Actas emitidas: la ordinaria y, si las hubo, sus correcciones. */
    public function actas(): HasMany
    {
        return $this->hasMany(Acta::class, 'asignatura_grupo_id');
    }
}
