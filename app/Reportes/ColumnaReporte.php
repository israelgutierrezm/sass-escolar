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

        /*
         * Sin resolutor, la CLAVE tiene que ser el nombre del atributo.
         *
         * `celda()` lee `$fila->{$clave}` cuando no hay closure, así que una
         * columna con `clave: 'periodo'` y `columnaSql: 'adeudos.periodo_etiqueta'`
         * pide un atributo que no existe y devuelve **NULL en todas las filas**.
         * No lanza nada: la columna sale EN BLANCO en la pantalla y en el Excel,
         * y quien lo reciba pensará que ese dato no está capturado.
         *
         * Mordió al escribir la fuente de cargos, y en tres columnas de golpe
         * —periodo, recargos y descuentos—; sólo se vio porque una mutación
         * imprimió el detalle. Comprobarlo aquí lo convierte en un error al
         * arrancar la aplicación, no en un archivo mudo entregado a la SEP.
         *
         * La salida cuando los nombres no pueden coincidir —porque la clave es
         * la que guardan las vistas y no se renombra— es escribir la closure.
         */
        if ($valor === null && $columnaSql !== null) {
            $atributo = str_contains($columnaSql, '.') ? explode('.', $columnaSql)[1] : $columnaSql;

            if ($atributo !== $clave) {
                throw new InvalidArgumentException(
                    "La columna «{$clave}» saca «{$columnaSql}» y no tiene resolutor, así que leería el "
                    ."atributo «{$clave}», que no existe: saldría vacía en todas las filas. Ponle "
                    ."`valor: fn (\$f) => \$f->{$atributo}` o llámala «{$atributo}»."
                );
            }
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
