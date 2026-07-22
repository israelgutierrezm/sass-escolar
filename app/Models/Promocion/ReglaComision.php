<?php

declare(strict_types=1);

namespace App\Models\Promocion;

use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Finanzas\ConceptoPago;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * reglas_comision (TENANT) — cuánto gana el promotor por alumno inscrito.
 *
 * Precedencia oferta → carrera → global, el mismo patrón de `planes_cobro` y
 * `emisores_fiscales`: la escuela pone una regla general y la excepciona donde
 * hace falta ("10% en general, pero la maestría paga fijo").
 *
 * `concepto_id` importa cuando el modo es porcentaje: sin él, "10%" no dice de
 * qué. Es el concepto del adeudo sobre cuyo monto se calcula, típicamente la
 * inscripción.
 */
class ReglaComision extends Model
{
    use TieneAuditoria;

    public const APLICA_GLOBAL = 'global';

    public const APLICA_CARRERA = 'carrera';

    public const APLICA_OFERTA = 'oferta';

    public const MODO_MONTO_FIJO = 'monto_fijo';

    public const MODO_PORCENTAJE = 'porcentaje';

    protected $table = 'reglas_comision';

    protected $attributes = [
        'aplica_a_tipo' => self::APLICA_GLOBAL,
        'activo' => true,
    ];

    protected $fillable = [
        'nombre',
        'aplica_a_tipo',
        'aplica_a_id',
        'modo',
        'valor',
        'concepto_id',
        'vigente_desde',
        'vigente_hasta',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:4',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'activo' => 'boolean',
        ];
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoPago::class, 'concepto_id');
    }

    public function destinatario(): ?Model
    {
        if ($this->aplica_a_id === null) {
            return null;
        }

        return match ($this->aplica_a_tipo) {
            self::APLICA_CARRERA => Carrera::find($this->aplica_a_id),
            self::APLICA_OFERTA => Oferta::find($this->aplica_a_id),
            default => null,
        };
    }

    public function nombreDelDestinatario(): string
    {
        if ($this->aplica_a_tipo === self::APLICA_GLOBAL) {
            return 'Toda la escuela';
        }

        return $this->destinatario()?->nombre ?? 'No encontrado (#'.$this->aplica_a_id.')';
    }

    public function scopeVigentes(Builder $query, ?string $fecha = null): Builder
    {
        $fecha ??= now()->toDateString();

        return $query->where('activo', true)
            ->whereDate('vigente_desde', '<=', $fecha)
            ->where(fn (Builder $q) => $q
                ->whereNull('vigente_hasta')
                ->orWhereDate('vigente_hasta', '>=', $fecha));
    }
}
