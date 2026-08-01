<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Los cuatro tipos de actividad del LMS.
 *
 * La diferencia que importa no es cómo se ven sino si el alumno ENTREGA algo:
 * una lectura se marca como vista y ya; una actividad, un foro y un examen
 * producen una entrega que alguien —o algo— califica.
 */
enum TipoActividad: string
{
    /** Texto, SCORM o HTML embebido. No se entrega: se lee. */
    case Lectura = 'lectura';

    /** Se entrega algo: un archivo, un texto, un cuestionario. */
    case Actividad = 'actividad';

    /** Una pregunta a debatir. La entrega son las participaciones. */
    case Foro = 'foro';

    /** Reactivos con ponderación y, donde se pueda, autocalificación. */
    case Examen = 'examen';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Lectura => 'Lectura',
            self::Actividad => 'Actividad',
            self::Foro => 'Foro',
            self::Examen => 'Examen',
        };
    }

    /**
     * Si produce una entrega calificable. La lectura no: se registra que se
     * abrió, pero no hay nada que calificar ni retroalimentar.
     */
    public function seEntrega(): bool
    {
        return $this !== self::Lectura;
    }

    /** @return array<int, array{valor: string, etiqueta: string, se_entrega: bool}> */
    public static function paraSelect(): array
    {
        return array_map(fn (self $t) => [
            'valor' => $t->value,
            'etiqueta' => $t->etiqueta(),
            'se_entrega' => $t->seEntrega(),
        ], self::cases());
    }
}
