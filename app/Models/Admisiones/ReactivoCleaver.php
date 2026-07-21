<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * reactivos_cleaver (TENANT-CONFIG) — reactivo del test DISC.
 * c = Cumplimiento, d = Dominio, i = Influencia, s = Estabilidad.
 */
class ReactivoCleaver extends Model
{
    use TieneAuditoria;

    protected $table = 'reactivos_cleaver';

    protected $fillable = ['nombre_reactivo', 'c', 'd', 'i', 's'];

    protected function casts(): array
    {
        return [
            'c' => 'boolean',
            'd' => 'boolean',
            'i' => 'boolean',
            's' => 'boolean',
        ];
    }
}
