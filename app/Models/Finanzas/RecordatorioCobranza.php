<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Plataforma\Aviso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * recordatorios_cobranza (TENANT) — este cargo ya recibió este peldaño.
 *
 * Es lo único que impide que el comando diario vuelva a avisar cada mañana
 * mientras el cargo siga vencido: un recordatorio que llega treinta días
 * seguidos deja de leerse al tercero.
 *
 * `aviso_id` es nullable a propósito: un aviso se puede borrar, y el rastro de
 * que YA se recordó tiene que sobrevivirle. Si el rastro se fuera con él, el
 * comando volvería a empezar la escalera.
 */
class RecordatorioCobranza extends Model
{
    use TieneAuditoria;

    protected $table = 'recordatorios_cobranza';

    protected $fillable = ['adeudo_id', 'regla_id', 'aviso_id', 'emitido_en'];

    protected function casts(): array
    {
        return ['emitido_en' => 'datetime'];
    }

    public function adeudo(): BelongsTo
    {
        return $this->belongsTo(Adeudo::class, 'adeudo_id');
    }

    public function regla(): BelongsTo
    {
        return $this->belongsTo(ReglaRecordatorioCobranza::class, 'regla_id');
    }

    public function aviso(): BelongsTo
    {
        return $this->belongsTo(Aviso::class, 'aviso_id');
    }
}
