<?php

declare(strict_types=1);

namespace App\Reportes;

use App\Support\CatalogoPermisos;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Por qué se puede AGRUPAR un reporte.
 *
 * ── Por qué no sirven las columnas ───────────────────────────────────────
 * Era lo primero que se intentó, y se midió que no. De las 181 columnas de las
 * 14 fuentes, sólo 67 existen en SQL —el resto son closures sobre relaciones
 * precargadas, que sirven para PINTAR y no para un `GROUP BY`—, y esas 67 son
 * identificadores o medidas:
 *
 *   matriculas.matricula   32 filas / 32 grupos   ← una fila por grupo
 *   docentes.clave_profesor  9 /  9               ← una fila por grupo
 *   cartera.saldo, cargos.monto…                  ← se agregan, no se agrupan
 *
 * Y **campus no es agrupable en ninguna de las 14**, ni programa académico, ni situación,
 * ni concepto, ni método de pago. O sea que un modo agrupado montado sobre
 * `columnaSql` ofrecería «agrupar por matrícula» —la pregunta inútil— y no
 * «agrupar por campus», que es la que alguien hace.
 *
 * `columnaSql` no significa «dimensión»: significa «por aquí se puede ORDENAR».
 *
 * ── Agrupar y etiquetar son dos expresiones distintas ────────────────────
 * Se agrupa por `oferta.campus_id` y se rotula con `campus.nombre`. Agrupar por
 * el nombre fundiría dos campus homónimos en una sola fila —y una escuela con
 * «Plantel Centro» en dos municipios los tiene—; rotular con el id daría una
 * tabla de números.
 *
 * Y donde la dimensión ya es una foránea de la tabla base, el `GROUP BY` cae
 * sobre columna indexada, que además es la variante barata.
 *
 * ── Y pasa por el MISMO filtro de permisos que las columnas ──────────────
 * La etiqueta de un grupo ES el valor de la columna. Agrupar por CURP sin el
 * permiso que la protege publicaría las CURP como encabezados de grupo, y
 * `Ejecutor::columnasOmitidas()` no la vería: recorre el arreglo de columnas
 * pedidas, y `agrupar_por` es un camino aparte.
 *
 * Hoy ninguna de las 14 columnas sensibles tiene `columnaSql`, así que la puerta
 * está cerrada POR ACCIDENTE. Esto la cierra a propósito.
 */
final readonly class DimensionReporte
{
    /**
     * @param  string  $clave  estable: la guardan las vistas y la bitácora
     * @param  string  $sqlAgrupacion  por dónde se agrupa; `tabla.columna` a ser posible indexada
     * @param  string  $sqlEtiqueta  cómo se rotula el grupo
     * @param  Closure|null  $join  fn(Builder): void — las uniones que la dimensión necesita
     * @param  string|null  $permisoExtra  permiso YA existente que además hace falta
     */
    public function __construct(
        public string $clave,
        public string $etiqueta,
        public string $sqlAgrupacion,
        public string $sqlEtiqueta,
        public ?Closure $join = null,
        public bool $sensible = false,
        public ?string $permisoExtra = null,
        public ?string $ayuda = null,
    ) {
        /*
         * Las dos expresiones se pegan en un `GROUP BY` y en un `SELECT`, así
         * que no pueden venir de fuera; y no vienen: las escribe quien programa
         * la fuente. Aun así se comprueba su FORMA al construirse —al arrancar
         * la aplicación, no con un usuario delante—, por lo mismo que
         * `ColumnaReporte` comprueba la suya: «nadie de fuera lo escribe» es
         * cierto hasta el día que alguien arma un nombre concatenando algo.
         */
        foreach (['sqlAgrupacion' => $sqlAgrupacion, 'sqlEtiqueta' => $sqlEtiqueta] as $cual => $literal) {
            if (! preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $literal)) {
                throw new InvalidArgumentException(
                    "La dimensión «{$clave}» tiene un {$cual} inválido: «{$literal}». "
                    .'Sólo `tabla.columna`.',
                );
            }
        }

        // Misma red que en `ColumnaReporte`: un permiso inexistente esconde la
        // dimensión para todo el mundo, en silencio.
        if ($permisoExtra !== null && ! CatalogoPermisos::existe($permisoExtra)) {
            throw new InvalidArgumentException(
                "La dimensión «{$clave}» exige el permiso «{$permisoExtra}», que no está en CatalogoPermisos.",
            );
        }
    }

    /** Le aplica a la consulta las uniones que esta dimensión necesita. */
    public function unir(Builder $consulta): void
    {
        if ($this->join !== null) {
            ($this->join)($consulta);
        }
    }
}
