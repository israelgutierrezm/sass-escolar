<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * modalidades_titulacion (TENANT-CONFIG) — catálogo OFICIAL SEP de modalidades
 * de titulación (por tesis, por promedio, por CENEVAL…). `identificador` es el
 * idModalidadTitulacion que se escribe en el título electrónico; `tipo_modalidad`
 * distingue las que van con acta de examen de las de constancia de exención.
 */
class ModalidadTitulacion extends Model
{
    use TieneAuditoria;

    protected $table = 'modalidades_titulacion';

    protected $fillable = ['identificador', 'descripcion', 'tipo_modalidad', 'protegido', 'activo'];

    protected function casts(): array
    {
        return ['identificador' => 'integer', 'protegido' => 'boolean', 'activo' => 'boolean'];
    }
}
