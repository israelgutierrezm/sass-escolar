<?php

declare(strict_types=1);

namespace App\Reportes;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Lo que devuelve el motor: las filas y todo lo que hace falta para
 * ENTENDERLAS.
 *
 * No es sólo la tabla. Van también el grano —«una fila es una matrícula»—, las
 * columnas que se omitieron por permiso y cuánto tardó, porque un reporte que se
 * lee sin saber qué cuenta cada renglón es como se llega a presentar «28
 * alumnos» cuando son las 28 materias de una sola alumna.
 */
final readonly class Resultado
{
    /**
     * @param  array<int, ColumnaReporte>  $columnas
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array<string, mixed>  $filtros
     * @param  array<int, string>  $columnasOmitidas
     */
    public function __construct(
        public DefinicionReporte $reporte,
        public FuenteDeReporte $fuente,
        public array $columnas,
        public array $filas,
        public LengthAwarePaginator $paginador,
        public array $filtros,
        public array $columnasOmitidas,
        public int $milisegundos,
        /**
         * El pie de la tabla, o null si ninguna columna pedida se totaliza.
         *
         * `cuadra` dice si la consulta agregada vio las mismas filas que el
         * paginador. En falso, la pantalla NO enseña las cifras: un total
         * inflado por un join que multiplica no da error, da otro número.
         *
         * @var array{cuadra: bool, filas: int, valores: array<string, float|null>}|null
         */
        public ?array $totales = null,
    ) {}

    /** Cuántas filas tiene el reporte completo, no la página. */
    public function total(): int
    {
        return $this->paginador->total();
    }

    /**
     * Las columnas omitidas, con su etiqueta, para poder decirlo en pantalla.
     *
     * @return array<int, string>
     */
    public function etiquetasOmitidas(): array
    {
        $catalogo = $this->fuente->columnas();

        return array_values(array_map(
            fn (string $c) => $catalogo[$c]->etiqueta,
            $this->columnasOmitidas,
        ));
    }
}
