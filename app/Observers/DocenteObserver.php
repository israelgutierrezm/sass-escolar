<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ControlEscolar\Docente;
use App\Models\Identidad\Persona;
use App\Services\AprovisionadorAcceso;

/**
 * Al materializar un docente, la persona pasa a ser usuario con rol `docente`.
 *
 * Vive como observer y no como una línea en `DocenteController` porque el
 * invariante «todo docente es usuario» debe cumplirse venga de donde venga el
 * alta —el controlador de hoy, un import de mañana, una semilla—. El servicio
 * es idempotente, así que un `updateOrCreate` que no crea nada no dispara
 * `created` y no vuelve a provisionar.
 */
class DocenteObserver
{
    public function __construct(private readonly AprovisionadorAcceso $aprovisionador) {}

    public function created(Docente $docente): void
    {
        $persona = Persona::find($docente->persona_id);

        if ($persona !== null) {
            $this->aprovisionador->paraPersona($persona, 'docente');
        }
    }
}
