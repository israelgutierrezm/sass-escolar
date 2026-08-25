<?php

declare(strict_types=1);

namespace App\Reportes;

use Closure;
use Generator;

/**
 * Un reporte listo para descargarse: sus columnas y CÓMO recorrer sus filas.
 *
 * Las filas no vienen en un arreglo: vienen detrás de una closure que devuelve
 * un generador. Es la diferencia entre exportar treinta mil renglones con
 * memoria constante y morir con `Allowed memory size exhausted` a la mitad —y
 * dejar al usuario con medio archivo que parece completo—.
 */
final readonly class Exportacion
{
    /**
     * @param  array<int, ColumnaReporte>  $columnas
     * @param  Closure(): Generator  $filas
     * @param  Closure(int, string): void  $alTerminar  anota la corrida cuando ya se sabe cuántas filas salieron
     */
    public function __construct(
        public DefinicionReporte $reporte,
        public FuenteDeReporte $fuente,
        public array $columnas,
        public int $total,
        public Closure $filas,
        public Closure $alTerminar,
    ) {}

    /** @return Generator<int, array<string, mixed>> */
    public function recorrer(): Generator
    {
        return ($this->filas)();
    }

    /**
     * El nombre del archivo.
     *
     * Lleva la fecha porque un reporte se vuelve a sacar cada mes y tres
     * archivos llamados igual en la carpeta de descargas no se distinguen.
     */
    public function archivo(string $extension): string
    {
        return $this->reporte->clave().'-'.now()->format('Y-m-d').'.'.$extension;
    }
}
