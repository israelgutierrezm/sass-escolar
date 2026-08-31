<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * tipos_movimiento_escolar (TENANT-CONFIG) — qué clases de movimiento reconoce
 * la escuela.
 *
 * Las banderas dicen QUÉ PIDE el formulario, y por eso agregar un tipo desde la
 * pantalla produce un formulario correcto sin tocar código. Preguntar por la
 * clave —`if ($tipo->clave === 'cambio_grupo')`— funcionaría hoy y dejaría de
 * funcionar en silencio el día que la escuela agregue el suyo.
 */
class TipoMovimientoEscolar extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_movimiento_escolar';

    protected $fillable = [
        'clave', 'nombre', 'descripcion', 'color',
        'pide_ciclo', 'pide_grupos', 'pide_situacion', 'pide_oferta',
        'pide_periodo', 'pide_motivo', 'solo_automatico', 'orden', 'activo',
    ];

    /** Claves que el CÓDIGO emite. Las demás las inventa la escuela. */
    public const ALTA = 'alta';

    public const BAJA_TEMPORAL = 'baja_temporal';

    public const BAJA_DEFINITIVA = 'baja_definitiva';

    public const REINGRESO = 'reingreso';

    public const EGRESO = 'egreso';

    public const TITULACION = 'titulacion';

    public const CORRECCION = 'correccion';

    protected function casts(): array
    {
        return [
            'pide_ciclo' => 'boolean',
            'pide_grupos' => 'boolean',
            'pide_situacion' => 'boolean',
            'pide_oferta' => 'boolean',
            'pide_periodo' => 'boolean',
            'pide_motivo' => 'boolean',
            'solo_automatico' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoEscolar::class, 'tipo_id');
    }

    /** Los que la escuela tiene encendidos, en su orden. */
    public function scopeActivos(Builder $q): Builder
    {
        return $q->where('activo', true)->orderBy('orden');
    }

    /**
     * Los que se pueden capturar A MANO.
     *
     * «Alta» la emite la conversión del aspirante: ofrecerla en el formulario
     * dejaría registrar dos altas de la misma matrícula, y la trayectoria
     * empezaría dos veces.
     */
    public function scopeCapturables(Builder $q): Builder
    {
        return $q->activos()->where('solo_automatico', false);
    }

    /** Qué campos pide este tipo, para que la pantalla dibuje sólo esos. */
    public function campos(): array
    {
        return [
            'ciclo' => $this->pide_ciclo,
            'grupos' => $this->pide_grupos,
            'situacion' => $this->pide_situacion,
            'oferta' => $this->pide_oferta,
            'periodo' => $this->pide_periodo,
            'motivo' => $this->pide_motivo,
        ];
    }
}
