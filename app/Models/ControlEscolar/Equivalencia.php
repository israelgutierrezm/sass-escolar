<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\PlanMateria;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * equivalencias (TENANT) — materia revalidada de otra institución.
 */
class Equivalencia extends Model
{
    use TieneAuditoria;

    protected $table = 'equivalencias';

    protected $fillable = [
        'matricula_oferta_id',
        'plan_materia_id',
        'institucion_procedencia',
        'calificacion',
        'documento_ruta',
    ];

    protected function casts(): array
    {
        return [
            'calificacion' => 'decimal:2',
        ];
    }

    public function matriculaOferta(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function planMateria(): BelongsTo
    {
        return $this->belongsTo(PlanMateria::class, 'plan_materia_id');
    }
}
