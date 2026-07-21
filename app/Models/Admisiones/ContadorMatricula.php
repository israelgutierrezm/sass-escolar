<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use Illuminate\Database\Eloquent\Model;

/**
 * contadores_matricula (TENANT) — consecutivo atómico por llave de ámbito.
 *
 * NO usa el trait TieneAuditoria: sin soft delete a propósito (ver la
 * migración). El incremento se hace en App\Services\GeneradorMatricula con una
 * sentencia atómica, no con save() sobre este modelo.
 */
class ContadorMatricula extends Model
{
    protected $table = 'contadores_matricula';

    /** La identidad del contador es su propia clave; no hay id autoincremental. */
    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['clave', 'valor'];
}
