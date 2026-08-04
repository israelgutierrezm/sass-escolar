<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cómo se contesta una pregunta de encuesta.
 *
 * ── Por qué importa el tipo, y no sólo el texto ────────────────────────────
 * De esto depende qué se puede hacer después con la respuesta. Una escala se
 * promedia y se compara entre docentes; una opción se cuenta y se reparte en
 * porcentajes; una respuesta abierta no se agrega, se lee. Elegir mal el tipo
 * es descubrir al cerrar la encuesta que los datos no contestan la pregunta
 * que la escuela quería hacerse, y para entonces ya no hay a quién volver a
 * preguntarle.
 */
enum TipoPregunta: string
{
    /** Una sola opción de varias. */
    case OpcionUnica = 'opcion_unica';

    /** Varias a la vez: «¿qué servicios has usado?». */
    case OpcionMultiple = 'opcion_multiple';

    /** Del 1 al N, con etiquetas en los extremos. Es la que se promedia. */
    case Escala = 'escala';

    /** Sí o no. */
    case SiNo = 'si_no';

    /** Un número: cuántas veces, cuántos minutos. */
    case Numerica = 'numerica';

    /** Texto libre. No se agrega: se lee. */
    case Abierta = 'abierta';

    public function etiqueta(): string
    {
        return match ($this) {
            self::OpcionUnica => 'Una opción',
            self::OpcionMultiple => 'Varias opciones',
            self::Escala => 'Escala',
            self::SiNo => 'Sí o no',
            self::Numerica => 'Número',
            self::Abierta => 'Respuesta abierta',
        };
    }

    /** Qué se obtiene al cerrar, dicho para quien arma el cuestionario. */
    public function descripcion(): string
    {
        return match ($this) {
            self::OpcionUnica => 'Se reparte en porcentajes. Para elegir entre alternativas excluyentes.',
            self::OpcionMultiple => 'Cuenta cuántos marcaron cada opción. Los porcentajes suman más de 100.',
            self::Escala => 'Da un promedio comparable entre docentes, materias o ciclos. Es la que sirve para ordenar.',
            self::SiNo => 'Un porcentaje de sí. Directa y fácil de leer en un tablero.',
            self::Numerica => 'Promedio y rango. Para cantidades, no para opiniones.',
            self::Abierta => 'No se puede promediar: hay que leerla. Úsala poco, o nadie leerá las mil respuestas.',
        };
    }

    /** ¿Necesita que se le capturen opciones? */
    public function requiereOpciones(): bool
    {
        return in_array($this, [self::OpcionUnica, self::OpcionMultiple], true);
    }

    /**
     * ¿Se puede promediar?
     *
     * Es lo que decide si una pregunta entra en los indicadores comparables o
     * sólo en el detalle. Promediar opciones —«2.4 de opción»— es un número que
     * no significa nada.
     */
    public function esNumerica(): bool
    {
        return in_array($this, [self::Escala, self::Numerica], true);
    }

    /** @return array<int, array<string, mixed>> */
    public static function paraSelector(): array
    {
        return array_map(fn (self $t) => [
            'valor' => $t->value,
            'texto' => $t->etiqueta(),
            'descripcion' => $t->descripcion(),
            'requiere_opciones' => $t->requiereOpciones(),
        ], self::cases());
    }
}
