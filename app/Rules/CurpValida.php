<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Curp;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida la CURP de verdad, no solo que mida 18.
 *
 * Antes la regla era `size:18`, que acepta cualquier ristra de dieciocho
 * caracteres. Una CURP mal copiada pasaba, se guardaba, y el error aparecía
 * meses después en un trámite —o peor, no aparecía y el alumno se titulaba con
 * una CURP que no es la suya—. El dígito verificador está justo para eso y
 * cuesta cero consultarlo.
 *
 * Acepta también la marca `EXTRANJERO`: quien no tiene CURP debe poder
 * registrarse. `IdentidadPersona` la traduce a curp null.
 */
class CurpValida implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value) || Curp::esMarcaDeExtranjero((string) $value)) {
            return;
        }

        if (! Curp::esValida((string) $value)) {
            $fail('La CURP no es válida. Revisa que esté completa y bien copiada, o escribe EXTRANJERO si no tienes.');
        }
    }
}
