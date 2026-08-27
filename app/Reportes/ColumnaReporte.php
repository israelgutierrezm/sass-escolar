<?php

declare(strict_types=1);

namespace App\Reportes;

use App\Support\CatalogoPermisos;
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
     * @param  Agregacion|null  $total  qué va al pie; OBLIGATORIO si el tipo es numérico
     * @param  string|null  $sqlTotal  expresión completa a agregar, para las que sólo existen en PHP
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
        public ?Agregacion $total = null,
        public ?string $sqlTotal = null,
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
        /*
         * Un permiso que NO EXISTE esconde la columna para TODO EL MUNDO.
         *
         * Falla cerrado, o sea que no hay fuga —y por eso mismo es silencioso:
         * la columna sale del Excel sin decir por qué, la pantalla explica «tu
         * rol no las alcanza» a quien tiene TODOS los permisos administrativos
         * de la escuela, y el permiso ni siquiera se puede conceder desde
         * «/plataforma/roles» porque no está en el catálogo.
         *
         * Mordió con «editar-docentes», que nunca existió: cuatro columnas
         * —correo, celular, CURP y RFC del profesorado— llevaban meses muertas.
         * Se comprueba aquí, al construirse, que es al arrancar la aplicación y
         * no con un usuario delante.
         */
        if ($permisoExtra !== null && ! CatalogoPermisos::existe($permisoExtra)) {
            throw new InvalidArgumentException(
                "La columna «{$clave}» exige el permiso «{$permisoExtra}», que no está en CatalogoPermisos. "
                .'Un permiso inexistente esconde la columna para todo el mundo, incluida dirección general.',
            );
        }

        /*
         * Una columna NUMÉRICA tiene que decir qué va al pie.
         *
         * No se deduce del tipo, y el porqué está en el docblock de
         * `Agregacion`: entre las numéricas hay ordinales, umbrales repetidos
         * por fila, conteos que no se suman entre sí y porcentajes. Un total
         * ofrecido sobre una de ésas es una cifra que alguien va a citar.
         *
         * Se exige AL CONSTRUIRSE —o sea al arrancar— y no al pintar: quien
         * escribe una columna de dinero nueva se entera en el momento, no un
         * mes después con un pie de tabla equivocado en producción.
         */
        if ($tipo->esNumerico() && $total === null) {
            throw new InvalidArgumentException(
                "La columna «{$clave}» es numérica y no dice qué va al pie. Declara `total:` con "
                .'`Agregacion::Suma`, `Agregacion::Promedio` o `Agregacion::Ninguno` — y si es Ninguno, '
                .'escribe en su `ayuda` por qué no se totaliza.',
            );
        }

        /*
         * Y si se totaliza, tiene que haber QUÉ agregar.
         *
         * Las columnas que sólo existen en PHP —una closure sobre una relación,
         * una resta entre dos importes— no tienen nada que meter dentro de un
         * `sum()`. Para ésas se declara `sqlTotal` con la expresión completa,
         * que `columnaSql` no puede llevar: su comprobación de forma sólo acepta
         * `tabla.columna` porque ese literal se pega en un `ORDER BY`.
         */
        if ($total?->totaliza() && $columnaSql === null && $sqlTotal === null) {
            throw new InvalidArgumentException(
                "La columna «{$clave}» se totaliza pero sólo existe en PHP. Dale `sqlTotal:` con la "
                .'expresión que hay que agregar, o declárala `Agregacion::Ninguno`.',
            );
        }

        /*
         * Un PORCENTAJE no se promedia sin ponderar.
         *
         * El 80 % de un grupo de 40 y el 100 % de uno de 2 no dan 90 %. Si un
         * día hace falta el promedio ponderado, se declara con `sqlTotal` y su
         * fórmula, no con esta bandera.
         */
        if ($tipo === TipoDato::Porcentaje && $total === Agregacion::Promedio && $sqlTotal === null) {
            throw new InvalidArgumentException(
                "La columna «{$clave}» es un porcentaje: promediarlo sin ponderar da un número falso. "
                .'Declara la fórmula ponderada en `sqlTotal`, o ponla en `Agregacion::Ninguno`.',
            );
        }

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
