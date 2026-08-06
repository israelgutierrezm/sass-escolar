<?php

declare(strict_types=1);

namespace App\Services\Horarios;

/**
 * Una sesión propuesta: esta materia, este día, de tal hora a tal hora.
 *
 * Un objeto y no un arreglo suelto porque el generador lo pasa por seis manos
 * —lo propone, lo valida contra el docente, contra el aula, contra el grupo, lo
 * cuenta y lo pinta— y un arreglo con las llaves mal escritas en una de esas
 * seis no falla: devuelve null y el bloque se coloca en un hueco equivocado.
 */
final class Bloque
{
    public function __construct(
        public readonly int $asignaturaGrupoId,
        public readonly int $dia,
        /** Minutos desde medianoche: comparar horas como texto se rompe con «7:00». */
        public readonly int $inicio,
        public readonly int $fin,
        public readonly ?int $personaId,
        public readonly ?int $aulaId,
        public readonly string $modalidad,
    ) {}

    public function duracionEnMinutos(): int
    {
        return $this->fin - $this->inicio;
    }

    /** ¿Se encima con otro? Dos bloques pegados —uno acaba donde empieza el otro— NO se enciman. */
    public function chocaCon(self $otro): bool
    {
        return $this->dia === $otro->dia
            && $this->inicio < $otro->fin
            && $otro->inicio < $this->fin;
    }

    public function conDocente(?int $personaId): self
    {
        return new self(
            $this->asignaturaGrupoId, $this->dia, $this->inicio, $this->fin,
            $personaId, $this->aulaId, $this->modalidad,
        );
    }

    public function conAula(?int $aulaId): self
    {
        return new self(
            $this->asignaturaGrupoId, $this->dia, $this->inicio, $this->fin,
            $this->personaId, $aulaId, $this->modalidad,
        );
    }

    public static function hora(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }

    /** @return array<string, mixed> */
    public function paraPantalla(): array
    {
        return [
            'asignatura_grupo_id' => $this->asignaturaGrupoId,
            'dia' => $this->dia,
            'hora_inicio' => self::hora($this->inicio),
            'hora_fin' => self::hora($this->fin),
            'persona_id' => $this->personaId,
            'aula_id' => $this->aulaId,
            'modalidad' => $this->modalidad,
        ];
    }
}
