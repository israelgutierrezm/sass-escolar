<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * adeudo_ajustes (TENANT) — por qué un cargo cuesta lo que cuesta.
 *
 * Un renglón por cada beca, descuento o recargo que movió el monto. El `monto`
 * va CON SIGNO (recargo +, beca/descuento −), así el total es
 * `adeudo.monto + SUM(ajustes)` y el estado de cuenta se puede auditar renglón a
 * renglón, en vez de mostrar un número que nadie sabe explicar cuando el papá
 * reclama.
 *
 * `etiqueta` es un snapshot: la beca puede renombrarse o borrarse después, y el
 * cargo viejo debe seguir explicándose igual.
 */
class AdeudoAjuste extends Model
{
    use TieneAuditoria;

    public const TIPO_BECA = 'beca';

    public const TIPO_DESCUENTO = 'descuento';

    public const TIPO_RECARGO = 'recargo';

    protected $table = 'adeudo_ajustes';

    protected $fillable = [
        'adeudo_id',
        'tipo',
        'origen_id',
        'etiqueta',
        'monto',
        'periodo_aplicado',
    ];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2'];
    }

    public function adeudo(): BelongsTo
    {
        return $this->belongsTo(Adeudo::class, 'adeudo_id');
    }
}
