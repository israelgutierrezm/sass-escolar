<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** situaciones_tutor (TENANT-CONFIG). */
class SituacionTutor extends Model
{
    use TieneAuditoria;

    protected $table = 'situaciones_tutor';

    protected $fillable = ['clave', 'nombre'];
}
