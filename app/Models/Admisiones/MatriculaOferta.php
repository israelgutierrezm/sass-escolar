<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Academico\Oferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'periodo_actual',
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

    /** Su kárdex. Sirve para saber si la matrícula ya tiene historia. */
    public function historial(): HasMany
    {
        return $this->hasMany(\App\Models\ControlEscolar\Historial::class, 'matricula_oferta_id');
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

    /** Sus certificaciones (renglones de lote). Emitida, pendiente o en error. */
    public function certificaciones(): HasMany
    {
        return $this->hasMany(\App\Models\Emision\Certificacion::class, 'matricula_oferta_id');
    }

    /** Sus titulaciones (renglones de lote de titulación). */
    public function titulaciones(): HasMany
    {
        return $this->hasMany(\App\Models\Emision\Titulacion::class, 'matricula_oferta_id');
    }

    /** Datos del título capturados por administración (uno por carrera-alumno). */
    public function tituloModalidad(): HasOne
    {
        return $this->hasOne(\App\Models\Emision\TituloModalidad::class, 'matricula_oferta_id');
    }

    public function tituloServicioSocial(): HasOne
    {
        return $this->hasOne(\App\Models\Emision\TituloServicioSocial::class, 'matricula_oferta_id');
    }

    public function tituloAntecedente(): HasOne
    {
        return $this->hasOne(\App\Models\Emision\TituloAntecedente::class, 'matricula_oferta_id');
    }
}
