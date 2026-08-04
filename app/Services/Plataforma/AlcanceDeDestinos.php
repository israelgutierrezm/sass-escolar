<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Enums\DestinoEvento;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;

/**
 * La condición de «esto va dirigido a mí».
 *
 * La comparten los eventos del calendario y los avisos: los dos guardan sus
 * destinatarios igual —una tabla hija con `tipo` y `destino_id`— y los dos
 * tienen que resolver la misma pregunta contra la misma persona. Cuando esto
 * vivía dentro de la agenda, dirigir un aviso «al grupo A» y dirigir un evento
 * «al grupo A» eran dos códigos distintos que podían dejar de coincidir.
 *
 * ── El criterio ────────────────────────────────────────────────────────────
 * Alcanza si encaja en AL MENOS UNO de sus destinos. «Campus norte» + «grupo A»
 * significa los del campus norte y además el grupo A: los destinos se suman.
 * Cruzarlos —exigir cumplir todos— dejaría casi cualquier aviso sin público,
 * porque nadie es a la vez «todos los docentes» y «el grupo A».
 *
 * ── Por qué se filtra en SQL y no en PHP ───────────────────────────────────
 * Podría traerse todo y descartar en memoria, y sería más fácil de leer. Pero
 * esto se pide en cada carga de página de cada usuario: filtrar en la base deja
 * el trabajo en el índice `(tipo, destino_id)` en vez de recorrer todo lo
 * publicado de la escuela, por persona.
 */
class AlcanceDeDestinos
{
    public function __construct(private readonly ContextoAcademico $contexto) {}

    /**
     * Acota la consulta a lo que alcanza a este usuario.
     *
     * Sirve para cualquier modelo con una relación `destinos` que guarde `tipo`
     * y `destino_id`. Un usuario sin matrícula ni materias —un administrativo—
     * sigue recibiendo lo de «toda la escuela» y lo de su rol, que es justo lo
     * que debe recibir.
     */
    public function aplicar(Builder $query, Usuario $usuario): Builder
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
