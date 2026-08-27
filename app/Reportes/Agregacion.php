<?php

declare(strict_types=1);

namespace App\Reportes;

/**
 * Qué se hace con una columna al pie de la tabla.
 *
 * ── Por qué se DECLARA y no se deduce del tipo ───────────────────────────
 * Era tentador: `TipoDato::esNumerico()` ya existe y no tenía un solo lector.
 * Y se equivoca en cuatro clases de columna que este módulo tiene hoy:
 *
 *  - **Ordinales.** `matriculas.periodo_actual` es «va en 3.º»; sumar los
 *    semestres de treinta alumnos da 94, que no significa nada.
 *  - **Umbrales repetidos por fila.** `certificables.meta` es la meta de
 *    créditos DEL PLAN, la misma en cada renglón: sumarla cuenta cada plan una
 *    vez por alumno inscrito.
 *  - **Conteos que no se suman entre sí.** `docentes.grupos` es un
 *    `count(distinct)` por docente; su suma no es el número de grupos de la
 *    escuela, es el de parejas docente-grupo.
 *  - **Porcentajes.** No se suman, y tampoco se promedian sin ponderar: el
 *    80 % de un grupo de 40 y el 100 % de uno de 2 no dan 90 %.
 *
 * Un total ofrecido sobre una columna que no lo admite es una cifra que alguien
 * va a citar en una junta. Por eso cada columna numérica declara la suya, y no
 * declararla es un error AL ARRANCAR — no un pie de tabla equivocado en
 * producción.
 *
 * ── Y por qué es enum y no catálogo ──────────────────────────────────────
 * Cada valor es una RAMA DE CÓDIGO: su función de SQL, qué hace con los nulos y
 * cómo se escribe la celda. Una fila nueva en una tabla no haría nada, que es
 * lo que este proyecto ya midió y documentó para `tipos_actividad` y
 * `tipos_reactivo`.
 */
enum Agregacion: string
{
    /**
     * Se suman. Los nulos cuentan como cero.
     *
     * Un total de cargos sobre un conjunto sin cargos ES cero pesos, así que
     * aquí el `coalesce` es correcto y no un maquillaje.
     */
    case Suma = 'suma';

    /**
     * Se promedian, SIN ponderar, y el vacío es NULL.
     *
     * Un promedio sin filas no es cero: es que no hay de qué promediar, y
     * escribir «0 años de antigüedad» al pie de una plantilla vacía sería
     * afirmar algo que nadie midió.
     *
     * No se ofrece sobre porcentajes: ver el docblock de la clase.
     */
    case Promedio = 'promedio';

    /** No se totaliza, y la columna dice por qué en su `ayuda`. */
    case Ninguno = 'ninguno';

    /** La función de SQL que le corresponde, sobre la expresión que se le dé. */
    public function sql(string $expresion): string
    {
        return match ($this) {
            self::Suma => "coalesce(sum({$expresion}), 0)",
            self::Promedio => "avg({$expresion})",
            self::Ninguno => throw new \LogicException('«Ninguno» no produce SQL: no se totaliza.'),
        };
    }

    /** Si esta agregación produce una cifra al pie. */
    public function totaliza(): bool
    {
        return $this !== self::Ninguno;
    }
}
