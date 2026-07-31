<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Acota lo que un controlador muestra y acepta al alcance de campus del rol.
 *
 * `persona_rol.campus_id` existe desde el slice de auth para poder decir
 * "coordinador del Campus Norte", pero cada pantalla tenía que acordarse de
 * usarlo, y varias no lo hacían: la cartera, el buscador de alumnos de becas y
 * el listado de facturas mostraban la escuela entera. Aquí vive la regla una
 * sola vez.
 *
 * Dos ideas que conviene no perder:
 *
 *  - `campusVisibles()` devuelve **null** cuando el alcance es global. Null no
 *    es "ninguno", es "todos": por eso todos los métodos salen temprano sin
 *    tocar la consulta.
 *  - Filtrar la lista NO basta. Si solo se acota lo que se ve, cambiar un id en
 *    la URL o mandar un POST a mano se salta el candado. Por eso hay un método
 *    para la consulta y otro para autorizar el registro concreto.
 */
trait AcotaPorCampus
{
    /** Campus que el usuario administra; null = toda la escuela. */
    protected function alcanceCampus(Request $request): ?array
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();

        return $usuario?->campusVisibles();
    }

    /**
     * Acota una consulta de `matricula_oferta` (o que la tenga como relación)
     * a los campus del usuario.
     *
     * @param  string|null  $relacion  Nombre de la relación hacia la matrícula;
     *                                 null si la consulta ya es de matrículas.
     */
    protected function acotarMatriculas(Builder $consulta, Request $request, ?string $relacion = null): Builder
    {
        $campus = $this->alcanceCampus($request);

        if ($campus === null) {
            return $consulta;
        }

        $porOferta = fn (Builder $q) => $q->whereHas('oferta', fn (Builder $o) => $o->whereIn('campus_id', $campus));

        return $relacion === null
            ? $consulta->where(fn (Builder $q) => $porOferta($q))
            : $consulta->whereHas($relacion, fn (Builder $q) => $porOferta($q));
    }

    /**
     * Corta el acceso a una matrícula fuera de alcance. Se usa en las pantallas
     * de detalle, donde el id viaja en la URL.
     */
    protected function autorizarMatricula(Request $request, MatriculaOferta $matricula): void
    {
        $campus = $this->alcanceCampus($request);

        if ($campus === null) {
            return;
        }

        abort_unless(
            in_array($matricula->oferta?->campus_id, $campus, true),
            403,
            'Ese alumno no pertenece a tus campus.',
        );
    }

    /**
     * Valida ids de campus que llegan del cliente. No se ignoran en silencio:
     * un id ajeno suele ser el síntoma de una pantalla abierta con un rol que
     * ya cambió, y callarlo deja al usuario sin entender por qué no se guardó.
     *
     * @param  array<int, int|string>  $enviados
     */
    protected function exigirCampusPropios(Request $request, array $enviados, string $campo = 'campus'): void
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        if ($usuario->campusVisibles() === null) {
            return;
        }

        $ajenos = array_values(array_filter(
            array_map('intval', $enviados),
            fn (int $id) => ! $usuario->alcanzaCampus($id),
        ));

        if ($ajenos !== []) {
            throw ValidationException::withMessages([
                $campo => 'Hay campus seleccionados que están fuera de tu alcance.',
            ]);
        }
    }
}
