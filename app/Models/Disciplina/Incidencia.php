<?php

declare(strict_types=1);

namespace App\Models\Disciplina;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * incidencias (TENANT) — una conducta registrada de un alumno.
 *
 * Cuelga de la MATRICULA, no de la persona: quien estudia dos programas académicos lleva la
 * conducta de cada una por separado. `fecha` es cuando OCURRIO, que no es
 * `created_at`.
 */
class Incidencia extends Model
{
    use TieneAuditoria;

    protected $table = 'incidencias';

    protected $fillable = [
        'matricula_oferta_id',
        'tipo_incidencia_id',
        'fecha',
        'descripcion',
        'reportada_por',
    ];

    protected function casts(): array
    {
        return ['fecha' => 'date'];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoIncidencia::class, 'tipo_incidencia_id');
    }

    public function reporta(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'reportada_por');
    }

    /** Las sanciones que la citaron. */
    public function sanciones(): BelongsToMany
    {
        return $this->belongsToMany(Sancion::class, 'incidencia_sancion', 'incidencia_id', 'sancion_id');
    }
}
