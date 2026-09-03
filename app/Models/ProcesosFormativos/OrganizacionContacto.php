<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * organizacion_contactos (TENANT) — con quién se habla en la receptora.
 *
 * ── UN solo lugar ──────────────────────────────────────────────────────────
 * Y no un `contacto_id` en la organización MÁS una tabla de «contactos
 * adicionales»: serían dos sitios donde buscar al mismo responsable y la duda
 * de si el principal aparece también en la tabla. Es la lección que dejó el
 * padrón de empleadores de la bolsa.
 *
 * ── Ser el CONTACTO y ser el SUPERVISOR son cosas distintas ────────────────
 * Quien firma el convenio en una dependencia rara vez es quien está al lado
 * del practicante todos los días, y el expediente apunta al segundo. Por eso
 * son dos banderas y no un tipo con dos valores: la misma persona puede ser las
 * dos cosas en una organización chica.
 *
 * ── `persona_id` es opcional a propósito ───────────────────────────────────
 * Exigir que el supervisor externo sea una `persona` de la escuela llenaría el
 * padrón de gente que ni estudia ni trabaja ahí. Cuando llegue su portal, será
 * esta columna la que lo haga posible sin cambiar nada más.
 */
class OrganizacionContacto extends Model
{
    use TieneAuditoria;

    protected $table = 'organizacion_contactos';

    protected $fillable = [
        'organizacion_id',
        'nombre',
        'cargo',
        'correo',
        'telefono',
        'es_principal',
        'es_supervisor',
        'persona_id',
    ];

    protected function casts(): array
    {
        return ['es_principal' => 'boolean', 'es_supervisor' => 'boolean'];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(OrganizacionReceptora::class, 'organizacion_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
