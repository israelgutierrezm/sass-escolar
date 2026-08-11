<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * tipos_certificacion (TENANT) — tipo de Documento Electrónico de Certificación
 * de la SEP: 79 = Total, 80 = Parcial.
 *
 * ── El valor oficial vive en la CLAVE, no en el id ────────────────────────
 * Aquí el id es un autoincremento normal —1 y 2— y la clave guarda el número
 * que espera la SEP. Es el único de los catálogos que alimentan el DEC donde
 * los dos NO coinciden, y por eso el XML tiene que leer la clave: mandar el id
 * sería mandar «1» donde la SEP espera «79».
 *
 * Se administra desde Académico → Catálogos, con el resto de catálogos de
 * clave + nombre + identificador.
 */
class TipoCertificacion extends Model
{
    use TieneAuditoria;

    /** Los dos valores que fija el DEC. Ver `claveOficial()`. */
    public const TOTAL = '79';

    public const PARCIAL = '80';

    protected $table = 'tipos_certificacion';

    protected $fillable = ['clave', 'identificador', 'nombre', 'protegido'];

    protected function casts(): array
    {
        return ['protegido' => 'boolean'];
    }

    /**
     * La clave del tipo que corresponde a este certificado.
     *
     * ── Por qué el 79 y el 80 siguen escritos aquí ────────────────────────
     * Porque los fija el DEC, no la escuela: un certificado es total o parcial
     * y no hay una tercera opción que alguien pueda configurar. Lo que SÍ
     * cambia es lo que la escuela tenga guardado en esa fila, y por eso se
     * consulta: si la clave se corrigió en el catálogo, el XML sigue a la
     * tabla y no a esta constante.
     *
     * El respaldo existe para el caso de que la fila se haya borrado: un
     * certificado sin `idTipoCertificacion` lo rechaza el web service entero,
     * y es peor que mandar el valor oficial de siempre.
     */
    public static function claveOficial(bool $parcial): string
    {
        $oficial = $parcial ? self::PARCIAL : self::TOTAL;

        $clave = self::query()->where('clave', $oficial)->value('clave');

        return (string) ($clave ?? $oficial);
    }
}
