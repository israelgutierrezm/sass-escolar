<?php

declare(strict_types=1);

namespace App\Services\Cfdi;

/**
 * Si a esta factura le toca el complemento educativo, y si no, por qué.
 *
 * Tres desenlaces y no dos, porque «no lleva complemento» significa dos cosas
 * muy distintas y confundirlas es el defecto:
 *
 *  - `noAplica()` — esta factura no ampara enseñanza deducible. Una escuela de
 *    licenciatura, o un cobro de credencial de reposición. Callar es correcto:
 *    avisar aquí entrenaría a ignorar el aviso.
 *  - `falta()` — sí ampara enseñanza que alguien marcó como deducible, y aun
 *    así el complemento no pudo armarse. ESO hay que decirlo antes de timbrar:
 *    después, arreglarlo cuesta cancelar ante el SAT y volver a emitir.
 *  - `lleva()` — viaja, con sus cuatro datos.
 */
final readonly class DecisionIedu
{
    /**
     * @param  array{nombre_alumno: string, curp: string, nivel_educativo: string, aut_rvoe: string}|null  $datos
     */
    private function __construct(
        public bool $aplica,
        public ?array $datos = null,
        public ?string $motivo = null,
    ) {}

    /**
     * @param  array{nombre_alumno: string, curp: string, nivel_educativo: string, aut_rvoe: string}  $datos
     */
    public static function lleva(array $datos): self
    {
        return new self(aplica: true, datos: $datos);
    }

    public static function noAplica(): self
    {
        return new self(aplica: false);
    }

    public static function falta(string $motivo): self
    {
        return new self(aplica: true, motivo: $motivo);
    }

    /** Aplica y no se pudo armar: es lo único que hay que enseñar y guardar. */
    public function incompleto(): bool
    {
        return $this->aplica && $this->datos === null;
    }
}
