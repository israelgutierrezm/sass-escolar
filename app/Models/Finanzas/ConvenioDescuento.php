<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * convenios_descuento (TENANT) — el acuerdo con una empresa, un sindicato o una
 * dependencia.
 *
 * ── OJO: no confundir con `ConvenioPago` ───────────────────────────────────
 * Se llaman parecido y son cosas opuestas. Un CONVENIO DE PAGO reprograma la
 * deuda de UN alumno que no puede pagar de golpe. Un CONVENIO DE DESCUENTO es
 * un acuerdo con un TERCERO por el que un grupo de familias paga menos. Uno
 * mueve fechas, el otro mueve importes.
 *
 * ── Lo que agrega sobre una beca ───────────────────────────────────────────
 * Sus TÉRMINOS son una beca —porcentaje o monto, sobre ciertos conceptos,
 * otorgada por matrícula— porque ese motor ya existe y duplicarlo divergiría.
 * Lo que la beca no sabe decir es quién es la contraparte, hasta cuándo vale el
 * acuerdo, dónde está el papel firmado, y que **al terminar se acaban todas sus
 * becas a la vez**.
 */
class ConvenioDescuento extends Model
{
    use TieneAuditoria;

    public const VIGENTE = 'vigente';

    /** Se acabó: por su fecha o porque alguien lo dio por terminado. */
    public const TERMINADO = 'terminado';

    protected $table = 'convenios_descuento';

    protected $attributes = ['estatus' => self::VIGENTE];

    protected $fillable = [
        'nombre',
        'contraparte',
        'rfc',
        'contacto',
        'correo',
        'telefono',
        'vigente_desde',
        'vigente_hasta',
        'documento_ruta',
        'documento_nombre',
        'notas',
        'estatus',
        'terminado_en',
        'motivo_termino',
    ];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'terminado_en' => 'datetime',
        ];
    }

    /** Las becas que llevan los términos de este convenio. */
    public function becas(): HasMany
    {
        return $this->hasMany(Beca::class, 'convenio_descuento_id');
    }

    public function estaVigente(): bool
    {
        return $this->estatus === self::VIGENTE;
    }

    /**
     * ¿Se le pasó la fecha?
     *
     * Es OTRA pregunta que `estaVigente()`: un convenio puede estar vencido y
     * seguir con estatus vigente hasta que el barrido nocturno lo cierre — y
     * confundirlas dejaría descuentos corriendo después de que el acuerdo
     * terminó. Misma distinción que en los convenios de la bolsa de trabajo.
     */
    public function estaVencido(?string $hoy = null): bool
    {
        return $this->vigente_hasta !== null
            && $this->vigente_hasta->toDateString() < ($hoy ?? now()->toDateString());
    }

    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta->where('estatus', self::VIGENTE);
    }

    /** Vigentes de estatus pero con la fecha pasada: lo que el barrido cierra. */
    public function scopePorVencer(Builder $consulta, ?string $hoy = null): Builder
    {
        return $consulta
            ->vigentes()
            ->whereDate('vigente_hasta', '<', $hoy ?? now()->toDateString());
    }
}
