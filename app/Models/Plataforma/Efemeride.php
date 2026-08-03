<?php

declare(strict_types=1);

namespace App\Models\Plataforma;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * efemerides (TENANT) — qué se conmemora en una fecha del año.
 *
 * Se guarda mes y día, no una fecha: se repite cada año. El `anio_origen` es
 * del hecho —1810, 1910— y sólo sirve para decir cuántos años se cumplen.
 */
class Efemeride extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    protected $table = 'efemerides';

    public const CIVICA = 'civica';

    public const INTERNACIONAL = 'internacional';

    public const ESCOLAR = 'escolar';

    protected $fillable = [
        'mes',
        'dia',
        'titulo',
        'descripcion',
        'tipo',
        'anio_origen',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'mes' => 'integer',
            'dia' => 'integer',
            'anio_origen' => 'integer',
            'activa' => 'boolean',
        ];
    }

    /** Cuántos años se cumplen hoy, si es un hecho fechado. */
    public function aniversario(?int $anio = null): ?int
    {
        if ($this->anio_origen === null) {
            return null;
        }

        return ($anio ?? (int) date('Y')) - $this->anio_origen;
    }

    public function scopeDelDia(Builder $query, int $mes, int $dia): Builder
    {
        return $query->where('activa', true)->where('mes', $mes)->where('dia', $dia);
    }

    /** El color con el que se pinta, por tipo. */
    public function color(): string
    {
        return match ($this->tipo) {
            self::CIVICA => '#16a34a',
            self::INTERNACIONAL => '#2563eb',
            default => '#7c3aed',
        };
    }
}
