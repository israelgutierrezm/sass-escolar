<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * temas (TENANT-CONFIG) — catálogo de temas visuales de la escuela.
 */
class Tema extends Model
{
    use TieneAuditoria;

    protected $table = 'temas';

    protected $fillable = [
        'clave',
        'nombre',
        'es_default',
        'permite_override_usuario',
    ];

    protected function casts(): array
    {
        return [
            'es_default' => 'boolean',
            'permite_override_usuario' => 'boolean',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(TemaToken::class);
    }
}
