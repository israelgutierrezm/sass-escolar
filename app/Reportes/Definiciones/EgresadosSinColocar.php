<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * A quién le falta empleo, o a quién falta preguntarle.
 *
 * ── Las dos lecturas importan, y por eso el título no dice «desempleados» ──
 * Una fila aquí puede significar que esa persona no encontró trabajo, o que lo
 * encontró y la escuela no se ha enterado. Vinculación no puede distinguirlas
 * sin llamar, y ésa es justamente la lista de a quién llamar.
 */
class EgresadosSinColocar extends DefinicionReporte
{
    public function clave(): string
    {
        return 'egresados-sin-colocar';
    }

    public function titulo(): string
    {
        return 'Egresados sin colocación registrada';
    }

    public function descripcion(): string
    {
        return 'Egresados a los que no se les ha registrado ningún empleo. NO significa que estén '
            .'desempleados: significa que la escuela no lo sabe. Es la lista de a quién darle '
            .'seguimiento, y cada llamada que resulte en un empleo registrado sube el indicador de '
            .'empleabilidad — aunque el trabajo lo haya conseguido por su cuenta.';
    }

    public function fuente(): string
    {
        return 'egresados-colocacion';
    }

    public function areaSugerida(): string
    {
        return 'bolsa';
    }

    public function filtrosFijos(): array
    {
        return ['solo_sin_colocar' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'egresado', 'programa_academico', 'campus', 'generacion', 'situacion'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['generacion', 'desc'];
    }
}
