<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * recibos_nomina (TENANT) — lo que se le paga a UNA persona en UN periodo.
 *
 * ── Sus renglones están MATERIALIZADOS ────────────────────────────────────
 * Guardan el importe que se calculó, no una referencia al sueldo vigente. Un
 * documento que se recalcula al mirarlo cambia de contenido cuando alguien
 * actualiza un dato de hoy, y un recibo es un hecho fechado que hay que poder
 * explicar dentro de cinco años. Misma decisión que `esquema_evaluacion`, que la
 * factura con su emisor y que el acta impresa.
 *
 * Por eso además apunta al esquema con el que se calculó: sin ese dato, explicar
 * de dónde salió un número obliga a reconstruir qué sueldo regía entonces.
 */
class ReciboNomina extends Model
{
    use TieneAuditoria;

    protected $table = 'recibos_nomina';

    protected $fillable = [
        'periodo_nomina_id',
        'expediente_laboral_id',
        'esquema_percepcion_id',
        'total_percepciones',
        'total_deducciones',
        'neto',
        'incidencias',
        'uuid',
        'xml_ruta',
        'pac',
        'timbrado_en',
        'error_timbrado',
    ];

    protected function casts(): array
    {
        return [
            'total_percepciones' => 'decimal:2',
            'total_deducciones' => 'decimal:2',
            'neto' => 'decimal:2',
            'timbrado_en' => 'datetime',
        ];
    }

    /** ¿Ya tiene folio fiscal? */
    public function estaTimbrado(): bool
    {
        return $this->uuid !== null;
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoNomina::class, 'periodo_nomina_id');
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteLaboral::class, 'expediente_laboral_id');
    }

    public function esquema(): BelongsTo
    {
        return $this->belongsTo(EsquemaPercepcion::class, 'esquema_percepcion_id');
    }

    public function conceptos(): HasMany
    {
        return $this->hasMany(ReciboConcepto::class, 'recibo_nomina_id');
    }

    /**
     * Rehace los totales a partir de sus renglones.
     *
     * Vive aquí y no en la calculadora porque también lo llama el ajuste manual:
     * escrito dos veces, un día agregar un descuento a mano dejaría el neto sin
     * actualizar y el recibo diría una cosa en los renglones y otra en el total.
     */
    public function recalcularTotales(): self
    {
        $renglones = $this->conceptos()->with('concepto')->get();

        $percepciones = $renglones->filter(fn (ReciboConcepto $r) => (bool) $r->concepto?->suma())
            ->sum(fn (ReciboConcepto $r) => (float) $r->importe);

        $deducciones = $renglones->reject(fn (ReciboConcepto $r) => (bool) $r->concepto?->suma())
            ->sum(fn (ReciboConcepto $r) => (float) $r->importe);

        $this->update([
            'total_percepciones' => round($percepciones, 2),
            'total_deducciones' => round($deducciones, 2),
            'neto' => round($percepciones - $deducciones, 2),
        ]);

        return $this->refresh();
    }
}
