<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * El documento que dice que alguien terminó su proceso formativo.
 *
 * ── Es INMUTABLE: corregir emite otra ─────────────────────────────────────
 * Su folio circula en un papel firmado, así que sobrescribirla borraría lo que
 * se entregó. La corrección es otra fila que apunta a ésta, y las dos se
 * conservan: la vieja queda marcada con `corregida_en` y deja de valer. Es el
 * molde del acta de corrección y de la nota de crédito.
 *
 * ── Y todo lo que dice sale del SNAPSHOT ──────────────────────────────────
 * No de las relaciones vivas. Una constancia emitida hace dos años se
 * reconstruiría con los datos de hoy —la organización pudo cambiar de razón
 * social, la regla de horas exigidas— y diría cosas que nadie firmó.
 */
class LiberacionProceso extends Model
{
    use SoftDeletes, TieneAuditoria;

    protected $table = 'liberaciones_proceso';

    /**
     * NADA es asignable en masa, a propósito.
     *
     * Todo lo escribe {@see LiberadorDeExpediente} con `forceFill`: el folio
     * sale de un contador atómico, el snapshot se arma leyendo el expediente y
     * las horas se copian de la bitácora. Por un formulario, cualquiera se
     * emitiría una constancia con el folio y las horas que quisiera.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'liberado_en' => 'date',
            'corregida_en' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteProceso::class, 'expediente_id');
    }

    public function liberadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'liberado_por');
    }

    /** La que ésta corrige. Null si es la primera. */
    public function corrige(): BelongsTo
    {
        return $this->belongsTo(self::class, 'liberacion_corregida_id');
    }

    /** La que corrigió a ésta. */
    public function correccion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id', 'liberacion_corregida_id');
    }

    /** Las que todavía valen. */
    public function scopeVigentes(Builder $q): Builder
    {
        return $q->whereNull('corregida_en');
    }

    public function estaVigente(): bool
    {
        return $this->corregida_en === null;
    }

    public function esCorreccion(): bool
    {
        return $this->liberacion_corregida_id !== null;
    }

    /**
     * Un dato del snapshot, por su ruta con puntos.
     *
     * Se lee así y no desde las relaciones: lo congelado es lo que el documento
     * dice, y las relaciones dicen lo de hoy.
     */
    public function delSnapshot(string $ruta, mixed $porOmision = null): mixed
    {
        return data_get($this->snapshot, $ruta, $porOmision);
    }
}
