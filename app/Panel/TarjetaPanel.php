<?php

declare(strict_types=1);

namespace App\Panel;

use App\Models\Identidad\Usuario;

/**
 * Una tarjeta del panel.
 *
 * El panel NO se resuelve con ramas por rol (`if rol == finanzas`). Cada
 * tarjeta declara qué permiso exige y el registro le entrega a cada persona las
 * que puede ver. Consecuencia directa: un rol nuevo que la escuela arme desde
 * `/plataforma/roles` obtiene su panel solo, sin que nadie toque código — que
 * es justo lo que pidió el cliente al decir que sus ejemplos son un caso del
 * mecanismo y no el mecanismo.
 *
 * Agregar una tarjeta = agregar una clase y registrarla. Nada más.
 */
interface TarjetaPanel
{
    /** Identificador estable. Se usa para ordenar y para ocultarla. */
    public function clave(): string;

    public function titulo(): string;

    /**
     * El permiso que hay que tener para verla, o null si es para cualquiera
     * con sesión (por ejemplo, "mis datos").
     */
    public function permiso(): ?string;

    /**
     * Cómo se pinta: `metrica` (un número grande), `lista` (renglones),
     * `barras` (serie con etiquetas) o `accesos` (botones directos).
     *
     * El tipo lo decide la tarjeta y no la pantalla: el front sabe pintar
     * cuatro formas, y una tarjeta nueva que use una de ellas no necesita
     * tocar el Vue.
     */
    public function tipo(): string;

    /** Ancho en columnas de 1 a 4. Una serie por hora no cabe en un cuarto. */
    public function ancho(): int;

    /**
     * Los datos ya listos para pintar. Devuelve null si esta tarjeta no aplica
     * a ESTA persona aunque tenga el permiso —el caso típico: un administrativo
     * con `ver-kardex` que no es alumno de nadie—.
     *
     * @return array<string, mixed>|null
     */
    public function datos(Usuario $usuario): ?array;
}
