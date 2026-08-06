<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Exceptions\AvisoParaElUsuario;

use App\Models\ControlEscolar\Inscripcion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * El alcance del alumno sale de su INSCRIPCIÓN, no de un permiso.
 *
 * `ver-mis-cursos` deja entrar al portal; en cuáles materias entra lo dicen las
 * inscripciones de sus matrículas. Cambiar el id en la URL para asomarse a la
 * materia de otro choca contra esta consulta.
 *
 * Está en un trait y no copiado en cada controlador por lo mismo que
 * {@see AutorizaMateriaPropia}: una regla de acceso repetida termina corregida
 * en tres lugares y olvidada en el cuarto, y el olvidado es el que deja entrar.
 */
trait AlcanceDelAlumno
{
    /** @return Builder<Inscripcion> */
    protected function misInscripciones(Request $request): Builder
    {
        $matriculas = $request->user()->persona?->matriculas()->pluck('matricula_oferta.id') ?? collect();

        return Inscripcion::query()->whereIn('matricula_oferta_id', $matriculas);
    }

    /**
     * Su inscripción en esta materia, o 403.
     *
     * No existe y no es suya devuelven lo mismo a propósito: distinguirlas le
     * diría a quien prueba ids ajenos cuáles sí existen.
     */
    protected function miInscripcionEn(Request $request, int $asignaturaGrupo, array $con = []): Inscripcion
    {
        $inscripcion = $this->misInscripciones($request)
            ->where('asignatura_grupo_id', $asignaturaGrupo)
            ->with($con)
            ->first();

        AvisoParaElUsuario::si($inscripcion === null, 403, 'Esa materia no está entre las que cursas.');

        return $inscripcion;
    }
}
