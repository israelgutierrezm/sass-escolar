<?php

declare(strict_types=1);

namespace App\Models\Plataforma;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * modulos (TENANT-CONFIG) — catálogo de módulos encendibles del sistema.
 */
class Modulo extends Model
{
    use TieneAuditoria;

    protected $table = 'modulos';

    protected $fillable = [
        'clave',
        'nombre',
    ];

    public function estadoActivo(): HasOne
    {
        return $this->hasOne(ModuloActivo::class);
    }
}
