<?php

declare(strict_types=1);

namespace App\Models\Plataforma;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * configuraciones (TENANT) — clave/valor escalar para lo encendible/apagable
 * que no es un módulo completo. Un valor = una fila. PK = clave (string).
 */
class Configuracion extends Model
{
    use TieneAuditoria;

    protected $table = 'configuraciones';

    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'clave',
        'valor',
        'tipo_dato',
        'descripcion',
    ];
}
