<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * tipos_responsable (TENANT-CONFIG) — Certificación (1) o Titulación (2).
 * Catálogo oficial protegido; la lógica lo conoce por estos ids.
 */
class TipoResponsable extends Model
{
    use TieneAuditoria;

    /** Ids fijos que la aplicación conoce. */
    public const CERTIFICACION = 1;

    public const TITULACION = 2;

    protected $table = 'tipos_responsable';

    protected $fillable = ['clave', 'nombre', 'protegido'];

    protected function casts(): array
    {
        return ['protegido' => 'boolean'];
    }
}
