<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** aulas (TENANT-CONFIG) — espacio físico de un campus. */
class Aula extends Model
{
    use TieneAuditoria;

    protected $table = 'aulas';

    protected $fillable = ['campus_id', 'clave', 'nombre', 'capacidad'];

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
