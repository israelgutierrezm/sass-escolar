<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\DestinoEvento;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un aviso o evento tiene que ir dirigido a alguien de verdad.
 *
 * «Y a sus familias» es un MODIFICADOR: no señala a nadie por sí solo, extiende
 * a los tutores lo que los demás destinos ya dijeron. Un aviso cuyo único
 * destino fuera ése no tendría alumnos alcanzados cuyas familias extender, así
 * que se guardaría sin público — el mismo defecto que la regla de «al menos un
 * destino» viene a evitar, sólo que disfrazado de estar bien.
 *
 * Vive aquí y no dentro de un controlador porque la comparten los dos que
 * guardan destinos —avisos y calendario—, y una regla de validación repetida es
 * una regla que tarde o temprano se corrige en uno y se olvida en el otro.
 */
class AlMenosUnDestinoReal implements ValidationRule
{
    public function validate(string $atributo, mixed $valor, Closure $falla): void
    {
        $reales = collect(is_array($valor) ? $valor : [])
            ->reject(fn ($destino) => DestinoEvento::tryFrom($destino['tipo'] ?? '')?->esModificador() ?? false);

        if ($reales->isEmpty()) {
            $falla('«Y a sus familias» extiende a los demás destinos; elige también a quién va dirigido.');
        }
    }
}
