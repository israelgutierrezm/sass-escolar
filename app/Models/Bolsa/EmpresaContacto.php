<?php

declare(strict_types=1);

namespace App\Models\Bolsa;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * empresa_contactos (TENANT) — con quién se habla en esa empresa.
 *
 * UNA sola tabla para todos los contactos, con `es_principal` para el de
 * siempre. La spec proponía además un `persona_contacto_id` en `empresas`, lo
 * que dejaba dos sitios donde buscar al reclutador y la duda de si el principal
 * aparecía también aquí. Ver el docblock de la migración.
 *
 * `persona_id` es opcional: la mayoría de los contactos son un nombre y un
 * teléfono, y obligarlos a ser `persona` llenaría el padrón de la escuela con
 * gente que no estudia ni trabaja ahí. Se llena sólo para el reclutador que
 * además tenga cuenta.
 */
class EmpresaContacto extends Model
{
    use TieneAuditoria;

    protected $table = 'empresa_contactos';

    protected $fillable = [
        'empresa_id',
        'persona_id',
        'nombre',
        'puesto',
        'email',
        'telefono',
        'es_principal',
    ];

    protected function casts(): array
    {
        return ['es_principal' => 'boolean'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
