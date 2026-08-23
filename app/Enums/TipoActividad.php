<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Los cinco tipos de actividad del LMS.
 *
 * La diferencia que importa no es cómo se ven sino si el alumno ENTREGA algo:
 * una lectura se marca como vista y ya; los demás producen una entrega que
 * alguien —o algo— califica.
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

    /**
     * Una colección que el alumno ACUMULA a lo largo del curso, pieza por pieza
     * y con su descripción. Lo que lo distingue de una tarea con adjuntos es
     * que cada evidencia se explica y se fecha por separado.
     */
    case Portafolio = 'portafolio';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Lectura => 'Lectura',
            self::Actividad => 'Actividad',
            self::Foro => 'Foro',
            self::Examen => 'Examen',
            self::Portafolio => 'Portafolio de evidencias',
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

    /**
     * Si el alumno arma la entrega PIEZA A PIEZA en vez de mandarla de una vez.
     *
     * Es lo que decide si la pantalla dibuja el formulario de siempre o el
     * portafolio, y vive aquí y no en un `if` de cada vista: mientras la
     * pregunta la conteste el tipo, agregar otro que se comporte igual es
     * agregar un `case`.
     */
    public function seAcumula(): bool
    {
        return $this === self::Portafolio;
    }

    /** @return array<int, array{valor: string, etiqueta: string, se_entrega: bool, se_acumula: bool}> */
    public static function paraSelect(): array
    {
        return array_map(fn (self $t) => [
            'valor' => $t->value,
            'etiqueta' => $t->etiqueta(),
            'se_entrega' => $t->seEntrega(),
            'se_acumula' => $t->seAcumula(),
        ], self::cases());
    }
}
