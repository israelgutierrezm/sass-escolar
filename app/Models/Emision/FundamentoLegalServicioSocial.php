<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * fundamentos_legales_servicio_social (TENANT-CONFIG) — catálogo OFICIAL SEP del
 * fundamento legal del servicio social. `identificador` es el
 * idFundamentoLegalServicioSocial que se escribe en el título electrónico.
 */
class FundamentoLegalServicioSocial extends Model
{
    use TieneAuditoria;

    protected $table = 'fundamentos_legales_servicio_social';

    protected $fillable = ['identificador', 'descripcion', 'protegido', 'activo'];

    protected function casts(): array
    {
        return ['identificador' => 'integer', 'protegido' => 'boolean', 'activo' => 'boolean'];
    }
}
