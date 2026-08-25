<?php

declare(strict_types=1);

namespace App\Reportes;

use App\Models\Identidad\Usuario;
use Closure;

/**
 * Un filtro que un reporte ofrece.
 *
 * `aplicar` es una closure que recibe el Builder y el valor YA VALIDADO. Nunca
 * recibe texto crudo del navegador: el motor comprueba el valor contra el tipo
 * antes de llamarla, así que dentro se puede usar el binding de Eloquent sin
 * pensar en escapes.
 */
final readonly class FiltroReporte
{
    /**
     * @param  Closure  $aplicar  fn(Builder $q, mixed $valor): Builder
     * @param  Closure|null  $opciones  fn(Usuario $u): array<int|string, string>
     */
    public function __construct(
        public string $clave,
        public string $etiqueta,
        public TipoFiltro $tipo,
        public Closure $aplicar,
        public ?Closure $opciones = null,
        public ?string $ayuda = null,
    ) {}

    /**
     * Las opciones de un filtro de catálogo, para ESTE usuario.
     *
     * Se leen VIVAS y no de una lista guardada: un campus nuevo aparece solo, y
     * el desplegable de campus ofrece únicamente los que su rol alcanza —que es
     * lo que hace que un coordinador no pueda pedir el reporte de otro plantel
     * simplemente eligiéndolo—.
     *
     * @return array<int|string, string>
     */
    public function opcionesPara(Usuario $usuario): array
    {
        return $this->opciones === null ? [] : ($this->opciones)($usuario);
    }
}
