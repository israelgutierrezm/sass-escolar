<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * conceptos_plan (TENANT) — una línea fechada del plan de cobro.
 *
 * Reemplaza al motor abstracto de "periodicidad + día del mes". Aquí cada cargo
 * se declara explícitamente: cuánto, de qué mes/año es y cuándo vence. Una
 * colegiatura se captura por RANGO en la UI y se expande en N líneas que
 * comparten `grupo_colegiatura`, de modo que semanal, mensual o cualquier otra
 * cadencia son el mismo mecanismo y no casos del código.
 */
class ConceptoPlan extends Model
{
    use TieneAuditoria;

    public const TIPO_INSCRIPCION = 'inscripcion';

    public const TIPO_COLEGIATURA = 'colegiatura';

    public const TIPO_CONCEPTO = 'concepto';

    /** @var array<string, string> */
    public const TIPOS = [
        self::TIPO_INSCRIPCION => 'Inscripción',
        self::TIPO_COLEGIATURA => 'Colegiatura',
        self::TIPO_CONCEPTO => 'Concepto',
    ];

    protected $table = 'conceptos_plan';

    protected $fillable = [
        'plan_cobro_id',
        'concepto_id',
        'tipo_pago',
        'descripcion',
        'monto',
        'mes_referencia',
        'anio_referencia',
        'fecha_limite',
        'aplica_recargos',
        'obligatorio',
        'grupo_colegiatura',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_limite' => 'date',
            'aplica_recargos' => 'boolean',
            'obligatorio' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanCobro::class, 'plan_cobro_id');
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoPago::class, 'concepto_id');
    }

    /** Override de recargo para esta línea (si lo tiene). */
    public function reglaRecargo(): BelongsTo
    {
        return $this->belongsTo(ReglaRecargo::class, 'id', 'concepto_plan_id');
    }

    public function adeudos(): HasMany
    {
        return $this->hasMany(Adeudo::class, 'concepto_plan_id');
    }

    /** "Marzo 2026" — la etiqueta legible del periodo que cubre el cargo. */
    public function periodoEtiqueta(): ?string
    {
        if ($this->mes_referencia === null || $this->anio_referencia === null) {
            return null;
        }

        $meses = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        return ($meses[$this->mes_referencia] ?? '?')." {$this->anio_referencia}";
    }
}
