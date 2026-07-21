<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Veredicto de la calculadora para UN alumno en UNA materia.
 *
 * Se devuelve como objeto y no como un simple float porque la hoja de captura
 * necesita distinguir tres cosas que un número solo no expresa: cuánto lleva,
 * si ya está completa, y qué falta para poder cerrar el acta.
 */
readonly class ResultadoCalificacion
{
    /**
     * @param  float|null  $final  Ponderado de lo capturado. NULL si el esquema no es utilizable.
     * @param  bool  $completa  Todos los componentes tienen número: el acta puede cerrarse.
     * @param  array<int, string>  $faltantes  Componentes sin capturar, por nombre.
     * @param  bool|null  $aprobada  Contra la mínima aprobatoria del plan. NULL si aún no está completa.
     * @param  string|null  $motivo  Por qué no se pudo calcular, cuando aplique.
     */
    public function __construct(
        public ?float $final,
        public bool $completa,
        public array $faltantes,
        public ?bool $aprobada,
        public ?string $motivo = null,
    ) {}

    public static function sinEsquema(string $motivo): self
    {
        return new self(null, false, [], null, $motivo);
    }
}
