<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * tipos_intervencion (TENANT-CONFIG) — qué se hizo con el alumno.
 *
 * ── Las cuatro BANDERAS son lo que el formulario lee ───────────────────────
 * Nunca la clave. Una escuela que invente «Canalización a servicios de salud»
 * se comporta igual que las de fábrica, y ésa es la prueba de que esto es
 * catálogo y no un enum disfrazado. Es la lección de `modalidades_percepcion`,
 * donde «base más horas» se creó desde la pantalla y funcionó.
 *
 * ── `permite_reservada` no está encendida en todas, a propósito ────────────
 * Un «seguimiento de asistencia» reservado esconde de su propio equipo el dato
 * que el equipo necesita para trabajar, y a cambio no protege nada: ahí no hay
 * nada personal. La reserva es para lo que de verdad la pide —una orientación,
 * una canalización— y ofrecerla en todas la vuelve una casilla que se palomea
 * por costumbre.
 */
class TipoIntervencion extends Model
{
    use TieneAuditoria;

    protected $table = 'tipos_intervencion';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'exige_evidencia',
        'exige_acuerdos',
        'exige_proxima_fecha',
        'permite_reservada',
        'orden',
        'activo',
    ];

    protected $attributes = [
        'exige_evidencia' => false,
        'exige_acuerdos' => false,
        'exige_proxima_fecha' => false,
        'permite_reservada' => false,
        'activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'exige_evidencia' => 'boolean',
            'exige_acuerdos' => 'boolean',
            'exige_proxima_fecha' => 'boolean',
            'permite_reservada' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
