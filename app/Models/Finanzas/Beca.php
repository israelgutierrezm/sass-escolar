<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * becas (TENANT) — la definición de una beca y las reglas para conservarla.
 *
 * Una beca no es un descuento comercial: se le otorga a UN alumno, y se conserva
 * o se pierde según su conducta de pago y su desempeño. Por eso las reglas viven
 * aquí como datos —cada escuela pone sus condiciones— en vez de estar cableadas.
 *
 * El promedio se evalúa contra el CICLO ANTERIOR (decisión del proyecto): es lo
 * que hace posible revisar en cada renovación si el alumno sigue siendo
 * candidato.
 */
class Beca extends Model
{
    use TieneAuditoria;

    public const MODO_PORCENTAJE = 'porcentaje';

    public const MODO_MONTO_FIJO = 'monto_fijo';

    /** El atraso no afecta la beca. */
    public const ATRASO_NINGUNO = 'ninguno';

    /** Ese cargo se cobra completo; los siguientes vuelven con descuento. */
    public const ATRASO_SUSPENDE_PERIODO = 'suspende_periodo';

    /** Pierde la beca definitivamente. */
    public const ATRASO_PIERDE = 'pierde_beca';

    public const PROMEDIO_NINGUNO = 'ninguno';

    public const PROMEDIO_NO_RENUEVA = 'no_renueva';

    public const PROMEDIO_PIERDE = 'pierde_beca';

    protected $table = 'becas';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        // De qué bolsa sale. Ver `Patrocinador`: dice quién la financia, no a
        // quién se le factura.
        'patrocinador_id',
        'modo',
        'valor',
        'tope_monto',
        'por_ciclo',
        'requiere_renovacion',
        'requiere_pago_puntual',
        'dias_tolerancia',
        'efecto_atraso',
        'promedio_minimo',
        'efecto_promedio',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:4',
            'tope_monto' => 'decimal:2',
            'promedio_minimo' => 'decimal:2',
            'por_ciclo' => 'boolean',
            'requiere_renovacion' => 'boolean',
            'requiere_pago_puntual' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /** Conceptos a los que aplica. SIN filas = aplica a todos. */
    public function conceptos(): BelongsToMany
    {
        return $this->belongsToMany(ConceptoPago::class, 'beca_concepto', 'beca_id', 'concepto_id');
    }

    public function patrocinador(): BelongsTo
    {
        return $this->belongsTo(Patrocinador::class, 'patrocinador_id');
    }

    public function otorgadas(): HasMany
    {
        return $this->hasMany(BecaAlumno::class, 'beca_id');
    }

    /** ¿Esta beca cubre este concepto de pago? Sin restricción, cubre todos. */
    public function cubreConcepto(int $conceptoId): bool
    {
        $ids = $this->relationLoaded('conceptos')
            ? $this->conceptos->pluck('id')->all()
            : $this->conceptos()->pluck('conceptos_pago.id')->all();

        return $ids === [] || in_array($conceptoId, $ids, true);
    }

    /** Descuento que aplica sobre `$base`, acotado por el tope y por la base. */
    public function descuentoSobre(float $base): float
    {
        $bruto = $this->modo === self::MODO_PORCENTAJE
            ? $base * (float) $this->valor
            : (float) $this->valor;

        if ($this->tope_monto !== null) {
            $bruto = min($bruto, (float) $this->tope_monto);
        }

        // Una beca nunca puede dejar el cargo en negativo.
        return round(min($bruto, $base), 2);
    }
}
