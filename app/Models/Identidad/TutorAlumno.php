<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * tutores_alumno (TENANT) — un vínculo padre/tutor ↔ alumno.
 *
 * El «padre de familia» del sistema es una persona ligada a uno o más alumnos
 * por una fila de esta tabla. Ver la migración para el porqué del modelo
 * persona↔persona y de los permisos por vínculo.
 */
class TutorAlumno extends Model
{
    use TieneAuditoria;

    protected $table = 'tutores_alumno';

    protected $fillable = [
        'tutor_persona_id',
        'alumno_persona_id',
        'parentesco',
        'puede_ver_academico',
        'puede_ver_finanzas',
        'acceso_materia',
    ];

    protected function casts(): array
    {
        return [
            'puede_ver_academico' => 'boolean',
            'puede_ver_finanzas' => 'boolean',
            'acceso_materia' => 'boolean',
        ];
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'tutor_persona_id');
    }

    public function alumno(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'alumno_persona_id');
    }
}
