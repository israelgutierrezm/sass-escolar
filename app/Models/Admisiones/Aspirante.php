<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Academico\Campus;
use App\Models\Academico\Oferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * aspirantes (TENANT) — prospecto en el embudo de admisión.
 */
class Aspirante extends Model
{
    use TieneAuditoria;

    protected $table = 'aspirantes';

    protected $fillable = [
        'persona_id',
        'oferta_interes_id',
        'campus_id',
        'clave_aspirante',
        'situacion_id',
        'paso',
        'acepto_terminos',
        'info_personal_completa',
        'cleaver_completo',
        'validado_admin',
        'origen',
        'ciclo_ingreso_id',
    ];

    protected function casts(): array
    {
        return [
            'acepto_terminos' => 'boolean',
            'info_personal_completa' => 'boolean',
            'cleaver_completo' => 'boolean',
            'validado_admin' => 'boolean',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function ofertaInteres(): BelongsTo
    {
        return $this->belongsTo(Oferta::class, 'oferta_interes_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionAspirante::class, 'situacion_id');
    }

    /** Asesores comerciales asignados. */
    public function asesores(): BelongsToMany
    {
        return $this->belongsToMany(Asesor::class, 'aspirante_asesor', 'aspirante_id', 'persona_id')
            ->withTimestamps();
    }

    /** Tutores de admisión asignados. */
    public function tutores(): BelongsToMany
    {
        return $this->belongsToMany(TutorCrm::class, 'aspirante_tutor_crm', 'aspirante_id', 'persona_id')
            ->withTimestamps();
    }

    /** Promociones/descuentos de admisión otorgados. */
    public function promociones(): BelongsToMany
    {
        return $this->belongsToMany(Promocion::class, 'aspirante_promocion', 'aspirante_id', 'promocion_id')
            ->withTimestamps();
    }

    /** Documentos entregados en el expediente de admisión. */
    public function expedienteDocumentos(): HasMany
    {
        return $this->hasMany(ExpedienteDocumento::class, 'aspirante_id');
    }

    /** Respuestas del test psicométrico Cleaver. */
    public function respuestasCleaver(): HasMany
    {
        return $this->hasMany(CleaverAspirante::class, 'aspirante_id');
    }
}
