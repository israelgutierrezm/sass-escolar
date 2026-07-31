<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * titulo_modalidad (TENANT) — datos de la modalidad/expedición del título de una
 * carrera-alumno. Alimenta el nodo Expedicion (y la fechaTerminacion del nodo
 * Carrera) del XML del título electrónico.
 */
class TituloModalidad extends Model
{
    use TieneAuditoria;

    protected $table = 'titulo_modalidad';

    protected $fillable = [
        'matricula_oferta_id',
        'modalidad_titulacion_id',
        'fecha_expedicion',
        'fecha_examen_profesional',
        'fecha_exencion_examen',
        'fecha_terminacion_carrera',
        'entidad_federativa_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_expedicion' => 'date',
            'fecha_examen_profesional' => 'date',
            'fecha_exencion_examen' => 'date',
            'fecha_terminacion_carrera' => 'date',
        ];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function modalidad(): BelongsTo
    {
        return $this->belongsTo(ModalidadTitulacion::class, 'modalidad_titulacion_id');
    }
}
