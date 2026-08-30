<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Academico\ProgramaAcademico;
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
     * Para qué programas académicos vale. VACÍO significa «todas».
     *
     * Es el caso simple y el más común —una escuela, una cuenta—, así que se
     * resuelve no diciendo nada en vez de obligando a marcar la lista entera.
     */
    public function programasAcademicos(): BelongsToMany
    {
        return $this->belongsToMany(ProgramaAcademico::class, 'cuenta_bancaria_programa_academico');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    /**
     * Las cuentas que puede usar quien estudia este programa académico.
     *
     * @return Collection<int, self>
     */
    public static function paraProgramaAcademico(?int $programaAcademicoId)
    {
        return static::query()
            ->activas()
            ->with('programasAcademicos:id')
            ->get()
            ->filter(fn (self $c) => $c->aplicaA($programaAcademicoId))
            ->values();
    }

    /** ¿Sirve para este programa académico? Sin programas académicos asignados, para todas. */
    public function aplicaA(?int $programaAcademicoId): bool
    {
        if ($this->programasAcademicos->isEmpty()) {
            return true;
        }

        return $programaAcademicoId !== null && $this->programasAcademicos->contains('id', $programaAcademicoId);
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
