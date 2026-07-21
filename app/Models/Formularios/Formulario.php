<?php

declare(strict_types=1);

namespace App\Models\Formularios;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * formularios (TENANT) — bloque versionado de preguntas.
 */
class Formulario extends Model
{
    use TieneAuditoria;

    protected $table = 'formularios';

    protected $fillable = [
        'clave',
        'titulo',
        'instruccion',
        'icono',
        'orden',
        'porcentaje',
        'obligatorio',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
        ];
    }

    public function campos(): HasMany
    {
        return $this->hasMany(CampoFormulario::class, 'formulario_id')->orderBy('orden');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(FormularioAsignacion::class, 'formulario_id');
    }
}
