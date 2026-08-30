<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A quién va dirigido un evento del calendario.
 *
 * ── Por qué una lista de destinos y no columnas ────────────────────────────
 * Un aviso puede ir «a todos», «a los alumnos del campus norte» o «a estos tres
 * alumnos». Con una columna por criterio (campus_id, programa_academico_id…) cada evento
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

    /** Nivel de estudios (licenciatura, maestría…). Cuelga del programa académico. */
    case Nivel = 'nivel';

    case ProgramaAcademico = 'programa_academico';

    case Plan = 'plan';

    case Grupo = 'grupo';

    /** Una materia impartida en concreto (asignatura_grupo). */
    case Materia = 'materia';

    /** Personas señaladas una por una. */
    case Alumno = 'alumno';

    /**
     * Que además lo vean los FAMILIARES de los alumnos alcanzados.
     *
     * No es un destino como los otros: es un modificador. No señala a nadie por
     * sí solo —igual que «toda la escuela», va sin id— sino que extiende a las
     * familias lo que los demás destinos ya dijeron. «Grupo A» + «y a sus
     * familias» llega a los treinta alumnos y a sus padres, sin tener que
     * elegirlos uno por uno.
     *
     * Se resolvió así y no con un destino «familiares de este alumno» porque
     * ése no compone: una circular a los padres del grupo A obligaría a
     * señalar treinta alumnos a mano, y la del programa académico entera sería
     * impracticable. Como modificador se multiplica con TODAS las
     * segmentaciones que ya existen y con las que se agreguen mañana.
     */
    case Familiares = 'familiares';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Todos => 'Toda la escuela',
            self::Rol => 'Por rol',
            self::Campus => 'Por campus',
            self::Nivel => 'Por nivel',
            self::ProgramaAcademico => 'Por programa académico',
            self::Plan => 'Por plan de estudios',
            self::Grupo => 'Por grupo',
            self::Materia => 'Por materia',
            self::Alumno => 'Alumnos específicos',
            self::Familiares => 'Y a sus familias',
        };
    }

    /** «Toda la escuela» y el modificador de familias no llevan id. */
    public function necesitaId(): bool
    {
        return $this !== self::Todos && $this !== self::Familiares;
    }

    /**
     * ¿Es un destino de verdad o un modificador de los demás?
     *
     * Importa al validar: un aviso cuyo ÚNICO destino sea «y a sus familias» no
     * va dirigido a nadie —no hay alumnos alcanzados cuyas familias extender—,
     * y se guardaría como un aviso que nadie ve.
     */
    public function esModificador(): bool
    {
        return $this === self::Familiares;
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
