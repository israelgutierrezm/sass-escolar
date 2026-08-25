<?php

declare(strict_types=1);

namespace App\Reportes;

use Closure;
use InvalidArgumentException;

/**
 * Una columna que un reporte puede traer.
 *
 * La declara un PROGRAMADOR en la fuente; lo que la escuela elige es cuáles de
 * ellas quiere ver y en qué orden. Ésa es la línea que separa este motor de un
 * generador de SQL configurable: nadie escribe una consulta desde una pantalla.
 */
final readonly class ColumnaReporte
{
    /**
     * @param  string  $clave  estable: la guardan las vistas y la bitácora
     * @param  Closure|null  $valor  fn(mixed $fila): mixed — resuelve la celda
     * @param  string|null  $columnaSql  literal para ORDENAR; sólo lo escribe el código
     * @param  bool  $sensible  dato personal o financiero que no ve cualquiera
     * @param  string|null  $permisoExtra  permiso YA existente que además hace falta
     */
    public function __construct(
        public string $clave,
        public string $etiqueta,
        public TipoDato $tipo = TipoDato::Texto,
        public ?Closure $valor = null,
        public ?string $columnaSql = null,
        public bool $ordenable = false,
        public bool $sensible = false,
        public ?string $permisoExtra = null,
        public int $ancho = 16,
        public ?string $ayuda = null,
    ) {
        /*
         * Doble red sobre `columnaSql`.
         *
         * Este literal se pega en un `ORDER BY`, así que no puede venir de
         * fuera; y no viene: lo escribe quien programa la fuente. Aun así se
         * comprueba su FORMA al construirse —o sea al arrancar la aplicación, no
         * en producción con un usuario delante—, porque «nadie de fuera lo
         * escribe» es cierto hasta el día que alguien escribe una fuente que
         * arma el nombre concatenando algo.
         */
        if ($columnaSql !== null && ! preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $columnaSql)) {
            throw new InvalidArgumentException("columnaSql inválida: {$columnaSql}");
        }

        // Ordenar exige saber POR QUÉ columna de la base: sin el literal, el
        // motor no tendría qué poner en el ORDER BY y la columna saldría
        // marcada como ordenable sin serlo.
        if ($ordenable && $columnaSql === null) {
            throw new InvalidArgumentException("La columna «{$clave}» se declara ordenable pero no dice por qué columna SQL.");
        }
    }

    /** La alineación sale del TIPO: los números a la derecha, el texto a la izquierda. */
    public function alineacion(): string
    {
        return $this->tipo->alineacion();
    }

    /** Resuelve la celda de una fila. */
    public function celda(mixed $fila): mixed
    {
        if ($this->valor !== null) {
            return ($this->valor)($fila);
        }

        // Sin resolutor, se toma el atributo con el mismo nombre que la clave.
        return is_object($fila) ? ($fila->{$this->clave} ?? null) : ($fila[$this->clave] ?? null);
    }
}
