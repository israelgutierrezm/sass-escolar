<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * reglas_matricula (TENANT-CONFIG) — formato de matrícula de la escuela.
 */
class ReglaMatricula extends Model
{
    use TieneAuditoria;

    /** Ámbitos donde puede definirse una regla, del más específico al más general. */
    public const AMBITOS = ['plan', 'carrera', 'global'];

    /** Cada cuánto se reinicia el consecutivo. */
    public const AMBITOS_CONSECUTIVO = [
        'global',
        'anio',
        'carrera',
        'plan',
        'carrera_anio',
        'plan_anio',
    ];

    protected $table = 'reglas_matricula';

    protected $fillable = [
        'ambito',
        'ambito_id',
        'plantilla',
        'ambito_consecutivo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }
}
