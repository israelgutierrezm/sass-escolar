<?php

declare(strict_types=1);

namespace App\Credencial;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\CredencialRol;
use App\Models\Identidad\Usuario;
use App\Support\CatalogoPermisos;
use Illuminate\Support\Collection;

/**
 * Cuántas credenciales tiene una persona y con qué configuración se dibuja cada
 * una.
 *
 * ── Una credencial POR MATRÍCULA ───────────────────────────────────────────
 * Quien estudia dos carreras tiene dos, no una. No es una interpretación: es la
 * decisión de arquitectura del proyecto —«el alumno es la MATRÍCULA, no la
 * persona»— y ya se aplica en el kárdex, que es independiente por inscripción.
 * En la escuela de ejemplo hay tres personas con dos matrículas activas a la
 * vez, así que el caso no es hipotético.
 *
 * Elegir «la más reciente» y emitir una sola parecía más simple, pero deja sin
 * credencial la carrera en la que esa persona también está inscrita —y es la
 * que va a enseñar el día que entre a ESE campus o a ESA clase—.
 *
 * Quien no es alumno tiene UNA: no hay matrícula que multiplique nada.
 */
class CredencialesDeLaPersona
{
    /**
     * Las credenciales emitibles de esta persona, con su configuración ya
     * resuelta. Vacío si su rol no emite.
     *
     * @return Collection<int, array{clave: string, matricula: ?MatriculaOferta, etiqueta: string, config: CredencialRol}>
     */
    public function para(Usuario $usuario): Collection
    {
        if ($usuario->rol_activo_id === null || $usuario->persona_id === null) {
            return collect();
        }

        $matriculas = $this->esAlumno($usuario)
            ? $this->matriculasDe($usuario)
            : collect();

        // Sin matrículas —o sin ser alumno— hay una sola credencial, la del rol
        // sin variante de nivel.
        if ($matriculas->isEmpty()) {
            $config = $this->configuracion($usuario->rol_activo_id, null);

            return $config?->emitible()
                ? collect([[
                    'clave' => 'rol',
                    'matricula' => null,
                    'etiqueta' => $usuario->rolActivo?->nombre ?? 'Credencial',
                    'config' => $config,
                ]])
                : collect();
        }

        return $matriculas
            ->map(function (MatriculaOferta $m) use ($usuario) {
                $config = $this->configuracion($usuario->rol_activo_id, $this->nivelDe($m));

                return $config?->emitible() ? [
                    'clave' => 'matricula-'.$m->id,
                    'matricula' => $m,
                    'etiqueta' => $m->oferta?->carrera?->nombre ?? $m->matricula,
                    'config' => $config,
                ] : null;
            })
            ->filter()
            ->values();
    }

    /**
     * La configuración que le toca: la del nivel si existe, y si no la general
     * del rol.
     *
     * Ese respaldo es lo que hace que la variante por nivel sea opcional. Una
     * escuela que no distinga niveles configura una sola credencial y todos sus
     * alumnos la reciben; la que sí distinga agrega la variante del doctorado y
     * el resto sigue cayendo a la general.
     */
    public function configuracion(int $rolId, ?int $nivelId): ?CredencialRol
    {
        $porNivel = $nivelId === null ? null : CredencialRol::query()
            ->where('rol_id', $rolId)
            ->where('nivel_estudios_id', $nivelId)
            ->first();

        return $porNivel ?? CredencialRol::query()
            ->where('rol_id', $rolId)
            ->whereNull('nivel_estudios_id')
            ->first();
    }

    /**
     * Si el rol activo es de la faceta ALUMNO.
     *
     * La variante por nivel y la credencial por matrícula sólo aplican ahí:
     * docentes, padres, tutores y administrativos no cursan nada. Se pregunta
     * por la FACETA y no por el nombre del rol para que un rol funcional que
     * cuelgue de alumno —«alumno de posgrado», si la escuela lo crea— herede el
     * mismo trato sin tocar código.
     */
    private function esAlumno(Usuario $usuario): bool
    {
        return $usuario->rolActivo?->ambitoDePermisos() === CatalogoPermisos::ALUMNO;
    }

    /** @return Collection<int, MatriculaOferta> */
    private function matriculasDe(Usuario $usuario): Collection
    {
        return MatriculaOferta::query()
            ->with('oferta.carrera:id,nombre,nivel_estudios_id', 'oferta.campus:id,nombre')
            ->where('persona_id', $usuario->persona_id)
            ->orderBy('matricula')
            ->get();
    }

    /**
     * El nivel de estudios de esa inscripción.
     *
     * Sale de la carrera, y es el del catálogo del TENANT: hay dos clases
     * `NivelEstudio` y consultar la del landlord devuelve otro nivel con el
     * mismo id, sin fallar. Aquí sólo se usa el id, así que no hay riesgo, pero
     * conviene que quede dicho para quien vaya a mostrar su nombre.
     */
    private function nivelDe(MatriculaOferta $matricula): ?int
    {
        return $matricula->oferta?->carrera?->nivel_estudios_id;
    }
}
