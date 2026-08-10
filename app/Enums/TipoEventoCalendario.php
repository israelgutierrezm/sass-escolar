<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué clase de cosa es lo que se pone en el calendario.
 *
 * El tipo no es decoración: decide el color con el que se pinta y, sobre todo,
 * si ese día SE TRABAJA. Un feriado y un aviso ocupan la misma fila en la tabla
 * y significan cosas distintas para quien mira la agenda —uno le dice que no
 * hay clases, el otro que lea algo—.
 *
 * Es un enum y no un catálogo configurable porque cada caso trae comportamiento
 * (el color, el «no se trabaja»), y eso es código. Si una escuela necesita
 * nombrar distinto sus recesos, lo hace en el título del evento.
 */
enum TipoEventoCalendario: string
{
    /** Día no laborable por ley o por la escuela. */
    case Feriado = 'feriado';

    /** Ceremonia, jornada, congreso: algo a lo que se asiste. */
    case Evento = 'evento';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Feriado => 'Día feriado',
            self::Evento => 'Evento',
        };
    }

    /**
     * El color con el que se pinta.
     *
     * Fijo por tipo y no elegible: si cada quien escoge el suyo, el calendario
     * deja de poder leerse de un vistazo —que es lo único que un calendario
     * tiene que hacer bien—.
     */
    public function color(): string
    {
        return match ($this) {
            self::Feriado => '#dc2626',
            self::Evento => '#0891b2',
        };
    }

    /** Si ese día no se trabaja. Lo usan feriado y receso; lo demás sí es día hábil. */
    public function esNoLaborable(): bool
    {
        return $this === self::Feriado;
    }

    /** @return array<int, array{valor: string, etiqueta: string, color: string, no_laborable: bool}> */
    public static function paraSelect(): array
    {
        return array_map(fn (self $t) => [
            'valor' => $t->value,
            'etiqueta' => $t->etiqueta(),
            'color' => $t->color(),
            'no_laborable' => $t->esNoLaborable(),
        ], self::cases());
    }
}
