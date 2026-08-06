<?php

declare(strict_types=1);

namespace App\Services\Horarios;

/**
 * Quién está ocupado y cuándo, mientras se arma el horario.
 *
 * El generador pregunta esto miles de veces —por cada materia, por cada hueco
 * posible, por cada docente candidato—, así que no puede resolverse recorriendo
 * la lista de bloques ya colocados. Aquí se mantienen tres índices en memoria:
 * lo ocupado por cada grupo, por cada docente y por cada aula.
 *
 * ── Por qué también cuenta lo que YA existe ────────────────────────────────
 * Se le siembran los bloques que ya estaban en la base antes de generar. Sin
 * eso, generar el horario de un grupo pisaría el de otro que ya estaba resuelto,
 * y el choque aparecería en producción y no aquí.
 */
final class Agenda
{
    /** @var array<int, array<int, Bloque[]>> grupo => día => bloques */
    private array $porGrupo = [];

    /** @var array<int, array<int, Bloque[]>> docente => día => bloques */
    private array $porDocente = [];

    /** @var array<int, array<int, Bloque[]>> aula => día => bloques */
    private array $porAula = [];

    /** @var array<int, int> docente => minutos ya asignados en la semana */
    private array $minutosSemana = [];

    /** @var array<int, array<int, int>> docente => día => minutos */
    private array $minutosDia = [];

    /** @param  array<int, int>  $grupoDeAsignaturaGrupo  asignatura_grupo_id => grupo_id */
    public function __construct(private readonly array $grupoDeAsignaturaGrupo) {}

    /**
     * Una copia para PROBAR sin ensuciar.
     *
     * El generador tantea varios docentes para la misma materia y cada tanteo
     * necesita ver sus propios bloques —para no encimarse consigo mismo— pero
     * los descartados no deben dejar rastro. Sin esto, un intento que colocaba
     * 3 de 5 horas y se desechaba dejaba esas 3 ocupando la agenda: el horario
     * final salía con huecos reservados por clases que nunca existieron.
     *
     * `clone` basta: las propiedades son arreglos —que PHP copia por valor— y
     * los `Bloque` de dentro son inmutables, así que compartirlos no daña.
     */
    public function copia(): self
    {
        return clone $this;
    }

    public function ocupar(Bloque $bloque): void
    {
        $grupo = $this->grupoDeAsignaturaGrupo[$bloque->asignaturaGrupoId] ?? null;

        if ($grupo !== null) {
            $this->porGrupo[$grupo][$bloque->dia][] = $bloque;
        }

        if ($bloque->personaId !== null) {
            $this->porDocente[$bloque->personaId][$bloque->dia][] = $bloque;
            $this->minutosSemana[$bloque->personaId] = ($this->minutosSemana[$bloque->personaId] ?? 0) + $bloque->duracionEnMinutos();
            $this->minutosDia[$bloque->personaId][$bloque->dia] = ($this->minutosDia[$bloque->personaId][$bloque->dia] ?? 0) + $bloque->duracionEnMinutos();
        }

        // Sólo lo presencial ocupa aula. Contar las clases en línea es de donde
        // sale la mitad de los choques falsos.
        if ($bloque->aulaId !== null) {
            $this->porAula[$bloque->aulaId][$bloque->dia][] = $bloque;
        }
    }

    public function grupoLibre(int $grupoId, Bloque $bloque): bool
    {
        return $this->libre($this->porGrupo[$grupoId][$bloque->dia] ?? [], $bloque);
    }

    public function aulaLibre(int $aulaId, Bloque $bloque): bool
    {
        return $this->libre($this->porAula[$aulaId][$bloque->dia] ?? [], $bloque);
    }

    /**
     * ¿El docente puede tomar este bloque?
     *
     * Además de no chocar, respeta el descanso mínimo entre clases: pegar dos
     * clases en salones distintos con cero minutos en medio produce horarios que
     * sólo funcionan si nadie tiene que caminar.
     */
    public function docenteLibre(int $personaId, Bloque $bloque, int $minutosDescanso = 0): bool
    {
        foreach ($this->porDocente[$personaId][$bloque->dia] ?? [] as $ocupado) {
            if ($ocupado->chocaCon($bloque)) {
                return false;
            }

            $separacion = $bloque->inicio >= $ocupado->fin
                ? $bloque->inicio - $ocupado->fin
                : $ocupado->inicio - $bloque->fin;

            if ($separacion < $minutosDescanso) {
                return false;
            }
        }

        return true;
    }

    public function minutosDelDia(int $personaId, int $dia): int
    {
        return $this->minutosDia[$personaId][$dia] ?? 0;
    }

    public function minutosDeLaSemana(int $personaId): int
    {
        return $this->minutosSemana[$personaId] ?? 0;
    }

    /** @return Bloque[] */
    public function bloquesDelGrupo(int $grupoId, int $dia): array
    {
        return $this->porGrupo[$grupoId][$dia] ?? [];
    }

    /** @param  Bloque[]  $ocupados */
    private function libre(array $ocupados, Bloque $bloque): bool
    {
        foreach ($ocupados as $ocupado) {
            if ($ocupado->chocaCon($bloque)) {
                return false;
            }
        }

        return true;
    }
}
