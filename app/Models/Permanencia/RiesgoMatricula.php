<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * riesgo_matricula (TENANT) — el nivel compuesto, fechado.
 *
 * ── Es una fila, no una columna de la matrícula ────────────────────────────
 * Una columna en `matricula_oferta` sería un atributo de la persona que se
 * arrastra para siempre y que cualquier pantalla lee sin contexto. Aquí es un
 * hecho con fecha, con su desglose y con la puerta abierta a que mañana valga
 * otra cosa. **El alumno no queda etiquetado**: es una de las diez reglas del
 * pedido y ésta es la forma de cumplirla.
 *
 * ── Append-only, y sólo cuando algo CAMBIA ─────────────────────────────────
 * Un renglón por matrícula por corrida serían 1.8 millones de filas al año para
 * decir «sigue igual». Se escribe cuando el nivel o el puntaje se mueven, y así
 * la tabla es la historia de los cambios — que es lo que alguien va a querer
 * leer dentro de un año.
 *
 * ── Sin `TieneAuditoria`, a propósito ──────────────────────────────────────
 * Lo escribe una máquina y no se edita nunca: `updated_by` no cambiaría jamás.
 * Quien AJUSTÓ va en `ajustado_por`, que es el dato que significa algo.
 */
class RiesgoMatricula extends Model
{
    protected $table = 'riesgo_matricula';

    protected $fillable = [
        'matricula_oferta_id',
        'calculado_en',
        'nivel_id',
        'puntaje',
        'desglose',
        'nivel_anterior_id',
        'puntaje_anterior',
        'nivel_ajustado_id',
        'ajuste_motivo',
        'ajustado_por',
        'ajustado_en',
        'corrida_id',
    ];

    protected function casts(): array
    {
        return [
            'calculado_en' => 'datetime',
            'ajustado_en' => 'datetime',
            'desglose' => 'array',
            'puntaje' => 'integer',
            'puntaje_anterior' => 'integer',
        ];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function nivel(): BelongsTo
    {
        return $this->belongsTo(NivelRiesgo::class, 'nivel_id');
    }

    public function nivelAnterior(): BelongsTo
    {
        return $this->belongsTo(NivelRiesgo::class, 'nivel_anterior_id');
    }

    public function nivelAjustado(): BelongsTo
    {
        return $this->belongsTo(NivelRiesgo::class, 'nivel_ajustado_id');
    }

    public function ajustadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'ajustado_por');
    }

    /** El VIGENTE de una matrícula: el más reciente. */
    public function scopeVigenteDe(Builder $c, int $matriculaId): Builder
    {
        return $c->where('matricula_oferta_id', $matriculaId)
            ->orderByDesc('calculado_en')
            ->orderByDesc('id');
    }

    public function fueAjustado(): bool
    {
        return $this->nivel_ajustado_id !== null;
    }

    /**
     * El nivel que MANDA: el ajustado si lo hay, si no el calculado.
     *
     * Una sola definición, porque la preguntan la bandeja, la ficha y los
     * indicadores. Escrita tres veces, el día que una olvide el ajuste habría
     * dos respuestas a «qué nivel tiene esta persona» y ninguna forma de saber
     * cuál vale.
     */
    public function nivelQueManda(): ?NivelRiesgo
    {
        return $this->fueAjustado() ? $this->nivelAjustado : $this->nivel;
    }

    /**
     * Cómo se le enseña a quien lo consulta.
     *
     * ── Las dos cifras van juntas cuando hay ajuste ────────────────────────
     * Enseñar sólo la ajustada escondería que alguien la movió; enseñar sólo la
     * calculada contradiría la decisión de quien la movió. Van las dos, con
     * quién y por qué — que es lo único que hace legítimo un ajuste.
     *
     * @return array<string, mixed>
     */
    public function comoSeLee(): array
    {
        $manda = $this->nivelQueManda();

        return [
            'id' => $this->id,
            'nivel' => $manda?->only(['id', 'clave', 'nombre', 'color', 'pide_seguimiento']),
            'puntaje' => $this->puntaje,
            'calculado_en' => $this->calculado_en?->toDateTimeString(),
            'desglose' => $this->desglose,
            'anterior' => $this->nivel_anterior_id === null ? null : [
                'nivel' => $this->nivelAnterior?->only(['id', 'clave', 'nombre', 'color']),
                'puntaje' => $this->puntaje_anterior,
            ],
            'ajuste' => ! $this->fueAjustado() ? null : [
                // El CALCULADO se conserva y se enseña: sin él, un ajuste sería
                // indistinguible de un cálculo.
                'nivel_calculado' => $this->nivel?->only(['id', 'clave', 'nombre', 'color']),
                'motivo' => $this->ajuste_motivo,
                'quien' => $this->ajustadoPor?->persona?->nombreCompleto(),
                'cuando' => $this->ajustado_en?->toDateTimeString(),
            ],
        ];
    }
}
