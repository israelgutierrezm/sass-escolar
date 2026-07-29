<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * titulos_profesionales (TENANT-CONFIG) — abreviatura + descripción. Lo
 * administra la escuela desde Configuración → Catálogos.
 */
class TituloProfesional extends Model
{
    use TieneAuditoria;

    protected $table = 'titulos_profesionales';

    protected $fillable = ['abreviatura', 'descripcion'];
}
