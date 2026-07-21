<?php

declare(strict_types=1);

namespace App\Models\Plataforma;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * modulos_activos (TENANT) — qué módulos tiene encendidos esta escuela.
 * PK = modulo_id (no autoincremental).
 */
class ModuloActivo extends Model
{
    use TieneAuditoria;

    protected $table = 'modulos_activos';

    protected $primaryKey = 'modulo_id';

    public $incrementing = false;

    protected $fillable = [
        'modulo_id',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class);
    }
}
