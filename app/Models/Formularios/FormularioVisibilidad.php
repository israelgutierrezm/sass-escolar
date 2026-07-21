<?php

declare(strict_types=1);

namespace App\Models\Formularios;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** formulario_visibilidad (TENANT-CONFIG). */
class FormularioVisibilidad extends Model
{
    use TieneAuditoria;

    protected $table = 'formulario_visibilidad';

    protected $fillable = ['clave', 'nombre'];
}
