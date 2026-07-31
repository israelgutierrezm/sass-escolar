<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Academico\NivelEstudio;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * titulo_antecedente (TENANT) — estudios antecedentes de una carrera-alumno para
 * el título. Alimenta el nodo Antecedente del XML del título electrónico. El
 * tipo de estudio se toma de niveles_estudio (su identificador_titulo es el
 * idTipoEstudioAntecedente).
 */
class TituloAntecedente extends Model
{
    use TieneAuditoria;

    protected $table = 'titulo_antecedente';

    protected $fillable = [
        'matricula_oferta_id',
        'institucion_procedencia',
        'nivel_antecedente_id',
        'entidad_federativa_id',
        'fecha_inicio',
        'fecha_terminacion',
        'no_cedula',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_terminacion' => 'date',
        ];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function nivel(): BelongsTo
    {
        // withTrashed: un nivel puede ser antecedente aunque la escuela ya no lo
        // oferte como programa (p. ej. Bachillerato para una licenciatura).
        return $this->belongsTo(NivelEstudio::class, 'nivel_antecedente_id')->withTrashed();
    }
}
