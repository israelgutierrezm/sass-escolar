<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * tareas_caso (TENANT) — lo que hace que el SLA tenga a quién reclamarle.
 *
 * Sin ellas, «hay que hablar con la mamá» vive en la cabeza de alguien y el caso
 * se queda parado sin que nada lo señale.
 */
class TareaCaso extends Model
{
    use TieneAuditoria;

    protected $table = 'tareas_caso';

    protected $fillable = [
        'caso_id',
        'titulo',
        'responsable_id',
        'vence_en',
        'completada_en',
        'resultado',
    ];

    protected function casts(): array
    {
        return [
            'vence_en' => 'date',
            'completada_en' => 'datetime',
        ];
    }

    public function caso(): BelongsTo
    {
        return $this->belongsTo(CasoPermanencia::class, 'caso_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsable_id');
    }

    public function scopePendientes(Builder $c): Builder
    {
        return $c->whereNull('completada_en');
    }

    /**
     * Las vencidas: pendientes y con la fecha pasada.
     *
     * El día MISMO no está vencida —«vence el 30» se lee como que el 30 aún
     * cuenta—, que es el mismo criterio de las exclusiones de regla.
     */
    public function scopeVencidas(Builder $c, ?string $dia = null): Builder
    {
        return $c->pendientes()
            ->whereNotNull('vence_en')
            ->whereDate('vence_en', '<', $dia ?? now()->toDateString());
    }
}
