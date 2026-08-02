<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Enums\DestinoEvento;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\EventoCalendario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Qué eventos del calendario le tocan a alguien.
 *
 * ── El criterio ────────────────────────────────────────────────────────────
 * Un evento se ve si encaja en AL MENOS UNO de sus destinos. «Campus norte» +
 * «grupo A» significa los del campus norte y además el grupo A: los destinos se
 * suman. Cruzarlos —exigir cumplir todos— dejaría casi cualquier aviso sin
 * público, porque nadie es a la vez «todos los docentes» y «el grupo A».
 *
 * ── Por qué se filtra en SQL y no en PHP ───────────────────────────────────
 * Podría traerse todo el mes y descartar en memoria, y sería más fácil de leer.
 * Pero la agenda se pide en cada carga del panel de cada usuario: filtrar en la
 * base deja el trabajo en el índice `(tipo, destino_id)` en vez de recorrer
 * todos los eventos de la escuela por persona.
 */
class AgendaDeUsuario
{
    public function __construct(private readonly ContextoAcademico $contexto) {}

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
            ->where(fn (Builder $q) => $this->loAlcanza($q, $usuario))
            ->orderBy('inicia_en')
            ->get();
    }

    /**
     * La condición de «este evento es para mí».
     *
     * Se arma como un `whereHas` por cada criterio en el que la persona encaja.
     * Un usuario sin matrícula ni materias —un administrativo— sigue viendo lo
     * de «toda la escuela» y lo de su rol, que es justo lo que debe ver.
     */
    private function loAlcanza(Builder $query, Usuario $usuario): Builder
    {
        $mio = $this->contexto->de($usuario->persona_id);

        /*
         * TODOS sus roles, no sólo el activo.
         *
         * Quien es docente y además coordinador recibe lo de ambos aunque en
         * ese momento esté operando como uno de los dos: un aviso para docentes
         * no puede desaparecer porque conmutó de rol para revisar otra cosa.
         *
         * La verdad sobre qué es una persona vive en `persona_rol`, no en la
         * cuenta —el sistema no usa el trait de Spatie sobre `Usuario`—.
         */
        $roles = $usuario->rolesDisponibles()->pluck('id')->all();

        return $query->whereHas('destinos', function (Builder $d) use ($mio, $usuario, $roles) {
            // Toda la escuela: sin id que comparar.
            $d->where('tipo', DestinoEvento::Todos->value);

            $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Rol, $roles));
            $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Campus, $mio['campus']));
            $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Nivel, $mio['nivel']));
            $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Carrera, $mio['carrera']));
            $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Plan, $mio['plan']));
            $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Grupo, $mio['grupo']));
            $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Materia, $mio['materia']));

            // Señalado por nombre y apellido: va contra la persona, no contra
            // la cuenta, porque el aviso es para el alumno aunque cambie de
            // usuario.
            if ($usuario->persona_id !== null) {
                $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Alumno, [$usuario->persona_id]));
            }
        });
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function porCriterio(Builder $query, DestinoEvento $tipo, array $ids): Builder
    {
        // Sin ids, la condición no puede cumplirse. `whereIn` con arreglo vacío
        // ya devuelve falso, pero dejarlo explícito evita armar SQL inútil.
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('tipo', $tipo->value)->whereIn('destino_id', $ids);
    }
}
