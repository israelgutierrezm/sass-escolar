<?php

declare(strict_types=1);

namespace App\Models\Plataforma;

use App\Enums\TipoEventoCalendario;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * eventos_calendario (TENANT) — algo que pasa en una fecha y le importa a alguien.
 *
 * Un aviso, un feriado, el inicio del ciclo, una ceremonia. Quién lo ve lo
 * deciden sus {@see EventoDestino}; esta tabla sólo dice qué es y cuándo.
 */
class EventoCalendario extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    protected $table = 'eventos_calendario';

    protected $fillable = [
        'tipo',
        'titulo',
        'descripcion',
        'inicia_en',
        'termina_en',
        'todo_el_dia',
        'no_laborable',
        'publicado',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoEventoCalendario::class,
            'inicia_en' => 'datetime',
            'termina_en' => 'datetime',
            'todo_el_dia' => 'boolean',
            'no_laborable' => 'boolean',
            'publicado' => 'boolean',
        ];
    }

    public function destinos(): HasMany
    {
        return $this->hasMany(EventoDestino::class, 'evento_id');
    }

    /** Cuándo termina de verdad: los de un solo día acaban cuando empiezan. */
    public function finReal(): \Illuminate\Support\Carbon
    {
        return $this->termina_en ?? $this->inicia_en;
    }

    /**
     * Los que caen dentro de un rango.
     *
     * Un evento entra si SE CRUZA con el rango, no si empieza dentro: un receso
     * del 20 de diciembre al 6 de enero tiene que aparecer al mirar enero, y su
     * fecha de inicio quedó en otro mes —y en otro año—.
     */
    public function scopeEnRango(Builder $query, string $desde, string $hasta): Builder
    {
        return $query
            ->whereDate('inicia_en', '<=', $hasta)
            ->where(function (Builder $q) use ($desde) {
                $q->whereDate('termina_en', '>=', $desde)
                    ->orWhere(fn (Builder $s) => $s->whereNull('termina_en')->whereDate('inicia_en', '>=', $desde));
            });
    }

    public function scopePublicados(Builder $query): Builder
    {
        return $query->where('publicado', true);
    }
}
