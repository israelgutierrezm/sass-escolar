<?php

declare(strict_types=1);

namespace App\Reportes;

/**
 * Un reporte visto por grupos: una fila por valor de la dimensión.
 *
 * ── Por qué NO trae paginador ────────────────────────────────────────────
 * Un agrupado es corto por construcción: tantas filas como campus, carreras o
 * situaciones tenga la escuela. Si sale con más grupos que el tope, no es un
 * agrupado — es el detalle con ruido, y la dimensión elegida no era una
 * dimensión. Se dice y se corta, en vez de paginar algo que no debería existir.
 *
 * Y hay una razón dura además de esa: el recorrido por lotes del motor avanza
 * con un keyset sobre `(columna de orden, llave primaria)`, y bajo `GROUP BY` la
 * llave primaria no identifica la fila. Medido, lanza «Illegal operator and
 * value combination» —pero sólo a partir del SEGUNDO lote, o sea con más de 500
 * grupos, o sea en la escuela grande y nunca en la prueba—. Reusarlo habría sido
 * dejar armada esa trampa.
 */
final readonly class ResultadoAgrupado
{
    /**
     * @param  array<int, array{etiqueta: string|null, filas: int, valores: array<string, float|null>}>  $grupos
     * @param  array<string, ColumnaReporte>  $medidas  qué se agregó dentro de cada grupo
     * @param  array<string, mixed>  $filtros
     */
    public function __construct(
        public DefinicionReporte $reporte,
        public FuenteDeReporte $fuente,
        public DimensionReporte $dimension,
        public array $grupos,
        public array $medidas,
        public array $filtros,
        public int $milisegundos,
        /**
         * Se alcanzó el tope de grupos, así que la tabla está INCOMPLETA.
         *
         * Se dice con todas las letras: un agrupado cortado que no lo confiesa
         * se lee como el total de la escuela, y sus subtotales no sumarían el
         * total general — que es justo lo que un agrupado promete.
         */
        public bool $truncado = false,
    ) {}

    /** Cuántas filas del detalle cubre este agrupado. */
    public function filas(): int
    {
        return array_sum(array_column($this->grupos, 'filas'));
    }

    /**
     * La suma de cada medida a lo largo de todos los grupos.
     *
     * Es lo que tiene que coincidir con el total del reporte plano, y es la
     * comprobación que este modo existe para permitir.
     *
     * @return array<string, float>
     */
    public function totalGeneral(): array
    {
        $suma = [];

        foreach ($this->medidas as $clave => $columna) {
            if ($columna->total !== Agregacion::Suma) {
                continue;
            }

            $suma[$clave] = 0.0;

            foreach ($this->grupos as $grupo) {
                $suma[$clave] += (float) ($grupo['valores'][$clave] ?? 0);
            }
        }

        return $suma;
    }
}
