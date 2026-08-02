<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A quién va dirigido un evento del calendario.
 *
 * ── Por qué una lista de destinos y no columnas ────────────────────────────
 * Un aviso puede ir «a todos», «a los alumnos del campus norte» o «a estos tres
 * alumnos». Con una columna por criterio (campus_id, carrera_id…) cada evento
 * quedaría atado a UNA combinación y no se podría decir «a los de enfermería
 * MÁS a los del grupo A de sistemas», que es justo lo que pide quien manda
 * avisos de verdad.
 *
 * Por eso cada evento tiene N destinos y basta con encajar en UNO para verlo:
 * los destinos se suman, no se cruzan. «Campus norte» + «grupo A» significa
 * todos los del campus norte y además el grupo A —no la intersección, que
 * dejaría el aviso sin público casi siempre—.
 *
 * El orden de esta lista es el de la interfaz: de lo más amplio a lo más
 * concreto, que es como uno piensa a quién quiere avisarle.
 */
enum DestinoEvento: string
{
    /** Toda la escuela. Sin id. */
    case Todos = 'todos';

    /** Quien tenga ese rol: docentes, alumnos, administrativos… */
    case Rol = 'rol';

    case Campus = 'campus';

    /** Nivel de estudios (licenciatura, maestría…). Cuelga de la carrera. */
    case Nivel = 'nivel';

    case Carrera = 'carrera';

    case Plan = 'plan';

    case Grupo = 'grupo';

    /** Una materia impartida en concreto (asignatura_grupo). */
    case Materia = 'materia';

    /** Personas señaladas una por una. */
    case Alumno = 'alumno';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Todos => 'Toda la escuela',
            self::Rol => 'Por rol',
            self::Campus => 'Por campus',
            self::Nivel => 'Por nivel',
            self::Carrera => 'Por carrera',
            self::Plan => 'Por plan de estudios',
            self::Grupo => 'Por grupo',
            self::Materia => 'Por materia',
            self::Alumno => 'Alumnos específicos',
        };
    }

    /** «Toda la escuela» no lleva id; el resto señala a algo. */
    public function necesitaId(): bool
    {
        return $this !== self::Todos;
    }

    /** @return array<int, array{valor: string, etiqueta: string, necesita_id: bool}> */
    public static function paraSelect(): array
    {
        return array_map(fn (self $d) => [
            'valor' => $d->value,
            'etiqueta' => $d->etiqueta(),
            'necesita_id' => $d->necesitaId(),
        ], self::cases());
    }
}
