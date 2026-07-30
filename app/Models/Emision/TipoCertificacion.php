<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * tipos_certificacion (TENANT) — tipo de Documento Electrónico de Certificación
 * de la SEP: 79 = Total, 80 = Parcial. El `identificador` es el valor oficial
 * que viaja en el XML (atributo idTipoCertificacion).
 */
class TipoCertificacion extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_certificacion';

    protected $fillable = ['clave', 'identificador', 'nombre', 'protegido'];

    protected function casts(): array
    {
        return ['protegido' => 'boolean'];
    }
}
