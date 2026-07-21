<?php

declare(strict_types=1);

namespace App\Models\Formularios;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * opciones_campo (TENANT) — una opción por fila.
 */
class OpcionCampo extends Model
{
    use TieneAuditoria;

    protected $table = 'opciones_campo';

    protected $fillable = [
        'campo_formulario_id',
        'valor',
        'etiqueta',
        'orden',
    ];

    public function campo(): BelongsTo
    {
        return $this->belongsTo(CampoFormulario::class, 'campo_formulario_id');
    }
}
