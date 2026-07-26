<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Admisiones\Alumno;
use App\Models\Identidad\Persona;
use App\Services\AprovisionadorAcceso;

/**
 * Al materializar un alumno, la persona pasa a ser usuario con rol `alumno`.
 *
 * Cubre las dos entradas —`ConvertidorAspirante` (embudo) y `MatriculadorOferta`
 * (quien ya estaba dentro)— porque ambas pasan por `Alumno::firstOrCreate`, y
 * `created` solo dispara la primera vez que esa persona se vuelve alumno.
 */
class AlumnoObserver
{
    public function __construct(private readonly AprovisionadorAcceso $aprovisionador) {}

    public function created(Alumno $alumno): void
    {
        $persona = Persona::find($alumno->persona_id);

        if ($persona !== null) {
            $this->aprovisionador->paraPersona($persona, 'alumno');
        }
    }
}
