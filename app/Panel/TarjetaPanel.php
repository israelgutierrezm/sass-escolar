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
     * `barras` (serie horizontal con etiquetas), `columnas` (serie vertical
     * compacta, para cosas como las 24 horas del día) o `accesos` (mosaico de
     * atajos).
     *
     * `barras` y `columnas` son la misma información con forma distinta, y la
     * distinción importa: veinticuatro barras horizontales apiladas ocupan
     * media pantalla de alto y roban visibilidad a todo lo demás. Una serie
     * larga va en columnas; una corta con etiquetas largas —las etapas del
     * embudo— va en barras.
     *
     * El tipo lo decide la tarjeta y no la pantalla: el front sabe pintar
     * cuatro formas, y una tarjeta nueva que use una de ellas no necesita
     * tocar el Vue.
     */
    public function tipo(): string;

    /** Ancho en columnas de 1 a 4. Una serie por hora no cabe en un cuarto. */
    public function ancho(): int;

    /**
     * El trazo `d` de un SVG de 24×24 para el icono de la tarjeta.
     *
     * Va en la tarjeta y no en la pantalla porque es parte de lo que la tarjeta
     * ES: quien agrega una nueva no debería tener que editar el Vue para que se
     * vea como las demás.
     */
    public function icono(): string;

    /**
     * Los datos ya listos para pintar. Devuelve null si esta tarjeta no aplica
     * a ESTA persona aunque tenga el permiso —el caso típico: un administrativo
     * con `ver-kardex` que no es alumno de nadie—.
     *
     * @return array<string, mixed>|null
     */
    public function datos(Usuario $usuario): ?array;
}
