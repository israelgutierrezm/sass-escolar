<?php

declare(strict_types=1);

namespace App\Models\Formularios;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/** tipos_antecedente_academico (TENANT-CONFIG). */
class TipoAntecedenteAcademico extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_antecedente_academico';

    protected $fillable = ['clave', 'nombre'];
}
