<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Los tipos de reactivo de un examen.
 *
 * Lo que separa a unos de otros no es cómo se ven sino DOS cosas que deciden
 * todo lo demás:
 *
 *  - si la máquina puede calificarlos sola (`autocalificable`), y
 *  - si necesitan opciones cargadas (`requiereOpciones`).
 *
 * Un examen con puro reactivo autocalificable se cierra solo; en cuanto lleva
 * uno abierto, queda esperando al docente. Eso hay que poder saberlo ANTES de
 * aplicarlo, no descubrirlo cuando cincuenta alumnos ya lo contestaron.
 */
enum TipoReactivo: string
{
    /** Una sola respuesta correcta entre varias opciones. */
    case OpcionUnica = 'opcion_unica';

    /** Varias correctas; se acierta marcando exactamente ese conjunto. */
    case OpcionMultiple = 'opcion_multiple';

    case VerdaderoFalso = 'verdadero_falso';

    /** Redacta. Solo el docente puede calificarla. */
    case Abierta = 'abierta';

    /** Una palabra o cifra; se compara contra una lista de aceptadas. */
    case RespuestaCorta = 'respuesta_corta';

    /** Un número con margen de error: acepta 3.1416 ± 0.001. */
    case Numerica = 'numerica';

    /** Texto con huecos; cada hueco tiene sus respuestas válidas. */
    case Completar = 'completar';

    /** Emparejar los elementos de dos columnas. */
    case RelacionColumnas = 'relacion_columnas';

    /** Poner en orden arrastrando. */
    case Ordenamiento = 'ordenamiento';

    /** Arrastrar cada elemento a su categoría. */
    case Clasificar = 'clasificar';

    /** Señalar una zona de una imagen (anatomía, mapas, diagramas). */
    case Hotspot = 'hotspot';

    /** Se sube un archivo dentro del examen. Lo revisa el docente. */
    case Archivo = 'archivo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::OpcionUnica => 'Opción múltiple (una respuesta)',
            self::OpcionMultiple => 'Opción múltiple (varias respuestas)',
            self::VerdaderoFalso => 'Verdadero o falso',
            self::Abierta => 'Pregunta abierta',
            self::RespuestaCorta => 'Respuesta corta',
            self::Numerica => 'Respuesta numérica',
            self::Completar => 'Completar espacios',
            self::RelacionColumnas => 'Relación de columnas',
            self::Ordenamiento => 'Ordenar elementos',
            self::Clasificar => 'Clasificar en categorías',
            self::Hotspot => 'Señalar en una imagen',
            self::Archivo => 'Subir un archivo',
        };
    }

    /**
     * Si el sistema puede calificarlo sin intervención humana.
     *
     * Las dos excepciones son las que piden criterio: una redacción y un
     * archivo entregado. Todo lo demás tiene respuesta comparable.
     */
    public function autocalificable(): bool
    {
        return ! in_array($this, [self::Abierta, self::Archivo], true);
    }

    /** Si el reactivo se carga con opciones (o pares, o categorías). */
    public function requiereOpciones(): bool
    {
        return in_array($this, [
            self::OpcionUnica,
            self::OpcionMultiple,
            self::VerdaderoFalso,
            self::RelacionColumnas,
            self::Ordenamiento,
            self::Clasificar,
            self::Hotspot,
        ], true);
    }

    /**
     * Cómo se responde, para que la pantalla sepa qué pintar sin una tabla de
     * equivalencias suya que se desincronice de este enum.
     */
    public function formaDeRespuesta(): string
    {
        return match ($this) {
            self::OpcionUnica, self::VerdaderoFalso => 'una_opcion',
            self::OpcionMultiple => 'varias_opciones',
            self::Abierta => 'texto_largo',
            self::RespuestaCorta => 'texto_corto',
            self::Numerica => 'numero',
            self::Completar => 'huecos',
            self::RelacionColumnas, self::Clasificar => 'emparejar',
            self::Ordenamiento => 'ordenar',
            self::Hotspot => 'coordenada',
            self::Archivo => 'archivo',
        };
    }

    /**
     * @return array<int, array{valor: string, etiqueta: string, autocalificable: bool, requiere_opciones: bool, forma: string}>
     */
    public static function paraSelect(): array
    {
        return array_map(fn (self $t) => [
            'valor' => $t->value,
            'etiqueta' => $t->etiqueta(),
            'autocalificable' => $t->autocalificable(),
            'requiere_opciones' => $t->requiereOpciones(),
            'forma' => $t->formaDeRespuesta(),
        ], self::cases());
    }
}
