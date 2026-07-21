<?php

declare(strict_types=1);

namespace App\Models\Plataforma;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * modulo_config (TENANT) — configuración clave/valor por módulo, relacional.
 *
 * PK compuesta (modulo_id, clave): Eloquent no maneja llaves compuestas de
 * forma nativa. Para lecturas usa scopes/where; para escrituras puntuales
 * prefiere `query()->where('modulo_id', ...)->where('clave', ...)->update(...)`.
 */
class ModuloConfig extends Model
{
    use TieneAuditoria;

    protected $table = 'modulo_config';

    protected $primaryKey = 'modulo_id';

    public $incrementing = false;

    protected $fillable = [
        'modulo_id',
        'clave',
        'valor',
    ];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class);
    }
}
