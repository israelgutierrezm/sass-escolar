<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * instituciones (TENANT) — la persona moral educativa dueña de los campus.
 *
 * Dato de encabezado (nombre + logo). Un campus la referencia solo de forma
 * informativa; no gobierna ninguna regla.
 */
class Institucion extends Model
{
    use TieneAuditoria;

    protected $table = 'instituciones';

    protected $fillable = [
        'clave',
        'nombre',
        'nombre_mostrar',
        'siglas',
        'logo_url',
    ];

    /** Campus que se agrupan bajo esta institución. */
    public function campus(): HasMany
    {
        return $this->hasMany(Campus::class, 'institucion_id');
    }

    /** Ruta autenticada del logo; null si no tiene. Nunca la ruta del disco. */
    public function urlLogo(): ?string
    {
        if ($this->logo_url === null) {
            return null;
        }

        // Cache-busting con `updated_at`: la ruta del logo es estable, así que
        // sin versión el navegador seguiría sirviendo el logo anterior de caché
        // tras subir uno nuevo (mismo criterio que el logo público en
        // HandleInertiaRequests).
        return "/academico/instituciones/{$this->id}/logo?v=".($this->updated_at?->timestamp ?? '');
    }
}
