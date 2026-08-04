<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Models\Identidad\Usuario;
use App\Models\Plataforma\EventoCalendario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Qué eventos del calendario le tocan a alguien.
 *
 * A quién alcanza cada evento lo resuelve `AlcanceDeDestinos`, que es el mismo
 * criterio que usan los avisos: dirigir algo «al grupo A» tiene que significar
 * lo mismo en las dos pantallas.
 */
class AgendaDeUsuario
{
    public function __construct(private readonly AlcanceDeDestinos $alcance) {}

    /**
     * Los eventos publicados que alcanzan a este usuario dentro del rango.
     *
     * @return Collection<int, EventoCalendario>
     */
    public function entre(Usuario $usuario, string $desde, string $hasta): Collection
    {
        return EventoCalendario::query()
            ->publicados()
            ->enRango($desde, $hasta)
            ->where(fn (Builder $q) => $this->alcance->aplicar($q, $usuario))
            ->orderBy('inicia_en')
            ->get();
    }
}
