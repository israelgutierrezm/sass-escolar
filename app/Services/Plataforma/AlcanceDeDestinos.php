<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Enums\DestinoEvento;
use App\Models\Identidad\TutorAlumno;
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
 * ── Y las FAMILIAS, que son la excepción a esa regla ───────────────────────
 * «Y a sus familias» no es un destino sino un MODIFICADOR: no señala a nadie
 * por sí solo, extiende a los tutores lo que los demás destinos ya dijeron. Por
 * eso su condición sí se cruza con las otras —hace falta el modificador Y que
 * algún hijo encaje—, al revés que todo lo demás.
 *
 * Sin esto, un citatorio dirigido a Juan le llegaba a Juan y no a su madre: el
 * destino «alumno» casa contra la persona de quien inició sesión.
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

        $hijos = $this->hijosDe($usuario);

        return $query->where(function (Builder $q) use ($mio, $roles, $usuario, $hijos) {
            $q->whereHas('destinos', fn (Builder $d) => $this->comoDestinatario($d, $mio, $roles, $usuario->persona_id));

            if ($hijos !== []) {
                $q->orWhere(fn (Builder $familiar) => $this->comoFamiliar($familiar, $hijos));
            }
        });
    }

    /** Lo que alcanza a la persona por sí misma. */
    private function comoDestinatario(Builder $d, array $mio, array $roles, ?int $personaId): Builder
    {
        // Toda la escuela: sin id que comparar.
        $d->where('tipo', DestinoEvento::Todos->value);

        $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Rol, $roles));
        $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Campus, $mio['campus']));
        $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Nivel, $mio['nivel']));
        $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::ProgramaAcademico, $mio['programa_academico']));
        $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Plan, $mio['plan']));
        $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Grupo, $mio['grupo']));
        $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Materia, $mio['materia']));

        // Señalado por nombre y apellido: va contra la persona, no contra la
        // cuenta, porque el aviso es para el alumno aunque cambie de usuario.
        if ($personaId !== null) {
            $d->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Alumno, [$personaId]));
        }

        return $d;
    }

    /**
     * Lo que le llega por ser familiar de alguien.
     *
     * Dos condiciones que se CRUZAN, y es lo único del servicio que se cruza:
     * el aviso tiene que llevar el modificador «y a sus familias» Y además
     * alguno de sus hijos tiene que encajar en los demás destinos. Con un OR,
     * cualquier aviso con el modificador le llegaría a todos los padres de la
     * escuela.
     *
     * NO se miran los roles de los hijos. «Rol: alumno» + familias sonaría a
     * «todas las familias», pero eso ya se dice mejor dirigiéndolo al rol de
     * padre de familia; mezclarlo haría que un aviso para docentes con el
     * modificador puesto le llegara a los padres de cualquier alumno.
     *
     * @param  array<int, int>  $hijos  personas de los alumnos a su cargo
     */
    private function comoFamiliar(Builder $query, array $hijos): Builder
    {
        $deEllos = $this->contextoDe($hijos);

        return $query
            ->whereHas('destinos', fn (Builder $d) => $d->where('tipo', DestinoEvento::Familiares->value))
            ->whereHas('destinos', fn (Builder $d) => $this->comoDestinatario($d, $deEllos, [], null)
                ->orWhere(fn (Builder $q) => $this->porCriterio($q, DestinoEvento::Alumno, $hijos)));
    }

    /**
     * A qué alumnos acompaña esta persona como tutor familiar.
     *
     * @return array<int, int>
     */
    private function hijosDe(Usuario $usuario): array
    {
        if ($usuario->persona_id === null) {
            return [];
        }

        return TutorAlumno::query()
            ->where('tutor_persona_id', $usuario->persona_id)
            ->pluck('alumno_persona_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * El contexto académico de VARIAS personas, unido.
     *
     * Un padre con dos hijos en programas académicos distintas alcanza lo de las dos: sus
     * contextos se suman igual que se suman los destinos de un aviso.
     *
     * @param  array<int, int>  $personas
     * @return array<string, int[]>
     */
    private function contextoDe(array $personas): array
    {
        $unido = ['campus' => [], 'nivel' => [], 'programa_academico' => [], 'plan' => [], 'grupo' => [], 'materia' => []];

        foreach ($personas as $persona) {
            foreach ($this->contexto->de($persona) as $clave => $ids) {
                $unido[$clave] = array_values(array_unique([...$unido[$clave] ?? [], ...$ids]));
            }
        }

        return $unido;
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
