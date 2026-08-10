<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Academico\Carrera;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * cuentas_bancarias (TENANT) — dónde recibe la escuela una transferencia
 * directa, sin pasarela de por medio.
 */
class CuentaBancaria extends Model
{
    use TieneAuditoria;

    protected $table = 'cuentas_bancarias';

    protected $fillable = [
        'nombre',
        'banco',
        'titular',
        'clabe',
        'numero_cuenta',
        'instrucciones',
        'activa',
    ];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    /**
     * Para qué carreras vale. VACÍO significa «todas».
     *
     * Es el caso simple y el más común —una escuela, una cuenta—, así que se
     * resuelve no diciendo nada en vez de obligando a marcar la lista entera.
     */
    public function carreras(): BelongsToMany
    {
        return $this->belongsToMany(Carrera::class, 'cuenta_bancaria_carrera');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    /**
     * Las cuentas que puede usar quien estudia esta carrera.
     *
     * @return Collection<int, self>
     */
    public static function paraCarrera(?int $carreraId)
    {
        return static::query()
            ->activas()
            ->with('carreras:id')
            ->get()
            ->filter(fn (self $c) => $c->aplicaA($carreraId))
            ->values();
    }

    /** ¿Sirve para esta carrera? Sin carreras asignadas, para todas. */
    public function aplicaA(?int $carreraId): bool
    {
        if ($this->carreras->isEmpty()) {
            return true;
        }

        return $carreraId !== null && $this->carreras->contains('id', $carreraId);
    }

    /**
     * ¿Tiene con qué recibir un depósito?
     *
     * Sin CLABE ni número de cuenta, lo que se le enseñaría a quien va a pagar
     * es un banco y un nombre: no se puede transferir a eso.
     */
    public function puedeRecibir(): bool
    {
        return filled($this->clabe) || filled($this->numero_cuenta);
    }
}
