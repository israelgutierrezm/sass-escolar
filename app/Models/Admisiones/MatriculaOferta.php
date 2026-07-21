<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Academico\Oferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * matricula_oferta (TENANT) — la inscripción de una persona a una oferta.
 * Es la unidad matriculable del sistema: de aquí cuelga todo lo académico y
 * financiero del alumno.
 */
class MatriculaOferta extends Model
{
    use TieneAuditoria;

    protected $table = 'matricula_oferta';

    protected $fillable = [
        'persona_id',
        'oferta_id',
        'matricula',
        'generacion',
        'fecha_ingreso',
        'situacion_id',
        'estatus',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(Oferta::class);
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionAlumno::class, 'situacion_id');
    }

    public function expedientes(): HasMany
    {
        return $this->hasMany(Expediente::class, 'matricula_oferta_id');
    }

    /** Respuestas de formulario ligadas a ESTA oferta. */
    public function respuestasCampo(): HasMany
    {
        return $this->hasMany(RespuestaCampo::class, 'matricula_oferta_id');
    }
}
