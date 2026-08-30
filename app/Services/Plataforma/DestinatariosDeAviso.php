<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Enums\DestinoEvento;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\PersonaRol;
use App\Models\Plataforma\Aviso;
use App\Models\Plataforma\AvisoDestino;

/**
 * A cuánta gente alcanza un aviso, y quién es.
 *
 * ── Para qué ───────────────────────────────────────────────────────────────
 * Sin esto, la pantalla de seguimiento sólo puede decir «lo confirmaron 12».
 * Doce de cuántos es la pregunta que importa: doce de catorce es un aviso que
 * llegó, doce de trescientos es un aviso que nadie leyó.
 *
 * ── Va al revés que `AlcanceDeDestinos` ────────────────────────────────────
 * Aquél contesta «¿este aviso es para mí?» y filtra avisos; éste contesta
 * «¿quiénes son ellos?» y devuelve personas. La misma pertenencia mirada desde
 * los dos lados, y por eso hay una prueba que las cruza: quien aparece aquí
 * tiene que recibir el aviso allá. Si un día divergen, esa prueba lo dice.
 *
 * ── Quién cuenta como destinatario ─────────────────────────────────────────
 * Sólo personas con algún rol ACTIVO. Un egresado sigue teniendo matrícula y
 * seguiría apareciendo en «los del plan 2020», pero ya no entra al sistema:
 * meterlo en el universo haría que ningún aviso llegara nunca al 100% y el
 * porcentaje dejaría de significar algo.
 */
class DestinatariosDeAviso
{
    /**
     * Los ids de persona a los que va dirigido.
     *
     * @return array<int, int>
     */
    public function de(Aviso $aviso): array
    {
        $aviso->loadMissing('destinos');

        $personas = [];

        foreach ($aviso->destinos as $destino) {
            foreach ($this->porDestino($destino) as $personaId) {
                $personas[$personaId] = true;
            }
        }

        // Se cruza con quien tiene rol activo: es el universo de quien puede
        // recibirlo de verdad.
        return array_values(array_intersect(array_keys($personas), $this->conRolActivo()));
    }

    /**
     * Cuántos son, por rol.
     *
     * Una persona con dos roles cuenta en los dos: la pregunta que responde
     * este desglose es «¿llegó a los docentes?», no «¿cómo se reparte el total?».
     *
     * @param  array<int, int>  $personas
     * @return array<int, array{rol: string, total: int}>
     */
    public function porRol(array $personas): array
    {
        if ($personas === []) {
            return [];
        }

        return PersonaRol::query()
            ->join('roles', 'roles.id', '=', 'persona_rol.rol_id')
            ->whereIn('persona_rol.persona_id', $personas)
            ->where('persona_rol.activo', true)
            ->groupBy('roles.id', 'roles.nombre', 'roles.name')
            ->orderByDesc('total')
            ->selectRaw('roles.id as rol_id, COALESCE(roles.nombre, roles.name) as rol, COUNT(DISTINCT persona_rol.persona_id) as total')
            ->get()
            ->map(fn ($fila) => [
                'rol_id' => (int) $fila->rol_id,
                'rol' => (string) $fila->rol,
                'total' => (int) $fila->total,
            ])
            ->all();
    }

    /**
     * Las personas de un destino concreto.
     *
     * @return array<int, int>
     */
    private function porDestino(AvisoDestino $destino): array
    {
        $id = $destino->destino_id;

        return match ($destino->tipo) {
            DestinoEvento::Todos => $this->conRolActivo(),
            DestinoEvento::Rol => $this->porRolActivo($id),
            DestinoEvento::Alumno => $id === null ? [] : [$id],
            DestinoEvento::Campus => $this->unir(
                $this->alumnosPor('oferta.campus_id', $id),
                $this->docentesPor('grupo.campus_id', $id),
                // El personal administrativo no tiene matrícula ni grupos: su
                // campus es el alcance de su rol. Sin esto, un aviso «al campus
                // norte» no llegaría a quien atiende su caja.
                $this->porCampusDeRol($id),
            ),
            DestinoEvento::Nivel => $this->unir(
                $this->alumnosPor('oferta.programaAcademico.nivel_estudios_id', $id),
                $this->docentesPor('grupo.plan.programaAcademico.nivel_estudios_id', $id),
            ),
            DestinoEvento::ProgramaAcademico => $this->unir(
                $this->alumnosPor('oferta.programa_academico_id', $id),
                $this->docentesPor('grupo.plan.programa_academico_id', $id),
            ),
            DestinoEvento::Plan => $this->unir(
                $this->alumnosPor('oferta.plan_id', $id),
                $this->docentesPor('grupo.plan_id', $id),
            ),
            DestinoEvento::Grupo => $this->unir(
                $this->alumnosDeMaterias(AsignaturaGrupo::where('grupo_id', $id)->pluck('id')->all()),
                $this->docentesPor('grupo_id', $id),
            ),
            DestinoEvento::Materia => $this->unir(
                $this->alumnosDeMaterias($id === null ? [] : [$id]),
                $this->docentesPor('id', $id),
            ),
        };
    }

    /** @return array<int, int> */
    private function conRolActivo(): array
    {
        return PersonaRol::query()
            ->where('activo', true)
            ->distinct()
            ->pluck('persona_id')
            ->all();
    }

    /** @return array<int, int> */
    private function porRolActivo(?int $rolId): array
    {
        if ($rolId === null) {
            return [];
        }

        return PersonaRol::query()
            ->where('rol_id', $rolId)
            ->where('activo', true)
            ->distinct()
            ->pluck('persona_id')
            ->all();
    }

    /** @return array<int, int> */
    private function porCampusDeRol(?int $campusId): array
    {
        if ($campusId === null) {
            return [];
        }

        return PersonaRol::query()
            ->where('campus_id', $campusId)
            ->where('activo', true)
            ->distinct()
            ->pluck('persona_id')
            ->all();
    }

    /**
     * Alumnos cuya oferta cumple la condición.
     *
     * La ruta viene con puntos («oferta.programa académico.nivel_estudios_id») y se
     * traduce a `whereHas` anidados: escribir los joins a mano repetiría los
     * nombres de tabla, que ya viven en los modelos.
     *
     * @return array<int, int>
     */
    private function alumnosPor(string $ruta, ?int $valor): array
    {
        if ($valor === null) {
            return [];
        }

        $partes = explode('.', $ruta);
        $columna = array_pop($partes);

        $consulta = MatriculaOferta::query();

        $this->anidar($consulta, $partes, $columna, $valor);

        return $consulta->distinct()->pluck('persona_id')->all();
    }

    /**
     * Docentes asignados a materias que cumplen la condición.
     *
     * @return array<int, int>
     */
    private function docentesPor(string $ruta, ?int $valor): array
    {
        if ($valor === null) {
            return [];
        }

        $partes = explode('.', $ruta);
        $columna = array_pop($partes);

        $consulta = AsignaturaGrupo::query();

        $this->anidar($consulta, $partes, $columna, $valor);

        return $consulta
            ->join('docente_asignatura_grupo', 'docente_asignatura_grupo.asignatura_grupo_id', '=', 'asignatura_grupo.id')
            ->distinct()
            ->pluck('docente_asignatura_grupo.persona_id')
            ->all();
    }

    /**
     * Alumnos inscritos en esas materias-grupo.
     *
     * @param  array<int, int>  $materias
     * @return array<int, int>
     */
    private function alumnosDeMaterias(array $materias): array
    {
        if ($materias === []) {
            return [];
        }

        return Inscripcion::query()
            ->whereIn('asignatura_grupo_id', $materias)
            ->join('matricula_oferta', 'matricula_oferta.id', '=', 'inscripcion.matricula_oferta_id')
            ->distinct()
            ->pluck('matricula_oferta.persona_id')
            ->all();
    }

    /**
     * Encadena `whereHas` por cada tramo de la ruta y aplica la condición al
     * final.
     *
     * @param  array<int, string>  $relaciones
     */
    private function anidar($consulta, array $relaciones, string $columna, int $valor): void
    {
        if ($relaciones === []) {
            $consulta->where($columna, $valor);

            return;
        }

        $primera = array_shift($relaciones);

        $consulta->whereHas($primera, fn ($q) => $this->anidar($q, $relaciones, $columna, $valor));
    }

    /**
     * @param  array<int, int>  ...$listas
     * @return array<int, int>
     */
    private function unir(array ...$listas): array
    {
        return array_values(array_unique(array_merge(...$listas)));
    }
}
